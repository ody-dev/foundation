<?php
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

        // Get or create service provider manager
        $providerManager = $container->has(ServiceProviderManager::class)
            ? $container->make(ServiceProviderManager::class)
            : new ServiceProviderManager($container);

        $container->instance(ServiceProviderManager::class, $providerManager);

        // Register core providers
        self::registerServiceProviders($providerManager);

        return $container;
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
        error_log('Loading coreProviders in ConsoleBootstrapper registerServiceProviders()');
        $coreProviders = [
            EnvServiceProvider::class,
            ConfigServiceProvider::class,
            LoggingServiceProvider::class,
            ConsoleServiceProvider::class,
        ];

        array_walk($coreProviders, function ($provider) use ($providerManager) {
            $providerManager->register($provider);
        });

        /**
         * TODO: Figure out if we really need providers from config in the console
         */
//        $config = $providerManager->getContainer()->make(Config::class);
//        $providers = $config->get('app.providers', []);
//
//        error_log('Loading providers from config in ConsoleBootstrapper registerServiceProviders()');
//        array_walk($providers, function ($provider) use ($providerManager) {
//            $providerManager->register($provider);
//        });

        // Boot all registered providers
        $providerManager->boot();
    }
}