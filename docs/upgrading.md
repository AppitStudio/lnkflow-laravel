# Upgrading and compatibility

Package `0.x` supports PHP 8.2+ with Laravel 12 and 13 and targets LnkFlow API
v1. Before upgrading:

1. read `CHANGELOG.md`;
2. run `composer update lnkflow/laravel --with-all-dependencies`;
3. publish migrations without overwriting local configuration;
4. run `php artisan migrate`;
5. run `php artisan lnkflow:doctor`;
6. run the host test suite and `lnkflow:sync --dry-run`.

Additive response fields and unknown edge status strings are backward
compatible. New required configuration, removed PHP APIs, changed retry or
consent semantics, and API contract breaks require a documented deprecation or
major package release. Deprecated APIs remain documented for at least one
minor release when practical.
