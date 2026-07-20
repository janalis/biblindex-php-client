<?php

declare(strict_types=1);

namespace BiblIndex\Client;

use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * Doctrine collection that fetches following API pages on demand.
 *
 * Page-aware laziness on top of {@see AbstractLazyCollection}:
 *
 * - no fetch: count() when hydra:totalItems is known, getLoadedItems(),
 *   isEmpty() when an item is already loaded, clear();
 * - fetch only the needed pages: get()/offsetGet(), containsKey()/
 *   offsetExists(), first(), slice() with a non-null length, iteration;
 * - fetch all remaining pages (inherited initialize-first behavior):
 *   count() when the total is unknown, toArray(), map(), filter(),
 *   contains(), last(), matching(), and every mutator (add, set, remove,
 *   offsetSet, offsetUnset, ...).
 *
 * Divergences from the Python client: mutators initialize the collection
 * first instead of mutating the partially loaded window, and out-of-range
 * indices return null (Doctrine convention) instead of raising.
 *
 * @extends AbstractLazyCollection<int, mixed>
 */
final class LazyCollection extends AbstractLazyCollection
{
    /** @var list<mixed> */
    private array $items;

    private string $currentResource;

    private ?string $nextResource;

    /** @param list<mixed> $items */
    public function __construct(
        private readonly ResourceClientInterface $client,
        array $items,
        string $currentResource,
        ?string $nextResource,
        private ?int $totalItems,
        private readonly ResourceCache $cache,
    ) {
        $this->collection = null;
        $this->items = \array_values($items);
        $this->currentResource = $currentResource;
        $this->nextResource = $nextResource;
    }

    /** Number of items already loaded locally. */
    public function getLoadedItems(): int
    {
        return $this->isInitialized() ? $this->collection->count() : \count($this->items);
    }

    public function count(): int
    {
        if ($this->isInitialized()) {
            return parent::count();
        }

        if ($this->totalItems !== null) {
            return $this->totalItems;
        }

        return parent::count();
    }

    public function isEmpty(): bool
    {
        if (!$this->isInitialized() && $this->items !== []) {
            return false;
        }

        return $this->count() === 0;
    }

    public function get(string|int $key): mixed
    {
        if ($this->isInitialized()) {
            return parent::get($key);
        }

        if (!\is_int($key) || $key < 0) {
            return null;
        }

        $this->fetchUntilIndex($key);

        return $this->items[$key] ?? null;
    }

    public function containsKey(string|int $key): bool
    {
        if ($this->isInitialized()) {
            return parent::containsKey($key);
        }

        if (!\is_int($key) || $key < 0) {
            return false;
        }

        $this->fetchUntilIndex($key);

        return \array_key_exists($key, $this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        if ($this->isInitialized()) {
            return parent::offsetExists($offset);
        }

        return (\is_int($offset) || \is_string($offset)) && $this->containsKey($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if ($this->isInitialized()) {
            return parent::offsetGet($offset);
        }

        return \is_int($offset) || \is_string($offset) ? $this->get($offset) : null;
    }

    public function first(): mixed
    {
        if ($this->isInitialized()) {
            return parent::first();
        }

        $this->fetchUntilIndex(0);

        return $this->items === [] ? false : $this->items[0];
    }

    public function slice(int $offset, ?int $length = null): array
    {
        if (!$this->isInitialized() && $length !== null && $offset >= 0) {
            if ($length > 0) {
                $this->fetchUntilIndex($offset + $length - 1);
            }

            return \array_slice($this->items, $offset, $length);
        }

        return parent::slice($offset, $length);
    }

    public function getIterator(): \Traversable
    {
        if ($this->isInitialized()) {
            return parent::getIterator();
        }

        return $this->lazyIterator();
    }

    public function clear(): void
    {
        if ($this->isInitialized()) {
            parent::clear();

            return;
        }

        $this->items = [];
        $this->nextResource = null;
        $this->totalItems = null;
        $this->collection = new ArrayCollection();
        $this->initialized = true;
    }

    protected function doInitialize(): void
    {
        $this->fetchAllPages();
        $this->collection = new ArrayCollection($this->items);
    }

    /** @return \Generator<int, mixed> */
    private function lazyIterator(): \Generator
    {
        $index = 0;
        while (true) {
            if ($index < \count($this->items)) {
                yield $index => $this->items[$index];
                $index++;
                continue;
            }

            if (!$this->fetchNextPage()) {
                return;
            }
        }
    }

    private function fetchNextPage(): bool
    {
        if ($this->nextResource === null) {
            return false;
        }

        $nextResource = $this->nextResource;
        $page = $this->client->requestJson($nextResource, []);

        if (\is_array($page) && \array_is_list($page)) {
            if ($page === []) {
                $this->nextResource = null;

                return false;
            }

            $wrappedItems = $this->client->wrapLinkedResources($page, $nextResource, $this->cache);
            foreach ($wrappedItems as $item) {
                $this->items[] = $item;
            }
            $this->currentResource = $nextResource;
            $this->nextResource = $this->client->nextPlainJsonPageResource($nextResource);

            return true;
        }

        if (!\is_array($page)) {
            $this->nextResource = null;

            return false;
        }

        $members = $page['hydra:member'] ?? [];
        if (\is_array($members) && \array_is_list($members)) {
            $wrappedMembers = $this->client->wrapLinkedResources($members, $nextResource, $this->cache);
            foreach ($wrappedMembers as $member) {
                $this->items[] = $member;
            }
        }

        $this->currentResource = $nextResource;
        $this->nextResource = $this->client->nextPageResource($page);

        return true;
    }

    private function fetchUntilIndex(int $index): void
    {
        while ($index >= \count($this->items) && $this->fetchNextPage()) {
        }
    }

    private function fetchAllPages(): void
    {
        while ($this->fetchNextPage()) {
        }
    }
}
