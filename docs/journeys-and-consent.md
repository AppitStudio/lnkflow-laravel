# Journeys, consent, and identity

A journey is the chain from a tracked click to a conversion. Capturing it
server-side survives ad blockers; capturing it at all requires consent.

## Consent first

The default resolver returns `unknown` for every signal, and `unknown` means **no
session state and no capture**. Nothing is stored and nothing is sent until the
host binds its own resolver:

```php
use LnkFlow\Laravel\Contracts\ConsentResolver;

$this->app->bind(ConsentResolver::class, AppConsentResolver::class);
```

```php
use Illuminate\Http\Request;
use LnkFlow\Laravel\Contracts\ConsentResolver;
use LnkFlow\Laravel\Data\ConsentState;

final class AppConsentResolver implements ConsentResolver
{
    public function storage(Request $request): ConsentState
    {
        return $this->cmp($request)->analytics
            ? ConsentState::Granted
            : ConsentState::Denied;
    }

    public function adUserData(Request $request): ConsentState { /* ... */ }

    public function adPersonalization(Request $request): ConsentState { /* ... */ }
}
```

Return `storage`, `ad_user_data`, and `ad_personalization` independently — they
are three decisions, not one. Global Privacy Control is an input to that
decision, not a universal answer, and LnkFlow does not replace your CMP or your
legal assessment. Do not grant storage by default.

`lnkflow:doctor` checks that a non-default resolver is bound whenever
`features.journeys` is on.

## Capture

`CaptureJourneyContext` is **not** registered for you. Add it by hand to the web
middleware group (or to selected routes), after the session middleware:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        LnkFlow\Laravel\Http\Middleware\CaptureJourneyContext::class,
    ]);
})
```

There is no config key for this. An earlier `journeys.middleware` setting was
removed: it read like the package would wire the middleware up, and it never
did.

The middleware only acts when **all** of these hold:

- the request is GET or HEAD;
- it is not a prefetch/preview (`Purpose`/`Sec-Purpose`) and not an obvious bot
  user agent;
- the request has a session;
- `lnk_id` is present in the query **and is a valid UUID**;
- `ConsentResolver::storage()` returns `Granted`.

Then it stores opaque first/last click context (plus `lnk_promo`, if present) in
the session and queues `CaptureTouchpointJob` **after the response**. It never
performs network I/O during navigation.

Set `journeys.clean_url` to strip `lnk_id` and `lnk_promo` from the address bar
with a redirect to the same path. It removes only those two parameters.

The session key is `journeys.session_key` (`_lnkflow` by default), and the queue
is `journeys.queue`.

## Identity

With `features.auth_identity` on, `AuthIdentitySubscriber` listens to Laravel's
own auth events:

| Event | Queued call | Meaning |
|---|---|---|
| `Login`, `Registered` | `POST /journeys/identify` | bind this browser to a stable opaque customer id |
| `Logout` | `POST /journeys/unidentify` | close the active binding |

Nothing happens when there is no visitor id in the session — identity without a
captured journey has nothing to bind.

The customer id comes from `CustomerExternalIdResolver`. The default produces
`{app-namespace}:{model key}`; bind your own if you need something else. Make it
opaque and stable. Do not use an email address.

**Unidentify is not revocation.** It closes the active browser-to-customer
interval and nothing more; history is preserved, and on a shared browser a later
login simply starts a new interval. Withdrawal of consent is a different
operation:

```php
app(LnkFlow\Laravel\Services\ConsentRevocationService::class)->revoke();
```

That clears the local journey state immediately and separately queues
`POST /journeys/revoke`, which revokes the visitor, closes active bindings, and
anonymizes unreferenced identifiers while preserving frozen financial facts. It
returns `false` when there was no visitor to revoke.

Never call `revoke` for a logout.

## Reporting with journey context

`ConversionDispatcher` enriches queued conversions from the session
automatically — `visitor_id`, `first_click_id`, `click_id`, `last_click_id`,
`promo_code`, `consent`, and the configured `website_id`. Precedence is journey
context < the payload's own `$context` < the payload's typed properties, so an
explicit value always wins. Refunds are not enriched.

See [Conversions](conversions.md).

## Calling the journey endpoints directly

```php
use LnkFlow\Laravel\Data\Consent;
use LnkFlow\Laravel\Data\ConsentState;
use LnkFlow\Laravel\Data\IdentityChange;
use LnkFlow\Laravel\Data\Touchpoint;
use LnkFlow\Laravel\Data\Visitor;

$client->journeys()->capture(new Touchpoint(
    visitorId: $visitorId,
    clickId: $clickId,
    consent: new Consent(
        storage: ConsentState::Granted,
        adUserData: ConsentState::Granted,
        adPersonalization: ConsentState::Denied,
        revision: 3,
    ),
    websiteId: 12,
));

$client->journeys()->identify(new IdentityChange($visitorId, 'customer_opaque_42'));
$client->journeys()->unidentify(new Visitor($visitorId));
$client->journeys()->revoke(new Visitor($visitorId));
```

`Touchpoint::$consent` is a `Consent` object, not an array — the states are an
enum precisely so `"granted "` or `true` cannot slip through. `Consent::fromArray()`
rebuilds one from stored session state, mapping anything unrecognised to
`Unknown`.

`capturedAt` must not be in the future and must fall inside the server's
tolerance window (seven days by default).

Capture status is deliberately coarse: 200 means an idempotent duplicate, 201
that the click resolved in scope, 202 that it was accepted but not attributed
yet. 202 covers pending, ineligible, refused, and out-of-scope alike, so a click
UUID cannot be used as a cross-tenant ownership oracle. Do not branch business
logic on it — the conversion journey read is the authoritative result.

## Production requirements

- Secure, HttpOnly, SameSite-appropriate sessions.
- A queue worker sharing the application database and cache.
- **Never render a visitor or click identifier into cacheable HTML**, and never
  put journey capture behind a shared full-page cache.
- Journey jobs carry only the opaque visitor and click identifiers the operation
  needs. Keep it that way if you extend them.
- The money path fails open while journey state fails closed: a capture or
  identity error must never reject an otherwise valid sale, and uncertain
  journey state is never guessed.

## Related

- [Browser bridge](browser-script.md) — the client-side half, its cookies, and
  wiring a CMP to `window.lnkflow.setConsent()`.
- [Conversions](conversions.md) — what the captured context is for.
