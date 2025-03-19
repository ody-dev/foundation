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
use Ody\Foundation\Middleware\AttributeResolver;
use Ody\Foundation\MiddlewareManager;
use Ody\Foundation\Middleware\MiddlewarePipeline;
use Ody\Foundation\Middleware\MiddlewareRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Controller Dispatcher
 *
 * Dispatches requests to controllers, handling middleware and dependency injection
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
    protected ControllerResolver $controllerResolver;

    /**
     * @var AttributeResolver
     */
    protected AttributeResolver $attributeResolver;

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
     * @param ControllerResolver|null $controllerResolver
     * @param AttributeResolver|null $attributeResolver
     * @param MiddlewareManager|null $middlewareManager
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        Container $container,
        ?ControllerResolver $controllerResolver = null,
        ?AttributeResolver $attributeResolver = null,
        ?MiddlewareManager $middlewareManager = null,
        ?LoggerInterface $logger = null
    ) {
        $this->container = $container;
        $this->controllerResolver = $controllerResolver ?? new ControllerResolver($container, $logger);
        $this->attributeResolver = $attributeResolver ?? new AttributeResolver($logger);
        $this->middlewareManager = $middlewareManager ?? $container->make(MiddlewareManager::class);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Dispatch a request to a controller
     *
     * @param ServerRequestInterface $request
     * @param string $controllerClass
     * @param string $method
     * @param array $routeParams
     * @return ResponseInterface
     */
    public function dispatch(
        ServerRequestInterface $request,
        string $controllerClass,
        string $method,
        array $routeParams = []
    ): ResponseInterface {
        $this->logger->debug("Dispatching to controller: {$controllerClass}::{$method}");

        try {
            // Create the controller instance
            $controller = $this->controllerResolver->createController($controllerClass);

            // Create a route signature for middleware lookup
            $routeMethod = $request->getMethod();
            $routePath = $request->getUri()->getPath();

            // Get middleware from route and attributes
            $middlewareStack = $this->getMiddlewareStack(
                $routeMethod, $routePath, $controllerClass, $method
            );

            // Add route parameters to the request
            foreach ($routeParams as $name => $value) {
                $request = $request->withAttribute($name, $value);
            }

            // Create the final handler that will call the controller method
            $finalHandler = function (ServerRequestInterface $request) use ($controller, $method, $routeParams) {
                return $this->controllerResolver->callMethod(
                    $controller, $method, $request, $routeParams
                );
            };

            // Create a middleware pipeline
            $pipeline = $this->createMiddlewarePipeline($middlewareStack, $finalHandler);

            // Process the request through the middleware pipeline
            return $pipeline->handle($request);

        } catch (\Throwable $e) {
            $this->logger->error("Error dispatching to controller: {$e->getMessage()}", [
                'controller' => $controllerClass,
                'method' => $method,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Re-throw the exception to be handled by the error handler
            throw $e;
        }
    }

    /**
     * Get middleware stack for a controller method
     *
     * @param string $routeMethod HTTP method of the route
     * @param string $routePath Path of the route
     * @param string $controllerClass Controller class
     * @param string $method Controller method
     * @return array Middleware stack
     */
    protected function getMiddlewareStack(
        string $routeMethod,
        string $routePath,
        string $controllerClass,
        string $method
    ): array {
        // Get route-specific middleware
        $routeMiddleware = $this->middlewareManager->getStackForRoute($routeMethod, $routePath);

        // Get attribute-based middleware
        $attributeMiddleware = $this->attributeResolver->getMiddleware($controllerClass, $method);

        // Convert attribute middleware format to registry format
        $normalizedAttributeMiddleware = [];
        foreach ($attributeMiddleware as $middleware) {
            if (isset($middleware['class'])) {
                // For class-based middleware with parameters
                $middlewareClass = $middleware['class'];
                $parameters = $middleware['parameters'] ?? [];

                // If we have parameters, we need to create a closure that will pass them
                if (!empty($parameters)) {
                    $normalizedAttributeMiddleware[] = function (ServerRequestInterface $request, callable $next) use ($middlewareClass, $parameters) {
                        $middleware = $this->container->make($middlewareClass);

                        // Add parameters to the request
                        foreach ($parameters as $key => $value) {
                            $request = $request->withAttribute("middleware_{$key}", $value);
                        }

                        return $middleware->process($request, new CallableHandlerAdapter($next));
                    };
                } else {
                    $normalizedAttributeMiddleware[] = $middlewareClass;
                }
            } else if (isset($middleware['group'])) {
                // For group-based middleware
                $normalizedAttributeMiddleware[] = $middleware['group'];
            }
        }

        // Merge the middleware stacks (attribute middleware applied after route middleware)
        return array_merge($routeMiddleware, $normalizedAttributeMiddleware);
    }

    /**
     * Create a middleware pipeline
     *
     * @param array $middlewareStack
     * @param callable $finalHandler
     * @return MiddlewarePipeline
     */
    protected function createMiddlewarePipeline(array $middlewareStack, callable $finalHandler): MiddlewarePipeline
    {
        $registry = $this->middlewareManager->getRegistry();

        return new MiddlewarePipeline(
            $registry,
            $middlewareStack,
            $finalHandler,
            $this->logger
        );
    }
}