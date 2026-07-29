<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

/**
 * One result from `GET /search` — the name-resolution primitive.
 *
 * Resolve names to IDs through this before any write, rather than hardcoding
 * website, influencer, or domain IDs in an integration.
 */
final readonly class SearchMatch extends ApiObject
{
    /** One of website, campaign, link, influencer, domain. Unknown values stay as-is. */
    public string $type;

    public int $id;

    public string $label;

    /** Type-specific detail; keys differ per type. */
    /** @var array<string, mixed> */
    public array $metadata;

    /** @param array<string, mixed> $raw */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->type = self::string($raw['type'] ?? null) ?? '';
        $this->id = self::int($raw['id'] ?? null) ?? 0;
        $this->label = self::string($raw['label'] ?? null) ?? '';
        $this->metadata = self::map($raw['metadata'] ?? null);
    }

    public function is(string $type): bool
    {
        return $this->type === $type;
    }
}
