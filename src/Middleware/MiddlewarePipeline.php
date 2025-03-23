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

class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @var array Stack of middleware
     */
    protected array $middleware = [];

    /**
     * @var callable Final handler to execute if no middleware returns a response
     */
    protected $finalHandler;

    /**
     * Constructor
     *
     * @param callable $finalHandler Final handler to execute
     */
    public function __construct(callable $finalHandler)
    {
        $this->finalHandler = $finalHandler;
    }

    /**
     * Create a pipeline from an array of middleware
     *
     * @param array $middleware
     * @param callable $finalHandler
     * @return self
     */
    public static function fromArray(array $middleware, callable $finalHandler): self
    {
        $pipeline = new self($finalHandler);
        $pipeline->addMultiple($middleware);
        return $pipeline;
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
            if ($middleware instanceof MiddlewareInterface) {
                $this->add($middleware);
            }
        }
        return $this;
    }

    /**
     * Add middleware to the pipeline
     *
     * @param MiddlewareInterface $middleware
     * @return self
     */
    public function add(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;
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
        if (empty($this->middleware)) {
            return call_user_func($this->finalHandler, $request);
        }

        // Take the first middleware from the stack
        $middleware = array_shift($this->middleware);

        // Process the request through the middleware
        return $middleware->process($request, $this);
    }
}