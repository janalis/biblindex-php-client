<?php

declare(strict_types=1);

namespace BiblIndex\Client;

/**
 * Map-like proxy that fetches an API resource when its data is read.
 *
 * PHP equivalent of the Python client's MutableMapping proxy: keys are read
 * and written through array access, iteration yields key => value pairs, and
 * Hydra metadata keys ({@see ResourceClientInterface::HYDRA_KEYS}) are never
 * exposed. Identity keys (@id, @type, id) present in the seed captured from
 * the parent response are served without triggering a fetch.
 *
 * @implements \ArrayAccess<array-key, mixed>
 * @implements \IteratorAggregate<array-key, mixed>
 */
final class LazyResource implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    private const array SEED_KEYS = ['@id', '@type', 'id'];

    /** @var array<array-key, mixed> */
    private readonly array $seed;

    /** @var array<array-key, mixed>|null */
    private ?array $data = null;

    private bool $loading = false;

    /** @param array<array-key, mixed>|null $seed */
    public function __construct(
        private readonly ResourceClientInterface $client,
        private readonly string $resource,
        private readonly ResourceCache $cache,
        ?array $seed = null,
    ) {
        $this->seed = $seed ?? [];
    }

    /** Normalized API path represented by this lazy resource. */
    public function getResource(): string
    {
        return $this->resource;
    }

    /** Whether the resource data has already been fetched. */
    public function isLoaded(): bool
    {
        return $this->data !== null;
    }

    public function offsetExists(mixed $offset): bool
    {
        if (\in_array($offset, ResourceClientInterface::HYDRA_KEYS, strict: true)) {
            return false;
        }

        if (
            $this->data === null
            && \in_array($offset, self::SEED_KEYS, strict: true)
            && \array_key_exists($offset, $this->seed)
        ) {
            return true;
        }

        return \array_key_exists($offset, $this->load());
    }

    public function offsetGet(mixed $offset): mixed
    {
        $this->assertNotHydraKey($offset);

        if (
            $this->data === null
            && \in_array($offset, self::SEED_KEYS, strict: true)
            && \array_key_exists($offset, $this->seed)
        ) {
            return $this->seed[$offset];
        }

        $data = $this->load();
        if (!\array_key_exists($offset, $data)) {
            throw new \OutOfBoundsException(\sprintf('Undefined key "%s" on resource "%s".', $offset, $this->resource));
        }

        return $data[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            throw new \OutOfBoundsException('Lazy resources require explicit string keys.');
        }

        $this->assertNotHydraKey($offset);

        $this->load();
        if ($this->data !== null) {
            $this->data[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->assertNotHydraKey($offset);

        $this->load();
        if ($this->data !== null) {
            unset($this->data[$offset]);
        }
    }

    public function count(): int
    {
        return \count($this->toArray());
    }

    /** @return \Generator<array-key, mixed> */
    public function getIterator(): \Generator
    {
        foreach ($this->load() as $key => $value) {
            if (\in_array($key, ResourceClientInterface::HYDRA_KEYS, strict: true)) {
                continue;
            }

            yield $key => $value;
        }
    }

    /**
     * Loaded data with Hydra metadata keys filtered out. Triggers a fetch.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return \array_diff_key($this->load(), \array_flip(ResourceClientInterface::HYDRA_KEYS));
    }

    /** @return array<array-key, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<array-key, mixed> */
    private function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        // Re-entrancy guard: while the fetch triggered by this resource is
        // wrapping its own response, serve the seed instead of recursing.
        if ($this->loading) {
            return $this->seed;
        }

        $this->loading = true;
        try {
            $raw = $this->client->requestJson($this->resource, []);
            $wrapped = $this->client->wrapLinkedResources($raw, $this->resource, $this->cache);
        } finally {
            $this->loading = false;
        }

        if (!\is_array($wrapped)) {
            throw new \UnexpectedValueException(\sprintf(
                'Resource "%s" did not return a JSON object.',
                $this->resource,
            ));
        }

        return $this->data = $wrapped;
    }

    private function assertNotHydraKey(mixed $offset): void
    {
        if (\in_array($offset, ResourceClientInterface::HYDRA_KEYS, strict: true)) {
            throw new \OutOfBoundsException(\sprintf('Key "%s" is not exposed by lazy resources.', $offset));
        }
    }
}
