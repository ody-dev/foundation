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

/**
 * RequestHandler
 *
 * Handles processing a request through a stack of middleware.
 */
class RequestHandler implements RequestHandlerInterface
{
    /**
     * @var callable The final handler to process the request if no middleware handles it
     */
    private $finalHandler;

    /**
     * @var array The middleware stack
     */
    private array $stack = [];

    /**
     * @var bool Whether the stack is locked (execution has started)
     */
    private bool $locked = false;

    /**
     * Constructor
     *
     * @param callable $finalHandler The handler to invoke if no middleware handles the request
     */
    public function __construct(callable $finalHandler)
    {
        $this->finalHandler = $finalHandler;
    }

    /**
     * Add middleware to the stack
     *
     * @param MiddlewareInterface $middleware
     * @return self
     * @throws \RuntimeException if the stack is locked (execution has started)
     */
    public function add(MiddlewareInterface $middleware): self
    {
        if ($this->locked) {
            throw new \RuntimeException('Cannot add middleware once the stack is executing');
        }

        $this->stack[] = $middleware;
        return $this;
    }

    /**
     * Add multiple middleware at once
     *
     * @param array $middlewareList
     * @return self
     */
    public function addMultiple(array $middlewareList): self
    {
        foreach ($middlewareList as $middleware) {
            $this->add($middleware);
        }

        return $this;
    }

    /**
     * Process the request through the middleware stack
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Mark the stack as locked so no more middleware can be added during execution
        $this->locked = true;

        // If there's no middleware, execute the final handler directly
        if (empty($this->stack)) {
            return call_user_func($this->finalHandler, $request);
        }

        // Get the next middleware from the stack
        $middleware = array_shift($this->stack);

        // Process the request through the middleware
        return $middleware->process($request, $this);
    }

    /**
     * Get the final handler
     *
     * @return callable
     */
    public function getFinalHandler(): callable
    {
        return $this->finalHandler;
    }

    /**
     * Get the current middleware stack
     *
     * @return array
     */
    public function getStack(): array
    {
        return $this->stack;
    }
}