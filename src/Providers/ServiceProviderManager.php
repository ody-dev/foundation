<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

/*
 * This file is part of ODY framework
 *
 * @link https://ody.dev
 * @documentation https://ody.dev/docs
 * @license https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Providers;

use Ody\Container\Container;
use Ody\Support\Config;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service Provider Manager
 */
class ServiceProviderManager
{
    /**
     * The container instance.
     *
     * @var Container
     */
    protected Container $container;

    /**
     * All of the registered service providers.
     *
     * @var ServiceProvider[]
     */
    protected array $providers = [];

    /**
     * The names of the loaded service providers.
     *
     * @var string[]
     */
    protected array $loaded = [];

    /**
     * Deferred service providers.
     *
     * @var array<string, string>
     */
    protected array $deferred = [];

    /**
     * Application configuration.
     *
     * @var Config|null
     */
    protected ?Config $config;

    /**
     * Logger instance.
     *
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * Create a new service provider manager instance.
     *
     * @param Container $container
     * @param Config|null $config
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        Container $container,
        ?Config $config = null,
        ?LoggerInterface $logger = null
    ) {
        $this->container = $container;
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Register a service provider.
     *
     * @param string|ServiceProvider $provider
     * @param bool $force Force registration even if already registered
     * @return ServiceProvider
     */
    public function register($provider, bool $force = false): ServiceProvider
    {
        // Convert string provider to instance
        $providerName = is_string($provider) ? $provider : get_class($provider);

        // Convert string provider to instance
        if (is_string($provider)) {
            $providerClass = $provider;

            // Skip if already registered and not forcing
            if (!$force && $this->isRegistered($providerClass)) {
                error_log("Provider already registered: {$providerClass}");
                return $this->providers[$providerClass];
            }

            // Create the provider instance
            try {
                $provider = $this->resolveProvider($providerClass);
            } catch (\Throwable $e) {
                error_log("Error resolving provider {$providerClass}: " . $e->getMessage());
                throw $e;
            }
        } else {
            $providerClass = get_class($provider);

            // Skip if already registered and not forcing
            if (!$force && $this->isRegistered($providerClass)) {
                error_log("Provider already registered: {$providerClass}");
                return $this->providers[$providerClass];
            }
        }

        // Set the container on the provider
        if (!isset($provider->container) || $provider->container === null) {
            $provider->container = $this->container;
        }

        // Check for deferred providers
        if (!$force && method_exists($provider, 'isDeferred') && $provider->isDeferred()) {
            $this->registerDeferredProvider($provider);
            return $provider;
        }

        $provider->registerCommon();

        $provider->register();

        // Store the provider
        $this->providers[$providerClass] = $provider;
        $this->loaded[] = $providerClass;

        $this->logger->debug("Registered provider: {$providerClass}");

        return $provider;
    }

    /**
     * Register a deferred provider.
     *
     * @param ServiceProvider $provider
     * @return void
     */
    protected function registerDeferredProvider(ServiceProvider $provider): void
    {
        $providerClass = get_class($provider);

        // Store provider for later booting
        $this->providers[$providerClass] = $provider;

        // Register the services that this provider provides
        if (method_exists($provider, 'provides')) {
            foreach ($provider->provides() as $service) {
                $this->deferred[$service] = $providerClass;
            }
        }
    }

    /**
     * Check if a provider is registered.
     *
     * @param string $providerClass
     * @return bool
     */
    public function isRegistered(string $providerClass): bool
    {
        return isset($this->providers[$providerClass]);
    }

    /**
     * Resolve a provider instance from class name.
     *
     * @param string $providerClass
     * @return ServiceProvider
     */
    protected function resolveProvider(string $providerClass): ServiceProvider
    {
        // Check if the container can resolve it
        if ($this->container->has($providerClass)) {
            return $this->container->make($providerClass);
        }

        // Otherwise create a new instance
        return new $providerClass($this->container);
    }

    /**
     * Boot all registered providers.
     *
     * @return void
     */
    public function boot(): void
    {
        foreach ($this->providers as $provider) {
            $this->bootProvider($provider);
        }
    }

    /**
     * Boot a specific provider.
     *
     * @param ServiceProvider $provider
     * @return void
     */
    public function bootProvider(ServiceProvider $provider): void
    {
        if (method_exists($provider, 'boot')) {
            try {
                $provider->boot();
                $this->logger->debug("Booted provider: " . get_class($provider));
            } catch (\Throwable $e) {
                $this->logger->error("Error booting provider: " . get_class($provider), [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                throw $e;
            }
        }
    }

    /**
     * Register providers from configuration.
     *
     * @param string $configKey Path to providers config (e.g., 'app.providers')
     * @return int Number of providers registered
     */
    public function registerConfigProviders(string $configKey): void
    {
        $config = $this->container->make(Config::class);
        $providers = $config->get($configKey, []);
        $count = 0;


        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    /**
     * Load a deferred provider if needed.
     *
     * @param string $service
     * @return bool True if a provider was loaded
     */
    public function loadDeferredProviderIfNeeded(string $service): bool
    {
        if (!isset($this->deferred[$service])) {
            return false;
        }

        $providerClass = $this->deferred[$service];

        // Already fully loaded
        if (in_array($providerClass, $this->loaded)) {
            return false;
        }

        // Register and boot the provider
        $provider = $this->providers[$providerClass];

        try {
            $provider->register();
            $this->bootProvider($provider);

            // Mark as loaded
            $this->loaded[] = $providerClass;

            // Remove from deferred list
            unset($this->deferred[$service]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error("Error loading deferred provider: {$providerClass}", [
                'service' => $service,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get all registered providers.
     *
     * @return ServiceProvider[]
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Get loaded provider class names.
     *
     * @return string[]
     */
    public function getLoaded(): array
    {
        return $this->loaded;
    }

    /**
     * Get deferred services mapping.
     *
     * @return array<string, string>
     */
    public function getDeferred(): array
    {
        return $this->deferred;
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

    /**
     * Set the configuration.
     *
     * @param Config $config
     * @return self
     */
    public function setConfig(Config $config): self
    {
        $this->config = $config;
        return $this;
    }

    /**
     * Set the logger.
     *
     * @param LoggerInterface $logger
     * @return self
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Get the container instance.
     *
     * @return Container
     */
    public function getContainer(): Container
    {
        return $this->container;
    }
}