<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Data\Campaign;
use LnkFlow\Laravel\Data\CreateCampaign;
use LnkFlow\Laravel\Data\Page;
use LnkFlow\Laravel\Data\UpdateCampaign;

final class CampaignsClient extends AbstractClient
{
    /**
     * @param  array<string, scalar|null>  $filters
     * @return Page<Campaign>
     */
    public function list(array $filters = []): Page
    {
        $payload = $this->transport->send('GET', 'campaigns', $filters);

        return new Page(
            array_map(fn (array $item): Campaign => new Campaign($item), $this->collection($payload)),
            is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            is_array($payload['links'] ?? null) ? $payload['links'] : [],
        );
    }

    public function get(int $id): Campaign
    {
        return new Campaign($this->data($this->transport->send('GET', "campaigns/{$id}")));
    }

    public function create(CreateCampaign $request, string $idempotencyKey): Campaign
    {
        return new Campaign($this->data($this->transport->send(
            'POST',
            'campaigns',
            json: $request->toArray(),
            headers: ['Idempotency-Key' => $idempotencyKey],
        )));
    }

    public function update(int $id, UpdateCampaign $request): Campaign
    {
        return new Campaign($this->data($this->transport->send(
            'PATCH',
            "campaigns/{$id}",
            json: $request->toArray(),
        )));
    }

    public function delete(int $id): void
    {
        $this->transport->send('DELETE', "campaigns/{$id}");
    }
}
