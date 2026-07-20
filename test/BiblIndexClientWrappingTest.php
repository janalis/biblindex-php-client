<?php

declare(strict_types=1);

namespace BiblIndex\Client\Test;

use BiblIndex\Client\BiblIndexClient;
use BiblIndex\Client\LazyCollection;
use BiblIndex\Client\LazyResource;
use BiblIndex\Client\ResourceCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

#[CoversClass(BiblIndexClient::class)]
#[UsesClass(LazyCollection::class)]
#[UsesClass(LazyResource::class)]
#[UsesClass(ResourceCache::class)]
final class BiblIndexClientWrappingTest extends TestCase
{
    use ClientFactoryTrait;

    private const string QUOTATION_URL = self::RESOURCE_URL . '/1229419';

    public function testRequestLazilyFetchesCollectionMembersFromResponseLinks(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse([
                '@context' => '/api/contexts/Quotation',
                '@id' => '/api/quotations',
                '@type' => 'hydra:Collection',
                'hydra:member' => [
                    '/api/quotations/1229419',
                    ['@id' => '/api/quotations/1229420', '@type' => 'Quotation'],
                ],
                'hydra:view' => ['@id' => '/api/quotations?page=1'],
            ]),
            new JsonMockResponse(['@id' => '/api/quotations/1229419', '@type' => 'Quotation', 'number' => 1229419]),
            new JsonMockResponse(['@id' => '/api/quotations/1229420', '@type' => 'Quotation', 'number' => 1229420]),
        ]);
        self::presetTokens($client);

        $result = $client->request(self::RESOURCE_PATH);

        self::assertInstanceOf(LazyCollection::class, $result);
        $firstMember = $result[0];
        $secondMember = $result[1];
        self::assertInstanceOf(LazyResource::class, $firstMember);
        self::assertInstanceOf(LazyResource::class, $secondMember);
        self::assertSame(1, $this->requestCount());

        self::assertSame(1229419, $firstMember['number']);
        self::assertSame('/api/quotations/1229420', $secondMember['@id']);
        self::assertSame(2, $this->requestCount());

        self::assertSame(1229420, $secondMember['number']);
        self::assertSame(3, $this->requestCount());
        self::assertSame(self::QUOTATION_URL, $this->requestUrl(1));
        self::assertSame(self::RESOURCE_URL . '/1229420', $this->requestUrl(2));
    }

    public function testRequestLazilyFetchesHydraPagesWhenAccessingLaterItems(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse([
                '@id' => '/api/quotations',
                '@type' => 'hydra:Collection',
                'hydra:member' => [['@id' => '/api/quotations/1229419', '@type' => 'Quotation']],
                'hydra:totalItems' => 2,
                'hydra:view' => [
                    '@id' => '/api/quotations?page=1',
                    '@type' => 'hydra:PartialCollectionView',
                    'hydra:next' => '/api/quotations?page=2',
                ],
            ]),
            new JsonMockResponse([
                '@id' => '/api/quotations',
                '@type' => 'hydra:Collection',
                'hydra:member' => [['@id' => '/api/quotations/1229420', '@type' => 'Quotation']],
                'hydra:totalItems' => 2,
                'hydra:view' => [
                    '@id' => '/api/quotations?page=2',
                    '@type' => 'hydra:PartialCollectionView',
                ],
            ]),
        ]);
        self::presetTokens($client);

        $result = $client->request(self::RESOURCE_PATH);

        self::assertInstanceOf(LazyCollection::class, $result);
        self::assertCount(2, $result);
        self::assertSame(1, $result->getLoadedItems());
        self::assertSame(1, $this->requestCount());

        self::assertSame('/api/quotations/1229420', $result[1]['@id']);
        self::assertSame(2, $result->getLoadedItems());
        self::assertSame(2, $this->requestCount());
        self::assertSame(self::RESOURCE_URL . '?page=2', $this->requestUrl(1));
    }

    public function testRequestLazilyFetchesCollectionMembersFromItemIds(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse([
                ['id' => 1229419, 'extract' => '/api/extracts/42'],
                ['id' => 1229420],
            ]),
            new JsonMockResponse(['id' => 1229419, 'extract' => '/api/extracts/42', 'number' => 1229419]),
            new JsonMockResponse(['id' => 1229420, 'number' => 1229420]),
        ]);
        self::presetTokens($client);

        $result = $client->request(self::RESOURCE_PATH);

        self::assertInstanceOf(LazyCollection::class, $result);
        $firstMember = $result[0];
        $secondMember = $result[1];
        self::assertInstanceOf(LazyResource::class, $firstMember);
        self::assertInstanceOf(LazyResource::class, $secondMember);
        self::assertSame(1, $this->requestCount());

        // The bare id is served from the seed without a fetch.
        self::assertSame(1229419, $firstMember['id']);
        self::assertSame(1, $this->requestCount());

        self::assertSame(1229419, $firstMember['number']);
        self::assertInstanceOf(LazyResource::class, $firstMember['extract']);
        self::assertSame(1229420, $secondMember['number']);
        self::assertSame(3, $this->requestCount());
        self::assertSame(self::QUOTATION_URL, $this->requestUrl(1));
        self::assertSame(self::RESOURCE_URL . '/1229420', $this->requestUrl(2));
    }

    public function testRequestLazilyFetchesPlainJsonCollectionPages(): void
    {
        $client = $this->makeClient(
            [
                new JsonMockResponse([
                    ['id' => 1229419],
                    ['id' => 1229420],
                ]),
                new JsonMockResponse([
                    ['id' => 1229421],
                ]),
            ],
            accept: 'application/json',
        );
        self::presetTokens($client);

        $result = $client->request(self::RESOURCE_PATH, ['page' => 1]);

        self::assertInstanceOf(LazyCollection::class, $result);
        self::assertSame(2, $result->getLoadedItems());
        self::assertSame(1, $this->requestCount());

        $thirdMember = $result[2];

        self::assertInstanceOf(LazyResource::class, $thirdMember);
        self::assertSame(1229421, $thirdMember['id']);
        self::assertSame(3, $result->getLoadedItems());
        self::assertSame(2, $this->requestCount());
        self::assertSame(self::RESOURCE_URL . '?page=2', $this->requestUrl(1));
        self::assertSame('application/json', $this->requestHeader(1, 'Accept'));
    }

    public function testRequestLazilyFetchesLinkedItemProperties(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse([
                '@id' => '/api/quotations/1229419',
                '@type' => 'Quotation',
                'extract' => '/api/extracts/42',
                'works' => [
                    '/api/works/1',
                    ['@id' => '/api/works/2', '@type' => 'Work'],
                ],
            ]),
            new JsonMockResponse(['@id' => '/api/extracts/42', '@type' => 'Extract', 'title' => 'Extract 42']),
            new JsonMockResponse(['@id' => '/api/works/1', '@type' => 'Work', 'title' => 'Work 1']),
            new JsonMockResponse(['@id' => '/api/works/2', '@type' => 'Work', 'title' => 'Work 2']),
        ]);
        self::presetTokens($client);

        $result = $client->request('/api/quotations/1229419');

        self::assertSame('/api/quotations/1229419', $result['@id']);
        self::assertInstanceOf(LazyResource::class, $result['extract']);
        self::assertInstanceOf(LazyResource::class, $result['works'][0]);
        self::assertInstanceOf(LazyResource::class, $result['works'][1]);
        self::assertSame(1, $this->requestCount());

        self::assertSame('Extract 42', $result['extract']['title']);
        self::assertSame(2, $this->requestCount());

        self::assertSame('Work 1', $result['works'][0]['title']);
        self::assertSame('/api/works/2', $result['works'][1]['@id']);
        self::assertSame(3, $this->requestCount());

        self::assertSame('Work 2', $result['works'][1]['title']);
        self::assertSame(4, $this->requestCount());
    }

    public function testRequestReusesCacheForRepeatedLinks(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse([
                '@id' => '/api/quotations/1229419',
                'extract' => '/api/extracts/42',
                'relatedExtract' => '/api/extracts/42',
            ]),
            new JsonMockResponse(['@id' => '/api/extracts/42', '@type' => 'Extract', 'title' => 'Extract 42']),
        ]);
        self::presetTokens($client);

        $result = $client->request('/api/quotations/1229419');

        self::assertSame($result['extract'], $result['relatedExtract']);
        self::assertSame(1, $this->requestCount());

        self::assertSame('Extract 42', $result['extract']['title']);
        self::assertSame(2, $this->requestCount());
        self::assertSame('Extract 42', $result['relatedExtract']['title']);
        self::assertSame(2, $this->requestCount());
    }

    public function testLinkClassificationThroughWrapping(): void
    {
        $client = $this->makeClient();
        $cache = new ResourceCache();
        $current = '/api/things';

        // Non-links pass through untouched.
        self::assertSame(42, $client->wrapLinkedResources(42, $current, $cache));
        self::assertSame(
            'https://other.example.com/api/things/1',
            $client->wrapLinkedResources('https://other.example.com/api/things/1', $current, $cache),
        );
        self::assertSame('not-a-resource', $client->wrapLinkedResources('not-a-resource', $current, $cache));
        self::assertSame('/api/token', $client->wrapLinkedResources('/api/token', $current, $cache));

        // Same-host absolute URLs and api/ relative paths become lazy resources.
        $absolute = $client->wrapLinkedResources(self::BASE_URL . '/api/things/1', $current, $cache);
        self::assertInstanceOf(LazyResource::class, $absolute);
        self::assertSame('/api/things/1', $absolute->getResource());

        $absoluteWithQuery = $client->wrapLinkedResources(self::BASE_URL . '/api/things/1?foo=bar', $current, $cache);
        self::assertInstanceOf(LazyResource::class, $absoluteWithQuery);
        self::assertSame('/api/things/1?foo=bar', $absoluteWithQuery->getResource());

        $relative = $client->wrapLinkedResources('api/things/2', $current, $cache);
        self::assertInstanceOf(LazyResource::class, $relative);
        self::assertSame('/api/things/2', $relative->getResource());
    }

    public function testNextPageResourceEdgeCases(): void
    {
        $client = $this->makeClient();

        self::assertNull($client->nextPageResource([]));
        self::assertNull($client->nextPageResource(['hydra:view' => 'not-a-map']));
        self::assertSame(
            '/api/things?page=2',
            $client->nextPageResource(['hydra:view' => ['hydra:next' => '/api/things?page=2']]),
        );
    }

    public function testNextPlainJsonPageResourceEdgeCases(): void
    {
        $client = $this->makeClient();

        self::assertNull($client->nextPlainJsonPageResource('/api/things'));
        self::assertNull($client->nextPlainJsonPageResource('/api/things?limit=10'));
        self::assertNull($client->nextPlainJsonPageResource('/api/things?page=abc'));
        self::assertSame(
            '/api/things?page=2&limit=10',
            $client->nextPlainJsonPageResource('/api/things?page=1&limit=10'),
        );
    }

    public function testCollectionItemIdInferenceEdgeCases(): void
    {
        $client = $this->makeClient();
        $cache = new ResourceCache();

        // No id, non-/api/ collection, and the item's own resource all pass through.
        self::assertSame(
            [['name' => 'no id']],
            $client->wrapLinkedResources([['name' => 'no id']], '/api/things', $cache),
        );
        self::assertSame(
            [['id' => 1]],
            $client->wrapLinkedResources([['id' => 1]], '/not-api/things', $cache),
        );
        self::assertSame(
            [['id' => 1]],
            $client->wrapLinkedResources([['id' => 1]], '/api/things/1', $cache),
        );

        $inferred = $client->wrapLinkedResources([['id' => 1]], '/api/things?page=1', $cache);
        self::assertInstanceOf(LazyResource::class, $inferred[0]);
        self::assertSame('/api/things/1', $inferred[0]->getResource());
    }

    public function testWrapLinkedResourcesReferenceBranches(): void
    {
        $client = $this->makeClient();
        $cache = new ResourceCache();

        self::assertSame(
            [['name' => 'not a resource']],
            $client->wrapLinkedResources([['name' => 'not a resource']], '/api/things', $cache),
        );
        self::assertSame(
            '/api/things/1',
            $client->wrapLinkedResources('/api/things/1', '/api/things/1', $cache),
        );
        self::assertInstanceOf(
            LazyResource::class,
            $client->wrapLinkedResources('/api/things/2', '/api/things/1', $cache),
        );

        // A property referencing the current resource stays a raw string.
        self::assertSame(
            ['self' => '/api/things/1'],
            $client->wrapLinkedResources(['self' => '/api/things/1'], '/api/things/1', $cache),
        );

        // An embedded mapping with a foreign @id becomes a seeded lazy resource.
        $wrappedLinkedMapping = $client->wrapLinkedResources(
            ['place' => ['@id' => '/api/places/1', 'name' => 'Paris']],
            '/api/things/1',
            $cache,
        );
        self::assertInstanceOf(LazyResource::class, $wrappedLinkedMapping['place']);
        self::assertSame('/api/places/1', $wrappedLinkedMapping['place']['@id']);

        // An embedded mapping whose @id is the current resource stays inline.
        self::assertSame(
            ['place' => ['@id' => '/api/things/1', 'name' => 'Current']],
            $client->wrapLinkedResources(
                ['place' => ['@id' => '/api/things/1', 'name' => 'Current']],
                '/api/things/1',
                $cache,
            ),
        );
    }

    public function testWrapLinkedResourcesSkipsHydraKeysOnItems(): void
    {
        $client = $this->makeClient();

        $wrapped = $client->wrapLinkedResources(
            ['hydra:view' => ['@id' => '/api/things?page=1'], 'title' => 'foo'],
            '/api/things',
            new ResourceCache(),
        );

        self::assertIsArray($wrapped);
        self::assertArrayNotHasKey('hydra:view', $wrapped);
        self::assertSame('foo', $wrapped['title']);
    }
}
