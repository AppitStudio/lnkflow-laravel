# Token scopes

Two independent questions decide whether a call succeeds:

1. **Which ability does the endpoint require?** That is the server's rule.
2. **Which configured token does the SDK send?** That is `ApiTransport`'s rule,
   driven by the client's "purpose".

Getting (1) wrong gives a `403 AuthorizationException`. Getting (2) wrong gives
either a 403 or `ConnectionException('No LnkFlow API token is configured.')`.
Neither falls back silently.

## Abilities

Tokens are created in Settings → API Tokens and are read-only by default.

| Ability | Grants |
|---|---|
| `read` | every GET the team can see |
| `write` | link/resource management — and, implicitly, conversion writes |
| `conversions` | journey and conversion writes, without general resource write |
| `*` | first-party/transient full capability |

`GET /me` reports the effective set:

```php
$me = LnkFlow::client()->identity()->me();
$me->can('write');       // bool
$me->can('conversions'); // true for a write token too
```

## Configured tokens

```dotenv
LNKFLOW_LINK_TOKEN=...        # ability: read,write
LNKFLOW_CONVERSION_TOKEN=...  # ability: read,conversions
LNKFLOW_API_TOKEN=...         # fallback + the only token some clients use
```

`ApiTransport::token()` resolves per purpose:

| Purpose | Token used | Falls back to |
|---|---|---|
| `links` | `link_token` | `api_token` |
| `conversions` | `conversion_token` | `api_token` |
| `journeys` | `conversion_token` | `api_token` |
| `api` | `api_token` | **nothing** |

## Which purpose each client uses

| Client | Purpose | Token |
|---|---|---|
| `campaigns()`, `links()`, `websites()`, `domains()`, `influencers()` | `links` | link → api |
| `journeys()` | `journeys` | conversion → api |
| `conversions()` | `conversions` | conversion → api |
| `identity()`, `search()`, `workspace()`, `stats()` | `api` | api only |

**The trap.** Configuring only `LNKFLOW_LINK_TOKEN` and
`LNKFLOW_CONVERSION_TOKEN` looks like correct least privilege, and link and
conversion work does succeed — but `identity()`, `search()`, `workspace()`, and
every `stats()` call then throw `ConnectionException('No LnkFlow API token is
configured.')`. `lnkflow:doctor` hits this: its configuration check accepts any
of the three tokens, then its connectivity check calls `identity()->me()`, which
does not. Set `LNKFLOW_API_TOKEN` to a read-only token if you use any of those
four clients.

## Call → ability matrix

| SDK call | Endpoint | Ability |
|---|---|---|
| `identity()->me()` | `GET /me` | read |
| `search()->query()` / `->first()` | `GET /search` | read |
| `workspace()->bootstrap()` | `GET /browser-extension/bootstrap` | read |
| `campaigns()->list()` / `->get()` | `GET /campaigns[/{id}]` | read |
| `campaigns()->create()` | `POST /campaigns` | **write** |
| `campaigns()->update()` | `PATCH /campaigns/{id}` | **write** |
| `campaigns()->delete()` | `DELETE /campaigns/{id}` | **write** |
| `links()->list()` / `->forCampaign()` / `->get()` | `GET /links`, `GET /campaigns/{id}/links`, `GET /links/{id}` | read |
| `links()->preview()` | `POST /links/preview` | **write** — see below |
| `links()->create()` | `POST /campaigns/{id}/links` | **write** |
| `links()->update()` / `->deactivate()` | `PATCH /links/{id}` | **write** |
| `links()->delete()` | `DELETE /links/{id}` | **write** |
| `websites()->list()` / `->get()` | `GET /websites[/{id}]` | read |
| `websites()->create()` / `->update()` | `POST` / `PATCH /websites` | **write** |
| `influencers()->list()` / `->get()` | `GET /influencers[/{id}]` | read |
| `influencers()->create()` / `->update()` | `POST` / `PATCH /influencers` | **write** |
| `influencers()->commissions()` / `->commissionsCsv()` | `GET /influencers/{id}/commissions` | read |
| `domains()->list()` | `GET /domains` | read |
| `stats()->summary/breakdown/compare/influencers/websites/campaign/link()` | `GET /stats/*`, `GET /{campaigns,links}/{id}/stats` | read |
| `stats()->conversions()` | `GET /stats/conversions` | read |
| `conversions()->events()` | `GET /track/events` | read |
| `conversions()->journey()` | `GET /track/events/{id}/journey` | read |
| `conversions()->event/lead/sale/refund/send()` | `POST /track/{lead,sale,refund}` | **conversions** or write |
| `journeys()->capture/identify/unidentify/revoke()` | `POST /journeys/*` | **conversions** or write |

### The `preview()` trap

`POST /links/preview` creates nothing — no campaign, no link, no influencer, no
click, no domain. It still requires a `write` token, because it previews a
*write intent* and runs the same account-scoping, destination-safety,
active-link-limit, slug, UTM, and usable-domain rules as a real create. A
read-only token gets a 403.

This propagates:

- `lnkflow:sync --dry-run` calls `ContentSynchronizer::preview()`, which calls
  `links()->preview()`. **A read-only token fails the dry run**, even though the
  dry run writes nothing.
- `content.preview_before_write` (default `true`) adds a preview call before
  each link create. The write token used for the create already covers it.

## Rate limits

- 60 requests/minute per token for the general API. The optional client-side
  throttle (`connections.*.throttle`) tries to stay inside that budget and
  deliberately fails open — if the budget is exhausted for longer than
  `max_wait_milliseconds`, the request still goes out and the server's 429 plus
  `Retry-After` becomes the authority.
- 600 requests/minute per team for the conversion-tracking writes.

## Handling tokens

- Load tokens from environment/configuration at call time. The SDK resolves them
  inside `send()`, never at boot, so rotation does not need a restart.
- Never put a token in source control, a database mapping, a queued job
  property, an exception, a log line, telemetry, a snapshot, or a fixture. No
  SDK job carries a token — they resolve a client in `handle()`.
- Use separate link and conversion tokens for automated workloads so a
  compromised CMS integration cannot report revenue, and vice versa.
- `lnkflow:install` prints the environment variables a preset needs and writes
  no secret.
