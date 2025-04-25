<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware;

class MiddlewareConfig
{
    /**
     * @var string The middleware class name
     */
    protected string $class;

    /**
     * @var array Parameters to pass to the middleware constructor
     */
    protected array $parameters;

    /**
     * Constructor
     *
     * @param string $class
     * @param array $parameters
     */
    public function __construct(string $class, array $parameters = [])
    {
        $this->class = $class;
        $this->parameters = $parameters;
    }

    /**
     * Get the middleware class name
     *
     * @return string
     */
    public function getClass(): string
    {
        return $this->class;
    }

    /**
     * Get the parameters
     *
     * @return array
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}