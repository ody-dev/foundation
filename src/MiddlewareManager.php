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
use Ody\Foundation\Middleware\MiddlewareDispatcher;
use Ody\Foundation\Middleware\MiddlewareResolutionCache;
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
 * Enhanced with middleware resolution caching for performance in long-running processes.
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
     * @var MiddlewareResolutionCache
     */
    protected MiddlewareResolutionCache $resolutionCache;

    /**
     * @var array Middleware groups configuration
     */
    protected array $middlewareGroups = [];

    /**
     * @var array Named middleware map
     */
    protected array $namedMiddleware = [];

    /**
     * Constructor
     *
     * @param Container $container
     * @param LoggerInterface|null $logger
     * @param bool $enableStatsCollection Whether to collect middleware resolution stats
     */
    public function __construct(
        Container        $container,
        ?LoggerInterface $logger = null,
        bool             $enableStatsCollection = false
    )
    {
        $this->container = $container;
        $this->logger = $logger ?? new NullLogger();

        // Create the middleware resolution cache
        $this->resolutionCache = new MiddlewareResolutionCache(
            $container,
            $logger,
            $enableStatsCollection
        );
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
     * Register named middleware
     *
     * @param string $name
     * @param string|MiddlewareInterface $middleware
     * @return self
     */
    public function registerNamed(string $name, $middleware): self
    {
        $this->namedMiddleware[$name] = $middleware;
        return $this;
    }

    /**
     * Register multiple named middleware
     *
     * @param array $namedMiddleware
     * @return self
     */
    public function registerNamedMiddleware(array $namedMiddleware): self
    {
        foreach ($namedMiddleware as $name => $middleware) {
            $this->registerNamed($name, $middleware);
        }
        return $this;
    }

    /**
     * Register a middleware group
     *
     * @param string $name
     * @param array $middleware
     * @return self
     */
    public function registerGroup(string $name, array $middleware): self
    {
        $this->middlewareGroups[$name] = $middleware;
        return $this;
    }

    /**
     * Register multiple middleware groups
     *
     * @param array $groups
     * @return self
     */
    public function registerGroups(array $groups): self
    {
        foreach ($groups as $name => $middleware) {
            $this->registerGroup($name, $middleware);
        }
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
     * @param callable|RequestHandlerInterface $handler Final handler
     * @return ResponseInterface
     */
    public function process(
        ServerRequestInterface $request,
        string                 $method,
        string                 $path,
                               $handler
    ): ResponseInterface
    {
        // Get all middleware for this route
        $middlewareList = $this->getForRoute($method, $path);

        // Resolve named middleware
        $request = $this->processMiddlewareParameters($request, $middlewareList);

        // Create the middleware dispatcher
        $dispatcher = $this->createDispatcher($middlewareList, $handler);

        // Process the request through middleware
        return $dispatcher->handle($request);
    }

    /**
     * Process middleware list before dispatching
     *
     * @param ServerRequestInterface $request
     * @param array $middlewareList
     * @return ServerRequestInterface
     */
    protected function processMiddlewareParameters(
        ServerRequestInterface $request,
        array                  &$middlewareList
    ): ServerRequestInterface
    {
        $processedList = [];

        foreach ($middlewareList as $middleware) {
            // Check if this is a named middleware
            if (is_string($middleware) && isset($this->namedMiddleware[$middleware])) {
                $processedList[] = $this->namedMiddleware[$middleware];
            } else {
                $processedList[] = $middleware;
            }
        }

        // Replace the original list with processed list
        $middlewareList = $processedList;

        return $request;
    }

    /**
     * Create a middleware dispatcher with the given middleware list
     *
     * @param array $middlewareList
     * @param callable|RequestHandlerInterface $finalHandler
     * @return MiddlewareDispatcher
     */
    protected function createDispatcher(array $middlewareList, $finalHandler): MiddlewareDispatcher
    {
        // Create the dispatcher with the final handler
        $dispatcher = new MiddlewareDispatcher(
            $this->container,
            $finalHandler,
            $this->logger
        );

        // Add middleware in reverse order so they execute in the correct order
        $middlewareList = array_reverse($middlewareList);

        foreach ($middlewareList as $middleware) {
            try {
                // Resolve the middleware with caching
                $resolvedMiddleware = $this->resolutionCache->resolve($middleware);

                // Add to the dispatcher
                $dispatcher->add($resolvedMiddleware);
            } catch (\Throwable $e) {
                $this->logger->warning("Failed to add middleware to dispatcher", [
                    'middleware' => is_string($middleware) ? $middleware : gettype($middleware),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $dispatcher;
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

        // Start with global middleware
        $middleware = $this->globalMiddleware;

        // Add route-specific middleware
        $middleware = array_merge($middleware, $routeSpecificMiddleware);

        // Process middleware groups
        $processedMiddleware = [];
        foreach ($middleware as $item) {
            if (is_string($item) && isset($this->middlewareGroups[$item])) {
                // If this is a middleware group, expand it
                $processedMiddleware = array_merge(
                    $processedMiddleware,
                    $this->middlewareGroups[$item]
                );
            } else {
                $processedMiddleware[] = $item;
            }
        }

        return $processedMiddleware;
    }

    /**
     * Get middleware resolution cache statistics
     *
     * @return array
     */
    public function getCacheStats(): array
    {
        return $this->resolutionCache->getStats();
    }

    /**
     * Clear the middleware resolution cache
     *
     * @return self
     */
    public function clearCache(): self
    {
        $this->resolutionCache->clear();
        return $this;
    }

    /**
     * Get the middleware resolution cache
     *
     * @return MiddlewareResolutionCache
     */
    public function getResolutionCache(): MiddlewareResolutionCache
    {
        return $this->resolutionCache;
    }

    /**
     * Register configuration from config array
     *
     * @param array $config
     * @return self
     */
    public function registerFromConfig(array $config): self
    {
        // Register named middleware
        if (isset($config['named']) && is_array($config['named'])) {
            $this->registerNamedMiddleware($config['named']);
        }

        // Register middleware groups
        if (isset($config['groups']) && is_array($config['groups'])) {
            $this->registerGroups($config['groups']);
        }

        // Register global middleware
        if (isset($config['global']) && is_array($config['global'])) {
            foreach ($config['global'] as $middleware) {
                $this->addGlobal($middleware);
            }
        }

        return $this;
    }
}