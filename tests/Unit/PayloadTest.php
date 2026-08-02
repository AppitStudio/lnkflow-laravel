<?php

declare(strict_types=1);

use LnkFlow\Laravel\Data\Consent;
use LnkFlow\Laravel\Data\ConsentState;
use LnkFlow\Laravel\Data\CreateCampaign;
use LnkFlow\Laravel\Data\CreateInfluencer;
use LnkFlow\Laravel\Data\CreateWebsite;
use LnkFlow\Laravel\Data\EnrichedPayload;
use LnkFlow\Laravel\Data\Lead;
use LnkFlow\Laravel\Data\NamedEvent;
use LnkFlow\Laravel\Data\PermissionBasis;
use LnkFlow\Laravel\Data\Refund;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Data\SocialPlatform;
use LnkFlow\Laravel\Data\Touchpoint;
use LnkFlow\Laravel\Data\UpdateInfluencer;
use LnkFlow\Laravel\Data\UpdateLink;
use LnkFlow\Laravel\Data\UpdateWebsite;

/*
 * Refund semantics
 *
 * The API takes no currency on a refund — the original sale owns it — and a
 * null amount means "reverse the whole sale", which is not the same request as
 * "reverse zero".
 */

it('omits the amount for a full refund so the API reverses the original sale', function (): void {
    $payload = (new Refund('invoice_42'))->toArray();

    expect($payload)->not->toHaveKey('amount')
        ->and($payload)->not->toHaveKey('currency')
        ->and($payload['original_invoice_id'])->toBe('invoice_42');
});

it('sends the amount for a partial refund', function (): void {
    expect((new Refund('invoice_42', 'refund_1', 500))->toArray())
        ->toBe([
            'original_invoice_id' => 'invoice_42',
            'refund_id' => 'refund_1',
            'amount' => 500,
        ]);
});

it('derives a stable business id for the idempotent full-refund case', function (): void {
    $refund = new Refund('invoice_42');

    // Retrying a full refund must be a duplicate, not a second clawback, so
    // the identifier has to be derivable from the invoice alone.
    expect($refund->businessId())->toBe('invoice_42:refund')
        ->and((new Refund('invoice_42'))->businessId())->toBe($refund->businessId())
        ->and($refund->toArray())->not->toHaveKey('refund_id');
});

it('uses the explicit refund id as the business id when there is one', function (): void {
    expect((new Refund('invoice_42', 'refund_1'))->businessId())->toBe('refund_1');
});

it('refuses a refund id equal to the invoice id', function (): void {
    // Conversions share one reference space, so reusing the invoice id would
    // collide the refund with the sale it reverses.
    expect(fn () => new Refund('invoice_42', 'invoice_42'))
        ->toThrow(InvalidArgumentException::class, 'Refund id must differ from the original invoice id');
});

it('refuses a non-positive partial refund amount', function (): void {
    expect(fn () => new Refund('invoice_42', 'refund_1', 0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Refund('invoice_42', 'refund_1', -100))
        ->toThrow(InvalidArgumentException::class);
});

/* Monetary payloads */

it('normalises a currency to lowercase ISO 4217', function (): void {
    expect((new Sale('invoice_1', 100, 'EUR'))->toArray()['currency'])->toBe('eur');
});

it('accepts a zero-amount sale but never a negative one', function (): void {
    expect((new Sale('invoice_1', 0, 'usd'))->toArray()['amount'])->toBe(0)
        ->and(fn () => new Sale('invoice_1', -1, 'usd'))->toThrow(InvalidArgumentException::class);
});

it('only sends a Meta deduplication id when one was given', function (): void {
    expect((new Sale('invoice_1', 100, 'usd'))->toArray())->not->toHaveKey('provider_event_ids')
        ->and((new Sale('invoice_1', 100, 'usd', metaEventId: 'evt.browser.1'))->toArray()['provider_event_ids'])
        ->toBe(['meta' => 'evt.browser.1']);
});

it('leaves customer email and name out of a lead unless explicitly set', function (): void {
    $payload = (new Lead('customer_7'))->toArray();

    expect($payload)->toBe(['customer_external_id' => 'customer_7', 'event_name' => 'lead'])
        ->and((new Lead('customer_7', customerEmail: 'a@example.test'))->toArray()['customer_email'])
        ->toBe('a@example.test');
});

it('reports a named event through the lead shape', function (): void {
    expect((new NamedEvent('trial_started', 'customer_7'))->toArray())
        ->toBe(['customer_external_id' => 'customer_7', 'event_name' => 'trial_started']);
});

it('formats timestamps as immutable ISO-8601', function (): void {
    $at = new DateTimeImmutable('2026-01-15T12:00:00+00:00');

    expect((new Sale('invoice_1', 100, 'usd', occurredAt: $at))->toArray()['occurred_at'])
        ->toBe('2026-01-15T12:00:00+00:00');
});

/* Consent */

it('defaults every consent signal to unknown, which the platform treats as no', function (): void {
    $consent = new Consent;

    expect($consent->granted())->toBeFalse()
        ->and($consent->toArray())->toBe([
            'storage' => 'unknown',
            'ad_user_data' => 'unknown',
            'ad_personalization' => 'unknown',
        ]);
});

it('round-trips consent through its array form', function (): void {
    $consent = Consent::fromArray([
        'storage' => 'granted',
        'ad_user_data' => 'denied',
        'ad_personalization' => 'not-a-state',
        'revision' => 3,
        'evidence_id' => 'cmp-42',
        'permission_basis' => 'regional_default',
        'policy_revision' => 7,
    ]);

    expect($consent->storage)->toBe(ConsentState::Granted)
        ->and($consent->adUserData)->toBe(ConsentState::Denied)
        // An unrecognised value degrades to unknown, never to granted.
        ->and($consent->adPersonalization)->toBe(ConsentState::Unknown)
        ->and($consent->granted())->toBeTrue()
        ->and($consent->toArray()['revision'])->toBe(3)
        ->and($consent->toArray()['evidence_id'])->toBe('cmp-42')
        ->and($consent->permissionBasis)->toBe(PermissionBasis::RegionalDefault)
        ->and($consent->policyRevision)->toBe(7);
});

it('does not treat a denied permission basis as granted processing', function (): void {
    $consent = new Consent(
        storage: ConsentState::Granted,
        permissionBasis: PermissionBasis::Denied,
    );

    expect($consent->granted())->toBeFalse();
});

it('always attaches consent to a touchpoint', function (): void {
    $payload = (new Touchpoint('visitor-1', 'click-1', new Consent(ConsentState::Granted), websiteId: 10))->toArray();

    expect($payload['consent']['storage'])->toBe('granted')
        ->and($payload['visitor_id'])->toBe('visitor-1')
        ->and($payload['click_id'])->toBe('click-1')
        ->and($payload['capture_method'])->toBe('backend');
});

/* Journey context precedence */

it('lets an explicit identifier win over the session and the escape hatch', function (): void {
    $payload = new EnrichedPayload(
        new Sale('invoice_1', 100, 'usd', clickId: 'explicit-click', context: ['click_id' => 'escape-hatch-click']),
        ['click_id' => 'session-click', 'visitor_id' => 'session-visitor'],
    );

    // journey context < the payload's own context < typed properties.
    expect($payload->toArray()['click_id'])->toBe('explicit-click')
        ->and($payload->toArray()['visitor_id'])->toBe('session-visitor');
});

it('falls back to the journey context for anything not set explicitly', function (): void {
    $payload = new EnrichedPayload(new Sale('invoice_1', 100, 'usd'), ['visitor_id' => 'session-visitor']);

    expect($payload->toArray()['visitor_id'])->toBe('session-visitor')
        ->and($payload->inner())->toBeInstanceOf(Sale::class);
});

/* Update guards */

it('rejects a field the API does not accept on an update', function (string $class, array $changes, string $message): void {
    expect(fn () => new $class($changes))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'link' => [UpdateLink::class, ['edge_status' => 'published'], 'Unsupported link update field(s) [edge_status]'],
    'website' => [UpdateWebsite::class, ['url_prefix' => 'x'], 'Unsupported website update field(s) [url_prefix]'],
    'influencer' => [UpdateInfluencer::class, ['payout_iban' => 'x'], 'Unsupported influencer update field(s) [payout_iban]'],
]);

it('accepts UTM keys on a link update', function (): void {
    expect((new UpdateLink(['utm_source' => 'newsletter', 'is_active' => true]))->toArray())
        ->toBe(['utm_source' => 'newsletter', 'is_active' => true]);
});

it('serializes an influencer platform enum on update too', function (): void {
    expect((new UpdateInfluencer(['primary_platform' => SocialPlatform::YouTube]))->toArray())
        ->toBe(['primary_platform' => 'youtube']);
});

/* Create payloads omit what was not set */

it('omits every unset field from a create payload', function (): void {
    expect((new CreateCampaign('Release'))->toArray())->toBe(['name' => 'Release'])
        ->and((new CreateWebsite('Docs'))->toArray())->toBe(['name' => 'Docs'])
        ->and((new CreateInfluencer('Partner'))->toArray())->toBe(['name' => 'Partner']);
});

it('sends a campaign slug as campaign_slug, never as the link slug', function (): void {
    expect((new CreateCampaign('Release', slug: 'release-2026'))->toArray())
        ->toBe(['name' => 'Release', 'campaign_slug' => 'release-2026']);
});

it('keeps a creator website separate from their social profiles', function (): void {
    $payload = (new CreateInfluencer(
        'Nova Rivers',
        websiteUrl: 'https://novarivers.example',
        socialLinks: ['youtube' => 'https://youtube.example/novarivers'],
    ))->toArray();

    expect($payload['website_url'])->toBe('https://novarivers.example')
        ->and($payload['social_links'])->toBe(['youtube' => 'https://youtube.example/novarivers']);
});
