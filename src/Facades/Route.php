<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Facades;

use Ody\Foundation\Router\Router;

/**
 * Route Facade
 *
 * Provides static access to the Router instance.
 */
class Route
{
    /**
     * Get the router instance from the container
     *
     * @return Router
     */
    protected static function router(): Router
    {
        return app('router');
    }

    /**
     * Register a GET route
     *
     * @param string $path
     * @param mixed $handler
     * @return \Ody\Foundation\Router\Route
     */
    public static function get(string $path, $handler)
    {
        return static::router()->get($path, $handler);
    }

    /**
     * Register a POST route
     *
     * @param string $path
     * @param mixed $handler
     * @return \Ody\Foundation\Router\Route
     */
    public static function post(string $path, $handler)
    {
        return static::router()->post($path, $handler);
    }

    /**
     * Register a PUT route
     *
     * @param string $path
     * @param mixed $handler
     * @return \Ody\Foundation\Router\Route
     */
    public static function put(string $path, $handler)
    {
        return static::router()->put($path, $handler);
    }

    /**
     * Register a DELETE route
     *
     * @param string $path
     * @param mixed $handler
     * @return \Ody\Foundation\Router\Route
     */
    public static function delete(string $path, $handler): \Ody\Foundation\Router\Route
    {
        return static::router()->delete($path, $handler);
    }

    /**
     * Register a PATCH route
     *
     * @param string $path
     * @param mixed $handler
     * @return \Ody\Foundation\Router\Route
     */
    public static function patch(string $path, $handler)
    {
        return static::router()->patch($path, $handler);
    }

    /**
     * Register an OPTIONS route
     *
     * @param string $path
     * @param mixed $handler
     * @return \Ody\Foundation\Router\Route
     */
    public static function options(string $path, $handler): \Ody\Foundation\Router\Route
    {
        return static::router()->options($path, $handler);
    }

    /**
     * Create a route group with shared attributes
     *
     * @param array $attributes
     * @param callable $callback
     * @return Router
     */
    public static function group(array $attributes, callable $callback): Router
    {
        return static::router()->group($attributes, $callback);
    }

    /**
     * Register a resource controller
     *
     * @param string $name
     * @param string $controller
     * @param array $options
     * @return Router
     */
    public static function resource(string $name, string $controller, array $options = []): Router
    {
        return static::router()->resource($name, $controller, $options);
    }

    /**
     * Register an API resource controller (no create/edit routes)
     *
     * @param string $name
     * @param string $controller
     * @param array $options
     * @return Router
     */
    public static function apiResource(string $name, string $controller, array $options = []): Router
    {
        // Default to excluding create and edit routes for API resources
        $options['except'] = $options['except'] ?? [];
        if (!in_array('create', $options['except'])) {
            $options['except'][] = 'create';
        }
        if (!in_array('edit', $options['except'])) {
            $options['except'][] = 'edit';
        }

        return static::router()->resource($name, $controller, $options);
    }

    /**
     * Match multiple HTTP verbs
     *
     * @param array $methods
     * @param string $path
     * @param mixed $handler
     * @return \Ody\Foundation\Router\Route
     */
    public static function match(array $methods, string $path, $handler): ?\Ody\Foundation\Router\Route
    {
        $route = null;

        foreach ($methods as $method) {
            $method = strtolower($method);
            if (method_exists(static::class, $method)) {
                $route = static::$method($path, $handler);
            }
        }

        return $route;
    }

    /**
     * Register a route that responds to all HTTP verbs
     *
     * @param string $path
     * @param mixed $handler
     * @return \Ody\Foundation\Router\Route|null
     */
    public static function any(string $path, $handler): ?\Ody\Foundation\Router\Route
    {
        return static::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $path, $handler);
    }

    /**
     * Register a route that responds to specified HTTP verbs
     *
     * @param array $methods
     * @param string $path
     * @param mixed $handler
     * @return \Ody\Foundation\Router\Route|null
     */
    public static function methods(array $methods, string $path, $handler): ?\Ody\Foundation\Router\Route
    {
        return static::match($methods, $path, $handler);
    }

    /**
     * Create a named route
     *
     * @param string $name
     * @param string $path
     * @param mixed $handler
     * @return \Ody\Foundation\Router\Route
     */
    public static function name(string $name, string $path, $handler): \Ody\Foundation\Router\Route
    {
        // This implementation depends on your Route class's support for naming
        $route = static::get($path, $handler);

        // If your Route class has a name() method, call it
        if (method_exists($route, 'name')) {
            $route->name($name);
        }

        return $route;
    }

    /**
     * Register multiple routes with a common prefix
     *
     * @param string $prefix
     * @param array $routes
     * @return Router
     */
    public static function prefix(string $prefix, array $routes): Router
    {
        return static::group(['prefix' => $prefix], function ($router) use ($routes) {
            foreach ($routes as $route) {
                if (count($route) >= 3) {
                    $method = strtolower($route[0]);
                    $path = $route[1];
                    $handler = $route[2];

                    if (method_exists($router, $method)) {
                        $router->$method($path, $handler);
                    }
                }
            }
        });
    }

    /**
     * Apply middleware to routes
     *
     * @param array|string $middleware
     * @param mixed $routes
     * @return Router
     */
    public static function middleware($middleware, $routes): Router
    {
        if (is_array($routes)) {
            return static::group(['middleware' => $middleware], function ($router) use ($routes) {
                foreach ($routes as $route) {
                    if (count($route) >= 3) {
                        $method = strtolower($route[0]);
                        $path = $route[1];
                        $handler = $route[2];

                        if (method_exists($router, $method)) {
                            $router->$method($path, $handler);
                        }
                    }
                }
            });
        }

        return static::group(['middleware' => $middleware], $routes);
    }

    /**
     * Add a route parameter constraint
     *
     * @param string $path
     * @param mixed $handler
     * @param array $constraints
     * @return \Ody\Foundation\Router\Route
     */
    public static function pattern(string $path, $handler, array $constraints): \Ody\Foundation\Router\Route
    {
        $route = static::get($path, $handler);

        // If your Route class has a where() method, call it
        if (method_exists($route, 'where')) {
            $route->where($constraints);
        }

        return $route;
    }
}