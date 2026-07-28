<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use LnkFlow\Laravel\Services\LnkFlowManager;
use LnkFlow\Laravel\Testing\FakeTransport;

/**
 * @method static \LnkFlow\Laravel\Services\Client client()
 * @method static \LnkFlow\Laravel\Services\Client connection(string $connection)
 * @method static \LnkFlow\Laravel\Services\Client forTeam(int|string|null $team)
 * @method static void trackEvent(\LnkFlow\Laravel\Data\NamedEvent $event)
 * @method static void trackLead(\LnkFlow\Laravel\Data\Lead $lead)
 * @method static void trackSale(\LnkFlow\Laravel\Data\Sale $sale)
 * @method static void trackRefund(\LnkFlow\Laravel\Data\Refund $refund)
 */
final class LnkFlow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'lnkflow';
    }

    public static function fake(): FakeTransport
    {
        return self::manager()->fake();
    }

    public static function assertLinkCreated(?Closure $callback = null): void
    {
        self::manager()->assertSent('POST', 'campaigns/*/links', $callback);
    }

    public static function assertLinkUpdated(int $id, ?Closure $callback = null): void
    {
        self::manager()->assertSent('PATCH', "links/{$id}", $callback);
    }

    public static function assertTouchpointCaptured(?Closure $callback = null): void
    {
        self::manager()->assertSent('POST', 'journeys/touchpoints', $callback);
    }

    public static function assertVisitorIdentified(?Closure $callback = null): void
    {
        self::manager()->assertSent('POST', 'journeys/identify', $callback);
    }

    public static function assertVisitorUnidentified(?Closure $callback = null): void
    {
        self::manager()->assertSent('POST', 'journeys/unidentify', $callback);
    }

    public static function assertEventTracked(?Closure $callback = null): void
    {
        self::manager()->assertSent('POST', 'track/lead', $callback);
    }

    public static function assertSaleTracked(?Closure $callback = null): void
    {
        self::manager()->assertSent('POST', 'track/sale', $callback);
    }

    public static function assertRefundTracked(?Closure $callback = null): void
    {
        self::manager()->assertSent('POST', 'track/refund', $callback);
    }

    public static function assertNothingSent(): void
    {
        self::manager()->assertNothingSent();
    }

    private static function manager(): LnkFlowManager
    {
        /** @var LnkFlowManager $root */
        $root = parent::getFacadeRoot();

        return $root;
    }
}
