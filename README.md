# LnkFlow Laravel

The official Laravel SDK for the LnkFlow `/api/v1` contract: link management,
CMS synchronization, consent-aware journeys, identity lifecycle, and conversion
reporting.

Requires PHP 8.2+ and Laravel 12 or 13. The `0.x` package line targets LnkFlow
API v1.

## What this package does

- A typed, least-privilege HTTP client for the v1 endpoints the SDK covers —
  campaigns, links, websites, influencers, domains, search, workspace, stats,
  journeys, and conversions. See [API index](docs/api-index.md).
- Bounded retries, `Retry-After` handling, request correlation, and a typed
  exception hierarchy. See [Errors](docs/errors.md).
- Optional automation, all off by default: Eloquent content synchronization,
  journey capture, auth identify/unidentify, and queued conversion reporting.
- A `<x-lnkflow-script />` Blade component that emits the hosted browser
  snippet. See [Browser bridge](docs/browser-script.md).
- A no-network fake for host application tests.

## What this package does not do

- It does not serve redirects. Clicks are served by LnkFlow's Cloudflare Worker
  at the edge; this SDK only reads and writes through the API.
- It does not move money. The influencer commission ledger is reporting only.
- It does not decide consent. The default resolver returns `unknown`, which
  stores and sends nothing until the host binds its own CMP decision.
- It does not manage custom domains, SSL, billing, or team membership. Those are
  dashboard operations.
- It does not convert currency. Amounts are integer minor units with a
  per-event currency code.

## Install

```bash
composer require lnkflow/laravel
php artisan lnkflow:install --preset=client
php artisan migrate
php artisan lnkflow:doctor
```

Presets are `client`, `links`, `content`, `journeys`, `conversions`, and
`full`. Installation publishes configuration and the mapping migrations, never
writes a secret, and never enables Cashier. `client` and `links` enable no
feature flags at all — direct client calls never need one.

Configure the smallest token scope the application needs:

```dotenv
LNKFLOW_API_URL=https://app.lnkflow.io/api/v1

# The NUMERIC LnkFlow team id. Not a user id, not a slug, not a public id.
LNKFLOW_TEAM=42

# The numeric website id this application reports against (optional).
LNKFLOW_WEBSITE=123

# Reads plus link/resource writes. Ability: read,write.
LNKFLOW_LINK_TOKEN=...

# Journeys and conversions. Ability: read,conversions.
LNKFLOW_CONVERSION_TOKEN=...

# Reads that belong to neither of the above — identity, search, workspace, and
# every stats endpoint — and the fallback for the two tokens above.
LNKFLOW_API_TOKEN=...
```

Find the numeric team id with `GET /me`, which lists every team the token can
reach:

```php
$me = LnkFlow::client()->identity()->me();

$me->id;            // the USER id — not the team id
$me->raw['teams'];  // [['id' => 42, 'name' => 'Acme', 'role' => 'owner'], ...]
$me->can('write');  // effective token abilities
```

`LNKFLOW_API_TOKEN` is not optional in practice: `identity()`, `search()`,
`workspace()`, and `stats()` use it and do **not** fall back to the link or
conversion token. `lnkflow:doctor` calls `identity()->me()`, so a setup with
only the two narrow tokens passes doctor's configuration checks and then fails
its connectivity check. See [Token scopes](docs/token-scopes.md).

## Typed client

```php
use LnkFlow\Laravel\Data\CreateCampaign;
use LnkFlow\Laravel\Data\CreateLink;
use LnkFlow\Laravel\Facades\LnkFlow;

$client = LnkFlow::forTeam(42);

$campaign = $client->campaigns()->create(
    new CreateCampaign('Product launch', websiteId: 123),
    idempotencyKey: 'cms:campaign:product-launch',
);

$link = $client->links()->create(
    $campaign->id,
    new CreateLink(
        destinationUrl: 'https://example.com/launch',
        slug: 'launch',
        utm: ['utm_source' => 'newsletter'],
        conversionTrackingEnabled: true,
        autoPromoCode: 'LAUNCH20',
    ),
    idempotencyKey: 'cms:link:product-launch:primary',
);

$link->shortUrl;   // the canonical URL to share
$link->replayed(); // true when the server replayed a stored response
```

`connection('name')` selects a named configuration; `forTeam($id)` sends an
explicit `X-LnkFlow-Team`. The SDK never silently chooses another team. Every
read model keeps the complete decoded payload in `->raw`, so a field the server
adds stays reachable without an SDK upgrade.

## Preset quickstarts

- `client`: typed, synchronous API access only — [Client usage](docs/client.md).
- `links`: client plus link management — [Links](docs/links.md).
- `content`: queued Eloquent reconciliation — [CMS sync](docs/cms-sync.md).
- `journeys`: consent-gated touchpoints and identity — [Journeys and consent](docs/journeys-and-consent.md).
- `conversions`: queued events, leads, sales, refunds — [Conversions](docs/conversions.md).
- `full`: everything; each host binding and worker requirement still applies.

Queues are required for content, journey, auth, and conversion automation.
Workers must share the application database and cache. Observers dispatch after
commit; nothing performs remote HTTP during a model save or a page request.

## Manual conversions

```php
use LnkFlow\Laravel\Data\NamedEvent;
use LnkFlow\Laravel\Data\Refund;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Facades\LnkFlow;

LnkFlow::trackEvent(new NamedEvent('trial_started', 'customer_opaque_42'));
LnkFlow::trackSale(new Sale('invoice_1042', 1299, 'usd', 'customer_opaque_42'));
LnkFlow::trackRefund(new Refund('invoice_1042'));                // full refund
LnkFlow::trackRefund(new Refund('invoice_1042', 'ref_7', 500));  // partial
```

Amounts are integer minor currency units. Stable invoice and refund identifiers
are what make retries safe. Refunds carry no currency — the original sale's
currency applies. Choose exactly one Stripe reporting owner: either LnkFlow's
direct Stripe webhook or this package's Cashier adapter, never both. See
[Cashier](docs/cashier.md).

## Testing

```php
use LnkFlow\Laravel\Facades\LnkFlow;

LnkFlow::fake();

// exercise application code

LnkFlow::assertLinkCreated();
LnkFlow::assertSaleTracked();
LnkFlow::assertNothingSent();
```

The fake replaces the transport and performs no network calls. See
[Testing](docs/testing.md).

## Operations and security

`php artisan lnkflow:doctor` is read-only. `lnkflow:sync --dry-run` writes
nothing but still needs a **write**-ability token, because it validates through
`POST /links/preview`. `lnkflow:verify --test-conversion` is deliberately
mutating and requires confirmation or `--force`.

The transport retries only connection failures, 408, 429, 5xx, and the single
retryable 409 (`IDEMPOTENCY_IN_PROGRESS`). POST retries require an
`Idempotency-Key` or an endpoint stable business key. Never log bearer tokens or
raw request bodies; the SDK's own diagnostics (`LNKFLOW_LOGGING=true`) carry
endpoint, status, attempt, duration, team, and request id only. See
[Troubleshooting](docs/troubleshooting.md).

## Documentation

| Guide | Contents |
|---|---|
| [Tutorial](docs/tutorial.md) | `composer require` to a live short URL and a verified test conversion |
| [API index](docs/api-index.md) | every client, method, signature, return type, and required ability |
| [Token scopes](docs/token-scopes.md) | which token each call needs, and the traps |
| [Client usage](docs/client.md) | transport, responses, pagination, teams, connections |
| [Links](docs/links.md) | campaigns, links, slugs, previews, idempotency |
| [CMS sync](docs/cms-sync.md) | Eloquent adapters, mappings, reconciliation |
| [Journeys and consent](docs/journeys-and-consent.md) | capture, identity, revocation |
| [Browser bridge](docs/browser-script.md) | `<x-lnkflow-script />`, storage modes, CMP wiring |
| [Conversions](docs/conversions.md) | events, leads, sales, refunds, mappers |
| [Cashier](docs/cashier.md) | the optional Stripe adapter |
| [Errors](docs/errors.md) | exception reference and retry semantics |
| [Testing](docs/testing.md) | the fake, assertions, transport tests |
| [Troubleshooting](docs/troubleshooting.md) | doctor, symptoms, production hardening |
| [Upgrading](docs/upgrading.md) | compatibility policy |

### Upstream API contract

This SDK is a client of a contract it does not own. When the two disagree, the
server wins. In the LnkFlow SaaS repository (`AppitStudio/lnkflow.io`):

- `docs/api-reference.md` — the human v1 contract.
- `docs/openapi/lnkflow-v1.yaml` — the machine-readable v1 contract.
- `PRPs/integrations/base-context.md` — the shared invariants every LnkFlow
  integration must satisfy.
- `docs/integrations/README.md` — the integrations index and compatibility
  matrix.
- `docs/integrations/conformance-checklist.md` — the reviewer checklist.

A live, token-personalized copy of the reference is in the dashboard under
**API Docs** (`https://app.lnkflow.io/docs/api`).

## Versioning

The SDK follows SemVer. Additive API fields are tolerated. Breaking public PHP
or API-contract changes require a package major version or a documented
deprecation window. See [Upgrading](docs/upgrading.md).

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md), and
[CHANGELOG.md](CHANGELOG.md).

LnkFlow Laravel is licensed under the [MIT License](LICENSE.md).
