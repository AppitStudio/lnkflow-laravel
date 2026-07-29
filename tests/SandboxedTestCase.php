<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Tests;

use Illuminate\Filesystem\Filesystem;

/**
 * A test case whose application lives in a throwaway directory of its own.
 *
 * `lnkflow:install` publishes files into `config/` and `database/migrations/`.
 * Against the shared Testbench skeleton that is not safe: every other test
 * process boots an application from the same directory, so a published — and
 * then removed — `config/lnkflow.php` makes unrelated tests fail, intermittently
 * and only under `--parallel`. Giving these tests their own base path means the
 * command really does publish, and publishes somewhere nobody else can see.
 */
abstract class SandboxedTestCase extends TestCase
{
    protected string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir().'/lnkflow-install-'.getmypid().'-'.bin2hex(random_bytes(4));
        $files = new Filesystem;

        foreach (['config', 'database/migrations', 'bootstrap/cache', 'storage/framework/views', 'storage/logs'] as $directory) {
            $files->ensureDirectoryExists($this->sandbox.'/'.$directory);
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem)->deleteDirectory($this->sandbox);
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Testbench caches the framework's default configuration statically the
        // first time an application boots from the shared skeleton, so an app
        // with its own base path can come up without these. Point them at the
        // sandbox explicitly rather than depending on suite ordering.
        $app['config']->set('view.compiled', $this->sandbox.'/storage/framework/views');
        $app['config']->set('view.paths', [$this->sandbox.'/resources/views']);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function getApplicationBasePath(): string
    {
        return $this->sandbox;
    }
}
