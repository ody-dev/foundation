<?php

namespace Ody\Foundation\Http;

use Swoole\Table;

class ControllerDependencyCache
{
    /**
     * @var Table|null Swoole table for persistent storage
     */
    private static ?Table $table = null;

    /**
     * Analyze and cache the constructor dependencies for a controller class
     */
    public function analyze(string $class): array
    {
        $controllerCacheEnabled = config('app.controller_cache.enabled');
        if ($controllerCacheEnabled) {
            // Return cached dependencies if already analyzed
            if (self::$table->exists($class)) {
                $data = self::$table->get($class, 'dependencies');
                $dependencies = unserialize($data);
                var_dump($dependencies);
                logger()->debug("ControllerDependencyCache::analyze() using cached dependencies: $class");
                return $dependencies;
            }

            logger()->debug("ControllerDependencyCache::analyze() no cached dependencies found: $class");
        }

        try {
            $dependencies = [];
            $reflectionClass = new \ReflectionClass($class);
            $constructor = $reflectionClass->getConstructor();

            // If no constructor, no dependencies
            if ($constructor === null) {
                self::$table->set($class, ['dependencies' => serialize([])]);
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

            if (config('app.controller_cache.enabled')) {
                // Cache the dependency information
                self::$table->set($class, ['dependencies' => serialize($dependencies)]);
            }

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
     * Get cached dependencies for a class
     */
    public function get(string $class): ?array
    {
        self::init();

        if (self::$table->exists($class)) {
            logger()->debug("ControllerDependencyCache::get() using cached controller dependencies");
            $data = self::$table->get($class, 'dependencies');
            return unserialize($data);
        }

        return null;
    }

    /**
     * Initialize the Swoole table
     */
    public static function init(): void
    {
        if (self::$table === null) {
            self::$table = new Table(1024);
            self::$table->column('dependencies', Table::TYPE_STRING, 8192); // For serialized dependency data
            self::$table->create();
        }
    }

    /**
     * Check if a class has cached dependencies
     */
    public function has(string $class): bool
    {
        self::init();
        return self::$table->exists($class);
    }

    /**
     * Clear the dependency cache
     */
    public function clear(): void
    {
        if (self::$table !== null) {
            foreach (self::$table as $key => $value) {
                self::$table->del($key);
            }
        }
    }
}