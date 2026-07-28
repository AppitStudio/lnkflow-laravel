# CMS synchronization

Enable the `content` preset, run the published migrations, and map each Eloquent
model to an adapter:

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

```php
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
        );
    }
}
```

Saved/restored/deleted events dispatch after-commit jobs. The mapping tables
persist remote IDs, payload hashes, stable idempotency keys, state, and safe
diagnostics. Unchanged payloads are skipped; changed content is patched;
removed/deleted placements are remotely deactivated, never deleted.

Eloquent mass updates bypass observers. Repair or backfill with
`php artisan lnkflow:sync`; use `--dry-run` first. Workers and web nodes must
share the database and cache. Queue payloads contain no credentials.
