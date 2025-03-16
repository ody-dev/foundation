<?php
namespace Ody\Foundation\Providers;

use Ody\Container\Container;
use Ody\Foundation\Middleware\MiddlewareRegistry;
use Ody\Foundation\Middleware\ParameterizedMiddlewareDecorator;
use Ody\Support\Config;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;

/**
 * Service provider for middleware
 */
class MiddlewareServiceProvider extends ServiceProvider
{
    /**
     * Services that should be registered as singletons
     *
     * @var array
     */
    protected array $singletons = [
        MiddlewareRegistry::class => null,
        ParameterizedMiddlewareDecorator::class => null,
    ];

    /**
     * Register custom services
     *
     * @return void
     */
    public function register(): void
    {
        // Register MiddlewareRegistry
        $this->singleton(MiddlewareRegistry::class, function ($container) {
            return new MiddlewareRegistry($container, $container->make(LoggerInterface::class));
        });
    }

    /**
     * Bootstrap middleware
     *
     * @return void
     */
    public function boot(): void
    {
        $registry = $this->make(MiddlewareRegistry::class);
        $config = $this->make(Config::class);
        $logger = $this->make(LoggerInterface::class);

        // Register named middleware
        $this->registerNamedMiddleware($registry, $config, $logger);

        // Register global middleware from configuration
        $this->registerGlobalMiddleware($registry, $config, $logger);

        // Register middleware groups
        $this->registerMiddlewareGroups($registry, $config);
    }

    /**
     * Register named middleware for use in routes
     *
     * @param MiddlewareRegistry $registry
     * @param Config $config
     * @param LoggerInterface $logger
     * @return void
     */
    protected function registerNamedMiddleware(
        MiddlewareRegistry $registry,
        Config $config,
        LoggerInterface $logger
    ): void {
        // Register middleware defined in config
        $namedMiddleware = $config->get('app.middleware.named', []);

        foreach ($namedMiddleware as $name => $middlewareDefinition) {
            try {
                // For simple class name middleware
                if (is_string($middlewareDefinition)) {
                    $registry->add($name, $middlewareDefinition);
                    $logger->debug("Registered named middleware: {$name} → {$middlewareDefinition}");
                }
                // For array configuration with class and parameters
                else if (is_array($middlewareDefinition) && isset($middlewareDefinition['class'])) {
                    $class = $middlewareDefinition['class'];
                    $parameters = $middlewareDefinition['parameters'] ?? [];

                    // Register with both class and parameters
                    $registry->add($name, [
                        'class' => $class,
                        'parameters' => $parameters
                    ]);

                    $logger->debug("Registered named middleware with parameters: {$name}");
                }
                else {
                    $logger->warning("Invalid middleware definition for '{$name}'", [
                        'definition' => $middlewareDefinition
                    ]);
                }
            } catch (\Throwable $e) {
                $logger->error("Failed to register middleware '{$name}'", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
    }

    /**
     * Register global middleware from configuration
     *
     * @param MiddlewareRegistry $registry
     * @param Config $config
     * @param LoggerInterface $logger
     * @return void
     */
    protected function registerGlobalMiddleware(
        MiddlewareRegistry $registry,
        Config $config,
        LoggerInterface $logger
    ): void {
        // Get global middleware from configuration
        $globalMiddleware = $config->get('app.middleware.global', []);

        // Register each middleware class
        foreach ($globalMiddleware as $middlewareDefinition) {
            try {
                // For simple class name middleware
                if (is_string($middlewareDefinition)) {
                    $registry->addGlobal($middlewareDefinition);
                    $logger->debug("Registered global middleware: {$middlewareDefinition}");
                }
                // For array configuration with class and parameters
                else if (is_array($middlewareDefinition) && isset($middlewareDefinition['class'])) {
                    $class = $middlewareDefinition['class'];
                    $parameters = $middlewareDefinition['parameters'] ?? [];

                    // Create a factory to instantiate with parameters
                    $factory = function() use ($class, $parameters, $logger) {
                        try {
                            // Instantiate the middleware
                            if ($this->container->has($class)) {
                                $middleware = $this->container->make($class);
                            } else {
                                $middleware = new $class();
                            }

                            // If there are parameters and it's a middleware, wrap it
                            if (!empty($parameters) && $middleware instanceof MiddlewareInterface) {
                                return new ParameterizedMiddlewareDecorator($middleware, $parameters);
                            }

                            return $middleware;
                        } catch (\Throwable $e) {
                            $logger->error("Failed to create middleware instance for {$class}", [
                                'error' => $e->getMessage(),
                                'parameters' => $parameters
                            ]);
                            throw $e;
                        }
                    };

                    $registry->addGlobal($factory);
                    $logger->debug("Registered global middleware with parameters: {$class}");
                }
                else {
                    $logger->warning("Invalid global middleware definition", [
                        'definition' => $middlewareDefinition
                    ]);
                }
            } catch (\Throwable $e) {
                $logger->error("Failed to register global middleware", [
                    'error' => $e->getMessage(),
                    'definition' => $middlewareDefinition
                ]);
            }
        }
    }

    /**
     * Register middleware groups from configuration
     *
     * @param MiddlewareRegistry $registry
     * @param Config $config
     * @return void
     */
    protected function registerMiddlewareGroups(MiddlewareRegistry $registry, Config $config): void
    {
        // Get middleware groups from configuration
        $middlewareGroups = $config->get('app.middleware.groups', []);

        // Register each group
        foreach ($middlewareGroups as $name => $middleware) {
            $registry->addGroup($name, $middleware);
        }
    }
}