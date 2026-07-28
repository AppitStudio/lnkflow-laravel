# Client usage

Resolve `LnkFlow\Laravel\Services\Client` from the container or use the facade.
All endpoint methods return typed objects while retaining the raw response in
`$object->raw`, so additive API fields do not break older clients.

```php
$me = LnkFlow::client()->identity()->me();
$links = LnkFlow::connection('agency')->forTeam('team_123')->links()->list();
```

Named connections live in `config/lnkflow.php`. Team selection is explicit and
immutable per client clone. Reads use `read`; resource writes use the link
token (`read,write`); journeys and conversions use the conversion token
(`read,conversions`). A general API token is only a fallback.

Each request sends an SDK version and request UUID. Exceptions expose status,
safe server message, field errors, error code, and server request ID:
`AuthenticationException`, `AuthorizationException`, `NotFoundException`,
`ConflictException`, `ValidationException`, `RateLimitException`,
`ServerException`, and `ConnectionException`.

Retries are bounded to connection failures, 408, 429, and 5xx. `Retry-After`
is honored. GET/PATCH/DELETE can retry; POST can retry only when the caller
supplies `Idempotency-Key` or the endpoint has a stable business key.
