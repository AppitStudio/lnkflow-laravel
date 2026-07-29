# Errors and retries

Every failure the SDK raises is a `LnkFlow\Laravel\Exceptions\LnkFlowException`
or a subclass of it. A non-2xx response never reaches a read model:
`ResponseMapper` throws before an `ApiResponse` is constructed.

## Exception reference

All classes are in `LnkFlow\Laravel\Exceptions`. `LnkFlowException` extends
`RuntimeException`; every other class is `final` and extends `LnkFlowException`.

| Class | Raised from | `status` |
|---|---|---|
| `AuthenticationException` | HTTP 401 | `401` |
| `AuthorizationException` | HTTP 403 | `403` |
| `NotFoundException` | HTTP 404 | `404` |
| `ConflictException` | HTTP 409 | `409` |
| `ValidationException` | HTTP 422 | `422` |
| `RateLimitException` | HTTP 429 | `429` |
| `ServerException` | HTTP >= 500 | the status |
| `LnkFlowException` | any other non-2xx (e.g. 400, 402, 405) | the status |
| `ConnectionException` | transport failure, exhausted attempts, missing token, unconfigured connection | `null` |

### Properties

All are public readonly.

| Property | Type | Present on | Meaning |
|---|---|---|---|
| `->status` | `?int` | all | the HTTP status, or `null` for `ConnectionException` |
| `->requestId` | `?string` | all | the server's `X-LnkFlow-Request-Id`. On a `ConnectionException` from a failed or exhausted request it is the client-generated id for that attempt chain; on the configuration failures (no token, unknown connection) it is `null`, because nothing was sent. Quote it in support requests |
| `->errorCode` | `?string` | all | the response body's `code`, e.g. `IDEMPOTENCY_IN_PROGRESS`, `IDEMPOTENCY_KEY_REUSED`. `null` when the server sent none |
| `->errors` | `array<string, list<string>>` | all | the validation bag, normalized to field ⇒ list of messages. Empty when the server sent none |
| `->retryAfter` | `?int` | `RateLimitException` **only** | seconds, parsed from `Retry-After` (numeric or HTTP-date) |
| `getMessage()` | `string` | all | the server's `message`, or `'The LnkFlow API request failed.'` |
| `getCode()` | `int` | all | mirrors `status`, or `0` |

```php
use LnkFlow\Laravel\Exceptions\RateLimitException;
use LnkFlow\Laravel\Exceptions\ValidationException;

try {
    $link = $client->links()->create($campaignId, $payload, $key);
} catch (ValidationException $e) {
    report_safely($e->errors, $e->requestId);   // ['slug' => ['The slug has already been taken.']]
} catch (RateLimitException $e) {
    $this->release($e->retryAfter ?? 60);
}
```

The message and the field bag come from the server. Do not log the raw request
body, the bearer token, or customer identifiers alongside them.

## What each status usually means

| Status | Usual cause | What to do |
|---|---|---|
| 401 | token invalid, revoked, or expired | rotate it; never log the value |
| 403 | token lacks the ability, or no access to the selected team | check [Token scopes](token-scopes.md); remember `links()->preview()` needs `write` |
| 404 | the resource does not exist, or belongs to another team | do not "fix" this by widening tenant scope — it is a security boundary |
| 409 | `IDEMPOTENCY_IN_PROGRESS` (a concurrent duplicate is still running) or a genuine conflict | the SDK retries only the former, automatically |
| 422 | validation failure, or `IDEMPOTENCY_KEY_REUSED` (same key, different payload) | read `->errors`; for key reuse, fix the key, not the payload |
| 429 | rate limited | honour `->retryAfter` |
| 5xx | server failure | retry with the same idempotency key |
| — | `ConnectionException` | timeout, DNS, TLS, no token configured, or unknown connection name |

## Retry policy

`ApiTransport` retries in-process, bounded by
`connections.*.attempts` (default 3).

**Retried:** connection failures, 408, 429, 5xx, and 409 **only** when the body's
`code` is `IDEMPOTENCY_IN_PROGRESS` — the one 4xx worth retrying, because the
first request is still running and will produce the authoritative response.

**Never retried:** 400, 401, 403, 404, 422, and any other 409.

**POST is only retried** when the call sent an `Idempotency-Key`
(`campaigns()->create()`, `links()->create()`) or supplied a stable business key
(the conversion and journey writes). Any other POST is attempted exactly once.
The stable business key is not sent as a header — it exists so the transport can
prove the endpoint is idempotent on its own terms.

### Waiting

- `Retry-After` is honoured when present, numeric or HTTP-date.
- Otherwise the delay is exponential backoff with jitter from
  `retry_base_milliseconds` (default 150 ms).
- Every wait is capped at `retry_max_wait_milliseconds` (default 2000 ms).
- If `Retry-After` asks for **longer than the cap**, the SDK stops retrying and
  lets `RateLimitException` surface. That is deliberate: a queued caller should
  release the job for the real delay rather than pin a PHP-FPM worker on
  `sleep`.

### In queued jobs

`Jobs\Concerns\ReportsApiFailures` is the shared job policy:

```php
public int $tries = 5;
public array $backoff = [10, 30, 120, 300];
```

`callApi(Closure $operation)` applies two rules:

- **Fail immediately** on `AuthenticationException`, `AuthorizationException`,
  `ConflictException`, `NotFoundException`, and `ValidationException`. A bad
  payload, a token without the right ability, or a resource that is not there
  will not become true by burning five attempts over eight minutes.
- **Release** on `RateLimitException` for `max(1, $exception->retryAfter ?? 60)`
  seconds, instead of blocking a worker.

Anything else — `ServerException`, `ConnectionException` — propagates and gets
the normal `$tries` / `$backoff` treatment.

`SendConversionJob::failed()` fires `Events\ConversionFailed` with the type, the
business id, and the exception class. `ContentSynchronizer` records
`last_error_code`, `last_request_id`, and a truncated `last_error_message` on
the mapping row, then re-throws.

## Safe diagnostics

Set `LNKFLOW_LOGGING=true` (`lnkflow.logging.enabled`, optional
`LNKFLOW_LOG_CHANNEL`) to log one `lnkflow.api` info line per request:
connection, purpose, method, path, status, attempt, duration, request id, team,
and `failure` when the connection failed.

It never logs the token, the request body, the response body, customer details,
or visitor/click identifiers. Keep it that way if you wrap it.
