<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use LnkFlow\Laravel\Data\NamedEvent;
use LnkFlow\Laravel\Services\Client;

final class VerifyCommand extends Command
{
    /** @var string */
    protected $signature = 'lnkflow:verify {--test-conversion : Create and read back a labeled test event} {--force : Skip confirmation}';

    /** @var string */
    protected $description = 'Run an explicit mutating LnkFlow integration verification';

    public function handle(Client $client): int
    {
        if (! $this->option('test-conversion')) {
            $this->components->error('Pass --test-conversion to select the explicit mutation.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Create a clearly labeled test conversion in LnkFlow?')) {
            return self::FAILURE;
        }

        $businessId = 'lnkflow_verify_'.Str::uuid();
        $event = $client->conversions()->event(new NamedEvent(
            'lnkflow_verification',
            $businessId,
            context: ['test' => true],
        ));
        $events = $client->conversions()->events(['test' => true, 'limit' => 100]);
        $found = collect($events)->contains(fn ($item): bool => $item->id === $event->id);

        $found
            ? $this->components->info("Test conversion {$event->id} was created and read back.")
            : $this->components->error('The test conversion was created but not found in the verification read.');

        $this->warn('The test event is retained and is excluded from production statistics.');

        return $found ? self::SUCCESS : self::FAILURE;
    }
}
