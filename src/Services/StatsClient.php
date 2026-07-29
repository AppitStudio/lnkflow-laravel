<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Data\ConversionStats;
use LnkFlow\Laravel\Data\Resource;

final class StatsClient extends AbstractClient
{
    /** @param array<string, scalar|null> $filters */
    public function summary(array $filters = []): Resource
    {
        return $this->get('stats/summary', $filters);
    }

    /** @param array<string, scalar|null> $filters */
    public function breakdown(array $filters = []): Resource
    {
        return $this->get('stats/breakdown', $filters);
    }

    /** @param array<string, scalar|null> $filters */
    public function compare(array $filters = []): Resource
    {
        return $this->get('stats/compare', $filters);
    }

    /** @param array<string, scalar|null> $filters */
    public function influencers(array $filters = []): Resource
    {
        return $this->get('stats/influencers', $filters);
    }

    /** @param array<string, scalar|null> $filters */
    public function websites(array $filters = []): Resource
    {
        return $this->get('stats/websites', $filters);
    }

    /**
     * Conversion analytics.
     *
     * Check `->hasConversionData` before rendering: when it is false the
     * numbers are structural zeros, not measured zeros.
     *
     * @param  array<string, scalar|null>  $filters
     */
    public function conversions(array $filters = []): ConversionStats
    {
        $response = $this->transport->send('GET', 'stats/conversions', $filters);

        return new ConversionStats($response->data(), $response->meta());
    }

    /** @param array<string, scalar|null> $filters */
    public function campaign(int $id, array $filters = []): Resource
    {
        return $this->get("campaigns/{$id}/stats", $filters);
    }

    /** @param array<string, scalar|null> $filters */
    public function link(int $id, array $filters = []): Resource
    {
        return $this->get("links/{$id}/stats", $filters);
    }

    /** @param array<string, scalar|null> $filters */
    private function get(string $path, array $filters): Resource
    {
        return new Resource($this->transport->send('GET', $path, $filters)->data());
    }
}
