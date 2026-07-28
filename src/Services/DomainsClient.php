<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Data\Resource;

final class DomainsClient extends AbstractClient
{
    /** @return list<resource> */
    public function list(bool $usable = false): array
    {
        return array_map(
            fn (array $item): Resource => new Resource($item),
            $this->collection($this->transport->send('GET', 'domains', ['usable' => $usable])),
        );
    }
}
