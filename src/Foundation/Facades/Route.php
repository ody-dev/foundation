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
 * Route Facade
 *
 * Allows route registration using static methods
 *
 * @method static \Ody\Foundation\Route get(string $path, mixed $handler)
 * @method static \Ody\Foundation\Route post(string $path, mixed $handler)
 * @method static \Ody\Foundation\Route put(string $path, mixed $handler)
 * @method static \Ody\Foundation\Route patch(string $path, mixed $handler)
 * @method static \Ody\Foundation\Route delete(string $path, mixed $handler)
 * @method static \Ody\Foundation\Route options(string $path, mixed $handler)
 * @method static \Ody\Foundation\Router group(array $attributes, callable $callback)
 */
class Route extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'router';
    }
}