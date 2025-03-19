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
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Controller Resolver
 *
 * Resolves controllers and their actions, handling dependency injection
 */
class ControllerResolver
{
    /**
     * @var Container
     */
    protected Container $container;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param Container $container
     * @param LoggerInterface|null $logger
     */
    public function __construct(Container $container, ?LoggerInterface $logger = null)
    {
        $this->container = $container;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Create a controller instance
     *
     * @param string $controllerClass
     * @return object
     * @throws \RuntimeException If controller cannot be resolved
     */
    public function createController(string $controllerClass)
    {
        try {
            // Check if class exists
            if (!class_exists($controllerClass)) {
                throw new \RuntimeException("Controller class not found: {$controllerClass}");
            }

            // Create from container
            if ($this->container->has($controllerClass)) {
                return $this->container->make($controllerClass);
            }

            // Create directly if not available in container
            return new $controllerClass();

        } catch (\Throwable $e) {
            $this->logger->error("Error creating controller: {$e->getMessage()}", [
                'controller' => $controllerClass,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException("Failed to create controller: {$controllerClass}", 0, $e);
        }
    }

    /**
     * Call a controller method with dependency injection
     *
     * @param object $controller Controller instance
     * @param string $method Method name
     * @param ServerRequestInterface $request
     * @param array $routeParams Route parameters
     * @return mixed Method return value
     * @throws \RuntimeException If method cannot be called
     */
    public function callMethod($controller, string $method, ServerRequestInterface $request, array $routeParams = [])
    {
        try {
            $controllerClass = get_class($controller);

            // Check if method exists
            if (!method_exists($controller, $method)) {
                throw new \RuntimeException("Method not found: {$controllerClass}::{$method}");
            }

            // Use reflection to analyze the method
            $reflectionMethod = new ReflectionMethod($controller, $method);
            $methodParams = $reflectionMethod->getParameters();
            $resolvedParams = [];

            // Create a default response if needed for controller methods that expect it
            $response = $this->container->has(ResponseInterface::class)
                ? $this->container->make(ResponseInterface::class)
                : new Response();

            // Check if method is expecting a response parameter
            $needsResponse = false;
            foreach ($methodParams as $param) {
                if ($this->isResponseParameter($param)) {
                    $needsResponse = true;
                    break;
                }
            }

            // Prepare parameters
            foreach ($methodParams as $param) {
                // Handle ServerRequestInterface parameter
                if ($this->isRequestParameter($param)) {
                    $resolvedParams[] = $request;
                    continue;
                }

                // Handle ResponseInterface parameter
                if ($this->isResponseParameter($param)) {
                    $resolvedParams[] = $response;
                    continue;
                }

                // Handle array of route parameters
                if ($param->getName() === 'params' && $param->getType() && $param->getType()->getName() === 'array') {
                    $resolvedParams[] = $routeParams;
                    continue;
                }

                // Try to resolve from route parameters
                if (isset($routeParams[$param->getName()])) {
                    $resolvedParams[] = $routeParams[$param->getName()];
                    continue;
                }

                // Try to resolve from container by type
                if ($param->getType() && !$param->getType()->isBuiltin()) {
                    $typeName = $param->getType()->getName();
                    if ($this->container->has($typeName)) {
                        $resolvedParams[] = $this->container->make($typeName);
                        continue;
                    }
                }

                // Use default value if available
                if ($param->isOptional()) {
                    $resolvedParams[] = $param->getDefaultValue();
                    continue;
                }

                // If we get here, we couldn't resolve the parameter
                throw new \RuntimeException("Could not resolve parameter '{$param->getName()}' for method {$controllerClass}::{$method}");
            }

            // Call the method with the resolved parameters
            return $reflectionMethod->invokeArgs($controller, $resolvedParams);

        } catch (\Throwable $e) {
            $this->logger->error("Error calling controller method: {$e->getMessage()}", [
                'controller' => get_class($controller),
                'method' => $method,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException("Failed to call controller method: {$method}", 0, $e);
        }
    }

    /**
     * Check if a parameter is a PSR-7 request
     *
     * @param ReflectionParameter $param
     * @return bool
     */
    private function isRequestParameter(ReflectionParameter $param): bool
    {
        return $param->getType()
            && ($param->getType()->getName() === ServerRequestInterface::class
                || is_subclass_of($param->getType()->getName(), ServerRequestInterface::class));
    }

    /**
     * Check if a parameter is a PSR-7 response
     *
     * @param ReflectionParameter $param
     * @return bool
     */
    private function isResponseParameter(ReflectionParameter $param): bool
    {
        return $param->getType()
            && ($param->getType()->getName() === ResponseInterface::class
                || is_subclass_of($param->getType()->getName(), ResponseInterface::class));
    }
}