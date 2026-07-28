# Journeys, consent, and identity

The default consent resolver returns `unknown`, which means no session state
and no capture. Bind the host application's consent manager:

```php
$this->app->bind(ConsentResolver::class, AppConsentResolver::class);
```

The resolver must independently return `storage`, `ad_user_data`, and
`ad_personalization` as `granted`, `denied`, or `unknown`. Treat Global Privacy
Control as host input when making that decision. Do not grant storage by
default.

Add `CaptureJourneyContext` to selected web routes after the session middleware.
For safe GET/HEAD navigation with a valid `lnk_id`, explicit storage consent,
and a non-bot request, it stores opaque first/last click context and queues the
touchpoint after the response. It never performs network I/O on navigation.
Optional URL cleanup removes only `lnk_id`/`lnk_promo` from the same path.

With `auth_identity`, registration/login queues identify using the configured
opaque `CustomerExternalIdResolver`. Logout queues **unidentify**: it closes the
active identity interval but does not revoke or erase history. On a shared
browser, a later login starts a new interval. Explicit consent revocation uses
`ConsentRevocationService`; it clears local state immediately and separately
queues remote revocation.

Use secure, HttpOnly, SameSite-appropriate sessions in production. Never render
visitor/click identifiers into cacheable HTML.
