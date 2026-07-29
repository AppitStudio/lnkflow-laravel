# Client usage

Resolve `LnkFlow\Laravel\Services\Client` from the container or use the facade.
Every endpoint method returns a typed object that also retains the complete
decoded payload in `->raw`, so an additive API field does not require an SDK
upgrade.

```php
use LnkFlow\Laravel\Facades\LnkFlow;
use LnkFlow\Laravel\Services\Client;

$me = LnkFlow::client()->identity()->me();
$links = LnkFlow::connection('agency')->forTeam(42)->links()->list();

// or inject it
public function __construct(private readonly Client $client) {}
```

The full catalogue of clients, methods, and return types is in the
[API index](api-index.md).

## Connections and teams

Named connections live in `config/lnkflow.php` under `connections`. Each has its
own URL, tokens, team, website, timeouts, retry budget, and throttle.

`connection()` and `forTeam()` return a **new** client; the original is
untouched. Team selection is explicit and immutable per clone, and sends
`X-LnkFlow-Team`. The SDK never silently picks another team.

`LNKFLOW_TEAM` is the **numeric** LnkFlow team id. It is not a user id, not a
slug, and not a public id. Find it with `GET /me`:

```php
$me = LnkFlow::client()->identity()->me();
$me->id;            // the USER id
$me->raw['teams'];  // [['id' => 42, 'name' => 'Acme', 'role' => 'owner'], ...]
```

`forTeam(null)` falls back to the connection's configured team; it does not
clear the header.

## Token routing

Each client is bound to a transport "purpose", which selects the configured
token: `links` prefers `link_token`, `journeys`/`conversions` prefer
`conversion_token`, and `identity()`, `search()`, `workspace()`, and `stats()`
use `api_token` with **no** fallback. See [Token scopes](token-scopes.md) — this
is the most common cause of a confusing 403 or a "No LnkFlow API token is
configured" `ConnectionException`.

## Requests

Every request sends:

```http
Accept: application/json
Authorization: Bearer <token>
User-Agent: lnkflow-laravel/<version> PHP/<php-version>
X-LnkFlow-SDK-Version: <version>
X-LnkFlow-Request-Id: <uuid>
X-LnkFlow-Team: <team>          # when a team is configured or selected
Idempotency-Key: <key>          # campaign and link creates only
```

One request id per attempt chain, not per attempt, so a retried call stays one
story in the server log.

## Responses

`Transport::send()` returns `LnkFlow\Laravel\Http\ApiResponse`. The clients
unwrap it for you; this is what a custom call receives.

| Member | Returns | Notes |
|---|---|---|
| `->status` | `int` | always 2xx — failures throw |
| `->body` | `array` | the decoded JSON envelope |
| `->contents` | `string` | the raw body, for CSV endpoints |
| `->headers` | `array<string, string>` | lowercased; only `x-lnkflow-request-id`, `idempotent-replayed`, and `retry-after` are retained |
| `data()` | `array` | the `data` envelope of a single resource |
| `collection()` | `list<array>` | the `data` envelope of a collection, non-array rows dropped |
| `meta()` / `links()` | `array` | pagination envelopes, verbatim |
| `header(string $name)` | `?string` | case-insensitive |
| `requestId()` | `?string` | |
| `replayed()` | `bool` | true when the server replayed a stored idempotent response |

Every other response header is deliberately discarded, so no incidental server
detail leaks into logs, snapshots, or fixtures.

Objects returned by a **write** carry the response, which is how they answer
`->replayed()` and `->requestId()`:

```php
$campaign = $client->campaigns()->create($payload, 'cms:campaign:launch');

if ($campaign->replayed()) {
    // this key already created this campaign; nothing new happened
}
```

On an object returned by a read, `replayed()` is `false` and `requestId()` is
`null`.

## Pagination

Every list returns `Page<T>`: `Countable`, `IteratorAggregate`, and able to
fetch the next page.

```php
$page = $client->links()->list(['per_page' => 100, 'active' => true]);

count($page);          // items on THIS page
$page->currentPage();
$page->lastPage();
$page->total();
$page->hasMorePages();

foreach ($page as $link) { /* this page only */ }

foreach ($page->each() as $link) {
    // every remaining item, one HTTP request per page, fetched lazily
}

$next = $page->next();  // ?Page<T>, null at the end
```

`->meta` and `->links` are preserved verbatim, including keys this SDK version
does not understand. `each()` costs one request per page — filter server-side
before walking a large account.

`domains()->list()` is the exception: it returns a plain `list<Domain>`, because
that endpoint is not paginated.

## Errors and retries

Exceptions expose `status`, `requestId`, `errorCode`, `errors`, and — on
`RateLimitException` — `retryAfter`. Retries are bounded and cover connection
failures, 408, 429, 5xx, and 409 only when the body's code is
`IDEMPOTENCY_IN_PROGRESS`. POST is retried only with an `Idempotency-Key` or an
endpoint stable business key. A `Retry-After` longer than
`retry_max_wait_milliseconds` stops the retry loop and surfaces
`RateLimitException`, so a queued caller can release the job for the real delay
instead of blocking a worker.

The full reference is in [Errors](errors.md).

## Client-side throttling

`connections.*.throttle` (on by default, `LNKFLOW_THROTTLE`) keeps a
cache-backed count of requests per token per minute and waits rather than firing
into a server 429. It fails open: when the budget has been exhausted for longer
than `max_wait_milliseconds`, the request still goes out and the server's 429
plus `Retry-After` becomes the authority. It needs a shared cache store to be
useful across workers; with no cache binding available it silently does nothing.

## Diagnostics

`LNKFLOW_LOGGING=true` logs one safe line per request — connection, purpose,
method, path, status, attempt, duration, request id, team. Never the token,
never a payload, never customer or visitor identifiers. Set
`LNKFLOW_LOG_CHANNEL` to route it somewhere specific.
