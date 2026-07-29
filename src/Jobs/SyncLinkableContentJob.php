<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use LnkFlow\Laravel\Jobs\Concerns\ReportsApiFailures;
use LnkFlow\Laravel\Services\ContentSynchronizer;

final class SyncLinkableContentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use ReportsApiFailures;

    public function __construct(
        public readonly string $modelClass,
        public readonly string $modelKey,
        public readonly bool $force = false,
    ) {}

    public function uniqueId(): string
    {
        return hash('sha256', $this->modelClass.':'.$this->modelKey);
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('lnkflow:content:'.$this->uniqueId()))
                ->releaseAfter(15)
                ->expireAfter(300),
        ];
    }

    public function handle(ContentSynchronizer $synchronizer): void
    {
        $this->callApi(fn (): mixed => $synchronizer->sync(
            $this->modelClass,
            $this->modelKey,
            $this->force,
        ));
    }
}
