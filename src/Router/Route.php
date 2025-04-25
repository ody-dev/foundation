<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Router;

use Ody\Container\Contracts\BindingResolutionException;
use Ody\Foundation\Middleware\MiddlewareConfig;
use Ody\Foundation\Middleware\MiddlewareManager;

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
        mixed $handler,
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
     * Supports multiple formats:
     * - middleware('Class1', 'Class2')          // Multiple middleware classes
     * - middleware('Class1', ['param' => 'value']) // Single middleware with parameters
     * - middleware([
     *     'Class1',
     *     ['Class2', ['param' => 'value']]      // Array of middleware with and without params
     *   ])
     *
     * @param mixed ...$args One or more middleware classes, instances, or callables
     * @return self
     */
    public function middleware(...$args): self
    {
        // If there are exactly 2 args and the second is an array, it's a middleware with params
        if (count($args) === 2 && is_string($args[0]) && is_array($args[1])) {
            return $this->addMiddlewareWithParams($args[0], $args[1]);
        }

        // Process each argument
        foreach ($args as $m) {
            // If it's an array with a class name and parameters
            if (is_array($m) && count($m) === 2 && is_string($m[0]) && is_array($m[1])) {
                $this->addMiddlewareWithParams($m[0], $m[1]);
            } // Process middleware array
            elseif (is_array($m) && !is_callable($m)) {
                foreach ($m as $subM) {
                    if (is_array($subM) && count($subM) === 2 && is_string($subM[0]) && is_array($subM[1])) {
                        $this->addMiddlewareWithParams($subM[0], $subM[1]);
                    } else {
                        $this->addMiddleware($subM);
                    }
                }
            } // Regular middleware
            else {
                $this->addMiddleware($m);
            }
        }

        return $this;
    }

    /**
     * Internal method to add a single middleware
     *
     * @param mixed $middleware
     * @return self
     */
    protected function addMiddleware($middleware): self
    {
        // Store the middleware reference
        $this->middlewareList[] = $middleware;

        // Register with the middleware manager
        $this->middlewareManager->addForRoute($this->method, $this->path, $middleware);

        return $this;
    }

    /**
     * Internal method to add a middleware with parameters
     *
     * @param string $middleware Middleware class name
     * @param array $parameters Parameters to pass to middleware constructor
     * @return self
     * @throws BindingResolutionException
     */
    protected function addMiddlewareWithParams(string $middleware, array $parameters): self
    {
        $config = new MiddlewareConfig($middleware, $parameters);

        // Store the middleware reference
        $this->middlewareList[] = $config;

        // Register with the middleware manager
        $this->middlewareManager->addForRoute($this->method, $this->path, $config);

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