<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Tests;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reader for the shared LnkFlow response corpus.
 *
 * `tests/Fixtures/contract` is a verbatim copy of the corpus the SaaS
 * repository generates from its own test suite with `php artisan
 * contract:fixtures` (source of truth: `docs/contract-fixtures/` in
 * `AppitStudio/lnkflow.io`). Every transport test drives its HTTP fakes from
 * these bytes, so the SDK and the deployed API cannot drift apart silently.
 *
 * The copy is vendored on purpose: this package is an independent repository,
 * so its CI cannot read a path inside the SaaS checkout. Two rules keep the
 * copy honest.
 *
 * 1. Never hand-edit anything under `contract/`. Refresh it instead:
 *
 *        rm -rf tests/Fixtures/contract
 *        cp -R ../../docs/contract-fixtures tests/Fixtures/contract
 *
 * 2. `ContractFixtureParityTest` fails when the SaaS corpus is present on disk
 *    and this copy differs from it. In a standalone checkout it skips.
 *
 * Responses the corpus does not record — 5xx bodies, connection failures, and
 * multi-page collections — are synthesized in the tests that need them, and
 * marked as such where they are built.
 */
final class Fixture
{
    public const BASE = 'https://app.lnkflow.test/api/v1';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * The whole fixture file: endpoint, case, request, status, headers, body.
     *
     * @return array<string, mixed>
     */
    public static function load(string $name): array
    {
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }

        $path = self::path($name);

        if (! is_file($path)) {
            throw new RuntimeException("Unknown LnkFlow contract fixture [{$name}].");
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Malformed LnkFlow contract fixture [{$name}].");
        }

        /** @var array<string, mixed> $decoded */
        return self::$cache[$name] = $decoded;
    }

    public static function path(string $name): string
    {
        return __DIR__.'/Fixtures/contract/'.$name.'.json';
    }

    /** @return array<string, mixed> */
    public static function body(string $name): array
    {
        $body = self::load($name)['body'] ?? [];

        return is_array($body) ? $body : [];
    }

    /** @return array<string, mixed> */
    public static function data(string $name): array
    {
        $data = self::body($name)['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    public static function status(string $name): int
    {
        return (int) (self::load($name)['status'] ?? 200);
    }

    /** @return array<string, string> */
    public static function headers(string $name): array
    {
        $headers = self::load($name)['headers'] ?? [];
        $normalized = [];

        foreach (is_array($headers) ? $headers : [] as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }

    /**
     * The absolute URL this fixture was recorded against, rewritten onto the
     * host the test suite configures. Usable directly as an `Http::fake()` key.
     */
    public static function url(string $name): string
    {
        $request = self::load($name)['request'] ?? [];
        $path = is_array($request) ? ($request['path'] ?? '') : '';

        return 'https://app.lnkflow.test'.(is_string($path) ? $path : '');
    }

    /**
     * A ready-made HTTP fake for this fixture.
     *
     * @param  array<string, mixed>  $body  replaces the recorded body entirely when non-empty
     * @param  array<string, string>  $headers  merged over the recorded headers
     */
    public static function response(string $name, array $body = [], array $headers = []): PromiseInterface
    {
        return Http::response(
            $body === [] ? self::body($name) : $body,
            self::status($name),
            [...self::headers($name), ...$headers],
        );
    }

    /**
     * The recorded body with the given dot-free top-level `data` keys replaced.
     * Used where a test needs a shape the corpus does not contain (an unknown
     * enum value, a second page, two different slugs) without abandoning the
     * real payload around it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function bodyWithData(string $name, array $overrides): array
    {
        $body = self::body($name);
        $body['data'] = [...self::data($name), ...$overrides];

        return $body;
    }

    /** @return list<array<string, mixed>> */
    public static function index(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(__DIR__.'/Fixtures/contract/index.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $fixtures = is_array($decoded) ? ($decoded['fixtures'] ?? []) : [];

        return array_values(array_filter(is_array($fixtures) ? $fixtures : [], is_array(...)));
    }
}
