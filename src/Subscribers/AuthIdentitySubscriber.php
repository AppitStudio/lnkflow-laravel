<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Subscribers;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Events\Dispatcher;
use LnkFlow\Laravel\Contracts\CustomerExternalIdResolver;
use LnkFlow\Laravel\Jobs\IdentifyVisitorJob;
use LnkFlow\Laravel\Jobs\UnidentifyVisitorJob;
use LnkFlow\Laravel\Services\JourneyContext;

final readonly class AuthIdentitySubscriber
{
    public function __construct(
        private JourneyContext $context,
        private CustomerExternalIdResolver $customers,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [$this, 'identified']);
        $events->listen(Registered::class, [$this, 'identified']);
        $events->listen(Logout::class, [$this, 'logout']);
    }

    public function identified(Login|Registered $event): void
    {
        $visitorId = $this->context->visitorId();

        if ($visitorId === null) {
            return;
        }

        IdentifyVisitorJob::dispatch(
            $visitorId,
            $this->customers->resolve($event->user),
            $this->websiteId(),
        )->onQueue(config('lnkflow.journeys.queue'))->afterCommit();
    }

    public function logout(Logout $event): void
    {
        $visitorId = $this->context->visitorId();

        if ($visitorId === null) {
            return;
        }

        UnidentifyVisitorJob::dispatch($visitorId, $this->websiteId())
            ->onQueue(config('lnkflow.journeys.queue'))
            ->afterCommit();
    }

    private function websiteId(): ?int
    {
        $value = config('lnkflow.connections.'.config('lnkflow.default').'.website');

        return is_numeric($value) ? (int) $value : null;
    }
}
