<?php

declare(strict_types=1);

namespace BiblIndex\Client\Test;

use BiblIndex\Client\BiblIndexClient;
use BiblIndex\Client\LazyCollection;
use BiblIndex\Client\LazyResource;
use BiblIndex\Client\ResourceCache;
use Doctrine\Common\Collections\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

#[CoversClass(LazyCollection::class)]
#[UsesClass(BiblIndexClient::class)]
#[UsesClass(LazyResource::class)]
#[UsesClass(ResourceCache::class)]
final class LazyCollectionTest extends TestCase
{
    use ClientFactoryTrait;

    /** @param list<mixed> $items */
    private function makeCollection(
        BiblIndexClient $client,
        array $items,
        string $currentResource = '/api/things?page=1',
        ?string $nextResource = '/api/things?page=2',
        ?int $totalItems = null,
    ): LazyCollection {
        return new LazyCollection($client, $items, $currentResource, $nextResource, $totalItems, new ResourceCache());
    }

    /** @return list<mixed> Items with lazy resources replaced by their id. */
    private static function ids(iterable $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = $item instanceof LazyResource ? $item['id'] : $item;
        }

        return $ids;
    }

    public function testOffsetGetFetchesPagesOnDemand(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse([['id' => 3]]),
            new JsonMockResponse([]),
        ]);
        self::presetTokens($client);
        $collection = $this->makeCollection($client, [1, 2]);

        static::assertSame(1, $collection[0]);
        static::assertSame([1], $collection->slice(0, 1));
        static::assertSame([], $collection->slice(0, 0));
        static::assertSame(0, $this->requestCount());

        static::assertInstanceOf(LazyResource::class, $collection[2]);
        static::assertSame(3, $collection[2]['id']);
        static::assertSame(3, $collection->getLoadedItems());
        static::assertSame(1, $this->requestCount());
        static::assertSame(self::BASE_URL . '/api/things?page=2', $this->requestUrl(0));

        static::assertSame([1, 2, 3], self::ids($collection));
        static::assertSame(2, $this->requestCount());
    }

    public function testMutatorsInitializeThenMutate(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse([['id' => 3]]),
            new JsonMockResponse([]),
        ]);
        self::presetTokens($client);
        $collection = $this->makeCollection($client, [1, 2]);

        // Divergence from the Python client: mutating fetches all remaining
        // pages first so indices are unambiguous.
        $collection->set(0, 10);
        static::assertSame(2, $this->requestCount());
        static::assertTrue($collection->isInitialized());

        $collection->add(11);
        $removed = $collection->remove(2);
        static::assertInstanceOf(LazyResource::class, $removed);
        static::assertSame([10, 2, 11], $collection->getValues());
        static::assertCount(3, $collection);
        static::assertSame(2, $this->requestCount());
    }

    public function testIterationAndOpenSliceFetchAllPages(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse([['id' => 2]]),
            new JsonMockResponse([]),
            new JsonMockResponse([['id' => 20]]),
            new JsonMockResponse([]),
        ]);
        self::presetTokens($client);

        $collection = $this->makeCollection($client, [1]);
        static::assertSame([1, 2], self::ids($collection));
        static::assertSame(2, $this->requestCount());

        $openSlice = $collection->slice(0);
        static::assertSame(1, $openSlice[0]);
        static::assertInstanceOf(LazyResource::class, $openSlice[1]);
        static::assertSame(2, $this->requestCount());

        $otherCollection = $this->makeCollection($client, [10], '/api/more-things?page=1', '/api/more-things?page=2');
        static::assertSame([10, 20], self::ids($otherCollection->toArray()));
        static::assertSame(4, $this->requestCount());
    }

    public function testReturnsNullWhenNextPageIsNotACollection(): void
    {
        $client = $this->makeClient([new JsonMockResponse(['items' => []])]);
        self::presetTokens($client);
        $collection = $this->makeCollection($client, [1]);

        // Divergence from the Python client (IndexError): out-of-range
        // indices follow the Doctrine convention and return null.
        static::assertNull($collection[1]);
        static::assertFalse($collection->containsKey(1));
        static::assertSame(1, $this->requestCount());
    }

    public function testFollowsHydraNextWhenPageHasNoMembers(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse([
                'hydra:member' => null,
                'hydra:view' => ['hydra:next' => '/api/things?page=3'],
            ]),
            new JsonMockResponse(['hydra:member' => [['@id' => '/api/things/2']]]),
        ]);
        self::presetTokens($client);
        $collection = $this->makeCollection($client, [1]);

        static::assertSame('/api/things/2', $collection[1]['@id']);
        static::assertSame(2, $this->requestCount());
    }

    public function testStopsOnScalarNextPage(): void
    {
        $client = $this->makeClient([new JsonMockResponse('not a collection')]);
        self::presetTokens($client);
        $collection = $this->makeCollection($client, [1]);

        static::assertSame([1], \iterator_to_array($collection));
        static::assertSame(1, $this->requestCount());
    }

    public function testCountReturnsTotalItemsWithoutFetching(): void
    {
        $client = $this->makeClient();
        $collection = $this->makeCollection($client, [1], totalItems: 5);

        static::assertCount(5, $collection);
        static::assertFalse($collection->isEmpty());
        static::assertSame(1, $collection->getLoadedItems());
        static::assertSame(0, $this->requestCount());
    }

    public function testCountFetchesAllPagesWhenTotalUnknown(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse([2]),
            new JsonMockResponse([]),
        ]);
        self::presetTokens($client);
        $collection = $this->makeCollection($client, [1]);

        static::assertCount(2, $collection);
        static::assertSame(2, $this->requestCount());
    }

    public function testFirstFetchesOnlyTheNeededPage(): void
    {
        $client = $this->makeClient();
        $collection = $this->makeCollection($client, [1]);
        static::assertSame(1, $collection->first());
        static::assertSame(0, $this->requestCount());

        $clientWithPage = $this->makeClient([new JsonMockResponse([5])]);
        self::presetTokens($clientWithPage);
        $emptyFirstPage = $this->makeCollection($clientWithPage, []);
        static::assertSame(5, $emptyFirstPage->first());
        static::assertSame(1, $this->requestCount());
    }

    public function testWholeCollectionOperationsFetchAllPages(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse([2]),
            new JsonMockResponse([]),
        ]);
        self::presetTokens($client);
        $collection = $this->makeCollection($client, [1]);

        static::assertSame(2, $collection->last());
        static::assertSame(2, $this->requestCount());

        static::assertTrue($collection->contains(2));
        static::assertSame([2, 4], $collection->map(static fn(int $item): int => $item * 2)->getValues());
        static::assertSame([1], $collection->filter(static fn(int $item): bool => ($item % 2) === 1)->getValues());
        static::assertSame(2, $this->requestCount());
    }

    public function testIsEmptyDoesNotFetchWhenAnItemIsLoaded(): void
    {
        $client = $this->makeClient();

        static::assertFalse($this->makeCollection($client, [1])->isEmpty());
        static::assertTrue($this->makeCollection($client, [], totalItems: 0)->isEmpty());
        static::assertSame(0, $this->requestCount());
    }

    public function testClearDropsStateWithoutFetching(): void
    {
        $client = $this->makeClient();
        $collection = $this->makeCollection($client, [1]);

        $collection->clear();

        static::assertCount(0, $collection);
        static::assertSame([], $collection->toArray());
        static::assertTrue($collection->isEmpty());
        static::assertSame(0, $this->requestCount());
    }

    public function testImplementsDoctrineCollection(): void
    {
        $collection = $this->makeCollection($this->makeClient(), []);

        static::assertInstanceOf(Collection::class, $collection);
    }
}
