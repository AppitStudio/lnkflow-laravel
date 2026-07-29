<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LnkFlow\Laravel\Contracts\ConsentResolver;
use LnkFlow\Laravel\Data\Consent;
use LnkFlow\Laravel\Data\ConsentState;
use LnkFlow\Laravel\Jobs\CaptureTouchpointJob;
use LnkFlow\Laravel\Services\JourneyContext;
use Symfony\Component\HttpFoundation\Response;

final readonly class CaptureJourneyContext
{
    public function __construct(
        private ConsentResolver $consent,
        private JourneyContext $context,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)
            || $this->isAutomated($request)
            || ! $request->hasSession()) {
            return $next($request);
        }

        $clickId = $request->query('lnk_id');

        if (! is_string($clickId) || ! Str::isUuid($clickId)) {
            return $next($request);
        }

        $storage = $this->consent->storage($request);

        if ($storage !== ConsentState::Granted) {
            return $next($request);
        }

        $state = $this->context->all();
        $visitorId = is_string($state['visitor_id'] ?? null)
            ? $state['visitor_id']
            : (string) Str::uuid();
        $now = now()->toIso8601String();
        $consent = (new Consent(
            storage: $storage,
            adUserData: $this->consent->adUserData($request),
            adPersonalization: $this->consent->adPersonalization($request),
        ))->toArray();

        $this->context->replace([
            ...$state,
            'visitor_id' => $visitorId,
            'first_click_id' => $state['first_click_id'] ?? $clickId,
            'last_click_id' => $clickId,
            'first_clicked_at' => $state['first_clicked_at'] ?? $now,
            'last_clicked_at' => $now,
            'promo_code' => is_string($request->query('lnk_promo')) ? $request->query('lnk_promo') : ($state['promo_code'] ?? null),
            'consent' => $consent,
        ]);

        $website = config('lnkflow.connections.'.config()->string('lnkflow.default', 'default').'.website');
        $queue = config('lnkflow.journeys.queue');

        CaptureTouchpointJob::dispatch(
            $visitorId,
            $clickId,
            is_numeric($website) ? (int) $website : null,
            $consent,
        )->onQueue(is_string($queue) ? $queue : null)->afterResponse();

        $response = $next($request);

        if (config('lnkflow.journeys.clean_url') === true && $request->method() === 'GET') {
            return $this->cleanRedirect($request);
        }

        return $response;
    }

    private function isAutomated(Request $request): bool
    {
        $purpose = mb_strtolower((string) ($request->header('Purpose') ?? $request->header('Sec-Purpose')));
        $agent = mb_strtolower((string) $request->userAgent());

        return str_contains($purpose, 'prefetch')
            || str_contains($purpose, 'preview')
            || preg_match('/bot|crawler|spider|preview/', $agent) === 1;
    }

    private function cleanRedirect(Request $request): RedirectResponse
    {
        $query = $request->query();
        unset($query['lnk_id'], $query['lnk_promo']);
        $url = $request->url();

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return redirect()->to($url);
    }
}
