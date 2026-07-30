# API index

Every public entry point in the SDK: the method, its signature, what it
returns, the v1 endpoint behind it, and the token ability that endpoint
requires. Nothing here is inferred — it is read from `src/`.

Abilities are the server's, not the SDK's. `read` means "any valid token for
the team". `write` and `conversions` are token abilities created in
Settings → API Tokens; a `write` token also satisfies `conversions`. Which
*configured* token the SDK picks for a call is a separate question — see
[Token scopes](token-scopes.md) — and getting it wrong produces a 403 or
`ConnectionException('No LnkFlow API token is configured.')`, not a silent
fallback.

## Entry points

```php
use LnkFlow\Laravel\Facades\LnkFlow;
use LnkFlow\Laravel\Services\Client;

$client = LnkFlow::client();          // or: app(Client::class)
$client = LnkFlow::connection('eu');  // named connection
$client = LnkFlow::forTeam(42);       // explicit X-LnkFlow-Team
```

| Facade method | Returns | Notes |
|---|---|---|
| `LnkFlow::client()` | `Client` | the shared client for the default connection |
| `LnkFlow::connection(string $connection)` | `Client` | selects `lnkflow.connections.{name}` |
| `LnkFlow::forTeam(int\|string\|null $team)` | `Client` | `null` falls back to the configured team; it does not clear it |
| `LnkFlow::trackEvent(NamedEvent $event)` | `void` | **queued** after commit |
| `LnkFlow::trackLead(Lead $lead)` | `void` | **queued** after commit |
| `LnkFlow::trackSale(Sale $sale)` | `void` | **queued** after commit |
| `LnkFlow::trackRefund(Refund $refund)` | `void` | **queued** after commit |
| `LnkFlow::fake()` | `FakeTransport` | see [Testing](testing.md) |

The four `track*` methods dispatch through `ConversionDispatcher`. Under
`LnkFlow::fake()` they call the client synchronously instead, so a test does not
need a queue. They always use the **default** connection and configured team;
`forTeam()` does not affect them, because the job resolves its own client.

## `Client`

| Method | Returns | Transport purpose |
|---|---|---|
| `connection(string $connection)` | `Client` | — |
| `forTeam(int\|string\|null $team)` | `Client` | — |
| `identity()` | `IdentityClient` | `api` |
| `campaigns()` | `CampaignsClient` | `links` |
| `links()` | `LinksClient` | `links` |
| `websites()` | `WebsitesClient` | `links` |
| `domains()` | `DomainsClient` | `links` |
| `influencers()` | `InfluencersClient` | `links` |
| `search()` | `SearchClient` | `api` |
| `workspace()` | `WorkspaceClient` | `api` |
| `stats()` | `StatsClient` | `api` |
| `journeys()` | `JourneysClient` | `journeys` |
| `conversions()` | `ConversionsClient` | `conversions` |

"Transport purpose" decides which configured token is used. `links` prefers
`link_token`, `journeys`/`conversions` prefer `conversion_token`, `api` uses
`api_token` only.

## `identity()` — `IdentityClient`

| Method | Returns | Endpoint | Ability |
|---|---|---|---|
| `me()` | `Identity` | `GET /me` | read |

`Identity::$id` is the **user** id. `Identity::can(string $ability): bool`
reports effective `read` / `write` / `conversions`. The accessible teams are in
`->raw['teams']`.

## `campaigns()` — `CampaignsClient`

| Method | Returns | Endpoint | Ability |
|---|---|---|---|
| `list(array $filters = [])` | `Page<Campaign>` | `GET /campaigns` | read |
| `get(int $id)` | `Campaign` | `GET /campaigns/{id}` | read |
| `create(CreateCampaign $request, string $idempotencyKey)` | `Campaign` | `POST /campaigns` | **write** |
| `update(int $id, UpdateCampaign $request)` | `Campaign` | `PATCH /campaigns/{id}` | **write** |
| `delete(int $id)` | `void` | `DELETE /campaigns/{id}` | **write** |

`create()` sends `Idempotency-Key`. Check `$campaign->replayed()` to tell a
fresh create from a replay. `delete()` cascades the campaign's links and click
history — prefer `UpdateCampaign(['is_active' => false])`.

Server filters for `list()`: `q`, `slug`, `active`, `website_id`,
`influencer_id`, `social_platform`, `page`, `per_page` (max 100).

## `links()` — `LinksClient`

| Method | Returns | Endpoint | Ability |
|---|---|---|---|
| `list(array $filters = [])` | `Page<Link>` | `GET /links` | read |
| `forCampaign(int $campaignId, array $filters = [])` | `Page<Link>` | `GET /campaigns/{id}/links` | read |
| `get(int $id)` | `Link` | `GET /links/{id}` | read |
| `preview(CreateLink $request, ?int $campaignId = null, ?string $campaignName = null)` | `Resource` | `POST /links/preview` | **write** |
| `create(int $campaignId, CreateLink $request, string $idempotencyKey)` | `Link` | `POST /campaigns/{id}/links` | **write** |
| `update(int $id, UpdateLink $request)` | `Link` | `PATCH /links/{id}` | **write** |
| `deactivate(int $id)` | `Link` | `PATCH /links/{id}` | **write** |
| `delete(int $id)` | `void` | `DELETE /links/{id}` | **write** |

`preview()` has no side effects but still needs a `write` token — it previews a
write intent, so a read-only token gets a 403. Pass exactly one of
`$campaignId` (existing campaign) or `$campaignName` (proposed campaign); the
server requires one of them. The preview payload is not modelled — read it from
`$resource->raw` (`short_url`, `destination_url_with_utm`, `selected_domain`,
`warnings`, `will_create`).

`deactivate()` is `update($id, new UpdateLink(['is_active' => false]))`. It is
the safe alternative to `delete()`, which also destroys click history.

Server filters for `list()`: `q`, `campaign_id`, `website_id`, `influencer_id`,
`social_platform`, `active`, `slug`, `page`, `per_page` (max 100).

## `websites()` — `WebsitesClient`

| Method | Returns | Endpoint | Ability |
|---|---|---|---|
| `list(array $filters = [])` | `Page<Website>` | `GET /websites` | read |
| `get(int $id)` | `Website` | `GET /websites/{id}` | read |
| `create(CreateWebsite $request)` | `Website` | `POST /websites` | **write** |
| `update(int $id, UpdateWebsite $request)` | `Website` | `PATCH /websites/{id}` | **write** |

Create and update take DTOs, not arrays. There is no website delete in v1; set
`is_active` false instead.

## `influencers()` — `InfluencersClient`

| Method | Returns | Endpoint | Ability |
|---|---|---|---|
| `list(array $filters = [])` | `Page<Influencer>` | `GET /influencers` | read |
| `get(int $id)` | `Influencer` | `GET /influencers/{id}` | read |
| `create(CreateInfluencer $request)` | `Influencer` | `POST /influencers` | **write** |
| `update(int $id, UpdateInfluencer $request)` | `Influencer` | `PATCH /influencers/{id}` | **write** |
| `commissions(int $id, array $filters = [])` | `Page<Commission>` | `GET /influencers/{id}/commissions` | read |
| `commissionsCsv(int $id, array $filters = [])` | `string` | `GET /influencers/{id}/commissions?export=csv` | read |

Commission filters: `status`, `from`, `to`, `per_page` (max 100).
`commissionsCsv()` returns the response body verbatim so it can be streamed
straight to a download. The ledger is reporting only — a negative
`commissionAmountCents` is a clawback row, and nothing in LnkFlow moves money.

There is no influencer delete in v1.

## `domains()` — `DomainsClient`

| Method | Returns | Endpoint | Ability |
|---|---|---|---|
| `list(bool $usable = false)` | `list<Domain>` | `GET /domains` | read |

Not paginated — it returns a plain array. Only a domain with `$domain->usable`
true can be attached to a link: `active` alone does not mean the certificate has
issued.

## `search()` — `SearchClient`

| Method | Returns | Endpoint | Ability |
|---|---|---|---|
| `query(string $query, array $types = [], int $limit = 10)` | `list<SearchMatch>` | `GET /search` | read |
| `first(string $query, string $type)` | `?SearchMatch` | `GET /search` | read |

`SearchClient::TYPES` is `['website', 'campaign', 'link', 'influencer',
'domain']`. Resolve a name to an id here before writing rather than hardcoding
ids — they differ per team and per environment.

```php
$website = $client->search()->first('Example Site', 'website');
$websiteId = $website?->id;
```

## `workspace()` — `WorkspaceClient`

| Method | Returns | Endpoint | Ability |
|---|---|---|---|
| `bootstrap()` | `Workspace` | `GET /browser-extension/bootstrap` | read |

Websites, domains, influencers, and accessible teams in one round trip.
Cheaper than three list calls when bootstrapping an adapter or a picker.

## `stats()` — `StatsClient`

| Method | Returns | Endpoint | Ability |
|---|---|---|---|
| `summary(array $filters = [])` | `Resource` | `GET /stats/summary` | read |
| `breakdown(array $filters = [])` | `Resource` | `GET /stats/breakdown` | read |
| `compare(array $filters = [])` | `Resource` | `GET /stats/compare` | read |
| `influencers(array $filters = [])` | `Resource` | `GET /stats/influencers` | read |
| `websites(array $filters = [])` | `Resource` | `GET /stats/websites` | read |
| `conversions(array $filters = [])` | `ConversionStats` | `GET /stats/conversions` | read |
| `campaign(int $id, array $filters = [])` | `Resource` | `GET /campaigns/{id}/stats` | read |
| `link(int $id, array $filters = [])` | `Resource` | `GET /links/{id}/stats` | read |

Shared filters: `from`, `to` (`YYYY-MM-DD`, max 366 days), `group_by`,
`compare=previous_period`, `include_bots` (default false — non-human traffic is
excluded), `tz`, and the id filters `website_id`, `campaign_id`, `link_id`,
`influencer_id`, `custom_domain_id`.

Click stats are returned as an untyped `Resource`; read `->raw`. Only
`conversions()` is modelled, because its `has_conversion_data` flag is
load-bearing:

```php
$stats = $client->stats()->conversions(['from' => '2026-07-01']);

if (! $stats->hasConversionData) {
    // structural zeros, not measured zeros — do not render these as revenue
}

$stats->clicks; $stats->leads; $stats->sales; $stats->revenueCents;
$stats->clickToLeadRate; $stats->leadToSaleRate; $stats->clickToSaleRate;
$stats->linkAttributed; $stats->codeAttributed; $stats->manualAttributed;
$stats->series; $stats->funnel; $stats->sourceSplit; $stats->journey; $stats->meta;
```

`revenueCents` is refund-adjusted net revenue. On `GET /stats/conversions` only
`influencer_id`, `campaign_id`, and `link_id` scope the conversion-side
aggregates; website/UTM/platform filters do not.

## `journeys()` — `JourneysClient`

| Method | Returns | Endpoint | Ability |
|---|---|---|---|
| `capture(Touchpoint $touchpoint)` | `Resource` | `POST /journeys/touchpoints` | **conversions** or write |
| `identify(IdentityChange $identity)` | `Resource` | `POST /journeys/identify` | **conversions** or write |
| `unidentify(Visitor $visitor)` | `Resource` | `POST /journeys/unidentify` | **conversions** or write |
| `revoke(Visitor $visitor)` | `Resource` | `POST /journeys/revoke` | **conversions** or write |

`unidentify` is logout. `revoke` is consent withdrawal. They are not
interchangeable — see [Journeys and consent](journeys-and-consent.md).

Capture status is deliberately coarse (200 duplicate / 201 resolved / 202
accepted-but-not-attributed). Do not branch business logic on it; read the
conversion journey instead.

## `conversions()` — `ConversionsClient`

| Method | Returns | Endpoint | Ability |
|---|---|---|---|
| `event(NamedEvent $event)` | `ConversionEvent` | `POST /track/lead` | **conversions** or write |
| `lead(Lead $lead)` | `ConversionEvent` | `POST /track/lead` | **conversions** or write |
| `sale(Sale $sale)` | `ConversionEvent` | `POST /track/sale` | **conversions** or write |
| `refund(Refund $refund)` | `ConversionEvent` | `POST /track/refund` | **conversions** or write |
| `events(array $filters = [])` | `list<ConversionEvent>` | `GET /track/events` | read |
| `journey(int $eventId)` | `Resource` | `GET /track/events/{id}/journey` | read |
| `send(string $type, Payload $payload, string $businessId)` | `ConversionEvent` | one of the three `track/*` | **conversions** or write |

These four write methods are **synchronous**. `LnkFlow::trackSale()` and friends
are the queued equivalents; prefer them anywhere a user is waiting.

`send()` exists for the queued path: `SendConversionJob` holds a payload it must
not rebuild (rebuilding would drop the journey context captured at dispatch
time), so it passes the payload through verbatim. `$type` is `lead`, `event`,
`sale`, or `refund`; anything else throws `InvalidArgumentException`.

`events()` filters: `type` (`lead|sale|refund`), `test`, `limit` (max 100). It
needs no special ability, which makes it the verification loop for a new
integration.

## Write DTOs

Constructor signatures, exactly as declared. Every one implements
`LnkFlow\Laravel\Contracts\Payload` (`toArray(): array`). Null-valued optional
fields are omitted from the request body.

### `CreateCampaign`

```php
new CreateCampaign(
    string $name,
    ?string $slug = null,          // sent as campaign_slug — the CAMPAIGN's slug
    ?string $description = null,
    ?int $websiteId = null,
    ?bool $active = null,
);
```

### `UpdateCampaign`

```php
new UpdateCampaign(array $changes);
```

Allowed keys: `name`, `title`, `description`, `default_destination_url`,
`default_custom_domain_id`, `starts_at`, `ends_at`, `website_id`, `is_active`.
Anything else throws `InvalidArgumentException` at construction. `slug` throws
with its own explanation — see [Links](links.md).

### `CreateLink`

```php
new CreateLink(
    string $destinationUrl,
    ?string $name = null,
    ?string $slug = null,
    array $utm = [],                        // validated against Utm::KEYS
    ?int $customDomainId = null,
    ?int $influencerId = null,
    ?string $socialPlatform = null,
    ?bool $active = null,                   // null = omit, server default applies
    ?bool $conversionTrackingEnabled = null, // null = omit
    ?string $autoPromoCode = null,
);
```

### `UpdateLink`

```php
new UpdateLink(array $changes);
```

Allowed keys: `destination_url`, `name`, `title`, `slug`, `custom_domain_id`,
`influencer_id`, `social_platform`, `is_active`, `conversion_tracking_enabled`,
`auto_promo_code`, plus the five `Utm::KEYS`.

UTM merge is per key on the server: present-with-value sets, present as
`null`/empty removes, omitted leaves unchanged.

### `Utm`

`Utm::KEYS` is `['utm_source', 'utm_medium', 'utm_campaign', 'utm_term',
'utm_content']`. `Utm::validate(array $utm): array` throws
`InvalidArgumentException` on any other key, and `CreateLink` /
`LinkDefinition` call it at construction so a typo like `utm_id` fails at
authoring time rather than inside a queued job.

### `CreateWebsite` / `UpdateWebsite`

```php
new CreateWebsite(
    string $name,
    ?string $domain = null,
    ?string $description = null,
    ?bool $active = null,
);

new UpdateWebsite(array $changes); // name, domain, description, is_active
```

### `CreateInfluencer` / `UpdateInfluencer`

```php
new CreateInfluencer(
    string $name,
    ?string $slug = null,
    SocialPlatform|string|null $primaryPlatform = null,
    ?string $primaryHandle = null,
    ?string $contactEmail = null,
    ?string $websiteUrl = null,
    array $socialLinks = [],   // ['instagram' => 'https://instagram.com/handle']
    array $metadata = [],
    ?string $notes = null,
    ?bool $active = null,
);

new UpdateInfluencer(array $changes);
```

`UpdateInfluencer` allowed keys: `name`, `slug`, `primary_platform`,
`primary_handle`, `contact_email`, `website_url`, `social_links`, `metadata`,
`notes`, `is_active`. A `SocialPlatform` enum passed as `primary_platform` is
converted to its string value.

`SocialPlatform` is a convenience enum (`Instagram`, `TikTok`, `YouTube`,
`Facebook`, `LinkedIn`, `X`, `WhatsApp`, `Email`, `MetaAds`, `GoogleAds`).
Every surface that takes a platform also accepts a plain string, so a platform
added server-side keeps working without an SDK upgrade.

`websiteUrl` is for a creator-owned website. A social profile belongs in
`socialLinks`.

### `LinkDefinition`

The CMS-sync unit. Same fields as `CreateLink` plus `placement`, `campaignKey`,
`campaignName`, and `websiteId`; `createLink(): CreateLink` projects it.

```php
new LinkDefinition(
    string $placement,
    string $campaignKey,
    string $campaignName,
    string $destinationUrl,
    ?string $name = null,
    ?string $slug = null,
    array $utm = [],
    ?int $websiteId = null,
    ?int $customDomainId = null,
    ?int $influencerId = null,
    ?string $socialPlatform = null,
    ?bool $active = null,
    ?bool $conversionTrackingEnabled = null,
    ?string $autoPromoCode = null,
);
```

## Conversion payloads

`context` is the last argument on all four and is an escape hatch for fields
this SDK version does not type yet. Precedence in `toArray()` is journey
context < `$context` < typed properties, so an explicitly passed `clickId` is
never replaced by whatever is in the session.

### `Lead`

```php
new Lead(
    string $customerExternalId,
    string $eventName = 'lead',
    ?string $clickId = null,
    ?string $visitorId = null,
    ?string $firstClickId = null,
    ?string $lastClickId = null,
    ?int $websiteId = null,
    ?Consent $consent = null,
    ?array $metadata = null,
    ?DateTimeInterface $occurredAt = null,
    ?bool $test = null,
    ?string $customerEmail = null,
    ?string $customerName = null,
    ?string $metaEventId = null,
    array $context = [],
);
```

`customerEmail` and `customerName` are opt-in personal data: LnkFlow does not
need them to attribute a conversion.

### `NamedEvent`

```php
new NamedEvent(
    string $name,
    string $customerExternalId,
    ?string $clickId = null,
    ?string $visitorId = null,
    ?string $firstClickId = null,
    ?string $lastClickId = null,
    ?int $websiteId = null,
    ?Consent $consent = null,
    ?array $metadata = null,
    ?DateTimeInterface $occurredAt = null,
    ?bool $test = null,
    array $context = [],
);
```

Serializes to a `Lead` with `event_name = $name`. That is how LnkFlow models
custom non-monetary events.

### `Sale`

```php
new Sale(
    string $invoiceId,
    int $amount,                 // integer minor units; negative throws
    string $currency,            // lowercased on serialization
    ?string $customerExternalId = null,
    ?string $eventName = null,
    ?string $clickId = null,
    ?string $visitorId = null,
    ?string $firstClickId = null,
    ?string $lastClickId = null,
    ?int $websiteId = null,
    ?string $paymentProcessor = null,
    ?string $promoCode = null,
    ?Consent $consent = null,
    ?array $metadata = null,
    ?DateTimeInterface $occurredAt = null,
    ?bool $test = null,
    ?string $metaEventId = null,
    array $context = [],
);
```

`$invoiceId` is the idempotency anchor. `$metaEventId` is the Meta CAPI
deduplication id and must match the id the browser Pixel sent for the same
purchase; leave it null unless you are genuinely mirroring a Pixel event.

### `Refund`

```php
new Refund(
    string $invoiceId,           // the original sale's invoice id
    ?string $refundId = null,    // null = the idempotent full-refund case
    ?int $amount = null,         // null = full refund; < 1 throws
    ?string $eventName = null,
    ?string $paymentProcessor = null,
    ?array $metadata = null,
    ?DateTimeInterface $occurredAt = null,
    ?bool $test = null,
    array $context = [],
);
```

There is no `currency`: the refund endpoint does not accept one, and the
original sale's currency applies. `businessId(): string` returns
`$refundId ?? "{$invoiceId}:refund"` — the stable key used for retry-safe
dispatch, and the same default the server derives.

### `Consent`

```php
new Consent(
    ConsentState $storage = ConsentState::Unknown,
    ConsentState $adUserData = ConsentState::Unknown,
    ConsentState $adPersonalization = ConsentState::Unknown,
    ?int $revision = null,
    ?string $evidenceId = null,
);

Consent::fromArray(array $raw): self;
$consent->granted(): bool;   // storage === Granted
```

`ConsentState` is `Granted` / `Denied` / `Unknown`. Unknown is treated as no
consent.

### Journey payloads

```php
new Touchpoint(
    string $visitorId,
    string $clickId,
    Consent $consent,            // an object, not an array
    ?int $websiteId = null,
    ?DateTimeInterface $capturedAt = null,
    ?string $captureMethod = 'backend',
);

new IdentityChange(
    string $visitorId,
    string $customerExternalId,
    ?int $websiteId = null,
    ?DateTimeImmutable $boundAt = null,
);

new Visitor(
    string $visitorId,
    ?int $websiteId = null,
    ?DateTimeImmutable $occurredAt = null,
);
```

`capturedAt` must not be in the future and must be within seven days
(`journeys.capture_timestamp_tolerance_days` on the server).

### `EnrichedPayload`

```php
new EnrichedPayload(Payload $payload, array $context = []);
$enriched->inner(): Payload;
```

Wraps a payload with journey context underneath it. `ConversionDispatcher` uses
it so a queued conversion keeps the context from the request that produced it.
Refunds are deliberately not enriched — they attribute through the original
sale.

## Read models

All extend `ApiObject`:

| Member | Type | Notes |
|---|---|---|
| `->raw` | `array` | the complete decoded payload |
| `->get(string $key, mixed $default = null)` | `mixed` | raw accessor |
| `->replayed()` | `bool` | true when the server replayed a stored idempotent response |
| `->requestId()` | `?string` | `X-LnkFlow-Request-Id` from the write that produced this object |

`replayed()` and `requestId()` are only populated on objects returned by a
write — `Link`, `Campaign`, `ConversionEvent`, and `Resource`. On objects from a
read they are `false` / `null`.

### `Campaign`

`id`, `name`, **`slug`** (the campaign's own slug, API field `campaign_slug`),
**`primaryLinkSlug`** (the primary link's slug, API field `slug`),
`description`, `defaultDestinationUrl`, `defaultCustomDomainId`, `shortUrl`,
`defaultShortUrl`, `customDomainUrl`, `edgeStatus`, `edgePublishedAt`,
`destinationUrl`, `destinationUrlWithUtm`, `utmParameters`, `websiteId`,
`websiteName`, `influencerId`, `influencerName`, `socialPlatform`, `active`,
`linksCount`, `activeLinksCount`, `totalClicks`, `primaryLink` (`?Link`),
`links` (`list<Link>`), `startsAt`, `endsAt`, `createdAt`, `updatedAt`.

### `Link`

`id`, `campaignId`, `campaignName`, `name`, `slug`, `shortUrl`,
`defaultShortUrl`, `customDomainUrl`, `edgeStatus`, `edgePublishedAt`,
`edgePublishFailedAt`, `customDomainId`, `customDomain`, `socialPlatform`,
`destinationUrl`, `destinationUrlWithUtm`, `utmParameters`, `influencerId`,
`influencerName`, `active`, `conversionTrackingEnabled`, `autoPromoCode`,
`totalClicks`, `createdAt`, `updatedAt`, and `published(): bool`
(`edgeStatus === 'published'`).

`edgeStatus` is an open string — new server states may be added, so treat
anything unknown as "not published yet", never as failure.

### `Website`

`id`, `name`, `domain`, `description`, `defaultCustomDomainId`,
`defaultCustomDomain`, `active`, `createdAt`.

### `Influencer`

`id`, `name`, `slug`, `primaryPlatform`, `primaryHandle`, `displayHandle`,
`contactEmail`, `websiteUrl`, `socialLinks`, `metadata`, `notes`, `active`,
`campaignsCount`, `linksCount`, `activeLinksCount`, `totalClicks`,
`salesCount`, `totalRevenueCents`, `createdAt`, `updatedAt`.

`salesCount` and `totalRevenueCents` are null unless the endpoint eager-loaded
conversion aggregates. Null means "not loaded", not zero.

### `Domain`

`id`, `domain`, `url`, `active`, `verified`, `usable`, `sslStatus`, `status`,
`createdAt`.

### `Commission`

`id`, `status`, `commissionAmountCents`, `saleAmountCents`, `currency`,
`reason`, `eventName`, `occurredAt`, `relatedCommissionId`, `approvedAt`,
`createdAt`.

### `ConversionEvent`

`id`, `type` (`lead|sale|refund`), `eventName`, `amountCents`, `currency`,
`attributionSource` (`link|code|manual`), `test`, `suspectedBot`, `fraudFlags`,
`occurredAt`, `linkId`, `campaignId`, `influencerId`, `journey`.

A promo code beats a click: when both attribute the same sale,
`attributionSource` is `code` and the code's influencer is credited.

### `ConversionStats`

`hasConversionData`, `clicks`, `leads`, `sales`, `revenueCents`,
`clickToLeadRate`, `leadToSaleRate`, `clickToSaleRate`, `series`,
`linkAttributed`, `codeAttributed`, `manualAttributed`, `codeSharePercent`,
`funnel`, `sourceSplit`, `journey`, `meta`.

### `SearchMatch`

`type`, `id`, `label`, `metadata`, and `is(string $type): bool`.

### `Workspace`

`websites` (`list<Website>`), `domains` (`list<Domain>`), `influencers`
(`list<Influencer>`), `teams` (`list<array>`).

### `Identity`

`id` (user id), `capabilities` (`array<string, bool>`), `can(string $ability)`.

### `Resource`

The generic model for endpoints the SDK does not type yet: `id` plus `raw`.
Returned by every `stats()` method except `conversions()`, by
`links()->preview()`, by every `journeys()` method, and by
`conversions()->journey()`.

## `Page<T>`

Returned by every paginated list. `Countable` and `IteratorAggregate`, so
`count($page)` and `foreach ($page as $item)` work over the **current page**.

| Member | Returns | Notes |
|---|---|---|
| `->data` | `list<T>` | the current page's items |
| `->meta` | `array` | verbatim, including keys this SDK does not understand |
| `->links` | `array` | verbatim |
| `currentPage()` | `int` | defaults to 1 |
| `lastPage()` | `?int` | |
| `total()` | `?int` | |
| `hasMorePages()` | `bool` | prefers `links.next`, falls back to `lastPage()` |
| `next()` | `?Page<T>` | null at the end, or when the page has no resolver |
| `each()` | `Generator<T>` | lazily walks this page and every page after it |

```php
foreach ($client->links()->list(['per_page' => 100])->each() as $link) {
    // one request per page, fetched on demand
}
```

`each()` issues a request per page. On a large account, filter first.

## Services

Resolve from the container. `Client`, `Transport`, `JourneyContext`,
`ContentSynchronizer`, and `ConversionDispatcher` are registered as singletons;
the rest are resolved on demand.

| Class | Method | Notes |
|---|---|---|
| `Services\ContentSynchronizer` | `sync(string $modelClass, string $modelKey, bool $force = false): int` | reconciles one model's links; returns the number of definitions processed |
| | `preview(string $modelClass, string $modelKey): array` | list of raw preview payloads; **needs a write token** |
| | `disableSource(string $modelClass, string $sourceKey): int` | deactivates every mapped link; never deletes |
| `Services\ConversionDispatcher` | `event/lead/sale/refund(...)` | queues after commit; never performs I/O inline |
| `Services\ConsentRevocationService` | `revoke(): bool` | clears local journey state, queues remote revocation; false when there was no visitor |
| `Services\JourneyContext` | `all()`, `visitorId()`, `replace(array)`, `clear()`, `enrich(array)` | session-backed journey state |
| `Services\ConversionMapperRegistry` | `map(object $event): bool` | returns **false** unless `lnkflow.features.conversions` is true |

## Contracts

| Interface | Method(s) | Bound by default to |
|---|---|---|
| `Contracts\ConsentResolver` | `storage/adUserData/adPersonalization(Request): ConsentState` | `DefaultConsentResolver` (always `Unknown`) |
| `Contracts\CustomerExternalIdResolver` | `resolve(object $user): string` | `DefaultCustomerExternalIdResolver` (`{app-namespace}:{key}`) |
| `Contracts\LinkableContent` | `lnkFlowSourceKey(Model): string`, `lnkFlowLinks(Model): iterable<LinkDefinition>` | nothing — you write it |
| `Contracts\ConversionMapper` | `supports(object): bool`, `map(object, JourneyContext): Lead\|Sale\|Refund\|null` | nothing — you write it |
| `Contracts\Payload` | `toArray(): array` | every write DTO |
| `Contracts\Transport` | `forConnection/forTeam/forPurpose/send` | `ApiTransport`; `FakeTransport` under `LnkFlow::fake()` |

## Events

All carry safe identifiers only — no payloads, tokens, or customer data.

| Event | Constructor |
|---|---|
| `Events\ContentSynchronized` | `(int $mappingId, int $remoteLinkId)` |
| `Events\ContentSynchronizationFailed` | `(int $mappingId, string $exceptionClass)` |
| `Events\ConversionQueued` | `(string $type, string $businessId)` |
| `Events\ConversionSent` | `(string $type, string $businessId, int $remoteEventId)` |
| `Events\ConversionFailed` | `(string $type, string $businessId, string $exceptionClass)` |

## Jobs

| Job | Dispatched by | Retry policy |
|---|---|---|
| `Jobs\SyncLinkableContentJob` | observer, `lnkflow:sync` | 5 tries, `[10, 30, 120, 300]`, unique + non-overlapping per model key |
| `Jobs\DisableLinkableContentJob` | observer on delete | non-overlapping per source key |
| `Jobs\CaptureTouchpointJob` | `CaptureJourneyContext` middleware | `ReportsApiFailures` |
| `Jobs\IdentifyVisitorJob` / `UnidentifyVisitorJob` | `AuthIdentitySubscriber` | `ReportsApiFailures` |
| `Jobs\RevokeVisitorJob` | `ConsentRevocationService` | `ReportsApiFailures` |
| `Jobs\SendConversionJob` | `ConversionDispatcher` | `ReportsApiFailures` |

`Jobs\Concerns\ReportsApiFailures` gives `$tries = 5`, `$backoff = [10, 30,
120, 300]`, and `callApi(Closure)`, which **fails immediately** on
`Authentication`, `Authorization`, `Conflict`, `NotFound`, and `Validation`
exceptions, and **releases** the job for `retryAfter` seconds on
`RateLimitException`. See [Errors](errors.md).

## Middleware and view components

| Class | Purpose |
|---|---|
| `Http\Middleware\CaptureJourneyContext` | consent-gated capture of `lnk_id` / `lnk_promo`; register it by hand in the host's web group, after the session middleware |
| `View\Components\Script` | `<x-lnkflow-script />` — see [Browser bridge](browser-script.md) |

## Commands

| Command | Writes remotely? | Notes |
|---|---|---|
| `lnkflow:install {--preset=}` | no | publishes config + content mapping migrations, rewrites the `features` block; non-content integrations can publish only the `lnkflow-config` tag |
| `lnkflow:doctor` | no | configuration checks plus one `GET /me` |
| `lnkflow:sync {--dry-run} {--model=} {--id=} {--chunk=} {--force}` | yes, unless `--dry-run` | `--dry-run` still calls `POST /links/preview`, so it needs a write token |
| `lnkflow:verify --test-conversion [--force]` | **yes** | creates a retained, labelled test conversion and reads it back |
