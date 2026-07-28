<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Data\CreateLink;
use LnkFlow\Laravel\Data\Link;
use LnkFlow\Laravel\Data\Page;
use LnkFlow\Laravel\Data\Resource;
use LnkFlow\Laravel\Data\UpdateLink;

final class LinksClient extends AbstractClient
{
    /**
     * @param  array<string, scalar|null>  $filters
     * @return Page<Link>
     */
    public function list(array $filters = []): Page
    {
        return $this->page($this->transport->send('GET', 'links', $filters));
    }

    /**
     * @param  array<string, scalar|null>  $filters
     * @return Page<Link>
     */
    public function forCampaign(int $campaignId, array $filters = []): Page
    {
        return $this->page($this->transport->send('GET', "campaigns/{$campaignId}/links", $filters));
    }

    public function get(int $id): Link
    {
        return new Link($this->data($this->transport->send('GET', "links/{$id}")));
    }

    public function preview(CreateLink $request, ?int $campaignId = null, ?string $campaignName = null): Resource
    {
        return new Resource($this->data($this->transport->send('POST', 'links/preview', json: [
            ...$request->toArray(),
            'campaign_id' => $campaignId,
            'campaign_name' => $campaignName,
        ])));
    }

    public function create(int $campaignId, CreateLink $request, string $idempotencyKey): Link
    {
        return new Link($this->data($this->transport->send(
            'POST',
            "campaigns/{$campaignId}/links",
            json: $request->toArray(),
            headers: ['Idempotency-Key' => $idempotencyKey],
        )));
    }

    public function update(int $id, UpdateLink $request): Link
    {
        return new Link($this->data($this->transport->send(
            'PATCH',
            "links/{$id}",
            json: $request->toArray(),
        )));
    }

    public function deactivate(int $id): Link
    {
        return $this->update($id, new UpdateLink(['is_active' => false]));
    }

    public function delete(int $id): void
    {
        $this->transport->send('DELETE', "links/{$id}");
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Page<Link>
     */
    private function page(array $payload): Page
    {
        return new Page(
            array_map(fn (array $item): Link => new Link($item), $this->collection($payload)),
            is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            is_array($payload['links'] ?? null) ? $payload['links'] : [],
        );
    }
}
