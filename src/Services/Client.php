<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Contracts\Transport;

class Client
{
    public function __construct(protected readonly Transport $transport) {}

    public function connection(string $connection): self
    {
        return new self($this->transport->forConnection($connection));
    }

    public function forTeam(int|string|null $team): self
    {
        return new self($this->transport->forTeam($team));
    }

    public function identity(): IdentityClient
    {
        return new IdentityClient($this->transport);
    }

    public function campaigns(): CampaignsClient
    {
        return new CampaignsClient($this->transport->forPurpose('links'));
    }

    public function links(): LinksClient
    {
        return new LinksClient($this->transport->forPurpose('links'));
    }

    public function websites(): WebsitesClient
    {
        return new WebsitesClient($this->transport->forPurpose('links'));
    }

    public function domains(): DomainsClient
    {
        return new DomainsClient($this->transport->forPurpose('links'));
    }

    public function influencers(): InfluencersClient
    {
        return new InfluencersClient($this->transport->forPurpose('links'));
    }

    public function search(): SearchClient
    {
        return new SearchClient($this->transport);
    }

    public function workspace(): WorkspaceClient
    {
        return new WorkspaceClient($this->transport);
    }

    public function stats(): StatsClient
    {
        return new StatsClient($this->transport);
    }

    public function journeys(): JourneysClient
    {
        return new JourneysClient($this->transport->forPurpose('journeys'));
    }

    public function conversions(): ConversionsClient
    {
        return new ConversionsClient($this->transport->forPurpose('conversions'));
    }
}
