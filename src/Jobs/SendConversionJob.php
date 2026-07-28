<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LnkFlow\Laravel\Contracts\Payload;
use LnkFlow\Laravel\Data\Lead;
use LnkFlow\Laravel\Data\NamedEvent;
use LnkFlow\Laravel\Data\Refund;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Events\ConversionFailed;
use LnkFlow\Laravel\Events\ConversionSent;
use LnkFlow\Laravel\Services\Client;
use LogicException;
use Throwable;

final class SendConversionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 300];

    public function __construct(
        public readonly string $type,
        public readonly Payload $conversion,
        public readonly string $businessId,
    ) {}

    public function handle(Client $client): void
    {
        $remote = match (true) {
            $this->conversion instanceof NamedEvent => $client->conversions()->event($this->conversion),
            $this->conversion instanceof Lead => $client->conversions()->lead($this->conversion),
            $this->conversion instanceof Sale => $client->conversions()->sale($this->conversion),
            $this->conversion instanceof Refund => $client->conversions()->refund($this->conversion),
            default => throw new LogicException('Unsupported LnkFlow conversion payload.'),
        };

        event(new ConversionSent($this->type, $this->businessId, $remote->id));
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
