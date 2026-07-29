<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Contracts\ConversionMapper;
use LnkFlow\Laravel\Data\Lead;
use LnkFlow\Laravel\Data\Refund;
use LnkFlow\Laravel\Data\Sale;

final readonly class ConversionMapperRegistry
{
    public function __construct(
        private ConversionDispatcher $dispatcher,
        private JourneyContext $context,
    ) {}

    public function map(object $event): bool
    {
        if (config('lnkflow.features.conversions') !== true) {
            return false;
        }

        $mappers = config('lnkflow.conversions.mappers', []);

        foreach (is_array($mappers) ? $mappers : [] as $mapperClass) {
            if (! is_string($mapperClass)) {
                continue;
            }

            $mapper = app($mapperClass);

            if (! $mapper instanceof ConversionMapper || ! $mapper->supports($event)) {
                continue;
            }

            $conversion = $mapper->map($event, $this->context);

            match (true) {
                $conversion instanceof Lead => $this->dispatcher->lead($conversion),
                $conversion instanceof Sale => $this->dispatcher->sale($conversion),
                $conversion instanceof Refund => $this->dispatcher->refund($conversion),
                default => null,
            };

            return $conversion !== null;
        }

        return false;
    }
}
