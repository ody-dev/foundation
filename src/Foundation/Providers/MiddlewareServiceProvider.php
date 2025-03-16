<?php
namespace Ody\Foundation\Providers;

use Ody\Container\Container;
use Ody\Foundation\Middleware\AuthMiddleware;
use Ody\Foundation\Middleware\CorsMiddleware;
use Ody\Foundation\Middleware\JsonBodyParserMiddleware;
use Ody\Foundation\Middleware\LoggingMiddleware;
use Ody\Foundation\Middleware\MiddlewareRegistry;
use Ody\Foundation\Middleware\RoleMiddleware;
use Ody\Foundation\Middleware\ThrottleMiddleware;
use Ody\Support\Config;
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
        AuthMiddleware::class => null,
        RoleMiddleware::class => null,
        ThrottleMiddleware::class => null
    ];

    /**
     * Tags for organizing services
     *
     * @var array
     */
    protected array $tags = [
        'middleware' => [
            MiddlewareRegistry::class,
            AuthMiddleware::class,
            RoleMiddleware::class,
            ThrottleMiddleware::class
        ]
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

        // Register middleware classes
        $this->singleton(AuthMiddleware::class, function ($container) {
            $logger = $container->make(LoggerInterface::class);
            return new AuthMiddleware('web', $logger);
        });

        $this->singleton(RoleMiddleware::class, function ($container) {
            $logger = $container->make(LoggerInterface::class);
            return new RoleMiddleware('user', $logger);
        });

        $this->singleton(ThrottleMiddleware::class, function () {
            return new ThrottleMiddleware(60, 1);
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

        // Register named middleware
        $this->registerNamedMiddleware($registry, $this->container);

        // Register global middleware from configuration
        $this->registerGlobalMiddleware($registry, $this->container, $config);

        // Register middleware groups
        $this->registerMiddlewareGroups($registry, $config);
    }

    /**
     * Register named middleware for use in routes
     *
     * @param MiddlewareRegistry $registry
     * @param Container $container
     * @return void
     */
    protected function registerNamedMiddleware(MiddlewareRegistry $registry, Container $container): void
    {
        // Get middleware from container (they were registered as singletons)
        $registry->add('auth', $container->make(AuthMiddleware::class));
//        $registry->add('auth:api', new Authenticate('api', $container->make(LoggerInterface::class)));
        $registry->add('auth:jwt', new AuthMiddleware('jwt', $container->make(LoggerInterface::class)));

        $registry->add('role', $container->make(RoleMiddleware::class));
        $registry->add('role:admin', new RoleMiddleware('admin', $container->make(LoggerInterface::class)));
        $registry->add('role:user', new RoleMiddleware('user', $container->make(LoggerInterface::class)));

        $registry->add('throttle', $container->make(ThrottleMiddleware::class));
        $registry->add('throttle:60,1', new ThrottleMiddleware(60, 1));
        $registry->add('throttle:1000,60', new ThrottleMiddleware(1000, 60));

        $registry->add('cors', $container->make(CorsMiddleware::class));
        $registry->add('json', $container->make(JsonBodyParserMiddleware::class));
        $registry->add('log', $container->make(LoggingMiddleware::class));

        // Register middleware defined in config
        $namedMiddleware = config('app.middleware.named', []);

        foreach ($namedMiddleware as $name => $class) {
            // Skip if already registered
            if ($registry->has($name)) {
                continue;
            }

            // If class exists and container has it, register the middleware
            if (class_exists($class) && $container->has($class)) {
                $registry->add($name, $container->make($class));
            }
            // Otherwise register the class name for later resolution
            else {
                $registry->add($name, $class);
            }
        }
    }

    /**
     * Register global middleware from configuration
     *
     * @param MiddlewareRegistry $registry
     * @param Container $container
     * @param Config $config
     * @return void
     */
    protected function registerGlobalMiddleware(MiddlewareRegistry $registry, Container $container, Config $config): void
    {
        // Get global middleware from configuration
        $globalMiddleware = $config->get('app.middleware.global', []);

        // Register each middleware class
        foreach ($globalMiddleware as $middlewareClass) {
            // If container has the middleware, add it directly
            if ($container->has($middlewareClass)) {
                $registry->addGlobal($container->make($middlewareClass));
            }
            // Otherwise add the class name for later resolution
            else {
                $registry->addGlobal($middlewareClass);
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