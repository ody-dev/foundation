<?php

namespace Ody\Foundation\Router;

use FastRoute\DataGenerator\GroupCountBased as DataGenerator;
use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased as GroupCountBasedDispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std as RouteParser;
use Ody\Container\Container;
use Ody\Container\Contracts\BindingResolutionException;
use Ody\Foundation\Http\ControllerPool;
use Ody\Foundation\MiddlewareManager;
use function FastRoute\simpleDispatcher;

//use function Ody\Foundation\gettype;

class Router
{
    /**
     * @var array
     */
    private array $routes = [];

    /**
     * @var Container
     */
    private Container $container;

    /**
     * @var MiddlewareManager
     */
    private MiddlewareManager $middlewareManager;

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
     * @param Container|null $container
     * @param MiddlewareManager|null $middlewareManager
     */
    public function __construct(
        ?Container $container = null,
        ?MiddlewareManager $middlewareManager = null
    ) {
        $this->container = $container ?? new Container();

        if ($middlewareManager) {
            $this->middlewareManager = $middlewareManager;
        } else if ($container && $container->has(MiddlewareManager::class)) {
            $this->middlewareManager = $container->make(MiddlewareManager::class);
        } else {
            $this->middlewareManager = new MiddlewareManager($this->container);
        }
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
        logger()->debug("Router: Registered {$method} route: {$path}");

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
        // Normalize the path
        $path = $this->normalizePath($path);

        // Create dispatcher only if needed and not already registered
        if ($this->dispatcher === null) {
            logger()->debug("Router: dispatcher was null even though routes are registered");
            $this->buildDispatcher();
        }

        $routeInfo = $this->dispatcher->dispatch($method, $path);

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                // Debug info in development mode
                if (env('APP_DEBUG', false)) {
                    logger()->debug("Router: No route found for {$method} {$path}. Total routes: " . count($this->routes));
                }
                return ['status' => 'not_found'];

            case Dispatcher::METHOD_NOT_ALLOWED:
                return [
                    'status' => 'method_not_allowed',
                    'allowed_methods' => $routeInfo[1]
                ];

            case Dispatcher::FOUND:
                $handler = $routeInfo[1];
                logger()->debug("Router: Route found for {$method} {$path}");

                // Try to convert string controller@method to callable
                $callable = $this->resolveController($handler);

                // Extract controller info for attribute middleware
                $controllerInfo = $this->extractControllerInfo($handler, $callable);

                return [
                    'status' => 'found',
                    'handler' => $callable,
                    'originalHandler' => $handler,
                    'vars' => $routeInfo[2],
                    'controller' => $controllerInfo['controller'] ?? null,
                    'action' => $controllerInfo['action'] ?? null
                ];
        }

        return ['status' => 'error'];
    }

    /**
     * Build the route dispatcher
     * This should be called after all routes are registered
     * but before the application starts handling requests
     *
     * @return void
     */
    public function buildDispatcher(): void
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
    public function markRoutesLoaded(): void
    {
        $this->routesLoaded = true;
        $this->buildDispatcher();
    }

    /**
     * Extract controller class and method information from a handler
     *
     * @param mixed $originalHandler The original handler (string or callable)
     * @param mixed $resolvedHandler The resolved callable handler
     * @return array Controller info with 'controller' and 'action' keys
     */
    protected function extractControllerInfo($originalHandler, $resolvedHandler): array
    {
        $info = [
            'controller' => null,
            'action' => null
        ];

        // Case 1: String in 'Controller@method' format
        if (is_string($originalHandler) && strpos($originalHandler, '@') !== false) {
            list($controller, $method) = explode('@', $originalHandler, 2);
            $info['controller'] = $controller;
            $info['action'] = $method;
            return $info;
        }

        // Case 2: Already resolved array callable [ControllerInstance, 'method']
        if (is_array($resolvedHandler) && count($resolvedHandler) === 2) {
            $controller = $resolvedHandler[0];
            $method = $resolvedHandler[1];

            if (is_object($controller)) {
                $info['controller'] = get_class($controller);
                $info['action'] = $method;
                return $info;
            }
        }

        return $info;
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
     * Convert string `controller@method` to callable
     *
     * @param string|callable $handler
     * @return callable
     * @throws BindingResolutionException
     */
    private function resolveController($handler)
    {
        // Only process string handlers in Controller@method format
        if (is_string($handler) && str_contains($handler, '@')) {
            // Cache resolved handlers
            static $resolvedHandlers = [];

            if (isset($resolvedHandlers[$handler])) {
                return $resolvedHandlers[$handler];
            }

            list($class, $method) = explode('@', $handler, 2);

            // Get controller from pool
            $controller = ControllerPool::get($class, $this->container);
            $callable = [$controller, $method];

            // Cache the resolved handler
            $resolvedHandlers[$handler] = $callable;

            return $callable;
        }

        // If it's already a callable or not in Controller@method format, return as is
        return $handler;
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
        if ($path !== '/' && substr($path, -1) === '/') {
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

    /**
     * Cache the compiled route dispatcher data
     *
     * @param string $cacheFile File path to store the cached data
     * @return bool Whether caching was successful
     */
    public function cacheRoutes(string $cacheFile): bool
    {
        $routeCollector = new RouteCollector(new RouteParser(), new DataGenerator());

        foreach ($this->routes as $route) {
            $method = $route[0];
            $path = $route[1];
            $handler = $route[2];

            $routeCollector->addRoute($method, $path, $handler);
        }

        $dispatchData = $routeCollector->getData();

        $cacheContent = '<?php return ' . var_export($dispatchData, true) . ';';

        $result = file_put_contents($cacheFile, $cacheContent);

        return $result !== false;
    }

    /**
     * Load cached route dispatcher data
     *
     * @param string $cacheFile File path to the cached data
     * @return bool Whether loading was successful
     */
    public function loadCachedRoutes(string $cacheFile): bool
    {
        if (!file_exists($cacheFile)) {
            return false;
        }

        $dispatchData = require $cacheFile;

        if (!is_array($dispatchData)) {
            return false;
        }

        $this->dispatcher = new GroupCountBasedDispatcher($dispatchData);

        return true;
    }
}