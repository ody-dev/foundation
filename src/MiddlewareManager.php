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
use Ody\Foundation\Middleware\MiddlewareRegistry;
use Ody\Foundation\Middleware\TerminatingMiddlewareInterface;
use Ody\Swoole\Coroutine\ContextManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
     * Constructor
     *
     * @param Container $container
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        Container $container,
        ?LoggerInterface $logger = null
    ) {
        $this->container = $container;
        $this->logger = $logger ?? new NullLogger();
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
                : new MiddlewareRegistry($this->container, $this->logger);
        }

        return $this->registry;
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
        $this->getRegistry()->addForRoute($method, $path, $middleware);
        return $this;
    }

    /**
     * Get all middleware for a route
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
     * Get all middleware for a controller route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param string|object|null $controller Controller class or instance
     * @param string|null $action Controller method name
     * @return array
     */
    public function getMiddlewareForRoute(
        string $method,
        string $path,
                $controller = null,
        ?string $action = null
    ): array {
        return $this->getRegistry()->getMiddlewareForRoute($method, $path, $controller, $action);
    }

    /**
     * Resolve a middleware to an instance
     *
     * @param mixed $middleware
     * @return MiddlewareInterface
     */
    public function resolve($middleware): MiddlewareInterface
    {
        return $this->getRegistry()->resolve($middleware);
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

        // Get middleware stack
        $stack = $this->getMiddlewareForRoute($method, $path, $controller, $action);

        $this->logger->debug('MiddlewareManager: terminate()', [
            'count' => count($stack),
            'has_controller' => !empty($controller)
        ]);

        // Process all middleware for termination
        foreach ($stack as $middleware) {
            try {
                // Resolve middleware instance
                $instance = $this->resolve($middleware);

                // Check if it implements TerminatingMiddlewareInterface
                if ($instance instanceof TerminatingMiddlewareInterface) {
                    $this->logger->debug('Executing terminate() on middleware: ' .
                        (is_object($instance) ? get_class($instance) : gettype($instance)));
                    $instance->terminate($request, $response);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Error in terminating middleware', [
                    'middleware' => is_string($middleware) ? $middleware : gettype($middleware),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
