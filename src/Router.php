<?php
namespace Ody\Foundation;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Ody\Container\Container;
use Ody\Container\Contracts\BindingResolutionException;
use Ody\Foundation\Middleware\MiddlewarePipeline;
use function FastRoute\simpleDispatcher;

class Router
{
    /**
     * Store all routes in a static property to persist between instances
     */
    private static array $allRoutes = [];

    /**
     * @var array
     */
    private $routes = [];

    /**
     * @var Container
     */
    private $container;

    /**
     * @var MiddlewareManager
     */
    private $middlewareManager;

    private static int $routesCacheHits = 0;

    private static int $routeCleanupThreshold = 10000;

    /**
     * Router constructor
     *
     * @param Container|null $container
     * @param MiddlewareManager|null $middlewareManager
     */
    public function __construct(
        ?Container         $container = null,
        ?MiddlewareManager $middlewareManager = null
    )
    {
        $this->container = $container ?? new Container();

        if ($middlewareManager) {
            $this->middlewareManager = $middlewareManager;
        } else if ($container && $container->has(MiddlewareManager::class)) {
            $this->middlewareManager = $container->make(MiddlewareManager::class);
        } else {
            $this->middlewareManager = new MiddlewareManager($this->container);
        }

        // IMPORTANT: Always use the static routes if available
        if (!empty(self::$allRoutes)) {
            $this->routes = self::$allRoutes;
            error_log("Router: Loaded " . count($this->routes) . " routes from static storage");
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
        $route = new Route('GET', $path, $handler, $this->middlewareManager);

        // Add to instance routes
        $this->routes[] = ['GET', $path, $handler];

        // IMPORTANT: Also store in static property
        self::$allRoutes[] = ['GET', $path, $handler];

        logger()->debug("Router: Registered GET route: {$path}");

        return $route;
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
        $route = new Route('POST', $path, $handler, $this->middlewareManager);
        $this->routes[] = ['POST', $path, $handler];
        self::$allRoutes[] = ['POST', $path, $handler];
        logger()->debug("Router: Registered POST route: {$path}");
        return $route;
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
        $route = new Route('PUT', $path, $handler, $this->middlewareManager);
        $this->routes[] = ['PUT', $path, $handler];
        self::$allRoutes[] = ['PUT', $path, $handler];
        logger()->debug("Router: Registered PUT route: {$path}");
        return $route;
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
        $route = new Route('DELETE', $path, $handler, $this->middlewareManager);
        $this->routes[] = ['DELETE', $path, $handler];
        self::$allRoutes[] = ['DELETE', $path, $handler];
        logger()->debug("Router: Registered DELETE route: {$path}");
        return $route;
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
        $route = new Route('PATCH', $path, $handler, $this->middlewareManager);
        $this->routes[] = ['PATCH', $path, $handler];
        self::$allRoutes[] = ['PATCH', $path, $handler];
        logger()->debug("Router: Registered PATCH route: {$path}");
        return $route;
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
        $route = new Route('OPTIONS', $path, $handler, $this->middlewareManager);
        $this->routes[] = ['OPTIONS', $path, $handler];
        self::$allRoutes[] = ['OPTIONS', $path, $handler];
        logger()->debug("Router: Registered OPTIONS route: {$path}");
        return $route;
    }

    // The rest of your Router class...

    /**
     * Match a request to a route
     *
     * @param string $method
     * @param string $path
     * @return array
     */
    public function match(string $method, string $path): array
    {
        // Load routes on-demand when first match is attempted
        RouteRegistry::loadRoutesIfNeeded($this);

        $this->deduplicateRoutes();

//        $this->routes = self::$allRoutes;

        self::$routesCacheHits++;

        // Periodically clean up excessive cached routes
        if (self::$routesCacheHits > self::$routeCleanupThreshold) {
            self::cleanupRouteCache();
            self::$routesCacheHits = 0;
        }

        // Normalize the path
        if (empty($path)) {
            $path = '/';
        } elseif ($path[0] !== '/') {
            $path = '/' . $path;
        }

        // Remove trailing slash (except for root path)
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }

        logger()->debug("Router::match() {$method} {$path}");

        // CRITICAL: If instance routes are empty but static routes exist, use those
        if (empty($this->routes) && !empty(self::$allRoutes)) {
            $this->routes = self::$allRoutes;
            error_log("Router::match() Restored " . count(self::$allRoutes) . " routes from static storage");
        }

        $dispatcher = $this->createDispatcher();
        $routeInfo = $dispatcher->dispatch($method, $path);

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                // Debug info in development mode
                if (env('APP_DEBUG', false)) {
                    $registeredRoutes = [];
                    foreach ($this->routes as $route) {
                        $registeredRoutes[] = $route[0] . ' ' . $route[1];
                    }
                    logger()->debug("Router: No route found for {$method} {$path}. Routes count: " . count($registeredRoutes));
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

                // Detect controller class and method for attribute middleware resolution
                $controllerInfo = $this->extractControllerInfo($handler, $callable);

                return [
                    'status' => 'found',
                    'handler' => $callable,
                    'originalHandler' => $handler, // Store original for reference
                    'vars' => $routeInfo[2],
                    'controller' => $controllerInfo['controller'] ?? null,
                    'action' => $controllerInfo['action'] ?? null
                ];
        }

        return ['status' => 'error'];
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

        // Case 3: Try to infer from closure or other callable
        // This might not be possible in many cases

        return $info;
    }

    /**
     * Create a middleware pipeline
     *
     * @param array $middlewareStack
     * @param callable $finalHandler
     * @return MiddlewarePipeline
     */
    protected function createMiddlewarePipeline(array $middlewareStack, callable $finalHandler): Middleware\MiddlewarePipeline
    {
        // Create a pipeline with the final handler
        $pipeline = new Middleware\MiddlewarePipeline($finalHandler);

        // Resolve and add each middleware to the pipeline
        foreach ($middlewareStack as $middleware) {
            try {
                $instance = $this->middlewareManager->resolve($middleware);
                $pipeline->add($instance);
            } catch (\Throwable $e) {
                logger()->error("Failed to resolve middleware", [
                    'middleware' => is_string($middleware) ? $middleware : gettype($middleware),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $pipeline;
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

        // Normalize prefix - ensure it starts with a slash if not empty
        if (!empty($prefix) && $prefix[0] !== '/') {
            $prefix = '/' . $prefix;
        }

        // Create a proxy router that will add the prefix to all routes
        $groupRouter = new class($this, $prefix, $middleware) {
            private $router;
            private $prefix;
            private $middleware;

            public function __construct($router, $prefix, $middleware)
            {
                $this->router = $router;
                $this->prefix = $prefix;
                $this->middleware = $middleware;
            }

            public function __call($method, $args)
            {
                // Only handle HTTP methods
                $httpMethods = ['get', 'post', 'put', 'patch', 'delete', 'options'];
                if (!in_array(strtolower($method), $httpMethods) || count($args) < 2) {
                    throw new \InvalidArgumentException("Unsupported method: {$method}");
                }

                // Extract path and handler
                $path = $args[0];
                $handler = $args[1];

                // Normalize the path - ensure it starts with a slash if not empty
                if (!empty($path) && $path[0] !== '/') {
                    $path = '/' . $path;
                }

                // FIX: Handle empty paths and trailing slashes properly
                // This is the key fix for the routing issue
                $fullPath = $this->combinePaths($this->prefix, $path);

                // Register the route
                $route = $this->router->{$method}($fullPath, $handler);

                // Apply group middleware to the route
                foreach ($this->middleware as $m) {
                    $route->middleware($m);
                }

                return $route;
            }

            // Add this new helper method to properly combine paths
            private function combinePaths($prefix, $path): string
            {
                // If path is empty, just return the prefix (without duplicate trailing slash)
                if ($path === '' || $path === '/') {
                    return rtrim($prefix, '/');
                }

                // Otherwise combine them properly avoiding double slashes
                return rtrim($prefix, '/') . $path;
            }

            // Define explicit methods for IDE auto-completion
            public function get($path, $handler) { return $this->__call('get', [$path, $handler]); }
            public function post($path, $handler) { return $this->__call('post', [$path, $handler]); }
            public function put($path, $handler) { return $this->__call('put', [$path, $handler]); }
            public function patch($path, $handler) { return $this->__call('patch', [$path, $handler]); }
            public function delete($path, $handler) { return $this->__call('delete', [$path, $handler]); }
            public function options($path, $handler) { return $this->__call('options', [$path, $handler]); }

            // Support nested groups
            public function group(array $attributes, callable $callback)
            {
                // Merge the prefixes
                $newPrefix = $this->prefix;
                if (isset($attributes['prefix'])) {
                    $prefixToAdd = $attributes['prefix'];
                    if (!empty($prefixToAdd) && $prefixToAdd[0] !== '/') {
                        $prefixToAdd = '/' . $prefixToAdd;
                    }
                    // Use the new combinePaths method here too
                    $newPrefix = $this->combinePaths($newPrefix, $prefixToAdd);
                }

                // Merge the middleware
                $newMiddleware = $this->middleware;
                if (isset($attributes['middleware'])) {
                    if (is_array($attributes['middleware'])) {
                        $newMiddleware = array_merge($newMiddleware, $attributes['middleware']);
                    } else {
                        $newMiddleware[] = $attributes['middleware'];
                    }
                }

                // Create new attributes
                $newAttributes = $attributes;
                $newAttributes['prefix'] = $newPrefix;
                $newAttributes['middleware'] = $newMiddleware;

                // Call the parent router's group method
                return $this->router->group($newAttributes, $callback);
            }
        };

        // Call the callback with the group router to collect routes
        $callback($groupRouter);

        return $this;
    }

    /**
     * Create FastRoute dispatcher with current routes
     *
     * @return Dispatcher
     */
    private function createDispatcher()
    {
        // CRITICAL: If instance routes are empty but static routes exist, use those
        if (empty($this->routes) && !empty(self::$allRoutes)) {
            $this->routes = self::$allRoutes;
        }

        return simpleDispatcher(function (RouteCollector $r) {
            foreach ($this->routes as $route) {
                $method = $route[0];
                $path = $route[1];

                // Ensure path starts with a slash for consistency
                if (!empty($path) && $path[0] !== '/') {
                    $path = '/' . $path;
                }

                $r->addRoute($method, $path, $route[2]);
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
        if (is_string($handler) && strpos($handler, '@') !== false) {
            list($class, $method) = explode('@', $handler, 2);

            // If we have a container, use it to resolve the controller
            if ($this->container) {
                $controller = $this->container->make($class);
                return [$controller, $method];
            }

            // Fallback if no container: create controller instance directly
            $controller = new $class();
            return [$controller, $method];
        }

        // If it's already a callable or not in Controller@method format, return as is
        return $handler;
    }

    /**
     * Force loading of all routes
     */
    public function loadAllRoutes(): void
    {
        RouteRegistry::loadRoutesIfNeeded($this);
    }

    /**
     * Return the count of registered routes
     *
     * @return int
     */
    public function countRoutes(): int
    {
        return count($this->routes);
    }

    /**
     * Return the count of static routes
     *
     * @return int
     */
    public static function countStaticRoutes(): int
    {
        return count(self::$allRoutes);
    }

    /**
     * Get the middleware manager
     *
     * @return MiddlewareManager
     */
    public function getMiddlewareManager(): MiddlewareManager
    {
        return $this->middlewareManager;
    }

    private static function cleanupRouteCache(): void
    {
        // Limit static route cache to a reasonable size
        if (count(self::$allRoutes) > 1000) {
            // Keep only unique routes, discard duplicates
            $uniqueRoutes = [];
            $seenRoutes = [];

            foreach (self::$allRoutes as $route) {
                $key = $route[0] . '|' . $route[1];
                if (!isset($seenRoutes[$key])) {
                    $uniqueRoutes[] = $route;
                    $seenRoutes[$key] = true;
                }
            }

            self::$allRoutes = $uniqueRoutes;
        }
    }

    public function syncRoutes(): void
    {
        $this->routes = self::$allRoutes;
        logger()->debug("Router: Synchronized " . count(self::$allRoutes) . " routes from static storage");
    }

    private function deduplicateRoutes(): void
    {
        $uniqueRoutes = [];
        $seenRoutes = [];

        foreach (self::$allRoutes as $route) {
            $key = $route[0] . '|' . $route[1]; // method|path
            if (!isset($seenRoutes[$key])) {
                $uniqueRoutes[] = $route;
                $seenRoutes[$key] = true;
            }
        }

        self::$allRoutes = $uniqueRoutes;
        $this->routes = $uniqueRoutes;

        logger()->debug("Router: Deduplicated routes. Result: " . count($uniqueRoutes) . " unique routes.");
    }
}