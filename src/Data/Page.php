<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

/** @template T */
final readonly class Page
{
    /**
     * @param  list<T>  $data
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $links
     */
    public function __construct(
        public array $data,
        public array $meta = [],
        public array $links = [],
    ) {}
}
