<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Router;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Ody\Middleware\MiddlewareManager;
use Psr\Log\LoggerInterface;
use function FastRoute\simpleDispatcher;

class Router
{
    /**
     * @var array
     */
    private array $routes = [];

    /**
     * @var Dispatcher|null
     */
    private ?Dispatcher $dispatcher = null;

    /**
     * @var bool
     */
    private bool $routesLoaded = false;

    /**
     * Router constructor
     *
     * @param MiddlewareManager $middlewareManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly MiddlewareManager $middlewareManager,
        private readonly LoggerInterface   $logger
    )
    {
    }

    /**
     * Register a GET route
     *
     * @param string $path
     * @param mixed $handler
     * @return Route
     */
    public function get(string $path, $handler): Route
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * Register a POST route
     *
     * @param string $path
     * @param mixed $handler
     * @return Route
     */
    public function post(string $path, $handler): Route
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * Register a PUT route
     *
     * @param string $path
     * @param mixed $handler
     * @return Route
     */
    public function put(string $path, $handler): Route
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Register a DELETE route
     *
     * @param string $path
     * @param mixed $handler
     * @return Route
     */
    public function delete(string $path, $handler): Route
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Register a PATCH route
     *
     * @param string $path
     * @param mixed $handler
     * @return Route
     */
    public function patch(string $path, $handler): Route
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * Register an OPTIONS route
     *
     * @param string $path
     * @param mixed $handler
     * @return Route
     */
    public function options(string $path, $handler): Route
    {
        return $this->addRoute('OPTIONS', $path, $handler);
    }

    /**
     * Register a route of any method
     *
     * @param string $method
     * @param string $path
     * @param mixed $handler
     * @return Route
     */
    protected function addRoute(string $method, string $path, $handler): Route
    {
        // Normalize path
        $path = $this->normalizePath($path);

        // Create Route object
        $route = new Route($method, $path, $handler, $this->middlewareManager);

        // Store route definition
        $this->routes[] = [$method, $path, $handler];

        // Reset dispatcher so it will be rebuilt on next match
        $this->dispatcher = null;

        // Log route registration
        $workerId = getmypid();
        $this->logger->debug("[Worker {$workerId}] Router: Registered {$method} route: {$path}");

        return $route;
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Match a request to a route
     *
     * @param string $method
     * @param string $path
     * @return array
     */
    public function match(string $method, string $path): array
    {
        $path = $this->normalizePath($path);

        if ($this->dispatcher === null) {
            $this->logger->debug("Router: dispatcher was null, rebuilding.");
            $this->buildDispatcher();
        }

        $routeInfo = $this->dispatcher->dispatch($method, $path);

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                $this->logger->debug("Router: No route found for {$method} {$path}.");
                return ['status' => 'not_found'];

            case Dispatcher::METHOD_NOT_ALLOWED:
                $this->logger->debug("Router: Method not allowed for {$method} {$path}.");
                return [
                    'status' => 'method_not_allowed',
                    'allowed_methods' => $routeInfo[1]
                ];

            case Dispatcher::FOUND:
                $handlerIdentifier = $routeInfo[1]; // This is the raw handler (string or closure)
                $routeParams = $routeInfo[2];
                $this->logger->debug("Router: Route found for {$method} {$path}");

                $controllerClass = null;
                $action = null;
                // Extract controller/action strings if it's a string handler
                if (is_string($handlerIdentifier) && str_contains($handlerIdentifier, '@')) {
                    list($controllerClass, $action) = explode('@', $handlerIdentifier, 2);
                }

                // Return the identifier, not a resolved callable
                return [
                    'status' => 'found',
                    'handler' => $handlerIdentifier, // Return the string 'Controller@method' or closure
                    'vars' => $routeParams,
                    'controller' => $controllerClass, // Extracted class string
                    'action' => $action // Extracted action string
                ];
        }
        $this->logger->error("Router: Dispatcher returned unexpected status: " . $routeInfo[0]);
        return ['status' => 'error']; // Should not happen
    }

    /**
     * Build the route dispatcher
     * This should be called after all routes are registered
     * but before the application starts handling requests
     *
     * @return void
     */
    public function buildDispatcher(): void // non-static
    {
        if ($this->dispatcher === null) {
            $this->dispatcher = $this->createDispatcher();
        }
    }

    /**
     * Mark routes as loaded and build the dispatcher
     *
     * @return void
     */
    public function markRoutesLoaded(): void // non-static
    {
        if (!$this->routesLoaded) {
            $this->routesLoaded = true;
            $this->buildDispatcher();
        }
    }

    /**
     * Create a route group with shared attributes
     *
     * @param array $attributes Group attributes (prefix, middleware)
     * @param callable $callback Function to define routes in the group
     * @return self
     */
    public function group(array $attributes, callable $callback): self
    {
        $prefix = $attributes['prefix'] ?? '';
        $middleware = $attributes['middleware'] ?? [];

        // Normalize prefix
        if (!empty($prefix) && $prefix[0] !== '/') {
            $prefix = '/' . $prefix;
        }

        $callback(
            new RouteGroup($this, $prefix, $middleware)
        );

        return $this;
    }

    /**
     * Create FastRoute dispatcher with current routes
     *
     * @return Dispatcher
     */
    private function createDispatcher(): Dispatcher
    {
        return simpleDispatcher(function (RouteCollector $r) {
            foreach ($this->routes as $route) {
                $method = $route[0];
                $path = $route[1];
                $handler = $route[2];

                $r->addRoute($method, $path, $handler);
            }
        });
    }

    /**
     * Normalize a path to ensure consistent formatting
     *
     * @param string $path
     * @return string
     */
    protected function normalizePath(string $path): string
    {
        // Ensure path starts with a slash
        if (empty($path)) {
            return '/';
        } elseif ($path[0] !== '/') {
            $path = '/' . $path;
        }

        // Remove trailing slash (except for root path)
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    /**
     * Register a resource route with RESTful actions
     *
     * @param string $name Resource name
     * @param string $controller Controller class
     * @param array $options Optional configuration options
     * @return self
     */
    public function resource(string $name, string $controller, array $options = []): self
    {
        // Normalize resource name (remove leading/trailing slashes)
        $name = trim($name, '/');

        // Default resource routes
        $defaultActions = [
            'index' => ['GET', "/{$name}"],
            'create' => ['GET', "/{$name}/create"],
            'store' => ['POST', "/{$name}"],
            'show' => ['GET', "/{$name}/{id}"],
            'edit' => ['GET', "/{$name}/{id}/edit"],
            'update' => ['PUT', "/{$name}/{id}"],
            'destroy' => ['DELETE', "/{$name}/{id}"]
        ];

        // Handle only/except options
        $actions = $defaultActions;

        if (isset($options['only']) && is_array($options['only'])) {
            $actions = array_intersect_key($actions, array_flip($options['only']));
        } elseif (isset($options['except']) && is_array($options['except'])) {
            $actions = array_diff_key($actions, array_flip($options['except']));
        }

        // Register each route
        foreach ($actions as $action => $route) {
            list($method, $path) = $route;
            $this->addRoute($method, $path, "{$controller}@{$action}");
        }

        return $this;
    }
}