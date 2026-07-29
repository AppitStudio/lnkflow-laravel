<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LnkFlow\Laravel\Contracts\Payload;
use LnkFlow\Laravel\Events\ConversionFailed;
use LnkFlow\Laravel\Events\ConversionSent;
use LnkFlow\Laravel\Jobs\Concerns\ReportsApiFailures;
use LnkFlow\Laravel\Services\Client;
use Throwable;

/**
 * Reports one conversion, after the host transaction committed.
 *
 * The payload is sent exactly as it was built at dispatch time. Rebuilding it
 * here would discard the journey context captured in the request that produced
 * the conversion, which is the only place that context exists.
 */
final class SendConversionJob implements ShouldQueue
{
    use Queueable;
    use ReportsApiFailures;

    public function __construct(
        public readonly string $type,
        public readonly Payload $conversion,
        public readonly string $businessId,
    ) {}

    public function handle(Client $client): void
    {
        $this->callApi(function () use ($client): void {
            $remote = $client->conversions()->send($this->type, $this->conversion, $this->businessId);

            event(new ConversionSent($this->type, $this->businessId, $remote->id));
        });
    }

    public function failed(?Throwable $exception): void
    {
        event(new ConversionFailed(
            $this->type,
            $this->businessId,
            $exception instanceof Throwable ? $exception::class : 'unknown',
        ));
    }
}
