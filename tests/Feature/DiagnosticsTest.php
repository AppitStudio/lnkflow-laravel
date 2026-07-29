<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Exceptions\ConnectionException;
use LnkFlow\Laravel\Services\Client;
use LnkFlow\Laravel\Tests\Fixture;

/*
 * Diagnostics exist so a support request can be correlated with a server-side
 * request id. They must never be a second place a token, a payload, or a
 * customer lives.
 */

/** Captures what the SDK writes to the log. Each entry is [message, context]. */
function captureLnkFlowLogs(): ArrayObject
{
    $captured = new ArrayObject;

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info')->andReturnUsing(function (string $message, array $context = []) use ($captured): void {
        $captured[] = [$message, $context];
    });

    return $captured;
}

it('writes nothing at all when logging is disabled', function (): void {
    Http::fake(['*' => Fixture::response('me/200')]);
    $captured = captureLnkFlowLogs();

    app(Client::class)->identity()->me();

    expect($captured)->toHaveCount(0);
});

it('logs the safe diagnostic fields and nothing else', function (): void {
    config()->set('lnkflow.logging.enabled', true);
    Http::fake([Fixture::url('me/200').'*' => Fixture::response('me/200')]);
    $captured = captureLnkFlowLogs();

    app(Client::class)->identity()->me();

    expect($captured)->toHaveCount(1)
        ->and($captured[0][0])->toBe('lnkflow.api');

    $context = $captured[0][1];

    expect(array_keys($context))->toBe([
        'connection',
        'purpose',
        'method',
        'path',
        'status',
        'attempt',
        'duration_ms',
        'request_id',
        'team',
    ])
        ->and($context['connection'])->toBe('default')
        ->and($context['purpose'])->toBe('api')
        ->and($context['method'])->toBe('GET')
        ->and($context['path'])->toBe('me')
        ->and($context['status'])->toBe(200)
        ->and($context['attempt'])->toBe(1)
        ->and($context['duration_ms'])->toBeInt()
        ->and($context['request_id'])->toMatch('/^[0-9a-f-]{36}$/')
        ->and($context['team'])->toBe('team-test');
});

it('never writes a token or a payload into a diagnostic', function (): void {
    config()->set('lnkflow.logging.enabled', true);
    Http::fake(['*' => Fixture::response('track-sale/201')]);
    $captured = captureLnkFlowLogs();

    app(Client::class)->conversions()->sale(new Sale(
        'invoice_42',
        2500,
        'usd',
        customerExternalId: 'customer_opaque_7',
        clickId: '30000000-0000-4000-8000-000000000001',
        visitorId: '10000000-0000-4000-8000-000000000001',
    ));

    $serialized = json_encode($captured->getArrayCopy(), JSON_THROW_ON_ERROR);

    expect($serialized)->not->toContain('api-test-token')
        ->and($serialized)->not->toContain('link-test-token')
        ->and($serialized)->not->toContain('conversion-test-token')
        ->and($serialized)->not->toContain('Bearer')
        ->and($serialized)->not->toContain('Authorization')
        ->and($serialized)->not->toContain('customer_opaque_7')
        ->and($serialized)->not->toContain('30000000-0000-4000-8000-000000000001')
        ->and($serialized)->not->toContain('10000000-0000-4000-8000-000000000001')
        ->and($serialized)->not->toContain('invoice_42')
        ->and($serialized)->not->toContain('2500');
});

it('records the attempt number so a retried call is legible', function (): void {
    config()->set('lnkflow.logging.enabled', true);
    Http::fakeSequence()
        ->push(['message' => 'Server Error'], 500)
        ->push(Fixture::body('campaigns-show/200'), 200);
    $captured = captureLnkFlowLogs();

    app(Client::class)->campaigns()->get(1);

    expect($captured)->toHaveCount(1)
        ->and($captured[0][1]['attempt'])->toBe(2)
        ->and($captured[0][1]['status'])->toBe(200)
        ->and($captured[0][1]['path'])->toBe('campaigns/1');
});

it('records a connection failure as a safe failure code, still without a token', function (): void {
    config()->set('lnkflow.logging.enabled', true);
    config()->set('lnkflow.connections.default.attempts', 1);
    Http::fake(['*' => Http::failedConnection('Could not resolve host: app.lnkflow.test')]);
    $captured = captureLnkFlowLogs();

    expect(fn () => app(Client::class)->identity()->me())->toThrow(ConnectionException::class);

    expect($captured)->toHaveCount(1)
        ->and($captured[0][1]['failure'])->toBe('connection_failed')
        ->and($captured[0][1]['status'])->toBeNull()
        ->and(json_encode($captured->getArrayCopy(), JSON_THROW_ON_ERROR))->not->toContain('api-test-token');
});

it('records the purpose so a least-privilege mistake is visible in the log', function (): void {
    config()->set('lnkflow.logging.enabled', true);
    Http::fake(['*' => Http::response(['data' => []])]);
    $captured = captureLnkFlowLogs();

    app(Client::class)->links()->list();

    expect($captured[0][1]['purpose'])->toBe('links');
});

it('honours the configured log channel', function (): void {
    config()->set('lnkflow.logging.enabled', true);
    config()->set('lnkflow.logging.channel', 'lnkflow');
    Http::fake(['*' => Fixture::response('me/200')]);
    $channels = [];
    Log::shouldReceive('channel')->andReturnUsing(function (?string $channel) use (&$channels) {
        $channels[] = $channel;

        return Log::getFacadeRoot();
    });
    Log::shouldReceive('info');

    app(Client::class)->identity()->me();

    expect($channels)->toBe(['lnkflow']);
});
