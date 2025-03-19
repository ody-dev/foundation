<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation;

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
     * @var array Middleware list for this route
     */
    private array $middlewareList = [];

    /**
     * @var MiddlewareManager
     */
    private MiddlewareManager $middlewareManager;

    /**
     * Route constructor
     *
     * @param string $method
     * @param string $path
     * @param mixed $handler
     * @param MiddlewareManager $middlewareManager
     */
    public function __construct(
        string            $method,
        string            $path,
                          $handler,
        MiddlewareManager $middlewareManager
    )
    {
        $this->method = $method;
        $this->path = $path;
        $this->handler = $handler;
        $this->middlewareManager = $middlewareManager;
    }

    /**
     * Add middleware to the route
     *
     * @param mixed ...$middleware One or more middleware classes, instances, or callables
     * @return self
     */
    public function middleware(...$middleware): self
    {
        foreach ($middleware as $m) {
            // Store the middleware reference
            $this->middlewareList[] = $m;

            // Register with the middleware manager using the simplified registry
            $this->middlewareManager->addForRoute($this->method, $this->path, $m);
        }

        return $this;
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
     * Get the middleware list
     *
     * @return array
     */
    public function getMiddleware(): array
    {
        return $this->middlewareList;
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