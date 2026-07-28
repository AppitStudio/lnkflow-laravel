<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use LnkFlow\Laravel\Services\ContentSynchronizer;

final class DisableLinkableContentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $modelClass,
        public readonly string $sourceKey,
    ) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('lnkflow:disable:'.hash('sha256', $this->modelClass.':'.$this->sourceKey)))
                ->releaseAfter(15)
                ->expireAfter(300),
        ];
    }

    public function handle(ContentSynchronizer $synchronizer): void
    {
        $synchronizer->disableSource($this->modelClass, $this->sourceKey);
    }
}
