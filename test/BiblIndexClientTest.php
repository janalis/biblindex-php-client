<?php

declare(strict_types=1);

namespace BiblIndex\Client\Test;

use BiblIndex\Client\BiblIndexClient;
use BiblIndex\Client\LazyCollection;
use BiblIndex\Client\ResourceCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

#[CoversClass(BiblIndexClient::class)]
#[UsesClass(LazyCollection::class)]
#[UsesClass(ResourceCache::class)]
final class BiblIndexClientTest extends TestCase
{
    use ClientFactoryTrait;

    public function testConstructorStoresConfigurationAndStartsUnauthenticated(): void
    {
        $client = $this->makeClient();

        static::assertSame(self::BASE_URL, $client->baseUrl);
        static::assertSame('application/ld+json', $client->accept);
        static::assertSame(30.0, $client->timeout);
        static::assertSame(0, $client->retries);
        static::assertNull($client->accessToken);
        static::assertNull($client->refreshToken);
        static::assertNull($client->expiresIn);
    }

    public function testConstructorAcceptsNullTimeout(): void
    {
        $client = $this->makeClient([new JsonMockResponse(['ok' => true])], timeout: null);
        self::presetTokens($client);

        static::assertNull($client->timeout);
        static::assertSame(['ok' => true], $client->request(self::RESOURCE_PATH));
    }

    public function testGetIsNotRetriedByDefault(): void
    {
        $client = $this->makeClient([new JsonMockResponse(['error' => 'boom'], ['http_code' => 500])]);
        self::presetTokens($client);

        try {
            $client->request(self::RESOURCE_PATH);
            static::fail('Expected a server exception.');
        } catch (ServerExceptionInterface) {
        }

        static::assertSame(1, $this->requestCount());
    }

    public function testGetIsRetriedOnRetryableStatusWhenRetriesEnabled(): void
    {
        // RetryableHttpClient really sleeps between attempts (~500ms), so the
        // scenario is kept to a single retry.
        $client = $this->makeClient([
            new JsonMockResponse(['error' => 'boom'], ['http_code' => 500]),
            new JsonMockResponse(['ok' => true]),
        ], retries: 1);
        self::presetTokens($client);

        static::assertSame(['ok' => true], $client->request(self::RESOURCE_PATH));
        static::assertSame(2, $this->requestCount());
        static::assertSame('GET', $this->requestMethod(0));
        static::assertSame('GET', $this->requestMethod(1));
    }

    public function testTokenPostIsNeverRetried(): void
    {
        $client = $this->makeClient([new JsonMockResponse(['error' => 'boom'], ['http_code' => 500])], retries: 3);

        try {
            $client->fetchTokens();
            static::fail('Expected a server exception.');
        } catch (ServerExceptionInterface) {
        }

        static::assertSame(1, $this->requestCount());
        static::assertSame('POST', $this->requestMethod(0));
    }

    public function testFetchTokensPopulatesState(): void
    {
        $client = $this->makeClient([new JsonMockResponse(self::tokenPayload('A1', 'R1', 1800))]);

        $before = new \DateTimeImmutable();
        $client->fetchTokens();

        static::assertSame('A1', $client->accessToken);
        static::assertSame('R1', $client->refreshToken);
        static::assertNotNull($client->expiresIn);
        // expiresIn should be ~1800s in the future (allow small clock drift).
        static::assertGreaterThanOrEqual($before->modify('+1799 seconds'), $client->expiresIn);
        static::assertLessThanOrEqual(new \DateTimeImmutable('+1801 seconds'), $client->expiresIn);
    }

    public function testFetchTokensUsesPasswordGrant(): void
    {
        $client = $this->makeClient([new JsonMockResponse(self::tokenPayload())]);

        $client->fetchTokens();

        static::assertSame('POST', $this->requestMethod(0));
        static::assertSame(self::TOKEN_URL, $this->requestUrl(0));
        static::assertSame(
            [
                'grant_type' => 'password',
                'username' => 'user',
                'password' => 'pass',
                'client_id' => 'id',
                'client_secret' => 'secret',
            ],
            $this->requestForm(0),
        );
    }

    public function testFetchTokensThrowsOnBadCredentialsAndKeepsState(): void
    {
        $client = $this->makeClient([new JsonMockResponse(['error' => 'invalid_grant'], ['http_code' => 400])]);

        try {
            $client->fetchTokens();
            static::fail('Expected a client exception.');
        } catch (ClientExceptionInterface) {
        }

        static::assertNull($client->accessToken);
        static::assertNull($client->refreshToken);
        static::assertNull($client->expiresIn);
    }

    public function testRefreshTokensUsesRefreshGrantAndUpdatesState(): void
    {
        $client = $this->makeClient([new JsonMockResponse(self::tokenPayload('A2', 'R2', 600))]);
        $client->refreshToken = 'old-refresh';

        $client->refreshTokens();

        static::assertSame(
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => 'old-refresh',
                'client_id' => 'id',
                'client_secret' => 'secret',
            ],
            $this->requestForm(0),
        );
        static::assertSame('A2', $client->accessToken);
        static::assertSame('R2', $client->refreshToken);
        static::assertNotNull($client->expiresIn);
    }

    public function testRefreshTokensThrowsOnRejectedRefreshTokenAndKeepsState(): void
    {
        $client = $this->makeClient([new JsonMockResponse(['error' => 'invalid_grant'], ['http_code' => 401])]);
        $client->accessToken = 'stale-A';
        $client->refreshToken = 'revoked-R';

        try {
            $client->refreshTokens();
            static::fail('Expected a client exception.');
        } catch (ClientExceptionInterface) {
        }

        static::assertSame('stale-A', $client->accessToken);
        static::assertSame('revoked-R', $client->refreshToken);
    }

    public function testRequestPassesTimeoutToGet(): void
    {
        $client = $this->makeClient([new JsonMockResponse(['ok' => true])]);
        self::presetTokens($client);

        $client->request(self::RESOURCE_PATH);

        static::assertSame(30.0, $this->requestTimeout(0));
    }

    public function testTokenRequestsPassTimeout(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse(self::tokenPayload('A1', 'R1')),
            new JsonMockResponse(self::tokenPayload('A2', 'R2')),
        ], timeout: 5.0);

        $client->fetchTokens();
        $client->refreshTokens();

        static::assertSame(5.0, $this->requestTimeout(0));
        static::assertSame(5.0, $this->requestTimeout(1));
    }

    public function testRequestFirstCallAuthenticatesThenGets(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse(self::tokenPayload('A1', 'R1')),
            new JsonMockResponse(['items' => []]),
        ]);

        $result = $client->request(self::RESOURCE_PATH, ['page' => 1]);

        static::assertSame(['items' => []], $result);
        static::assertSame(2, $this->requestCount());
        static::assertSame('POST', $this->requestMethod(0));
        static::assertSame(self::TOKEN_URL, $this->requestUrl(0));
        // Second call is the resource GET, with the bearer token.
        static::assertSame('GET', $this->requestMethod(1));
        static::assertStringStartsWith(self::RESOURCE_URL, $this->requestUrl(1));
        static::assertSame('Bearer A1', $this->requestHeader(1, 'Authorization'));
        static::assertSame('application/ld+json', $this->requestHeader(1, 'Accept'));
    }

    public function testRequestCanUseApplicationJsonAcceptHeader(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse(self::tokenPayload('A1', 'R1')),
            new JsonMockResponse([]),
        ], accept: 'application/json');

        $client->request(self::RESOURCE_PATH, ['page' => 1]);

        static::assertSame('application/json', $this->requestHeader(1, 'Accept'));
    }

    public function testRequestWithValidTokenSkipsTokenEndpoint(): void
    {
        $client = $this->makeClient([new JsonMockResponse(['ok' => true])]);
        self::presetTokens($client, access: 'preset-A', refresh: 'preset-R');

        $result = $client->request(self::RESOURCE_PATH);

        static::assertSame(['ok' => true], $result);
        static::assertSame(1, $this->requestCount());
        static::assertSame('Bearer preset-A', $this->requestHeader(0, 'Authorization'));
    }

    public function testRequestRefreshesWhenTokenExpired(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse(self::tokenPayload('fresh-A', 'fresh-R')),
            new JsonMockResponse(['refreshed' => true]),
        ]);
        $client->accessToken = 'stale-A';
        $client->refreshToken = 'good-R';
        $client->expiresIn = new \DateTimeImmutable('-1 second');

        $result = $client->request(self::RESOURCE_PATH);

        static::assertSame(['refreshed' => true], $result);
        static::assertSame(2, $this->requestCount());
        // First call: refresh-grant POST.
        $refreshForm = $this->requestForm(0);
        static::assertSame('refresh_token', $refreshForm['grant_type']);
        static::assertSame('good-R', $refreshForm['refresh_token']);
        // Second call: GET with the new bearer token.
        static::assertSame('Bearer fresh-A', $this->requestHeader(1, 'Authorization'));
    }

    public function testRequestRefreshesTokenInsideLeewayWindow(): void
    {
        $client = $this->makeClient([
            new JsonMockResponse(self::tokenPayload('fresh-A', 'fresh-R')),
            new JsonMockResponse(['ok' => true]),
        ]);
        $client->accessToken = 'almost-stale-A';
        $client->refreshToken = 'good-R';
        $client->expiresIn = new \DateTimeImmutable('+10 seconds');

        $client->request(self::RESOURCE_PATH);

        static::assertSame('refresh_token', $this->requestForm(0)['grant_type']);
        static::assertSame('Bearer fresh-A', $this->requestHeader(1, 'Authorization'));
    }

    public function testRequestReplaysOnceWithRenewedTokenOn401(): void
    {
        $client = $this->makeClient([
            new MockResponse('', ['http_code' => 401]),
            new JsonMockResponse(self::tokenPayload('new-A', 'new-R')),
            new JsonMockResponse(['ok' => true]),
        ]);
        self::presetTokens($client, access: 'invalidated-A', refresh: 'good-R');

        $result = $client->request(self::RESOURCE_PATH);

        static::assertSame(['ok' => true], $result);
        static::assertSame(3, $this->requestCount());
        static::assertSame('refresh_token', $this->requestForm(1)['grant_type']);
        static::assertSame('Bearer new-A', $this->requestHeader(2, 'Authorization'));
    }

    public function testRequestDoesNotLoopOnPersistent401(): void
    {
        $client = $this->makeClient([
            new MockResponse('', ['http_code' => 401]),
            new JsonMockResponse(self::tokenPayload('new-A', 'new-R')),
            new MockResponse('', ['http_code' => 401]),
        ]);
        self::presetTokens($client, access: 'rejected-A', refresh: 'good-R');

        try {
            $client->request(self::RESOURCE_PATH);
            static::fail('Expected a client exception.');
        } catch (ClientExceptionInterface) {
        }

        static::assertSame(3, $this->requestCount());
    }

    public function testRequestFallsBackToPasswordGrantWhenRefreshRejected(): void
    {
        $client = $this->makeClient([
            new MockResponse('', ['http_code' => 401]),
            new JsonMockResponse(['error' => 'invalid_grant'], ['http_code' => 400]),
            new JsonMockResponse(self::tokenPayload('new-A', 'new-R')),
            new JsonMockResponse(['ok' => true]),
        ]);
        self::presetTokens($client, access: 'invalidated-A', refresh: 'revoked-R');

        $result = $client->request(self::RESOURCE_PATH);

        static::assertSame(['ok' => true], $result);
        static::assertSame(4, $this->requestCount());
        static::assertSame('refresh_token', $this->requestForm(1)['grant_type']);
        static::assertSame('password', $this->requestForm(2)['grant_type']);
        static::assertSame('Bearer new-A', $this->requestHeader(3, 'Authorization'));
    }

    public function testRequest401WithoutRefreshTokenUsesPasswordGrant(): void
    {
        $client = $this->makeClient([
            new MockResponse('', ['http_code' => 401]),
            new JsonMockResponse(self::tokenPayload('new-A', 'new-R')),
            new JsonMockResponse(['ok' => true]),
        ]);
        $client->accessToken = 'invalidated-A';

        $result = $client->request(self::RESOURCE_PATH);

        static::assertSame(['ok' => true], $result);
        static::assertSame('password', $this->requestForm(1)['grant_type']);
        static::assertSame('Bearer new-A', $this->requestHeader(2, 'Authorization'));
    }

    public function testRequestPassesQueryParams(): void
    {
        $client = $this->makeClient([new JsonMockResponse(['ok' => true])]);
        self::presetTokens($client);

        $client->request(self::RESOURCE_PATH, ['page' => 2, 'limit' => 10]);

        static::assertStringContainsString('page=2', $this->requestUrl(0));
        static::assertStringContainsString('limit=10', $this->requestUrl(0));
    }

    public function testRequestThrowsOnHttpError(): void
    {
        $client = $this->makeClient([new JsonMockResponse(['error' => 'boom'], ['http_code' => 500])]);
        self::presetTokens($client);

        $this->expectException(ServerExceptionInterface::class);
        $client->request(self::RESOURCE_PATH);
    }

    public function testRequestBuildsUrlWithLeadingSlash(): void
    {
        $client = $this->makeClient([new JsonMockResponse(['ok' => true])]);
        self::presetTokens($client);

        $client->request('/api/quotations');

        static::assertSame(self::RESOURCE_URL, $this->requestUrl(0));
        static::assertStringNotContainsString('//api/quotations', $this->requestUrl(0));
    }

    public function testRequestNormalizesMissingLeadingSlash(): void
    {
        $client = $this->makeClient([new JsonMockResponse(['ok' => true])]);
        self::presetTokens($client);

        $client->request('api/quotations');

        static::assertSame(self::RESOURCE_URL, $this->requestUrl(0));
    }

    public function testRequestNormalizesMissingApiPrefix(): void
    {
        $client = $this->makeClient([new JsonMockResponse(['ok' => true])]);
        self::presetTokens($client);

        $client->request('quotations');

        static::assertSame(self::RESOURCE_URL, $this->requestUrl(0));
    }

    public function testRequestAppendsParamsToResourceWithExistingQuery(): void
    {
        $client = $this->makeClient([new JsonMockResponse(['ok' => true])]);
        self::presetTokens($client);

        $client->request('/api/quotations?existing=1', ['page' => 2]);

        static::assertSame(self::RESOURCE_URL . '?existing=1&page=2', $this->requestUrl(0));
    }
}
