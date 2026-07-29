<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LnkFlow\Laravel\Contracts\LinkableContent;
use LnkFlow\Laravel\Data\CreateCampaign;
use LnkFlow\Laravel\Data\LinkDefinition;
use LnkFlow\Laravel\Data\UpdateCampaign;
use LnkFlow\Laravel\Data\UpdateLink;
use LnkFlow\Laravel\Events\ContentSynchronizationFailed;
use LnkFlow\Laravel\Events\ContentSynchronized;
use LnkFlow\Laravel\Exceptions\LnkFlowException;
use LnkFlow\Laravel\Models\CampaignMapping;
use LnkFlow\Laravel\Models\LinkMapping;
use RuntimeException;
use Throwable;

final readonly class ContentSynchronizer
{
    public function __construct(private Client $client) {}

    public function sync(string $modelClass, string $modelKey, bool $force = false): int
    {
        $model = $this->model($modelClass, $modelKey);
        $adapter = $this->adapter($modelClass);
        $sourceKey = $adapter->lnkFlowSourceKey($model);
        $definitions = [...$adapter->lnkFlowLinks($model)];
        $placements = [];
        $synced = 0;

        foreach ($definitions as $definition) {
            $placements[] = $definition->placement;
            $this->syncDefinition($modelClass, $sourceKey, $definition, $force);
            $synced++;
        }

        $this->disableMissing($modelClass, $sourceKey, $placements);

        return $synced;
    }

    /** @return list<array<string, mixed>> */
    public function preview(string $modelClass, string $modelKey): array
    {
        $model = $this->model($modelClass, $modelKey);
        $adapter = $this->adapter($modelClass);
        $previews = [];
        $client = $this->scopedClient();

        foreach ($adapter->lnkFlowLinks($model) as $definition) {
            $previews[] = $client->links()->preview(
                $definition->createLink(),
                campaignName: $definition->campaignName,
            )->raw;
        }

        return $previews;
    }

    public function disableSource(string $modelClass, string $sourceKey): int
    {
        $mappings = LinkMapping::query()
            ->where('connection', $this->connection())
            ->where('remote_team_id', $this->team())
            ->where('source_type', $modelClass)
            ->where('source_id', $sourceKey)
            ->whereNotNull('remote_link_id')
            ->get();

        foreach ($mappings as $mapping) {
            $this->scopedClient()->links()->deactivate((int) $mapping->remote_link_id);
            $mapping->update([
                'state' => 'disabled',
                'last_error_code' => null,
                'last_request_id' => null,
                'last_error_message' => null,
                'last_synced_at' => now(),
            ]);
        }

        return $mappings->count();
    }

    private function syncDefinition(
        string $modelClass,
        string $sourceKey,
        LinkDefinition $definition,
        bool $force,
    ): void {
        $campaign = CampaignMapping::query()->firstOrCreate(
            [
                'connection' => $this->connection(),
                'remote_team_id' => $this->team(),
                'campaign_key' => $definition->campaignKey,
            ],
            [
                'idempotency_key' => (string) Str::uuid(),
                'state' => 'pending',
            ],
        );
        $campaignPayload = new CreateCampaign(
            $definition->campaignName,
            websiteId: $definition->websiteId,
        );
        $campaignHash = $this->hash($campaignPayload->toArray());

        if ($campaign->remote_campaign_id === null) {
            $remote = $this->scopedClient()->campaigns()->create(
                $campaignPayload,
                $campaign->idempotency_key,
            );
            $campaign->update([
                'remote_campaign_id' => $remote->id,
                'payload_hash' => $campaignHash,
                'state' => 'synced',
                'last_synced_at' => now(),
            ]);
        } elseif (! hash_equals((string) $campaign->payload_hash, $campaignHash)) {
            // Reconcile campaign drift. Without this a renamed campaign or a
            // moved website is computed on every sync and silently discarded,
            // so the remote campaign can never catch up with the source.
            //
            // Only the fields the adapter actually owns are sent. `is_active`
            // is deliberately excluded: the API forwards it to the primary
            // link, so including it would un-pause a campaign or link paused
            // by hand in the dashboard.
            $this->scopedClient()->campaigns()->update(
                (int) $campaign->remote_campaign_id,
                new UpdateCampaign(array_filter([
                    'name' => $definition->campaignName,
                    'website_id' => $definition->websiteId,
                ], static fn (mixed $value): bool => $value !== null)),
            );
            $campaign->update([
                'payload_hash' => $campaignHash,
                'state' => 'synced',
                'last_error_code' => null,
                'last_request_id' => null,
                'last_error_message' => null,
                'last_synced_at' => now(),
            ]);
        }

        $mapping = LinkMapping::query()->firstOrCreate(
            [
                'connection' => $this->connection(),
                'remote_team_id' => $this->team(),
                'source_type' => $modelClass,
                'source_id' => $sourceKey,
                'placement' => $definition->placement,
            ],
            [
                'campaign_mapping_id' => $campaign->id,
                'remote_campaign_id' => $campaign->remote_campaign_id,
                'idempotency_key' => (string) Str::uuid(),
                'state' => 'pending',
            ],
        );
        $payload = $definition->createLink();
        $hash = $this->hash($payload->toArray());

        if (! $force && $mapping->state === 'synced' && hash_equals((string) $mapping->payload_hash, $hash)) {
            return;
        }

        try {
            if ($mapping->remote_link_id === null) {
                if (config('lnkflow.content.preview_before_write') === true) {
                    $this->scopedClient()->links()->preview(
                        $payload,
                        campaignId: (int) $campaign->remote_campaign_id,
                    );
                }

                $remote = $this->scopedClient()->links()->create(
                    (int) $campaign->remote_campaign_id,
                    $payload,
                    $mapping->idempotency_key,
                );
            } else {
                // `CreateLink` omits `is_active` and `conversion_tracking_enabled`
                // unless the adapter set them explicitly, so a link paused — or a
                // link with conversion tracking switched on — in the LnkFlow
                // dashboard survives the next content change instead of being
                // silently reset by the sync.
                $remote = $this->scopedClient()->links()->update(
                    (int) $mapping->remote_link_id,
                    new UpdateLink($payload->toArray()),
                );
            }

            $mapping->update([
                'campaign_mapping_id' => $campaign->id,
                'remote_campaign_id' => $campaign->remote_campaign_id,
                'remote_link_id' => $remote->id,
                'short_url' => $remote->shortUrl,
                'payload_hash' => $hash,
                'state' => 'synced',
                'last_error_code' => null,
                'last_request_id' => null,
                'last_error_message' => null,
                'last_synced_at' => now(),
            ]);
            event(new ContentSynchronized((int) $mapping->id, $remote->id));
        } catch (Throwable $exception) {
            $mapping->update([
                'state' => 'failed',
                'last_error_code' => $exception instanceof LnkFlowException
                    ? $exception->errorCode ?? $exception::class
                    : $exception::class,
                'last_request_id' => $exception instanceof LnkFlowException ? $exception->requestId : null,
                'last_error_message' => Str::limit($exception->getMessage(), 500, ''),
            ]);
            event(new ContentSynchronizationFailed((int) $mapping->id, $exception::class));

            throw $exception;
        }
    }

    /** @param list<string> $placements */
    private function disableMissing(string $modelClass, string $sourceKey, array $placements): void
    {
        $query = LinkMapping::query()
            ->where('connection', $this->connection())
            ->where('remote_team_id', $this->team())
            ->where('source_type', $modelClass)
            ->where('source_id', $sourceKey)
            ->whereNotNull('remote_link_id')
            ->where('state', '!=', 'disabled');

        if ($placements !== []) {
            $query->whereNotIn('placement', $placements);
        }

        foreach ($query->get() as $mapping) {
            $this->scopedClient()->links()->deactivate((int) $mapping->remote_link_id);
            $mapping->update(['state' => 'disabled', 'last_synced_at' => now()]);
        }
    }

    private function model(string $modelClass, string $key): Model
    {
        $model = new $modelClass;

        if (! $model instanceof Model) {
            throw new InvalidArgumentException("{$modelClass} is not an Eloquent model.");
        }

        /** @var Model|null $fresh */
        $fresh = $model->newQuery()->find($key);

        if (! $fresh instanceof Model) {
            throw new RuntimeException("The source model [{$modelClass}:{$key}] no longer exists.");
        }

        return $fresh;
    }

    private function adapter(string $modelClass): LinkableContent
    {
        $models = config('lnkflow.content.models', []);
        $adapterClass = is_array($models) ? ($models[$modelClass] ?? null) : null;
        $adapter = is_string($adapterClass) ? app($adapterClass) : $adapterClass;

        if (! $adapter instanceof LinkableContent) {
            throw new RuntimeException("No LinkableContent adapter is configured for [{$modelClass}].");
        }

        return $adapter;
    }

    private function scopedClient(): Client
    {
        return $this->client->connection($this->connection())->forTeam($this->team());
    }

    private function connection(): string
    {
        return config()->string('lnkflow.default', 'default');
    }

    private function team(): string
    {
        $team = config("lnkflow.connections.{$this->connection()}.team");

        if (! is_scalar($team) || (string) $team === '') {
            throw new RuntimeException('LNKFLOW_TEAM is required for content synchronization.');
        }

        return (string) $team;
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        $payload = $this->canonicalize($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Nested values are whatever the payload carries, lists included, so this
     * is keyed by `array-key` rather than by the string keys of the top-level
     * payload it is first called with.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalize($value);
            }
        }

        return $payload;
    }
}
