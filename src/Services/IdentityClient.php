<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Data\Identity;

final class IdentityClient extends AbstractClient
{
    public function me(): Identity
    {
        return new Identity($this->data($this->transport->send('GET', 'me')));
    }
}
