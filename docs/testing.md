# Testing

`LnkFlow::fake()` swaps the transport for `FakeTransport`, which records every
request and answers with a plausible response. No account, no token, and no
network are involved.

```php
use LnkFlow\Laravel\Facades\LnkFlow;

LnkFlow::fake();

$this->post('/publish');

LnkFlow::assertLinkCreated(
    fn (array $request): bool => $request['json']['slug'] === 'release',
);
LnkFlow::assertSaleTracked();
```

`fake()` rebinds `Services\Client` in the container and returns the
`FakeTransport` so you can stub responses. Call it **before** the code under
test resolves a client. It does not rebind `Contracts\Transport`, so anything
resolving the transport directly still gets the real one.

Under the fake, `LnkFlow::trackEvent/trackLead/trackSale/trackRefund` call the
client **synchronously** instead of queueing, so conversion assertions work
without a queue fake.

## Assertions

| Assertion | Matches |
|---|---|
| `assertLinkCreated(?Closure)` | `POST campaigns/*/links` |
| `assertLinkUpdated(int $id, ?Closure)` | `PATCH links/{id}` |
| `assertTouchpointCaptured(?Closure)` | `POST journeys/touchpoints` |
| `assertVisitorIdentified(?Closure)` | `POST journeys/identify` |
| `assertVisitorUnidentified(?Closure)` | `POST journeys/unidentify` |
| `assertEventTracked(?Closure)` | `POST track/lead` |
| `assertSaleTracked(?Closure)` | `POST track/sale` |
| `assertRefundTracked(?Closure)` | `POST track/refund` |
| `assertNothingSent()` | no request at all |

Paths are matched exactly or by `fnmatch`, so `campaigns/*/links` covers any
campaign id. The optional closure receives the recorded request and must return
**strictly `true`** to match:

```php
[
    'method' => 'POST',
    'path' => 'campaigns/1/links',
    'query' => [],
    'json' => ['destination_url' => '...', 'slug' => 'release'],
    'headers' => ['Idempotency-Key' => 'cms:link:42:primary'],
    'stable_key' => null,
    'connection' => null,
    'team' => 42,
    'purpose' => 'links',
]
```

`assertNothingSent()` is the one that proves a consent-denied or feature-disabled
path really stayed offline. Use it — an absent request is exactly what a
`denied` consent decision is supposed to produce.

`LnkFlow::client()->...` calls made against the fake are recorded too, so
assertions cover both the facade and direct client use.

## Stubbing responses

`respond()` overrides a single endpoint. Pass an array for the body, a closure
for a per-request decision, or an `ApiResponse` when you need to control status
and headers:

```php
use LnkFlow\Laravel\Http\ApiResponse;

$fake = LnkFlow::fake();

$fake->respond('GET', 'links/7', ['data' => ['id' => 7, 'slug' => 'news']]);

$fake->respond('POST', 'campaigns', fn (array $request): array => [
    'data' => ['id' => 99, 'name' => $request['json']['name']],
]);

// Drive an idempotent replay.
$fake->respond('POST', 'campaigns', new ApiResponse(
    status: 200,
    body: ['data' => ['id' => 99]],
    headers: ['idempotent-replayed' => 'true'],
));
```

Header keys on `ApiResponse` must be lowercase — that is how `header()`,
`requestId()`, and `replayed()` look them up.

Without a stub, the fake returns a plausible default: empty `data`/`meta`/`links`
for the known list endpoints, a zeroed `stats/conversions` payload with
`has_conversion_data: false`, a link-shaped or campaign-shaped body echoing the
request JSON for the link and campaign paths, and `['data' => ['id' => 1, ...$json]]`
for anything else. Status is 201 for POST and 200 otherwise.

`$fake->requests()` returns every recorded request if you need to assert
something the helpers do not cover.

## Transport-level tests

To test the real `ApiTransport` — retries, `Retry-After`, error mapping — use
Laravel's HTTP fake instead:

```php
Http::preventStrayRequests();

Http::fake([
    '*/campaigns/*/links' => Http::sequence()
        ->push(['message' => 'Too Many Requests'], 429, ['Retry-After' => '1'])
        ->push(['data' => ['id' => 5]], 201),
]);
```

Cover the idempotent and the non-idempotent POST paths separately: a POST
without an `Idempotency-Key` and without a stable business key is attempted
exactly once, by design.

Never put a real token or real customer data in a fixture.
