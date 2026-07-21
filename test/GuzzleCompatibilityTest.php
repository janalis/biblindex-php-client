<?php

declare(strict_types=1);

namespace BiblIndex\Client\Test;

use BiblIndex\Client\BiblIndexClient;
use BiblIndex\Client\Exception\ClientErrorException;
use BiblIndex\Client\Exception\HttpException;
use BiblIndex\Client\LazyCollection;
use BiblIndex\Client\LazyResource;
use BiblIndex\Client\ResourceCache;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Proves the client works against a second, real PSR-18 implementation
 * (Guzzle) with nyholm/psr7 factories — no Symfony component involved.
 */
#[CoversClass(BiblIndexClient::class)]
#[UsesClass(ClientErrorException::class)]
#[UsesClass(HttpException::class)]
#[UsesClass(LazyCollection::class)]
#[UsesClass(LazyResource::class)]
#[UsesClass(ResourceCache::class)]
final class GuzzleCompatibilityTest extends TestCase
{
    private const string BASE_URL = 'https://api.example.com';

    /** @var array<array-key, mixed> Guzzle history-middleware transactions. */
    private array $history = [];

    /** @param list<Response> $responses */
    private function makeGuzzleClient(array $responses, int $retries = 0): BiblIndexClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        // Guzzle's history() stub widens the by-ref container to
        // array|ArrayAccess; here it is always the array property.
        // @mago-ignore analysis:invalid-property-assignment-value
        $stack->push(Middleware::history($this->history));
        $psr17 = new Psr17Factory();

        return new BiblIndexClient(
            self::BASE_URL,
            'user',
            'pass',
            'id',
            'secret',
            retries: $retries,
            httpClient: new Client(['handler' => $stack]),
            requestFactory: $psr17,
            streamFactory: $psr17,
        );
    }

    private static function jsonResponse(mixed $data, int $status = 200): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/ld+json'],
            \json_encode($data, flags: \JSON_THROW_ON_ERROR),
        );
    }

    private function sentRequest(int $index): RequestInterface
    {
        /** @var RequestInterface $request */
        $request = $this->history[$index]['request'];

        return $request;
    }

    public function testAuthenticatesWithPasswordGrantThenGets(): void
    {
        $client = $this->makeGuzzleClient([
            self::jsonResponse(['access_token' => 'A1', 'refresh_token' => 'R1', 'expires_in' => 3600]),
            self::jsonResponse(['ok' => true]),
        ]);

        $result = $client->request('/api/quotations', ['page' => 1]);

        static::assertSame(['ok' => true], $result);
        static::assertCount(2, $this->history);

        $tokenRequest = $this->sentRequest(0);
        static::assertSame('POST', $tokenRequest->getMethod());
        static::assertSame(self::BASE_URL . '/api/token', (string) $tokenRequest->getUri());
        static::assertSame('application/x-www-form-urlencoded', $tokenRequest->getHeaderLine('Content-Type'));
        $form = [];
        \parse_str((string) $tokenRequest->getBody(), $form);
        static::assertSame(
            [
                'grant_type' => 'password',
                'username' => 'user',
                'password' => 'pass',
                'client_id' => 'id',
                'client_secret' => 'secret',
            ],
            $form,
        );

        $getRequest = $this->sentRequest(1);
        static::assertSame('GET', $getRequest->getMethod());
        static::assertSame(self::BASE_URL . '/api/quotations?page=1', (string) $getRequest->getUri());
        static::assertSame('Bearer A1', $getRequest->getHeaderLine('Authorization'));
        static::assertSame('application/ld+json', $getRequest->getHeaderLine('Accept'));
    }

    public function testLazyHydraPaginationAcrossPages(): void
    {
        $client = $this->makeGuzzleClient([
            self::jsonResponse(['access_token' => 'A1', 'refresh_token' => 'R1', 'expires_in' => 3600]),
            self::jsonResponse([
                'hydra:member' => [['@id' => '/api/quotations/1', '@type' => 'Quotation']],
                'hydra:totalItems' => 2,
                'hydra:view' => ['hydra:next' => '/api/quotations?page=2'],
            ]),
            self::jsonResponse([
                'hydra:member' => [['@id' => '/api/quotations/2', '@type' => 'Quotation']],
                'hydra:totalItems' => 2,
            ]),
        ]);

        $collection = $client->request('/api/quotations');

        static::assertInstanceOf(LazyCollection::class, $collection);
        static::assertCount(2, $collection);
        static::assertCount(2, $this->history);

        $ids = [];
        foreach ($collection as $member) {
            $ids[] = $member['@id'];
        }

        static::assertSame(['/api/quotations/1', '/api/quotations/2'], $ids);
        static::assertCount(3, $this->history);
        static::assertSame(self::BASE_URL . '/api/quotations?page=2', (string) $this->sentRequest(2)->getUri());
    }

    public function testReplaysOnceWithRenewedTokenOn401(): void
    {
        $client = $this->makeGuzzleClient([
            new Response(401),
            self::jsonResponse(['access_token' => 'new-A', 'refresh_token' => 'new-R', 'expires_in' => 3600]),
            self::jsonResponse(['ok' => true]),
        ]);
        $client->accessToken = 'invalidated-A';
        $client->refreshToken = 'good-R';
        $client->expiresIn = new \DateTimeImmutable('+300 seconds');

        $result = $client->request('/api/quotations');

        static::assertSame(['ok' => true], $result);
        static::assertCount(3, $this->history);

        $form = [];
        \parse_str((string) $this->sentRequest(1)->getBody(), $form);
        static::assertSame('refresh_token', $form['grant_type']);
        static::assertSame('good-R', $form['refresh_token']);
        static::assertSame('Bearer new-A', $this->sentRequest(2)->getHeaderLine('Authorization'));
    }

    public function testRetriesTransientServerErrorOnGet(): void
    {
        $client = $this->makeGuzzleClient([
            self::jsonResponse(['error' => 'boom'], 500),
            self::jsonResponse(['ok' => true]),
        ], retries: 1);
        $client->accessToken = 'A';
        $client->refreshToken = 'R';
        $client->expiresIn = new \DateTimeImmutable('+300 seconds');

        static::assertSame(['ok' => true], $client->request('/api/quotations'));
        static::assertCount(2, $this->history);
        static::assertSame('GET', $this->sentRequest(0)->getMethod());
        static::assertSame('GET', $this->sentRequest(1)->getMethod());
    }
}
