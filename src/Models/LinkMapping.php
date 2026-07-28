<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $connection
 * @property string $remote_team_id
 * @property string $source_type
 * @property string $source_id
 * @property string $placement
 * @property int $campaign_mapping_id
 * @property int|null $remote_campaign_id
 * @property int|null $remote_link_id
 * @property string|null $short_url
 * @property string $idempotency_key
 * @property string|null $payload_hash
 * @property string $state
 */
final class LinkMapping extends Model
{
    protected $table = 'lnkflow_link_mappings';

    /** @var list<string> */
    protected $fillable = [
        'connection', 'remote_team_id', 'source_type', 'source_id', 'placement',
        'campaign_mapping_id', 'remote_campaign_id', 'remote_link_id', 'short_url',
        'idempotency_key', 'payload_hash', 'state', 'last_error_code',
        'last_request_id', 'last_error_message', 'last_synced_at',
    ];

    /** @return BelongsTo<CampaignMapping, $this> */
    public function campaignMapping(): BelongsTo
    {
        return $this->belongsTo(CampaignMapping::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'campaign_mapping_id' => 'integer',
            'remote_campaign_id' => 'integer',
            'remote_link_id' => 'integer',
            'last_synced_at' => 'immutable_datetime',
        ];
    }
}
