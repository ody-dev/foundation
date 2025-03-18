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
 * MiddlewareResolutionCache
 *
 * Provides caching for middleware resolution to avoid repeated container lookups.
 * This improves performance in long-running processes like Swoole servers.
 */
class MiddlewareResolutionCache
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
     * @var array Cache of resolved middleware instances
     */
    protected array $resolvedMiddleware = [];

    /**
     * @var array Count of cache hits for statistics
     */
    protected array $cacheHits = [];

    /**
     * @var bool Whether to collect cache statistics
     */
    protected bool $collectStats;

    private $maxCacheSize = 100;

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
     * Resolve middleware and cache the result
     *
     * @param mixed $middleware String class name, MiddlewareInterface instance, or callable
     * @return MiddlewareInterface|callable
     * @throws \RuntimeException If middleware cannot be resolved
     */
    public function resolve($middleware)
    {
        // If it's already a usable middleware instance, return it directly
        if ($middleware instanceof MiddlewareInterface ||
            (is_callable($middleware) && !is_string($middleware))) {
            return $middleware;
        }

        // Check cache size and prune if needed
        if (count($this->resolvedMiddleware) > $this->maxCacheSize) {
            // Remove the least used entries
            array_shift($this->resolvedMiddleware);
        }

        // Generate a cache key for the middleware
        $cacheKey = $this->generateCacheKey($middleware);

        // Check if we've already resolved this middleware
        if (isset($this->resolvedMiddleware[$cacheKey])) {
            // Track cache hit if stats are enabled
            if ($this->collectStats) {
                $this->cacheHits[$cacheKey] = ($this->cacheHits[$cacheKey] ?? 0) + 1;
            }

            return $this->resolvedMiddleware[$cacheKey];
        }

        // Need to resolve the middleware
        try {
            $instance = $this->resolveMiddlewareInstance($middleware);

            // Cache the resolved instance
            $this->resolvedMiddleware[$cacheKey] = $instance;

            return $instance;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to resolve middleware', [
                'middleware' => is_string($middleware) ? $middleware : gettype($middleware),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException(
                'Failed to resolve middleware: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Generate a cache key for a middleware
     *
     * @param mixed $middleware
     * @return string
     */
    protected function generateCacheKey($middleware): string
    {
        if (is_string($middleware)) {
            return 'string:' . $middleware;
        }

        if (is_array($middleware)) {
            // Handle [class, method] callable format
            return 'array:' . (is_object($middleware[0])
                    ? get_class($middleware[0])
                    : (string)$middleware[0]) . '::' . (string)$middleware[1];
        }

        if (is_object($middleware)) {
            return 'object:' . get_class($middleware) . ':' . spl_object_hash($middleware);
        }

        // For other types (closure, etc)
        return 'other:' . gettype($middleware) . ':' . spl_object_hash($middleware);
    }

    /**
     * Actually resolve a middleware instance from various formats
     *
     * @param mixed $middleware
     * @return MiddlewareInterface|callable
     * @throws \RuntimeException If the middleware cannot be resolved
     */
    protected function resolveMiddlewareInstance($middleware)
    {
        // Handle string class names
        if (is_string($middleware) && class_exists($middleware)) {
            // Try to resolve from the container
            if ($this->container->has($middleware)) {
                $instance = $this->container->make($middleware);
            } else {
                // Or create an instance directly
                $instance = new $middleware();
            }

            // Validate the instance
            if ($instance instanceof MiddlewareInterface) {
                return $instance;
            }

            if (is_callable($instance)) {
                return $instance;
            }

            throw new \RuntimeException(
                "Middleware class '$middleware' must implement MiddlewareInterface or be callable"
            );
        }

        // Handle callables (but not class string callables, which are handled above)
        if (is_callable($middleware) && !is_string($middleware)) {
            return new CallableMiddlewareAdapter($middleware);
        }

        // Handle middleware by string name in container
        if (is_string($middleware)) {
            // Check if registered with container directly
            if ($this->container->has("middleware.{$middleware}")) {
                return $this->container->make("middleware.{$middleware}");
            }
        }

        throw new \RuntimeException(
            sprintf(
                'Middleware must be a class name, instance, or callable; %s given',
                is_object($middleware) ? get_class($middleware) : gettype($middleware)
            )
        );
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public function getStats(): array
    {
        return [
            'cached_middleware' => count($this->resolvedMiddleware),
            'cache_hits' => $this->cacheHits,
            'total_hits' => array_sum($this->cacheHits),
        ];
    }

    /**
     * Clear the middleware cache
     *
     * @return void
     */
    public function clear(): void
    {
        $this->resolvedMiddleware = [];
        $this->cacheHits = [];
    }
}