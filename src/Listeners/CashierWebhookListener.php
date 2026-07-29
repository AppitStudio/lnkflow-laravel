<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Listeners;

use LnkFlow\Laravel\Data\Refund;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Services\ConversionDispatcher;

/**
 * Reports Cashier's Stripe webhooks as LnkFlow conversions.
 *
 * Enable this or LnkFlow's own per-team Stripe webhook, never both: they see
 * the same Stripe events and would each record the sale.
 *
 * Note what is deliberately not set here. `provider_event_ids.meta` is the Meta
 * CAPI event id used to deduplicate a server event against the browser Pixel
 * event, so it has to match the id the Pixel sent. A Stripe webhook event id
 * never matches, and supplying one breaks deduplication instead of providing
 * it — Meta would count every purchase twice.
 */
final readonly class CashierWebhookListener
{
    public function __construct(private ConversionDispatcher $conversions) {}

    public function __invoke(object $event): void
    {
        $payload = $event->payload ?? null;

        if (! is_array($payload)
            || (($payload['livemode'] ?? true) === false && config('lnkflow.cashier.include_test_events') !== true)) {
            return;
        }

        $type = $payload['type'] ?? null;
        $data = $payload['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;

        if (! is_array($object)) {
            return;
        }

        if ($type === 'invoice.paid') {
            $invoiceId = $object['id'] ?? null;
            $amount = $object['amount_paid'] ?? null;
            $currency = $object['currency'] ?? null;

            if (is_string($invoiceId) && is_int($amount) && is_string($currency)) {
                $this->conversions->sale(new Sale(
                    invoiceId: $invoiceId,
                    amount: $amount,
                    currency: $currency,
                    customerExternalId: is_string($object['customer'] ?? null) ? $object['customer'] : null,
                    paymentProcessor: 'stripe',
                ));
            }

            return;
        }

        if ($type === 'charge.refunded') {
            $invoiceId = $object['invoice'] ?? null;
            $container = $object['refunds'] ?? null;
            $refunds = is_array($container) ? ($container['data'] ?? null) : null;

            foreach (is_array($refunds) ? $refunds : [] as $refund) {
                if (! is_array($refund)
                    || ! is_string($invoiceId)
                    || ! is_string($refund['id'] ?? null)
                    || ! is_int($refund['amount'] ?? null)) {
                    continue;
                }

                $this->conversions->refund(new Refund(
                    invoiceId: $invoiceId,
                    refundId: $refund['id'],
                    amount: $refund['amount'],
                    paymentProcessor: 'stripe',
                ));
            }
        }
    }
}
