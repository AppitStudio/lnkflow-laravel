# CMS synchronization

Keeps LnkFlow links in step with host content: an Eloquent model declares the
links it should have, and the package reconciles them after every commit.

Enable the `content` preset, run the published migrations, and map each model to
an adapter:

```php
// config/lnkflow.php
'content' => [
    'models' => [
        App\Models\Post::class => App\LnkFlow\PostLinks::class,
    ],
    'preview_before_write' => true,
    'queue' => 'integrations',
],
```

`features.content` must be `true` as well — that is what registers the observer.
`LNKFLOW_TEAM` is required; without it the synchronizer throws
`RuntimeException('LNKFLOW_TEAM is required for content synchronization.')`
rather than writing into whatever team the token defaults to.

## The adapter

```php
use Illuminate\Database\Eloquent\Model;
use LnkFlow\Laravel\Contracts\LinkableContent;
use LnkFlow\Laravel\Data\LinkDefinition;

final class PostLinks implements LinkableContent
{
    public function lnkFlowSourceKey(Model $model): string
    {
        return (string) $model->getKey();
    }

    public function lnkFlowLinks(Model $model): iterable
    {
        yield new LinkDefinition(
            placement: 'primary',
            campaignKey: 'blog',
            campaignName: 'Blog',
            destinationUrl: route('posts.show', $model),
            name: $model->title,
            slug: $model->slug,
            utm: ['utm_source' => 'newsletter'],
            websiteId: 12,
        );
    }
}
```

`placement` identifies the link **within** the source record, so one model can
own several links — a header CTA and a footer CTA, say — and each keeps its own
stable mapping. `campaignKey` is your own key for the campaign the links live
under; the package creates that campaign once and reuses it.

`lnkFlowSourceKey()` exists so the mapping survives things the primary key does
not, such as a translated or duplicated record. Return the primary key when in
doubt, but return the same value every time — it is the join.

## What a sync does

`SyncLinkableContentJob` (dispatched after commit, unique and non-overlapping
per model key) runs `ContentSynchronizer::sync()`, which for each definition:

1. Finds or creates the `CampaignMapping` row for `campaignKey`, generating and
   persisting a stable idempotency key.
2. Creates the remote campaign if it has none, or **PATCHes it when the
   campaign payload hash has drifted**.
3. Finds or creates the `LinkMapping` row for `(source, placement)`.
4. Skips the link entirely when its payload hash is unchanged and its state is
   `synced` (unless `--force`).
5. Otherwise previews (when `preview_before_write` is true), then creates with
   the mapping's idempotency key, or updates an existing link.
6. Records `remote_link_id`, `short_url`, the payload hash, `state`, and
   `last_synced_at`, and fires `ContentSynchronized`.

Placements that disappeared from `lnkFlowLinks()` are **deactivated**, never
deleted. Deleting the source model deactivates every link mapped to it.

### Campaign drift is reconciled — with two fields only

When the campaign payload hash changes, the sync sends
`PATCH /campaigns/{id}` with `name` and `website_id`, and nothing else.

Reconciling at all matters: without it, a renamed campaign or a moved website is
recomputed on every sync and silently discarded, so the remote campaign can
never catch up with the source.

Sending only those two fields matters just as much. `is_active` is deliberately
excluded, because the API forwards it to the campaign's primary link — including
it would un-pause a campaign or a link that somebody paused by hand in the
dashboard. `slug` is not sendable at all; see [Links](links.md) for why.

### Link updates omit what they do not own

`LinkDefinition::$active` and `$conversionTrackingEnabled` default to `null`,
and a null field is omitted from the payload. So a link paused in the LnkFlow
dashboard — or one with conversion tracking switched on there — survives the
next content change instead of being reset by the sync.

Set them only when the host application is genuinely the owner of that state.

## Mapping tables

Content synchronization is the only SDK feature that requires package-owned
database tables. Publish and migrate them when enabling `features.content`:

```bash
php artisan vendor:publish \
    --provider="LnkFlow\Laravel\LnkFlowServiceProvider" \
    --tag=lnkflow-migrations
php artisan migrate
```

Two published tables persist the join: `lnkflow_campaign_mappings` and
`lnkflow_link_mappings`. They hold the connection, remote team id, remote ids,
the payload hash, the stable idempotency key, `state`
(`pending|synced|failed|disabled`), `last_synced_at`, and safe failure
diagnostics (`last_error_code`, `last_request_id`, a truncated
`last_error_message`).

They never hold a token, a payload, or customer data. Neither do the queued
jobs — every job carries identifiers and resolves a client in `handle()`.

Do not publish these migrations for client-only, links, journeys, identity, or
conversion integrations. Those records live remotely in LnkFlow.

## Commands

```bash
php artisan lnkflow:sync --dry-run                        # validate everything
php artisan lnkflow:sync --model="App\Models\Post" --id=42
php artisan lnkflow:sync --force                          # ignore matching hashes
php artisan lnkflow:sync --chunk=250
```

Without `--dry-run` the command queues a job per record; it does not sync
inline.

**`--dry-run` needs a write-ability token.** It has no side effects — it calls
`ContentSynchronizer::preview()`, which creates no campaign, link, influencer,
click, or domain — but it reaches `POST /links/preview`, and that endpoint
requires `write` because it previews a write intent. A read-only token fails the
dry run with a 403. See [Token scopes](token-scopes.md).

The same applies to `content.preview_before_write` (default `true`), which adds
a preview call before each create. That one is already covered by the write
token the create needs.

## Operating it

- Eloquent mass updates and deletes emit no model events, so they bypass the
  observer entirely. `lnkflow:sync` is the repair and backfill path — the
  command says so on every run.
- Workers and web nodes must share the application database and cache. The
  mapping rows and the unique-job locks live there.
- Nothing performs remote HTTP during a model save. The observer dispatches
  after commit; a LnkFlow outage cannot fail a content save.
- A failed sync marks the mapping `failed` with a safe error code and request
  id, fires `ContentSynchronizationFailed`, and re-throws so the queue retry
  policy applies: `SyncLinkableContentJob` has `$tries = 5` and
  `$backoff = [10, 30, 120, 300]`. Unlike the journey and conversion jobs it
  does **not** use `ReportsApiFailures`, so a permanent failure such as a 422 or
  a 403 still burns all five attempts before it lands in `failed_jobs`. Watch
  `ContentSynchronizationFailed` and the mapping's `last_error_code` rather than
  waiting for the retries. See [Errors](errors.md).
- `ContentSynchronizer::disableSource()` is available directly when you need to
  retire a source record's links without deleting the record.
