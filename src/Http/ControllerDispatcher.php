<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Http;

use Ody\Container\Container;
use Ody\Foundation\Middleware\MiddlewarePipeline;
use Ody\Foundation\MiddlewareManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * ControllerDispatcher
 *
 * Handles dispatching requests to controller methods with middleware
 */
class ControllerDispatcher
{
    /**
     * @var Container
     */
    protected Container $container;

    /**
     * @var ControllerResolver
     */
    protected ControllerResolver $resolver;

    /**
     * @var MiddlewareManager
     */
    protected MiddlewareManager $middlewareManager;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param Container $container
     * @param ControllerResolver $resolver
     * @param MiddlewareManager $middlewareManager
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        Container $container,
        ControllerResolver $resolver,
        MiddlewareManager  $middlewareManager,
        ?LoggerInterface $logger = null
    ) {
        $this->container = $container;
        $this->resolver = $resolver;
        $this->middlewareManager = $middlewareManager;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Dispatch a request to a controller method
     *
     * @param ServerRequestInterface $request
     * @param string $controller Controller class name
     * @param string $action Controller method name
     * @param array $params Route parameters
     * @return ResponseInterface
     */
    public function dispatch(
        ServerRequestInterface $request,
        string $controller,
        string $action,
        array  $params = []
    ): ResponseInterface {
        $this->logger->debug("Dispatching to controller: {$controller}@{$action}");

        // Resolve the controller instance using createController method
        $instance = $this->resolver->createController($controller);

        // Add route parameters to the request attributes
        foreach ($params as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        // Build the middleware stack for this controller/action
        $middlewareStack = $this->middlewareManager->getMiddlewareForRoute(
            $request->getMethod(),
            $request->getUri()->getPath(),
            $controller,
            $action
        );

        // Create the final handler that invokes the controller method
        $finalHandler = function (ServerRequestInterface $request) use ($instance, $action, $params) {
            // Call the controller method using the resolver
            return $this->resolver->callMethod($instance, $action, $request, $params);
        };

        // Create a middleware pipeline
        $pipeline = new MiddlewarePipeline($finalHandler);

        // Add the middleware to the pipeline
        foreach ($middlewareStack as $middleware) {
            try {
                $resolvedMiddleware = $this->middlewareManager->resolve($middleware);
                $pipeline->add($resolvedMiddleware);
            } catch (\Throwable $e) {
                $this->logger->error("Failed to resolve middleware in controller dispatch", [
                    'middleware' => is_string($middleware) ? $middleware : gettype($middleware),
                    'controller' => $controller,
                    'action' => $action,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Execute the pipeline
        return $pipeline->handle($request);
    }
}