# Link management

A campaign is a marketing container. A campaign link is the shareable, tracked
redirect unit. Create or find a campaign, then manage links under it.

```php
use LnkFlow\Laravel\Data\CreateCampaign;
use LnkFlow\Laravel\Data\CreateLink;

$campaign = $client->campaigns()->create(
    new CreateCampaign('Newsletter', websiteId: 12),
    idempotencyKey: 'newsletter:campaign',
);

$preview = $client->links()->preview(
    new CreateLink('https://example.com/news', slug: 'news'),
    campaignId: $campaign->id,
);

$link = $client->links()->create(
    $campaign->id,
    new CreateLink(
        destinationUrl: 'https://example.com/news',
        slug: 'news',
        utm: ['utm_source' => 'email'],
        conversionTrackingEnabled: true,
        autoPromoCode: 'NEWS20',
    ),
    idempotencyKey: 'newsletter:primary-link',
);

$link->shortUrl;   // the canonical URL to share
```

`POST /campaigns` can still create a campaign and its first link in one call
when a destination is supplied. Do not build on that: it is legacy
compatibility, and it is the reason for the slug confusion below.

## Two slugs

The API's `slug` field on a campaign is the **primary link's** slug, kept for
the legacy single-link payload shape. The campaign's own slug is
`campaign_slug`. The SDK names them for what they are:

| Property | API field | Meaning |
|---|---|---|
| `Campaign::$slug` | `campaign_slug` | the campaign's own slug |
| `Campaign::$primaryLinkSlug` | `slug` | the primary link's slug, null when the campaign has no links |
| `Link::$slug` | `slug` | the link's slug — the one in the short URL |

`CreateCampaign::$slug` is sent as `campaign_slug`, so it sets the campaign
slug, not a link slug.

## `UpdateCampaign` rejects `slug` — on purpose

```php
new UpdateCampaign(['slug' => 'new-slug']);
// InvalidArgumentException
```

**`PATCH /campaigns/{id}` forwards a slug into the campaign's primary link as
well.** That rewrites the live short URL: every link already shared — in an
email, a printed QR code, a creator's bio — starts 404ing, and there is no undo
because the old slug is simply gone. The SDK refuses the field rather than let a
campaign rename silently break production traffic.

If renaming the link really is the intent, say so explicitly:

```php
$client->links()->update($linkId, new UpdateLink(['slug' => 'new-slug']));
```

and accept that the old short URL stops resolving. Prefer creating a new link
over renaming a published one.

`is_active` **is** allowed on `UpdateCampaign`, and it is forwarded to the
primary link too — deactivating a campaign also pauses its primary link. That
one is intended.

`UpdateCampaign` and `UpdateLink` both reject unknown keys at construction, so a
typo fails where you wrote it rather than as a 422 inside a queued job. The
allowed key lists are in the [API index](api-index.md).

## Idempotency keys

`campaigns()->create()` and `links()->create()` require a key you own. Persist
it beside your local mapping **before** the first request, and reuse it for
every retry of the same logical create. Never generate a new key per attempt —
that is how a timeout becomes two campaigns.

The server keeps the first successful response for 48 hours:

- an identical retry replays it, and `->replayed()` is true;
- a concurrent duplicate returns 409 `IDEMPOTENCY_IN_PROGRESS`, which is the one
  4xx the transport retries for you;
- the same key with a different payload returns 422 `IDEMPOTENCY_KEY_REUSED` —
  fix the key, not the payload;
- authentication and validation failures do not reserve the key.

```php
$link = $client->links()->create($campaignId, $payload, $mapping->idempotency_key);

if ($link->replayed()) {
    // the link already existed; this was a replay, not a new create
}
```

## Preview

`links()->preview()` creates nothing — no campaign, link, influencer, click, or
domain. It returns the generated short URL, the destination with UTMs applied,
the selected domain and why it was selected, resolved website/campaign/
influencer context, warnings, and a `will_create` map.

**It still requires a `write` token.** It previews a write intent and runs the
same validation as a create, so a read-only token gets a 403. This is why
`lnkflow:sync --dry-run` needs a write token even though it writes nothing.

Pass exactly one of `campaignId` (an existing campaign) or `campaignName` (a
proposed one). The response is untyped; read `$preview->raw`:

```php
$preview = $client->links()->preview(
    new CreateLink('https://example.com/news', slug: 'news'),
    campaignName: 'Newsletter',
);

$preview->raw['short_url'];
$preview->raw['destination_url_with_utm'];
$preview->raw['selected_domain'];   // ['type' => 'custom', 'source' => 'website_default', ...]
$preview->raw['warnings'];          // compact advisory objects
$preview->raw['will_create'];       // ['campaign' => true, 'link' => true, ...]
```

Warnings are advisory, not errors. Current safe codes include
`inactive_website_selected`, `website_ignored_for_existing_campaign`,
`campaign_default_destination_used`, `explicit_main_domain`,
`campaign_default_domain_unusable`, `website_default_domain_unusable`,
`main_domain_selected`, and `generated_slug_adjusted`. Treat the list as open.

## UTM parameters

Only five keys are accepted: `utm_source`, `utm_medium`, `utm_campaign`,
`utm_term`, `utm_content` (`Utm::KEYS`). `CreateLink` and `LinkDefinition`
validate at construction, so `utm_id` throws immediately instead of 422-ing
later from a background job.

UTMs are appended server-side at redirect time. The shared short URL never shows
them, which is the point — they cannot be stripped or flagged in transit.

On update the merge is per key: present-with-value sets, present as
`null`/empty removes, omitted leaves unchanged.

When `utm_campaign` is omitted on create, the server defaults it to the parent
campaign name.

## `active` and `conversionTrackingEnabled` are nullable

Both default to `null` on `CreateLink` and `LinkDefinition`, which **omits** the
field from the payload.

That matters most on update. If the SDK always sent `is_active: true`, a link
someone paused in the LnkFlow dashboard would silently un-pause on the next
content change; if it always sent `conversion_tracking_enabled: false`,
switching conversion tracking on in the dashboard would be undone by the next
save. Setting either one is a claim that the host application owns that state.
Leave them null unless it does.

## Domains, edge status, and deletion

`custom_domain_id` selects a branded domain. Only a domain where
`Domain::$usable` is true can serve links — `active` alone does not mean the
certificate has issued:

```php
$usable = $client->domains()->list(usable: true);
```

Explicit `null` on `custom_domain_id` forces the main domain; omitting it
inherits the campaign or website default.

`edgeStatus` is asynchronous publication state, and an open string: new server
states may be added. A successful API write does not mean the edge has the link
yet. Treat an unknown value as "not published yet", never as failure.
`Link::published()` is true only for `published`.

Prefer `deactivate()` to `delete()`. `DELETE /links/{id}` also destroys the
link's click history, and `DELETE /campaigns/{id}` cascades to every link under
it. The package's own CMS automation never calls delete — it deactivates.

## Resolving ids

Ids differ per team and per environment, so resolve names rather than hardcoding
them:

```php
$website = $client->search()->first('Example Site', 'website');
$influencer = $client->search()->first('Maya Chen', 'influencer');

// or one round trip for everything
$workspace = $client->workspace()->bootstrap();
$workspace->websites; $workspace->domains; $workspace->influencers;
```

If several plausible matches come back, ask a human rather than guessing.

## Websites and influencers

Both take DTOs, not arrays:

```php
use LnkFlow\Laravel\Data\CreateInfluencer;
use LnkFlow\Laravel\Data\CreateWebsite;
use LnkFlow\Laravel\Data\SocialPlatform;
use LnkFlow\Laravel\Data\UpdateWebsite;

$website = $client->websites()->create(new CreateWebsite('Example Site', domain: 'example.com'));
$client->websites()->update($website->id, new UpdateWebsite(['is_active' => false]));

$influencer = $client->influencers()->create(new CreateInfluencer(
    'Maya Chen',
    primaryPlatform: SocialPlatform::Instagram,
    primaryHandle: 'mayachen',
    socialLinks: ['instagram' => 'https://instagram.com/mayachen'],
));
```

A social profile URL belongs in `socialLinks`; `websiteUrl` is for a separate
creator-owned website. `primaryPlatform` accepts the `SocialPlatform` enum or a
plain string, so a platform added server-side works without an SDK upgrade.

Neither resource has a delete endpoint in v1. Set `is_active` false instead.

## Related

- [API index](api-index.md) — every method and DTO field.
- [Token scopes](token-scopes.md) — why `preview()` needs `write`.
- [CMS sync](cms-sync.md) — the same operations, reconciled from Eloquent.
