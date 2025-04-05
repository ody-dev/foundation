<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest as NyholmServerRequest;
use Ody\Swoole\Coroutine\ContextManager;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Swoole\Http\Request as SwooleRequest;

/**
 * PSR-7 compatible HTTP Request
 */
class Request implements ServerRequestInterface
{
    /**
     * @var ServerRequestInterface Internal PSR-7 implementation
     */
    private ServerRequestInterface $psrRequest;

    /**
     * @var array Route parameters
     */
    private array $routeParams = [];

    /**
     * Constructor
     */
    public function __construct(ServerRequestInterface $request)
    {
        $this->psrRequest = $request;
    }

    /**
     * Create a request from globals or context manager
     */
    public static function createFromGlobals(): self
    {
        $factory = new Psr17Factory();

        // First check if we're in a Swoole environment with ContextManager
        if (extension_loaded('swoole') && class_exists('\Swoole\Coroutine') &&
            method_exists('\Swoole\Coroutine', 'getContext')) {

            // Get request data from ContextManager
            $server = ContextManager::get('_SERVER') ?? [];
            $get = ContextManager::get('_GET') ?? [];
            $post = ContextManager::get('_POST') ?? [];
            $cookie = ContextManager::get('_COOKIE') ?? [];
            $files = ContextManager::get('_FILES') ?? [];

            // If we have server data in the context, we're in a Swoole request
            if (!empty($server)) {
                // Create URI from context server data
                $uri = self::createUriFromServerArray($server, $factory);
                $method = $server['request_method'] ?? 'GET';
                $protocol = isset($server['server_protocol']) ?
                    str_replace('HTTP/', '', $server['server_protocol']) : '1.1';

                // Get headers from server array
                $headers = self::getHeadersFromServerArray($server);

                // Create a stream for the request body
                // Note: In Swoole context, we'd ideally have access to the raw content
                $body = $factory->createStream('');

                $request = new NyholmServerRequest(
                    $method,
                    $uri,
                    $headers,
                    $body,
                    $protocol,
                    $server
                );

                return new self(
                    $request
                        ->withCookieParams($cookie)
                        ->withQueryParams($get)
                        ->withParsedBody($post)
                        ->withUploadedFiles(normalizeUploadedFiles($files))
                );
            }
        }

        // Fall back to PHP globals for traditional environment
        $uri = self::createUriFromServerArray($_SERVER, $factory);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $body = $factory->createStreamFromFile('php://input');
        $headers = self::getHeadersFromServerArray($_SERVER);

        $request = new NyholmServerRequest(
            $method,
            $uri,
            $headers,
            $body,
            $_SERVER['SERVER_PROTOCOL'] ?? '1.1',
            $_SERVER
        );

        return new self(
            $request
                ->withCookieParams($_COOKIE)
                ->withQueryParams($_GET)
                ->withParsedBody($_POST)
                ->withUploadedFiles(normalizeUploadedFiles($_FILES))
        );
    }

    /**
     * Create a request from a Swoole request
     */
    public static function createFromSwooleRequest(SwooleRequest $swooleRequest): self
    {
        $factory = new Psr17Factory();

        // Extract server params
        $server = $swooleRequest->server ?? [];

        // Create URI
        $uri = self::createUriFromServerArray($server, $factory);

        // Create body stream from pool instead of creating new one
        $rawContent = $swooleRequest->rawContent() ?? '';
        $body = RequestResponsePool::getStream();

        // Reset and write new content to the pooled stream
        try {
            $body->rewind();
            $body->write($rawContent);
            $body->rewind();
        } catch (\Throwable $e) {
            // If reusing stream fails, create a new one
            $body = $factory->createStream($rawContent);
        }

        // Create basic request
        $method = $server['request_method'] ?? 'GET';
        $protocol = isset($server['server_protocol']) ?
            str_replace('HTTP/', '', $server['server_protocol']) : '1.1';

        // Convert Swoole headers to PSR-7 format
        $headers = [];
        foreach ($swooleRequest->header ?? [] as $name => $value) {
            $headers[str_replace('_', '-', $name)] = $value;
        }

        $request = new NyholmServerRequest(
            $method,
            $uri,
            $headers,
            $body,
            $protocol,
            $server
        );

        // Add additional request data
        $request = $request
            ->withCookieParams($swooleRequest->cookie ?? [])
            ->withQueryParams($swooleRequest->get ?? [])
            ->withParsedBody($swooleRequest->post ?? [])
            ->withUploadedFiles(normalizeUploadedFiles($swooleRequest->files ?? []));

        return new self($request);
    }

    /**
     * Create a URI from server parameters
     */
    private static function createUriFromServerArray(array $server, Psr17Factory $factory): \Psr\Http\Message\UriInterface
    {
        // Determine scheme
        $https = $server['HTTPS'] ?? '';
        $scheme = (empty($https) || $https === 'off') ? 'http' : 'https';

        // Determine host
        $host = $server['HTTP_HOST'] ?? '';
        if (empty($host)) {
            $host = $server['SERVER_NAME'] ?? '';
            if (empty($host)) {
                $host = $server['SERVER_ADDR'] ?? '';
            }
        }

        // Determine port
        $port = $server['SERVER_PORT'] ?? null;

        // Determine path and query
        $requestUri = $server['REQUEST_URI'] ?? '';
        $path = '/';
        $query = '';

        if (!empty($requestUri)) {
            $parts = parse_url($requestUri);
            if ($parts !== false) {
                $path = $parts['path'] ?? '/';
                $query = $parts['query'] ?? '';
            }
        }

        // Create URI
        $uri = $factory->createUri('')
            ->withScheme($scheme)
            ->withHost($host)
            ->withPath($path);

        if (!empty($query)) {
            $uri = $uri->withQuery($query);
        }

        if (!empty($port)) {
            $uri = $uri->withPort((int)$port);
        }

        return $uri;
    }

    /**
     * Extract headers from server array
     */
    private static function getHeadersFromServerArray(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $name = str_replace('_', '-', $key);
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    /**
     * Get request method
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->psrRequest->getMethod();
    }

    /**
     * Get request URI
     *
     * @return UriInterface
     */
    public function getUri(): UriInterface
    {
        return $this->psrRequest->getUri();
    }

    /**
     * Get request URI as string
     *
     * @return string
     */
    public function getUriString(): string
    {
        return (string) $this->psrRequest->getUri();
    }

    /**
     * Get request path (without query string)
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->psrRequest->getUri()->getPath();
    }

    /**
     * Get raw request body
     *
     * @return string
     */
    public function rawContent(): string
    {
        return (string) $this->psrRequest->getBody();
    }

    /**
     * Get request body as JSON
     *
     * @param bool $assoc Return as associative array
     * @return mixed
     */
    public function json(bool $assoc = true)
    {
        $content = $this->rawContent();
        return json_decode($content, $assoc);
    }

    /**
     * Get input parameter (GET, POST, or JSON body)
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        $parsedBody = $this->psrRequest->getParsedBody();

        if (is_array($parsedBody) && isset($parsedBody[$key])) {
            return $parsedBody[$key];
        }

        $queryParams = $this->psrRequest->getQueryParams();

        if (isset($queryParams[$key])) {
            return $queryParams[$key];
        }

        return $default;
    }

    /**
     * Get all input parameters
     *
     * @return array
     */
    public function all(): array
    {
        $queryParams = $this->psrRequest->getQueryParams();
        $parsedBody = $this->psrRequest->getParsedBody();

        if (!is_array($parsedBody)) {
            $parsedBody = [];
        }

        return array_merge($queryParams, $parsedBody);
    }

    /* PSR-7 ServerRequestInterface methods */

    public function getProtocolVersion(): string
    {
        return $this->psrRequest->getProtocolVersion();
    }

    public function withProtocolVersion($version): MessageInterface
    {
        $new = clone $this;
        $new->psrRequest = $this->psrRequest->withProtocolVersion($version);
        return $new;
    }

    public function getHeaders(): array
    {
        return $this->psrRequest->getHeaders();
    }

    public function hasHeader($name): bool
    {
        return $this->psrRequest->hasHeader($name);
    }

    public function getHeader($name): array
    {
        return $this->psrRequest->getHeader($name);
    }

    public function getHeaderLine($name): string
    {
        return $this->psrRequest->getHeaderLine($name);
    }

    public function withHeader($name, $value): MessageInterface
    {
        $new = clone $this;
        $new->psrRequest = $this->psrRequest->withHeader($name, $value);
        return $new;
    }

    public function withAddedHeader($name, $value): MessageInterface
    {
        $new = clone $this;
        $new->psrRequest = $this->psrRequest->withAddedHeader($name, $value);
        return $new;
    }

    public function withoutHeader($name): MessageInterface
    {
        $new = clone $this;
        $new->psrRequest = $this->psrRequest->withoutHeader($name);
        return $new;
    }

    public function getBody(): StreamInterface
    {
        return $this->psrRequest->getBody();
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        $new = clone $this;
        $new->psrRequest = $this->psrRequest->withBody($body);
        return $new;
    }

    public function getRequestTarget(): string
    {
        return $this->psrRequest->getRequestTarget();
    }

    public function withRequestTarget($requestTarget): \Psr\Http\Message\RequestInterface
    {
        $new = clone $this;
        $new->psrRequest = $this->psrRequest->withRequestTarget($requestTarget);
        return $new;
    }

    public function withMethod($method): \Psr\Http\Message\RequestInterface
    {
        $new = clone $this;
        $new->psrRequest = $this->psrRequest->withMethod($method);
        return $new;
    }

    public function withUri(UriInterface $uri, $preserveHost = false): \Psr\Http\Message\RequestInterface
    {
        $new = clone $this;
        $new->psrRequest = $this->psrRequest->withUri($uri, $preserveHost);
        return $new;
    }

    public function getServerParams(): array
    {
        return $this->psrRequest->getServerParams();
    }

    public function getCookieParams(): array
    {
        return $this->psrRequest->getCookieParams();
    }

    public function withCookieParams(array $cookies): Request|static
    {
        $new = clone $this;
        $new->psrRequest = $this->psrRequest->withCookieParams($cookies);
        return $new;
    }

    public function getQueryParams(): array
    {
        return $this->psrRequest->getQueryParams();
    }

    public function withQueryParams(array $query): Request|static
    {
        $new = clone $this;
        $new->psrRequest = $this->psrRequest->withQueryParams($query);
        return $new;
    }

    public function getUploadedFiles(): array
    {
        return $this->psrRequest->getUploadedFiles();
    }

    public function withUploadedFiles(array $uploadedFiles): Request|static
    {
        $new = clone $this;
        $new->psrRequest = $this->psrRequest->withUploadedFiles($uploadedFiles);
        return $new;
    }

    public function getParsedBody(): object|array|null
    {
        return $this->psrRequest->getParsedBody();
    }

    public function withParsedBody($data): ServerRequestInterface
    {
        $new = clone $this;
        $new->psrRequest = $this->psrRequest->withParsedBody($data);
        return $new;
    }

    public function getAttributes(): array
    {
        return $this->psrRequest->getAttributes();
    }

    public function getAttribute($name, $default = null)
    {
        return $this->psrRequest->getAttribute($name, $default);
    }

    public function withAttribute($name, $value): ServerRequestInterface
    {
        $new = clone $this;
        $new->psrRequest = $this->psrRequest->withAttribute($name, $value);
        return $new;
    }

    public function withoutAttribute($name): ServerRequestInterface
    {
        $new = clone $this;
        $new->psrRequest = $this->psrRequest->withoutAttribute($name);
        return $new;
    }

    /**
     * Get the underlying PSR-7 server request
     *
     * @return ServerRequestInterface
     */
    public function getPsrRequest(): ServerRequestInterface
    {
        return $this->psrRequest;
    }

    public function getRouteParams(): array
    {
        return $this->routeParams;
    }

    public function withRouteParams(array $params): self
    {
        $new = clone $this;
        $new->routeParams = $params;
        return $new;
    }
}