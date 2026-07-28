<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $connection
 * @property string $remote_team_id
 * @property string $campaign_key
 * @property int|null $remote_campaign_id
 * @property string $idempotency_key
 * @property string|null $payload_hash
 * @property string $state
 */
final class CampaignMapping extends Model
{
    protected $table = 'lnkflow_campaign_mappings';

    /** @var list<string> */
    protected $fillable = [
        'connection', 'remote_team_id', 'campaign_key', 'remote_campaign_id',
        'idempotency_key', 'payload_hash', 'state', 'last_error_code',
        'last_request_id', 'last_error_message', 'last_synced_at',
    ];

    /** @return HasMany<LinkMapping, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(LinkMapping::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'remote_campaign_id' => 'integer',
            'last_synced_at' => 'immutable_datetime',
        ];
    }
}
