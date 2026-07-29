<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use Closure;
use Countable;
use Generator;
use IteratorAggregate;
use Traversable;

/**
 * One page of a paginated LnkFlow collection.
 *
 * `meta` and `links` are preserved verbatim, including keys this SDK version
 * does not understand. Iterating a page walks the current page only; use
 * {@see each()} to walk every remaining page lazily.
 *
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 */
final readonly class Page implements Countable, IteratorAggregate
{
    /**
     * @param  list<T>  $data
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $links
     * @param  (Closure(int): Page<T>)|null  $resolver  fetches another page by number
     */
    public function __construct(
        public array $data,
        public array $meta = [],
        public array $links = [],
        private ?Closure $resolver = null,
    ) {}

    public function currentPage(): int
    {
        return is_numeric($this->meta['current_page'] ?? null) ? (int) $this->meta['current_page'] : 1;
    }

    public function lastPage(): ?int
    {
        return is_numeric($this->meta['last_page'] ?? null) ? (int) $this->meta['last_page'] : null;
    }

    public function total(): ?int
    {
        return is_numeric($this->meta['total'] ?? null) ? (int) $this->meta['total'] : null;
    }

    public function hasMorePages(): bool
    {
        if (array_key_exists('next', $this->links)) {
            $next = $this->links['next'];

            return is_string($next) && $next !== '';
        }

        $last = $this->lastPage();

        return $last !== null && $this->currentPage() < $last;
    }

    /**
     * The next page, or null at the end of the collection (or when this page
     * came from somewhere that cannot fetch more).
     *
     * @return self<T>|null
     */
    public function next(): ?self
    {
        if ($this->resolver === null || ! $this->hasMorePages()) {
            return null;
        }

        return ($this->resolver)($this->currentPage() + 1);
    }

    /**
     * Lazily walk every item from this page onwards, fetching pages as needed.
     *
     * @return Generator<int, T>
     */
    public function each(): Generator
    {
        $page = $this;

        while ($page instanceof self) {
            foreach ($page->data as $item) {
                yield $item;
            }

            $page = $page->next();
        }
    }

    /** @return Traversable<int, T> */
    public function getIterator(): Traversable
    {
        yield from $this->data;
    }

    public function count(): int
    {
        return count($this->data);
    }
}
