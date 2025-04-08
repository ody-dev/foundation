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
     *
     * @var bool
     */
    private bool $enableCaching;

    /**
     * Controller classes that should be excluded from caching
     *
     * @var array
     */
    private array $excludedControllers = [];

    /**
     * Cached controller instances (stored in worker memory)
     *
     * @var array
     */
    private array $instances = [];

    /**
     * Cached dependency information (stored in worker memory)
     *
     * @var array
     */
    private array $dependencyInfo = [];

    public function __construct(
        private readonly Container       $container,
        private readonly LoggerInterface $logger,
        bool                             $enableCaching = true,
        array                            $excludedControllers = []
    )
    {
        $this->enableCaching = $enableCaching;
        $this->excludedControllers = $excludedControllers;
        $workerId = getmypid();
        $this->logger->debug("[Worker {$workerId}] ControllerPool instance created for worker.", [
            'cachingEnabled' => $this->enableCaching,
            'excludedCount' => count($this->excludedControllers)
        ]);
    }

    /**
     * Get a controller instance, either from cache or newly created
     *
     * @param string $class Fully qualified class name
     * @return object Controller instance
     * @throws BindingResolutionException If controller instantiation fails
     * @throws ReflectionException If controller instantiation fails
     */
    public function get(string $class): object
    {

        if (!$this->shouldCache($class)) {
            return $this->createInstance($class);
        }

        if (isset($this->instances[$class])) {
            $this->logger->debug("ControllerPool: Using cached instance of {$class}");
            return $this->instances[$class];
        }

        $instance = $this->createInstance($class);
        $this->instances[$class] = $instance;
        return $instance;
    }

    /**
     * Determine if a controller class should be cached
     *
     * @param string $class
     * @return bool
     */
    private function shouldCache(string $class): bool
    {
        return $this->enableCaching && !in_array($class, $this->excludedControllers);
    }

    /**
     * Create a new controller instance with resolved dependencies
     *
     * @param string $class
     * @return object
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    private function createInstance(string $class): object
    {
        $dependencies = $this->getDependencyInfo($class);

        if (empty($dependencies)) {
            return new $class();
        }

        // Resolve dependencies
        $parameters = [];
        $logger = $this->container->make(LoggerInterface::class);

        foreach ($dependencies as $paramInfo) {
            // For typed parameters that aren't built-in types
            if ($paramInfo['hasType'] && !$paramInfo['isBuiltin']) {
                $typeName = $paramInfo['type'];

                // Try to resolve from container
                try {
                    $parameters[] = $this->container->make($typeName);
                    continue;
                } catch (\Throwable $e) {
                    $this->logger->error("ControllerPool FAILED container->make for: {$typeName}", [
                        'error_message' => $e->getMessage(),
                        'error_file' => $e->getFile(),
                        'error_line' => $e->getLine(),
                        'error_trace' => $e->getTraceAsString()
                    ]);
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
    private function getDependencyInfo(string $class): array
    {
        // Return cached info if available
        if (isset($this->dependencyInfo[$class])) {
            return $this->dependencyInfo[$class];
        }

        try {
            $dependencies = [];
            $reflectionClass = new ReflectionClass($class);
            $constructor = $reflectionClass->getConstructor();

            // If no constructor, no dependencies
            if ($constructor === null) {
                $this->dependencyInfo[$class] = [];
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
            $this->dependencyInfo[$class] = $dependencies;

            return $dependencies;

        } catch (\Throwable $e) {
            $this->logger->error("Error analyzing controller dependencies", [
                'controller' => $class,
                'error' => $e->getMessage()
            ]);
            // If analysis fails, return empty array and don't cache
            return [];
        }
    }

    /**
     * Enable controller caching globally
     *
     * @return void
     */
    public function enableCaching(): void
    {
        $this->enableCaching = true;
    }

    /**
     * Clear all cached instances and dependency information
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->instances = [];
        $this->dependencyInfo = [];
        $this->logger->debug("ControllerPool: Cache cleared");
    }

    public function controllerIsCached(string $class)
    {
        return isset($this->instances[$class]);
    }

    /**
     * Add a controller class to the exclusion list
     *
     * @param string $controllerClass
     * @return void
     */
    public function excludeController(string $controllerClass): void
    {
        if (!in_array($controllerClass, $this->excludedControllers)) {
            $this->excludedControllers[] = $controllerClass;
            $this->logger->debug("ControllerPool: Excluded {$controllerClass} from caching");
        }
    }

    /**
     * @param bool $enabled
     * @return void
     */
    public function setCachingEnabled(bool $enabled): void
    {
        $this->enableCaching = $enabled;
    }

    /**
     * @param array $excluded
     * @return void
     */
    public function setExcludedControllers(array $excluded): void
    {
        $this->excludedControllers = $excluded;
    }
}