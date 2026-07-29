# Tutorial: from install to a verified conversion

By the end of this you will have a real short URL you created from PHP, and a
conversion you can read back through `GET /track/events` — the same loop a
production integration runs.

The vehicle is the package's own `workbench/` app: a throwaway Laravel
application that Testbench boots with the SDK already registered. Every step
shows the code, so you can follow along in your own application instead.

**This tutorial writes to a real LnkFlow team.** Use one you are happy to create
throwaway records in. Nothing here touches production infrastructure, but the
campaign, link, and test conversion are real rows.

## 0. What you need

- PHP 8.2+ and Composer.
- A LnkFlow account with at least one team.
- One API token from Settings → API Tokens with **"Allow link management
  (write)"** enabled. A `write` token also satisfies the conversion endpoints,
  so one token covers the whole tutorial. Production should split it — see
  [Token scopes](token-scopes.md).
- Your **numeric** team id. Step 2 prints it for you.

## 1. Install

In your own application:

```bash
composer require lnkflow/laravel
php artisan lnkflow:install --preset=client
php artisan migrate
```

`--preset=client` publishes `config/lnkflow.php` and the mapping migrations and
enables no feature flags. Direct client calls never need one.

To follow along in the workbench instead, clone this package and install its
dependencies:

```bash
git clone <this-repository> lnkflow-laravel
cd lnkflow-laravel
composer install
```

The workbench needs its own environment file. `.env.example` ships without an
application key, so set one:

```bash
cp workbench/.env.example workbench/.env
php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'
# paste that value into APP_KEY= in workbench/.env
```

Then add your LnkFlow settings to the bottom of `workbench/.env`:

```dotenv
LNKFLOW_API_URL=https://app.lnkflow.io/api/v1
LNKFLOW_API_TOKEN=your-write-enabled-token
LNKFLOW_TEAM=
```

Leave `LNKFLOW_TEAM` empty for now — step 2 tells you what to put there.

Boot it:

```bash
composer serve
```

That builds the workbench app and serves it on <http://127.0.0.1:8000>. The
index page lists the six steps below and shows which settings it can see. It
prints whether each token is *set*, never the value.

> **If an edit to `workbench/.env` seems to have no effect**, run
> `composer clear` and start the server again. Testbench copies
> `workbench/.env` into its skeleton application, and it only copies when the
> skeleton has no `.env` of its own — so a leftover copy shadows your edits.

> The workbench loads the SDK because `testbench.yaml` lists
> `LnkFlow\Laravel\LnkFlowServiceProvider` under `providers`. Composer package
> discovery cannot find a package that is not installed into `vendor/`, so the
> workbench has to name it. Your own application does not — discovery handles it.

## 2. Prove the token, and find the team id

```bash
php artisan lnkflow:doctor          # in your own application
vendor/bin/testbench lnkflow:doctor # in this package's workbench
```

Doctor is read-only: it checks the URL and TLS, token presence, team, migrations,
consent binding, queue and session drivers, and finishes with one `GET /me`.

Open <http://127.0.0.1:8000/lnkflow/whoami> for the same thing as JSON:

```php
$me = $client->identity()->me();

$me->id;            // the USER id — not the team id
$me->capabilities;  // ['read' => true, 'write' => true, 'conversions' => true]
$me->raw['teams'];  // [['id' => 42, 'name' => 'Acme', 'role' => 'owner'], ...]
```

Take the numeric `id` from the team you want to use, put it in `LNKFLOW_TEAM`,
and restart the server. That value becomes the `X-LnkFlow-Team` header on every
request.

If you see `ConnectionException: No LnkFlow API token is configured.`, the
client you called wants a token you have not set. `identity()`, `search()`,
`workspace()`, and `stats()` all use `LNKFLOW_API_TOKEN` and do not fall back to
the narrower tokens.

## 3. Preview before you write

<http://127.0.0.1:8000/lnkflow/preview>

```php
$preview = $client->links()->preview(
    new CreateLink(
        destinationUrl: 'http://127.0.0.1:8000/lnkflow/landing',
        name: 'Workbench tutorial link',
        slug: 'workbench-tutorial',
        utm: ['utm_source' => 'workbench'],
        conversionTrackingEnabled: true,
    ),
    campaignName: 'Workbench tutorial',
);

$preview->raw['short_url'];
$preview->raw['will_create'];  // ['campaign' => true, 'link' => true, ...]
$preview->raw['warnings'];
```

Nothing is created. You get the exact short URL you would get, the destination
with UTMs applied, which domain was selected and why, and any warnings.

`preview()` still needs a **write** token, because it previews a write intent.
If this returns 403 with a read-only token, that is the reason — not a bug.

## 4. Create the campaign and the link

<http://127.0.0.1:8000/lnkflow/create> — **this writes.**

```php
$campaign = $client->campaigns()->create(
    new CreateCampaign('Workbench tutorial'),
    idempotencyKey: 'workbench:tutorial:v1:campaign',
);

$link = $client->links()->create(
    $campaign->id,
    new CreateLink(
        destinationUrl: 'http://127.0.0.1:8000/lnkflow/landing',
        name: 'Workbench tutorial link',
        slug: 'workbench-tutorial',
        utm: ['utm_source' => 'workbench'],
        conversionTrackingEnabled: true,
    ),
    idempotencyKey: 'workbench:tutorial:v1:link',
);

$link->shortUrl;    // https://<your-prefix>.mylnk.click/workbench-tutorial
$link->edgeStatus;  // "publishing" at first, then "published"
```

The idempotency keys are **constants**, not random values. Reload the page: the
records are not created twice, and `replayed` flips to `true` — the server
replayed the stored response for that key. That is the whole point of an
idempotency key, and it is why you persist one beside your local mapping before
the first request rather than generating one per attempt. Pass `?run=v2` to
start a fresh pair.

Two details worth noticing in the response:

- `campaign.slug` is the *campaign's* slug; `campaign.primary_link_slug` and
  `link.slug` are the link's. The API's field names disagree with that, which is
  exactly why the SDK renames them. See [Links](links.md).
- `edge_status` is asynchronous. A successful write does not mean the edge has
  the link yet. Give it a few seconds and reload if it still says `publishing`.

`conversionTrackingEnabled: true` is what makes the edge append `lnk_id` to the
destination. Without it there is nothing to attribute in the next step.

## 5. Click the link

Open `short_url` from step 4 in a browser. LnkFlow's edge records the click and
redirects you to the destination — the workbench landing page — with `?lnk_id=`
appended.

The landing page renders the browser snippet:

```blade
<x-lnkflow-script storage="manual" attribution="manual" />
```

and shows the captured click id. Notice that it works before you press anything:
`storage="manual"` captures into memory but writes **no cookie** until consent
arrives. Press **Grant storage consent** to see
`window.lnkflow.setConsent({storage: true, attribution: true})` take effect, and
**Revoke** to see `revokeConsent()` delete the cookies again. That is the whole
consent contract — see [Browser bridge](browser-script.md).

If the click id says "none", the link has not published to the edge yet, or you
opened the landing page directly instead of through the short URL.

## 6. Report a conversion

Click **Report a test sale for this click** — <http://127.0.0.1:8000/lnkflow/track>
with the captured click id. **This writes.**

```php
$sale = $client->conversions()->sale(new Sale(
    invoiceId: 'workbench-20260729-120000',
    amount: 1299,          // integer minor units — cents, not dollars
    currency: 'usd',
    customerExternalId: 'workbench-customer',
    clickId: $clickId,
    paymentProcessor: 'workbench',
    test: true,            // clearly labelled, excluded from statistics
));
```

This call is synchronous so you can see the result immediately. **Production
code should not do that.** Use `LnkFlow::trackSale($sale)`, which queues the
report after the host transaction commits, so a LnkFlow outage can never fail a
checkout.

`invoiceId` is the idempotency anchor: reporting the same invoice twice records
one sale and returns `"duplicate": true`.

## 7. Verify it

<http://127.0.0.1:8000/lnkflow/events>

```php
$events = $client->conversions()->events(['test' => true, 'limit' => 20]);

foreach ($events as $event) {
    $event->id;
    $event->type;               // "sale"
    $event->amountCents;        // 1299
    $event->attributionSource;  // "link" when the click resolved
    $event->test;               // true
}
```

`GET /track/events` needs no special ability, which is what makes it the
verification loop. Find the event you just created and confirm `is_test: true`
and, if you came through the short URL, `attribution_source: "link"`.

`php artisan lnkflow:verify --test-conversion` runs this same create-and-read-back
loop as a command.

**Test events do not appear in `stats()->conversions()`.** Test and bot-flagged
events are excluded from every statistic, on purpose. An empty funnel here is
correct, not a failure — and `hasConversionData` stays false until the team
records a real conversion.

## 8. Clean up

Pause the link rather than deleting it — `DELETE` also destroys click history:

```php
$client->links()->deactivate($link->id);
```

The test conversion is retained. It is labelled and excluded from statistics, so
it does not pollute reporting.

## Where to go next

| You want to | Read |
|---|---|
| know every method and what it needs | [API index](api-index.md), [Token scopes](token-scopes.md) |
| keep CMS content and links in sync automatically | [CMS sync](cms-sync.md) |
| capture journeys server-side, with consent | [Journeys and consent](journeys-and-consent.md) |
| wire the browser snippet to a real CMP | [Browser bridge](browser-script.md) |
| report conversions from Stripe | [Cashier](cashier.md) |
| handle failures properly | [Errors](errors.md) |
| test all of this without a network | [Testing](testing.md) |

## If a step fails

| Symptom | Cause |
|---|---|
| `No LnkFlow API token is configured.` | that client uses `LNKFLOW_API_TOKEN`; set it |
| 403 on preview or create | the token has no `write` ability |
| 404 on a resource you can see in the dashboard | wrong team; `LNKFLOW_TEAM` is the numeric id |
| 422 `IDEMPOTENCY_KEY_REUSED` | the same key with a different payload — use `?run=v2` |
| short URL 404s | the link has not published to the edge yet; check `edge_status` |
| landing page shows no click id | you did not arrive through the short URL, or conversion tracking is off on the link |

More in [Troubleshooting](troubleshooting.md).
