<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware;

use Ody\Container\Container;
use Ody\Foundation\Middleware\Adapters\CallableMiddlewareAdapter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MiddlewareRegistry
 *
 * Central registry for middleware management including registration,
 * resolution, and execution of middleware chains.
 */
class MiddlewareRegistry
{
    /**
     * @var Container
     */
    protected Container $container;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var array Global middleware that runs for all requests
     */
    protected array $globalMiddleware = [];

    /**
     * @var array Named middleware map
     */
    protected array $namedMiddleware = [];

    /**
     * @var array Route-specific middleware
     */
    protected array $routeMiddleware = [];

    /**
     * @var array Middleware groups
     */
    protected array $middlewareGroups = [];

    /**
     * @var array Path pattern middleware
     */
    protected array $patternMiddleware = [];

    /**
     * @var array Middleware parameter cache
     */
    protected array $middlewareParams = [];
    private $groups;

    /**
     * Constructor
     *
     * @param Container $container
     * @param LoggerInterface|null $logger
     */
    public function __construct(Container $container, ?LoggerInterface $logger = null)
    {
        $this->container = $container;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Add a global middleware
     *
     * @param string|callable|MiddlewareInterface $middleware
     * @return self
     */
    public function addGlobal($middleware): self
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
    public function add(string $name, $middleware): self
    {
        $this->namedMiddleware[$name] = $middleware;

        return $this;
    }

    /**
     * Check if a named middleware exists
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->namedMiddleware[$name]);
    }

    /**
     * Get a named middleware
     *
     * @param string $name
     * @return mixed|null
     */
    public function get(string $name)
    {
        return $this->namedMiddleware[$name] ?? null;
    }

    /**
     * Add a middleware group
     *
     * @param string $name Group name
     * @param array $middleware List of middleware names or instances
     * @return self
     */
    public function addGroup(string $name, array $middleware): self
    {
        $this->middlewareGroups[$name] = $middleware;

        return $this;
    }

    /**
     * Add middleware to a route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param string|callable|MiddlewareInterface $middleware
     * @return self
     */
    public function addToRoute(string $method, string $path, $middleware): self
    {
        $route = $this->formatRouteIdentifier($method, $path);

        if (!isset($this->routeMiddleware[$route])) {
            $this->routeMiddleware[$route] = [];
        }

        $this->routeMiddleware[$route][] = $middleware;

        return $this;
    }

    /**
     * Add middleware to routes matching a pattern
     *
     * @param string $pattern Route pattern (uses fnmatch)
     * @param string|callable|MiddlewareInterface $middleware
     * @return self
     */
    public function addToPattern(string $pattern, $middleware): self
    {
        $this->patternMiddleware[] = [
            'pattern' => $pattern,
            'middleware' => $middleware
        ];

        return $this;
    }

    /**
     * Store middleware parameters
     *
     * @param string $middlewareName
     * @param array $params
     * @return self
     */
    public function withParameters(string $middlewareName, array $params): self
    {
        if (!isset($this->middlewareParams[$middlewareName])) {
            $this->middlewareParams[$middlewareName] = [];
        }

        $this->middlewareParams[$middlewareName] = array_merge(
            $this->middlewareParams[$middlewareName],
            $params
        );

        return $this;
    }

    /**
     * Get middleware parameters
     *
     * @param string $middlewareName
     * @return array
     */
    public function getParameters(string $middlewareName): array
    {
        return $this->middlewareParams[$middlewareName] ?? [];
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
        $route = $this->formatRouteIdentifier($method, $path);
        $middleware = $this->globalMiddleware;
        $routeMiddlewareAdded = false;

        // Step 1: Add exact match route middleware if found
        if (isset($this->routeMiddleware[$route])) {
            foreach ($this->routeMiddleware[$route] as $m) {
                $middleware[] = $m;
            }
            $routeMiddlewareAdded = true;
        }

        // Step 2: If no exact match was found, check for pattern-based routes
        if (!$routeMiddlewareAdded) {
            foreach (array_keys($this->routeMiddleware) as $routePattern) {
                // Skip if not for the same HTTP method
                if (strpos($routePattern, strtoupper($method) . ':') !== 0) {
                    continue;
                }

                // Extract just the path portion for pattern matching
                $patternPath = substr($routePattern, strlen(strtoupper($method) . ':'));

                // Check if this is a pattern-based route (contains regex pattern markers)
                if (strpos($patternPath, '{') !== false && strpos($patternPath, '}') !== false) {
                    // Create a regex pattern from the route pattern to match against the actual path
                    $regexPath = $this->convertRoutePatternToRegex($patternPath);

                    if (preg_match($regexPath, $path)) {
                        foreach ($this->routeMiddleware[$routePattern] as $m) {
                            $middleware[] = $m;
                        }
                        break;
                    }
                }
            }
        }

        // Process pattern-based middleware
        foreach ($this->patternMiddleware as $group) {
            if ($this->matchesPattern($route, $group['pattern'])) {
                $middleware[] = $group['middleware'];
            }
        }

        return $middleware;
    }

    /**
     * Convert a route pattern with {param:regex} to a regex pattern
     *
     * @param string $routePattern
     * @return string
     */
    protected function convertRoutePatternToRegex(string $routePattern): string
    {
        // Replace {param:regex} with (?P<param>regex)
        $pattern = preg_replace('/\{([^:}]+)(?::([^}]+))?}/', '(?P<$1>$2)', $routePattern);

        // If no regex was specified, match any character except /
        $pattern = preg_replace('/\(\?P<([^>]+)>\)/', '(?P<$1>[^/]+)', $pattern);

        // Add start and end markers and escape forward slashes
        $pattern = '#^' . $pattern . '$#';

        return $pattern;
    }

    /**
     * Process a request through middleware chain
     *
     * @param ServerRequestInterface $request
     * @param callable $finalHandler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, callable $finalHandler): ResponseInterface
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        // Get middleware for this route
        $middlewareList = $this->getMiddlewareForRoute($method, $path);

        // Create the final handler with additional validation
        $coreHandler = function (ServerRequestInterface $request) use ($finalHandler): ResponseInterface {
            try {
                $response = call_user_func($finalHandler, $request);

                // Validate response
                if (!$response instanceof ResponseInterface) {
                    $this->logger->error('Route handler returned an invalid response', [
                        'handler' => is_string($finalHandler) ? $finalHandler : 'Callable',
                        'response_type' => is_object($response) ? get_class($response) : gettype($response)
                    ]);

                    // Create a fallback response
                    $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
                    $response = $factory->createResponse(500)
                        ->withHeader('Content-Type', 'application/json');
                    $response->getBody()->write(json_encode([
                        'error' => 'Internal Server Error',
                        'message' => 'Route handler returned an invalid response type'
                    ]));

                    return $response;
                }

                return $response;
            } catch (\Throwable $e) {
                $this->logger->error('Exception in route handler', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]);

                // Create an error response
                $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
                $response = $factory->createResponse(500)
                    ->withHeader('Content-Type', 'application/json');
                $response->getBody()->write(json_encode([
                    'error' => 'Internal Server Error',
                    'message' => $e->getMessage()
                ]));

                return $response;
            }
        };

        $handler = new RequestHandler($coreHandler);

        // Add middleware in reverse order (so they execute in the order they were added)
        foreach (array_reverse($middlewareList) as $middleware) {
            $resolvedMiddleware = $this->resolveMiddleware($middleware);
            if ($resolvedMiddleware) {
                $handler->add($resolvedMiddleware);
            }
        }

        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            $this->logger->error('Exception in middleware chain', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            // Create an error response
            $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            $response = $factory->createResponse(500)
                ->withHeader('Content-Type', 'application/json');
            $response->getBody()->write(json_encode([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ]));

            return $response;
        }
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
            // Try each resolution strategy in order
            $instance = $this->resolveBuiltInTypes($middleware)
                ?? $this->resolveNamedMiddleware($middleware)
                ?? $this->resolveMiddlewareGroup($middleware)
                ?? $this->resolveClassMiddleware($middleware)
                ?? $this->resolveParameterizedMiddleware($middleware);

            if ($instance) {
                return $instance;
            }

            $this->logger->warning("Failed to resolve middleware", ['middleware' => $this->getMiddlewareName($middleware)]);
            return null;

        } catch (\Throwable $e) {
            $this->logger->error("Error resolving middleware", [
                'middleware' => $this->getMiddlewareName($middleware),
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
     * Resolve built-in middleware types (instances and callables)
     *
     * @param mixed $middleware
     * @return MiddlewareInterface|null
     */
    private function resolveBuiltInTypes($middleware): ?MiddlewareInterface
    {
        // Already a PSR-15 middleware
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        // Callable middleware
        if (is_callable($middleware)) {
            return new CallableMiddlewareAdapter($middleware);
        }

        return null;
    }

    /**
     * Resolve named middleware
     *
     * @param mixed $middleware
     * @return MiddlewareInterface|null
     */
    private function resolveNamedMiddleware($middleware): ?MiddlewareInterface
    {
        if (is_string($middleware) && isset($this->namedMiddleware[$middleware])) {
            return $this->resolveMiddleware($this->namedMiddleware[$middleware]);
        }

        return null;
    }

    /**
     * Resolve middleware group
     *
     * @param mixed $middleware
     * @return MiddlewareInterface|null
     */
    private function resolveMiddlewareGroup($middleware): ?MiddlewareInterface
    {
        if (is_string($middleware) && isset($this->middlewareGroups[$middleware])) {
            return $this->createMiddlewareGroupAdapter($this->middlewareGroups[$middleware]);
        }

        return null;
    }

    /**
     * Create an adapter for a middleware group
     *
     * @param array $group
     * @return MiddlewareInterface
     */
    private function createMiddlewareGroupAdapter(array $group): MiddlewareInterface
    {
        return new class($this, $group) implements MiddlewareInterface {
            protected $registry;
            protected $group;

            public function __construct(MiddlewareRegistry $registry, array $group) {
                $this->registry = $registry;
                $this->group = $group;
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                $innerHandler = $handler;

                // Add middleware in reverse order
                foreach (array_reverse($this->group) as $middleware) {
                    $resolved = $this->registry->resolveMiddleware($middleware);
                    if ($resolved) {
                        // Create a wrapper that chains this middleware with the current handler
                        $currentHandler = $innerHandler; // Capture the current handler
                        $innerHandler = new class($resolved, $currentHandler) implements RequestHandlerInterface {
                            private $middleware;
                            private $handler;

                            public function __construct(MiddlewareInterface $middleware, RequestHandlerInterface $handler) {
                                $this->middleware = $middleware;
                                $this->handler = $handler;
                            }

                            public function handle(ServerRequestInterface $request): ResponseInterface {
                                return $this->middleware->process($request, $this->handler);
                            }
                        };
                    }
                }

                return $innerHandler->handle($request);
            }
        };
    }

    /**
     * Resolve class-based middleware
     *
     * @param mixed $middleware
     * @return MiddlewareInterface|null
     */
    private function resolveClassMiddleware($middleware): ?MiddlewareInterface
    {
        if (!is_string($middleware) || !class_exists($middleware)) {
            return null;
        }

        // Try to resolve from container first
        if ($this->container->has($middleware)) {
            $instance = $this->container->make($middleware);

            if ($instance instanceof MiddlewareInterface) {
                return $instance;
            }

            return null;
        }

        // Try to instantiate using reflection if not in container
        return $this->instantiateUsingReflection($middleware);
    }

    /**
     * Instantiate a middleware class using reflection
     *
     * @param string $class
     * @return MiddlewareInterface|null
     */
    private function instantiateUsingReflection(string $class): ?MiddlewareInterface
    {
        $reflector = new \ReflectionClass($class);

        // If no constructor or constructor has no parameters, we can create directly
        $constructor = $reflector->getConstructor();
        if (!$constructor || $constructor->getNumberOfParameters() === 0) {
            $instance = new $class();

            if ($instance instanceof MiddlewareInterface) {
                return $instance;
            }

            return null;
        }

        // Try to resolve constructor parameters from the container
        $parameters = [];
        $canResolveAll = true;

        foreach ($constructor->getParameters() as $param) {
            $paramInstance = $this->resolveConstructorParameter($param, $class);

            if ($paramInstance !== null) {
                $parameters[] = $paramInstance;
            } else {
                $canResolveAll = false;
                break;
            }
        }

        if ($canResolveAll) {
            $instance = $reflector->newInstanceArgs($parameters);

            if ($instance instanceof MiddlewareInterface) {
                return $instance;
            }
        }

        return null;
    }

    /**
     * Resolve a constructor parameter
     *
     * @param \ReflectionParameter $param
     * @param string $className For logging purposes
     * @return mixed|null
     */
    private function resolveConstructorParameter(\ReflectionParameter $param, string $className)
    {
        // Try to resolve by type hint
        $type = $param->getType() && !$param->getType()->isBuiltin()
            ? $param->getType()->getName()
            : null;

        if ($type && $this->container->has($type)) {
            return $this->container->make($type);
        }

        // Try to use default value
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        // Can't resolve parameter
        $this->logger->warning(
            "Cannot resolve parameter '{$param->getName()}' for middleware '{$className}'",
            ['type' => $type]
        );

        return null;
    }

    /**
     * Resolve parameterized middleware (e.g., 'auth:api')
     *
     * @param mixed $middleware
     * @return MiddlewareInterface|null
     */
    private function resolveParameterizedMiddleware($middleware): ?MiddlewareInterface
    {
        if (!is_string($middleware) || strpos($middleware, ':') === false) {
            return null;
        }

        list($name, $param) = explode(':', $middleware, 2);

        // Store the parameter
        $this->withParameters($name, ['value' => $param]);

        // Try to resolve the base middleware
        if (isset($this->namedMiddleware[$name])) {
            return $this->resolveMiddleware($this->namedMiddleware[$name]);
        }

        return null;
    }

    /**
     * Format a route identifier
     *
     * @param string $method
     * @param string $path
     * @return string
     */
    protected function formatRouteIdentifier(string $method, string $path): string
    {
        return strtoupper($method) . ':' . $path;
    }

    /**
     * Check if a route matches a pattern
     *
     * @param string $route
     * @param string $pattern
     * @return bool
     */
    protected function matchesPattern(string $route, string $pattern): bool
    {
        return fnmatch($pattern, $route);
    }

    /**
     * Get a readable name for a middleware
     *
     * @param mixed $middleware
     * @return string
     */
    protected function getMiddlewareName($middleware): string
    {
        if (is_string($middleware)) {
            return $middleware;
        } elseif (is_object($middleware)) {
            return get_class($middleware);
        } elseif (is_callable($middleware)) {
            return 'Callable';
        } else {
            return gettype($middleware);
        }
    }

    /**
     * Get a list of all named middleware
     *
     * @return array
     */
    public function getNamedMiddleware(): array
    {
        return $this->namedMiddleware;
    }

    /**
     * Get a list of all middleware groups
     *
     * @return array
     */
    public function getMiddlewareGroups(): array
    {
        return $this->middlewareGroups;
    }

    /**
     * Get all global middleware
     *
     * @return array
     */
    public function getGlobalMiddleware(): array
    {
        return $this->globalMiddleware;
    }
}