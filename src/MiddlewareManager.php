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
use Ody\Foundation\Middleware\MiddlewarePipeline;
use Ody\Foundation\Middleware\MiddlewareRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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

        // Create the middleware registry
        $this->registry = new Middleware\MiddlewareRegistry(
            $container,
            $logger,
            $collectStats
        );
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
        $pipeline = $this->buildPipeline($method, $path, $finalHandler);

//        // Track terminating middleware for this request
//        $this->collectTerminatingMiddleware($middlewareList);

        // Process the request through the pipeline
        return $pipeline->handle($request);
    }

    /**
     * Build a middleware pipeline for a specific route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param callable|RequestHandlerInterface $finalHandler
     * @return MiddlewarePipeline
     */
    public function buildPipeline(string $method, string $path, $finalHandler): MiddlewarePipeline
    {
        // Get middleware for this route
        $middlewareList = $this->registry->buildPipeline($method, $path);

        $this->logger->debug("Built middleware pipeline", [
            'count' => count($middlewareList),
            'method' => $method,
            'path' => $path
        ]);

        // Create and return the pipeline
        return new MiddlewarePipeline(
            $this->registry,
            $middlewareList,
            $finalHandler,
            $this->logger
        );
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
                $instance = $this->resolutionCache->resolve($middleware);

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