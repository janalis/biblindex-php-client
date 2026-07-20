<?php

declare(strict_types=1);

namespace BiblIndex\Client\Test;

use BiblIndex\Client\BiblIndexClient;
use BiblIndex\Client\LazyResource;
use BiblIndex\Client\ResourceCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

#[CoversClass(LazyResource::class)]
#[UsesClass(BiblIndexClient::class)]
#[UsesClass(ResourceCache::class)]
final class LazyResourceTest extends TestCase
{
    use ClientFactoryTrait;

    private const string EXTRACT_RESOURCE = '/api/extracts/42';

    public function testBehavesLikeMapAfterLoading(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse([
                '@id' => '/api/extracts/42',
                'title' => 'Extract 42',
                'place' => '/api/places/1',
            ]),
        ]);
        self::presetTokens($client);

        $resource = new LazyResource($client, self::EXTRACT_RESOURCE, new ResourceCache());

        self::assertSame(self::EXTRACT_RESOURCE, $resource->getResource());
        self::assertFalse($resource->isLoaded());

        self::assertCount(3, $resource);
        self::assertTrue($resource->isLoaded());
        self::assertSame(['@id', 'title', 'place'], \array_keys(\iterator_to_array($resource)));

        $resource['title'] = 'Updated';
        self::assertSame('Updated', $resource['title']);
        self::assertInstanceOf(LazyResource::class, $resource['place']);
        unset($resource['place']);
        self::assertFalse(isset($resource['place']));
        self::assertSame(['@id' => '/api/extracts/42', 'title' => 'Updated'], $resource->toArray());
        self::assertSame(1, $this->requestCount());
    }

    public function testReturnsSeedWhileAlreadyLoading(): void
    {
        $client = $this->makeClient();
        $resource = new LazyResource($client, self::EXTRACT_RESOURCE, new ResourceCache(), ['id' => 42]);

        $loading = new \ReflectionProperty(LazyResource::class, 'loading');
        $loading->setValue($resource, true);

        self::assertSame(['id' => 42], $resource->toArray());
        self::assertFalse($resource->isLoaded());
        self::assertSame(0, $this->requestCount());
    }

    public function testServesSeedIdentityKeysWithoutFetching(): void
    {
        $client = $this->makeClient();
        $resource = new LazyResource(
            $client,
            self::EXTRACT_RESOURCE,
            new ResourceCache(),
            ['@id' => '/api/extracts/42', '@type' => 'Extract', 'id' => 42],
        );

        self::assertSame('/api/extracts/42', $resource['@id']);
        self::assertSame('Extract', $resource['@type']);
        self::assertSame(42, $resource['id']);
        self::assertTrue(isset($resource['@id']));
        self::assertFalse($resource->isLoaded());
        self::assertSame(0, $this->requestCount());
    }

    public function testBlocksHydraKeys(): void
    {
        $client = $this->makeClient();
        $resource = new LazyResource($client, self::EXTRACT_RESOURCE, new ResourceCache());

        $data = new \ReflectionProperty(LazyResource::class, 'data');
        $data->setValue($resource, [
            '@id' => '/api/extracts/42',
            '@type' => 'Extract',
            '@context' => '/api/contexts/Extract',
            'title' => 'Extract 42',
            'hydra:view' => ['@id' => '/api/extracts/42'],
            'hydra:totalItems' => 1,
        ]);

        try {
            $resource['hydra:view'];
            self::fail('Reading a hydra key should throw.');
        } catch (\OutOfBoundsException) {
        }

        try {
            $resource['hydra:totalItems'] = 2;
            self::fail('Writing a hydra key should throw.');
        } catch (\OutOfBoundsException) {
        }

        try {
            unset($resource['hydra:view']);
            self::fail('Unsetting a hydra key should throw.');
        } catch (\OutOfBoundsException) {
        }

        self::assertFalse(isset($resource['hydra:view']));

        $keys = \array_keys(\iterator_to_array($resource));
        self::assertNotContains('hydra:view', $keys);
        self::assertNotContains('hydra:totalItems', $keys);
        self::assertContains('@id', $keys);
        self::assertContains('@type', $keys);
        self::assertContains('@context', $keys);
        self::assertContains('title', $keys);

        self::assertCount(4, $resource);
        self::assertSame(0, $this->requestCount());
    }

    public function testOffsetGetThrowsOnMissingKey(): void
    {
        $client = $this->makeClient([new JsonMockResponse(['title' => 'Extract 42'])]);
        self::presetTokens($client);
        $resource = new LazyResource($client, self::EXTRACT_RESOURCE, new ResourceCache());

        $this->expectException(\OutOfBoundsException::class);
        $resource['nope'];
    }

    public function testOffsetSetRequiresExplicitKey(): void
    {
        $client = $this->makeClient();
        $resource = new LazyResource($client, self::EXTRACT_RESOURCE, new ResourceCache());

        $this->expectException(\OutOfBoundsException::class);
        $resource[] = 'value';
    }

    public function testJsonSerializeTriggersLoadAndFiltersHydraKeys(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse(['@id' => '/api/extracts/42', 'title' => 'Extract 42']),
        ]);
        self::presetTokens($client);
        $resource = new LazyResource($client, self::EXTRACT_RESOURCE, new ResourceCache());

        self::assertSame(
            '{"@id":"\/api\/extracts\/42","title":"Extract 42"}',
            \json_encode($resource),
        );
        self::assertTrue($resource->isLoaded());
        self::assertSame(1, $this->requestCount());
    }
}
