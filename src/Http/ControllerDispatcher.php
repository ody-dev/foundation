<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

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
use Throwable;

/**
 * ControllerDispatcher
 *
 * Dispatches requests to controllers with middleware processing
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
     * @param LoggerInterface $logger
     */
    public function __construct(
        Container $container,
        ControllerResolver $resolver,
        MiddlewareManager $middlewareManager,
        LoggerInterface   $logger
    ) {
        $this->container = $container;
        $this->resolver = $resolver;
        $this->middlewareManager = $middlewareManager;
        $this->logger = $logger;
    }

    /**
     * Dispatch a request to a controller
     *
     * @param ServerRequestInterface $request
     * @param string $controller
     * @param string $action
     * @param array $routeParams
     * @return ResponseInterface
     * @throws Throwable
     */
    public function dispatch(
        ServerRequestInterface $request,
        string $controller,
        string $action,
        array $routeParams = []
    ): ResponseInterface {
        try {
            // Resolve the controller
            $controllerInstance = $this->resolver->createController($controller);

            // Get middleware for the controller and action
            $method = $request->getMethod();
            $path = $request->getUri()->getPath();

            // Get middleware for this controller/action
            $middlewareStack = $this->middlewareManager->getMiddlewareForRoute(
                $method,
                $path,
                $controller,
                $action
            );

            // Create a final handler for the controller action
            $finalHandler = function (ServerRequestInterface $request) use ($controllerInstance, $action, $routeParams) {
                // Create a response instance
                $response = $this->container->make(Response::class);

                // Add route parameters to the request
                foreach ($routeParams as $name => $value) {
                    $request = $request->withAttribute($name, $value);
                }

                // Call the controller action with request, response, and any route parameters
                return call_user_func([$controllerInstance, $action], $request, $response, $routeParams);
            };

            // Create a middleware pipeline from the resolved stack
            $pipeline = new MiddlewarePipeline($finalHandler);

            // Add each middleware to the pipeline
            foreach ($middlewareStack as $middleware) {
                try {
                    $instance = $this->middlewareManager->resolve($middleware);
                    $pipeline->add($instance);
                } catch (Throwable $e) {
                    $this->logger->error('Error resolving controller middleware', [
                        'middleware' => is_string($middleware) ? $middleware : gettype($middleware),
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Process the request through the middleware pipeline
            return $pipeline->handle($request);

        } catch (Throwable $e) {
            $this->logger->error("Controller dispatch error", [
                'controller' => $controller,
                'action' => $action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }
}