<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Data\Domain;

final class DomainsClient extends AbstractClient
{
    /**
     * @param  bool  $usable  restrict to domains that can actually serve links today
     * @return list<Domain>
     */
    public function list(bool $usable = false): array
    {
        return array_map(
            static fn (array $item): Domain => new Domain($item),
            $this->transport->send('GET', 'domains', ['usable' => $usable])->collection(),
        );
    }
}
