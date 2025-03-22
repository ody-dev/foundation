<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Http;

use Ody\Container\Container;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;

/**
 * ControllerResolver
 *
 * Resolves string controller references to callable instances
 */
class ControllerResolver
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
     * @var array
     */
    private array $resolvedControllers = [];

    /**
     * Constructor
     *
     * @param Container $container
     * @param LoggerInterface $logger
     */
    public function __construct(Container $container, LoggerInterface $logger)
    {
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * Resolve a controller reference
     *
     * @param string|callable $controller Controller reference
     * @return array|callable
     * @throws \Exception
     */
    public function resolve($controller)
    {
        // Already a callable, return as-is
        if (is_callable($controller)) {
            return $controller;
        }

        // Handle "Controller@method" string format
        if (is_string($controller) && strpos($controller, '@') !== false) {
            list($class, $method) = explode('@', $controller, 2);

            // Create the controller instance
            $instance = $this->createController($class);

            // Check if the method exists
            if (!method_exists($instance, $method)) {
                throw new \RuntimeException("Method '{$method}' does not exist on controller '{$class}'");
            }

            // Return as callable array
            return [$instance, $method];
        }

        // Handle ["Controller", "method"] array format
        if (is_array($controller) && count($controller) === 2 && is_string($controller[0]) && is_string($controller[1])) {
            $class = $controller[0];
            $method = $controller[1];

            // Create the controller instance
            $instance = $this->createController($class);

            // Check if the method exists
            if (!method_exists($instance, $method)) {
                throw new \RuntimeException("Method '{$method}' does not exist on controller '{$class}'");
            }

            // Return as callable array
            return [$instance, $method];
        }

        // Handle already resolved [object, "method"] array format
        if (is_array($controller) && count($controller) === 2 && is_object($controller[0]) && is_string($controller[1])) {
            return $controller;
        }

        throw new \InvalidArgumentException("Unable to resolve controller: " . (is_string($controller) ? $controller : gettype($controller)));
    }

    /**
     * Create a controller instance with all dependencies resolved
     *
     * @param string $class Controller class name
     * @return object Controller instance
     * @throws \Exception If controller cannot be created
     */
    public function createController(string $class): object
    {
        try {
            // First check if the controller exists
            if (!class_exists($class)) {
                throw new \RuntimeException("Controller class '{$class}' does not exist");
            }

            $this->logger->debug("Attempting to create controller: {$class}");

            // First try to resolve from container directly
            if ($this->container->has($class)) {
                $this->logger->debug("Container has binding for {$class}, using container->make()");
                return $this->container->make($class);
            }

            // Use dependency cache to resolve constructor parameters
            $dependencyCache = $this->container->make(ControllerDependencyCache::class);
            $dependencies = $dependencyCache->has($class)
                ? $dependencyCache->get($class)
                : $dependencyCache->analyze($class);

            // No dependencies, create directly
            if (empty($dependencies)) {
                $this->logger->debug("{$class} has no constructor or no parameters, creating directly");
                return new $class();
            }

            // Resolve dependencies using cached information
            $parameters = [];
            foreach ($dependencies as $paramInfo) {
                // For typed parameters that aren't built-in types
                if ($paramInfo['hasType'] && !$paramInfo['isBuiltin']) {
                    $typeName = $paramInfo['type'];

                    // Try to resolve from container
                    try {
                        $parameters[] = $this->container->make($typeName);
                        continue;
                    } catch (\Throwable $e) {
                        $this->logger->debug("Failed to resolve {$typeName} from container: {$e->getMessage()}");
                    }
                }

                // Fall back to default value if available
                if ($paramInfo['optional']) {
                    $parameters[] = $paramInfo['defaultValue'] ?? null;
                } else {
                    // If required parameter can't be resolved, throw exception
                    throw new \RuntimeException(
                        "Required parameter '{$paramInfo['name']}' could not be resolved for {$class}"
                    );
                }
            }

            // Create instance with resolved parameters
            $reflectionClass = new \ReflectionClass($class);
            return $reflectionClass->newInstanceArgs($parameters);

        } catch (\Throwable $e) {
            $this->logger->error("Error creating controller", [
                'controller' => $class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException("Error creating controller: {$e->getMessage()}", 0, $e);
        }
    }

//    public function createController(string $class): object
//    {
//        try {
//            // Check global cache first
//            // TODO: rm Controller Cache and use the application cache
////            $controllerCache = $this->container->get(ControllerCache::class);
////            $controllerCache = ControllerCache
//            if (ControllerCache::has($class)) {
//                $this->logger->debug("Using cached controller instance for {$class}");
//                return ControllerCache::get($class);
//            }
//
//            // First check if the controller exists
//            if (!class_exists($class)) {
//                throw new \RuntimeException("Controller class '{$class}' does not exist");
//            }
//
//            // Log attempt
//            $this->logger->debug("Attempting to create controller: {$class}");
//
//            // First try to resolve using the container's make method
//            try {
//                // Does the container know about this class?
//                if ($this->container->has($class)) {
//                    $this->logger->debug("Container has binding for {$class}, using container->make()");
//                    return $this->container->make($class);
//                }
//
//                // Try to make the controller even if it's not explicitly bound
//                $this->logger->debug("No explicit binding for {$class}, trying container->make() anyway");
//                $instance = $this->container->make($class);
//                $this->logger->debug("Successfully created {$class} via container");
//
//                // Cache the controller instance
//                ControllerCache::set($class, $instance);
//
//                return $instance;
//            } catch (\Throwable $containerException) {
//                // Log container error
//                $this->logger->warning("Container failed to create {$class}: {$containerException->getMessage()}");
//                $this->logger->debug("Container error trace: {$containerException->getTraceAsString()}");
//
//                // Fall back to reflection-based instantiation with more debug info
//                $this->logger->debug("Falling back to reflection-based instantiation for {$class}");
//                return $this->createControllerWithReflection($class);
//            }
//        } catch (\Throwable $e) {
//            $this->logger->error("Error creating controller", [
//                'controller' => $class,
//                'error' => $e->getMessage(),
//                'trace' => $e->getTraceAsString()
//            ]);
//
//            throw new \RuntimeException("Error creating controller: {$e->getMessage()}", 0, $e);
//        }
//    }

    /**
     * Create a controller using reflection to resolve dependencies
     *
     * @param string $class Controller class name
     * @return object Controller instance
     * @throws ReflectionException
     */
    protected function createControllerWithReflection(string $class): object
    {
        $reflectionClass = new ReflectionClass($class);

        if (!$reflectionClass->isInstantiable()) {
            throw new \RuntimeException("Controller class '{$class}' is not instantiable");
        }

        $constructor = $reflectionClass->getConstructor();

        // If no constructor, we can just create a new instance
        if ($constructor === null) {
            $this->logger->debug("{$class} has no constructor, creating directly");
            return new $class();
        }

        // Get constructor parameters
        $parameters = $constructor->getParameters();

        if (empty($parameters)) {
            $this->logger->debug("{$class} constructor has no parameters, creating directly");
            return new $class();
        }

        // Log out the required dependencies
        $parameterDetails = [];
        foreach ($parameters as $param) {
            $type = $param->getType() ? $param->getType()->getName() : 'unknown';
            $parameterDetails[] = "{$param->getName()}: {$type}" .
                ($param->isOptional() ? ' (optional)' : ' (required)');
        }

        $this->logger->debug("Controller {$class} requires dependencies:", [
            'parameters' => $parameterDetails
        ]);

        // Check container bindings for each dependency
        foreach ($parameters as $param) {
            $typeName = $param->getType() ? $param->getType()->getName() : null;
            if ($typeName && $this->container->has($typeName)) {
                $this->logger->debug("Found container binding for {$typeName}");
            } elseif ($typeName) {
                $this->logger->warning("No container binding found for {$typeName}");
            }
        }

        // Resolve dependencies
        $dependencies = $this->resolveDependencies($parameters);

        // Create and return the controller instance
        $this->logger->debug("Creating {$class} with " . count($dependencies) . " resolved dependencies");
        return $reflectionClass->newInstanceArgs($dependencies);
    }

    /**
     * Resolve constructor dependencies
     *
     * @param ReflectionParameter[] $parameters
     * @return array
     * @throws \Exception
     */
    protected function resolveDependencies(array $parameters): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $dependencies[] = $this->resolveDependency($parameter);
        }

        return $dependencies;
    }

    /**
     * Resolve a single dependency
     *
     * @param ReflectionParameter $parameter
     * @return mixed
     * @throws \Exception
     */
    protected function resolveDependency(ReflectionParameter $parameter)
    {
        // If the parameter has a type, try to resolve it
        $type = $parameter->getType();

        if ($type && !$type->isBuiltin()) {
            $typeName = $type->getName();

            $this->logger->debug("Attempting to resolve dependency: {$parameter->getName()} of type {$typeName}");

            // Try to get from container
            if ($this->container->has($typeName)) {
                try {
                    $instance = $this->container->get($typeName);
                    $this->logger->debug("Successfully resolved {$typeName} from container");
                    return $instance;
                } catch (\Throwable $e) {
                    $this->logger->warning("Failed to get {$typeName} from container: {$e->getMessage()}");
                    // Continue to other resolution methods
                }
            }

            // Try to make the instance
            try {
                $instance = $this->container->make($typeName);
                $this->logger->debug("Successfully created {$typeName} via container->make()");
                return $instance;
            } catch (\Throwable $e) {
                $this->logger->warning("Failed to make {$typeName}: {$e->getMessage()}");
                // Continue to other resolution methods
            }

            // Check for service aliases
            $aliases = [
                'logger' => 'Psr\Log\LoggerInterface',
                'auth' => 'Ody\Auth\AuthManager',
                'auth.manager' => 'Ody\Auth\AuthManager',
                'app' => 'Ody\Foundation\Application',
                'config' => 'Ody\Support\Config',
                'db' => 'Ody\Database\Connection',
            ];

            // Try to resolve from known aliases
            foreach ($aliases as $alias => $aliasedClass) {
                if ($typeName === $aliasedClass && $this->container->has($alias)) {
                    try {
                        $instance = $this->container->get($alias);
                        $this->logger->debug("Resolved {$typeName} from alias '{$alias}'");
                        return $instance;
                    } catch (\Throwable $e) {
                        $this->logger->warning("Failed to get {$typeName} from alias '{$alias}': {$e->getMessage()}");
                    }
                }
            }

            // Last resort: Try to create the dependency directly (this can lead to unconfigured instances)
            if (class_exists($typeName)) {
                try {
                    $dependencyReflection = new ReflectionClass($typeName);
                    if ($dependencyReflection->isInstantiable()) {
                        $dependencyConstructor = $dependencyReflection->getConstructor();

                        // If the dependency has no constructor or has optional parameters
                        if ($dependencyConstructor === null || $dependencyConstructor->getNumberOfRequiredParameters() === 0) {
                            $this->logger->debug("Creating {$typeName} directly as a last resort");
                            return $dependencyReflection->newInstance();
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning("Failed to create {$typeName} directly: {$e->getMessage()}");
                }
            }
        }

        // If parameter is optional, return default value
        if ($parameter->isOptional()) {
            $this->logger->debug("Using default value for optional parameter {$parameter->getName()}");
            return $parameter->getDefaultValue();
        }

        // Failed to resolve the dependency
        throw new \RuntimeException(
            "Unable to resolve dependency: {$parameter->getName()}" .
            ($type ? " of type " . $type->getName() : "")
        );
    }

    /**
     * Get a descriptive name for a controller
     *
     * @param mixed $controller
     * @return string
     */
    public function getControllerName($controller): string
    {
        if (is_string($controller)) {
            return $controller;
        }

        if (is_array($controller)) {
            if (is_object($controller[0])) {
                return get_class($controller[0]) . '::' . $controller[1];
            }
            return $controller[0] . '::' . $controller[1];
        }

        if (is_object($controller)) {
            return get_class($controller);
        }

        return 'unknown';
    }
}