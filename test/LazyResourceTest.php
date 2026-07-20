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

        static::assertSame(self::EXTRACT_RESOURCE, $resource->getResource());
        static::assertFalse($resource->isLoaded());

        static::assertCount(3, $resource);
        static::assertTrue($resource->isLoaded());
        static::assertSame(['@id', 'title', 'place'], \array_keys(\iterator_to_array($resource)));

        $resource['title'] = 'Updated';
        static::assertSame('Updated', $resource['title']);
        static::assertInstanceOf(LazyResource::class, $resource['place']);
        unset($resource['place']);
        static::assertFalse(isset($resource['place']));
        static::assertSame(['@id' => '/api/extracts/42', 'title' => 'Updated'], $resource->toArray());
        static::assertSame(1, $this->requestCount());
    }

    public function testReturnsSeedWhileAlreadyLoading(): void
    {
        $client = $this->makeClient();
        $resource = new LazyResource($client, self::EXTRACT_RESOURCE, new ResourceCache(), ['id' => 42]);

        $loading = new \ReflectionProperty(LazyResource::class, 'loading');
        $loading->setValue($resource, true);

        static::assertSame(['id' => 42], $resource->toArray());
        static::assertFalse($resource->isLoaded());
        static::assertSame(0, $this->requestCount());
    }

    public function testServesSeedIdentityKeysWithoutFetching(): void
    {
        $client = $this->makeClient();
        $resource = new LazyResource($client, self::EXTRACT_RESOURCE, new ResourceCache(), [
            '@id' => '/api/extracts/42',
            '@type' => 'Extract',
            'id' => 42,
        ]);

        static::assertSame('/api/extracts/42', $resource['@id']);
        static::assertSame('Extract', $resource['@type']);
        static::assertSame(42, $resource['id']);
        static::assertTrue(isset($resource['@id']));
        static::assertFalse($resource->isLoaded());
        static::assertSame(0, $this->requestCount());
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
            static::fail('Reading a hydra key should throw.');
        } catch (\OutOfBoundsException) {
        }

        try {
            $resource['hydra:totalItems'] = 2;
            static::fail('Writing a hydra key should throw.');
        } catch (\OutOfBoundsException) {
        }

        try {
            unset($resource['hydra:view']);
            static::fail('Unsetting a hydra key should throw.');
        } catch (\OutOfBoundsException) {
        }

        static::assertFalse(isset($resource['hydra:view']));

        $keys = \array_keys(\iterator_to_array($resource));
        static::assertNotContains('hydra:view', $keys);
        static::assertNotContains('hydra:totalItems', $keys);
        static::assertContains('@id', $keys);
        static::assertContains('@type', $keys);
        static::assertContains('@context', $keys);
        static::assertContains('title', $keys);

        static::assertCount(4, $resource);
        static::assertSame(0, $this->requestCount());
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

        static::assertSame('{"@id":"\/api\/extracts\/42","title":"Extract 42"}', \json_encode($resource));
        static::assertTrue($resource->isLoaded());
        static::assertSame(1, $this->requestCount());
    }
}
