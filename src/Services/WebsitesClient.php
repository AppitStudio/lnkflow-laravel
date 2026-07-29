<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Data\CreateWebsite;
use LnkFlow\Laravel\Data\Page;
use LnkFlow\Laravel\Data\UpdateWebsite;
use LnkFlow\Laravel\Data\Website;

final class WebsitesClient extends AbstractClient
{
    /**
     * @param  array<string, scalar|null>  $filters
     * @return Page<Website>
     */
    public function list(array $filters = []): Page
    {
        return $this->paginate('websites', $filters, static fn (array $item): Website => new Website($item));
    }

    public function get(int $id): Website
    {
        return new Website($this->transport->send('GET', "websites/{$id}")->data());
    }

    public function create(CreateWebsite $request): Website
    {
        return new Website($this->transport->send('POST', 'websites', json: $request->toArray())->data());
    }

    public function update(int $id, UpdateWebsite $request): Website
    {
        return new Website($this->transport->send('PATCH', "websites/{$id}", json: $request->toArray())->data());
    }
}
