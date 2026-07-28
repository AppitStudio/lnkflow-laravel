<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Data\IdentityChange;
use LnkFlow\Laravel\Data\Resource;
use LnkFlow\Laravel\Data\Touchpoint;
use LnkFlow\Laravel\Data\Visitor;

final class JourneysClient extends AbstractClient
{
    public function capture(Touchpoint $touchpoint): Resource
    {
        return new Resource($this->data($this->transport->send(
            'POST',
            'journeys/touchpoints',
            json: $touchpoint->toArray(),
            stableBusinessKey: $touchpoint->visitorId.':'.$touchpoint->clickId,
        )));
    }

    public function identify(IdentityChange $identity): Resource
    {
        return new Resource($this->data($this->transport->send(
            'POST',
            'journeys/identify',
            json: $identity->toArray(),
            stableBusinessKey: $identity->visitorId.':'.$identity->customerExternalId,
        )));
    }

    public function unidentify(Visitor $visitor): Resource
    {
        return new Resource($this->data($this->transport->send(
            'POST',
            'journeys/unidentify',
            json: $visitor->toArray(),
            stableBusinessKey: $visitor->visitorId,
        )));
    }

    public function revoke(Visitor $visitor): Resource
    {
        return new Resource($this->data($this->transport->send(
            'POST',
            'journeys/revoke',
            json: $visitor->toArray(),
            stableBusinessKey: $visitor->visitorId,
        )));
    }
}
