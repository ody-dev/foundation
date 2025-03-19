<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware;

use Ody\Container\Container;
use Ody\Foundation\Middleware\Adapters\CallableMiddlewareAdapter;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Unified Middleware Registry
 *
 * Manages middleware registration, resolution, and pipeline building.
 */
class MiddlewareRegistry
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
    protected array $global = [];

    /**
     * @var array Route-specific middleware
     */
    protected array $routes = [];

    /**
     * @var array Named middleware map
     */
    protected array $named = [];

    /**
     * @var array Middleware groups
     */
    protected array $groups = [];

    /**
     * @var array Cache of resolved middleware instances
     */
    protected array $resolved = [];

    /**
     * @var bool Whether to collect cache statistics
     */
    protected bool $collectStats;

    /**
     * @var array Cache hits for statistics
     */
    protected array $cacheHits = [];

    /**
     * Constructor
     *
     * @param Container $container
     * @param LoggerInterface|null $logger
     * @param bool $collectStats Whether to collect cache statistics
     */
    public function __construct(
        Container        $container,
        ?LoggerInterface $logger = null,
        bool             $collectStats = false
    )
    {
        $this->container = $container;
        $this->logger = $logger ?? new NullLogger();
        $this->collectStats = $collectStats;
    }

    /**
     * Register middleware for a specific route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param mixed $middleware
     * @param string|null $id Optional identifier (generated if not provided)
     * @return self
     */
    public function forRoute(string $method, string $path, $middleware, ?string $id = null): self
    {
        $id = $id ?? $this->generateId($middleware);
        return $this->register($id, $middleware, [
            'type' => 'route',
            'method' => $method,
            'path' => $path
        ]);
    }

    /**
     * Generate an identifier for a middleware
     *
     * @param mixed $middleware
     * @return string
     */
    protected function generateId($middleware): string
    {
        if (is_string($middleware)) {
            return 'middleware_' . md5($middleware);
        }

        if (is_object($middleware)) {
            return 'middleware_' . get_class($middleware) . '_' . spl_object_id($middleware);
        }

        return 'middleware_' . md5(serialize($middleware));
    }

    /**
     * Register a middleware with the registry
     *
     * @param string $id Unique identifier for the middleware
     * @param mixed $middleware The middleware to register
     * @param array $options Registration options
     * @return self
     */
    public function register(string $id, $middleware, array $options = []): self
    {
        $options = array_merge([
            'type' => 'global',  // global, route, named, or group
            'method' => null,    // for route-specific middleware
            'path' => null,      // for route-specific middleware
            'middlewareList' => [], // for groups
        ], $options);

        switch ($options['type']) {
            case 'global':
                $this->global[$id] = $middleware;
                break;
            case 'route':
                $routeKey = $this->formatRouteKey($options['method'], $options['path']);
                if (!isset($this->routes[$routeKey])) {
                    $this->routes[$routeKey] = [];
                }
                $this->routes[$routeKey][$id] = $middleware;
                break;
            case 'named':
                $this->named[$id] = $middleware;
                break;
            case 'group':
                $this->groups[$id] = $options['middlewareList'];
                break;
        }

        logger()->debug("Registered middleware: {$id}", ['type' => $options['type']]);

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
     * Load middleware configuration from array
     *
     * @param array $config
     * @return self
     */
    public function fromConfig(array $config): self
    {
        // Register named middleware
        if (isset($config['named']) && is_array($config['named'])) {
            $this->registerNamedMiddleware($config['named']);
        }

        // Register groups
        if (isset($config['groups']) && is_array($config['groups'])) {
            $this->registerGroups($config['groups']);
        }

        // Register global middleware
        if (isset($config['global']) && is_array($config['global'])) {
            foreach ($config['global'] as $middleware) {
                $this->global($middleware);
            }
        }

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
            $this->name($name, $middleware);
        }
        return $this;
    }

    /**
     * Register a named middleware
     *
     * @param string $name
     * @param mixed $middleware
     * @return self
     */
    public function name(string $name, $middleware): self
    {
        return $this->register($name, $middleware, ['type' => 'named']);
    }

    /**
     * Register multiple middleware groups
     *
     * @param array $groups
     * @return self
     */
    public function registerGroups(array $groups): self
    {
        foreach ($groups as $name => $middlewareList) {
            $this->group($name, $middlewareList);
        }
        return $this;
    }

    /**
     * Register a middleware group
     *
     * @param string $name
     * @param array $middlewareList
     * @return self
     */
    public function group(string $name, array $middlewareList): self
    {
        return $this->register($name, null, [
            'type' => 'group',
            'middlewareList' => $middlewareList
        ]);
    }

    /**
     * Register a global middleware
     *
     * @param mixed $middleware
     * @param string|null $id Optional identifier (generated if not provided)
     * @return self
     */
    public function global($middleware, ?string $id = null): self
    {
        $id = $id ?? $this->generateId($middleware);
        return $this->register($id, $middleware, ['type' => 'global']);
    }

    /**
     * Build a middleware pipeline for a specific route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @return array List of middleware for this route
     */
    public function buildPipeline(string $method, string $path): array
    {
        // Start with global middleware
        $middlewareList = array_values($this->global);

        // Add route-specific middleware if any
        $routeKey = rtrim($this->formatRouteKey($method, $path), '/');
        if (isset($this->routes[$routeKey])) {
            $middlewareList = array_merge($middlewareList, array_values($this->routes[$routeKey]));
        }

        // Process and expand the middleware list
        return $this->expandMiddlewareList($middlewareList);
    }

    /**
     * Expand middleware list, resolving names and groups
     *
     * @param array $middlewareList
     * @return array
     */
    public function expandMiddlewareList(array $middlewareList): array
    {
        $expanded = [];

        foreach ($middlewareList as $middleware) {
            // If it's a string that might be a named middleware or group
            if (is_string($middleware)) {
                // Check if it's a named middleware
                if (isset($this->named[$middleware])) {
                    $namedMiddleware = $this->named[$middleware];

                    $expanded[] = $namedMiddleware;
                    continue;
                }

                // Check if it's a middleware group
                if (isset($this->groups[$middleware])) {
                    // Recursively expand the group
                    $groupMiddleware = $this->expandMiddlewareList($this->groups[$middleware]);
                    $expanded = array_merge($expanded, $groupMiddleware);
                    continue;
                }
            }

            // Add the middleware as is
            $expanded[] = $middleware;
        }

        return $expanded;
    }

    /**
     * Resolve a middleware to an instance
     *
     * @param mixed $middleware
     * @return MiddlewareInterface
     * @throws \RuntimeException If middleware cannot be resolved
     */
    public function resolve($middleware): MiddlewareInterface
    {
        // If it's already an instance, return it
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        // Get cache key
        $cacheKey = $this->getCacheKey($middleware);

        // Check cache
        if (isset($this->resolved[$cacheKey])) {
            if ($this->collectStats) {
                $this->cacheHits[$cacheKey] = ($this->cacheHits[$cacheKey] ?? 0) + 1;
            }
            return $this->resolved[$cacheKey];
        }

        try {
            // Resolve the middleware
            $instance = $this->resolveMiddleware($middleware);

            // Cache the result
            $this->resolved[$cacheKey] = $instance;

            return $instance;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to resolve middleware', [
                'middleware' => is_string($middleware) ? $middleware : gettype($middleware),
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException(
                'Failed to resolve middleware: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get a cache key for a middleware
     *
     * @param mixed $middleware
     * @return string
     */
    protected function getCacheKey($middleware): string
    {
        if (is_string($middleware)) {
            return 'string:' . $middleware;
        }

        if (is_array($middleware)) {
            return 'array:' . (is_object($middleware[0])
                    ? get_class($middleware[0])
                    : (string)$middleware[0]) . '::' . (string)$middleware[1];
        }

        if (is_object($middleware)) {
            return 'object:' . get_class($middleware) . ':' . spl_object_id($middleware);
        }

        return 'other:' . gettype($middleware);
    }

    /**
     * Resolve a middleware to an instance
     *
     * @param mixed $middleware
     * @return MiddlewareInterface
     * @throws \RuntimeException If middleware cannot be resolved
     */
    protected function resolveMiddleware($middleware): MiddlewareInterface
    {
        // Handle string class names
        if (is_string($middleware) && class_exists($middleware)) {
            // Try to resolve from container
            if ($this->container->has($middleware)) {
                $instance = $this->container->make($middleware);
            } else {
                // Create the instance directly
                $instance = new $middleware();
            }

            // Ensure it's a valid middleware
            if ($instance instanceof MiddlewareInterface) {
                return $instance;
            }

            // Convert callable to middleware adapter
            if (is_callable($instance)) {
                return new CallableMiddlewareAdapter($instance);
            }

            throw new \RuntimeException(
                "Middleware class '$middleware' must implement MiddlewareInterface or be callable"
            );
        }

        // Handle callable middleware
        if (is_callable($middleware)) {
            return new CallableMiddlewareAdapter($middleware);
        }

        throw new \RuntimeException(
            'Middleware must be a class name, instance of MiddlewareInterface, or callable'
        );
    }

    /**
     * Get all registered global middleware
     *
     * @return array
     */
    public function getGlobalMiddleware(): array
    {
        return $this->global;
    }

    /**
     * Get all registered route middleware
     *
     * @return array
     */
    public function getRouteMiddleware(): array
    {
        return $this->routes;
    }

    /**
     * Get all named middleware
     *
     * @return array
     */
    public function getNamedMiddleware(): array
    {
        return $this->named;
    }

    /**
     * Get all middleware groups
     *
     * @return array
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public function getCacheStats(): array
    {
        return [
            'cached_middleware' => count($this->resolved),
            'cache_hits' => $this->cacheHits,
            'total_hits' => array_sum($this->cacheHits)
        ];
    }

    /**
     * Clear the middleware cache
     *
     * @return self
     */
    public function clearCache(): self
    {
        $this->resolved = [];
        $this->cacheHits = [];
        return $this;
    }
}