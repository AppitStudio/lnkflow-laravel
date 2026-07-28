# Conversions

Manual reporting supports named events, leads, sales, and refunds:

```php
LnkFlow::trackEvent(new NamedEvent('signup', 'customer_opaque_42'));
LnkFlow::trackLead(new Lead('customer_opaque_42', 'qualified_lead'));
LnkFlow::trackSale(new Sale('invoice_42', 4999, 'EUR', 'customer_opaque_42'));
LnkFlow::trackRefund(new Refund('invoice_42', 'refund_7', 1200, 'EUR'));
```

Sales/refunds require integer minor units and stable invoice/refund IDs.
Named events use lead semantics with a stable `event_name`. The dispatcher
enriches payloads with explicit journey context, queues after commit, and fails
open for the application request. Explicit payload context overrides session
context.

Applications can bind `ConversionMapper` implementations through
`ConversionMapperRegistry` for domain events. Listen to `ConversionQueued`,
`ConversionSent`, and `ConversionFailed` for safe operational telemetry. Do
not log raw bodies, tokens, email addresses, or names.
