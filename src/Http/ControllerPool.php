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
     * Get a controller instance, either from cache or newly created
     */
    public static function get(string $class, Container $container): object
    {
        // Check if caching is disabled globally or for this controller
        if (!self::$enableCaching || in_array($class, self::$excludedControllers)) {
            // Skip cache and create a new instance
            return self::createInstance($class, $container);
        }

        // Check if already cached
        if (ControllerCache::has($class)) {
            return ControllerCache::get($class);
        }

        // Create a new instance
        $instance = self::createInstance($class, $container);

        // Cache the instance
        ControllerCache::set($class, $instance);

        return $instance;
    }

    /**
     * Create a new controller instance
     */
    private static function createInstance(string $class, Container $container): object
    {
        // Get dependency info from cache
        $dependencyCache = $container->make(ControllerDependencyCache::class);
        $dependencies = $dependencyCache->has($class)
            ? $dependencyCache->get($class)
            : $dependencyCache->analyze($class);

        // No dependencies, create directly
        if (empty($dependencies)) {
            logger()->debug("{$class} has no constructor or no parameters, creating directly");
            return new $class();
        }

        // Resolve dependencies
        $parameters = [];
        foreach ($dependencies as $paramInfo) {
            // For typed parameters that aren't built-in types
            if ($paramInfo['hasType'] && !$paramInfo['isBuiltin']) {
                $typeName = $paramInfo['type'];

                // Try to resolve from container
                try {
                    $parameters[] = $container->make($typeName);
                    continue;
                } catch (\Throwable $e) {
                    logger()->debug("Failed to resolve {$typeName} from container: {$e->getMessage()}");
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
    }

    /**
     * Disable controller caching globally
     */
    public static function disableCaching(): void
    {
        self::$enableCaching = false;
    }

    /**
     * Enable controller caching globally
     */
    public static function enableCaching(): void
    {
        self::$enableCaching = true;
    }

    /**
     * Add a controller class to the exclusion list
     */
    public static function excludeController(string $controllerClass): void
    {
        if (!in_array($controllerClass, self::$excludedControllers)) {
            self::$excludedControllers[] = $controllerClass;
        }
    }

    /**
     * Add multiple controller classes to the exclusion list
     */
    public static function excludeControllers(array $controllerClasses): void
    {
        foreach ($controllerClasses as $class) {
            self::excludeController($class);
        }
    }
}