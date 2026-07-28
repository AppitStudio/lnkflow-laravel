<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Data\Page;
use LnkFlow\Laravel\Data\Resource;

final class WebsitesClient extends AbstractClient
{
    /**
     * @param  array<string, scalar|null>  $filters
     * @return Page<resource>
     */
    public function list(array $filters = []): Page
    {
        $payload = $this->transport->send('GET', 'websites', $filters);

        return new Page(
            array_map(fn (array $item): Resource => new Resource($item), $this->collection($payload)),
            is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            is_array($payload['links'] ?? null) ? $payload['links'] : [],
        );
    }

    public function get(int $id): Resource
    {
        return new Resource($this->data($this->transport->send('GET', "websites/{$id}")));
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Resource
    {
        return new Resource($this->data($this->transport->send('POST', 'websites', json: $attributes)));
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $id, array $attributes): Resource
    {
        return new Resource($this->data($this->transport->send('PATCH', "websites/{$id}", json: $attributes)));
    }
}
