<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation;

use Ody\Foundation\Middleware\MiddlewareRegistry;

/**
 * Route class for defining application routes
 */
class Route
{
    /**
     * @var string HTTP method
     */
    private string $method;

    /**
     * @var string Route path
     */
    private string $path;

    /**
     * @var mixed Route handler
     */
    private $handler;

    /**
     * @var MiddlewareRegistry
     */
    private MiddlewareRegistry $middlewareRegistry;
    private array $middlewareList;

    /**
     * Route constructor
     *
     * @param string $method
     * @param string $path
     * @param mixed $handler
     * @param MiddlewareRegistry $middlewareRegistry
     */
    public function __construct(
        string             $method,
        string             $path,
                           $handler,
        MiddlewareRegistry $middlewareRegistry
    )
    {
        $this->method = $method;
        $this->path = $path;
        $this->handler = $handler;
        $this->middlewareRegistry = $middlewareRegistry;
    }

    /**
     * Add multiple middleware to the route
     *
     * @param array $middleware
     * @return self
     */
    public function middlewares(array $middleware): self
    {
        foreach ($middleware as $m) {
            $this->middleware($m);
        }
        return $this;
    }

    /**
     * Add middleware to the route
     *
     * @param callable|object|string $middleware
     * @return self
     */
    public function middleware(callable|object|string $middleware): self
    {
        // Store the original pattern as is
        $this->middlewareRegistry->addToRoute($this->method, $this->path, $middleware);

        // Also register the middleware with the route for easier access later
        if (!property_exists($this, 'middlewareList')) {
            $this->middlewareList = [];
        }

        $this->middlewareList[] = $middleware;

        return $this;
    }

    /**
     * Add a middleware with parameters
     *
     * @param string $middleware
     * @param array $parameters
     * @return self
     */
    public function middlewareWithParams(string $middleware, array $parameters): self
    {
        // Register the middleware
        $this->middlewareRegistry->addToRoute($this->method, $this->path, $middleware);

        // Add parameters
        $this->middlewareRegistry->withParameters($middleware, $parameters);

        return $this;
    }

    /**
     * Get all middleware registered for this route
     *
     * @return array
     */
    public function getMiddleware(): array
    {
        return $this->middlewareList ?? [];
    }

    /**
     * Get the route method
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Get the route path
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get the route handler
     *
     * @return mixed
     */
    public function getHandler()
    {
        return $this->handler;
    }

    /**
     * Get the route name (method + path)
     *
     * @return string
     */
    public function getName(): string
    {
        return strtoupper($this->method) . ':' . $this->path;
    }
}