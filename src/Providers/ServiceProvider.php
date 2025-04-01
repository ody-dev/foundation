<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Providers;

use Ody\Container\Container;
use Ody\Foundation\Console\CommandRegistry;
use Ody\Foundation\Loaders\RouteLoader;
use Ody\Foundation\Publishing\Publisher;
use Ody\Foundation\Router\Router;

/**
 * Base Service Provider
 *
 * A simplified abstract class that acts as a boilerplate for users creating service providers.
 * This class focuses on being a clear interface with minimal complexity.
 */
abstract class ServiceProvider
{
    /**
     * The application container instance.
     *
     * @var Container
     */
    public Container $container;

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected bool $defer = false;

    /**
     * Services that should be registered as singletons
     *
     * @var array<string, mixed>
     */
    protected array $singletons = [];

    /**
     * Services that should be registered as bindings
     *
     * @var array<string, mixed>
     */
    protected array $bindings = [];

    /**
     * Services that should be registered as aliases
     *
     * @var array<string, string>
     */
    protected array $aliases = [];

    /**
     * Tags for organizing services
     *
     * @var array<string, array<string>>
     */
    protected array $tags = [];

    /**
     * Initialize the provider.
     *
     * @param Container|null $container Optional container instance
     * @return void
     */
    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? Container::getInstance();
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    abstract public function register(): void;

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    abstract public function boot(): void;

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides(): array
    {
        return [];
    }

    /**
     * Determine if the provider is deferred.
     *
     * @return bool
     */
    public function isDeferred(): bool
    {
        return $this->defer;
    }

    /**
     * Register the given commands.
     *
     * @param array $commands Array of command class names
     * @return void
     */
    protected function registerCommands(array $commands): void
    {
        // Skip if not in console or container not available
        if (!$this->isRunningInConsole() || !isset($this->container)) {
            return;
        }

        // Skip if command registry is not available
        if (!$this->container->has(CommandRegistry::class)) {
            if (get_class($this) === ConsoleServiceProvider::class && property_exists($this, 'commands')) {
                $this->commands = array_merge($this->commands, $commands);
            }
            return;
        }

        try {
            // Get the command registry from the container
            $registry = $this->container->make(CommandRegistry::class);

            // Register each command with the registry
            foreach ($commands as $command) {
                $registry->add($command);
            }
        } catch (\Throwable $e) {
            // If there's an error (like missing logger), store commands for later if in ConsoleServiceProvider
            if (get_class($this) === ConsoleServiceProvider::class && property_exists($this, 'commands')) {
                $this->commands = array_merge($this->commands, $commands);
            }
        }
    }

    /**
     * Register a single command.
     *
     * @param string $command Command class name
     * @return void
     */
    protected function registerCommand(string $command): void
    {
        $this->registerCommands([$command]);
    }

    /**
     * Determine if the application is running in the console.
     *
     * @return bool
     */
    protected function isRunningInConsole(): bool
    {
        return $this->container->get('runningInConsole') ?? false;
    }

    /**
     * Load routes from the specified path.
     *
     * @param string $path Path to a route file or directory
     * @param array $attributes Optional route group attributes
     * @return void
     */
    protected function loadRoutesFrom(string $path, array $attributes = []): void
    {
        if (!file_exists($path)) {
            return;
        }

        $router = $this->container->make(Router::class);
        $routeLoader = $this->container->make(RouteLoader::class);

        if (!empty($attributes)) {
            $router->group($attributes, function () use ($routeLoader, $path) {
                $routeLoader->load($path);
            });
        } else {
            $routeLoader->load($path);
        }
    }

    /**
     * Load routes from a specific path or directory.
     *
     * @param string $path Path to a route file or directory
     * @param array $attributes Optional route group attributes
     * @return void
     */
    protected function loadRoutes(string $path, array $attributes = []): void
    {
        // Check if the RouteServiceProvider is registered
        if ($this->container->has(RouteServiceProvider::class)) {
            // Use the provider's method if available
            $routeServiceProvider = $this->container->make(RouteServiceProvider::class);
            if (method_exists($routeServiceProvider, 'loadRoutes')) {
                $routeServiceProvider->loadRoutes($path, $attributes);
                return;
            }
        }

        // Fallback to the RouteLoader if available
        if ($this->container->has(RouteLoader::class)) {
            $routeLoader = $this->container->make(RouteLoader::class);

            if (is_dir($path)) {
                $routeLoader->loadDirectory($path, $attributes);
            } else {
                $routeLoader->load($path, $attributes);
            }
        } else {
            // Fallback to loadRoutesFrom for backward compatibility
            $this->loadRoutesFrom($path, $attributes);
        }
    }

    /**
     * Set up the provider with a container.
     * This method is for compatibility with older code.
     *
     * @param Container $container
     * @return void
     */
    public function setup(Container $container): void
    {
        $this->container = $container;
    }

    /**
     * Run common registration logic when a provider is registered
     *
     * @return void
     */
    public function registerCommon(): void
    {
        // Register singletons
        foreach ($this->singletons as $abstract => $concrete) {
            if (!$this->container->bound($abstract)) {
                $this->singleton($abstract, $concrete);
            }
        }

        // Register bindings
        foreach ($this->bindings as $abstract => $concrete) {
            if (!$this->container->bound($abstract)) {
                $this->bind($abstract, $concrete);
            }
        }

        // Register aliases
        foreach ($this->aliases as $alias => $abstract) {
            if (!$this->container->bound($alias)) {
                $this->alias($abstract, $alias);
            }
        }

        // Register tags
        foreach ($this->tags as $tag => $abstracts) {
            foreach ($abstracts as $abstract) {
                $this->container->tag($abstract, $tag);
            }
        }
    }

    /**
     * Register publishable assets for the service provider
     *
     * @param array $paths Asset paths in format ['source' => 'destination']
     * @param string $type The type of asset (config, migrations, etc.)
     * @return self
     */
    protected function publishes(array $paths, string $package = null, string $type = 'config'): self
    {
        // Skip if not in console or publisher doesn't exist
        if (!$this->container->has(Publisher::class)) {
            return $this;
        }

        // Get the package name from the provider class
        if ($package === null) {
            $package = $this->getPackageName();
        }

        // Register with the publisher
        $publisher = $this->container->make(Publisher::class);
        $publisher->registerPackage($package, [$type => $paths]);

        return $this;
    }

    /**
     * Get the package name from the provider class
     *
     * @return string
     */
    protected function getPackageName(): string
    {
        $class = get_class($this);
        $parts = explode('\\', $class);

        // Try to determine package name from namespace
        // Assumes format like Ody\PackageName\Providers
        if (count($parts) >= 2) {
            return strtolower($parts[1]);
        }

        return 'unknown';
    }

    /**
     * Register a binding with the container.
     *
     * @param string $abstract
     * @param mixed $concrete
     * @param bool $shared
     * @return void
     */
    protected function bind(string $abstract, $concrete = null, bool $shared = false): void
    {
        $this->container->bind($abstract, $concrete, $shared);
    }

    /**
     * Register a shared binding in the container.
     *
     * @param string $abstract
     * @param mixed $concrete
     * @return void
     */
    protected function singleton(string $abstract, $concrete = null): void
    {
        $this->container->singleton($abstract, $concrete);
    }

    /**
     * Register an existing instance as shared in the container.
     *
     * @param string $abstract
     * @param mixed $instance
     * @return void
     */
    protected function instance(string $abstract, $instance): void
    {
        $this->container->instance($abstract, $instance);
    }

    /**
     * Alias a type to a shorter name.
     *
     * @param string $abstract
     * @param string $alias
     * @return void
     */
    protected function alias(string $abstract, string $alias): void
    {
        $this->container->alias($abstract, $alias);
    }

    /**
     * Get a service from the container.
     *
     * @param string $abstract
     * @param array $parameters
     * @return mixed
     */
    protected function make(string $abstract, array $parameters = [])
    {
        return $this->container->make($abstract, $parameters);
    }

    /**
     * Check if a binding exists in the container.
     *
     * @param string $abstract
     * @return bool
     */
    protected function has(string $abstract): bool
    {
        return $this->container->bound($abstract) || $this->container->has($abstract);
    }
}