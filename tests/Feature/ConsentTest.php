<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LnkFlow\Laravel\Contracts\ConsentResolver;
use LnkFlow\Laravel\Data\ConsentState;
use LnkFlow\Laravel\Http\Middleware\CaptureJourneyContext;
use LnkFlow\Laravel\Services\JourneyContext;
use Symfony\Component\HttpFoundation\Response;

function consentResolver(ConsentState $storage): ConsentResolver
{
    return new class($storage) implements ConsentResolver
    {
        public function __construct(private readonly ConsentState $storage) {}

        public function storage(Request $request): ConsentState
        {
            return $this->storage;
        }

        public function adUserData(Request $request): ConsentState
        {
            return ConsentState::Unknown;
        }

        public function adPersonalization(Request $request): ConsentState
        {
            return ConsentState::Denied;
        }
    };
}

function journeyRequest(string $clickId, string $userAgent = 'Mozilla/5.0'): array
{
    $session = new Store('lnkflow-test', new ArraySessionHandler(10));
    $request = Request::create('/landing?lnk_id='.$clickId.'&lnk_promo=SAVE20', 'GET');
    $request->headers->set('User-Agent', $userAgent);
    $request->setLaravelSession($session);

    return [$request, $session];
}

it('stores and queues a touchpoint only after explicit storage consent', function (): void {
    Event::fake([JobQueued::class]);
    $clickId = (string) Str::uuid();
    [$request, $session] = journeyRequest($clickId);
    $middleware = new CaptureJourneyContext(
        consentResolver(ConsentState::Granted),
        new JourneyContext($session),
    );

    $response = $middleware->handle($request, fn (): Response => new Response('ok'));

    expect($response->getContent())->toBe('ok')
        ->and($session->get('_lnkflow.first_click_id'))->toBe($clickId)
        ->and($session->get('_lnkflow.last_click_id'))->toBe($clickId)
        ->and($session->get('_lnkflow.promo_code'))->toBe('SAVE20')
        ->and($session->get('_lnkflow.visitor_id'))->toBeString();
});

it('does nothing for unknown consent or automated requests', function (): void {
    $clickId = (string) Str::uuid();
    [$unknownRequest, $unknownSession] = journeyRequest($clickId);
    $unknown = new CaptureJourneyContext(
        consentResolver(ConsentState::Unknown),
        new JourneyContext($unknownSession),
    );
    $unknown->handle($unknownRequest, fn (): Response => new Response('ok'));

    [$botRequest, $botSession] = journeyRequest($clickId, 'ExampleBot/1.0');
    $bot = new CaptureJourneyContext(
        consentResolver(ConsentState::Granted),
        new JourneyContext($botSession),
    );
    $bot->handle($botRequest, fn (): Response => new Response('ok'));

    expect($unknownSession->has('_lnkflow'))->toBeFalse()
        ->and($botSession->has('_lnkflow'))->toBeFalse();
});
