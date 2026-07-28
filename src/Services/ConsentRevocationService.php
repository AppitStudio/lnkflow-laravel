<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Jobs\RevokeVisitorJob;

final readonly class ConsentRevocationService
{
    public function __construct(private JourneyContext $context) {}

    public function revoke(): bool
    {
        $visitorId = $this->context->visitorId();
        $this->context->clear();

        if ($visitorId === null) {
            return false;
        }

        $website = config('lnkflow.connections.'.config('lnkflow.default').'.website');

        RevokeVisitorJob::dispatch(
            $visitorId,
            is_numeric($website) ? (int) $website : null,
        )->onQueue(config('lnkflow.journeys.queue'))->afterCommit();

        return true;
    }
}
