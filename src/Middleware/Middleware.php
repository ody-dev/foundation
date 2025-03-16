<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware;


use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ody\Foundation\Http\Response;
use Ody\Foundation\Http\Request;
use Ody\Container\Container;

/**
 * PSR-15 compliant middleware implementation
 */
class Middleware
{
    /**
     * @var array Global middleware stack
     */
    private array $globalMiddleware = [];

    /**
     * @var array Named middleware
     */
    private array $namedMiddleware = [];

    /**
     * @var array Route-specific middleware
     */
    private array $routeMiddleware = [];

    /**
     * @var array Middleware groups
     */
    private array $groups = [];

    /**
     * @var Container|null
     */
    private ?Container $container;

    /**
     * Middleware constructor
     *
     * @param Container|null $container
     */
    public function __construct(?Container $container = null)
    {
        $this->container = $container;
    }

    /**
     * Add global middleware
     *
     * @param string|callable|MiddlewareInterface $middleware
     * @return self
     */
    public function add($middleware): self
    {
        $this->globalMiddleware[] = $middleware;
        return $this;
    }

    /**
     * Register a named middleware
     *
     * @param string $name
     * @param string|callable|MiddlewareInterface $middleware
     * @return self
     */
    public function addNamed(string $name, $middleware): self
    {
        $this->namedMiddleware[$name] = $middleware;
        return $this;
    }

    /**
     * Get a named middleware
     *
     * @param string $name
     * @return callable|MiddlewareInterface|null
     */
    public function getNamedMiddleware(string $name)
    {
        return $this->namedMiddleware[$name] ?? null;
    }

    /**
     * Apply middleware to a specific route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param string|callable|MiddlewareInterface $middleware
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
     * @param string|callable|MiddlewareInterface $middleware
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
                $middleware[] = $m;
            }
        }

        // Add group middleware
        foreach ($this->groups as $group) {
            if ($this->routeMatchesPattern($route, $group['pattern'])) {
                $middleware[] = $group['middleware'];
            }
        }

        return $middleware;
    }

    /**
     * Resolve a middleware to a PSR-15 middleware instance
     *
     * @param mixed $middleware
     * @return MiddlewareInterface|null
     */
    public function resolveMiddleware($middleware): ?MiddlewareInterface
    {
        try {
            // If it's already a PSR-15 middleware, return it
            if ($middleware instanceof MiddlewareInterface) {
                return $middleware;
            }

            // If it's a callable that returns a middleware, invoke it
            if (is_callable($middleware) && !$middleware instanceof MiddlewareInterface) {
                $resolvedMiddleware = $middleware();
                if ($resolvedMiddleware instanceof MiddlewareInterface) {
                    return $resolvedMiddleware;
                }
            }

            // Handle string middleware with possible parameters
            if (is_string($middleware)) {
                list($name, $parameters) = $this->parseMiddlewareString($middleware);

                // Check if we have the middleware registered
                if (isset($this->namedMiddleware[$name])) {
                    return $this->resolveNamedMiddleware($name, $parameters);
                }

                // Try to resolve as a class name
                if (class_exists($middleware)) {
                    $instance = $this->resolveClass($middleware);
                    if ($instance instanceof MiddlewareInterface) {
                        return $instance;
                    }
                }

                $this->logger->warning("Failed to resolve string middleware", ['middleware' => $middleware]);
                return null;
            }

            // Handle array configuration with class and parameters
            if (is_array($middleware) && isset($middleware['class'])) {
                $class = $middleware['class'];
                $parameters = $middleware['parameters'] ?? [];

                if (class_exists($class)) {
                    $instance = $this->resolveClass($class);
                    if ($instance instanceof MiddlewareInterface) {
                        if (!empty($parameters)) {
                            return new ParameterizedMiddlewareDecorator($instance, $parameters);
                        }
                        return $instance;
                    }
                }

                $this->logger->warning("Failed to resolve array middleware configuration", [
                    'class' => $class,
                    'parameters' => $parameters
                ]);
                return null;
            }

            // We don't know how to handle this type
            $this->logger->warning("Unknown middleware type", ['type' => gettype($middleware)]);
            return null;
        } catch (\Throwable $e) {
            $this->logger->error("Error resolving middleware", [
                'middleware' => is_string($middleware) ? $middleware : gettype($middleware),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if (env('APP_DEBUG', false)) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * Resolve a named middleware
     *
     * @param string $name
     * @param array $parameters
     * @return MiddlewareInterface|null
     */
    private function resolveNamedMiddleware(string $name, array $parameters = []): ?MiddlewareInterface
    {
        $middlewareDef = $this->namedMiddleware[$name];

        // If it's a string (class name)
        if (is_string($middlewareDef)) {
            $instance = $this->resolveClass($middlewareDef);
            if ($instance instanceof MiddlewareInterface) {
                if (!empty($parameters)) {
                    return new ParameterizedMiddlewareDecorator($instance, $parameters);
                }
                return $instance;
            }
        }

        // If it's already a middleware instance
        if ($middlewareDef instanceof MiddlewareInterface) {
            if (!empty($parameters)) {
                return new ParameterizedMiddlewareDecorator($middlewareDef, $parameters);
            }
            return $middlewareDef;
        }

        // If it's a callable
        if (is_callable($middlewareDef)) {
            $instance = $middlewareDef();
            if ($instance instanceof MiddlewareInterface) {
                if (!empty($parameters)) {
                    return new ParameterizedMiddlewareDecorator($instance, $parameters);
                }
                return $instance;
            }
        }

        // If it's an array configuration
        if (is_array($middlewareDef) && isset($middlewareDef['class'])) {
            $class = $middlewareDef['class'];
            $baseParams = $middlewareDef['parameters'] ?? [];

            // Merge parameters with priority to runtime parameters
            $mergedParams = array_merge($baseParams, $parameters);

            $instance = $this->resolveClass($class);
            if ($instance instanceof MiddlewareInterface) {
                if (!empty($mergedParams)) {
                    return new ParameterizedMiddlewareDecorator($instance, $mergedParams);
                }
                return $instance;
            }
        }

        $this->logger->warning("Failed to resolve named middleware", ['name' => $name]);
        return null;
    }

    /**
     * Resolve a class to a middleware instance
     *
     * @param string $class
     * @return MiddlewareInterface|null
     */
    private function resolveClass(string $class): ?MiddlewareInterface
    {
        if (!class_exists($class)) {
            $this->logger->warning("Middleware class does not exist", ['class' => $class]);
            return null;
        }

        try {
            // Try to resolve from container first
            if ($this->container->has($class)) {
                $instance = $this->container->make($class);
            } else {
                // Create a new instance
                $instance = new $class();
            }

            if ($instance instanceof MiddlewareInterface) {
                return $instance;
            }

            $this->logger->warning("Class is not a middleware", [
                'class' => $class,
                'type' => get_class($instance)
            ]);
            return null;
        } catch (\Throwable $e) {
            $this->logger->error("Error creating middleware instance", [
                'class' => $class,
                'error' => $e->getMessage()
            ]);

            if (env('APP_DEBUG', false)) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * Parse a middleware string into name and parameters
     *
     * @param string $middlewareName Middleware name (e.g., 'auth:api' or 'throttle:60,1')
     * @return array [name, parameters]
     */
    private function parseMiddlewareString(string $middlewareName): array
    {
        // Default values
        $name = $middlewareName;
        $parameters = [];

        // Check if middleware has parameters
        if (str_contains($middlewareName, ':')) {
            list($name, $paramString) = explode(':', $middlewareName, 2);

            // Parse parameters
            if (str_contains($paramString, ',')) {
                // Multiple parameters (e.g., 'throttle:60,1')
                $paramValues = explode(',', $paramString);

                // For throttle middleware
                if ($name === 'throttle' && count($paramValues) >= 2) {
                    $parameters = [
                        'maxRequests' => (int) $paramValues[0],
                        'minutes' => (int) $paramValues[1]
                    ];
                }
                // For other middleware with multiple parameters
                else {
                    foreach ($paramValues as $i => $value) {
                        $parameters["param$i"] = $value;
                    }
                }
            } else {
                // Single parameter

                // For auth middleware
                if ($name === 'auth') {
                    $parameters['guard'] = $paramString;
                }
                // For role middleware
                else if ($name === 'role') {
                    $parameters['requiredRole'] = $paramString;
                }
                // For other middleware
                else {
                    $parameters['value'] = $paramString;
                }
            }
        }

        return [$name, $parameters];
    }

    /**
     * Run middleware stack for a route
     *
     * @param ServerRequestInterface $request
     * @param callable $handler Final request handler
     * @return ResponseInterface
     */
    public function run(ServerRequestInterface $request, callable $handler): ResponseInterface
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        $middlewareList = $this->getMiddlewareForRoute($method, $path);

        // Resolve all middleware to PSR-15 compatible instances
        $psr15Middleware = [];
        foreach ($middlewareList as $middleware) {
            try {
                $psr15Middleware[] = $this->resolveMiddleware($middleware);
            } catch (\InvalidArgumentException $e) {
                // Log and continue, skipping this middleware
                if ($this->container && $this->container->has('logger')) {
                    $this->container->get('logger')->warning('Failed to resolve middleware', [
                        'error' => $e->getMessage(),
                        'middleware' => is_string($middleware) ? $middleware : get_class($middleware)
                    ]);
                }
            }
        }

        // Create callable adapter for the final handler
        $handlerAdapter = function (ServerRequestInterface $request) use ($handler): ResponseInterface {
            $response = call_user_func($handler, $request);

            // Ensure we return a ResponseInterface
            if (!$response instanceof ResponseInterface) {
                // If handler returns something else, convert to Response
                if (is_string($response)) {
                    return (new Response())->body($response);
                } elseif (is_array($response) || is_object($response)) {
                    return (new Response())->json()->withJson($response);
                } else {
                    return new Response();
                }
            }

            return $response;
        };

        // Create request handler with middleware stack
        $requestHandler = new RequestHandler($handlerAdapter, $psr15Middleware);

        // Process the request through the middleware stack
        return $requestHandler->handle($request);
    }
}