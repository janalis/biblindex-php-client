<?php

declare(strict_types=1);

namespace BiblIndex\Client;

use BiblIndex\Client\Exception\HttpException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * HTTP client for interacting with the BiblIndex API.
 *
 * Handles:
 * - Authentication via OAuth2 password grant
 * - Automatic token refresh, including re-authentication on a 401 response
 * - Authenticated GET requests to API resources
 *
 * API resource links found in response bodies are wrapped in lazy proxies
 * ({@see LazyResource}, {@see LazyCollection}) that are fetched on access.
 */
class BiblIndexClient implements ResourceClientInterface
{
    public const string JSON_LD_MIME_TYPE = 'application/ld+json';

    /**
     * Refresh tokens slightly before their actual expiry so a token that
     * would expire mid-request is renewed up front.
     */
    public const int TOKEN_EXPIRY_LEEWAY_SECONDS = 30;

    private const array RETRY_STATUS_CODES = [429, 500, 502, 503, 504];

    private const int RETRY_DELAY_MS = 500;

    /** Current access token, or null before first authentication. */
    public ?string $accessToken = null;

    /** Refresh token used to renew access tokens. */
    public ?string $refreshToken = null;

    /** Expiration instant of the current access token. */
    public ?\DateTimeImmutable $expiresIn = null;

    private readonly ClientInterface $httpClient;

    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    /**
     * Timeouts are a transport concern: configure them on the injected
     * PSR-18 client (e.g. Guzzle's `timeout` option or Symfony's
     * `HttpClient::create(['timeout' => ...])`).
     *
     * @param string $baseUrl Base URL of the API.
     * @param string $accept  Media type used in the Accept header for API GET requests.
     * @param int    $retries Number of retries for GET requests on transient
     *                        failures (0 disables retries).
     * @param ?ClientInterface $httpClient PSR-18 client; discovered when omitted.
     * @param ?RequestFactoryInterface $requestFactory PSR-17 factory; discovered when omitted.
     * @param ?StreamFactoryInterface $streamFactory PSR-17 factory; discovered when omitted.
     */
    public function __construct(
        public readonly string $baseUrl,
        private readonly string $username,
        #[\SensitiveParameter]
        private readonly string $password,
        private readonly string $clientId,
        #[\SensitiveParameter]
        private readonly string $clientSecret,
        public readonly string $accept = self::JSON_LD_MIME_TYPE,
        public readonly int $retries = 0,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * Perform an authenticated GET request to the API.
     *
     * Automatically fetches tokens if missing, and refreshes them if expired
     * before issuing the call. If the API still answers 401, the tokens are
     * renewed and the call is replayed once. API resource links found in the
     * response body are wrapped in lazy resources that are fetched on access.
     *
     * @param string $resource API resource path. Should start with a leading
     *                         slash (e.g. "/api/quotations"); a missing
     *                         leading slash is normalized for convenience.
     * @param array<string, mixed> $params Query parameters for the request.
     *
     * @return mixed Parsed JSON response; objects come back as PHP arrays
     *               whose linked values may be {@see LazyResource} or
     *               {@see LazyCollection} instances, collections as
     *               {@see LazyCollection}.
     *
     * @throws HttpException If the API answers with a 4xx/5xx status. Transport
     *                       failures surface as the PSR-18 client's own
     *                       ClientExceptionInterface.
     */
    public function request(string $resource, array $params = []): mixed
    {
        $resource = $this->normalizeResource($resource);
        $currentResource = $this->resourceWithParams($resource, $params);
        $data = $this->requestJson($resource, $params);

        $cache = new ResourceCache();
        $cache->set($resource, $data);
        $cache->set($currentResource, $data);

        $wrapped = $this->wrapLinkedResources($data, $currentResource, $cache);
        if (\is_array($data) && \array_is_list($data) && \is_array($wrapped)) {
            return new LazyCollection(
                $this,
                \array_values($wrapped),
                $currentResource,
                $this->nextPlainJsonPageResource($currentResource),
                null,
                $cache,
            );
        }

        return $wrapped;
    }

    /**
     * Perform an authenticated GET request and return the decoded JSON body.
     *
     * A 401 response triggers a token renewal and a single replay of the
     * request; a second 401 surfaces as an {@see HttpException}.
     *
     * @internal Part of {@see ResourceClientInterface} for the lazy wrappers.
     *
     * @param array<string, mixed> $params
     */
    public function requestJson(string $resource, array $params): mixed
    {
        if ($this->accessToken === null) {
            $this->fetchTokens();
        }

        if (
            $this->expiresIn !== null
            && $this->expiresIn < new \DateTimeImmutable(\sprintf('+%d seconds', self::TOKEN_EXPIRY_LEEWAY_SECONDS))
        ) {
            $this->refreshTokens();
        }

        $response = $this->authorizedGet($resource, $params);
        if ($response->getStatusCode() === 401) {
            // PSR-7 has no cancel(); the unused response is simply dropped.
            $this->reauthenticate();
            $response = $this->authorizedGet($resource, $params);
        }

        if ($response->getStatusCode() >= 400) {
            throw HttpException::fromResponse('GET', $this->requestUrl($resource, $params), $response);
        }

        return \json_decode((string) $response->getBody(), associative: true, depth: 512, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * Fetch initial OAuth access and refresh tokens using the password grant.
     *
     * Updates $accessToken, $refreshToken and $expiresIn.
     *
     * @throws HttpException If the token endpoint rejects the
     *                                credentials. Token state is left
     *                                untouched on failure.
     */
    public function fetchTokens(): void
    {
        $this->requestTokens([
            'grant_type' => 'password',
            'username' => $this->username,
            'password' => $this->password,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
    }

    /**
     * Refresh the OAuth access token using the stored refresh token.
     *
     * Updates $accessToken, $refreshToken and $expiresIn.
     *
     * @throws HttpException If the token endpoint rejects the
     *                                refresh token. Token state is left
     *                                untouched on failure.
     */
    public function refreshTokens(): void
    {
        $this->requestTokens([
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $this->refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
    }

    /**
     * Wrap API links embedded in a response body with lazy resources.
     *
     * @internal Part of {@see ResourceClientInterface} for the lazy wrappers.
     */
    public function wrapLinkedResources(mixed $data, string $currentResource, ResourceCache $cache): mixed
    {
        // A decoded empty JSON object also matches the list check ({} decodes
        // to []); treating it as an empty list is benign for every caller.
        if (\is_array($data) && \array_is_list($data)) {
            $wrappedItems = [];
            foreach ($data as $item) {
                $isMap = \is_array($item) && !\array_is_list($item);
                $seed = $isMap ? $item : null;
                $resource = $isMap
                    ? $this->linkedResource($item['@id'] ?? null) ?? $this->resourceFromCollectionItem(
                        $item,
                        $currentResource,
                    )
                    : $this->linkedResource($item);

                if ($resource !== null && $resource !== $currentResource) {
                    $wrappedItems[] = $this->lazyResource($resource, $cache, $seed);
                    continue;
                }

                $wrappedItems[] = $this->wrapLinkedResources($item, $currentResource, $cache);
            }

            return $wrappedItems;
        }

        if (\is_array($data)) {
            $hydraMember = $data['hydra:member'] ?? null;
            if (\is_array($hydraMember) && \array_is_list($hydraMember)) {
                $wrappedMembers = $this->wrapLinkedResources($hydraMember, $currentResource, $cache);

                return new LazyCollection(
                    $this,
                    \is_array($wrappedMembers) ? \array_values($wrappedMembers) : [],
                    $currentResource,
                    $this->nextPageResource($data),
                    \is_int($data['hydra:totalItems'] ?? null) ? $data['hydra:totalItems'] : null,
                    $cache,
                );
            }

            return $this->wrapLinkedResourceProperties($data, $currentResource, $cache);
        }

        $resource = $this->linkedResource($data);
        if ($resource === null || $resource === $currentResource) {
            return $data;
        }

        return $this->lazyResource($resource, $cache);
    }

    /**
     * Resolve the next page of a Hydra collection from its hydra:view link.
     *
     * @internal Part of {@see ResourceClientInterface} for the lazy wrappers.
     *
     * @param array<array-key, mixed> $data
     */
    public function nextPageResource(array $data): ?string
    {
        $view = $data['hydra:view'] ?? null;
        if (!\is_array($view) || \array_is_list($view)) {
            return null;
        }

        return $this->linkedResource($view['hydra:next'] ?? null);
    }

    /**
     * Resolve the next page of a plain JSON collection by incrementing ?page=N.
     *
     * @internal Part of {@see ResourceClientInterface} for the lazy wrappers.
     */
    public function nextPlainJsonPageResource(string $resource): ?string
    {
        $path = \parse_url($resource, \PHP_URL_PATH) ?? '';
        $queryString = \parse_url($resource, \PHP_URL_QUERY);
        if (!\is_string($path) || !\is_string($queryString) || $queryString === '') {
            return null;
        }

        // Hand-parse instead of parse_str(), which mangles dots and spaces
        // in parameter names. Blank values are kept; duplicate keys collapse
        // to the last value.
        $query = [];
        foreach (\explode('&', $queryString) as $pair) {
            [$key, $value] = \array_pad(\explode('=', $pair, limit: 2), length: 2, value: '');
            $query[\urldecode($key)] = \urldecode($value);
        }

        if (!\array_key_exists('page', $query)) {
            return null;
        }

        $page = \filter_var($query['page'], \FILTER_VALIDATE_INT);
        if ($page === false) {
            return null;
        }

        $query['page'] = (string) ($page + 1);
        $nextQuery = \http_build_query($query);

        return $nextQuery === '' ? $path : $path . '?' . $nextQuery;
    }

    /**
     * Issue a GET request carrying the current bearer token.
     *
     * @param array<string, mixed> $params
     */
    private function authorizedGet(string $resource, array $params): ResponseInterface
    {
        $request = $this->requestFactory
            ->createRequest('GET', $this->requestUrl($resource, $params))
            ->withHeader('Authorization', 'Bearer ' . ($this->accessToken ?? ''))
            ->withHeader('Accept', $this->accept);

        return $this->sendWithRetries($request);
    }

    /**
     * Absolute request URL for a normalized resource path and params.
     *
     * @param array<string, mixed> $params
     */
    private function requestUrl(string $resource, array $params): string
    {
        return $this->baseUrl . $this->resourceWithParams($resource, $params);
    }

    /**
     * Send a GET request, retrying transient failures when retries are enabled.
     *
     * Retries 429/5xx statuses and PSR-18 network exceptions with exponential
     * backoff (500 ms doubling), matching the semantics of the Symfony retry
     * strategy previously used here. Request exceptions (malformed request)
     * are never retried.
     */
    private function sendWithRetries(RequestInterface $request): ResponseInterface
    {
        $attempt = 0;
        while (true) {
            try {
                $response = $this->httpClient->sendRequest($request);
            } catch (NetworkExceptionInterface $exception) {
                if ($attempt >= $this->retries) {
                    throw $exception;
                }

                $this->pause($attempt++);
                continue;
            }

            if (
                $attempt >= $this->retries
                || !\in_array($response->getStatusCode(), self::RETRY_STATUS_CODES, strict: true)
            ) {
                return $response;
            }

            $this->pause($attempt++);
        }
    }

    /** Sleep before retry attempt N (0-based): RETRY_DELAY_MS * 2^N. */
    private function pause(int $attempt): void
    {
        \usleep(\max(0, self::RETRY_DELAY_MS * (2 ** $attempt) * 1000));
    }

    /** Renew tokens after a 401: refresh grant if possible, else password grant. */
    private function reauthenticate(): void
    {
        if ($this->refreshToken === null) {
            $this->fetchTokens();

            return;
        }

        try {
            $this->refreshTokens();
        } catch (HttpException) {
            $this->fetchTokens();
        }
    }

    /**
     * POST a grant to the token endpoint and update the token state.
     *
     * @param array<string, string> $body
     */
    private function requestTokens(array $body): void
    {
        $url = $this->baseUrl . '/api/token';
        $request = $this->requestFactory
            ->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streamFactory->createStream(\http_build_query($body)));

        // Deliberately not retried — a blind retry after an ambiguous failure
        // could rotate the refresh token server-side and desynchronize auth
        // state. Thrown before touching token state, which stays untouched on
        // failure.
        $response = $this->httpClient->sendRequest($request);
        if ($response->getStatusCode() >= 400) {
            throw HttpException::fromResponse('POST', $url, $response);
        }

        $data = \json_decode((string) $response->getBody(), associative: true, depth: 512, flags: \JSON_THROW_ON_ERROR);

        $this->accessToken = $data['access_token'];
        $this->refreshToken = $data['refresh_token'];
        $this->expiresIn = new \DateTimeImmutable(\sprintf('+%d seconds', $data['expires_in']));
    }

    /**
     * Wrap resource links in a map without replacing its metadata.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function wrapLinkedResourceProperties(array $data, string $currentResource, ResourceCache $cache): array
    {
        $wrapped = [];
        foreach ($data as $key => $value) {
            if ($key === '@id' || $key === '@type') {
                $wrapped[$key] = $value;
                continue;
            }

            if (\in_array($key, self::HYDRA_KEYS, strict: true)) {
                continue;
            }

            $resource = $this->linkedResource($value);
            if ($resource !== null) {
                $wrapped[$key] = $resource === $currentResource ? $value : $this->lazyResource($resource, $cache);
                continue;
            }

            if (\is_array($value) && !\array_is_list($value)) {
                $valueResource = $this->linkedResource($value['@id'] ?? null);
                if ($valueResource !== null && $valueResource !== $currentResource) {
                    $wrapped[$key] = $this->lazyResource($valueResource, $cache, $value);
                    continue;
                }
            }

            $wrapped[$key] = $this->wrapLinkedResources($value, $currentResource, $cache);
        }

        return $wrapped;
    }

    /** @param array<array-key, mixed>|null $seed */
    private function lazyResource(string $resource, ResourceCache $cache, ?array $seed = null): mixed
    {
        if ($cache->has($resource)) {
            return $cache->get($resource);
        }

        $lazyResource = new LazyResource($this, $resource, $cache, $seed);
        $cache->set($resource, $lazyResource);

        return $lazyResource;
    }

    /**
     * Infer an item resource from a collection item carrying only an id.
     *
     * @param array<array-key, mixed> $item
     */
    private function resourceFromCollectionItem(array $item, string $currentResource): ?string
    {
        $itemId = $item['id'] ?? null;
        if (!\is_int($itemId) && !\is_string($itemId)) {
            return null;
        }

        $collectionResource = \rtrim(\explode('?', $currentResource, limit: 2)[0], characters: '/');
        if (!\str_starts_with($collectionResource, '/api/')) {
            return null;
        }

        $segments = \explode('/', $collectionResource);
        if (\end($segments) === (string) $itemId) {
            return null;
        }

        return $collectionResource . '/' . $itemId;
    }

    /** Return a normalized API resource path when $value is a link. */
    private function linkedResource(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        if (\str_starts_with($value, 'http://') || \str_starts_with($value, 'https://')) {
            $parsedBaseUrl = \parse_url($this->baseUrl);
            $parsedValue = \parse_url($value);
            if ($parsedBaseUrl === false || $parsedValue === false) {
                return null;
            }

            if (
                ($parsedValue['scheme'] ?? null) !== ($parsedBaseUrl['scheme'] ?? null)
                || ($parsedValue['host'] ?? null) !== ($parsedBaseUrl['host'] ?? null)
                || ($parsedValue['port'] ?? null) !== ($parsedBaseUrl['port'] ?? null)
            ) {
                return null;
            }

            $value = $parsedValue['path'] ?? '';
            if (isset($parsedValue['query']) && $parsedValue['query'] !== '') {
                $value .= '?' . $parsedValue['query'];
            }
        }

        if (!\str_starts_with($value, '/') && !\str_starts_with($value, 'api/')) {
            return null;
        }

        $resource = $this->normalizeResource($value);
        // This compares a URL path, not a credential — the rule pattern-matches
        // on the word "token".
        // @mago-ignore lint:no-insecure-comparison
        if ($resource === '/api/token' || !\str_starts_with($resource, '/api/')) {
            return null;
        }

        return $resource;
    }

    /** Normalize a resource path so it can be appended to $baseUrl. */
    private function normalizeResource(string $resource): string
    {
        if (\str_starts_with($resource, $this->baseUrl)) {
            $resource = \substr($resource, \strlen($this->baseUrl));
        }

        if (!\str_starts_with($resource, '/')) {
            $resource = '/' . $resource;
        }

        if (!\str_starts_with($resource, '/api')) {
            $resource = '/api' . $resource;
        }

        return $resource;
    }

    /** @param array<string, mixed> $params */
    private function resourceWithParams(string $resource, array $params): string
    {
        if ($params === []) {
            return $resource;
        }

        $query = \http_build_query($params);
        $separator = \str_contains($resource, '?') ? '&' : '?';

        return $resource . $separator . $query;
    }
}
