<?php

declare(strict_types=1);

namespace BiblIndex\Client\Test;

use BiblIndex\Client\ResourceCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResourceCache::class)]
final class ResourceCacheTest extends TestCase
{
    public function testHasGetSetRoundTrip(): void
    {
        $cache = new ResourceCache();

        self::assertFalse($cache->has('/api/things/1'));
        self::assertNull($cache->get('/api/things/1'));

        $cache->set('/api/things/1', ['id' => 1]);
        self::assertTrue($cache->has('/api/things/1'));
        self::assertSame(['id' => 1], $cache->get('/api/things/1'));

        $cache->set('/api/things/1', null);
        self::assertTrue($cache->has('/api/things/1'));
        self::assertNull($cache->get('/api/things/1'));
    }

    public function testGetReturnsStoredIdentity(): void
    {
        $cache = new ResourceCache();
        $value = new \stdClass();

        $cache->set('/api/things/1', $value);

        self::assertSame($value, $cache->get('/api/things/1'));
    }
}
