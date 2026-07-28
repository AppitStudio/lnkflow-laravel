# Troubleshooting and production hardening

Start with `php artisan lnkflow:doctor`. It is read-only and checks API URL/TLS,
token presence, team, mapping migrations, consent binding, queue/session
configuration, Cashier selection, and `/me` connectivity.

- `401`: token invalid or expired; rotate it without logging the value.
- `403`: token lacks an ability or selected team access.
- `404`: resource is absent or intentionally hidden across tenant boundaries.
- `409`: idempotency key was reused with a different payload.
- `422`: inspect the typed field error bag.
- `429`: honor the exception `retryAfter`; queued jobs will retry.
- `5xx`/connection: retain the request ID and stable key, then retry safely.

Production requirements: HTTPS API URL; secure host sessions; persistent queue
workers; shared database/cache; least-privilege tokens; explicit team/website;
consent resolver bound before journey capture; no raw request/response logging.
Use worker monitoring and failed-job alerts. `lnkflow:verify
--test-conversion` writes a retained test event and is not a health check.
