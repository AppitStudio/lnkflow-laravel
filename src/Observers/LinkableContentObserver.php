<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Observers;

use Illuminate\Database\Eloquent\Model;
use LnkFlow\Laravel\Contracts\LinkableContent;
use LnkFlow\Laravel\Jobs\DisableLinkableContentJob;
use LnkFlow\Laravel\Jobs\SyncLinkableContentJob;

final class LinkableContentObserver
{
    public bool $afterCommit = true;

    public function saved(Model $model): void
    {
        $this->dispatchSync($model);
    }

    public function restored(Model $model): void
    {
        $this->dispatchSync($model);
    }

    public function deleted(Model $model): void
    {
        $key = $this->sourceKey($model);

        if ($key !== null) {
            DisableLinkableContentJob::dispatch($model::class, $key)
                ->onQueue(config('lnkflow.content.queue'))
                ->afterCommit();
        }
    }

    private function dispatchSync(Model $model): void
    {
        $key = $model->getKey();

        if (is_scalar($key)) {
            SyncLinkableContentJob::dispatch($model::class, (string) $key)
                ->onQueue(config('lnkflow.content.queue'))
                ->afterCommit();
        }
    }

    private function sourceKey(Model $model): ?string
    {
        $models = config('lnkflow.content.models', []);
        $adapterClass = is_array($models) ? ($models[$model::class] ?? null) : null;
        $adapter = is_string($adapterClass) ? app($adapterClass) : $adapterClass;

        if ($adapter instanceof LinkableContent) {
            return $adapter->lnkFlowSourceKey($model);
        }

        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : null;
    }
}
