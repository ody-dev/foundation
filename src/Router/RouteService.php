<?php

namespace Ody\Foundation\Router;

use Ody\Container\Container;
use Ody\Foundation\Loaders\RouteLoader;
use Ody\Foundation\MiddlewareManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Route Service
 *
 * Centralized service for managing routes in the application.
 * Loads all routes during the application bootstrap process.
 */
class RouteService
{
    /**
     * @var Container
     */
    protected Container $container;

    /**
     * @var Router
     */
    protected Router $router;

    /**
     * @var MiddlewareManager
     */
    protected MiddlewareManager $middlewareManager;

    /**
     * @var RouteLoader
     */
    protected RouteLoader $routeLoader;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var bool
     */
    protected bool $routesLoaded = false;

    /**
     * Create a new route service
     *
     * @param Container $container
     * @param Router $router
     * @param MiddlewareManager $middlewareManager
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        Container $container,
        Router $router,
        MiddlewareManager $middlewareManager,
        ?LoggerInterface $logger = null
    ) {
        $this->container = $container;
        $this->router = $router;
        $this->middlewareManager = $middlewareManager;
        $this->logger = $logger ?? new NullLogger();

        // Create a route loader instance
        $this->routeLoader = new RouteLoader(
            $router,
            $middlewareManager,
            $container,
            $logger
        );

        // Register self in container
        $container->instance(self::class, $this);
    }

    /**
     * Load all application routes during bootstrap
     *
     * @return void
     */
    public function bootRoutes(): void
    {
        if ($this->routesLoaded) {
            $this->logger->debug("Routes already loaded, skipping");
            return;
        }

        $this->logger->info("Loading application routes");

        try {
            // Load core routes
            $this->loadCoreRoutes();

            // Load module routes
            $this->loadModuleRoutes();

            $this->routesLoaded = true;
        } catch (\Throwable $e) {
            $this->logger->error("Error loading routes: {$e->getMessage()}", [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Load core application routes
     *
     * @return void
     */
    protected function loadCoreRoutes(): void
    {
        $routesPath = base_path('routes');

        // Load essential routes first with explicit order
        $priorityRoutes = ['api.php', 'web.php'];

        foreach ($priorityRoutes as $routeFile) {
            $filePath = $routesPath . '/' . $routeFile;
            if (file_exists($filePath)) {
                $this->routeLoader->load($filePath);
            }
        }

        // Load any remaining route files in the routes directory
        $this->routeLoader->loadDirectory($routesPath, [], 'php');
    }

    /**
     * Load routes from application modules
     *
     * @return void
     */
    protected function loadModuleRoutes(): void
    {
        $modulesPath = base_path('modules');

        if (!is_dir($modulesPath)) {
            return;
        }

        $modules = array_diff(scandir($modulesPath), ['.', '..']);

        foreach ($modules as $module) {
            $moduleRoutesPath = $modulesPath . '/' . $module . '/routes';

            if (is_dir($moduleRoutesPath)) {
                // Get module configuration if available
                $config = config("modules.{$module}", []);

                // Set up module attributes
                $attributes = [
                    'prefix' => $config['route_prefix'] ?? $module,
                    'namespace' => $config['namespace'] ?? "Modules\\{$module}\\Controllers",
                ];

                // Add middleware if configured
                if (!empty($config['middleware'])) {
                    $attributes['middleware'] = $config['middleware'];
                }

                // Load module routes with the configured attributes
                $this->routeLoader->loadDirectory($moduleRoutesPath, $attributes);

                $this->logger->debug("Loaded routes for module: {$module}");
            }
        }
    }

    /**
     * Get the router instance
     *
     * @return Router
     */
    public function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * Get the route loader instance
     *
     * @return RouteLoader
     */
    public function getRouteLoader(): RouteLoader
    {
        return $this->routeLoader;
    }

    /**
     * Check if routes have been loaded
     *
     * @return bool
     */
    public function isRoutesLoaded(): bool
    {
        return $this->routesLoaded;
    }
}