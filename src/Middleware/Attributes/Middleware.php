<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware\Attributes;

use Attribute;

/**
 * Middleware Attribute
 *
 * Use this attribute to define middleware for controllers and methods
 *
 * @Attribute
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Middleware
{
    /**
     * @var string|array The middleware class or list of middleware classes
     */
    protected $middleware;

    /**
     * @var array Optional parameters to pass to the middleware
     */
    protected array $parameters;

    /**
     * Constructor
     *
     * @param string|array $middleware The middleware class or list of middleware classes
     * @param array $parameters Optional parameters to pass to the middleware
     */
    public function __construct($middleware, array $parameters = [])
    {
        $this->middleware = $middleware;
        $this->parameters = $parameters;
    }

    /**
     * Get the middleware
     *
     * @return string|array
     */
    public function getMiddleware()
    {
        return $this->middleware;
    }

    /**
     * Get middleware parameters
     *
     * @return array
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}