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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MiddlewareStack
 *
 * A stack-based PSR-15 middleware pipeline
 */
class MiddlewareStack implements RequestHandlerInterface
{
    /**
     * @var MiddlewareInterface[] Array of middleware instances
     */
    protected array $middleware = [];

    /**
     * @var Container
     */
    protected Container $container;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var RequestHandlerInterface
     */
    protected RequestHandlerInterface $fallbackHandler;

    /**
     * Constructor
     *
     * @param Container $container
     * @param RequestHandlerInterface|null $fallbackHandler
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        Container                $container,
        ?RequestHandlerInterface $fallbackHandler = null,
        ?LoggerInterface         $logger = null
    )
    {
        $this->container = $container;
        $this->logger = $logger ?? new NullLogger();

        // Create a default fallback handler if none provided
        if (!$fallbackHandler) {
            $fallbackHandler = new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new \RuntimeException('No middleware handled the request');
                }
            };
        }

        $this->fallbackHandler = $fallbackHandler;
    }

    /**
     * Add middleware to the stack
     *
     * @param MiddlewareInterface|string $middleware Instance or class name
     * @return self
     */
    public function add($middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Set the fallback handler
     *
     * @param RequestHandlerInterface $handler
     * @return self
     */
    public function setFallbackHandler(RequestHandlerInterface $handler): self
    {
        $this->fallbackHandler = $handler;
        return $this;
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
     * Process the request through middleware stack
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (empty($this->middleware)) {
            return $this->fallbackHandler->handle($request);
        }

        $middleware = array_shift($this->middleware);
        $middleware = $this->resolveMiddleware($middleware);

        // Create a copy of the remaining stack as the next handler
        $next = clone $this;

        try {
            return $middleware->process($request, $next);
        } catch (\Throwable $e) {
            $this->logger->error('Middleware error', [
                'middleware' => get_class($middleware),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Resolve middleware from string to instance
     *
     * @param MiddlewareInterface|string $middleware
     * @return MiddlewareInterface
     * @throws \RuntimeException
     */
    protected function resolveMiddleware($middleware): MiddlewareInterface
    {
        // Already resolved
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        // Class name that needs resolving
        if (is_string($middleware) && class_exists($middleware)) {
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

                throw new \RuntimeException("Class $middleware is not a valid middleware");
            } catch (\Throwable $e) {
                $this->logger->error("Failed to resolve middleware '$middleware'", [
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }

        // If it's a callable, adapt it
        if (is_callable($middleware)) {
            return new CallableMiddlewareAdapter($middleware);
        }

        throw new \RuntimeException('Invalid middleware: ' . (is_object($middleware) ? get_class($middleware) : gettype($middleware)));
    }
}