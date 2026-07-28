# Testing

`LnkFlow::fake()` replaces the shared transport, records typed client calls,
and performs no HTTP:

```php
LnkFlow::fake();

$this->post('/publish');

LnkFlow::assertLinkCreated(
    fn (array $request) => $request['json']['slug'] === 'release',
);
LnkFlow::assertSaleTracked();
```

Available focused assertions cover link create/update, touchpoint capture,
identify/unidentify, event/lead, sale, and refund. `assertNothingSent()` proves
that consent-denied or disabled paths stayed offline.

For direct transport tests, use Laravel `Http::fake()` and
`Http::preventStrayRequests()`. Test idempotent retry and non-idempotent POST
behavior separately. Never place real tokens or customer data in fixtures.
