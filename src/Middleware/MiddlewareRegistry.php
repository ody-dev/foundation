<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

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
use Ody\Foundation\Middleware\Attributes\Middleware;
use Ody\Foundation\Middleware\Attributes\MiddlewareGroup;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

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
     * @var array Global middleware applied to all requests
     */
    protected array $global = [];

    /**
     * @var array Route-specific middleware
     */
    protected array $routes = [];

    /**
     * @var array Named middleware map
     */
    protected array $named = [];

    /**
     * @var array Middleware groups
     */
    protected array $groups = [];

    /**
     * @var array Cache of resolved middleware instances
     */
    protected array $resolved = [];

    /**
     * @var array Cache of resolved controller middleware
     */
    protected array $controllerCache = [];

    /**
     * @var array Cache of resolved method middleware
     */
    protected array $methodCache = [];

    /**
     * @var bool Whether to collect cache statistics
     */
    protected bool $collectStats;

    /**
     * @var array Cache hits for statistics
     */
    protected array $cacheHits = [];

    /**
     * Constructor
     *
     * @param Container $container
     * @param LoggerInterface|null $logger
     * @param bool $collectStats Whether to collect cache statistics
     */
    public function __construct(
        Container $container,
        ?LoggerInterface $logger = null,
        bool      $collectStats = false
    )
    {
        $this->container = $container;
        $this->logger = $logger ?? new NullLogger();
        $this->collectStats = $collectStats;
    }

    /**
     * Register middleware for a specific route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param mixed $middleware
     * @return self
     */
    public function addForRoute(string $method, string $path, $middleware): self
    {
        $routeKey = $this->formatRouteKey($method, $path);
        if (!isset($this->routes[$routeKey])) {
            $this->routes[$routeKey] = [];
        }

        $this->routes[$routeKey][] = $middleware;

        $this->logger->debug("Registered route middleware", [
            'method' => $method,
            'path' => $path
        ]);

        return $this;
    }

    /**
     * Register a global middleware
     *
     * @param mixed $middleware
     * @return self
     */
    public function global($middleware): self
    {
        $this->global[] = $middleware;
        return $this;
    }

    /**
     * Register a named middleware
     *
     * @param string $name
     * @param mixed $middleware
     * @return self
     */
    public function named(string $name, $middleware): self
    {
        $this->named[$name] = $middleware;
        return $this;
    }

    /**
     * Register a middleware group
     *
     * @param string $name
     * @param array $middlewareList
     * @return self
     */
    public function group(string $name, array $middlewareList): self
    {
        $this->groups[$name] = $middlewareList;
        return $this;
    }

    /**
     * Format a route key
     *
     * @param string $method
     * @param string $path
     * @return string
     */
    protected function formatRouteKey(string $method, string $path): string
    {
        return strtoupper($method) . ':' . $path;
    }

    /**
     * Build a middleware pipeline for a specific route
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @return array List of middleware for this route
     */
    public function buildPipeline(string $method, string $path): array
    {
        // Start with global middleware
        $middlewareList = array_values($this->global);

        // Add route-specific middleware if any
        $routeKey = $this->formatRouteKey($method, $path);
        if (isset($this->routes[$routeKey])) {
            $middlewareList = array_merge($middlewareList, $this->routes[$routeKey]);
        }

        // Process and expand the middleware list
        return $this->expandMiddleware($middlewareList);
    }

    /**
     * Get all middleware for a route, including controller attributes if provided
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param string|object|null $controller Controller class or instance
     * @param string|null $action Controller method name
     * @return array Combined middleware list
     */
    public function getMiddlewareForRoute(
        string  $method,
        string  $path,
                $controller = null,
        ?string $action = null
    ): array
    {
        // Get route middleware
        $middlewareList = $this->buildPipeline($method, $path);

        // If controller and method are provided, get attribute middleware
        if ($controller && $action) {
            $attributeMiddleware = $this->getMiddleware($controller, $action);
            $middlewareList = array_merge($middlewareList, $this->convertAttributeFormat($attributeMiddleware));
        }

        return $middlewareList;
    }

    /**
     * Convert attribute middleware to standard format
     *
     * @param array $attributeMiddleware
     * @return array
     */
    protected function convertAttributeFormat(array $attributeMiddleware): array
    {
        $result = [];

        foreach ($attributeMiddleware as $middleware) {
            if (isset($middleware['class'])) {
                $result[] = $middleware['class'];
            } elseif (isset($middleware['group'])) {
                $result[] = $middleware['group'];
            }
        }

        return $result;
    }

    /**
     * Expand middleware references (resolve named middleware and groups)
     *
     * @param array $middleware
     * @return array
     */
    protected function expandMiddleware(array $middleware): array
    {
        $result = [];

        foreach ($middleware as $item) {
            if (is_string($item)) {
                // Check if it's a named middleware
                if (isset($this->named[$item])) {
                    $result[] = $this->named[$item];
                    continue;
                }

                // Check if it's a middleware group
                if (isset($this->groups[$item])) {
                    $expanded = $this->expandMiddleware($this->groups[$item]);
                    $result = array_merge($result, $expanded);
                    continue;
                }
            }

            // Add the middleware as is
            $result[] = $item;
        }

        return $result;
    }

    /**
     * Resolve a middleware to an instance
     *
     * @param mixed $middleware
     * @return MiddlewareInterface
     * @throws \RuntimeException If middleware cannot be resolved
     */
    public function resolve($middleware): MiddlewareInterface
    {
        // If already an instance, return it
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        // Get cache key
        $cacheKey = $this->getCacheKey($middleware);

        // Check cache
        if (isset($this->resolved[$cacheKey])) {
            if ($this->collectStats) {
                $this->cacheHits[$cacheKey] = ($this->cacheHits[$cacheKey] ?? 0) + 1;
            }
            return $this->resolved[$cacheKey];
        }

        try {
            // Resolve the middleware
            $instance = $this->resolveMiddleware($middleware);

            // Cache the result
            $this->resolved[$cacheKey] = $instance;

            return $instance;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to resolve middleware', [
                'middleware' => is_string($middleware) ? $middleware : gettype($middleware),
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException(
                'Failed to resolve middleware: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get a cache key for a middleware
     *
     * @param mixed $middleware
     * @return string
     */
    protected function getCacheKey($middleware): string
    {
        if (is_string($middleware)) {
            return 'str:' . $middleware;
        }

        if (is_array($middleware)) {
            if (count($middleware) === 2 && is_callable($middleware)) {
                return 'arr:' . (is_object($middleware[0])
                        ? get_class($middleware[0])
                        : (string)$middleware[0]) . '::' . (string)$middleware[1];
            }
            return 'arr:' . md5(serialize($middleware));
        }

        if (is_object($middleware)) {
            return 'obj:' . get_class($middleware) . ':' . spl_object_hash($middleware);
        }

        return 'other:' . gettype($middleware);
    }

    /**
     * Resolve middleware from various formats
     *
     * @param mixed $middleware
     * @return MiddlewareInterface
     * @throws \RuntimeException If middleware cannot be resolved
     */
    protected function resolveMiddleware($middleware): MiddlewareInterface
    {
        // Handle MiddlewareConfig objects
        if ($middleware instanceof MiddlewareConfig) {
            $class = $middleware->getClass();
            $parameters = $middleware->getParameters();

            // Try to create the instance with the provided parameters
            $instance = $this->createInstanceWithParameters($class, $parameters);

            // Ensure it's a valid middleware
            if ($instance instanceof MiddlewareInterface) {
                return $instance;
            }

            throw new \RuntimeException(
                "Middleware class '$class' must implement MiddlewareInterface"
            );
        }

        // Handle string class names
        if (is_string($middleware) && class_exists($middleware)) {
            // Try to resolve from container
            if ($this->container->has($middleware)) {
                $instance = $this->container->make($middleware);
            } else {
                // FIXED: Instead of creating directly with new, use container's make method
                // This allows the container to resolve dependencies
                try {
                    $instance = $this->container->make($middleware);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to resolve middleware via container, trying reflection: ' . $e->getMessage());
                    // Fallback to reflection-based instantiation
                    $instance = $this->createInstanceWithReflection($middleware);
                }
            }

            // Ensure it's a valid middleware
            if ($instance instanceof MiddlewareInterface) {
                return $instance;
            }

            // Convert callable to middleware adapter
            if (is_callable($instance)) {
                return new CallableMiddlewareAdapter($instance);
            }

            throw new \RuntimeException(
                "Middleware class '$middleware' must implement MiddlewareInterface or be callable"
            );
        }

        // Handle callable middleware
        if (is_callable($middleware)) {
            return new CallableMiddlewareAdapter($middleware);
        }

        throw new \RuntimeException(
            'Middleware must be a class name, instance of MiddlewareInterface, callable, or MiddlewareConfig'
        );
    }

    /**
     * Create an instance with specific parameters
     *
     * @param string $className
     * @param array $parameters
     * @return object
     * @throws \ReflectionException
     */
    protected function createInstanceWithParameters(string $className, array $parameters): object
    {
        $reflectionClass = new \ReflectionClass($className);

        if (!$reflectionClass->isInstantiable()) {
            throw new \RuntimeException("Class $className is not instantiable");
        }

        $constructor = $reflectionClass->getConstructor();

        // If no constructor, create instance directly
        if ($constructor === null) {
            return new $className();
        }

        // Get constructor parameters
        $constructorParams = $constructor->getParameters();
        $resolvedParams = [];

        // Resolve each parameter
        foreach ($constructorParams as $param) {
            $paramName = $param->getName();

            // If parameter exists in provided parameters, use it
            if (array_key_exists($paramName, $parameters)) {
                $resolvedParams[] = $parameters[$paramName];
                continue;
            }

            // Otherwise try to resolve from container
            $paramType = $param->getType();
            if ($paramType !== null && !$paramType->isBuiltin()) {
                $typeName = $paramType->getName();

                if ($this->container->has($typeName)) {
                    $resolvedParams[] = $this->container->make($typeName);
                    continue;
                }
            }

            // If parameter is optional, use default value
            if ($param->isOptional()) {
                $resolvedParams[] = $param->getDefaultValue();
                continue;
            }

            // If we can't resolve the parameter, log and throw exception
            $this->logger->error("Cannot resolve parameter '$paramName' for class $className");
            throw new \RuntimeException("Cannot resolve parameter '$paramName' for class $className");
        }

        // Create instance with resolved parameters
        return $reflectionClass->newInstanceArgs($resolvedParams);
    }

    /**
     * Create an instance using reflection to resolve constructor dependencies
     *
     * @param string $className
     * @return object
     * @throws \ReflectionException
     */
    protected function createInstanceWithReflection(string $className): object
    {
        $reflectionClass = new \ReflectionClass($className);

        if (!$reflectionClass->isInstantiable()) {
            throw new \RuntimeException("Class $className is not instantiable");
        }

        $constructor = $reflectionClass->getConstructor();

        // If no constructor or constructor has no parameters, create instance directly
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return new $className();
        }

        // Resolve constructor parameters
        $parameters = [];
        foreach ($constructor->getParameters() as $param) {
            $paramName = $param->getName();
            $paramType = $param->getType();

            // If parameter has a type, try to resolve from container
            if ($paramType !== null && !$paramType->isBuiltin()) {
                $typeName = $paramType->getName();

                if ($this->container->has($typeName)) {
                    $parameters[] = $this->container->make($typeName);
                    continue;
                }

                // Special handling for common types
                if ($typeName === LoggerInterface::class && $this->container->has('logger')) {
                    $parameters[] = $this->container->make('logger');
                    continue;
                }
            }

            // Check if parameter is optional
            if ($param->isOptional()) {
                $parameters[] = $param->getDefaultValue();
                continue;
            }

            // If we can't resolve the parameter, log and throw exception
            $this->logger->error("Cannot resolve parameter '$paramName' for class $className");
            throw new \RuntimeException("Cannot resolve parameter '$paramName' for class $className");
        }

        // Create instance with resolved parameters
        return $reflectionClass->newInstanceArgs($parameters);
    }

    /**
     * Get middleware for a controller and method
     *
     * @param object|string $controller Controller class or instance
     * @param string $method Method name
     * @return array Combined middleware list for the controller and method
     */
    public function getMiddleware(object|string $controller, string $method): array
    {
        // Get the controller class name
        $controllerClass = is_object($controller) ? get_class($controller) : $controller;

        // Create a unique key for the controller method
        $methodKey = $controllerClass . '@' . $method;

        // Return from cache if available
        if (isset($this->methodCache[$methodKey])) {
            return $this->methodCache[$methodKey];
        }

        // Get controller-level middleware
        $controllerMiddleware = $this->getControllerMiddleware($controllerClass);

        // Get method-level middleware
        $methodMiddleware = $this->getMethodMiddleware($controllerClass, $method);

        // Combine the middleware lists (method-level middleware takes precedence)
        $combinedMiddleware = array_merge($controllerMiddleware, $methodMiddleware);

        // Cache the result
        $this->methodCache[$methodKey] = $combinedMiddleware;

        $this->logger->debug("Resolved middleware for {$methodKey}", [
            'count' => count($combinedMiddleware)
        ]);

        return $combinedMiddleware;
    }

    /**
     * Get middleware defined at the controller class level
     *
     * @param string $controllerClass
     * @return array
     */
    protected function getControllerMiddleware(string $controllerClass): array
    {
        // Return from cache if available
        if (isset($this->controllerCache[$controllerClass])) {
            return $this->controllerCache[$controllerClass];
        }

        // Check if class exists
        if (!class_exists($controllerClass)) {
            $this->logger->warning("Controller class not found: {$controllerClass}");
            return [];
        }

        $middleware = [];

        try {
            $reflectionClass = new ReflectionClass($controllerClass);

            // Get middleware attributes
            $middlewareAttributes = $reflectionClass->getAttributes(Middleware::class);
            foreach ($middlewareAttributes as $attribute) {
                $instance = $attribute->newInstance();
                $middlewareClass = $instance->getMiddleware();
                $parameters = $instance->getParameters();

                if (is_array($middlewareClass)) {
                    // Handle array of middleware
                    foreach ($middlewareClass as $mw) {
                        $middleware[] = [
                            'class' => $mw,
                            'parameters' => $parameters
                        ];
                    }
                } else {
                    // Handle single middleware
                    $middleware[] = [
                        'class' => $middlewareClass,
                        'parameters' => $parameters
                    ];
                }
            }

            // Get middleware group attributes
            $groupAttributes = $reflectionClass->getAttributes(MiddlewareGroup::class);
            foreach ($groupAttributes as $attribute) {
                $instance = $attribute->newInstance();
                $middleware[] = [
                    'group' => $instance->getGroupName(),
                    'parameters' => []
                ];
            }

        } catch (\Throwable $e) {
            $this->logger->error("Error resolving controller middleware: {$e->getMessage()}", [
                'controller' => $controllerClass,
                'error' => $e->getMessage()
            ]);
        }

        // Cache the result
        $this->controllerCache[$controllerClass] = $middleware;

        return $middleware;
    }

    /**
     * Get middleware defined at the method level
     *
     * @param string $controllerClass
     * @param string $method
     * @return array
     */
    protected function getMethodMiddleware(string $controllerClass, string $method): array
    {
        // Check if class and method exist
        if (!class_exists($controllerClass) || !method_exists($controllerClass, $method)) {
            return [];
        }

        $middleware = [];

        try {
            $reflectionMethod = new ReflectionMethod($controllerClass, $method);

            // Get middleware attributes
            $middlewareAttributes = $reflectionMethod->getAttributes(Middleware::class);
            foreach ($middlewareAttributes as $attribute) {
                $instance = $attribute->newInstance();
                $middlewareClass = $instance->getMiddleware();
                $parameters = $instance->getParameters();

                if (is_array($middlewareClass)) {
                    // Handle array of middleware
                    foreach ($middlewareClass as $mw) {
                        $middleware[] = [
                            'class' => $mw,
                            'parameters' => $parameters
                        ];
                    }
                } else {
                    // Handle single middleware
                    $middleware[] = [
                        'class' => $middlewareClass,
                        'parameters' => $parameters
                    ];
                }
            }

            // Get middleware group attributes
            $groupAttributes = $reflectionMethod->getAttributes(MiddlewareGroup::class);
            foreach ($groupAttributes as $attribute) {
                $instance = $attribute->newInstance();
                $middleware[] = [
                    'group' => $instance->getGroupName(),
                    'parameters' => []
                ];
            }

        } catch (\Throwable $e) {
            $this->logger->error("Error resolving method middleware: {$e->getMessage()}", [
                'controller' => $controllerClass,
                'method' => $method,
                'error' => $e->getMessage()
            ]);
        }

        return $middleware;
    }

    /**
     * Load middleware configuration from array
     *
     * @param array $config
     * @return self
     */
    public function fromConfig(array $config): self
    {
        // Register named middleware
        if (isset($config['named']) && is_array($config['named'])) {
            $this->registerNamedMiddleware($config['named']);
        }

        // Register groups
        if (isset($config['groups']) && is_array($config['groups'])) {
            $this->registerGroupedMiddleware($config['groups']);
        }

        // Register global middleware
        if (isset($config['global']) && is_array($config['global'])) {
            foreach ($config['global'] as $middleware) {
                // Check if middleware has parameters
                if (is_array($middleware) && isset($middleware['class'])) {
                    $middlewareClass = $middleware['class'];
                    $parameters = $middleware['parameters'] ?? [];
                    $this->global(new MiddlewareConfig($middlewareClass, $parameters));
                } else {
                    $this->global($middleware);
                }
            }
        }

        return $this;
    }

    /**
     * Registers named middleware
     *
     * @param array $nameMiddleware
     * @return void
     */
    public function registerNamedMiddleware(array $nameMiddleware): void
    {
        foreach ($nameMiddleware as $name => $middleware) {
            // Check if middleware has parameters
            if (is_array($middleware) && isset($middleware['class'])) {
                $middlewareClass = $middleware['class'];
                $parameters = $middleware['parameters'] ?? [];
                $this->named($name, new MiddlewareConfig($middlewareClass, $parameters));
            } else {
                $this->named($name, $middleware);
            }
        }
    }

    /**
     * Registers grouped middleware
     *
     * @param array $groupedMiddleware
     * @return void
     */
    private function registerGroupedMiddleware(array $groupedMiddleware): void
    {
        foreach ($groupedMiddleware as $name => $middlewareList) {
            $processed = $this->processMiddlewareConfig($middlewareList);
            $this->group($name, $processed);
        }
    }

    /**
     * Process middleware configuration to support parameters
     *
     * @param array $middlewareList
     * @return array
     */
    protected function processMiddlewareConfig(array $middlewareList): array
    {
        $result = [];

        foreach ($middlewareList as $middleware) {
            // Check if it's a middleware with parameters
            if (is_array($middleware) && isset($middleware['class'])) {
                $middlewareClass = $middleware['class'];
                $parameters = $middleware['parameters'] ?? [];
                $result[] = new MiddlewareConfig($middlewareClass, $parameters);
            } // Check if it's a [class, parameters] array format
            elseif (is_array($middleware) && count($middleware) === 2 && is_string($middleware[0]) && is_array($middleware[1])) {
                $middlewareClass = $middleware[0];
                $parameters = $middleware[1];
                $result[] = new MiddlewareConfig($middlewareClass, $parameters);
            } else {
                $result[] = $middleware;
            }
        }

        return $result;
    }
}