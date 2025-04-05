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
 * Response Facade
 *
 * @method static \Ody\Foundation\Http\Response status(int $statusCode)
 * @method static \Ody\Foundation\Http\Response header(string $name, string $value)
 * @method static \Ody\Foundation\Http\Response contentType(string $contentType)
 * @method static \Ody\Foundation\Http\Response json()
 * @method static \Ody\Foundation\Http\Response text()
 * @method static \Ody\Foundation\Http\Response html()
 * @method static \Ody\Foundation\Http\Response body(string $content)
 * @method static \Ody\Foundation\Http\Response withJson(mixed $data, int $options = 0)
 */
class Response extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'response';
    }
}