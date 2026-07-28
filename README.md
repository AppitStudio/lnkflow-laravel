# LnkFlow Laravel

The official Laravel SDK for LnkFlow link management, CMS synchronization,
consent-aware journeys, identity lifecycle, and conversion reporting.

Requires PHP 8.2+ and Laravel 12 or 13. The `0.x` package line targets the
LnkFlow `/api/v1` contract.

## Install

```bash
composer require lnkflow/laravel
php artisan lnkflow:install --preset=client
php artisan migrate
php artisan lnkflow:doctor
```

Available presets are `client`, `links`, `content`, `journeys`, `conversions`,
and `full`. Installation publishes configuration and mapping migrations but
never writes secrets or enables Cashier.

Configure the smallest token scope the application needs:

```dotenv
LNKFLOW_API_URL=https://app.lnkflow.io/api/v1
LNKFLOW_TEAM=team_public_id
LNKFLOW_WEBSITE=123
LNKFLOW_LINK_TOKEN=link_token_with_read_and_write
LNKFLOW_CONVERSION_TOKEN=conversion_token_with_read_and_conversions
```

Use `LNKFLOW_API_TOKEN` only when one broader token is intentional. Automated
link and conversion workloads should use separate tokens.

## Typed client

```php
use LnkFlow\Laravel\Data\CreateCampaign;
use LnkFlow\Laravel\Data\CreateLink;
use LnkFlow\Laravel\Facades\LnkFlow;

$client = LnkFlow::forTeam('team_public_id');

$campaign = $client->campaigns()->create(
    new CreateCampaign('Product launch', websiteId: 123),
    idempotencyKey: 'cms:campaign:product-launch',
);

$link = $client->links()->create(
    $campaign->id,
    new CreateLink(
        destinationUrl: 'https://example.com/launch',
        slug: 'launch',
        conversionTrackingEnabled: true,
        autoPromoCode: 'LAUNCH20',
    ),
    idempotencyKey: 'cms:link:product-launch:primary',
);
```

Clients are available for identity, campaigns, links, websites, domains,
influencers, stats, journeys, and conversions. `connection('name')` selects a
named configuration; `forTeam($id)` sends the explicit `X-LnkFlow-Team`
header. The SDK never silently chooses another team.

## Preset quickstarts

- `client`: typed, synchronous API access only. See [Client usage](docs/client.md).
- `links`: client plus link-management configuration. See [Links](docs/links.md).
- `content`: queued Eloquent-to-LnkFlow reconciliation. See [CMS sync](docs/cms-sync.md).
- `journeys`: consent-gated touchpoints and auth identity lifecycle. See [Journeys and consent](docs/journeys-and-consent.md).
- `conversions`: queued events, leads, sales, and refunds. See [Conversions](docs/conversions.md).
- `full`: enables all package features; each host binding and worker requirement still applies.

Queues are required for CMS, journey, auth, and conversion automation.
Production workers must share the application database and cache. Eloquent
observers only dispatch after commit; they never perform remote HTTP during a
model save.

## Manual conversions

```php
use LnkFlow\Laravel\Data\NamedEvent;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Facades\LnkFlow;

LnkFlow::trackEvent(new NamedEvent('trial_started', 'customer_opaque_42'));
LnkFlow::trackSale(new Sale('invoice_1042', 1299, 'USD', 'customer_opaque_42'));
```

Amounts use integer minor currency units. Stable invoice/refund/customer-event
identifiers are required for safe retries. Cashier support is optional; choose
either the direct LnkFlow Stripe webhook or the package Cashier adapter for the
same transactions, never both. See [Cashier](docs/cashier.md).

## Testing

```php
use LnkFlow\Laravel\Facades\LnkFlow;

LnkFlow::fake();

// exercise application code

LnkFlow::assertLinkCreated();
LnkFlow::assertSaleTracked();
LnkFlow::assertNothingSent();
```

The fake replaces the transport and performs no network calls. See
[Testing](docs/testing.md).

## Operations and security

`php artisan lnkflow:doctor` is read-only. `lnkflow:sync --dry-run` validates
configured content without remote writes. `lnkflow:verify --test-conversion`
is intentionally mutating and requires confirmation or `--force`.

The transport has bounded retries, honors `Retry-After`, and retries POST only
with an idempotency key or documented stable business key. Never log bearer
tokens or raw request bodies. Use HTTPS, secure host sessions, shared queue
infrastructure, conservative consent defaults, and least-privilege tokens.
See [Troubleshooting and hardening](docs/troubleshooting.md).

## Versioning

The SDK follows SemVer. Additive API fields are tolerated. Breaking public PHP
or API-contract changes require a package major version or a documented
deprecation window. See [Upgrading](docs/upgrading.md).

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md), and
[CHANGELOG.md](CHANGELOG.md).

LnkFlow Laravel is licensed under the [MIT License](LICENSE.md).
