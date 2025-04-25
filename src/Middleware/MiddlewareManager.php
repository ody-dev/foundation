<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware;

use Ody\Container\Container;
use Ody\Container\Contracts\BindingResolutionException;
use Ody\Swoole\Coroutine\ContextManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function gettype;

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
     * @var MiddlewareResolver|null
     */
    protected ?MiddlewareResolver $registry = null;

    /**
     * Constructor
     *
     * @param Container $container
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        Container        $container,
        ?LoggerInterface $logger = null
    )
    {
        $this->container = $container;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Register middleware from configuration
     *
     * @param array $config
     * @return self
     * @throws BindingResolutionException
     */
    public function registerFromConfig(array $config): self
    {
        $this->getRegistry()->fromConfig($config);
        return $this;
    }

    /**
     * Get the middleware registry
     *
     * @return MiddlewareResolver
     * @throws BindingResolutionException
     */
    public function getRegistry(): MiddlewareResolver
    {
        if (!$this->registry) {
            $this->registry = $this->container->has(MiddlewareResolver::class)
                ? $this->container->make(MiddlewareResolver::class)
                : new MiddlewareResolver($this->container, $this->logger);
        }

        return $this->registry;
    }

    /**
     * Add middleware for a specific route
     *
     * @param string $method
     * @param string $path
     * @param mixed $middleware
     * @return self
     * @throws BindingResolutionException
     */
    public function addForRoute(string $method, string $path, mixed $middleware): self
    {
        $this->getRegistry()->addForRoute($method, $path, $middleware);
        return $this;
    }

    /**
     * Handle terminating middleware
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return void
     * @throws BindingResolutionException
     */
    public function terminate(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        // Get handler info from request attributes
        $handler = ContextManager::get('_handler');

        // Get middleware stack
        $stack = $this->getMiddlewareForRoute($method, $path, $this->container->make($handler));

        // Process all middleware for termination
        foreach ($stack as $middleware) {
            try {
                // Resolve middleware instance
                $instance = $this->resolve($middleware);

                // Check if it implements TerminatingMiddlewareInterface
                if ($instance instanceof TerminatingMiddlewareInterface) {
                    $this->logger->debug('Executing terminate() on middleware: ' . get_class($instance));
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

    /**
     * Get all middleware for a controller route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param RequestHandlerInterface $handler
     * @return array
     * @throws BindingResolutionException
     */
    public function getMiddlewareForRoute(
        string                  $method,
        string                  $path,
        RequestHandlerInterface $handler,
    ): array
    {
        return $this->getRegistry()->getMiddlewareForRoute($method, $path, $handler);
    }

    /**
     * Resolve a middleware to an instance
     *
     * @param mixed $middleware
     * @return MiddlewareInterface
     * @throws BindingResolutionException
     */
    public function resolve(mixed $middleware): MiddlewareInterface
    {
        return $this->getRegistry()->resolve($middleware);
    }
}
