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
use Ody\Foundation\Middleware\Adapters\CallableHandlerAdapter;
use Ody\Foundation\Middleware\Adapters\CallableMiddlewareAdapter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Middleware Dispatcher
 *
 * A consolidated PSR-15 middleware dispatcher that handles a middleware pipeline.
 * Combines functionality from MiddlewareStack and MiddlewareDispatcher.
 */
class MiddlewareDispatcher implements RequestHandlerInterface
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
     * @var array Middleware stack
     */
    protected array $middleware = [];

    /**
     * @var callable|RequestHandlerInterface The final handler to process requests if no middleware handles it
     */
    protected $finalHandler;

    /**
     * @var array Cache of resolved middleware instances
     */
    protected array $resolvedMiddleware = [];

    /**
     * Constructor
     *
     * @param Container $container
     * @param callable|RequestHandlerInterface|null $finalHandler
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        Container $container,
                  $finalHandler = null,
        ?LoggerInterface $logger = null
    )
    {
        $this->container = $container;

        // Set default final handler if none provided
        if ($finalHandler === null) {
            $this->finalHandler = function (ServerRequestInterface $request) {
                throw new \RuntimeException('No handler available for request');
            };
        } else {
            $this->finalHandler = $finalHandler;
        }
    }

    /**
     * Add middleware to the stack
     *
     * @param string|MiddlewareInterface|callable $middleware
     * @return self
     */
    public function add($middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Add multiple middleware at once
     *
     * @param array $middlewareList
     * @return self
     */
    public function addMultiple(array $middlewareList): self
    {
        foreach ($middlewareList as $middleware) {
            $this->add($middleware);
        }
        return $this;
    }

    /**
     * Set the final handler
     *
     * @param callable|RequestHandlerInterface $handler
     * @return self
     */
    public function setFinalHandler($handler): self
    {
        $this->finalHandler = $handler;
        return $this;
    }

    /**
     * Get the middleware stack
     *
     * @return array
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Process a request through the middleware stack
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (empty($this->middleware)) {
            return $this->runFinalHandler($request);
        }

        // Take a copy of the middleware stack
        $stack = $this->middleware;

        // Get the first middleware
        $middleware = array_shift($stack);

        try {
            // Get middleware instance (resolved or new)
            $instance = $this->getMiddlewareInstance($middleware);

            // Create a dispatcher for the remaining middleware
            $next = $this->createNextHandler($stack);

            // Process the request through the middleware
            if ($instance instanceof MiddlewareInterface) {
                // For PSR-15 middleware
                $handler = new CallableHandlerAdapter($next);
                $response = $instance->process($request, $handler);
            } else {
                // For callable middleware (request-response-next format)
                $response = $instance($request, $next);
            }

            // Ensure the response is valid
            if (!$response instanceof ResponseInterface) {
                throw new \RuntimeException(sprintf(
                    'Middleware "%s" must return an instance of ResponseInterface',
                    is_object($middleware) ? get_class($middleware) : gettype($middleware)
                ));
            }

            return $response;
        } catch (\Throwable $e) {
            $this->logMiddlewareError($middleware, $e);
            throw $e;
        }
    }

    /**
     * Create a handler for the next middleware in the stack
     *
     * @param array $stack Remaining middleware stack
     * @return callable
     */
    protected function createNextHandler(array $stack): callable
    {
        return function (ServerRequestInterface $request) use ($stack) {
            // If there's no more middleware, run the final handler
            if (empty($stack)) {
                return $this->runFinalHandler($request);
            }

            // Get the next middleware
            $middleware = array_shift($stack);
            $instance = $this->getMiddlewareInstance($middleware);

            // Create next handler with remaining stack
            $next = $this->createNextHandler($stack);

            // Process through middleware
            if ($instance instanceof MiddlewareInterface) {
                $handler = new CallableHandlerAdapter($next);
                return $instance->process($request, $handler);
            } else {
                return $instance($request, $next);
            }
        };
    }

    /**
     * Run the final handler
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    protected function runFinalHandler(ServerRequestInterface $request): ResponseInterface
    {
        // If the final handler is a RequestHandlerInterface, use its handle method
        if ($this->finalHandler instanceof RequestHandlerInterface) {
            return $this->finalHandler->handle($request);
        }

        // Otherwise, call it as a callable
        return call_user_func($this->finalHandler, $request);
    }

    /**
     * Get a middleware instance, either from cache or by resolving it
     *
     * @param mixed $middleware
     * @return MiddlewareInterface|callable
     */
    protected function getMiddlewareInstance($middleware)
    {
        // If it's already a resolved middleware instance, return it
        if ($middleware instanceof MiddlewareInterface || (is_callable($middleware) && !is_string($middleware))) {
            return $middleware;
        }

        // If it's a string (class name), check if it's already been resolved
        if (is_string($middleware)) {
            if (isset($this->resolvedMiddleware[$middleware])) {
                return $this->resolvedMiddleware[$middleware];
            }

            // Try to resolve the middleware
            $instance = $this->resolveMiddleware($middleware);

            // Cache the resolved instance
            $this->resolvedMiddleware[$middleware] = $instance;

            return $instance;
        }

        // If we get here, we couldn't resolve the middleware
        throw new \RuntimeException(sprintf(
            'Cannot resolve middleware of type "%s"',
            is_object($middleware) ? get_class($middleware) : gettype($middleware)
        ));
    }

    /**
     * Resolve middleware from string to instance
     *
     * @param string $middleware
     * @return MiddlewareInterface|callable
     * @throws \RuntimeException
     */
    protected function resolveMiddleware(string $middleware)
    {
        // Try to resolve from container if it's a class name
        if (class_exists($middleware)) {
            try {
                // Try to resolve from container
                if ($this->container->has($middleware)) {
                    $instance = $this->container->make($middleware);
                } else {
                    // Create directly as fallback
                    $instance = new $middleware();
                }

                if ($instance instanceof MiddlewareInterface) {
                    return $instance;
                }

                if (is_callable($instance)) {
                    return $instance;
                }

                throw new \RuntimeException(sprintf(
                    'Middleware class "%s" must implement MiddlewareInterface or be callable',
                    $middleware
                ));
            } catch (\Throwable $e) {
                logger()->error("Failed to resolve middleware '{$middleware}'", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
        }

        // If it's a callable, adapt it
        if (is_callable($middleware)) {
            return new CallableMiddlewareAdapter($middleware);
        }

        throw new \RuntimeException(sprintf(
            'Middleware "%s" must be a class name, instance of MiddlewareInterface, or callable',
            $middleware
        ));
    }

    /**
     * Log middleware error with context
     *
     * @param mixed $middleware
     * @param \Throwable $e
     * @return void
     */
    protected function logMiddlewareError($middleware, \Throwable $e): void
    {
        $middlewareName = is_string($middleware)
            ? $middleware
            : (is_object($middleware) ? get_class($middleware) : gettype($middleware));

        logger()->error("Error in middleware '{$middlewareName}'", [
            'middleware' => $middlewareName,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    /**
     * Create a callable handler from this middleware stack
     *
     * @return callable
     */
    public function toCallable(): callable
    {
        return function (ServerRequestInterface $request): ResponseInterface {
            return $this->handle($request);
        };
    }

    /**
     * Clear the middleware stack
     *
     * @return self
     */
    public function clearMiddleware(): self
    {
        $this->middleware = [];
        return $this;
    }

    /**
     * Clear the resolved middleware cache
     *
     * @return self
     */
    public function clearResolvedMiddleware(): self
    {
        $this->resolvedMiddleware = [];
        return $this;
    }
}