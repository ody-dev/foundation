<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware;

use Ody\Container\Container;
use Ody\Foundation\Middleware\Adapters\CallableMiddlewareAdapter;
use Ody\Foundation\Middleware\Attributes\Middleware;
use Ody\Foundation\Middleware\Attributes\MiddlewareGroup;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;
use Throwable;

class MiddlewareResolver
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
     * @var array Raw global middleware definitions.
     */
    protected array $global = [];

    /**
     * @var array<string, array> Raw route-specific middleware definitions (routeKey => [definitions...]).
     */
    protected array $routes = [];

    /**
     * @var array<string, mixed> Named middleware alias => definition map.
     */
    protected array $named = [];

    /**
     * @var array<string, array> Group name => [definitions...] map.
     */
    protected array $groups = [];

    /**
     * @var array<string, MiddlewareInterface> Cache for resolved middleware instances (cacheKey => instance).
     */
    protected array $resolved = [];

    /**
     * @var array<class-string, array> Cache for attribute middleware configurations from classes.
     */
    protected array $controllerCache = [];

    /**
     * @var array<class-string, array> Cache for combined attribute middleware for a handler class.
     */
    protected array $methodCache = []; // TODO: Might be misnamed, seems to cache combined class+method attribute middleware configs.

    /**
     * @var bool Whether to collect cache hit statistics.
     */
    protected bool $collectStats;

    /**
     * @var array<string, int> Cache hit statistics.
     */
    protected array $cacheHits = [];

    /**
     * @var array<class-string, array{class: ReflectionClass, constructor: ?ReflectionMethod, parameters: ReflectionParameter[]}> Cache for reflection data.
     */
    protected array $reflectionCache = [];

    /**
     * @param Container $container
     * @param LoggerInterface|null $logger
     * @param bool $collectStats
     */
    public function __construct(
        Container        $container,
        ?LoggerInterface $logger = null,
        bool             $collectStats = false
    )
    {
        $this->container = $container;
        $this->logger = $logger ?? new NullLogger();
        $this->collectStats = $collectStats;
    }

    /**
     * Add a raw middleware definition for a specific route.
     *
     * @param string $method HTTP method.
     * @param string $path Route path.
     * @param mixed $middleware The middleware definition (string, callable, instance, MiddlewareConfig).
     * @return $this
     */
    public function addForRoute(string $method, string $path, $middleware): self
    {
        $routeKey = $this->formatRouteKey($method, $path);
        // Ensure the key exists and is an array before appending
        if (!isset($this->routes[$routeKey]) || !is_array($this->routes[$routeKey])) {
            $this->routes[$routeKey] = [];
        }

        $this->routes[$routeKey][] = $middleware;

        return $this;
    }

    /**
     * Format a route key string.
     *
     * @param string $method HTTP method.
     * @param string $path Route path.
     * @return string Formatted route key (e.g., "GET:/users").
     */
    protected function formatRouteKey(string $method, string $path): string
    {
        return strtoupper($method) . ':' . $path;
    }

    /**
     * Get all expanded and unique middleware definitions for a route,
     * including global, route-specific, and handler attributes.
     *
     * @param string $method HTTP method.
     * @param string $path Route path.
     * @param RequestHandlerInterface $handler The matched handler instance.
     * @return array Final, expanded list of unique middleware definitions.
     */
    public function getMiddlewareForRoute(
        string                  $method,
        string                  $path,
        RequestHandlerInterface $handler
    ): array
    {
        $rawDefinitions = $this->global;

        $routeKey = $this->formatRouteKey($method, $path);
        if (isset($this->routes[$routeKey])) {
            $routeMiddleware = is_array($this->routes[$routeKey]) ? $this->routes[$routeKey] : [$this->routes[$routeKey]];
            $rawDefinitions = array_merge($rawDefinitions, $routeMiddleware);
        }

        $attributeMiddlewareConfigs = $this->getMiddleware($handler); // Gets combined class/method configs
        $attributeMiddlewareDefinitions = $this->convertAttributeConfigsToDefinitions($attributeMiddlewareConfigs);
        $rawDefinitions = array_merge($rawDefinitions, $attributeMiddlewareDefinitions);

        $visitedGroups = []; // For loop detection
        $expandedList = $this->expandMiddleware($rawDefinitions, $visitedGroups);

        $uniqueMiddleware = [];
        $seenKeys = [];
        foreach ($expandedList as $def) {
            try {
                // Use the existing cache key logic for uniqueness check
                $key = $this->getCacheKey($def);
                if (!isset($seenKeys[$key])) {
                    $uniqueMiddleware[] = $def;
                    $seenKeys[$key] = true;
                }
            } catch (Throwable $e) {
                // Log error if cache key generation fails for some reason
                $type = is_object($def) ? get_class($def) : gettype($def);
                $this->logger->error("Error generating cache key for middleware definition during uniqueness check. Skipping.", [
                    'definition_type' => $type,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $uniqueMiddleware;
    }

    /**
     * Expand middleware references (resolve named middleware and groups recursively),
     * preventing infinite loops.
     *
     * @param array $middleware List of middleware definitions (can contain names/groups).
     * @param array<string, bool> $visitedGroups Tracks visited groups in the current recursion path to prevent loops. Passed by reference.
     * @return array Expanded list containing only concrete definitions (class strings, callables, instances, configs).
     */
    protected function expandMiddleware(array $middleware, array &$visitedGroups = []): array
    {
        $result = [];

        foreach ($middleware as $item) {
            if (is_string($item)) {
                // Check if it's a named middleware alias
                if (isset($this->named[$item])) {
                    // Add the definition associated with the name
                    $result[] = $this->named[$item];
                    continue;
                }

                // Check if it's a middleware group name
                if (isset($this->groups[$item]) && is_array($this->groups[$item])) {
                    // --- Loop Detection ---
                    if (isset($visitedGroups[$item])) {
                        // Loop detected! Log and skip this group expansion.
                        $this->logger->warning("Middleware group expansion loop detected involving '{$item}'. Skipping recursive expansion here.");
                        continue; // Skip this group to prevent infinite loop
                    }
                    // --- End Loop Detection ---

                    // Mark group as visited for this path
                    $visitedGroups[$item] = true;

                    // Recursively expand the group and merge results
                    $expanded = $this->expandMiddleware($this->groups[$item], $visitedGroups); // Pass $visitedGroups by reference
                    $result = array_merge($result, $expanded);

                    unset($visitedGroups[$item]);

                    continue;
                }
            }

            $result[] = $item;
        }

        return array_values($result);
    }

    /**
     * Get combined middleware configurations from class and method attributes.
     * Caches the result.
     *
     * @param RequestHandlerInterface $handler Handler instance.
     * @return array Combined list of middleware configurations from attributes.
     */
    public function getMiddleware(RequestHandlerInterface $handler): array
    {
        $handlerClass = get_class($handler);

        if (isset($this->methodCache[$handlerClass])) {
            if ($this->collectStats) $this->cacheHits["attr:{$handlerClass}"] = ($this->cacheHits["attr:{$handlerClass}"] ?? 0) + 1;
            return $this->methodCache[$handlerClass];
        }

        $handlerMiddleware = $this->getHandlerMiddleware($handlerClass);

        $this->methodCache[$handlerClass] = $handlerMiddleware;

        return $handlerMiddleware;
    }


    /**
     * Get middleware configurations defined via attributes on the handler class.
     * Uses caching.
     *
     * @param class-string $handlerClass The fully qualified class name.
     * @return array List of middleware configurations from class attributes.
     */
    protected function getHandlerMiddleware(string $handlerClass): array
    {
        // Return from cache if available.
        if (isset($this->controllerCache[$handlerClass])) {
            if ($this->collectStats) $this->cacheHits["class_attr:{$handlerClass}"] = ($this->cacheHits["class_attr:{$handlerClass}"] ?? 0) + 1;
            return $this->controllerCache[$handlerClass];
        }

        if (!class_exists($handlerClass)) {
            $this->logger->warning("Handler class not found when checking attributes: {$handlerClass}");
            $this->controllerCache[$handlerClass] = []; // Cache empty result
            return [];
        }

        $middlewareConfigs = [];
        try {
            // Use reflection cache if available, otherwise create and store
            if (!isset($this->reflectionCache[$handlerClass])) {
                $reflectionClass = new ReflectionClass($handlerClass);
                $constructor = $reflectionClass->getConstructor();
                $this->reflectionCache[$handlerClass] = [
                    'class' => $reflectionClass,
                    'constructor' => $constructor,
                    'parameters' => $constructor ? $constructor->getParameters() : []
                ];
                if ($this->collectStats) $this->cacheHits["reflect_miss:{$handlerClass}"] = ($this->cacheHits["reflect_miss:{$handlerClass}"] ?? 0) + 1;
            } else {
                $reflectionClass = $this->reflectionCache[$handlerClass]['class'];
                if ($this->collectStats) $this->cacheHits["reflect_hit:{$handlerClass}"] = ($this->cacheHits["reflect_hit:{$handlerClass}"] ?? 0) + 1;
            }


            // Process #[Middleware] attributes
            $middlewareAttributes = $reflectionClass->getAttributes(Middleware::class);
            foreach ($middlewareAttributes as $attribute) {
                /** @var Middleware $instance */
                $instance = $attribute->newInstance();
                $middlewareDefs = $instance->getMiddleware();
                $parameters = $instance->getParameters();

                $defs = is_array($middlewareDefs) ? $middlewareDefs : [$middlewareDefs];

                // Create config for each class specified in the attribute
                foreach ($defs as $def) {
                    if (is_string($def)) {
                        $middlewareConfigs[] = [
                            'class' => $def,
                            'parameters' => $parameters
                        ];
                    }
                }
            }

            // Process #[MiddlewareGroup] attributes
            $groupAttributes = $reflectionClass->getAttributes(MiddlewareGroup::class);
            foreach ($groupAttributes as $attribute) {
                /** @var MiddlewareGroup $instance */
                $instance = $attribute->newInstance();
                $middlewareConfigs[] = [
                    'group' => $instance->getGroupName(),
                    'parameters' => []
                ];
            }

        } catch (Throwable $e) {
            $this->logger->error("Error reflecting or processing controller attributes: {$e->getMessage()}", [
                'controller' => $handlerClass,
                'exception' => $e
            ]);

            // Return empty on error to avoid partial results
            $middlewareConfigs = [];
        }

        // Cache the result (even if empty or error occurred)
        $this->controllerCache[$handlerClass] = $middlewareConfigs;

        return $middlewareConfigs;
    }


    /**
     * Helper to convert AttributeMiddlewareConfig[] used internally
     * into standard MiddlewareDefinition formats (class string, MiddlewareConfig, group name string).
     *
     * @param array $configs Array format from getHandlerMiddleware.
     * @return array List of standard middleware definitions.
     */
    protected function convertAttributeConfigsToDefinitions(array $configs): array
    {
        $definitions = [];
        foreach ($configs as $config) {
            // Single class defined in attribute
            if (isset($config['class']) && is_string($config['class'])) {
                if (!empty($config['parameters']) && is_array($config['parameters'])) {
                    // Class with parameters -> MiddlewareConfig
                    $definitions[] = new MiddlewareConfig($config['class'], $config['parameters']);
                } else {
                    // Class without parameters -> class string
                    $definitions[] = $config['class'];
                }
            } // Group name defined in attribute -> group name string
            elseif (isset($config['group']) && is_string($config['group'])) {
                $definitions[] = $config['group'];
            }
            // Potentially add handling for ['class' => ['ClassA', 'ClassB']] if needed,
            // though the current getHandlerMiddleware structure seems to create separate entries.
        }
        return $definitions;
    }


    /**
     * Resolve a middleware definition to an instance of MiddlewareInterface.
     * Handles caching.
     *
     * @param mixed $middleware The definition to resolve.
     * @return MiddlewareInterface The resolved middleware instance.
     * @throws RuntimeException If middleware cannot be resolved.
     */
    public function resolve(mixed $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        $cacheKey = $this->getCacheKey($middleware);

        if (isset($this->resolved[$cacheKey])) {
            if ($this->collectStats) {
                $this->cacheHits["resolve:{$cacheKey}"] = ($this->cacheHits["resolve:{$cacheKey}"] ?? 0) + 1;
            }
            return $this->resolved[$cacheKey];
        }

        try {
            $instance = $this->resolveMiddleware($middleware);

            $this->resolved[$cacheKey] = $instance;

            return $instance;
        } catch (Throwable $e) {
            $middlewareIdentifier = is_string($middleware) ? $middleware : (is_object($middleware) ? get_class($middleware) : gettype($middleware));
            $this->logger->error('Failed to resolve middleware', [
                'middleware' => $middlewareIdentifier,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new RuntimeException(
                "Failed to resolve middleware '{$middlewareIdentifier}': " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }


    /**
     * Get a unique cache key for a middleware definition.
     *
     * @param mixed $middleware The middleware definition.
     * @return string The generated cache key.
     */
    protected function getCacheKey(mixed $middleware): string
    {
        if (is_string($middleware)) {
            return 'str:' . $middleware;
        }

        if (is_array($middleware) && count($middleware) === 2 && isset($middleware[0], $middleware[1]) && is_string($middleware[1])) {
            if (is_object($middleware[0])) {
                return 'call_obj:' . get_class($middleware[0]) . '::' . $middleware[1];
            } elseif (is_string($middleware[0]) && (class_exists($middleware[0]) || interface_exists($middleware[0]))) {
                return 'call_static:' . $middleware[0] . '::' . $middleware[1];
            }
        }

        if ($middleware instanceof MiddlewareConfig) {
            return 'config:' . $middleware->getClass() . ':' . md5(serialize($middleware->getParameters()));
        }

        if (is_object($middleware)) {
            // spl_object_hash is unique per instance *within a request/worker context*
            return 'obj:' . get_class($middleware) . ':' . spl_object_hash($middleware);
        }

        if (is_array($middleware)) {
            return 'arr:' . md5(serialize($middleware));
        }

        return 'other:' . gettype($middleware) . ':' . md5(serialize($middleware));
    }


    /**
     * Resolve middleware from various definition formats into a MiddlewareInterface instance.
     *
     * @param mixed $middleware The middleware definition.
     * @return MiddlewareInterface The resolved middleware instance.
     * @throws RuntimeException If middleware cannot be resolved or is invalid.
     */
    protected function resolveMiddleware(mixed $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareConfig) {
            $class = $middleware->getClass();
            $parameters = $middleware->getParameters();

            try {
                $instance = $this->createInstanceWithParameters($class, $parameters);
            } catch (ReflectionException $e) {
                throw new RuntimeException("Reflection error creating instance of '$class': " . $e->getMessage(), 0, $e);
            } catch (Throwable $e) {
                throw new RuntimeException("Error creating instance of '$class': " . $e->getMessage(), 0, $e);
            }


            if ($instance instanceof MiddlewareInterface) {
                return $instance;
            }

            if (is_callable($instance)) {
                $this->logger->debug("Resolved '$class' via MiddlewareConfig resulted in a callable, wrapping with adapter.");
                return new CallableMiddlewareAdapter($instance);
            }

            throw new RuntimeException(
                "Middleware class '$class' resolved via MiddlewareConfig must implement MiddlewareInterface or be callable."
            );
        }

        if (is_string($middleware) && (class_exists($middleware) || interface_exists($middleware))) { // Added interface_exists check
            try {
                // Prefer container resolution for dependency injection.
                if ($this->container->has($middleware)) {
                    $instance = $this->container->make($middleware);
                } else {
                    // Fallback to reflection-based instantiation.
                    $this->logger->debug("Middleware class/interface '$middleware' not found in container, attempting reflection instantiation.");
                    $instance = $this->createInstanceWithReflection($middleware);
                }
            } catch (ReflectionException $e) {
                throw new RuntimeException("Reflection error resolving middleware class/interface '$middleware': " . $e->getMessage(), 0, $e);
            } catch (Throwable $e) {
                // Catch potential container errors or other instantiation issues.
                throw new RuntimeException("Error resolving middleware class/interface '$middleware': " . $e->getMessage(), 0, $e);
            }

            if ($instance instanceof MiddlewareInterface) {
                return $instance;
            }

            if (is_callable($instance)) {
                $this->logger->debug("Resolved class/interface '$middleware' resulted in a callable, wrapping with adapter.");
                return new CallableMiddlewareAdapter($instance);
            }

            throw new RuntimeException(
                "Middleware class/interface '$middleware' must resolve to an instance of MiddlewareInterface or a callable."
            );
        }

        if (is_callable($middleware)) {
            return new CallableMiddlewareAdapter($middleware);
        }

        $type = is_object($middleware) ? get_class($middleware) : gettype($middleware);
        throw new RuntimeException(
            "Invalid middleware definition provided. Expected class name/interface, MiddlewareInterface instance, callable, or MiddlewareConfig. Got: " . $type
        );
    }


    /**
     * Create an instance of a class, attempting to use provided parameters
     * and falling back to container/reflection for remaining dependencies.
     *
     * @param string $className
     * @param array $parameters Explicit parameters to use for constructor arguments by name.
     * @return object
     * @throws ReflectionException
     * @throws RuntimeException If a required parameter cannot be resolved.
     */
    protected function createInstanceWithParameters(string $className, array $parameters): object
    {
        // Use reflection cache
        if (!isset($this->reflectionCache[$className])) {
            if (!class_exists($className)) { // Ensure class exists before reflecting
                throw new RuntimeException("Class $className not found for instantiation.");
            }
            $reflectionClass = new ReflectionClass($className);
            if (!$reflectionClass->isInstantiable()) {
                throw new RuntimeException("Class $className is not instantiable (e.g., abstract).");
            }
            $constructor = $reflectionClass->getConstructor();
            $this->reflectionCache[$className] = [
                'class' => $reflectionClass,
                'constructor' => $constructor,
                'parameters' => $constructor ? $constructor->getParameters() : []
            ];
            if ($this->collectStats) $this->cacheHits["reflect_create_miss:{$className}"] = ($this->cacheHits["reflect_create_miss:{$className}"] ?? 0) + 1;
        } else {
            if ($this->collectStats) $this->cacheHits["reflect_create_hit:{$className}"] = ($this->cacheHits["reflect_create_hit:{$className}"] ?? 0) + 1;
        }


        $reflectionClass = $this->reflectionCache[$className]['class'];
        $constructor = $this->reflectionCache[$className]['constructor'];
        $constructorParams = $this->reflectionCache[$className]['parameters'];

        if ($constructor === null) {
            // Cannot pass parameters if there's no constructor
            if (!empty($parameters)) {
                $this->logger->warning("Attempted to pass parameters to class '{$className}' which has no constructor.");
            }
            return $reflectionClass->newInstance(); // No args needed
        }

        $resolvedArgs = [];
        foreach ($constructorParams as $param) {
            $paramName = $param->getName();
            $resolved = false;

            if (array_key_exists($paramName, $parameters)) {
                $resolvedArgs[] = $parameters[$paramName];
                $resolved = true;
                continue;
            }

            $paramType = $param->getType();
            if ($paramType instanceof \ReflectionNamedType && !$paramType->isBuiltin()) {
                $typeName = $paramType->getName();
                if ($this->container->has($typeName)) {
                    try {
                        $resolvedArgs[] = $this->container->make($typeName);
                        $resolved = true;
                        continue;
                    } catch (Throwable $e) {
                        $this->logger->debug("Container failed to resolve type '{$typeName}' for parameter '{$paramName}' in '{$className}': " . $e->getMessage());
                    }
                }
            }

            if ($param->isOptional()) {
                try {
                    $resolvedArgs[] = $param->getDefaultValue();
                    $resolved = true;
                    continue;
                } catch (ReflectionException $e) {
                    // This can happen for e.g. internal constants in default values
                    // If it's nullable, null is a valid default resolution here.
                    if ($param->allowsNull()) {
                        $resolvedArgs[] = null;
                        $resolved = true;
                        continue;
                    }

                    throw new RuntimeException("Could not get default value for optional parameter '{$paramName}' in class {$className}: " . $e->getMessage(), 0, $e);
                }
            }

            if ($param->allowsNull()) {
                $resolvedArgs[] = null;
                $resolved = true;
                continue;
            }

            if (!$resolved) {
                $typeHint = $paramType ? $paramType->getName() : 'unknown type';
                throw new RuntimeException("Cannot resolve required constructor parameter '{$paramName}' of type '{$typeHint}' for class {$className}. Parameter not provided explicitly, not found in container, not optional, and not nullable.");
            }
        }

        return $reflectionClass->newInstanceArgs($resolvedArgs);
    }


    /**
     * Create an instance using reflection, resolving constructor dependencies solely via the container or defaults.
     *
     * @param string $className
     * @return object
     * @throws ReflectionException
     */
    protected function createInstanceWithReflection(string $className): object
    {
        return $this->createInstanceWithParameters($className, []);
    }


    /**
     * Clear reflection caches to potentially free memory, e.g., in long-running processes.
     *
     * @return void
     */
    public function clearReflectionCache(): void
    {
        $this->reflectionCache = [];
        $this->logger->debug("Reflection cache cleared.");
    }


    /**
     * Load middleware configuration from an array structure.
     *
     * @param array $config The configuration array (e.g., from `config/middleware.php`).
     * @return $this
     */
    public function fromConfig(array $config): self
    {
        // Register named middleware
        if (isset($config['named']) && is_array($config['named'])) {
            $processedNamed = [];
            foreach ($config['named'] as $name => $middleware) {
                try {
                    $processedNamed[$name] = $this->processSingleMiddlewareConfig($middleware);
                } catch (RuntimeException $e) {
                    $this->logger->error("Invalid named middleware definition for '{$name}'. Skipping.", ['error' => $e->getMessage()]);
                }
            }
            $this->registerNamedMiddleware($processedNamed);
        }

        // Register groups
        if (isset($config['groups']) && is_array($config['groups'])) {
            $this->registerGroupedMiddleware($config['groups']); // Handles internal processing
        }

        // Register global middleware
        if (isset($config['global']) && is_array($config['global'])) {
            try {
                $processedGlobals = $this->processMiddlewareConfig($config['global']);
                $this->global = array_merge($this->global, $processedGlobals); // Append processed globals
            } catch (RuntimeException $e) {
                $this->logger->error("Invalid global middleware definition encountered. Some globals may not be registered.", ['error' => $e->getMessage()]);
            }
        }

        return $this;
    }

    /**
     * Process a single middleware definition from config, converting array formats
     * to MiddlewareConfig if necessary. Returns a standard definition type.
     *
     * @param mixed $middleware The raw definition from config.
     * @return callable|MiddlewareConfig|MiddlewareInterface|string A standard definition type.
     * @throws RuntimeException If the format is invalid.
     */
    protected function processSingleMiddlewareConfig($middleware): callable|MiddlewareInterface|string|MiddlewareConfig
    {
        // Format: ['class' => ClassName::class, 'parameters' => [...]]
        if (is_array($middleware) && isset($middleware['class']) && is_string($middleware['class'])) {
            $middlewareClass = $middleware['class'];
            $parameters = $middleware['parameters'] ?? [];
            if (!is_array($parameters)) {
                $this->logger->warning("Invalid 'parameters' format for middleware '{$middlewareClass}'. Expected array, got " . gettype($parameters) . ". Using empty array.", ['parameters' => $parameters]);
                $parameters = []; // Default to empty array on invalid format
            }
            return new MiddlewareConfig($middlewareClass, $parameters);
        } // Format: [ClassName::class, [...parameters]]
        elseif (is_array($middleware) && count($middleware) === 2 && is_string($middleware[0]) && (class_exists($middleware[0]) || interface_exists($middleware[0])) && is_array($middleware[1])) {
            $middlewareClass = $middleware[0];
            $parameters = $middleware[1];
            return new MiddlewareConfig($middlewareClass, $parameters);
        } // Already in a valid format (string, callable, instance, MiddlewareConfig)
        elseif (is_string($middleware) || is_callable($middleware) || $middleware instanceof MiddlewareInterface || $middleware instanceof MiddlewareConfig) {
            return $middleware;
        } else {
            // Log invalid format and throw exception
            $type = is_object($middleware) ? get_class($middleware) : gettype($middleware);
            throw new RuntimeException("Invalid middleware definition format encountered in configuration: type '{$type}'");
        }
    }


    /**
     * Registers named middleware definitions.
     *
     * @param array<string, mixed> $nameMiddleware Map of alias => processed definition.
     * @return void
     */
    public function registerNamedMiddleware(array $nameMiddleware): void
    {
        foreach ($nameMiddleware as $name => $middleware) {
            $this->named($name, $middleware);
        }
    }


    /**
     * Register a named middleware alias.
     *
     * @param string $name The alias for the middleware.
     * @param mixed $middleware The processed middleware definition.
     * @return $this
     */
    public function named(string $name, mixed $middleware): self
    {
        $this->named[$name] = $middleware;
        return $this;
    }


    /**
     * Registers grouped middleware definitions.
     * Processes potential configuration arrays within the groups.
     *
     * @param array<string, array> $groupedMiddleware Group name => list of raw definitions.
     * @return void
     */
    private function registerGroupedMiddleware(array $groupedMiddleware): void
    {
        foreach ($groupedMiddleware as $name => $middlewareList) {
            if (is_array($middlewareList)) {
                try {
                    // Process the list to handle potential config arrays
                    $processed = $this->processMiddlewareConfig($middlewareList);
                    $this->group($name, $processed);
                } catch (RuntimeException $e) {
                    $this->logger->error("Invalid middleware definition in group '{$name}'. Skipping group registration.", ['error' => $e->getMessage()]);
                }
            } else {
                $this->logger->warning("Invalid format for middleware group '{$name}'. Expected an array/list.");
            }
        }
    }


    /**
     * Process a list of middleware definitions from config, converting array configurations
     * into MiddlewareConfig objects or returning other valid types.
     *
     * @param array $middlewareList List of raw definitions from config.
     * @return array List of standard middleware definitions.
     * @throws RuntimeException If any definition has an invalid format.
     */
    protected function processMiddlewareConfig(array $middlewareList): array
    {
        $result = [];
        foreach ($middlewareList as $middleware) {
            $result[] = $this->processSingleMiddlewareConfig($middleware);
        }

        return array_values($result);
    }


    /**
     * Register a middleware group.
     *
     * @param string $name The name/alias for the group.
     * @param array $middlewareList List of processed middleware definitions for the group.
     * @return $this
     */
    public function group(string $name, array $middlewareList): self
    {
        $this->groups[$name] = $middlewareList;
        return $this;
    }


    /**
     * Register a global middleware definition.
     * The definition should be processed before calling this.
     *
     * @param mixed $middleware The processed middleware definition to add globally.
     * @return $this
     */
    public function global($middleware): self
    {
        $this->global[] = $middleware;
        return $this;
    }
}