<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Listeners;

use LnkFlow\Laravel\Data\Refund;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Services\ConversionDispatcher;

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
        $object = $payload['data']['object'] ?? null;

        if (! is_array($object)) {
            return;
        }

        if ($type === 'invoice.paid') {
            $invoiceId = $object['id'] ?? null;
            $amount = $object['amount_paid'] ?? null;
            $currency = $object['currency'] ?? null;

            if (is_string($invoiceId) && is_int($amount) && is_string($currency)) {
                $this->conversions->sale(new Sale(
                    $invoiceId,
                    $amount,
                    $currency,
                    is_string($object['customer'] ?? null) ? $object['customer'] : null,
                    ['provider_event_ids' => ['meta' => $payload['id'] ?? null]],
                ));
            }

            return;
        }

        if ($type === 'charge.refunded') {
            $invoiceId = $object['invoice'] ?? null;
            $refunds = $object['refunds']['data'] ?? [];

            foreach (is_array($refunds) ? $refunds : [] as $refund) {
                if (! is_array($refund)
                    || ! is_string($invoiceId)
                    || ! is_string($refund['id'] ?? null)
                    || ! is_int($refund['amount'] ?? null)
                    || ! is_string($object['currency'] ?? null)) {
                    continue;
                }

                $this->conversions->refund(new Refund(
                    $invoiceId,
                    $refund['id'],
                    $refund['amount'],
                    $object['currency'],
                    ['provider_event_ids' => ['meta' => $payload['id'] ?? null]],
                ));
            }
        }
    }
}
