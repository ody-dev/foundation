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
 * ParameterizedMiddlewareDecorator
 *
 * Decorates a PSR-15 middleware with parameters that are added to the request attributes.
 * This allows middleware to receive parameters without requiring constructor arguments.
 */
class ParameterizedMiddlewareDecorator implements MiddlewareInterface
{
    /**
     * @var MiddlewareInterface The decorated middleware
     */
    private MiddlewareInterface $middleware;

    /**
     * @var array Parameters to pass to the middleware
     */
    private array $parameters;

    /**
     * @var string The prefix for attribute names
     */
    private string $attributePrefix;

    /**
     * Constructor
     *
     * @param MiddlewareInterface $middleware The middleware to decorate
     * @param array $parameters Parameters to pass to the middleware
     * @param string $attributePrefix Prefix for request attribute names
     */
    public function __construct(
        MiddlewareInterface $middleware,
        array $parameters = [],
        string $attributePrefix = 'middleware_'
    ) {
        $this->middleware = $middleware;
        $this->parameters = $parameters;
        $this->attributePrefix = $attributePrefix;
    }

    /**
     * Process the request through the middleware
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Add parameters to request attributes
        foreach ($this->parameters as $key => $value) {
            $request = $request->withAttribute("{$this->attributePrefix}{$key}", $value);
        }

        // Process with the decorated middleware
        return $this->middleware->process($request, $handler);
    }

    /**
     * Get the decorated middleware
     *
     * @return MiddlewareInterface
     */
    public function getMiddleware(): MiddlewareInterface
    {
        return $this->middleware;
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