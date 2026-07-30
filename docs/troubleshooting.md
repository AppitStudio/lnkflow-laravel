# Troubleshooting and production hardening

Start with `php artisan lnkflow:doctor`. It is read-only and checks the API URL
and TLS, token presence, team, the consent binding, queue and session
configuration, Cashier selection, and `GET /me` connectivity. It checks mapping
migrations only when content synchronization is enabled.

For the exception classes and their properties, see [Errors](errors.md). For
which token a call needs, see [Token scopes](token-scopes.md).

## Symptoms

**`ConnectionException: No LnkFlow API token is configured.`**
The client you used resolves a token your configuration does not set. Most often
you set only `LNKFLOW_LINK_TOKEN` and `LNKFLOW_CONVERSION_TOKEN` and then called
`identity()`, `search()`, `workspace()`, or `stats()` — those use `api_token`
with no fallback. Set `LNKFLOW_API_TOKEN`.

**`lnkflow:doctor` reports "An API token is configured" and then fails
connectivity.** Same cause: the configuration check accepts any of the three
tokens, but the connectivity check calls `identity()->me()`, which needs
`api_token`.

**`ConnectionException: The LnkFlow connection [x] is not configured.`**
`LNKFLOW_CONNECTION` or a `connection('x')` call names a key that does not exist
under `lnkflow.connections`.

**403 on `links()->preview()` or `lnkflow:sync --dry-run`.**
`POST /links/preview` requires a `write` token even though it has no side
effects. A read-only token cannot dry-run.

**404 on a resource you can see in the dashboard.**
Wrong team. `LNKFLOW_TEAM` is the **numeric** team id — not a user id, not a
slug. Confirm with `identity()->me()->raw['teams']`. Cross-tenant 404 is a
security boundary; do not work around it.

**422 with `errorCode = IDEMPOTENCY_KEY_REUSED`.**
The same idempotency key was sent with a different canonical payload. Fix the
key, not the payload — a key belongs to one logical create.

**`InvalidArgumentException` from a DTO constructor.**
`UpdateCampaign`/`UpdateLink`/`UpdateWebsite`/`UpdateInfluencer` reject unknown
keys, `CreateLink` rejects unknown UTM keys, and `UpdateCampaign` rejects `slug`
outright. These fire where you wrote the code rather than as a 422 inside a
queued job. The `slug` case is explained in [Links](links.md).

**A link keeps un-pausing, or conversion tracking keeps switching off.**
The content adapter is asserting state it does not own. Leave
`LinkDefinition::$active` and `$conversionTrackingEnabled` null so the fields are
omitted and the dashboard value survives. See [CMS sync](cms-sync.md).

**A campaign rename never reaches LnkFlow.**
Only `name` and `website_id` are reconciled, and only when the campaign payload
hash changes. `is_active` is excluded on purpose, and `slug` cannot be updated
at all.

**Nothing is captured, and nothing is sent.**
That is what `unknown` consent produces. Bind a `ConsentResolver`, and register
`CaptureJourneyContext` yourself — there is no config key that registers it.

**Conversion stats are all zeros.**
Check `ConversionStats::$hasConversionData` first. False means the team has never
recorded a real conversion, so those are structural zeros, not measured zeros.

**Everything works locally and nothing works on the workers.**
Workers must share the application database and cache with the web nodes. The
mapping rows, unique-job locks, and the client-side throttle counter all live
there.

## Status quick reference

| Status | Meaning | Action |
|---|---|---|
| 401 | invalid, revoked, or expired token | rotate it; never log the value |
| 403 | missing ability or no access to the selected team | check the ability matrix |
| 404 | absent, or another team's resource | verify the team id |
| 409 | `IDEMPOTENCY_IN_PROGRESS`, or a genuine conflict | the transport retries only the former |
| 422 | validation, or `IDEMPOTENCY_KEY_REUSED` | read `->errors` |
| 429 | rate limited | honour `->retryAfter`; queued jobs release themselves |
| 5xx / connection | server or transport failure | retain the request id and reuse the same idempotency key |

## Production requirements

- HTTPS API URL. `lnkflow:doctor` fails a non-TLS production URL.
- Least-privilege tokens, and an explicit numeric team and website.
- Secure, HttpOnly, SameSite-appropriate sessions.
- Persistent queue workers sharing the application database and cache, with
  failed-job alerting. Watch `ConversionFailed` and
  `ContentSynchronizationFailed`.
- A `ConsentResolver` bound before any journey capture.
- No raw request or response logging. `LNKFLOW_LOGGING=true` emits endpoint,
  status, attempt, duration, team, and request id only — never the token, the
  payload, or customer/visitor identifiers.
- Keep the request id from a failure. It is the only handle support has for
  correlating a report with a server log line.

## Commands and what they touch

| Command | Remote effect |
|---|---|
| `lnkflow:doctor` | one `GET /me`. Nothing else. |
| `lnkflow:sync --dry-run` | `POST /links/preview` only — no writes, but needs a **write** token |
| `lnkflow:sync` | queues real creates/updates/deactivations |
| `lnkflow:verify --test-conversion` | **writes** a retained, labelled test conversion and reads it back. Not a health check. |
| `lnkflow:install` | local files only; writes no secret, enables no Cashier |
