# Browser bridge — `<x-lnkflow-script />`

Server-side capture (the `CaptureJourneyContext` middleware) records that a
visitor arrived from a tracked click. The hosted browser snippet is the other
half: it is what keeps the click id in the visitor's browser so a conversion
minutes or days later can still be attributed, and what decorates checkout
links.

```blade
<x-lnkflow-script />
```

The component renders the `<script defer>` tag for the hosted snippet, and
nothing else. It is registered by the service provider as `lnkflow-script`; no
alias or import is needed. The Blade view is namespaced `lnkflow::`, and can be
published with `php artisan vendor:publish --tag=lnkflow-views` if you need to
change the markup.

**The snippet sets first-party cookies.** `lnk_id`, `lnk_vid`, `lnk_first`,
`lnk_last`, and `lnk_promo`, for `cookie_days` days (90 by default), on your
domain. That is a consent decision and it is yours, not LnkFlow's: this package
gives you the switches, and your site still owes its own cookie/ePrivacy notice
and CMP entry. Do not describe LnkFlow as cookie-less because redirects set no
cookies — this snippet does.

## Props

| Prop | Type | Default | Effect |
|---|---|---|---|
| `url` | `string` | `lnkflow.journeys.script.url` → `https://app.lnkflow.io/lnk.js` | the `src` |
| `site-key` | `?string` | `lnkflow.journeys.script.site_key` | `data-site-key`; omitted when null |
| `storage` | `string` | `lnkflow.journeys.script.storage` → `cookie` | `data-storage`; one of `cookie`, `manual`, `none` |
| `attribution` | `string` | `lnkflow.journeys.script.attribution` → `auto` | `data-attribution`; one of `auto`, `manual`, `none` |
| `stripe` | `bool` | `lnkflow.journeys.script.stripe` → `false` | renders `data-stripe="auto"` when true |
| `capture-endpoint` | `?string` | `lnkflow.journeys.script.capture_endpoint` | `data-capture-endpoint`; omitted when null |
| `cookie-days` | `?int` | `lnkflow.journeys.script.cookie_days` → `90` | `data-cookie-days`; omitted when the config value is non-numeric |
| `consent` | `Consent\|bool\|null` | `null` | renders a `window.lnkflow.setConsent()` bootstrap; see below |
| `nonce` | `?string` | `null` | CSP nonce, applied to both emitted script tags |

An unrecognised `storage` or `attribution` value falls back to the safe default
rather than being passed through, so a typo cannot silently disable the mode you
asked for.

The tag renders whether or not a site key is set. The site key is only needed
for **browser-side** journey capture; without it the snippet still captures
`lnk_id`/`lnk_promo` and decorates checkout links.

```blade
{{-- Consent-managed: store nothing and decorate nothing until the CMP says so --}}
<x-lnkflow-script storage="manual" attribution="manual" :stripe="true" />

{{-- Never touch document.cookie at all --}}
<x-lnkflow-script storage="none" />

{{-- With a CSP nonce --}}
<x-lnkflow-script :nonce="$cspNonce" />
```

## The three storage modes

| `storage` | Behaviour | `setConsent({storage: true})` |
|---|---|---|
| `cookie` | The snippet captures and persists immediately on page load. Only correct where you have already established a lawful basis before the page renders. | no-op, already storing |
| `manual` | Captures the values into memory, stores nothing. Same-page attribution works; cross-page and return-visit attribution does not, until consent arrives. | starts storing, back-fills cookies from memory, and sends the journey capture |
| `none` | The snippet never reads or writes `document.cookie`. Memory-only, landing page only. | **ignored** — `none` is a hard opt-out, not a default |

`attribution` is independent of `storage` and controls checkout-URL decoration
only:

| `attribution` | Behaviour | `setConsent({attribution: true})` |
|---|---|---|
| `auto` | `buy.stripe.com` anchors are decorated on load, if `stripe` is on | no-op |
| `manual` | No decoration until consent | decorates, and re-decorates anchors added later |
| `none` | Never decorates | **ignored** |

Decoration requires `stripe` to be true as well: it adds
`client_reference_id=lnk_<id>` and, when a promo code was captured,
`prefilled_promo_code=<code>`. Without it, a hardcoded Stripe Payment Link
carries no click id and the sale arrives unattributed.

For a fully consent-gated integration set **both** to `manual`. Setting only
`storage="manual"` leaves `attribution` at its `auto` default, and the URL is
still decorated.

## The runtime API

The snippet exposes these on `window.lnkflow` once it has loaded:

| Function | Returns |
|---|---|
| `getClickId()` | the last click id, or `null` |
| `getPromoCode()` | the captured promo code, or `null` |
| `getVisitorId()` | the pseudonymous visitor id, or `null` |
| `getFirstClickId()` / `getLastClickId()` | journey endpoints |
| `getJourneyContext()` | `{visitor_id, first_click_id, last_click_id}` |
| `setConsent({storage, attribution})` | applies a consent decision; each key is optional and independently applied; only booleans are honoured |
| `revokeConsent()` | deletes the LnkFlow cookies, clears memory, stops further capture, and restores every decorated Stripe URL |

Send whichever of those values your backend needs on your own checkout or signup
request, then report the conversion server-side. Never render a visitor id or
click id into cacheable HTML.

## Wiring a CMP

The reliable pattern is: render the snippet in `manual`/`manual`, and let the
CMP's own callback push the decision in. That keeps the page itself
consent-neutral and cacheable.

```blade
{{-- layouts/app.blade.php --}}
<x-lnkflow-script storage="manual" attribution="manual" :stripe="true" />

<script>
    // One adapter, called from whichever CMP the site uses.
    window.applyLnkFlowConsent = function (storage, attribution) {
        if (window.lnkflow) {
            window.lnkflow.setConsent({ storage: storage, attribution: attribution });
        }
    };

    // Example: Cookiebot. Register for both documented consent events so a
    // later change of mind is honoured, not just the first answer.
    window.addEventListener('CookiebotOnConsentReady', function () {
        window.applyLnkFlowConsent(Cookiebot.consent.statistics, Cookiebot.consent.marketing);
    });
    window.addEventListener('CookiebotOnConsentChanged', function () {
        window.applyLnkFlowConsent(Cookiebot.consent.statistics, Cookiebot.consent.marketing);
    });
</script>
```

The same shape works for any vendor — CookieYes' consent-update callback,
OneTrust's `OptanonWrapper` with your configured group ids, or a custom CMP.
LnkFlow consumes the explicit decision; mapping your categories onto `storage`
and `attribution` is a policy choice only you can make.

Withdrawal must reach both halves:

```js
// On the CMP's "reject"/"withdraw" path.
window.lnkflow.revokeConsent();   // browser: delete cookies, stop capture
fetch('/privacy/consent', { method: 'DELETE', headers: { 'X-CSRF-TOKEN': token } });
```

…and on the server side of that route:

```php
app(LnkFlow\Laravel\Services\ConsentRevocationService::class)->revoke();
```

which clears the session journey state immediately and queues
`POST /journeys/revoke`. Browser revocation alone leaves the server-side journey
state intact.

## Server-resolved consent (`:consent`)

If the host already knows the decision when the page is rendered, pass it and
the component emits the `setConsent()` call for you:

```blade
<x-lnkflow-script
    storage="manual"
    attribution="manual"
    :consent="new LnkFlow\Laravel\Data\Consent(
        storage: LnkFlow\Laravel\Data\ConsentState::Granted,
        adUserData: LnkFlow\Laravel\Data\ConsentState::Granted,
    )"
/>
```

`:consent="true"` / `:consent="false"` is shorthand for both flags. A `Consent`
object maps `storage` from `$consent->storage === Granted` and `attribution`
from `$consent->adUserData === Granted`. `setConsent` takes booleans, so
`unknown` is not expressible: anything short of an explicit grant becomes a
denial, which is the correct default for consent.

**Do not use `:consent` on a page served from a shared cache.** One visitor's
consent decision would be baked into HTML served to the next. On any cached or
CDN-fronted page, use the CMP wiring above instead.

Because the snippet is deferred, `window.lnkflow` does not exist yet when the
bootstrap runs. The emitted script polls every 50 ms for up to 5 seconds rather
than blocking the parser on a synchronous tag.

## Site key and browser-side capture

`site-key` plus `capture-endpoint` enable the browser fallback for journey
capture: the snippet posts to `POST /api/v1/public/journeys/touchpoints` with an
`X-Lnk-Site-Key` header from an allowlisted `Origin`. That endpoint accepts only
visitor/click/time/consent fields; it rejects customer, amount, source,
campaign, metadata, and provider fields.

Prefer backend capture. `CaptureJourneyContext` cannot be blocked by an ad
blocker, and consent is enforced server-side either way. Use the browser path
only where the landing page is static or served outside Laravel.

## Related

- [Journeys and consent](journeys-and-consent.md) — the server-side half.
- [Conversions](conversions.md) — reporting what the captured ids attribute.
- SaaS repository: `docs/api-reference.md` § *Conversion Tracking* documents the
  snippet's own attributes and the public capture endpoints.
