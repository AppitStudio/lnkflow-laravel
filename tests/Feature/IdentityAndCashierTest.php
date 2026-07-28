<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LnkFlow\Laravel\Contracts\CustomerExternalIdResolver;
use LnkFlow\Laravel\Jobs\IdentifyVisitorJob;
use LnkFlow\Laravel\Jobs\RevokeVisitorJob;
use LnkFlow\Laravel\Jobs\SendConversionJob;
use LnkFlow\Laravel\Jobs\UnidentifyVisitorJob;
use LnkFlow\Laravel\Listeners\CashierWebhookListener;
use LnkFlow\Laravel\Services\ConsentRevocationService;
use LnkFlow\Laravel\Services\ConversionDispatcher;
use LnkFlow\Laravel\Services\DefaultCustomerExternalIdResolver;
use LnkFlow\Laravel\Services\JourneyContext;
use LnkFlow\Laravel\Subscribers\AuthIdentitySubscriber;

function identityContext(?string $visitorId = null): JourneyContext
{
    $session = new Store('identity-test', new ArraySessionHandler(10));
    $context = new JourneyContext($session);

    if ($visitorId !== null) {
        $context->replace(['visitor_id' => $visitorId]);
    }

    return $context;
}

it('queues identify, unidentify, and explicit revocation as separate operations', function (): void {
    Bus::fake();
    $visitor = (string) Str::uuid();
    $context = identityContext($visitor);
    $resolver = new class implements CustomerExternalIdResolver
    {
        public function resolve(object $user): string
        {
            return 'customer_opaque_7';
        }
    };
    $subscriber = new AuthIdentitySubscriber($context, $resolver);
    $user = new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 7;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }

        public function getAuthPasswordSalt(): string
        {
            return '';
        }
    };

    $subscriber->identified(new Login('web', $user, false));
    $subscriber->logout(new Logout('web', $user));
    $revoked = (new ConsentRevocationService($context))->revoke();

    Bus::assertDispatched(IdentifyVisitorJob::class, fn (IdentifyVisitorJob $job): bool => $job->visitorId === $visitor);
    Bus::assertDispatched(UnidentifyVisitorJob::class, fn (UnidentifyVisitorJob $job): bool => $job->visitorId === $visitor);
    Bus::assertDispatched(RevokeVisitorJob::class, fn (RevokeVisitorJob $job): bool => $job->visitorId === $visitor);
    expect($revoked)->toBeTrue()
        ->and($context->visitorId())->toBeNull();
});

it('maps supported Cashier payloads and ignores test mode by default', function (): void {
    Bus::fake();
    Event::fake();
    $listener = new CashierWebhookListener(new ConversionDispatcher(identityContext()));

    $listener((object) ['payload' => [
        'id' => 'evt_test',
        'livemode' => false,
        'type' => 'invoice.paid',
        'data' => ['object' => [
            'id' => 'invoice_test',
            'amount_paid' => 100,
            'currency' => 'usd',
        ]],
    ]]);
    Bus::assertNotDispatched(SendConversionJob::class);

    $listener((object) ['payload' => [
        'id' => 'evt_live',
        'livemode' => true,
        'type' => 'invoice.paid',
        'data' => ['object' => [
            'id' => 'invoice_live',
            'amount_paid' => 2500,
            'currency' => 'usd',
            'customer' => 'cus_opaque',
        ]],
    ]]);

    Bus::assertDispatched(SendConversionJob::class, function (SendConversionJob $job): bool {
        $payload = $job->conversion->toArray();

        return $job->businessId === 'invoice_live'
            && $payload['amount'] === 2500
            && $payload['provider_event_ids']['meta'] === 'evt_live';
    });
});

it('derives opaque default customer ids without email or names', function (): void {
    config()->set('lnkflow.journeys.app_namespace', 'docs-app');
    $user = new class
    {
        public function getKey(): int
        {
            return 42;
        }
    };

    expect((new DefaultCustomerExternalIdResolver)->resolve($user))
        ->toBe('docs-app:42');
});
