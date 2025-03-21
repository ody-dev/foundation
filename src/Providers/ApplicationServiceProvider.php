<?php
namespace Ody\Foundation\Providers;

use Nyholm\Psr7\Factory\Psr17Factory;
use Ody\Foundation\Application;
use Ody\Foundation\Middleware\CorsMiddleware;
use Ody\Foundation\Middleware\JsonBodyParserMiddleware;
use Ody\Foundation\Middleware\LoggingMiddleware;
use Ody\Foundation\MiddlewareManager;
use Ody\Foundation\Router\Router;
use Ody\Support\Config;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service provider for core application services
 */
class ApplicationServiceProvider extends ServiceProvider
{
    /**
     * Services that should be registered as singletons
     *
     * @var array
     */
    protected array $singletons = [
        Application::class => null,
        Router::class => null,
        Psr17Factory::class => null,
        ServerRequestFactoryInterface::class => Psr17Factory::class,
        ResponseFactoryInterface::class => Psr17Factory::class,
        StreamFactoryInterface::class => Psr17Factory::class,
        UploadedFileFactoryInterface::class => Psr17Factory::class,
        UriFactoryInterface::class => Psr17Factory::class,
        CorsMiddleware::class => null,
        JsonBodyParserMiddleware::class => null,
        LoggingMiddleware::class => null
    ];

    /**
     * Tags for organizing services
     *
     * @var array
     */
    protected array $tags = [
        'psr7' => [
            Psr17Factory::class,
            ServerRequestFactoryInterface::class,
            ResponseFactoryInterface::class,
            StreamFactoryInterface::class,
            UploadedFileFactoryInterface::class,
            UriFactoryInterface::class
        ],
        'middleware' => [
            CorsMiddleware::class,
            JsonBodyParserMiddleware::class,
            LoggingMiddleware::class
        ]
    ];

    /**
     * Register custom services
     *
     * @return void
     */
    public function register(): void
    {
        // Register router with container and middleware
        // Register router with container and middleware manager
        $this->singleton(Router::class, function ($container) {
            $middlewareManager = $container->make(MiddlewareManager::class);
            return new Router($container, $middlewareManager);
        });

        // Register PSR-15 middleware classes
        $this->registerPsr15Middleware();

        // Register application
        $this->singleton(Application::class, function ($container) {
            // Get the ServiceProviderManager
            $providerManager = $container->make(\Ody\Foundation\Providers\ServiceProviderManager::class);

            // If ServiceProviderManager isn't registered yet, create it
            if (!$providerManager) {
                $config = $container->has('config') ? $container->make('config') : null;
                $logger = $container->has(LoggerInterface::class) ? $container->make(LoggerInterface::class) : null;
                $providerManager = new \Ody\Foundation\Providers\ServiceProviderManager($container, $config, $logger);
                $container->instance(\Ody\Foundation\Providers\ServiceProviderManager::class, $providerManager);
            }

            // Return the Application with correct constructor parameters
            return new Application($container, $providerManager);
        });
    }

    /**
     * Bootstrap the service provider.
     *
     * @return void
     */
    public function boot(): void
    {
        // Application bootstrapping logic
    }

    /**
     * Register PSR-15 middleware implementations
     *
     * @return void
     */
    private function registerPsr15Middleware(): void
    {
        // Register CORS middleware
        $this->singleton(CorsMiddleware::class, function ($container) {
            $config = $container->make(Config::class);
            $corsConfig = $config->get('cors', [
                'origin' => '*',
                'methods' => 'GET, POST, PUT, DELETE, OPTIONS',
                'headers' => 'Content-Type, Authorization, X-API-Key',
                'max_age' => 86400
            ]);

            return new CorsMiddleware($corsConfig);
        });

        // Register JSON body parser middleware
        $this->singleton(JsonBodyParserMiddleware::class, function () {
            return new JsonBodyParserMiddleware();
        });

        // Register logging middleware
        $this->singleton(LoggingMiddleware::class, function ($container) {
            $logger = $container->make(LoggerInterface::class);

            $config = $container->make(Config::class);

            // Get routes to exclude from logging
            $excludedRoutes = $config->get('logging.exclude_routes', []);

            // Add InfluxDB log viewer routes to excluded routes
            $influxDbExcludedRoutes = [
                '/api/logs/recent',
                '/api/logs/services',
                '/api/logs/levels',
                '/api/logs/service/*', // Wildcard pattern for service-specific logs
            ];

            $excludedRoutes = array_merge($excludedRoutes, $influxDbExcludedRoutes);

            return new LoggingMiddleware($logger, $excludedRoutes);
        });
    }
}