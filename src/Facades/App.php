<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Facades;

/**
 * App Facade
 *
 * @method static \Ody\Foundation\Router\Router getRouter()
 * @method static \Ody\Foundation\Middleware\Middleware getMiddleware()
 * @method static \Ody\Container getContainer()
 * @method static \Psr\Http\Message\ResponseInterface handleRequest(\Psr\Http\Message\ServerRequestInterface $request = null)
 * @method static void run()
 */
class App extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return \Ody\Foundation\Application::class;
    }
}