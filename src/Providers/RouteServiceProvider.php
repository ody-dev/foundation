<?php

namespace Ody\Foundation\Providers;

use Ody\Container\Container;
use Ody\Foundation\Loaders\RouteLoader;
use Ody\Foundation\Middleware\MiddlewareRegistry;
use Ody\Foundation\Router;
use Ody\Support\Config;
use Psr\Log\LoggerInterface;

/**
 * Route Service Provider
 *
 * Handles route registration and loading.
 * Demonstrates how to use the new ServiceProvider base class.
 */
class RouteServiceProvider extends ServiceProvider
{
    /**
     * The route loader instance.
     *
     * @var RouteLoader|null
     */
    protected ?RouteLoader $routeLoader = null;

    /**
     * The base path for route files.
     *
     * @var string
     */
    protected string $routesPath = '';

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        // Register RouteLoader as a singleton
        $this->singleton(RouteLoader::class, function (Container $container) {
            error_log('RouteServiceProvider::singleton(RouteLoader)...');
            $router = $container->make(Router::class);
            $middlewareRegistry = $container->make(MiddlewareRegistry::class);
            $logger = $container->make(LoggerInterface::class);

            return new RouteLoader($router, $middlewareRegistry, $container, $logger);
        });

        // Register the route loader alias
        $this->alias(RouteLoader::class, 'route.loader');

        // Set route path from configuration
        $config = $this->make(Config::class);
        $this->routesPath = $config->get('app.routes.path', base_path('routes'));
    }

    /**
     * Bootstrap the service provider.
     *
     * @return void
     */
    public function boot(): void
    {
        // Don't load routes during bootstrap, they'll be loaded on demand
        $this->routeLoader = $this->make(RouteLoader::class);

        // Only register the route loader, but don't load routes yet
        error_log("RouteServiceProvider: Setting up lazy route loading");

        // Load routes from the configured path
//        $this->loadRoutes();
    }

    /**
     * Load routes from a path or the default routes directory.
     *
     * @param string|null $path Path to a route file or directory
     * @param array $attributes Optional route group attributes
     * @return void
     */
    public function loadRoutes(?string $path = null, array $attributes = []): void
    {
        // If no path provided, load from default routes location
        if ($path === null) {
            $this->loadDefaultRoutes();
            return;
        }

        // Check if the path is a directory or file
        if (is_dir($path)) {
            $this->routeLoader->loadDirectory($path, $attributes);
        } else if (file_exists($path)) {
            $this->routeLoader->load($path, $attributes);
        } else {
            // Try prepending base path if the path is relative
            $fullPath = function_exists('base_path') ? base_path($path) : $path;

            if (is_dir($fullPath)) {
                $this->routeLoader->loadDirectory($fullPath, $attributes);
            } else if (file_exists($fullPath)) {
                $this->routeLoader->load($fullPath, $attributes);
            } else {
                $logger = $this->make(LoggerInterface::class);
                $logger->warning("Route path not found: {$path}");
            }
        }
    }

    /**
     * Load the default application routes.
     *
     * @return void
     */
    protected function loadDefaultRoutes(): void
    {
        if (!is_dir($this->routesPath)) {
            return;
        }

        // Load main web routes
        $webRoutesFile = $this->routesPath . '/web.php';
        if (file_exists($webRoutesFile)) {
            $this->routeLoader->load($webRoutesFile);
        }

        // Load API routes with appropriate prefixing and middleware
        $apiRoutesFile = $this->routesPath . '/api.php';
        if (file_exists($apiRoutesFile)) {
            $this->routeLoader->load($apiRoutesFile, [
                'prefix' => '/api',
                'middleware' => ['api'],
            ]);
        }

        // Load all other route files in the directory
        $this->routeLoader->loadDirectory($this->routesPath);
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides(): array
    {
        return [RouteLoader::class, 'route.loader'];
    }
}