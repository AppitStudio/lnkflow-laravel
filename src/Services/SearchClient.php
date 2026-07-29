<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Data\SearchMatch;

/**
 * Name resolution across websites, campaigns, links, influencers, and domains.
 *
 * Resolve a name to an ID here before writing, rather than hardcoding IDs in an
 * integration — IDs differ per team and per environment.
 */
final class SearchClient extends AbstractClient
{
    /** @var list<string> */
    public const TYPES = ['website', 'campaign', 'link', 'influencer', 'domain'];

    /**
     * @param  list<string>  $types  restrict the search; defaults to all types
     * @return list<SearchMatch>
     */
    public function query(string $query, array $types = [], int $limit = 10): array
    {
        return array_map(
            static fn (array $item): SearchMatch => new SearchMatch($item),
            $this->transport->send('GET', 'search', array_filter([
                'q' => $query,
                'types' => $types === [] ? null : implode(',', $types),
                'limit' => $limit,
            ], static fn (mixed $value): bool => $value !== null))->collection(),
        );
    }

    /**
     * The single best match of one type, or null.
     *
     * Convenience for the common "resolve this website name to an id" step.
     */
    public function first(string $query, string $type): ?SearchMatch
    {
        return $this->query($query, [$type], 1)[0] ?? null;
    }
}
