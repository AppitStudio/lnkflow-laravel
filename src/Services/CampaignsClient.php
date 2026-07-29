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
        return $this->paginate('campaigns', $filters, static fn (array $item): Campaign => new Campaign($item));
    }

    public function get(int $id): Campaign
    {
        return new Campaign($this->transport->send('GET', "campaigns/{$id}")->data());
    }

    /**
     * The idempotency key must be stable across retries of the same logical
     * create. Check `->replayed()` on the result to tell a fresh campaign from
     * a replay of one this key already created.
     */
    public function create(CreateCampaign $request, string $idempotencyKey): Campaign
    {
        $response = $this->transport->send(
            'POST',
            'campaigns',
            json: $request->toArray(),
            headers: ['Idempotency-Key' => $idempotencyKey],
        );

        return new Campaign($response->data(), $response);
    }

    public function update(int $id, UpdateCampaign $request): Campaign
    {
        $response = $this->transport->send('PATCH', "campaigns/{$id}", json: $request->toArray());

        return new Campaign($response->data(), $response);
    }

    public function delete(int $id): void
    {
        $this->transport->send('DELETE', "campaigns/{$id}");
    }
}
