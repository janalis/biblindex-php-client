<?php

declare(strict_types=1);

namespace BiblIndex\Client;

/**
 * Shared per-response-tree cache keyed by normalized resource path.
 *
 * An object (not a plain array) so the client and every lazy wrapper spawned
 * from one response mutate the same store, ensuring a given API resource is
 * fetched at most once per response tree.
 *
 * @internal
 */
final class ResourceCache
{
    /** @var array<string, mixed> */
    private array $entries = [];

    public function has(string $resource): bool
    {
        return \array_key_exists($resource, $this->entries);
    }

    public function get(string $resource): mixed
    {
        return $this->entries[$resource] ?? null;
    }

    public function set(string $resource, mixed $value): void
    {
        $this->entries[$resource] = $value;
    }
}
