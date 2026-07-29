<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Commands;

use Illuminate\Console\Command;
use LnkFlow\Laravel\LnkFlowServiceProvider;

final class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'lnkflow:install {--preset=client : client|links|content|journeys|conversions|full}';

    /** @var string */
    protected $description = 'Publish and configure the LnkFlow Laravel integration';

    /** @var array<string, array<string, bool>> */
    private const PRESETS = [
        // Manual client calls need no feature flag; `client` and `links` are
        // guidance presets that publish config and print the right env vars.
        'client' => [],
        'links' => [],
        'content' => ['content' => true],
        'journeys' => ['journeys' => true, 'auth_identity' => true],
        'conversions' => ['conversions' => true],
        'full' => [
            'content' => true,
            'journeys' => true,
            'auth_identity' => true,
            'conversions' => true,
        ],
    ];

    public function handle(): int
    {
        $option = $this->option('preset');
        $preset = is_string($option) ? $option : '';

        if (! array_key_exists($preset, self::PRESETS)) {
            $this->components->error('Invalid preset. Use client, links, content, journeys, conversions, or full.');

            return self::FAILURE;
        }

        $this->callSilent('vendor:publish', [
            '--provider' => LnkFlowServiceProvider::class,
            '--tag' => 'lnkflow-config',
        ]);
        $this->callSilent('vendor:publish', [
            '--provider' => LnkFlowServiceProvider::class,
            '--tag' => 'lnkflow-migrations',
        ]);
        $this->applyPreset(config_path('lnkflow.php'), self::PRESETS[$preset]);

        $this->components->info("LnkFlow configuration installed with the [{$preset}] preset.");
        $this->line('Configure only the environment variables your preset needs:');
        $this->line('  LNKFLOW_API_URL, LNKFLOW_CONNECTION, LNKFLOW_TEAM, LNKFLOW_WEBSITE');

        if (in_array($preset, ['links', 'content', 'full'], true)) {
            $this->line('  LNKFLOW_LINK_TOKEN (read,write) or LNKFLOW_API_TOKEN');
        }

        if (in_array($preset, ['journeys', 'conversions', 'full'], true)) {
            $this->line('  LNKFLOW_CONVERSION_TOKEN (read,conversions) or LNKFLOW_API_TOKEN');
        }

        $this->newLine();
        $this->warn('No secret was written to .env. Configure a queue and shared cache before enabling automation.');

        if (in_array($preset, ['journeys', 'full'], true)) {
            $this->warn('Bind ConsentResolver before capture. Unknown consent stores and sends nothing.');
            $this->line('  Add CaptureJourneyContext to the web middleware group by hand, and render');
            $this->line('  <x-lnkflow-script /> once LNKFLOW_SITE_KEY is set.');
        }

        $this->line('Cashier remains disabled. Choose it explicitly only if the direct LnkFlow Stripe webhook is not reporting the same transactions.');
        $this->line('Next: php artisan migrate && php artisan lnkflow:doctor');

        return self::SUCCESS;
    }

    /** @param array<string, bool> $enabled */
    private function applyPreset(string $path, array $enabled): void
    {
        if (! is_file($path)) {
            return;
        }

        $features = [
            'content' => false,
            'journeys' => false,
            'auth_identity' => false,
            'conversions' => false,
            ...$enabled,
        ];
        $contents = (string) file_get_contents($path);
        $entries = array_map(
            static fn (string $feature, bool $enabled): string => sprintf(
                "        '%s' => %s,",
                $feature,
                $enabled ? 'true' : 'false',
            ),
            array_keys($features),
            array_values($features),
        );
        $replacement = "'features' => [\n".implode("\n", $entries)."\n    ],";
        $updated = preg_replace(
            "/'features'\\s*=>\\s*\\[(?:[^\\[\\]]|\\[[^\\]]*\\])*\\],/s",
            $replacement,
            $contents,
            1,
        );

        if (is_string($updated) && $updated !== $contents) {
            file_put_contents($path, $updated);
        }
    }
}
