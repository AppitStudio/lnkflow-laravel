<?php

declare(strict_types=1);

use LnkFlow\Laravel\Data\Consent;
use LnkFlow\Laravel\Data\ConsentState;

/*
 * The snippet sets first-party cookies on the host's own site, so what it is
 * told to do is a consent decision. The component's job is to render exactly
 * the host's decision and never to invent one.
 */

beforeEach(function (): void {
    config()->set('lnkflow.journeys.script', [
        'url' => 'https://app.lnkflow.test/lnk.js',
        'site_key' => null,
        'storage' => 'cookie',
        'attribution' => 'auto',
        'stripe' => false,
        'capture_endpoint' => null,
        'cookie_days' => 90,
    ]);
});

it('renders the configured snippet with its defaults', function (): void {
    $this->blade('<x-lnkflow-script />')
        ->assertSee('src="https://app.lnkflow.test/lnk.js"', false)
        ->assertSee('data-storage="cookie"', false)
        ->assertSee('data-attribution="auto"', false)
        ->assertSee('data-cookie-days="90"', false)
        ->assertSee('defer', false)
        ->assertDontSee('data-site-key', false)
        ->assertDontSee('data-capture-endpoint', false)
        ->assertDontSee('data-stripe', false)
        ->assertDontSee('setConsent', false);
});

it('omits the site key entirely when none is configured', function (): void {
    $rendered = $this->blade('<x-lnkflow-script />')->__toString();

    // An empty `data-site-key=""` would look configured and silently do
    // nothing, so the attribute must be absent rather than blank.
    expect($rendered)->not->toContain('data-site-key');
});

it('renders the site key and capture endpoint from configuration', function (): void {
    config()->set('lnkflow.journeys.script.site_key', 'site_abc123');
    config()->set('lnkflow.journeys.script.capture_endpoint', 'https://app.lnkflow.test/api/v1/journeys/touchpoints');

    $this->blade('<x-lnkflow-script />')
        ->assertSee('data-site-key="site_abc123"', false)
        ->assertSee('data-capture-endpoint="https://app.lnkflow.test/api/v1/journeys/touchpoints"', false);
});

it('lets props override configuration', function (): void {
    config()->set('lnkflow.journeys.script.site_key', 'site_from_config');

    $this->blade('<x-lnkflow-script url="https://cdn.example/lnk.js" site-key="site_from_prop" storage="manual" attribution="none" :cookie-days="30" />')
        ->assertSee('src="https://cdn.example/lnk.js"', false)
        ->assertSee('data-site-key="site_from_prop"', false)
        ->assertSee('data-storage="manual"', false)
        ->assertSee('data-attribution="none"', false)
        ->assertSee('data-cookie-days="30"', false);
});

it('falls back to a safe value for an unrecognised storage or attribution mode', function (): void {
    $this->blade('<x-lnkflow-script storage="whatever" attribution="whatever" />')
        ->assertSee('data-storage="cookie"', false)
        ->assertSee('data-attribution="auto"', false);
});

it('opts into Stripe anchor tagging only when asked', function (): void {
    $this->blade('<x-lnkflow-script />')->assertDontSee('data-stripe', false);
    $this->blade('<x-lnkflow-script :stripe="true" />')->assertSee('data-stripe="auto"', false);

    config()->set('lnkflow.journeys.script.stripe', true);
    $this->blade('<x-lnkflow-script />')->assertSee('data-stripe="auto"', false);
});

it('renders no consent bootstrap unless consent is passed', function (): void {
    $rendered = $this->blade('<x-lnkflow-script />')->__toString();

    expect($rendered)->not->toContain('window.lnkflow.setConsent');
});

it('renders a consent bootstrap from a resolved Consent object', function (): void {
    $consent = new Consent(
        storage: ConsentState::Granted,
        adUserData: ConsentState::Granted,
        adPersonalization: ConsentState::Denied,
    );

    $this->blade('<x-lnkflow-script :consent="$consent" />', ['consent' => $consent])
        ->assertSee('window.lnkflow.setConsent', false)
        ->assertSee('{"storage":true', false)
        ->assertSee('"attribution":true', false);
});

it('treats anything short of an explicit grant as a denial', function (): void {
    $consent = new Consent(storage: ConsentState::Unknown, adUserData: ConsentState::Denied);

    // `setConsent` takes booleans, so `unknown` is not expressible — and the
    // correct default for consent is no.
    $this->blade('<x-lnkflow-script :consent="$consent" />', ['consent' => $consent])
        ->assertSee('{"storage":false', false)
        ->assertSee('"attribution":false', false);
});

it('accepts a plain boolean consent decision', function (): void {
    $this->blade('<x-lnkflow-script :consent="true" />')
        ->assertSee('{"storage":true', false);

    $this->blade('<x-lnkflow-script :consent="false" />')
        ->assertSee('{"storage":false', false);
});

it('applies a CSP nonce to both script tags', function (): void {
    $rendered = $this->blade(
        '<x-lnkflow-script nonce="n0nce" :consent="true" />',
    )->__toString();

    expect(substr_count($rendered, 'nonce="n0nce"'))->toBe(2);
});

it('never renders a visitor or click identifier into the page', function (): void {
    session()->put('_lnkflow', [
        'visitor_id' => '10000000-0000-4000-8000-000000000001',
        'last_click_id' => '30000000-0000-4000-8000-000000000001',
    ]);
    config()->set('lnkflow.journeys.script.site_key', 'site_abc123');

    $rendered = $this->blade('<x-lnkflow-script :consent="true" />')->__toString();

    // Rendering either into HTML would leak one visitor's identity to the next
    // through any shared cache.
    expect($rendered)->not->toContain('10000000-0000-4000-8000-000000000001')
        ->and($rendered)->not->toContain('30000000-0000-4000-8000-000000000001');
});

it('publishes its view so a host can override the markup', function (): void {
    expect(view()->exists('lnkflow::components.script'))->toBeTrue();
});
