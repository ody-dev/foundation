<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware;


use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ody\Foundation\Http\Response;
use Ody\Foundation\Http\Request;
use Ody\Container\Container;

/**
 * PSR-15 compliant middleware implementation
 */
class Middleware
{
    /**
     * @var array Global middleware stack
     */
    private array $globalMiddleware = [];

    /**
     * @var array Named middleware
     */
    private array $namedMiddleware = [];

    /**
     * @var array Route-specific middleware
     */
    private array $routeMiddleware = [];

    /**
     * @var array Middleware groups
     */
    private array $groups = [];

    /**
     * @var Container|null
     */
    private ?Container $container;

    /**
     * Middleware constructor
     *
     * @param Container|null $container
     */
    public function __construct(?Container $container = null)
    {
        $this->container = $container;
    }

    /**
     * Add global middleware
     *
     * @param string|callable|MiddlewareInterface $middleware
     * @return self
     */
    public function add($middleware): self
    {
        $this->globalMiddleware[] = $middleware;
        return $this;
    }

    /**
     * Register a named middleware
     *
     * @param string $name
     * @param string|callable|MiddlewareInterface $middleware
     * @return self
     */
    public function addNamed(string $name, $middleware): self
    {
        $this->namedMiddleware[$name] = $middleware;
        return $this;
    }

    /**
     * Get a named middleware
     *
     * @param string $name
     * @return callable|MiddlewareInterface|null
     */
    public function getNamedMiddleware(string $name)
    {
        return $this->namedMiddleware[$name] ?? null;
    }

    /**
     * Apply middleware to a specific route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param string|callable|MiddlewareInterface $middleware
     * @return self
     */
    public function addToRoute(string $method, string $path, $middleware): self
    {
        $route = $this->formatRoute($method, $path);

        if (!isset($this->routeMiddleware[$route])) {
            $this->routeMiddleware[$route] = [];
        }

        $this->routeMiddleware[$route][] = $middleware;
        return $this;
    }

    /**
     * Apply middleware to multiple routes using a pattern
     *
     * @param string $pattern Route pattern (uses fnmatch)
     * @param string|callable|MiddlewareInterface $middleware
     * @return self
     */
    public function addToGroup(string $pattern, $middleware): self
    {
        $this->groups[] = [
            'pattern' => $pattern,
            'middleware' => $middleware
        ];
        return $this;
    }

    /**
     * Format route identifier
     *
     * @param string $method
     * @param string $path
     * @return string
     */
    private function formatRoute(string $method, string $path): string
    {
        return strtoupper($method) . ':' . $path;
    }

    /**
     * Check if route matches a pattern
     *
     * @param string $route
     * @param string $pattern
     * @return bool
     */
    private function routeMatchesPattern(string $route, string $pattern): bool
    {
        return fnmatch($pattern, $route);
    }

    /**
     * Get middleware for a specific route
     *
     * @param string $method
     * @param string $path
     * @return array
     */
    public function getMiddlewareForRoute(string $method, string $path): array
    {
        $route = $this->formatRoute($method, $path);
        $middleware = $this->globalMiddleware;

        // Add route-specific middleware
        if (isset($this->routeMiddleware[$route])) {
            foreach ($this->routeMiddleware[$route] as $m) {
                $middleware[] = $m;
            }
        }

        // Add group middleware
        foreach ($this->groups as $group) {
            if ($this->routeMatchesPattern($route, $group['pattern'])) {
                $middleware[] = $group['middleware'];
            }
        }

        return $middleware;
    }

    /**
     * Resolve middleware from name or callable
     *
     * @param string|callable|MiddlewareInterface $middleware
     * @return MiddlewareInterface
     * @throws \InvalidArgumentException
     */
    private function resolveMiddleware($middleware): MiddlewareInterface
    {
        // If it's already a PSR-15 middleware, return it
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        // If it's a string, try to resolve it from the container
        if (is_string($middleware)) {
            if (isset($this->namedMiddleware[$middleware])) {
                return $this->resolveMiddleware($this->namedMiddleware[$middleware]);
            }

            if ($this->container && $this->container->has($middleware)) {
                $resolvedMiddleware = $this->container->get($middleware);
                if ($resolvedMiddleware instanceof MiddlewareInterface) {
                    return $resolvedMiddleware;
                }
            }

            throw new \InvalidArgumentException("Middleware '$middleware' not found or not a valid middleware");
        }

        // If it's a callable, wrap it in a PSR-15 compatible middleware
        if (is_callable($middleware)) {
            return new CallableMiddlewareAdapter($middleware);
        }

        throw new \InvalidArgumentException('Middleware must be a string, callable, or MiddlewareInterface instance');
    }

    /**
     * Run middleware stack for a route
     *
     * @param ServerRequestInterface $request
     * @param callable $handler Final request handler
     * @return ResponseInterface
     */
    public function run(ServerRequestInterface $request, callable $handler): ResponseInterface
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        $middlewareList = $this->getMiddlewareForRoute($method, $path);

        // Resolve all middleware to PSR-15 compatible instances
        $psr15Middleware = [];
        foreach ($middlewareList as $middleware) {
            try {
                $psr15Middleware[] = $this->resolveMiddleware($middleware);
            } catch (\InvalidArgumentException $e) {
                // Log and continue, skipping this middleware
                if ($this->container && $this->container->has('logger')) {
                    $this->container->get('logger')->warning('Failed to resolve middleware', [
                        'error' => $e->getMessage(),
                        'middleware' => is_string($middleware) ? $middleware : get_class($middleware)
                    ]);
                }
            }
        }

        // Create callable adapter for the final handler
        $handlerAdapter = function (ServerRequestInterface $request) use ($handler): ResponseInterface {
            $response = call_user_func($handler, $request);

            // Ensure we return a ResponseInterface
            if (!$response instanceof ResponseInterface) {
                // If handler returns something else, convert to Response
                if (is_string($response)) {
                    return (new Response())->body($response);
                } elseif (is_array($response) || is_object($response)) {
                    return (new Response())->json()->withJson($response);
                } else {
                    return new Response();
                }
            }

            return $response;
        };

        // Create request handler with middleware stack
        $requestHandler = new RequestHandler($handlerAdapter, $psr15Middleware);

        // Process the request through the middleware stack
        return $requestHandler->handle($request);
    }
}