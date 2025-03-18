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
use Ody\Foundation\Middleware\MiddlewareRegistry;
use Ody\Foundation\Middleware\TerminatingMiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Application Middleware Manager
 *
 * Simplified facade that coordinates between the MiddlewareRegistry
 * and MiddlewarePipeline to provide a clean interface for the Application.
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
     * @var MiddlewareRegistry
     */
    protected MiddlewareRegistry $registry;

    /**
     * @var array Middleware instances that implement TerminatingMiddlewareInterface
     */
    protected array $terminatingMiddleware = [];

    /**
     * Constructor
     *
     * @param Container $container
     * @param LoggerInterface|null $logger
     * @param bool $collectStats Whether to collect middleware resolution stats
     */
    public function __construct(
        Container $container,
        ?LoggerInterface $logger = null,
        bool      $collectStats = false
    )
    {
        $this->container = $container;
        $this->logger = $logger ?? new NullLogger();

        // Use the registry from the container if available, otherwise create a new one
        if ($container->has(MiddlewareRegistry::class)) {
            $this->registry = $container->make(MiddlewareRegistry::class);
        } else {
            $this->registry = new Middleware\MiddlewareRegistry(
                $container,
                $logger,
                $collectStats
            );
        }
    }

    /**
     * Process a request through middleware
     *
     * @param ServerRequestInterface $request
     * @param string $method HTTP method
     * @param string $path Route path
     * @param callable|RequestHandlerInterface $finalHandler
     * @return ResponseInterface
     */
    public function process(
        ServerRequestInterface $request,
        string                 $method,
        string                 $path,
                               $finalHandler
    ): ResponseInterface
    {
        $this->logger->debug("Processing request through middleware: {$method} {$path}");

        // Build the middleware pipeline for this route
        $dispatcher = $this->createDispatcher($method, $path, $finalHandler);

        // Process the request through the pipeline
        return $dispatcher->handle($request);
    }

    /**
     * Build a middleware pipeline for a specific route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param callable|RequestHandlerInterface $finalHandler
     * @return MiddlewareDispatcher
     */
    public function createDispatcher(
        string $method,
        string $path,
        callable|RequestHandlerInterface $finalHandler
    ): MiddlewareDispatcher
    {
        // Get middleware for this route
        $middlewareList = $this->registry->buildPipeline($method, $path);

        // Track terminating middleware for this request
        $this->collectTerminatingMiddleware($middlewareList);

        $this->logger->debug("Built middleware pipeline", [
            'count' => count($middlewareList),
            'method' => $method,
            'path' => $path
        ]);

        // Create the dispatcher
        $dispatcher = new MiddlewareDispatcher(
            $this->container,
            $finalHandler,
            $this->logger
        );

        // Add all middleware to the dispatcher
        $dispatcher->addMultiple($middlewareList);

        return $dispatcher;
    }

    /**
     * Collect middleware instances that implement TerminatingMiddlewareInterface
     *
     * @param array $middlewareList
     * @return void
     */
    protected function collectTerminatingMiddleware(array $middlewareList): void
    {
        foreach ($middlewareList as $middleware) {
            try {
                // First, try to get a resolved instance
                $instance = null;

                // If it's already a usable middleware instance, use it directly
                if ($middleware instanceof MiddlewareInterface) {
                    $instance = $middleware;
                } else {
                    // Otherwise, resolve it from container or instantiate it
                    if (is_string($middleware)) {
                        // Check if it's a named middleware first
                        $namedMiddleware = $this->registry->getNamedMiddleware();
                        if (isset($namedMiddleware[$middleware])) {
                            $middleware = $namedMiddleware[$middleware];
                        }

                        // Resolve from container
                        if ($this->container->has($middleware)) {
                            $instance = $this->container->make($middleware);
                        } else if (class_exists($middleware)) {
                            $instance = new $middleware();
                        }
                    }
                }

                if ($instance instanceof TerminatingMiddlewareInterface) {
                    $this->terminatingMiddleware[] = $instance;
                }
            } catch (\Throwable $e) {
                $this->logger->warning("Failed to resolve terminating middleware", [
                    'middleware' => is_string($middleware) ? $middleware : gettype($middleware),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Run terminating middleware after the response has been sent
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return void
     */
    public function terminate(ServerRequestInterface $request, ResponseInterface $response): void
    {
        foreach ($this->terminatingMiddleware as $middleware) {
            try {
                $middleware->terminate($request, $response);
            } catch (\Throwable $e) {
                $this->logger->error("Error in terminating middleware", [
                    'middleware' => get_class($middleware),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        // Clear terminating middleware for this request
        $this->terminatingMiddleware = [];
    }

    /**
     * Add a global middleware
     *
     * @param mixed $middleware
     * @return self
     */
    public function addGlobal($middleware): self
    {
        $this->registry->global($middleware);
        return $this;
    }

    /**
     * Add middleware for a specific route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param mixed $middleware
     * @return self
     */
    public function addForRoute(string $method, string $path, $middleware): self
    {
        $this->registry->forRoute($method, $path, $middleware);
        return $this;
    }

    /**
     * Register a named middleware
     *
     * @param string $name
     * @param mixed $middleware
     * @return self
     */
    public function registerNamed(string $name, $middleware): self
    {
        $this->registry->name($name, $middleware);
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
        $this->registry->registerNamedMiddleware($namedMiddleware);

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
        $this->registry->group($name, $middleware);
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
        $this->registry->registerGroups($groups);
        return $this;
    }

    /**
     * Register configuration from an array
     *
     * @param array $config
     * @return self
     */
    public function registerFromConfig(array $config): self
    {
        $this->registry->fromConfig($config);
        return $this;
    }

    /**
     * Get middleware registry statistics
     *
     * @return array
     */
    public function getCacheStats(): array
    {
        return $this->registry->getCacheStats();
    }

    /**
     * Clear the middleware cache
     *
     * @return self
     */
    public function clearCache(): self
    {
        $this->registry->clearCache();
        return $this;
    }

    /**
     * Get the underlying registry
     *
     * @return MiddlewareRegistry
     */
    public function getRegistry(): MiddlewareRegistry
    {
        return $this->registry;
    }
}