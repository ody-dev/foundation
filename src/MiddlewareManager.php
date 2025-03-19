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
use Ody\Foundation\Middleware\AttributeResolver;
use Ody\Foundation\Middleware\MiddlewareRegistry;
use Ody\Foundation\Middleware\MiddlewareResolutionCache;
use Ody\Foundation\Middleware\TerminatingMiddlewareInterface;
use Ody\Swoole\Coroutine\ContextManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Middleware Manager
 *
 * Manages middleware registration and execution
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
     * @var MiddlewareRegistry|null
     */
    protected ?MiddlewareRegistry $registry = null;

    /**
     * @var MiddlewareResolutionCache|null
     */
    protected ?MiddlewareResolutionCache $resolutionCache = null;

    /**
     * @var AttributeResolver|null
     */
    protected ?AttributeResolver $attributeResolver = null;

    /**
     * @var bool Whether to collect cache statistics
     */
    protected bool $collectStats;

    /**
     * Constructor
     *
     * @param Container $container
     * @param LoggerInterface|null $logger
     * @param bool $collectStats Whether to collect cache statistics
     */
    public function __construct(
        Container $container,
        ?LoggerInterface $logger = null,
        bool $collectStats = false
    ) {
        $this->container = $container;
        $this->logger = $logger ?? new NullLogger();
        $this->collectStats = $collectStats;
    }

    /**
     * Get the middleware registry
     *
     * @return MiddlewareRegistry
     */
    public function getRegistry(): MiddlewareRegistry
    {
        if (!$this->registry) {
            $this->registry = $this->container->has(MiddlewareRegistry::class)
                ? $this->container->make(MiddlewareRegistry::class)
                : new MiddlewareRegistry($this->container, $this->logger, $this->collectStats);
        }

        return $this->registry;
    }

    /**
     * Get the middleware resolution cache
     *
     * @return MiddlewareResolutionCache
     */
    protected function getResolutionCache(): MiddlewareResolutionCache
    {
        if (!$this->resolutionCache) {
            $this->resolutionCache = new MiddlewareResolutionCache(
                $this->container,
                $this->logger,
                $this->collectStats
            );
        }

        return $this->resolutionCache;
    }

    /**
     * Get the attribute resolver
     *
     * @return AttributeResolver
     */
    public function getAttributeResolver(): AttributeResolver
    {
        if (!$this->attributeResolver) {
            $this->attributeResolver = new AttributeResolver($this->logger);
        }

        return $this->attributeResolver;
    }

    /**
     * Register middleware from configuration
     *
     * @param array $config
     * @return self
     */
    public function registerFromConfig(array $config): self
    {
        $this->getRegistry()->fromConfig($config);
        return $this;
    }

    /**
     * Add middleware for a specific route
     *
     * @param string $method
     * @param string $path
     * @param mixed $middleware
     * @return self
     */
    public function addForRoute(string $method, string $path, $middleware): self
    {
        $this->getRegistry()->forRoute($method, $path, $middleware);
        return $this;
    }

    /**
     * Get middleware stack for a route
     *
     * @param string $method
     * @param string $path
     * @return array
     */
    public function getStackForRoute(string $method, string $path): array
    {
        return $this->getRegistry()->buildPipeline($method, $path);
    }

    /**
     * Create a middleware pipeline for a controller-based route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param string|object $controller Controller class or instance
     * @param string $action Controller method name
     * @return array Combined middleware stack
     */
    public function getStackForControllerRoute(
        string $method,
        string $path,
               $controller,
        string $action
    ): array {
        // Get route middleware from the registry
        $routeMiddleware = $this->getRegistry()->buildPipeline($method, $path);

        // Get attribute-based middleware for the controller and method
        $attributeMiddleware = $this->getAttributeResolver()->getMiddleware($controller, $action);

        // Convert attribute middleware format to registry format
        $normalizedAttributeMiddleware = [];
        foreach ($attributeMiddleware as $middleware) {
            if (isset($middleware['class'])) {
                // For class-based middleware
                $normalizedAttributeMiddleware[] = $middleware['class'];
            } else if (isset($middleware['group'])) {
                // For group-based middleware
                $normalizedAttributeMiddleware[] = $middleware['group'];
            }
        }

        // Merge the middleware stacks (attribute middleware applied after route middleware)
        return array_merge($routeMiddleware, $normalizedAttributeMiddleware);
    }

    /**
     * Resolve a middleware to an instance
     *
     * @param mixed $middleware
     * @return MiddlewareInterface
     */
    public function resolveMiddleware($middleware): MiddlewareInterface
    {
        return $this->getResolutionCache()->resolve($middleware);
    }

    /**
     * Handle terminating middleware
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return void
     */
    public function terminate(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        // Get controller/action info from request attributes
        $controller = ContextManager::get('_controller');
        $action = ContextManager::get('_action');

        // If we have controller info, use getStackForControllerRoute
        if ($controller && $action) {
            $stack = $this->getStackForControllerRoute($method, $path, $controller, $action);
        } else {
            // Fall back to route-based middleware only
            $stack = $this->getStackForRoute($method, $path);
        }

        logger()->debug('MiddlewareManager: terminate()', [
            'count' => count($stack),
            'has_controller' => !empty($controller),
            'controller' => $controller,
            'action' => $action
        ]);

        // Process all middleware for termination
        foreach ($stack as $middleware) {
            try {
                // Resolve middleware instance
                $instance = $this->resolveMiddleware($middleware);

                // Check if it implements TerminatingMiddlewareInterface
                if ($instance instanceof TerminatingMiddlewareInterface) {
                    $this->logger->debug('Executing terminate() on middleware: ' .
                        (is_object($instance) ? get_class($instance) : (is_string($instance) ? $instance : gettype($instance))));
                    $instance->terminate($request, $response);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Error in terminating middleware', [
                    'middleware' => is_object($middleware) ? get_class($middleware) : (is_string($middleware) ? $middleware : gettype($middleware)),
                    'error' => $e->getMessage()
                ]);
            }
        }
        // Clear all data from the context manager
        // TODO: this has to happen at the end of each request.
        ContextManager::clear();
    }
}