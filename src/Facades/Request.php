<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Facades;

/**
 * Request Facade
 *
 * @method static string getMethod()
 * @method static \Psr\Http\Message\UriInterface getUri()
 * @method static string getUriString()
 * @method static string getPath()
 * @method static string rawContent()
 * @method static mixed json(bool $assoc = true)
 * @method static mixed input(string $key, mixed $default = null)
 * @method static array all()
 */
class Request extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'request';
    }

    /**
     * Create a request from globals
     *
     * This is a static method on the actual Request class
     *
     * @return \Ody\Foundation\Http\Request
     */
    public static function createFromGlobals()
    {
        return \Ody\Foundation\Http\Request::createFromGlobals();
    }
}