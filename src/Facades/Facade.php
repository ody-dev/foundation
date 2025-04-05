<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Facades;

use Ody\Container\Container;

/**
 * Base facade class
 */
abstract class Facade
{
    /**
     * The container instance
     *
     * @var Container|null
     */
    protected static $container = null;

    /**
     * The resolved object instances
     *
     * @var array
     */
    protected static $resolvedInstances = [];

    /**
     * Handle dynamic, static calls to the object.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public static function __callStatic($method, $args)
    {
        $instance = static::getFacadeRoot();

        if (!$instance) {
            throw new \RuntimeException('A facade root has not been set.');
        }

        return $instance->{$method}(...$args);
    }

    /**
     * Get the container
     *
     * @return Container
     */
    public static function getFacadeContainer()
    {
        if (is_null(static::$container)) {
            static::$container = Container::getInstance();
        }

        return static::$container;
    }

    /**
     * Set the container
     *
     * @param Container $container
     * @return void
     */
    public static function setFacadeContainer(Container $container)
    {
        static::$container = $container;
    }

    /**
     * Get the root object
     *
     * @return mixed
     */
    public static function getFacadeRoot()
    {
        return static::resolveFacadeInstance(static::getFacadeAccessor());
    }

    /**
     * Resolve the facade root instance
     *
     * @param string|object $name
     * @return mixed
     */
    protected static function resolveFacadeInstance($name)
    {
        if (is_object($name)) {
            return $name;
        }

        if (isset(static::$resolvedInstances[$name])) {
            return static::$resolvedInstances[$name];
        }

        $container = static::getFacadeContainer();

        // Replace the abstract with a concrete instance
        static::$resolvedInstances[$name] = $container->make($name);

        return static::$resolvedInstances[$name];
    }

    /**
     * Clear all resolved instances
     *
     * @return void
     */
    public static function clearResolvedInstances()
    {
        static::$resolvedInstances = [];
    }

    /**
     * Get the facade accessor
     *
     * @return string
     * @throws \RuntimeException
     */
    protected static function getFacadeAccessor()
    {
        throw new \RuntimeException('Facade does not implement getFacadeAccessor method.');
    }
}