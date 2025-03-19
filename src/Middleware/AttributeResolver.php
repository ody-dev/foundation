<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware;

use Ody\Foundation\Middleware\Attributes\Middleware;
use Ody\Foundation\Middleware\Attributes\MiddlewareGroup;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

/**
 * Middleware Attribute Resolver
 *
 * Resolves middleware attributes for controllers and methods
 */
class AttributeResolver
{
    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var array Cache of resolved middleware for controllers
     */
    protected array $controllerCache = [];

    /**
     * @var array Cache of resolved middleware for methods
     */
    protected array $methodCache = [];

    /**
     * Constructor
     *
     * @param LoggerInterface|null $logger
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Get middleware for a controller and method
     *
     * @param string|object $controller Controller class or instance
     * @param string $method Method name
     * @return array Combined middleware list for the controller and method
     */
    public function getMiddleware($controller, string $method): array
    {
        // Get the controller class name
        $controllerClass = is_object($controller) ? get_class($controller) : $controller;

        // Create a unique key for the controller method
        $methodKey = $controllerClass . '@' . $method;

        // Return from cache if available
        if (isset($this->methodCache[$methodKey])) {
            return $this->methodCache[$methodKey];
        }

        // Get controller-level middleware
        $controllerMiddleware = $this->getControllerMiddleware($controllerClass);

        // Get method-level middleware
        $methodMiddleware = $this->getMethodMiddleware($controllerClass, $method);

        // Combine the middleware lists (method-level middleware takes precedence)
        $combinedMiddleware = array_merge($controllerMiddleware, $methodMiddleware);

        // Cache the result
        $this->methodCache[$methodKey] = $combinedMiddleware;

        $this->logger->debug("Resolved middleware for {$methodKey}", [
            'count' => count($combinedMiddleware)
        ]);

        return $combinedMiddleware;
    }

    /**
     * Get middleware defined at the controller class level
     *
     * @param string $controllerClass
     * @return array
     */
    protected function getControllerMiddleware(string $controllerClass): array
    {
        // Return from cache if available
        if (isset($this->controllerCache[$controllerClass])) {
            return $this->controllerCache[$controllerClass];
        }

        // Check if class exists
        if (!class_exists($controllerClass)) {
            $this->logger->warning("Controller class not found: {$controllerClass}");
            return [];
        }

        $middleware = [];

        try {
            $reflectionClass = new ReflectionClass($controllerClass);

            // Get middleware attributes
            $middlewareAttributes = $reflectionClass->getAttributes(Middleware::class);
            foreach ($middlewareAttributes as $attribute) {
                $instance = $attribute->newInstance();
                $middlewareClass = $instance->getMiddleware();
                $parameters = $instance->getParameters();

                if (is_array($middlewareClass)) {
                    // Handle array of middleware
                    foreach ($middlewareClass as $mw) {
                        $middleware[] = [
                            'class' => $mw,
                            'parameters' => $parameters
                        ];
                    }
                } else {
                    // Handle single middleware
                    $middleware[] = [
                        'class' => $middlewareClass,
                        'parameters' => $parameters
                    ];
                }
            }

            // Get middleware group attributes
            $groupAttributes = $reflectionClass->getAttributes(MiddlewareGroup::class);
            foreach ($groupAttributes as $attribute) {
                $instance = $attribute->newInstance();
                $middleware[] = [
                    'group' => $instance->getGroupName(),
                    'parameters' => []
                ];
            }

        } catch (\Throwable $e) {
            $this->logger->error("Error resolving controller middleware: {$e->getMessage()}", [
                'controller' => $controllerClass,
                'error' => $e->getMessage()
            ]);
        }

        // Cache the result
        $this->controllerCache[$controllerClass] = $middleware;

        return $middleware;
    }

    /**
     * Get middleware defined at the method level
     *
     * @param string $controllerClass
     * @param string $method
     * @return array
     */
    protected function getMethodMiddleware(string $controllerClass, string $method): array
    {
        // Check if class and method exist
        if (!class_exists($controllerClass) || !method_exists($controllerClass, $method)) {
            return [];
        }

        $middleware = [];

        try {
            $reflectionMethod = new ReflectionMethod($controllerClass, $method);

            // Get middleware attributes
            $middlewareAttributes = $reflectionMethod->getAttributes(Middleware::class);
            foreach ($middlewareAttributes as $attribute) {
                $instance = $attribute->newInstance();
                $middlewareClass = $instance->getMiddleware();
                $parameters = $instance->getParameters();

                if (is_array($middlewareClass)) {
                    // Handle array of middleware
                    foreach ($middlewareClass as $mw) {
                        $middleware[] = [
                            'class' => $mw,
                            'parameters' => $parameters
                        ];
                    }
                } else {
                    // Handle single middleware
                    $middleware[] = [
                        'class' => $middlewareClass,
                        'parameters' => $parameters
                    ];
                }
            }

            // Get middleware group attributes
            $groupAttributes = $reflectionMethod->getAttributes(MiddlewareGroup::class);
            foreach ($groupAttributes as $attribute) {
                $instance = $attribute->newInstance();
                $middleware[] = [
                    'group' => $instance->getGroupName(),
                    'parameters' => []
                ];
            }

        } catch (\Throwable $e) {
            $this->logger->error("Error resolving method middleware: {$e->getMessage()}", [
                'controller' => $controllerClass,
                'method' => $method,
                'error' => $e->getMessage()
            ]);
        }

        return $middleware;
    }

    /**
     * Clear the middleware cache
     */
    public function clearCache(): void
    {
        $this->controllerCache = [];
        $this->methodCache = [];
    }
}