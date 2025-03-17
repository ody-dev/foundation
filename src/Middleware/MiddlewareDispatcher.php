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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Middleware Dispatcher
 *
 * Simple PSR-15 middleware dispatcher that handles a middleware pipeline
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
     * @var array Global middleware stack - applied to all requests
     */
    protected array $middleware = [];

    /**
     * @var callable The final handler to process requests if no middleware handles it
     */
    protected $finalHandler;

    /**
     * Constructor
     *
     * @param Container $container
     * @param callable|null $finalHandler
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        Container        $container,
        ?callable        $finalHandler = null,
        ?LoggerInterface $logger = null
    )
    {
        $this->container = $container;
        $this->logger = $logger ?? new NullLogger();
        $this->finalHandler = $finalHandler ?? function (ServerRequestInterface $request) {
            throw new \RuntimeException('No handler available for request');
        };
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
     * Set the final handler
     *
     * @param callable $handler
     * @return self
     */
    public function setFinalHandler(callable $handler): self
    {
        $this->finalHandler = $handler;
        return $this;
    }

    /**
     * Process a request through the middleware stack
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Clone the middleware stack to avoid modifying the original
        $stack = $this->middleware;

        // If no middleware, just run the final handler
        if (empty($stack)) {
            return call_user_func($this->finalHandler, $request);
        }

        // Get the next middleware from the stack
        $middleware = array_shift($stack);

        // Create a handler for remaining middleware
        $next = function (ServerRequestInterface $request) use ($stack) {
            $dispatcher = clone $this;
            $dispatcher->middleware = $stack;
            return $dispatcher->handle($request);
        };

        try {
            // Resolve and execute the middleware
            $instance = $this->resolveMiddleware($middleware);

            if ($instance instanceof MiddlewareInterface) {
                // For PSR-15 middleware
                $handler = new CallableHandlerAdapter($next);
                return $instance->process($request, $handler);
            } else {
                // For callable middleware in request-response-next format
                $response = $middleware($request, $next);

                // Ensure it returns a ResponseInterface
                if (!$response instanceof ResponseInterface) {
                    throw new \RuntimeException('Middleware must return an instance of ResponseInterface');
                }

                return $response;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Middleware error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Resolve middleware to a PSR-15 middleware instance
     *
     * @param mixed $middleware
     * @return MiddlewareInterface|callable
     * @throws \RuntimeException If middleware cannot be resolved
     */
    protected function resolveMiddleware($middleware)
    {
        // Already a PSR-15 middleware
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        // Callable middleware (request-response-next format)
        if (is_callable($middleware) && !is_string($middleware)) {
            return $middleware;
        }

        // String middleware (class name)
        if (is_string($middleware) && class_exists($middleware)) {
            try {
                // Try to resolve from container first
                if ($this->container->has($middleware)) {
                    $instance = $this->container->make($middleware);
                } else {
                    // Create new instance as fallback
                    $instance = new $middleware();
                }

                if ($instance instanceof MiddlewareInterface) {
                    return $instance;
                }

                if (is_callable($instance)) {
                    return $instance;
                }

                throw new \RuntimeException("Middleware class '$middleware' is not a valid middleware");
            } catch (\Throwable $e) {
                $this->logger->error("Failed to resolve middleware '$middleware'", [
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }

        throw new \RuntimeException("Cannot resolve middleware: " . (is_object($middleware) ? get_class($middleware) : gettype($middleware)));
    }
}