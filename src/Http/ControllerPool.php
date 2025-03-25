<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Http;

use Ody\Container\Container;
use Ody\Container\Contracts\BindingResolutionException;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;

/**
 * Controller Pool
 *
 * An optimized implementation for managing controller instances in Swoole.
 * This implementation avoids serialization by storing controller instances
 * directly in worker memory.
 */
class ControllerPool
{
    /**
     * Enable or disable controller caching globally
     * @var bool
     */
    private static bool $enableCaching = true;

    /**
     * Controller classes that should be excluded from caching
     * @var array
     */
    private static array $excludedControllers = [];

    /**
     * Cached controller instances (stored in worker memory)
     * @var array
     */
    private static array $instances = [];

    /**
     * Cached dependency information (stored in worker memory)
     * @var array
     */
    private static array $dependencyInfo = [];

    /**
     * Get a controller instance, either from cache or newly created
     *
     * @param string $class Fully qualified class name
     * @param Container $container DI container
     * @return object Controller instance
     * @throws ReflectionException If controller instantiation fails
     */
    public static function get(string $class, Container $container): object
    {
        if (!self::shouldCache($class)) {
            return self::createInstance($class, $container);
        }

        if (isset(self::$instances[$class])) {
            logger()->debug("ControllerPool: Using cached instance of {$class}");
            return self::$instances[$class];
        }

        $instance = self::createInstance($class, $container);

        self::$instances[$class] = $instance;

        return $instance;
    }

    /**
     * Determine if a controller class should be cached
     *
     * @param string $class
     * @return bool
     */
    private static function shouldCache(string $class): bool
    {
        return self::$enableCaching && !in_array($class, self::$excludedControllers);
    }

    /**
     * Create a new controller instance with resolved dependencies
     *
     * @param string $class
     * @param Container $container
     * @return object
     * @throws ReflectionException|BindingResolutionException
     */
    private static function createInstance(string $class, Container $container): object
    {
        $dependencies = self::getDependencyInfo($class);

        if (empty($dependencies)) {
            return new $class();
        }

        // Resolve dependencies
        $parameters = [];
        $logger = $container->has(LoggerInterface::class) ? $container->make(LoggerInterface::class) : null;

        foreach ($dependencies as $paramInfo) {
            // For typed parameters that aren't built-in types
            if ($paramInfo['hasType'] && !$paramInfo['isBuiltin']) {
                $typeName = $paramInfo['type'];

                // Try to resolve from container
                try {
                    $parameters[] = $container->make($typeName);
                    continue;
                } catch (\Throwable $e) {
                    if ($logger) {
                        $logger->debug("Failed to resolve {$typeName} from container: {$e->getMessage()}");
                    }
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
        $reflectionClass = new ReflectionClass($class);
        return $reflectionClass->newInstanceArgs($parameters);
    }

    /**
     * Get or analyze constructor dependency information for a class
     *
     * @param string $class
     * @return array Dependency information
     */
    private static function getDependencyInfo(string $class): array
    {
        // Return cached info if available
        if (isset(self::$dependencyInfo[$class])) {
            return self::$dependencyInfo[$class];
        }

        try {
            $dependencies = [];
            $reflectionClass = new ReflectionClass($class);
            $constructor = $reflectionClass->getConstructor();

            // If no constructor, no dependencies
            if ($constructor === null) {
                self::$dependencyInfo[$class] = [];
                return [];
            }

            // Analyze each constructor parameter
            foreach ($constructor->getParameters() as $param) {
                $paramInfo = [
                    'name' => $param->getName(),
                    'optional' => $param->isOptional(),
                    'position' => $param->getPosition(),
                ];

                // Get type information if available
                if ($param->getType()) {
                    $paramInfo['hasType'] = true;
                    $paramInfo['type'] = $param->getType()->getName();
                    $paramInfo['isBuiltin'] = $param->getType()->isBuiltin();
                } else {
                    $paramInfo['hasType'] = false;
                }

                // Get default value if available
                if ($param->isOptional()) {
                    $paramInfo['hasDefault'] = true;
                    try {
                        $paramInfo['defaultValue'] = $param->getDefaultValue();
                    } catch (\Throwable $e) {
                        $paramInfo['defaultValue'] = null;
                    }
                }

                $dependencies[] = $paramInfo;
            }

            // Cache the dependency information
            self::$dependencyInfo[$class] = $dependencies;

            return $dependencies;

        } catch (\Throwable $e) {
            logger()->error("Error analyzing controller dependencies", [
                'controller' => $class,
                'error' => $e->getMessage()
            ]);
            // If analysis fails, return empty array and don't cache
            return [];
        }
    }

    /**
     * Clear all cached instances and dependency information
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$instances = [];
        self::$dependencyInfo = [];
        logger()->debug("ControllerPool: Cache cleared");
    }

    /**
     * Remove a specific controller from the cache
     *
     * @param string $class
     * @return void
     */
    public static function removeFromCache(string $class): void
    {
        unset(self::$instances[$class]);
        logger()->debug("ControllerPool: Removed {$class} from cache");
    }

    /**
     * Disable controller caching globally
     *
     * @return void
     */
    public static function disableCaching(): void
    {
        self::$enableCaching = false;
        logger()->debug("ControllerPool: Caching disabled globally");
    }

    /**
     * Enable controller caching globally
     *
     * @return void
     */
    public static function enableCaching(): void
    {
        self::$enableCaching = true;
        logger()->debug("ControllerPool: Caching enabled globally");
    }

    /**
     * Add a controller class to the exclusion list
     *
     * @param string $controllerClass
     * @return void
     */
    public static function excludeController(string $controllerClass): void
    {
        if (!in_array($controllerClass, self::$excludedControllers)) {
            self::$excludedControllers[] = $controllerClass;
            logger()->debug("ControllerPool: Excluded {$controllerClass} from caching");
        }
    }

    /**
     * Add multiple controller classes to the exclusion list
     *
     * @param array $controllerClasses
     * @return void
     */
    public static function excludeControllers(array $controllerClasses): void
    {
        foreach ($controllerClasses as $class) {
            self::excludeController($class);
        }
    }

    /**
     * Get the currently cached controllers (for diagnostic purposes)
     *
     * @return array
     */
    public static function getCachedControllers(): array
    {
        return array_keys(self::$instances);
    }
}