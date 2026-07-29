<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use LnkFlow\Laravel\Jobs\SyncLinkableContentJob;
use LnkFlow\Laravel\Services\ContentSynchronizer;

final class SyncCommand extends Command
{
    /** @var string */
    protected $signature = 'lnkflow:sync
        {--dry-run : Preview remote validation without writing}
        {--model= : One configured Eloquent model class}
        {--id= : One model primary key}
        {--chunk=100 : Chunk size}
        {--force : Ignore matching payload hashes}';

    /** @var string */
    protected $description = 'Reconcile configured Eloquent content with LnkFlow';

    public function handle(ContentSynchronizer $synchronizer): int
    {
        $configured = config('lnkflow.content.models', []);
        $models = is_array($configured) ? array_keys($configured) : [];
        $selected = $this->option('model');

        if (is_string($selected) && $selected !== '') {
            $models = in_array($selected, $models, true) ? [$selected] : [];
        }

        if ($models === []) {
            $this->components->error('No matching content model is configured.');

            return self::FAILURE;
        }

        foreach ($models as $modelClass) {
            $model = new $modelClass;

            if (! $model instanceof Model) {
                continue;
            }

            if (is_scalar($this->option('id')) && (string) $this->option('id') !== '') {
                $this->process($synchronizer, $modelClass, (string) $this->option('id'));

                continue;
            }

            $model->newQuery()->chunkById(
                max(1, (int) $this->option('chunk')),
                function ($rows) use ($synchronizer, $modelClass): void {
                    foreach ($rows as $row) {
                        if (is_scalar($row->getKey())) {
                            $this->process($synchronizer, $modelClass, (string) $row->getKey());
                        }
                    }
                },
            );
        }

        $this->warn('Eloquent mass updates/deletes bypass observers; this command is the repair and backfill path.');

        return self::SUCCESS;
    }

    private function process(ContentSynchronizer $synchronizer, string $modelClass, string $key): void
    {
        if ($this->option('dry-run')) {
            $previews = $synchronizer->preview($modelClass, $key);
            $this->line("Would synchronize {$modelClass}:{$key} with ".count($previews).' link(s).');

            return;
        }

        $queue = config('lnkflow.content.queue');

        SyncLinkableContentJob::dispatch($modelClass, $key, (bool) $this->option('force'))
            ->onQueue(is_string($queue) ? $queue : null);
        $this->line("Queued {$modelClass}:{$key}.");
    }
}
