# Contributing

Use PHP 8.2+ and Composer 2. Fork the repository, create a focused branch, and
include tests and documentation with behavior changes.

```bash
composer install
composer validate --strict
composer test
composer audit
```

Keep public APIs typed, preserve Laravel 12/13 compatibility, and do not weaken
tenant, consent, idempotency, retry, or credential-redaction guarantees. Never
include real tokens or customer data in fixtures. Explain breaking changes and
update `CHANGELOG.md` plus `docs/upgrading.md`.
