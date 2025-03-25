<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Console;

use Ody\Container\Container;
use Ody\Container\Contracts\BindingResolutionException;
use Ody\Foundation\Providers\ConfigServiceProvider;
use Ody\Foundation\Providers\ConsoleServiceProvider;
use Ody\Foundation\Providers\EnvServiceProvider;
use Ody\Foundation\Providers\LoggingServiceProvider;
use Ody\Foundation\Providers\ServiceProviderManager;
use Ody\Foundation\Publishing\Publisher;
use Ody\Logger\NullLogger;
use Ody\Support\Config;
use Symfony\Component\Console\Application as ConsoleApplication;

/**
 * Console Bootstrapper
 *
 * This class helps initialize the console environment with proper dependencies
 */
class ConsoleBootstrapper
{
    /**
     * Initialize a configured console kernel
     *
     * @param Container|null $container
     * @return ConsoleKernel
     * @throws BindingResolutionException
     */
    public static function kernel(?Container $container = null): ConsoleKernel
    {
        $container = self::bootstrap($container);
        return $container->make(ConsoleKernel::class);
    }

    /**
     * Bootstrap the console environment
     *
     * @param Container|null $container
     * @return Container
     */
    public static function bootstrap(?Container $container = null): Container
    {
        // Initialize container if not provided
        $container = $container ?: new Container();
        Container::setInstance($container);

        // Mark as running in console
        $container->instance('runningInConsole', true);

        // Get or create service provider manager
        $providerManager = $container->has(ServiceProviderManager::class)
            ? $container->make(ServiceProviderManager::class)
            : new ServiceProviderManager($container);

        $container->instance(ServiceProviderManager::class, $providerManager);

        $container->singleton(Publisher::class, function ($container) {
            return new Publisher(
                $container,
                new NullLogger()
            );
        });

        // Register core providers
        self::registerServiceProviders($providerManager);

        return $container;
    }

    /**
     * Finalize command registration by adding all registered commands to the Symfony console
     *
     * @param Container $container
     * @return void
     */
    protected static function finalizeCommandRegistration(Container $container): void
    {
        // Skip if not running in console or missing required components
        if (!$container->has('runningInConsole') ||
            !$container->has(CommandRegistry::class) ||
            !$container->has(ConsoleApplication::class)) {
            return;
        }

        // Get the command registry and console application
        $registry = $container->make(CommandRegistry::class);
        $console = $container->make(ConsoleApplication::class);

        // Add all registered commands to the Symfony console
        foreach ($registry->getCommands() as $command) {
            $console->add($command);
        }
    }

    /**
     * Register core service providers
     *
     * @param ServiceProviderManager $providerManager
     * @return void
     */
    protected static function registerServiceProviders(ServiceProviderManager $providerManager): void
    {
        // Core providers that must be registered in console environment
        // Order matters! LoggingServiceProvider must be registered before ConsoleServiceProvider
        $coreProviders = [
            EnvServiceProvider::class,
            ConfigServiceProvider::class,
            LoggingServiceProvider::class, // This must be fully registered and booted first
            ConsoleServiceProvider::class, // This depends on logger
        ];

        array_walk($coreProviders, function ($provider) use ($providerManager) {
            $providerManager->register($provider);
        });

        // Get container and check if config is available
        $container = $providerManager->getContainer();
        if ($container->has(Config::class)) {
            $config = $container->make(Config::class);
            $providers = $config->get('app.providers', []);

            // Register all providers from config
            array_walk($providers, function ($provider) use ($providerManager) {
                $providerManager->register($provider);
            });
        }

        // Boot all registered providers
        $providerManager->boot();

        // After all providers are booted, ensure all commands are registered with Symfony
        self::finalizeCommandRegistration($container);
    }
}