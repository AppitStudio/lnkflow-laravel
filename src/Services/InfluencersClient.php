<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Data\Commission;
use LnkFlow\Laravel\Data\CreateInfluencer;
use LnkFlow\Laravel\Data\Influencer;
use LnkFlow\Laravel\Data\Page;
use LnkFlow\Laravel\Data\UpdateInfluencer;

final class InfluencersClient extends AbstractClient
{
    /**
     * @param  array<string, scalar|null>  $filters
     * @return Page<Influencer>
     */
    public function list(array $filters = []): Page
    {
        return $this->paginate('influencers', $filters, static fn (array $item): Influencer => new Influencer($item));
    }

    public function get(int $id): Influencer
    {
        return new Influencer($this->transport->send('GET', "influencers/{$id}")->data());
    }

    public function create(CreateInfluencer $request): Influencer
    {
        return new Influencer($this->transport->send('POST', 'influencers', json: $request->toArray())->data());
    }

    public function update(int $id, UpdateInfluencer $request): Influencer
    {
        return new Influencer(
            $this->transport->send('PATCH', "influencers/{$id}", json: $request->toArray())->data(),
        );
    }

    /**
     * The influencer's commission ledger.
     *
     * Reporting only: these rows record what a commission rule computed.
     * LnkFlow never moves money, so nothing here is a payout.
     *
     * @param  array<string, scalar|null>  $filters  status, from, to, per_page
     * @return Page<Commission>
     */
    public function commissions(int $id, array $filters = []): Page
    {
        return $this->paginate(
            "influencers/{$id}/commissions",
            $filters,
            static fn (array $item): Commission => new Commission($item),
        );
    }

    /**
     * The same ledger as raw CSV. Returns the response body verbatim, so it can
     * be streamed straight to a download.
     *
     * @param  array<string, scalar|null>  $filters
     */
    public function commissionsCsv(int $id, array $filters = []): string
    {
        return $this->transport->send(
            'GET',
            "influencers/{$id}/commissions",
            [...$filters, 'export' => 'csv'],
        )->contents;
    }
}
