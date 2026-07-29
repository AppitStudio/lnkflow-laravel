<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Data\Identity;

final class IdentityClient extends AbstractClient
{
    public function me(): Identity
    {
        return new Identity($this->transport->send('GET', 'me')->data());
    }
}
