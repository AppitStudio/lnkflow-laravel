<?php

declare(strict_types=1);

use LnkFlow\Laravel\Tests\Fixture;

/**
 * The vendored corpus under `tests/Fixtures/contract` must stay byte-identical
 * to the SaaS repository's generated `docs/contract-fixtures`. When this
 * package is checked out next to the SaaS repository we can prove that; in a
 * standalone checkout there is nothing to compare against, so the test skips
 * rather than failing CI for an absence.
 */
it('keeps the vendored contract corpus identical to the generated source corpus', function (): void {
    $source = __DIR__.'/../../../../docs/contract-fixtures';

    if (! is_dir($source)) {
        test()->markTestSkipped('The SaaS contract-fixture corpus is not present in this checkout.');
    }

    $hash = static function (string $root): array {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'json') {
                $files[str_replace($root.'/', '', $file->getPathname())] = md5_file($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    };

    expect($hash(__DIR__.'/../Fixtures/contract'))->toBe($hash($source));
});

it('covers every fixture endpoint the corpus publishes', function (): void {
    $endpoints = array_values(array_unique(array_column(Fixture::index(), 'endpoint')));

    // Every endpoint in the corpus is either exercised by the SDK's transport
    // tests or deliberately out of scope. Keeping the list here means a new
    // server endpoint shows up as a failing assertion rather than silently
    // going untested.
    expect($endpoints)->toBe([
        'me',
        'search',
        'browser-extension-bootstrap',
        'campaigns-index',
        'campaigns-show',
        'campaigns-store',
        'campaign-links-index',
        'campaign-links-store',
        'links-show',
        'links-update',
        'links-preview',
        'websites-index',
        'websites-show',
        'websites-store',
        'influencers-index',
        'influencers-show',
        'influencers-store',
        'domains-index',
        'stats-summary',
        'track-lead',
        'track-sale',
        'track-refund',
        'track-events',
        'stats-conversions',
        'journeys-touchpoints',
        'influencer-commissions',
    ]);
});
