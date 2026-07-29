# Upgrading and compatibility

Package `0.x` supports PHP 8.2+ with Laravel 12 and 13, and targets LnkFlow API
v1. Before upgrading:

1. read [`CHANGELOG.md`](../CHANGELOG.md);
2. run `composer update lnkflow/laravel --with-all-dependencies`;
3. publish migrations without overwriting local configuration;
4. run `php artisan migrate`;
5. run `php artisan lnkflow:doctor`;
6. run the host test suite and `lnkflow:sync --dry-run` (which needs a write
   token — see [Token scopes](token-scopes.md)).

## Compatibility promises

- **Additive response fields are tolerated.** Every read model keeps the full
  decoded payload in `->raw`, so a new server field is reachable without an SDK
  upgrade.
- **Unknown enum values stay usable as raw strings.** `edge_status`,
  `social_platform`, `attribution_source`, and the preview warning codes are all
  open. Treat an unknown `edge_status` as "not published yet", never as failure.
- **Ids stay integers** where the API guarantees them; dates stay ISO-8601
  strings; currency stays lowercase ISO 4217 with integer minor units.
- **Pagination preserves `data`, `meta`, and `links`** verbatim, including
  metadata this SDK version does not understand.

New required configuration, removed PHP APIs, changed retry or consent
semantics, and API contract breaks require a documented deprecation or a major
package release. Deprecated APIs remain documented for at least one minor
release where practical.

## While `0.x` is pre-release

No version has been published yet. Until `0.1.0` is tagged, the public PHP
surface may change without a deprecation window; the compatibility promises
above describe the intent, and become binding at the first release.

## When the API changes

This SDK is a client of a contract it does not own. When the two disagree, the
server wins and the SDK is the bug. The upstream sources of truth live in the
LnkFlow SaaS repository (`AppitStudio/lnkflow.io`):

- `docs/openapi/lnkflow-v1.yaml` — the machine-readable v1 contract.
- `docs/api-reference.md` — the human v1 contract.
- `PRPs/integrations/base-context.md` — the invariants every LnkFlow
  integration must satisfy.
- `docs/integrations/conformance-checklist.md` — the reviewer checklist to tick
  before shipping a change here.
- `docs/integrations/README.md` — the compatibility matrix showing which
  response fields this SDK depends on, and who else breaks if one changes.

A change to a v1 route, request, or resource in that repository is expected to
update the OpenAPI file and the human reference in the same change; CI enforces
it. If you find this SDK relying on something none of those documents describe,
treat that as a defect in one of them, not as a licence to keep the dependency.
