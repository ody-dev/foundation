<?php

namespace Ody\Foundation\Loaders;

use Ody\Container\Container;
use Ody\Foundation\MiddlewareManager;
use Ody\Foundation\Router;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Route Loader
 *
 * Handles loading and registering routes from files.
 * Supports module-specific routes with proper grouping and namespacing.
 */
class RouteLoader
{
    /**
     * The router instance.
     *
     * @var Router
     */
    protected Router $router;

    /**
     * The middleware manager instance.
     *
     * @var MiddlewareManager
     */
    protected MiddlewareManager $middlewareManager;

    /**
     * The container instance.
     *
     * @var Container
     */
    protected Container $container;

    /**
     * The logger instance.
     *
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * The loaded route files.
     *
     * @var array
     */
    protected array $loadedFiles = [];

    /**
     * Create a new route loader instance.
     *
     * @param Router $router
     * @param MiddlewareManager $middlewareManager
     * @param Container $container
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        Router $router,
        MiddlewareManager $middlewareManager,
        Container $container,
        ?LoggerInterface $logger = null
    ) {
        $this->router = $router;
        $this->middlewareManager = $middlewareManager;
        $this->container = $container;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Load routes from a file.
     *
     * @param string $path Path to the route file
     * @param array $attributes Optional route group attributes
     * @return bool True if file was loaded, false if already loaded or not found
     */
    public function load(string $path, array $attributes = []): bool
    {
        // Normalize file path
        $path = $this->normalizePath($path);

        if (!file_exists($path)) {
            $this->logger->warning("Route file not found: {$path}");
            return false;
        }

        // Don't load the same file twice
        if (in_array($path, $this->loadedFiles)) {
            return false;
        }

        // Add to loaded files list
        $this->loadedFiles[] = $path;

        // Load the routes with the router, middleware manager, and container in scope
        $router = $this->router;
        $middlewareManager = $this->middlewareManager;
        $container = $this->container;

        // Apply attributes if provided (for route groups)
        if (!empty($attributes)) {
            $router->group($attributes, function () use ($path, $router, $middlewareManager, $container) {
                require $path;
            });
        } else {
            require $path;
        }

        return true;
    }

    /**
     * Load all route files from a directory.
     *
     * @param string $directory Directory containing route files
     * @param array $attributes Optional route group attributes
     * @param string $extension File extension to look for (default: .php)
     * @return int Number of files loaded
     */
    public function loadDirectory(string $directory, array $attributes = [], string $extension = '.php'): int
    {
        $directory = $this->normalizePath($directory);

        if (!is_dir($directory)) {
            $this->logger->warning("Routes directory not found: {$directory}");
            return 0;
        }

        $count = 0;
        $files = scandir($directory);
        $extension = ltrim($extension, '.');

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $directory . '/' . $file;

            if (is_file($filePath) && pathinfo($filePath, PATHINFO_EXTENSION) === $extension) {
                if ($this->load($filePath, $attributes)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Normalize a file path.
     *
     * @param string $path
     * @return string
     */
    protected function normalizePath(string $path): string
    {
        // Handle relative paths
        if ($path[0] !== '/' && function_exists('base_path')) {
            $path = base_path($path);
        }

        return $path;
    }

    /**
     * Get a list of loaded files.
     *
     * @return array
     */
    public function getLoadedFiles(): array
    {
        return $this->loadedFiles;
    }

    /**
     * Set the router instance.
     *
     * @param Router $router
     * @return self
     */
    public function setRouter(Router $router): self
    {
        $this->router = $router;
        return $this;
    }

    /**
     * Set the middleware manager instance.
     *
     * @param MiddlewareManager $middlewareManager
     * @return self
     */
    public function setMiddlewareManager(MiddlewareManager $middlewareManager): self
    {
        $this->middlewareManager = $middlewareManager;
        return $this;
    }

    /**
     * Set the container instance.
     *
     * @param Container $container
     * @return self
     */
    public function setContainer(Container $container): self
    {
        $this->container = $container;
        return $this;
    }
}