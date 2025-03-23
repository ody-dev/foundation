<?php

namespace Ody\Foundation\Router;

/**
 * Route Group
 *
 * Handles grouping of routes with shared attributes like prefix and middleware.
 */
class RouteGroup
{
    /**
     * @var Router
     */
    private Router $router;

    /**
     * @var string
     */
    private string $prefix;

    /**
     * @var array
     */
    private array $middleware;

    /**
     * Create a new route group instance
     *
     * @param Router $router
     * @param string $prefix
     * @param array $middleware
     */
    public function __construct(Router $router, string $prefix, array $middleware)
    {
        $this->router = $router;
        $this->prefix = $prefix;
        $this->middleware = $middleware;
    }

    /**
     * Register a GET route in this group
     *
     * @param string $path
     * @param mixed $handler
     * @return Route
     */
    public function get(string $path, $handler): Route
    {
        return $this->addRoute('get', $path, $handler);
    }

    /**
     * Register a POST route in this group
     *
     * @param string $path
     * @param mixed $handler
     * @return Route
     */
    public function post(string $path, $handler): Route
    {
        return $this->addRoute('post', $path, $handler);
    }

    /**
     * Register a PUT route in this group
     *
     * @param string $path
     * @param mixed $handler
     * @return Route
     */
    public function put(string $path, $handler): Route
    {
        return $this->addRoute('put', $path, $handler);
    }

    /**
     * Register a DELETE route in this group
     *
     * @param string $path
     * @param mixed $handler
     * @return Route
     */
    public function delete(string $path, $handler): Route
    {
        return $this->addRoute('delete', $path, $handler);
    }

    /**
     * Register a PATCH route in this group
     *
     * @param string $path
     * @param mixed $handler
     * @return Route
     */
    public function patch(string $path, $handler): Route
    {
        return $this->addRoute('patch', $path, $handler);
    }

    /**
     * Register an OPTIONS route in this group
     *
     * @param string $path
     * @param mixed $handler
     * @return Route
     */
    public function options(string $path, $handler): Route
    {
        return $this->addRoute('options', $path, $handler);
    }

    /**
     * Register a resource within this group
     *
     * @param string $name
     * @param string $controller
     * @param array $options
     * @return Router
     */
    public function resource(string $name, string $controller, array $options = []): Router
    {
        $prefixedName = ltrim($this->combinePaths($this->prefix, '/' . trim($name, '/')), '/');
        return $this->router->resource($prefixedName, $controller, $options);
    }

    /**
     * Register a route of the specified method in this group
     *
     * @param string $method
     * @param string $path
     * @param mixed $handler
     * @return Route
     */
    private function addRoute(string $method, string $path, $handler): Route
    {
        // Normalize the path
        if (!empty($path) && $path[0] !== '/') {
            $path = '/' . $path;
        }

        // Combine the prefix with the path
        $fullPath = $this->combinePaths($this->prefix, $path);

        // Register the route with the router
        $route = $this->router->$method($fullPath, $handler);

        // Apply group middleware to the route
        foreach ($this->middleware as $middleware) {
            $route->middleware($middleware);
        }

        return $route;
    }

    /**
     * Support nested groups
     *
     * @param array $attributes
     * @param callable $callback
     * @return Router
     */
    public function group(array $attributes, callable $callback): Router
    {
        // Merge the prefixes
        $newPrefix = $this->prefix;
        if (isset($attributes['prefix'])) {
            $prefixToAdd = $attributes['prefix'];
            if (!empty($prefixToAdd) && $prefixToAdd[0] !== '/') {
                $prefixToAdd = '/' . $prefixToAdd;
            }
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

    /**
     * Helper method to properly combine paths
     *
     * @param string $prefix
     * @param string $path
     * @return string
     */
    private function combinePaths(string $prefix, string $path): string
    {
        // If path is empty, just return the prefix (without duplicate trailing slash)
        if ($path === '' || $path === '/') {
            return rtrim($prefix, '/');
        }

        // Otherwise combine them properly avoiding double slashes
        return rtrim($prefix, '/') . $path;
    }
}