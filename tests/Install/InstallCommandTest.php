<?php

declare(strict_types=1);

/*
 * These tests run against an application in a throwaway directory (see
 * SandboxedTestCase), so `lnkflow:install` really publishes files and we can
 * read back exactly what it wrote.
 */

it('installs a preset, writes the matching feature flags, and writes no secret', function (string $preset, array $expected): void {
    $this->artisan('lnkflow:install', ['--preset' => $preset])
        ->expectsOutputToContain("[{$preset}] preset")
        ->expectsOutputToContain('No secret was written to .env.')
        ->assertSuccessful();

    $published = (string) file_get_contents(config_path('lnkflow.php'));

    foreach ($expected as $feature => $enabled) {
        expect($published)->toContain(sprintf("'%s' => %s,", $feature, $enabled ? 'true' : 'false'));
    }

    // Every credential stays an env() lookup: `install` publishes
    // configuration, it never bakes a token into a file under version control.
    expect($published)->toContain("env('LNKFLOW_API_TOKEN')")
        ->and($published)->toContain("env('LNKFLOW_LINK_TOKEN')")
        ->and($published)->toContain("env('LNKFLOW_CONVERSION_TOKEN')")
        ->and($published)->not->toContain('api-test-token')
        ->and($published)->not->toContain('link-test-token')
        ->and($published)->not->toContain('conversion-test-token');
})->with([
    'client' => ['client', ['content' => false, 'journeys' => false, 'auth_identity' => false, 'conversions' => false]],
    'links' => ['links', ['content' => false, 'journeys' => false, 'auth_identity' => false, 'conversions' => false]],
    'content' => ['content', ['content' => true, 'journeys' => false, 'auth_identity' => false, 'conversions' => false]],
    'journeys' => ['journeys', ['content' => false, 'journeys' => true, 'auth_identity' => true, 'conversions' => false]],
    'conversions' => ['conversions', ['content' => false, 'journeys' => false, 'auth_identity' => false, 'conversions' => true]],
    'full' => ['full', ['content' => true, 'journeys' => true, 'auth_identity' => true, 'conversions' => true]],
]);

it('leaves the published configuration valid PHP that still parses', function (): void {
    $this->artisan('lnkflow:install', ['--preset' => 'full'])->assertSuccessful();

    $config = require config_path('lnkflow.php');

    expect($config)->toBeArray()
        ->and($config['features'])->toBe([
            'content' => true,
            'journeys' => true,
            'auth_identity' => true,
            'conversions' => true,
        ])
        ->and($config['connections']['default'])->toHaveKeys(['url', 'api_token', 'throttle']);
});

it('publishes the content mapping migrations', function (): void {
    $this->artisan('lnkflow:install', ['--preset' => 'content'])->assertSuccessful();

    $migrations = glob(database_path('migrations').'/*.php') ?: [];

    expect(implode("\n", $migrations))
        ->toContain('create_lnkflow_campaign_mappings_table')
        ->toContain('create_lnkflow_link_mappings_table');
});

it('names only the tokens the chosen preset actually needs', function (): void {
    $this->artisan('lnkflow:install', ['--preset' => 'conversions'])
        ->expectsOutputToContain('LNKFLOW_CONVERSION_TOKEN')
        ->doesntExpectOutputToContain('LNKFLOW_LINK_TOKEN (read,write)')
        ->assertSuccessful();
});

it('warns that consent must be bound before journey capture', function (): void {
    $this->artisan('lnkflow:install', ['--preset' => 'journeys'])
        ->expectsOutputToContain('Bind ConsentResolver before capture.')
        ->expectsOutputToContain('<x-lnkflow-script />')
        ->assertSuccessful();
});

it('leaves the Cashier bridge off and says why', function (): void {
    $this->artisan('lnkflow:install', ['--preset' => 'full'])
        ->expectsOutputToContain('Cashier remains disabled.')
        ->assertSuccessful();

    expect(require config_path('lnkflow.php'))
        ->cashier->toBe(['enabled' => false, 'include_test_events' => false]);
});

it('rejects an unknown install preset without publishing anything', function (): void {
    $this->artisan('lnkflow:install', ['--preset' => 'everything'])
        ->expectsOutputToContain('Invalid preset.')
        ->assertFailed();

    expect(is_file(config_path('lnkflow.php')))->toBeFalse()
        ->and(glob(database_path('migrations').'/*.php'))->toBe([]);
});

it('is safe to run twice and keeps the second preset', function (): void {
    $this->artisan('lnkflow:install', ['--preset' => 'full'])->assertSuccessful();
    $this->artisan('lnkflow:install', ['--preset' => 'content'])->assertSuccessful();

    expect(require config_path('lnkflow.php'))
        ->features->toBe([
            'content' => true,
            'journeys' => false,
            'auth_identity' => false,
            'conversions' => false,
        ]);
});
