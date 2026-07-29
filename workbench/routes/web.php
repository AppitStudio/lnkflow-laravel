<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use LnkFlow\Laravel\Data\CreateCampaign;
use LnkFlow\Laravel\Data\CreateLink;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Exceptions\LnkFlowException;
use LnkFlow\Laravel\Services\Client;

/*
|--------------------------------------------------------------------------
| LnkFlow SDK tutorial routes
|--------------------------------------------------------------------------
|
| The runnable half of docs/tutorial.md. `composer serve` boots this app; the
| routes below walk from "is my token working" to a live short URL and a
| verified test conversion.
|
| Two of these routes write to a real LnkFlow team. Point the workbench at a
| team you are happy to create throwaway records in — never production.
|
*/

/**
 * Render a result as JSON, and a LnkFlow failure as the shape a host
 * application should log: status, error code, field errors, request id. Never
 * the token, never the request body.
 */
$respond = function (callable $callback): JsonResponse {
    $options = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    try {
        return response()->json($callback(), 200, [], $options);
    } catch (LnkFlowException $exception) {
        return response()->json([
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'status' => $exception->status,
            'error_code' => $exception->errorCode,
            'errors' => $exception->errors,
            'request_id' => $exception->requestId,
        ], $exception->status ?? 503, [], $options);
    }
};

Route::get('/', function (): string {
    $connection = (string) config('lnkflow.default', 'default');
    $settings = config("lnkflow.connections.{$connection}", []);
    $settings = is_array($settings) ? $settings : [];
    $state = static fn (string $key): string => filled($settings[$key] ?? null) ? 'set' : 'MISSING';

    $url = e((string) ($settings['url'] ?? ''));
    $team = e((string) ($settings['team'] ?? ''));
    $website = e((string) ($settings['website'] ?? ''));
    $apiToken = $state('api_token');
    $linkToken = $state('link_token');
    $conversionToken = $state('conversion_token');

    return <<<HTML
        <!doctype html>
        <html lang="en">
        <head><meta charset="utf-8"><title>LnkFlow SDK workbench</title></head>
        <body style="font-family: system-ui, sans-serif; max-width: 46rem; margin: 3rem auto; line-height: 1.6">
            <h1>LnkFlow SDK workbench</h1>
            <p>The runnable half of <code>docs/tutorial.md</code>. Two of these routes write to a real team.</p>
            <h2>Configuration</h2>
            <ul>
                <li>connection: <code>{$connection}</code></li>
                <li>url: <code>{$url}</code></li>
                <li>team: <code>{$team}</code> (must be the numeric team id)</li>
                <li>website: <code>{$website}</code></li>
                <li>api token: <code>{$apiToken}</code></li>
                <li>link token: <code>{$linkToken}</code></li>
                <li>conversion token: <code>{$conversionToken}</code></li>
            </ul>
            <h2>Steps</h2>
            <ol>
                <li><a href="/lnkflow/whoami">/lnkflow/whoami</a> &mdash; read only; proves the token and lists team ids</li>
                <li><a href="/lnkflow/preview">/lnkflow/preview</a> &mdash; needs write ability, creates nothing</li>
                <li><a href="/lnkflow/create">/lnkflow/create</a> &mdash; <strong>writes</strong> a campaign and a link</li>
                <li><a href="/lnkflow/landing">/lnkflow/landing</a> &mdash; the destination, with the browser snippet</li>
                <li><a href="/lnkflow/track">/lnkflow/track</a> &mdash; <strong>writes</strong> a test sale</li>
                <li><a href="/lnkflow/events">/lnkflow/events</a> &mdash; reads the test events back</li>
            </ol>
        </body>
        </html>
        HTML;
});

/**
 * Step 1 — read only. Also the answer to "which numeric team id do I use?".
 */
Route::get('lnkflow/whoami', function (Client $client) use ($respond): JsonResponse {
    return $respond(function () use ($client): array {
        $me = $client->identity()->me();
        $connection = (string) config('lnkflow.default', 'default');

        return [
            'user_id' => $me->id,
            'capabilities' => $me->capabilities,
            'teams' => $me->raw['teams'] ?? [],
            'configured_team' => config("lnkflow.connections.{$connection}.team"),
        ];
    });
});

/**
 * Step 2 — side-effect free, but it still needs a write-ability token, because
 * `POST /links/preview` previews a write intent.
 */
Route::get('lnkflow/preview', function (Request $request, Client $client) use ($respond): JsonResponse {
    return $respond(function () use ($request, $client): array {
        $preview = $client->links()->preview(
            new CreateLink(
                destinationUrl: (string) $request->query('destination', $request->getSchemeAndHttpHost().'/lnkflow/landing'),
                name: 'Workbench tutorial link',
                slug: (string) $request->query('slug', 'workbench-tutorial'),
                utm: ['utm_source' => 'workbench'],
                conversionTrackingEnabled: true,
            ),
            campaignName: (string) $request->query('campaign', 'Workbench tutorial'),
        );

        return $preview->raw;
    });
});

/**
 * Step 3 — the only creating route. The idempotency keys are constants, so
 * reloading this page replays the first response instead of creating a second
 * campaign: `replayed` flips to true. Pass ?run=v2 to start a fresh pair.
 */
Route::get('lnkflow/create', function (Request $request, Client $client) use ($respond): JsonResponse {
    return $respond(function () use ($request, $client): array {
        $run = (string) $request->query('run', 'v1');

        $campaign = $client->campaigns()->create(
            new CreateCampaign((string) $request->query('campaign', 'Workbench tutorial')),
            idempotencyKey: "workbench:tutorial:{$run}:campaign",
        );

        $link = $client->links()->create(
            $campaign->id,
            new CreateLink(
                destinationUrl: (string) $request->query('destination', $request->getSchemeAndHttpHost().'/lnkflow/landing'),
                name: 'Workbench tutorial link',
                slug: (string) $request->query('slug', 'workbench-tutorial'),
                utm: ['utm_source' => 'workbench'],
                conversionTrackingEnabled: true,
            ),
            idempotencyKey: "workbench:tutorial:{$run}:link",
        );

        return [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'slug' => $campaign->slug,
                'primary_link_slug' => $campaign->primaryLinkSlug,
                'replayed' => $campaign->replayed(),
            ],
            'link' => [
                'id' => $link->id,
                'slug' => $link->slug,
                'short_url' => $link->shortUrl,
                'destination_url_with_utm' => $link->destinationUrlWithUtm,
                'conversion_tracking_enabled' => $link->conversionTrackingEnabled,
                'edge_status' => $link->edgeStatus,
                'published' => $link->published(),
                'replayed' => $link->replayed(),
                'request_id' => $link->requestId(),
            ],
            'next' => 'Open short_url in a browser. It should land on /lnkflow/landing?lnk_id=...',
        ];
    });
});

/**
 * Step 4 — the destination. The browser snippet captures `lnk_id` from the URL;
 * `storage="manual"` means nothing is written to document.cookie until a
 * consent decision arrives, which is what the buttons below simulate.
 */
Route::get('lnkflow/landing', function (): string {
    return Blade::render(<<<'BLADE'
        <!doctype html>
        <html lang="en">
        <head><meta charset="utf-8"><title>LnkFlow tutorial landing</title></head>
        <body style="font-family: system-ui, sans-serif; max-width: 46rem; margin: 3rem auto; line-height: 1.6">
            <h1>Landing page</h1>
            <p>Click id in memory: <code id="click-id">reading&hellip;</code></p>
            <p>Promo code: <code id="promo-code">&mdash;</code></p>
            <p>
                <button id="grant" type="button">Grant storage consent</button>
                <button id="revoke" type="button">Revoke</button>
            </p>
            <p><a id="track" href="/lnkflow/track">Report a test sale for this click &rarr;</a></p>

            <x-lnkflow-script storage="manual" attribution="manual" />

            <script>
                function refresh() {
                    var click = window.lnkflow ? window.lnkflow.getClickId() : null;

                    document.getElementById('click-id').textContent =
                        click || 'none - open a tracked short URL';
                    document.getElementById('promo-code').textContent =
                        (window.lnkflow && window.lnkflow.getPromoCode()) || '-';

                    if (click) {
                        document.getElementById('track').href =
                            '/lnkflow/track?click_id=' + encodeURIComponent(click);
                    }
                }

                document.getElementById('grant').addEventListener('click', function () {
                    window.lnkflow.setConsent({ storage: true, attribution: true });
                    refresh();
                });

                document.getElementById('revoke').addEventListener('click', function () {
                    window.lnkflow.revokeConsent();
                    refresh();
                });

                setTimeout(refresh, 300);
            </script>
        </body>
        </html>
        BLADE);
});

/**
 * Step 5 — reports a clearly labelled TEST sale, synchronously so the result is
 * visible immediately. Production code uses LnkFlow::trackSale(), which queues
 * after commit so a reporting failure can never fail a checkout.
 */
Route::get('lnkflow/track', function (Request $request, Client $client) use ($respond): JsonResponse {
    return $respond(function () use ($request, $client): array {
        $clickId = $request->query('click_id');
        $invoiceId = (string) $request->query('invoice', 'workbench-'.now()->format('Ymd-His'));

        $sale = $client->conversions()->sale(new Sale(
            invoiceId: $invoiceId,
            amount: 1299,
            currency: 'usd',
            customerExternalId: 'workbench-customer',
            clickId: is_string($clickId) && $clickId !== '' ? $clickId : null,
            paymentProcessor: 'workbench',
            test: true,
        ));

        return [
            'id' => $sale->id,
            'type' => $sale->type,
            'amount_cents' => $sale->amountCents,
            'currency' => $sale->currency,
            'attribution_source' => $sale->attributionSource,
            'is_test' => $sale->test,
            'link_id' => $sale->linkId,
            'campaign_id' => $sale->campaignId,
            'duplicate' => $sale->raw['duplicate'] ?? false,
            'invoice_id' => $invoiceId,
            'next' => '/lnkflow/events',
        ];
    });
});

/**
 * Step 6 — the verification loop. `GET /track/events` needs no special ability.
 */
Route::get('lnkflow/events', function (Client $client) use ($respond): JsonResponse {
    return $respond(fn (): array => array_map(
        static fn ($event): array => [
            'id' => $event->id,
            'type' => $event->type,
            'event_name' => $event->eventName,
            'amount_cents' => $event->amountCents,
            'currency' => $event->currency,
            'attribution_source' => $event->attributionSource,
            'is_test' => $event->test,
            'occurred_at' => $event->occurredAt,
            'link_id' => $event->linkId,
        ],
        $client->conversions()->events(['test' => true, 'limit' => 20]),
    ));
});
