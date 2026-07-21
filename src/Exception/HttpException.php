<?php

declare(strict_types=1);

namespace BiblIndex\Client\Exception;

use Psr\Http\Message\ResponseInterface;

/**
 * An HTTP response with a 4xx/5xx status.
 *
 * PSR-18 clients do not throw on HTTP error statuses; the BiblIndex client
 * raises this hierarchy itself after inspecting the response. The request
 * method and URL are carried as plain strings — never the request object,
 * whose body may contain credentials.
 */
class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly ResponseInterface $response,
    ) {
        parent::__construct(\sprintf('HTTP %d returned for "%s %s".', $response->getStatusCode(), $method, $url));
    }

    /** Map an error response to the concrete exception class. */
    public static function fromResponse(string $method, string $url, ResponseInterface $response): self
    {
        return $response->getStatusCode() >= 500
            ? new ServerErrorException($method, $url, $response)
            : new ClientErrorException($method, $url, $response);
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }
}
