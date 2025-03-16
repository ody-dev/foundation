<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Facades;

/**
 * Env Facade
 *
 * @method static void load(string $environment = null)
 */
class Env extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return \Ody\Support\Env::class;
    }

    /**
     * Get an environment variable
     *
     * This is a static method on the actual Env class,
     * so we'll call it directly
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        return \Ody\Support\Env::get($key, $default);
    }

    /**
     * Check if an environment variable exists
     *
     * This is a static method on the actual Env class,
     * so we'll call it directly
     *
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        return \Ody\Support\Env::has($key);
    }
}