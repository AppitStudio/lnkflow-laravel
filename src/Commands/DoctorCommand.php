<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use LnkFlow\Laravel\Contracts\ConsentResolver;
use LnkFlow\Laravel\Data\ConsentState;
use LnkFlow\Laravel\Exceptions\LnkFlowException;
use LnkFlow\Laravel\Services\Client;
use LnkFlow\Laravel\Services\DefaultConsentResolver;

final class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'lnkflow:doctor';

    /** @var string */
    protected $description = 'Run read-only LnkFlow configuration and connectivity checks';

    public function handle(Client $client, ConsentResolver $consent): int
    {
        $connection = config()->string('lnkflow.default', 'default');
        $settings = config("lnkflow.connections.{$connection}", []);
        $failures = 0;
        $url = is_array($settings) ? ($settings['url'] ?? null) : null;
        $team = is_array($settings) ? ($settings['team'] ?? null) : null;
        $token = is_array($settings)
            ? ($settings['api_token'] ?? $settings['link_token'] ?? $settings['conversion_token'] ?? null)
            : null;

        $failures += $this->check(is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false, 'API URL is valid');
        $productionUrl = is_string($url) && ! str_contains($url, '.test') && ! str_contains($url, 'localhost');
        $failures += $this->check(! $productionUrl || str_starts_with($url, 'https://'), 'Production API URL uses TLS');
        $failures += $this->check(is_string($token) && $token !== '', 'An API token is configured');
        $failures += $this->check(is_scalar($team) && (string) $team !== '', 'A team is configured');

        if (config('lnkflow.features.content') === true) {
            $failures += $this->check(
                Schema::hasTable('lnkflow_campaign_mappings') && Schema::hasTable('lnkflow_link_mappings'),
                'Content mapping migrations are applied',
            );
        }

        if (config('lnkflow.features.journeys') === true) {
            $failures += $this->check(! $consent instanceof DefaultConsentResolver, 'A host ConsentResolver is bound');
            $this->line('  storage consent default: '.ConsentState::Unknown->value);
        }

        $queue = config('queue.default');
        $session = config('session.driver');

        $this->line('  queue: '.(is_scalar($queue) ? (string) $queue : ''));
        $this->line('  session: '.(is_scalar($session) ? (string) $session : ''));
        $this->line('  Cashier adapter: '.(config('lnkflow.cashier.enabled') === true ? 'enabled' : 'disabled'));

        if ($failures === 0) {
            try {
                $identity = $client->identity()->me();
                $this->components->info('API connectivity succeeded; abilities: '.implode(', ', array_keys(array_filter($identity->capabilities))));
            } catch (LnkFlowException $exception) {
                $this->components->error('API connectivity failed: '.$exception->getMessage().' (request '.($exception->requestId ?? 'n/a').')');
                $failures++;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function check(bool $condition, string $label): int
    {
        $condition
            ? $this->components->info($label)
            : $this->components->error($label);

        return $condition ? 0 : 1;
    }
}
