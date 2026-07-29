<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\View\Components;

use Illuminate\View\Component;
use LnkFlow\Laravel\Data\Consent;
use LnkFlow\Laravel\Data\ConsentState;

/**
 * Emits the hosted LnkFlow browser snippet.
 *
 * Server-side journey capture (the CaptureJourneyContext middleware) records
 * the arrival. This snippet is the other half: it is what persists the click id
 * in the visitor's browser so a conversion that happens minutes or days later
 * can still be attributed, and what decorates checkout links.
 *
 * The snippet sets first-party cookies. That is a consent decision, and it is
 * the host application's to make:
 *
 *   - `storage="cookie"` (default) lets the snippet store on its own. Only
 *     correct where the host has already established a lawful basis.
 *   - `storage="manual"` stores nothing until the host's CMP calls
 *     `window.lnkflow.setConsent({ storage: true })`.
 *
 * Passing `:consent` renders that call for you from a server-resolved decision.
 * Do not use it on a page served from a shared cache — one visitor's consent
 * would be served to the next.
 */
final class Script extends Component
{
    public string $url;

    public ?string $siteKey;

    public string $storage;

    public string $attribution;

    public bool $stripe;

    public ?string $captureEndpoint;

    public ?int $cookieDays;

    /** @var array<string, bool>|null */
    public ?array $consentPayload;

    public function __construct(
        ?string $url = null,
        ?string $siteKey = null,
        ?string $storage = null,
        ?string $attribution = null,
        ?bool $stripe = null,
        ?string $captureEndpoint = null,
        ?int $cookieDays = null,
        Consent|bool|null $consent = null,
        public ?string $nonce = null,
    ) {
        $this->url = $url ?? $this->config('url', 'https://app.lnkflow.io/lnk.js');
        $this->siteKey = $siteKey ?? $this->nullableConfig('site_key');
        $this->storage = $this->oneOf($storage ?? $this->config('storage', 'cookie'), ['cookie', 'manual', 'none'], 'cookie');
        $this->attribution = $this->oneOf($attribution ?? $this->config('attribution', 'auto'), ['auto', 'manual', 'none'], 'auto');
        $this->stripe = $stripe ?? (bool) config('lnkflow.journeys.script.stripe', false);
        $this->captureEndpoint = $captureEndpoint ?? $this->nullableConfig('capture_endpoint');
        $days = $cookieDays ?? config('lnkflow.journeys.script.cookie_days');
        $this->cookieDays = is_numeric($days) ? (int) $days : null;
        $this->consentPayload = $this->consentPayload($consent);
    }

    public function render(): string
    {
        return 'lnkflow::components.script';
    }

    /**
     * `setConsent` takes booleans, so `unknown` is not expressible. Anything
     * that is not an explicit grant is therefore a denial — which is the
     * correct default for consent anyway.
     *
     * @return array<string, bool>|null
     */
    private function consentPayload(Consent|bool|null $consent): ?array
    {
        if ($consent === null) {
            return null;
        }

        if (is_bool($consent)) {
            return ['storage' => $consent, 'attribution' => $consent];
        }

        return [
            'storage' => $consent->storage === ConsentState::Granted,
            'attribution' => $consent->adUserData === ConsentState::Granted,
        ];
    }

    /** @param list<string> $allowed */
    private function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function config(string $key, string $default): string
    {
        $value = config("lnkflow.journeys.script.{$key}");

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function nullableConfig(string $key): ?string
    {
        $value = config("lnkflow.journeys.script.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
