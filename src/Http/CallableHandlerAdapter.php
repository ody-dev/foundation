<?php

namespace Ody\Foundation\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * CallableHandlerAdapter
 *
 * Adapts a callable to a PSR-15 RequestHandlerInterface
 */
class CallableHandlerAdapter implements \Psr\Http\Server\RequestHandlerInterface
{
    /**
     * @var callable
     */
    private $callable;

    /**
     * Constructor
     *
     * @param callable $callable
     */
    public function __construct(callable $callable)
    {
        $this->callable = $callable;
    }

    /**
     * Handle the request
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return call_user_func($this->callable, $request);
    }
}