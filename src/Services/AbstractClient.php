<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use Closure;
use LnkFlow\Laravel\Contracts\Transport;
use LnkFlow\Laravel\Data\Page;

abstract class AbstractClient
{
    public function __construct(protected readonly Transport $transport) {}

    /**
     * Fetch one page and hand it a resolver so callers can walk the rest with
     * `->next()` or `->each()` instead of re-implementing paging.
     *
     * @template TItem
     *
     * @param  array<string, scalar|null>  $filters
     * @param  Closure(array<string, mixed>): TItem  $factory
     * @return Page<TItem>
     */
    protected function paginate(string $path, array $filters, Closure $factory): Page
    {
        $response = $this->transport->send('GET', $path, $filters);

        return new Page(
            array_map($factory, $response->collection()),
            $response->meta(),
            $response->links(),
            fn (int $page): Page => $this->paginate($path, [...$filters, 'page' => $page], $factory),
        );
    }
}
