# BiblIndex PHP client

[![PHP](https://img.shields.io/badge/PHP-8.4+-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Composer](https://img.shields.io/badge/Composer-package%20manager-885630?logo=composer)](https://getcomposer.org/)
![Cross Platform](https://img.shields.io/badge/platform-macOS%20%7C%20Linux%20%7C%20WSL-lightgrey)
[![CI](https://github.com/janalis/biblindex-php-client/actions/workflows/ci.yml/badge.svg)](https://github.com/janalis/biblindex-php-client/actions/workflows/ci.yml)

PHP client for the BiblIndex API.

## Maintainers

| Name              | Email              |
| ----------------- | ------------------ |
| Pierre Hennequart | pierre@janalis.com |

## Documentation

https://www.biblindex.org/api

## Installation

### As a library in another project

The client is built on the PSR-18/PSR-17 HTTP abstractions: it needs any
PSR-18 client and PSR-17 factory implementation alongside it, for example
Guzzle:

```bash
composer require janalis/biblindex-client guzzlehttp/guzzle
```

or Symfony HttpClient with nyholm/psr7:

```bash
composer require janalis/biblindex-client symfony/http-client nyholm/psr7
```

Installed implementations are auto-detected via
[php-http/discovery](https://github.com/php-http/discovery) (its composer
plugin may prompt once to allow itself and can install a missing
implementation for you).

To use a version that hasn't been released to Packagist yet, add the Git
repository to your project's `composer.json`:

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/janalis/biblindex-php-client"}
    ],
    "require": {
        "janalis/biblindex-client": "dev-main"
    }
}
```

### For development

```bash
git clone <repo-url>
cd <repo-name>
composer install
```

### Environment variables (example runner only)

```bash
cp .env.example .env.local
```

Edit `.env.local` with your configuration, then run the example:

```bash
php example.php
```

## Usage

```php
use BiblIndex\Client\BiblIndexClient;

$client = new BiblIndexClient(
    baseUrl: 'https://www.biblindex.org',
    username: '...',
    password: '...',
    clientId: '...',
    clientSecret: '...',
);

$quotations = $client->request('/api/quotations', ['page' => 1]);
```

The client authenticates with the OAuth2 password grant on first use, then
refreshes the access token automatically (including a refresh slightly before
expiry, and a renew-and-replay when the API answers 401).

With no arguments beyond the credentials, the HTTP client and message
factories are discovered from whatever implementation is installed. To control
the transport (or in dependency-injection setups), inject them explicitly —
any PSR-18 client works:

```php
// Guzzle
$client = new BiblIndexClient(
    baseUrl: 'https://www.biblindex.org',
    username: '...',
    password: '...',
    clientId: '...',
    clientSecret: '...',
    httpClient: new \GuzzleHttp\Client(['timeout' => 10]),
);

// Symfony HttpClient
$psr18 = new \Symfony\Component\HttpClient\Psr18Client(
    \Symfony\Component\HttpClient\HttpClient::create(['timeout' => 10]),
);
$client = new BiblIndexClient(
    baseUrl: 'https://www.biblindex.org',
    // ...credentials...
    httpClient: $psr18,
    requestFactory: $psr18,
    streamFactory: $psr18,
);
```

### Reliability

Timeouts are a transport concern: configure them on the injected client, as
in the examples above (Guzzle's `timeout` option, Symfony's
`HttpClient::create(['timeout' => 10])`).

Retries are off by default — pass `retries: N` to retry GET requests on
transient failures (429/5xx responses and PSR-18 network exceptions) with
exponential backoff (500 ms doubling; no jitter, `Retry-After` not
consulted). Token requests are never blindly retried; instead, a 401 API
response triggers an automatic token renewal (refresh grant, falling back to
the password grant) and a single replay of the request.

```php
$client = new BiblIndexClient(
    baseUrl: 'https://www.biblindex.org',
    username: '...',
    password: '...',
    clientId: '...',
    clientSecret: '...',
    retries: 3,
);
```

HTTP error statuses raise the client's own exceptions (PSR-18 clients do not
throw on 4xx/5xx): `BiblIndex\Client\Exception\ClientErrorException` (4xx)
and `ServerErrorException` (5xx), both extending `HttpException`, which
exposes `method`, `url`, the PSR-7 `response` and `getStatusCode()`.
Transport failures surface as the underlying client's PSR-18
`Psr\Http\Client\ClientExceptionInterface` / `NetworkExceptionInterface`.

### Lazy fetching

API responses are automatically wrapped in lazy proxies that defer network
requests until data is actually read:

- **`LazyResource`** (`ArrayAccess` + `Countable` + `IteratorAggregate`):
  resource links (e.g. `/api/extracts/42`, `{"@id": "/api/works/1"}`)
  embedded in responses are wrapped as lazy maps — the linked resource is
  fetched only when a field is accessed. Identity keys (`@id`, `@type`, `id`)
  already present in the parent response are served without a fetch.
- **`LazyCollection`** (Doctrine `Collection`): paginated Hydra collections
  and plain JSON arrays are wrapped as lazy sequences — subsequent pages are
  fetched on demand when iterating or indexing beyond the current page.

Hydra metadata properties (`hydra:member`, `hydra:view`, `hydra:search`,
`hydra:totalItems`) are not exposed through the lazy wrappers — collections
are returned directly as `LazyCollection` instances.

Caching ensures the same API resource is never fetched twice within a single
response tree.

```php
use BiblIndex\Client\LazyResource;

$collection = $client->request('/api/quotations', ['page' => 1]);

// $collection is a LazyCollection — pages fetched lazily
$item = $collection[0];       // no network call yet
echo $item['@id'];            // triggers fetch of /api/quotations/1229419
```

#### Lazy vs. full-fetch operations on `LazyCollection`

| Behavior | Operations |
| --- | --- |
| No fetch | `count()` when `hydra:totalItems` is known, `getLoadedItems()`, `isEmpty()` when an item is already loaded, `clear()`, `isInitialized()` |
| Fetch only the needed pages | `offsetGet()` / `get()`, `offsetExists()` / `containsKey()`, `first()`, `slice($offset, $length)` with a non-null length, iteration (page by page as it advances) |
| Fetch all remaining pages | `count()` when the total is unknown, `toArray()`, `getValues()`, `map()`, `filter()`, `contains()`, `indexOf()`, `last()`, `matching()`, `slice($offset)` without length, and every mutator (`add()`, `set()`, `remove()`, `offsetSet()`, `offsetUnset()`, ...) |

#### Using `application/json` (plain JSON)

> **Warning:** Prefer `application/ld+json` (the default) whenever the API
> supports it. The Hydra JSON-LD format provides metadata
> (`hydra:totalItems`, `hydra:view`) that enables an accurate `count()`
> without fetching and proper next-page resolution via `hydra:next` links.
> With plain `application/json`, the total item count is unavailable
> (`count()` fetches all pages) and pagination falls back to incrementing
> `?page=N`, which may yield empty pages at the end.

```php
$client = new BiblIndexClient(
    baseUrl: 'https://www.biblindex.org',
    username: '...',
    password: '...',
    clientId: '...',
    clientSecret: '...',
    accept: 'application/json',
);

$collection = $client->request('/api/quotations', ['page' => 1]);

echo $collection->getLoadedItems();  // items loaded so far (page 1)

// Accessing beyond the current page triggers ?page=N
$item = $collection[2];              // fetches /api/quotations?page=2
echo $item['id'];                    // reads from the fetched item
```

### Behavior notes

- `LazyResource` implements `ArrayAccess`/`Countable`/`IteratorAggregate`;
  use `$resource->toArray()` to get the plain array. Missing and blocked keys
  throw `\OutOfBoundsException`.
- `LazyCollection` implements Doctrine's `Collection` interface.
  Out-of-range indices return `null` (Doctrine convention), negative indices
  are not supported (use `last()`), and mutators fetch all remaining pages
  before mutating.
- `count()` on a collection whose total is unknown fetches all remaining
  pages.
- No `close()` call is needed — connection lifecycle belongs to the injected
  PSR-18 client.
- Redirect following depends on the injected client: Guzzle's PSR-18 mode
  does not follow redirects, Symfony's `Psr18Client` does.
- Array query parameters are encoded in `key[0]=a&key[1]=b` bracket style
  (`http_build_query`).

## Testing

```bash
composer install
bin/phpunit
```

The suite mocks all HTTP traffic — no network access needed: Symfony's
`MockHttpClient` (bridged through its PSR-18 `Psr18Client` adapter) backs the
main suite, and a Guzzle `MockHandler` compatibility suite proves the client
against a second PSR-18 implementation.

## Code quality

Formatting, linting and static analysis are handled by
[Mago](https://mago.carthage.software/) (Symfony coding standard: PSR-12
formatter preset plus the `symfony` and `phpunit` linter integrations),
configured in [`mago.toml`](mago.toml) and enforced by CI:

```bash
composer format        # format in place
composer format:check  # verify formatting
composer lint          # lint
composer analyze       # static analysis
composer check         # format check + lint + analysis + tests
```

## Publishing a new version

Releases are published to [Packagist](https://packagist.org/packages/janalis/biblindex-client)
**automatically**: every push to `main` whose CI build (tests + code
quality) is green is released as the next **patch** version by the
[CI workflow](.github/workflows/ci.yml)'s release job — it tags the next
version (starting from v0.0.0), creates a GitHub Release with auto-generated
notes and pings the Packagist update API.

Control the release from the commit message:

| Marker | Effect |
| --- | --- |
| *(none)* | patch bump (default) |
| `[release:minor]` | minor bump (resets patch) |
| `[release:major]` | major bump (resets minor/patch) |
| `[release:skip]` | no release for this build |

Manual releases still work — `composer release:patch` (or
`release:minor` / `release:major`) tags and pushes from your machine, which
triggers the tag-driven [Release workflow](.github/workflows/release.yml);
the automatic job then skips that commit because it is already tagged.

The Packagist ping requires the `PACKAGIST_API_TOKEN` repository secret (the
"safe API token" from your [Packagist
profile](https://packagist.org/profile/)); when it is missing the workflows
skip the ping with a warning.

CI and releases run on self-hosted runners managed by
[ARC](https://github.com/actions/actions-runner-controller) (runner scale set
`biblindex-php-client-runners`).

## Contributing

This project is open to contributions.

We welcome pull requests following the standard GitHub flow:

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Open a pull request

Please ensure your changes are well tested and follow the existing code style.

## API Modifications

If you need changes, extensions, or adjustments to the API, please contact the maintainers.
