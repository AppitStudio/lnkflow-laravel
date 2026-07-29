<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use LnkFlow\Laravel\Contracts\ConversionMapper;
use LnkFlow\Laravel\Data\Lead;
use LnkFlow\Laravel\Data\Refund;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Events\ConversionQueued;
use LnkFlow\Laravel\Jobs\SendConversionJob;
use LnkFlow\Laravel\Services\ConversionDispatcher;
use LnkFlow\Laravel\Services\ConversionMapperRegistry;
use LnkFlow\Laravel\Services\JourneyContext;

final class SaleMapper implements ConversionMapper
{
    public function supports(object $event): bool
    {
        return property_exists($event, 'invoiceId');
    }

    public function map(object $event, JourneyContext $context): Lead|Sale|Refund|null
    {
        return new Sale((string) $event->invoiceId, 2500, 'usd');
    }
}

function dispatcherWithContext(array $state = []): ConversionDispatcher
{
    $context = new JourneyContext(new Store('wiring-test', new ArraySessionHandler(10)));
    $context->replace($state);

    return new ConversionDispatcher($context);
}

it('does not run configured conversion mappers while the feature is off', function (): void {
    Bus::fake();
    config()->set('lnkflow.features.conversions', false);
    config()->set('lnkflow.conversions.mappers', [SaleMapper::class]);
    $registry = new ConversionMapperRegistry(
        dispatcherWithContext(),
        app(JourneyContext::class),
    );

    expect($registry->map((object) ['invoiceId' => 'invoice_1']))->toBeFalse();
    Bus::assertNothingDispatched();
});

it('runs configured conversion mappers once the feature is on', function (): void {
    Bus::fake();
    config()->set('lnkflow.features.conversions', true);
    config()->set('lnkflow.conversions.mappers', [SaleMapper::class]);
    $registry = new ConversionMapperRegistry(
        dispatcherWithContext(),
        app(JourneyContext::class),
    );

    expect($registry->map((object) ['invoiceId' => 'invoice_1']))->toBeTrue();
    Bus::assertDispatched(SendConversionJob::class);
});

it('ignores an event no configured mapper supports', function (): void {
    Bus::fake();
    config()->set('lnkflow.features.conversions', true);
    config()->set('lnkflow.conversions.mappers', [SaleMapper::class]);
    $registry = new ConversionMapperRegistry(
        dispatcherWithContext(),
        app(JourneyContext::class),
    );

    expect($registry->map((object) ['unrelated' => true]))->toBeFalse();
    Bus::assertNothingDispatched();
});

it('queues a conversion after the host transaction commits rather than sending inline', function (): void {
    Bus::fake();
    Event::fake([ConversionQueued::class]);

    dispatcherWithContext()->sale(new Sale('invoice_42', 2500, 'usd'));

    Bus::assertDispatched(
        SendConversionJob::class,
        // A failed conversion report must never take a checkout down with it,
        // so nothing here touches the network.
        fn (SendConversionJob $job): bool => $job->afterCommit === true && $job->businessId === 'invoice_42',
    );
    Event::assertDispatched(ConversionQueued::class);
});

it('applies the journey context to a sale but never to a refund', function (): void {
    Bus::fake();
    $dispatcher = dispatcherWithContext([
        'visitor_id' => 'visitor-1',
        'first_click_id' => 'click-first',
        'last_click_id' => 'click-last',
        'promo_code' => 'NOVA10',
    ]);

    $dispatcher->sale(new Sale('invoice_42', 2500, 'usd'));
    $dispatcher->refund(new Refund('invoice_42', 'refund_1', 500));

    Bus::assertDispatched(SendConversionJob::class, function (SendConversionJob $job): bool {
        $payload = $job->conversion->toArray();

        return $job->type === 'sale'
            && $payload['visitor_id'] === 'visitor-1'
            && $payload['first_click_id'] === 'click-first'
            && $payload['click_id'] === 'click-last'
            && $payload['promo_code'] === 'NOVA10';
    });

    Bus::assertDispatched(SendConversionJob::class, function (SendConversionJob $job): bool {
        $payload = $job->conversion->toArray();

        // A refund attributes through the original sale, so moving visitor and
        // click identifiers around for it would be pointless exposure.
        return $job->type === 'refund'
            && ! array_key_exists('visitor_id', $payload)
            && ! array_key_exists('click_id', $payload)
            && ! array_key_exists('promo_code', $payload);
    });
});

it('registers every console command the package documents', function (): void {
    expect(array_keys(app(Kernel::class)->all()))
        ->toContain('lnkflow:install', 'lnkflow:doctor', 'lnkflow:sync', 'lnkflow:verify');
});

it('merges its own configuration so a host can override one key at a time', function (): void {
    expect(config('lnkflow.features'))->toBe([
        'content' => false,
        'journeys' => false,
        'auth_identity' => false,
        'conversions' => false,
    ])->and(config('lnkflow.journeys.session_key'))->toBe('_lnkflow');
});

it('publishes its config, views, and migrations under documented tags', function (): void {
    expect(ServiceProvider::publishableGroups())
        ->toContain('lnkflow', 'lnkflow-config', 'lnkflow-views', 'lnkflow-migrations');
});
