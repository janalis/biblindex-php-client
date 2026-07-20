<?php

declare(strict_types=1);

namespace BiblIndex\Client\Test;

use BiblIndex\Client\BiblIndexClient;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Shared helpers to build a client backed by a MockHttpClient that serves a
 * fixed queue of responses and records every request it receives.
 */
trait ClientFactoryTrait
{
    private const string BASE_URL = 'https://api.example.com';
    private const string TOKEN_URL = self::BASE_URL . '/api/token';
    private const string RESOURCE_PATH = '/api/quotations';
    private const string RESOURCE_URL = self::BASE_URL . self::RESOURCE_PATH;

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /**
     * @param list<ResponseInterface> $responses Responses served in order; any
     *                                           extra request fails the test.
     */
    private function makeClient(
        array $responses = [],
        string $accept = BiblIndexClient::JSON_LD_MIME_TYPE,
        ?float $timeout = BiblIndexClient::DEFAULT_TIMEOUT,
        int $retries = 0,
    ): BiblIndexClient {
        $this->requests = [];
        $queue = $responses;

        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$queue): ResponseInterface {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            if ($queue === []) {
                Assert::fail(\sprintf('Unexpected extra request: %s %s', $method, $url));
            }

            return \array_shift($queue);
        });

        return new BiblIndexClient(
            self::BASE_URL,
            'user',
            'pass',
            'id',
            'secret',
            accept: $accept,
            timeout: $timeout,
            retries: $retries,
            httpClient: $mock,
        );
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int} */
    private static function tokenPayload(
        string $access = 'access-1',
        string $refresh = 'refresh-1',
        int $expiresIn = 3600,
    ): array {
        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => $expiresIn,
        ];
    }

    /** Preset valid tokens so requests skip the token endpoint. */
    private static function presetTokens(
        BiblIndexClient $client,
        string $access = 'A',
        string $refresh = 'R',
    ): void {
        $client->accessToken = $access;
        $client->refreshToken = $refresh;
        $client->expiresIn = new \DateTimeImmutable('+300 seconds');
    }

    private function requestCount(): int
    {
        return \count($this->requests);
    }

    private function requestMethod(int $index): string
    {
        return $this->requests[$index]['method'];
    }

    private function requestUrl(int $index): string
    {
        return $this->requests[$index]['url'];
    }

    private function requestHeader(int $index, string $name): ?string
    {
        $lines = $this->requests[$index]['options']['normalized_headers'][\strtolower($name)] ?? [];
        foreach ($lines as $line) {
            return \explode(': ', $line, 2)[1];
        }

        return null;
    }

    /** @return array<string, string> Decoded x-www-form-urlencoded POST body. */
    private function requestForm(int $index): array
    {
        \parse_str((string) ($this->requests[$index]['options']['body'] ?? ''), $form);

        return $form;
    }

    private function requestTimeout(int $index): ?float
    {
        $timeout = $this->requests[$index]['options']['timeout'] ?? null;

        return $timeout === null ? null : (float) $timeout;
    }
}
