# Optional Cashier adapter

Cashier is suggested, not required. The adapter listens to Cashier's documented
`WebhookHandled` event only when both Cashier is installed and
`lnkflow.cashier.enabled` is true. It maps paid invoices and refunds to queued
LnkFlow conversions and retains the Stripe event ID in the supported metadata
field.

```php
'cashier' => [
    'enabled' => true,
    'include_test_events' => false,
],
```

Choose one reporting owner. If LnkFlow already receives Stripe events through
its direct Stripe webhook, leave this adapter disabled to avoid double
reporting. Test-mode events are ignored unless explicitly enabled.
