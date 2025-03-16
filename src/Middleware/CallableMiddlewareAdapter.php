<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ody\Foundation\Http\Request;
use Ody\Foundation\Http\Response;

/**
 * Adapter to convert callable middleware to PSR-15 middleware
 */
class CallableMiddlewareAdapter implements MiddlewareInterface
{
    /**
     * @var callable The middleware function
     */
    private $middleware;

    /**
     * CallableMiddlewareAdapter constructor
     *
     * @param callable $middleware
     */
    public function __construct(callable $middleware)
    {
        $this->middleware = $middleware;
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
        // Create the "next" handler
        $next = function (ServerRequestInterface $request) use ($handler): ResponseInterface {
            return $handler->handle($request);
        };

        // Call the middleware with PSR-7 request and the next handler
        $response = call_user_func($this->middleware, $request, $next);

        // If the middleware returned a response, use it
        if ($response instanceof ResponseInterface) {
            return $response;
        }

        // If the middleware didn't return a response, assume it called "next"
        return $handler->handle($request);
    }
}