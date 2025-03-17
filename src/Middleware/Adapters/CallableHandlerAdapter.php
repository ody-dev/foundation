<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware\Adapters;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CallableHandlerAdapter
 *
 * Adapts a callable to implement PSR-15 RequestHandlerInterface
 */
class CallableHandlerAdapter implements RequestHandlerInterface
{
    /**
     * @var callable The next handler function
     */
    private $handler;

    /**
     * Constructor
     *
     * @param callable $handler A handler with function(ServerRequestInterface $request): ResponseInterface signature
     */
    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    /**
     * Handle the request by calling the wrapped handler
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return call_user_func($this->handler, $request);
    }
}