<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

/**
 * The workspace bundle from `GET /browser-extension/bootstrap`: websites,
 * domains, influencers, and accessible teams in one round trip.
 *
 * Useful for bootstrapping an adapter or a picker UI without three separate
 * list calls.
 */
final readonly class Workspace extends ApiObject
{
    /** @var list<Website> */
    public array $websites;

    /** @var list<Domain> */
    public array $domains;

    /** @var list<Influencer> */
    public array $influencers;

    /** @var list<array<string, mixed>> */
    public array $teams;

    /** @param array<string, mixed> $raw */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->websites = array_map(
            static fn (array $item): Website => new Website($item),
            self::rows($raw['websites'] ?? null),
        );
        $this->domains = array_map(
            static fn (array $item): Domain => new Domain($item),
            self::rows($raw['domains'] ?? null),
        );
        $this->influencers = array_map(
            static fn (array $item): Influencer => new Influencer($item),
            self::rows($raw['influencers'] ?? null),
        );
        $this->teams = self::rows($raw['teams'] ?? null) ?: self::rows($raw['accounts'] ?? null);
    }
}
