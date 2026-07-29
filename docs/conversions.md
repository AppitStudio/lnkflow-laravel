# Conversions

LnkFlow attributes downstream leads and sales back to the click that drove them.
This package reports those conversions. Four kinds exist: named events, leads,
sales, and refunds.

```php
use LnkFlow\Laravel\Data\Lead;
use LnkFlow\Laravel\Data\NamedEvent;
use LnkFlow\Laravel\Data\Refund;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Facades\LnkFlow;

LnkFlow::trackEvent(new NamedEvent('signup', 'customer_opaque_42'));
LnkFlow::trackLead(new Lead('customer_opaque_42', 'qualified_lead'));
LnkFlow::trackSale(new Sale('invoice_42', 4999, 'eur', 'customer_opaque_42'));

LnkFlow::trackRefund(new Refund('invoice_42'));                    // full refund
LnkFlow::trackRefund(new Refund('invoice_42', 'refund_7', 1200));  // partial
```

The facade methods **queue** the report after the host transaction commits. They
never perform a network call inline: a failed report must not take a checkout
down with it. The synchronous equivalents are on the client
(`$client->conversions()->sale(...)`) — use those from a console command or a
verification loop, not from a request a user is waiting on.

No feature flag is needed for manual reporting. `features.conversions` gates
only the automatic paths: `ConversionMapperRegistry::map()` returns false when
it is off, and the Cashier adapter needs it too.

## Money

Amounts are **integer minor currency units** — cents, not dollars. The types
enforce it; a negative sale amount throws. There is no FX conversion in v1: each
event stores its own lowercase ISO currency code and aggregates sum cents, so a
mixed-currency team gets a cents sum across currencies.

## Refunds

```php
new Refund(
    string $invoiceId,          // the ORIGINAL sale's invoice id
    ?string $refundId = null,
    ?int $amount = null,
    ?string $eventName = null,
    ?string $paymentProcessor = null,
    ?array $metadata = null,
    ?DateTimeInterface $occurredAt = null,
    ?bool $test = null,
    array $context = [],
);
```

Three things changed shape here and are easy to get wrong:

- **There is no `currency`.** The refund endpoint does not accept one; the
  original sale's currency applies. Passing one is a `TypeError`.
- **Null `$amount` means a full refund.** The server reverses the original
  sale's amount, so you never have to re-derive it. Set it only for a partial
  refund; anything below 1 throws.
- **Null `$refundId` is the idempotent full-refund case.** The server derives a
  single stable reference (`{invoice_id}:refund`), so a retry is a duplicate
  rather than a second clawback. `Refund::businessId()` returns the same value
  the server would. Partial or repeated refunds against one sale each need their
  own distinct `$refundId`, and it must differ from the invoice id — conversions
  share one reference space, and the constructor rejects a collision.

The referenced sale must already exist. The API answers 422 rather than
recording an unattributed refund; the Stripe webhook is lenient here because it
must tolerate out-of-order delivery, but an API caller referencing an unknown
sale is a bug.

## Attribution identifiers

`clickId`, `visitorId`, `firstClickId`, `lastClickId`, `websiteId`, `consent`,
and the rest are typed named arguments. You normally do not pass them: when a
journey session exists, `ConversionDispatcher` fills them in from it.

Pass them explicitly when reporting from a context with no session — a webhook, a
console command, a background import:

```php
LnkFlow::trackSale(new Sale(
    invoiceId: $invoice->id,
    amount: $invoice->total_cents,
    currency: $invoice->currency,
    customerExternalId: $customerId,
    clickId: $clickIdFromCheckoutMetadata,
    paymentProcessor: 'stripe',
    promoCode: $invoice->promo_code,
));
```

Precedence in the serialized payload is **journey context < `$context` <
typed properties**. An explicitly passed `clickId` is never replaced by whatever
happens to be in the session. `$context` remains the escape hatch for a field
this SDK version does not type yet.

Refunds deliberately carry **no** journey context. They attribute through the
original sale, so moving visitor or click identifiers around would add nothing.

`customerEmail` and `customerName` on `Lead` are opt-in. LnkFlow does not need
them to attribute a conversion, and sending them makes the payload personal
data — do not set them without a lawful basis.

`metaEventId` on `Lead` and `Sale` is the Meta CAPI deduplication id. It must be
the same id the browser Pixel sent for that purchase; a payment-provider event
id never matches, and supplying one breaks deduplication instead of providing
it. Leave it null unless you are genuinely mirroring a Pixel event.

## Idempotency and duplicates

| Kind | Stable key | Server behaviour |
|---|---|---|
| sale | `invoice_id` | replay returns the original event with `"duplicate": true` and 200 |
| refund | `refund_id`, defaulting to `{invoice_id}:refund` | same |
| lead / named event | `(customer_external_id, event_name)` | first insert 201; an existing lead returns the original with `"duplicate": true` and 200 |

These keys are what let the transport retry a POST at all — they are not sent as
an `Idempotency-Key` header, they are the endpoint's own contract.

**Lead dedupe is best-effort.** The server documents `(customer_external_id,
event_name)` deduplication as racy under concurrent retries, so duplicate leads
are possible. Do not build billing, entitlements, or a quota on the assumption
that a lead lands exactly once; read the event feed if you need certainty.

## Verifying

`GET /track/events` needs no special ability, which makes it the verification
loop for a new integration:

```php
$events = $client->conversions()->events(['test' => true, 'type' => 'sale', 'limit' => 50]);

foreach ($events as $event) {
    $event->id;
    $event->type;               // lead | sale | refund
    $event->amountCents;
    $event->attributionSource;  // link | code | manual
    $event->test;
}
```

`php artisan lnkflow:verify --test-conversion` does exactly this loop end to
end: it creates a clearly labelled test event and reads it back. It is
deliberately mutating — the test event is retained, though it is excluded from
production statistics — so it requires confirmation or `--force`. It is not a
health check; `lnkflow:doctor` is.

`$client->conversions()->journey($eventId)` returns the privacy-safe frozen
attribution timeline for one event. It never contains IP addresses, user agents,
fingerprints, or provider tokens.

## Reading the numbers back

```php
$stats = $client->stats()->conversions(['from' => '2026-07-01', 'to' => '2026-07-31']);

if (! $stats->hasConversionData) {
    // structural zeros, not measured zeros
}
```

`hasConversionData` is the canonical flag for "does this team have real
conversion data". Treat every number as absent until it is true, and never
render a structural zero as revenue. `revenueCents` is refund-adjusted net
revenue, and each conversion counts exactly once. Assisted-conversion counts are
non-financial influence metrics — never add assists across sources as extra
sales or revenue.

**Code beats click.** When a promo code and a click both attribute the same
sale, the code wins: `attributionSource` is `code` and the code's influencer is
credited.

The influencer commission ledger (`influencers()->commissions()` /
`commissionsCsv()`) is reporting only. A negative `commissionAmountCents` is a
clawback from a refund. Nothing in LnkFlow moves money; never describe a ledger
row as a payment.

## Automatic mapping

Bind `ConversionMapper` implementations to turn host domain events into
conversions:

```php
// config/lnkflow.php
'features' => ['conversions' => true],
'conversions' => [
    'mappers' => [App\LnkFlow\OrderPaidMapper::class],
    'queue' => 'integrations',
],
```

```php
use LnkFlow\Laravel\Contracts\ConversionMapper;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Services\JourneyContext;

final class OrderPaidMapper implements ConversionMapper
{
    public function supports(object $event): bool
    {
        return $event instanceof OrderPaid;
    }

    public function map(object $event, JourneyContext $context): Sale
    {
        return new Sale(
            invoiceId: $event->order->invoice_id,
            amount: $event->order->total_cents,
            currency: $event->order->currency,
            customerExternalId: (string) $event->order->customer_id,
        );
    }
}
```

`ConversionMapperRegistry::map($event)` walks the configured mappers, dispatches
the first non-null result, and returns whether anything was reported. It returns
`false` immediately when `features.conversions` is off — the flag is the kill
switch for every automatic path.

Return `null` from `map()` to decline an event you support but do not want
reported (a zero-value order, a test fixture).

## Telemetry

Listen to `ConversionQueued`, `ConversionSent`, and `ConversionFailed`. They
carry the type, the stable business id, and — for sent — the remote event id.
That is enough to alert on a stuck reporter without logging a payload. Do not
log raw bodies, tokens, email addresses, or names.

## Related

- [Browser bridge](browser-script.md) — capturing the click id in the browser.
- [Journeys and consent](journeys-and-consent.md) — capturing it server-side.
- [Cashier](cashier.md) — the optional Stripe adapter, and why you pick exactly
  one Stripe reporting owner.
