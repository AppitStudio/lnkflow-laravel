# Changelog

All notable changes to `lnkflow/laravel` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0-beta.1] - 2026-07-30

The first public beta. Everything below is the initial package surface rather
than a delta from a previously published version.

Entries marked **breaking** changed after the package's first commit and before
this release. They matter only if you have been tracking the `main` branch;
they are called out because working code exists against the earlier shapes.

### Added

- **Typed API client** for the LnkFlow v1 contract, covering identity,
  campaigns, links, websites, domains, influencers, search, workspace, stats,
  journeys, and conversions. Every read model keeps the complete decoded payload
  in `->raw` so additive server fields stay reachable.
- **`Http\ApiResponse`** — a first-class successful response with `status`,
  `body`, retained `headers`, raw `contents`, and `data()`, `collection()`,
  `meta()`, `links()`, `header()`, `requestId()`, `replayed()`.
- **`Exceptions\ErrorCode`** — the API's machine-readable `code` as a backed
  enum, plus `LnkFlowException::code()` and `::is(ErrorCode ...)`. Branch on
  these instead of on `getMessage()`: a 403 for a read-only *token* and a 403
  for a read-only *role* need different fixes and were previously
  indistinguishable without matching English prose. An unrecognised code from a
  newer server yields `null` from `code()` while `errorCode` keeps the raw
  string, so an older SDK release keeps working.
- **New read models**: `Website`, `Influencer`, `Domain`, `Commission`,
  `ConversionEvent`, `ConversionStats` (with `hasConversionData`), `SearchMatch`,
  and `Workspace`. `Link` and `Campaign` are now fully typed rather than raw
  arrays with a handful of accessors.
- **New clients**: `SearchClient` (`search()->query()` / `->first()`) for name
  resolution, and `WorkspaceClient` (`workspace()->bootstrap()`) for websites,
  domains, influencers, and accessible teams in one round trip.
- **`InfluencersClient::commissions()`** (paginated) and `commissionsCsv()` for
  the reporting-only commission ledger.
- **`ConversionsClient::send(string $type, Payload $payload, string $businessId)`**
  for the queued path, so a job can report a payload verbatim instead of
  rebuilding one and losing its journey context.
- **New payload DTOs**: `Consent`, `Utm`, `EnrichedPayload`, `CreateWebsite`,
  `UpdateWebsite`, `CreateInfluencer`, `UpdateInfluencer`, and the
  `SocialPlatform` enum.
- **Typed named arguments on every conversion payload** — `clickId`,
  `visitorId`, `firstClickId`, `lastClickId`, `websiteId`, `consent`,
  `metadata`, `occurredAt`, `test`, `paymentProcessor`, `promoCode`,
  `customerEmail`, `customerName`, `metaEventId`. The untyped `context` array
  remains as a last-resort escape hatch. Precedence is journey context <
  `context` < typed properties.
- **`Page<T>`** is `Countable` and `IteratorAggregate`, and gained
  `currentPage()`, `lastPage()`, `total()`, `hasMorePages()`, `next()`, and a
  lazy `each()` generator that walks every remaining page.
- **`ApiObject::replayed()` / `requestId()`** — objects returned by a write
  carry their `ApiResponse`, so a fresh create can be told from an idempotent
  replay.
- **`Jobs\Concerns\ReportsApiFailures`** — the shared retry policy for API jobs:
  `$tries = 5`, `$backoff = [10, 30, 120, 300]`, and a `callApi()` wrapper that
  fails the job immediately on permanent errors (401/403/404/409/422) and
  releases it for `retryAfter` seconds on a rate limit instead of blocking a
  worker. Used by `SendConversionJob` and all four journey jobs.
- **`<x-lnkflow-script />` Blade component** (`View\Components\Script`) emitting
  the hosted browser snippet, with `url`, `site-key`, `storage`, `attribution`,
  `stripe`, `capture-endpoint`, `cookie-days`, `consent`, and `nonce` props.
  Views are namespaced `lnkflow::` and publishable under the `lnkflow-views`
  tag.
- **Client-side throttle** (`connections.*.throttle`, on by default) that stays
  inside the per-token request budget and deliberately fails open.
- **Safe request diagnostics** behind `logging.enabled` / `logging.channel`:
  endpoint, status, attempt, duration, team, and request id — never the token,
  the payload, or customer/visitor identifiers.
- **`connections.*.retry_max_wait_milliseconds`** (default 2000) capping every
  in-process wait.
- **Documentation**: an end-to-end [tutorial](docs/tutorial.md) backed by a
  runnable `workbench/` app, a complete [API index](docs/api-index.md), a
  [token-scope matrix](docs/token-scopes.md), an [exception
  reference](docs/errors.md), and [browser bridge](docs/browser-script.md)
  guidance, plus cross-links to the upstream v1 contract.

### Changed

- `connections.*.throttle.budgets.default` is **60** instead of `null`. It was
  null honestly: the server defined a general `throttle:api` limiter and wired
  it to no route, so reads really were uncapped. The server now applies it to
  every authenticated endpoint, so the client-side budget mirrors a real limit
  again. Conversion and journey writes keep their own 600/min budget and are
  not affected.
- Static analysis runs at **PHPStan level 10** (was level 7, despite being
  described as level 10). Raising it surfaced the unbounded-timeout defect
  listed under Fixed; the other 81 findings were type-narrowing noise.
- **breaking** — `Transport::send()` returns `Http\ApiResponse` instead of an
  array. `ApiTransport::__construct()` gained a trailing
  `?CacheFactory $cache = null`.
- **breaking** — `Campaign::$slug` is now the **campaign's** slug (API field
  `campaign_slug`); the primary link's slug is `Campaign::$primaryLinkSlug` (API
  field `slug`). The previous mapping silently returned a link slug where a
  campaign slug was expected.
- **breaking** — `Touchpoint::$consent` is a `Consent` object rather than an
  array, and it is a required constructor argument.
- **breaking** — `WebsitesClient` and `InfluencersClient` create/update take
  DTOs (`CreateWebsite`, `UpdateWebsite`, `CreateInfluencer`,
  `UpdateInfluencer`) instead of arrays.
- **breaking** — `Refund` no longer accepts `currency`; the API does not take
  one and the original sale's currency applies. The signature is now
  `new Refund(string $invoiceId, ?string $refundId = null, ?int $amount = null, ...)`.
  A null `$amount` means a full refund; a null `$refundId` is the idempotent
  full-refund case, and `Refund::businessId()` derives the same stable key the
  server would.
- **breaking** — `CreateLink::$active` and `$conversionTrackingEnabled` (and the
  same fields on `LinkDefinition`) default to `null` rather than a concrete
  boolean, so they are omitted from the payload unless the caller sets them.
- **breaking** — `StatsClient::conversions()` returns `ConversionStats` instead
  of a generic resource; `ConversionsClient` methods return `ConversionEvent`.
- `AbstractClient::paginate()` builds pages with a resolver closure, which is
  what makes `Page::next()` and `Page::each()` possible.
- `ApiTransport` retries `409` only when the body's `code` is
  `IDEMPOTENCY_IN_PROGRESS`, and stops retrying when `Retry-After` exceeds
  `retry_max_wait_milliseconds` so the typed `RateLimitException` surfaces
  instead of a worker sleeping through it.
- `ContentSynchronizer` now PATCHes a campaign whose payload hash has drifted,
  sending only `name` and `website_id`.
- `ConversionMapperRegistry::map()` returns `false` unless
  `features.conversions` is true, making that flag the kill switch for every
  automatic reporting path.
- `Testing\FakeTransport` returns `ApiResponse`, and `respond()` accepts an
  `ApiResponse` so tests can drive status and headers such as
  `Idempotent-Replayed`.
- `testbench.yaml` registers `LnkFlowServiceProvider` explicitly, so the
  `workbench/` app boots with the SDK's config, commands, and Blade component
  available. Composer discovery cannot find a package that is not installed into
  `vendor/`.

### Removed

- **breaking** — the dead `features.links` config key. Direct client calls have
  never needed a feature flag.
- **breaking** — the `journeys.middleware` config key. It implied the package
  registered `CaptureJourneyContext` for you; it never did. Add the middleware
  to the host's web group by hand.

### Fixed

- **Unbounded request timeouts from mistyped configuration.** Connection
  settings were read with a bare `(int)` cast, so a published config carrying
  `'timeout' => '30s'` — or any other non-numeric value — coerced to `0`, and
  Guzzle reads a timeout of `0` as *no limit*. One hung LnkFlow request could
  therefore pin a PHP-FPM worker indefinitely. Non-numeric values now fall back
  to the documented default. `attempts` and `retry_base_milliseconds` had the
  same defect.
- **`lnkflow:doctor` failing on a correct least-privilege setup.** The token
  check accepted any of the three tokens, but the connectivity check calls
  `GET /me`, which resolved only `api_token`. A host following the package's own
  advice — a link token and a conversion token, no general token — passed every
  check and then failed connectivity. Read-only calls (`me`, `search`, `stats`,
  the workspace bundle) now fall back across all configured tokens, since every
  ability can read. Write purposes still refuse to fall back to a token that
  lacks the ability.
- **Content jobs burning five attempts on permanent failures.**
  `SyncLinkableContentJob` and `DisableLinkableContentJob` declared their own
  retry policy and never used the shared failure handling, so a 422 or a 403
  retried for roughly eight minutes before failing. Both now share
  `ReportsApiFailures` with the journey and conversion jobs.
- **Meta CAPI deduplication broken by the Cashier adapter.** The listener wrote
  the Stripe webhook event id into `provider_event_ids.meta`. That field is the
  Meta CAPI deduplication id and has to match the id the browser Pixel sent; a
  Stripe `evt_…` never does, so the field was causing double-counting rather
  than preventing it. It is no longer set.
- **Campaign slug misread.** `Campaign::$slug` returned the primary link's slug,
  so any code branching on a campaign slug was reading the wrong value.
- **Campaign slug rename breaking live short URLs.** `UpdateCampaign` now throws
  on a `slug` key with an explanation. `PATCH /campaigns/{id}` forwards a slug
  into the primary link, which rewrites the live short URL and 404s every link
  already shared. Rename the link explicitly through `links()->update()` if that
  is genuinely intended.
- **CMS campaign drift was never reconciled.** A renamed campaign or a moved
  website was recomputed on every sync and silently discarded, so the remote
  campaign could never catch up. `is_active` is deliberately excluded from that
  PATCH: the API forwards it to the primary link, so including it would un-pause
  a campaign or link paused by hand in the dashboard.
- **CMS sync un-paused paused links and reset conversion tracking.** Because
  `is_active` and `conversion_tracking_enabled` were always sent, a link paused
  in the dashboard came back on at the next content save. They are now omitted
  unless the adapter sets them.
- **Unsupported UTM keys failed late.** `CreateLink` and `LinkDefinition`
  validate against `Utm::KEYS` at construction, so a typo like `utm_id` throws
  where it was written instead of 422-ing inside a queued job days later.
- **Permanent API failures burned five queue attempts.** Journey and conversion
  jobs now fail immediately on 401/403/404/409/422 and release on 429 for the
  server's `Retry-After`.
- **The Cashier adapter broke Meta deduplication.** It set
  `provider_event_ids.meta` from the Stripe event id. That field is the Meta CAPI
  deduplication id and must match the id the browser Pixel sent; a Stripe
  `evt_...` never matches, so supplying one caused Meta to count every purchase
  twice instead of deduplicating. It is no longer set.

### Security

- No API token is written to `.env`, a mapping row, a queued job property, an
  exception, or a log line. Jobs resolve a client in `handle()`.
- Only `X-LnkFlow-Request-Id`, `Idempotent-Replayed`, and `Retry-After` are
  retained from a response; every other header is discarded so incidental server
  detail cannot reach logs, snapshots, or fixtures.
- Consent defaults to `unknown`, which stores nothing and sends nothing until a
  host binds its own `ConsentResolver`.

[Unreleased]: https://github.com/AppitStudio/lnkflow-laravel/compare/v0.1.0-beta.1...HEAD
[0.1.0-beta.1]: https://github.com/AppitStudio/lnkflow-laravel/releases/tag/v0.1.0-beta.1
