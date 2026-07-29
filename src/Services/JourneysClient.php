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
        return $this->post(
            'journeys/touchpoints',
            $touchpoint->toArray(),
            $touchpoint->visitorId.':'.$touchpoint->clickId,
        );
    }

    /** Bind the current browser to a stable, opaque customer id. */
    public function identify(IdentityChange $identity): Resource
    {
        return $this->post(
            'journeys/identify',
            $identity->toArray(),
            $identity->visitorId.':'.$identity->customerExternalId,
        );
    }

    /** Close the active browser-to-customer binding. This is logout. */
    public function unidentify(Visitor $visitor): Resource
    {
        return $this->post('journeys/unidentify', $visitor->toArray(), $visitor->visitorId);
    }

    /** Withdraw consent. Semantically separate from logout. */
    public function revoke(Visitor $visitor): Resource
    {
        return $this->post('journeys/revoke', $visitor->toArray(), $visitor->visitorId);
    }

    /** @param array<string, mixed> $json */
    private function post(string $path, array $json, string $stableBusinessKey): Resource
    {
        $response = $this->transport->send('POST', $path, json: $json, stableBusinessKey: $stableBusinessKey);

        return new Resource($response->data(), $response);
    }
}
