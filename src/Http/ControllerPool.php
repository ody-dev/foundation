<?php

namespace Ody\Foundation\Http;

use Ody\Container\Container;

class ControllerPool
{
    /**
     * Get a controller instance, either from cache or newly created
     */
    public static function get(string $class, Container $container): object
    {
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
}