# Optional Cashier adapter

Cashier is suggested, not required. The adapter listens to Cashier's documented
`WebhookHandled` event and maps Stripe webhooks to queued LnkFlow conversions.

```php
// config/lnkflow.php
'features' => ['conversions' => true],
'cashier' => [
    'enabled' => true,
    'include_test_events' => false,
],
```

It registers only when **all three** hold: `features.conversions` is true,
`cashier.enabled` is true, and `Laravel\Cashier\Events\WebhookHandled` exists.
Two switches for one behaviour is deliberate — `features.conversions` is the
kill switch for every automatic reporting path, and turning Cashier reporting on
while LnkFlow's own Stripe webhook is also reporting would double-count.

## Choose one reporting owner

LnkFlow can receive Stripe events directly, through a per-team webhook
configured in Conversions → Setup. That path and this adapter see the same
Stripe events and would each record the sale.

Pick one:

- **LnkFlow's direct Stripe webhook** — zero code, handles
  `checkout.session.completed`, `checkout.session.async_payment_succeeded`,
  `invoice.paid`, and `charge.refunded`. Leave this adapter disabled.
- **This adapter** — useful when the host already owns Cashier's webhook
  handling and you would rather not point Stripe at a second endpoint. Then do
  not configure the LnkFlow Stripe integration for the same account and mode.

## What it maps

| Stripe event | Reported as | Fields taken |
|---|---|---|
| `invoice.paid` | `Sale` | `id` → `invoiceId`, `amount_paid` → `amount`, `currency`, `customer` → `customerExternalId`, `payment_processor: 'stripe'` |
| `charge.refunded` | one `Refund` per entry in `refunds.data` | `invoice` → `invoiceId`, refund `id` → `refundId`, refund `amount` → `amount`, `payment_processor: 'stripe'` |

Events with `livemode: false` are ignored unless `include_test_events` is true.
Anything with a payload shape the adapter cannot read confidently is skipped
rather than guessed at.

Each refund entry gets its own `refundId`, so a partial refund followed by
another is two distinct clawbacks rather than one replayed duplicate.

## What it deliberately does not set

`provider_event_ids.meta` is **not** populated from the Stripe event id.

That field is the Meta CAPI deduplication id: it has to match the id the browser
Pixel sent for the same purchase so Meta can collapse the two into one
conversion. A Stripe `evt_...` never matches a Pixel event id, so supplying one
breaks deduplication rather than providing it — Meta would count every purchase
twice. If you are mirroring a Pixel event, set `metaEventId` yourself on a `Sale`
you build, with the id the Pixel actually used.

## Notes

- Reporting is queued after commit through `ConversionDispatcher`, so a LnkFlow
  outage cannot fail Stripe webhook handling.
- The adapter needs a token with the `conversions` (or `write`) ability — see
  [Token scopes](token-scopes.md).
- Refunds reported here carry no journey context by design; they attribute
  through the original sale.
