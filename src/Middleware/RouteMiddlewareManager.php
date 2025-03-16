<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware;


use Ody\Foundation\Http\Request;
use Ody\Foundation\Http\Response;

/**
 * Route Middleware Manager
 *
 * Handles route-specific middleware
 */
/**
 * Route Middleware Manager
 *
 * Handles route-specific middleware
 */
class RouteMiddlewareManager
{
    /**
     * @var array Global middleware applied to all routes
     */
    private $globalMiddleware = [];

    /**
     * @var array Route-specific middleware
     */
    private $routeMiddleware = [];

    /**
     * @var array Named middleware
     */
    private $namedMiddleware = [];

    /**
     * @var array Route groups with middleware
     */
    private $groups = [];

    /**
     * Register a global middleware
     *
     * @param callable $middleware
     * @return self
     */
    public function addGlobal(callable $middleware): self
    {
        $this->globalMiddleware[] = $middleware;
        return $this;
    }

    /**
     * Register a named middleware
     *
     * @param string $name
     * @param callable $middleware
     * @return self
     */
    public function addNamed(string $name, callable $middleware): self
    {
        $this->namedMiddleware[$name] = $middleware;
        return $this;
    }

    /**
     * Apply middleware to a specific route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param string|callable $middleware Middleware name or callable
     * @return self
     */
    public function addToRoute(string $method, string $path, $middleware): self
    {
        $route = $this->formatRoute($method, $path);

        if (!isset($this->routeMiddleware[$route])) {
            $this->routeMiddleware[$route] = [];
        }

        $this->routeMiddleware[$route][] = $middleware;
        return $this;
    }

    /**
     * Apply middleware to multiple routes using a pattern
     *
     * @param string $pattern Route pattern (uses fnmatch)
     * @param string|callable $middleware Middleware name or callable
     * @return self
     */
    public function addToGroup(string $pattern, $middleware): self
    {
        $this->groups[] = [
            'pattern' => $pattern,
            'middleware' => $middleware
        ];
        return $this;
    }

    /**
     * Format route identifier
     *
     * @param string $method
     * @param string $path
     * @return string
     */
    private function formatRoute(string $method, string $path): string
    {
        return strtoupper($method) . ':' . $path;
    }

    /**
     * Check if route matches a pattern
     *
     * @param string $route
     * @param string $pattern
     * @return bool
     */
    private function routeMatchesPattern(string $route, string $pattern): bool
    {
        return fnmatch($pattern, $route);
    }

    /**
     * Get middleware for a specific route
     *
     * @param string $method
     * @param string $path
     * @return array
     */
    public function getMiddlewareForRoute(string $method, string $path): array
    {
        $route = $this->formatRoute($method, $path);
        $middleware = $this->globalMiddleware;

        // Add route-specific middleware
        if (isset($this->routeMiddleware[$route])) {
            foreach ($this->routeMiddleware[$route] as $m) {
                $middleware[] = $this->resolveMiddleware($m);
            }
        }

        // Add group middleware
        foreach ($this->groups as $group) {
            if ($this->routeMatchesPattern($route, $group['pattern'])) {
                $middleware[] = $this->resolveMiddleware($group['middleware']);
            }
        }

        return $middleware;
    }

    /**
     * Resolve middleware from name or callable
     *
     * @param string|callable $middleware
     * @return callable
     * @throws \InvalidArgumentException
     */
    private function resolveMiddleware($middleware): callable
    {
        if (is_callable($middleware)) {
            return $middleware;
        }

        if (is_string($middleware) && isset($this->namedMiddleware[$middleware])) {
            return $this->namedMiddleware[$middleware];
        }

        throw new \InvalidArgumentException("Middleware '$middleware' not found");
    }

    /**
     * Run middleware stack for a route
     *
     * @param Request $request
     * @param Response $response
     * @param callable $handler
     * @param string $method
     * @param string $path
     * @return mixed
     */
    public function run(Request $request, Response $response, callable $handler, string $method, string $path)
    {
        $middleware = $this->getMiddlewareForRoute($method, $path);

        // Build middleware stack
        $next = $handler;

        // Execute middleware in reverse order
        $middlewareStack = array_reverse($middleware);

        foreach ($middlewareStack as $m) {
            $next = function ($request, $response) use ($m, $next) {
                return $m($request, $response, $next);
            };
        }

        return $next($request, $response);
    }
}