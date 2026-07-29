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
        return $this->paginate('links', $filters, static fn (array $item): Link => new Link($item));
    }

    /**
     * @param  array<string, scalar|null>  $filters
     * @return Page<Link>
     */
    public function forCampaign(int $campaignId, array $filters = []): Page
    {
        return $this->paginate(
            "campaigns/{$campaignId}/links",
            $filters,
            static fn (array $item): Link => new Link($item),
        );
    }

    public function get(int $id): Link
    {
        return new Link($this->transport->send('GET', "links/{$id}")->data());
    }

    /**
     * Side-effect-free validation. Note this still needs a token with the
     * `write` ability — a read-only token gets a 403.
     */
    public function preview(CreateLink $request, ?int $campaignId = null, ?string $campaignName = null): Resource
    {
        return new Resource($this->transport->send('POST', 'links/preview', json: array_filter([
            ...$request->toArray(),
            'campaign_id' => $campaignId,
            'campaign_name' => $campaignName,
        ], static fn (mixed $value): bool => $value !== null))->data());
    }

    public function create(int $campaignId, CreateLink $request, string $idempotencyKey): Link
    {
        $response = $this->transport->send(
            'POST',
            "campaigns/{$campaignId}/links",
            json: $request->toArray(),
            headers: ['Idempotency-Key' => $idempotencyKey],
        );

        return new Link($response->data(), $response);
    }

    public function update(int $id, UpdateLink $request): Link
    {
        $response = $this->transport->send('PATCH', "links/{$id}", json: $request->toArray());

        return new Link($response->data(), $response);
    }

    /** Pause a link. This is the safe alternative to deleting it. */
    public function deactivate(int $id): Link
    {
        return $this->update($id, new UpdateLink(['is_active' => false]));
    }

    public function delete(int $id): void
    {
        $this->transport->send('DELETE', "links/{$id}");
    }
}
