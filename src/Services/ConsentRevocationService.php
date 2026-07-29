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

        $website = config('lnkflow.connections.'.config()->string('lnkflow.default', 'default').'.website');
        $queue = config('lnkflow.journeys.queue');

        RevokeVisitorJob::dispatch(
            $visitorId,
            is_numeric($website) ? (int) $website : null,
        )->onQueue(is_string($queue) ? $queue : null)->afterCommit();

        return true;
    }
}
