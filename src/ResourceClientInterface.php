<?php

declare(strict_types=1);

namespace BiblIndex\Client;

/**
 * Client behavior required by lazy resources and collections.
 *
 * @internal Contract between {@see BiblIndexClient} and the lazy wrappers.
 */
interface ResourceClientInterface
{
    /**
     * Hydra metadata keys hidden from lazy wrappers; collections are exposed
     * as {@see LazyCollection} instances instead.
     */
    public const array HYDRA_KEYS = [
        'hydra:member',
        'hydra:view',
        'hydra:search',
        'hydra:totalItems',
    ];

    /**
     * Perform an authenticated GET request and return the decoded JSON body.
     *
     * @param array<string, mixed> $params
     */
    public function requestJson(string $resource, array $params): mixed;

    /**
     * Wrap API links embedded in a response body with lazy resources.
     */
    public function wrapLinkedResources(mixed $data, string $currentResource, ResourceCache $cache): mixed;

    /**
     * Resolve the next page of a Hydra collection from its hydra:view link.
     *
     * @param array<array-key, mixed> $data
     */
    public function nextPageResource(array $data): ?string;

    /**
     * Resolve the next page of a plain JSON collection by incrementing ?page=N.
     */
    public function nextPlainJsonPageResource(string $resource): ?string;
}
