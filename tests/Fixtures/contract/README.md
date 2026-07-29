# LnkFlow contract fixtures

Canonical recordings of the real v1 API. Every LnkFlow integration — the Laravel
SDK, the npm MCP installer/stdio adapter, the hosted MCP server, and the Chrome
extension — has to map the same HTTP statuses onto the same typed errors. Before
this corpus existed each of them invented its own fake responses, and their error
taxonomies drifted apart with nothing to catch it.

Assert against these bytes instead of hand-writing a response body.

> **Generated. Never hand-edit.** Every file here is produced by
> `php artisan contract:fixtures`. Editing one by hand makes CI fail on the next
> run and, worse, makes an SDK pass against a contract the server does not have.

## Regenerating

```bash
php artisan contract:fixtures          # rewrite the corpus
php artisan contract:fixtures --check  # fail if the committed corpus is stale
```

The command seeds a throwaway in-memory SQLite database, drives the real HTTP
kernel in-process (real middleware, real form requests, real exception renderer),
and writes the responses here. It never reads your development database, never
opens a socket, and refuses to run in production.

`--check` regenerates into memory and byte-compares. It is wired into
`tests/Feature/Api/ContractFixtureTest.php` and runs in CI from
`.github/workflows/api-contract.yaml`. If it fails, run the command and commit
the result — do not patch the files.

### Why it is byte-stable

The generator pins everything that would otherwise drift: the clock is frozen at
`2026-01-15T12:00:00+00:00`, Faker is seeded, `Str::uuid()`/`Str::ulid()`/
`Str::random()` are replaced with counters, the rate limiter gets its own cache
(so a previous run cannot leak throttle counters in), and
`X-LnkFlow-Request-Id` is normalised to the fixed placeholder
`00000000-0000-4000-8000-000000000000`.

## File shape

```
docs/contract-fixtures/
  index.json                     # machine-readable listing of every fixture
  <endpoint-slug>/
    <status>.json                # e.g. 200.json, 404.json, 429.json
    <status>-<variant>.json      # e.g. 201-idempotent-replay.json
```

A variant suffix exists only where one status is reachable in materially
different ways — an idempotent replay and a first create are both `201`, but an
SDK must distinguish them.

Each file:

```json
{
  "endpoint": "campaigns-store",
  "case": "The per-user link-creation budget is exhausted. Retry-After is authoritative.",
  "request": {
    "method": "POST",
    "path": "/api/v1/campaigns",
    "query": null,
    "authenticated": true
  },
  "status": 429,
  "headers": {
    "content-type": "application/json",
    "cache-control": "no-cache, private",
    "x-lnkflow-request-id": "00000000-0000-4000-8000-000000000000",
    "retry-after": "60",
    "x-ratelimit-limit": "20",
    "x-ratelimit-remaining": "0"
  },
  "body": { "message": "Too Many Attempts.", "code": "RATE_LIMITED" }
}
```

`status`, `headers` and `body` are the response. `endpoint`, `case` and `request`
are metadata describing how the response was produced.

### Headers

Only headers that change client behaviour are recorded:

| Header | Why it is here |
|---|---|
| `content-type` | always `application/json` on v1 |
| `cache-control` | `browser-extension-bootstrap` is the only cacheable response |
| `x-lnkflow-request-id` | correlation id — always present, always normalised here |
| `retry-after` | present on `429`; authoritative over any client backoff |
| `x-ratelimit-limit`, `x-ratelimit-remaining` | present on every throttled route, which is now all of them |
| `idempotent-replayed` | `true` when a create was replayed from a stored response |

Everything else (`Date`, `Content-Length`, `Set-Cookie`, …) is dropped as
transport noise.

Two caveats:

- **`x-ratelimit-remaining` is not a contract.** Its value reflects how many
  earlier fixtures happened to use the same throttled route during generation.
  Assert on its presence, never on its number.
- **`retry-after` is.** Honour it, in seconds, over any local backoff.

## Data safety

Fixtures are committed to a public-facing repository and read by four downstream
projects. The generator refuses to write a fixture that contains a bearer token,
a Sanctum plaintext token, an IP address, or an email address outside the
reserved `.invalid` / `.test` / `.example` / `.localhost` namespaces
(RFC 2606 / RFC 6761). `ContractFixtureTest` re-asserts the same rules against the
committed bytes, so the guarantee survives a hand-edit.

Consequences worth knowing:

- The only `@` in the corpus are `owner@contract-fixtures.invalid` (in
  `browser-extension-bootstrap/200.json`, which really does return the actor's
  email) and `@handle` display strings.
- The seeded influencer has `contact_email: null` on purpose.
- Visitor ids, click ids and customer ids are fabricated counters, not real
  identifiers.

## Coverage

99 fixtures across 26 endpoints. `index.json` is the machine-readable version.

| Endpoint slug | Route | Recorded statuses |
|---|---|---|
| `me` | `GET /api/v1/me` | 200, 401, 403 |
| `search` | `GET /api/v1/search` | 200, 401, 422 |
| `browser-extension-bootstrap` | `GET /api/v1/browser-extension/bootstrap` | 200, 401 |
| `campaigns-index` | `GET /api/v1/campaigns` | 200, 401 |
| `campaigns-show` | `GET /api/v1/campaigns/{id}` | 200, 401, 404 |
| `campaigns-store` | `POST /api/v1/campaigns` | 201, 401, 403, 409, 422, 429 (+ 5 variants, incl. the idempotent replay) |
| `campaign-links-index` | `GET /api/v1/campaigns/{id}/links` | 200, 401, 404 |
| `campaign-links-store` | `POST /api/v1/campaigns/{id}/links` | 201, 401, 403, 404, 422 |
| `links-show` | `GET /api/v1/links/{id}` | 200, 401, 404 |
| `links-update` | `PATCH /api/v1/links/{id}` | 200 (+1 variant), 401, 403, 404, 422 |
| `links-preview` | `POST /api/v1/links/preview` | 200, 401, 403, 422 |
| `websites-index` | `GET /api/v1/websites` | 200, 401 |
| `websites-show` | `GET /api/v1/websites/{id}` | 200, 401, 404 |
| `websites-store` | `POST /api/v1/websites` | 201, 401, 403, 422 |
| `influencers-index` | `GET /api/v1/influencers` | 200, 401 |
| `influencers-show` | `GET /api/v1/influencers/{id}` | 200, 401, 404 |
| `influencers-store` | `POST /api/v1/influencers` | 201, 401, 403, 422 |
| `influencer-commissions` | `GET /api/v1/influencers/{id}/commissions` | 200, 401, 404 |
| `domains-index` | `GET /api/v1/domains` | 200, 401 |
| `stats-summary` | `GET /api/v1/stats/summary` | 200, 401, 422 |
| `stats-conversions` | `GET /api/v1/stats/conversions` | 200, 401 |
| `track-lead` | `POST /api/v1/track/lead` | 200 (duplicate), 201, 401, 403, 422 |
| `track-sale` | `POST /api/v1/track/sale` | 200 (duplicate), 201, 401, 403, 422 |
| `track-refund` | `POST /api/v1/track/refund` | 200 (duplicate), 201, 401, 403, 422 |
| `track-events` | `GET /api/v1/track/events` | 200 (+1 variant), 401, 422 |
| `journeys-touchpoints` | `POST /api/v1/journeys/touchpoints` | 200 (duplicate), 201, 202, 401, 403, 422 |

An idempotent replay reuses the original status, so `201-idempotent-replay.json`
is a `201` distinguished only by `Idempotent-Replayed: true`.

### Statuses that are deliberately absent

Not every status is reachable on every endpoint, and inventing an unreachable one
would be worse than omitting it. What is missing, and why:

| Status | Where it is missing | Why |
|---|---|---|
| `403` | every read endpoint except `me` | On reads, `403` is reachable **only** through an `X-LnkFlow-Team` header naming a team the token cannot access — the envelope is identical everywhere, so `me/403.json` is the single representative. Read-only tokens are *allowed* to read, so they cannot produce `403` here. |
| `404` | every collection route and every route without a path parameter (`campaigns-index`, `websites-index`, `links-preview`, `stats/*`, `track/*`, `journeys/touchpoints`, `me`, `search`, `domains`, `browser-extension/bootstrap`) | There is no resource to miss. Cross-tenant access to a *collection* returns an empty page, not `404`. |
| `409` | everything except `campaigns-store` | `409 IDEMPOTENCY_IN_PROGRESS` is emitted only by `EnsureIdempotentApiRequest`, which is applied to exactly two routes: `POST /campaigns` and `POST /campaigns/{id}/links`. Both behave identically, so only the campaign route is recorded. |
| `422` | `campaigns-index`, `campaigns-show`, `links-show`, `websites-index/show`, `influencers-index/show`, `influencer-commissions`, `domains-index`, `browser-extension/bootstrap`, `stats/conversions` | These take no validated input beyond pagination, which coerces rather than rejects. |
| `429` | everything except `campaigns-store` | Every v1 route is throttled now, but only one budget is small enough to reach from a generator. `throttle:link-creation` is 20/min per user on the two create routes; the general `throttle:api` budget is 60/min per token on everything else; `throttle:conversion-tracking` and `throttle:journey-capture` are 600/min per team. So `campaigns-store/429.json` is the single representative, and the reads instead record the `x-ratelimit-limit` / `x-ratelimit-remaining` headers they now carry. |
| `5xx` | everywhere | Not synthesisable from a healthy application. SDKs must still treat `5xx` as retryable per `PRPs/integrations/base-context.md`. |
| `204` | everywhere | `DELETE /campaigns/{id}` and `DELETE /links/{id}` return it, but integrations deactivate (`is_active=false`) rather than delete — see `base-context.md`. Not part of the corpus. |

## How each integration should consume this

The corpus is a set of files, not a package. Point your fake transport at it.

**Laravel SDK** (`integrations/lnkflow-laravel`) — load a fixture into
`FakeTransport` and assert `ResponseMapper` produces the right typed error:

```php
$fixture = json_decode(file_get_contents($corpus.'/campaigns-store/429.json'), true);

$transport->push($fixture['status'], $fixture['body'], $fixture['headers']);

expect(fn () => $client->campaigns()->create($payload))
    ->toThrow(RateLimitedException::class)
    ->and($exception->retryAfter)->toBe(60)
    ->and($exception->requestId)->toBe($fixture['headers']['x-lnkflow-request-id']);
```

**npm MCP installer / stdio adapter** (`integrations/lnkflow-mcp-server`) — vendor
or submodule the directory and feed it to a `fetch` mock; iterate `index.json` so
a newly recorded status fails the suite until it is mapped.

**Hosted MCP server** (`app/Mcp/Servers/LnkFlowServer.php`) — it dispatches
in-process through `McpApiGateway`, so it does not need a transport fake, but its
tool-level error mapping should be asserted against the same bodies.

**Chrome extension** (`integrations/lnkflow-browser-extension`) — `popup/api.js`
has its own status mapping; drive it from these files in the extension's Jest/Vitest
suite rather than from hand-written JSON.

For all four, the rule from `docs/integrations/conformance-checklist.md` §3 holds:
one fixture per error class, asserting the exception **type**, `status`, `errors`,
`requestId`, and `retryAfter` — and never string-matching `message`.

## Contract notes surfaced by the corpus

Reading the recorded bodies side by side is what surfaced two inconsistencies
that have since been fixed. Both are worth knowing, because the *shape* of the
fix is the contract:

1. **Every error carries a machine-readable `code`.** It used to be only the
   idempotency middleware; `401`, `403`, `404`, ordinary `422` and `429` carried
   prose and nothing else, so a client had to string-match English to tell a
   read-only *token* from a read-only *role*. `App\Exceptions\Api\ApiErrorCode`
   is now the single source of those symbols and
   `App\Exceptions\Api\ApiExceptionRenderer` puts one on every `/api/*` failure.
   Assert on `code`; never assert on `message`, which is prose and gets reworded.
2. **`404` no longer names the model class.** Cross-tenant reads used to return
   `"No query results for model [App\\Models\\Campaign] 2"` — the right *status*
   (a tenancy boundary must be indistinguishable from a missing row) with a body
   that gave the boundary away. It is now `"Resource not found."` with
   `code=NOT_FOUND`, byte-identical to a genuinely missing id. The corpus has
   both cases; they should stay identical.

## Related documents

- `PRPs/integrations/base-context.md` — the shared invariants, including the
  error taxonomy and the retry/idempotency contract these fixtures illustrate.
- `docs/openapi/lnkflow-v1.yaml` — the machine-readable contract.
- `docs/api-reference.md` — the human contract.
- `docs/integrations/conformance-checklist.md` — the reviewer checklist that
  requires fixtures from here.
- `.github/workflows/api-contract.yaml` — the CI guard for both this corpus and
  the documentation surfaces.
