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
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CallableMiddlewareAdapter
 *
 * Adapts a callable to implement the PSR-15 MiddlewareInterface.
 */
class CallableMiddlewareAdapter implements MiddlewareInterface
{
    /**
     * @var callable The middleware function to be wrapped
     */
    private $middleware;

    /**
     * @var array Additional parameters to use when invoking the middleware
     */
    private array $parameters;

    /**
     * Constructor
     *
     * @param callable $middleware The middleware function with signature function(ServerRequestInterface $request, callable $next): ResponseInterface
     * @param array $parameters Additional parameters to pass to the middleware
     */
    public function __construct(callable $middleware, array $parameters = [])
    {
        $this->middleware = $middleware;
        $this->parameters = $parameters;
    }

    /**
     * Process an incoming server request
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Create a next handler function that passes the request to the handler
        $next = function (ServerRequestInterface $request) use ($handler): ResponseInterface {
            return $handler->handle($request);
        };

        // Call the middleware with the request, next handler, and any additional parameters
        if (empty($this->parameters)) {
            return call_user_func($this->middleware, $request, $next);
        } else {
            return call_user_func($this->middleware, $request, $next, $this->parameters);
        }
    }

    /**
     * Create a new instance from a callable
     *
     * @param callable $middleware
     * @param array $parameters
     * @return self
     */
    public static function fromCallable(callable $middleware, array $parameters = []): self
    {
        return new self($middleware, $parameters);
    }
}