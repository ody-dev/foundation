<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation;

use Ody\Container\Container;
use Ody\Foundation\Middleware\MiddlewareStack;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Application Middleware Manager
 *
 * Manages middleware configuration and execution at the application level.
 */
class MiddlewareManager
{
    /**
     * @var Container
     */
    protected Container $container;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var array Global middleware applied to all requests
     */
    protected array $globalMiddleware = [];

    /**
     * @var array Route-specific middleware
     */
    protected array $routeMiddleware = [];

    /**
     * Constructor
     *
     * @param Container $container
     * @param LoggerInterface|null $logger
     */
    public function __construct(Container $container, ?LoggerInterface $logger = null)
    {
        $this->container = $container;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Add global middleware
     *
     * @param string|MiddlewareInterface $middleware
     * @return self
     */
    public function addGlobal($middleware): self
    {
        $this->globalMiddleware[] = $middleware;
        return $this;
    }

    /**
     * Add middleware for a specific route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param string|MiddlewareInterface $middleware
     * @return self
     */
    public function addForRoute(string $method, string $path, $middleware): self
    {
        $routeKey = $this->formatRouteKey($method, $path);

        if (!isset($this->routeMiddleware[$routeKey])) {
            $this->routeMiddleware[$routeKey] = [];
        }

        $this->routeMiddleware[$routeKey][] = $middleware;
        return $this;
    }

    /**
     * Format a route key
     *
     * @param string $method
     * @param string $path
     * @return string
     */
    protected function formatRouteKey(string $method, string $path): string
    {
        return strtoupper($method) . ':' . $path;
    }

    /**
     * Process a request through middleware for a specific route
     *
     * @param ServerRequestInterface $request
     * @param string $method HTTP method
     * @param string $path Route path
     * @param callable $handler The final handler that processes the request if no middleware terminates early
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, string $method, string $path, callable $handler): ResponseInterface
    {
        $stack = $this->createStackForRoute($method, $path, $handler);
        return $stack->handle($request);
    }

    /**
     * Create a middleware stack for a route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param RequestHandlerInterface|callable $finalHandler
     * @return MiddlewareStack
     */
    public function createStackForRoute(string $method, string $path, $finalHandler): MiddlewareStack
    {
        // Convert callable to handler if needed
        if (is_callable($finalHandler) && !$finalHandler instanceof RequestHandlerInterface) {
            $finalHandler = new class($finalHandler) implements RequestHandlerInterface {
                private $handler;

                public function __construct(callable $handler)
                {
                    $this->handler = $handler;
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return call_user_func($this->handler, $request);
                }
            };
        }

        // Create the middleware stack
        $stack = new MiddlewareStack($this->container, $finalHandler, $this->logger);

        // Add route-specific middleware in reverse order (so they execute in the right order)
        $middleware = array_reverse($this->getForRoute($method, $path));

        foreach ($middleware as $m) {
            $stack->add($m);
        }

        return $stack;
    }

    /**
     * Get middleware for a specific route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @return array
     */
    public function getForRoute(string $method, string $path): array
    {
        $routeKey = $this->formatRouteKey($method, $path);
        $routeSpecificMiddleware = $this->routeMiddleware[$routeKey] ?? [];

        // Combine global and route-specific middleware
        return array_merge($this->globalMiddleware, $routeSpecificMiddleware);
    }
}