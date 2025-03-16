<?php
declare(strict_types=1);

namespace Ody\Foundation;

use Ody\Container\Container;
use Ody\Container\Contracts\BindingResolutionException;
use Ody\Foundation\Providers\ServiceProviderManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Application Bootstrap
 *
 * Central bootstrap point for both web and console entry points.
 */
class Bootstrap
{
    /**
     * Static instance of the application
     */
    private static ?Application $instance = null;

    /**
     * Track bootstrap operation status
     */
    private static bool $bootstrapping = false;

    /**
     * Initialize the application
     */
    public static function init(
        ?Container $container = null,
        ?string $basePath = null,
    ): Application
    {
        // Return existing instance if already initialized
        if (self::$instance !== null) {
            // Ensure it's bootstrapped
            if (!self::$instance->isBootstrapped()) {
                self::$instance->bootstrap();
            }

            // logger()->debug("Bootstrap::init() returning existing application instance");
            return self::$instance;
        }

        if (self::$bootstrapping) {
            // logger()->warning("Bootstrap::init() called recursively");
            exit(1); // TODO: throw appropriate exception;
        }

        // Set bootstrapping flag to prevent recursion
        self::$bootstrapping = true;
        // logger()->debug("Bootstrap::init() creating new application instance");

        try {
            self::initBasePath($basePath);
            $container = self::initContainer($container);

            $providerManager = new ServiceProviderManager($container);
            $container->instance(ServiceProviderManager::class, $providerManager);

            // Create application but don't bootstrap it yet
            $application = self::createApplication($container, $providerManager);

            // Store the instance immediately (before bootstrapping)
            self::$instance = $application;

            // Reset bootstrapping flag
            self::$bootstrapping = false;

            return $application;
        } catch (\Throwable $e) {
            // Reset flag
            self::$bootstrapping = false;
            throw $e;
        }
    }

    /**
     * Initialize the base path
     *
     * @param string|null $basePath
     * @return void
     */
    private static function initBasePath(?string $basePath = null): void
    {
        // Use provided path, or determine from current file location
        $basePath = $basePath ?? dirname(__DIR__, 2);

        // Define constant for global access if not already defined
        if (!defined('APP_BASE_PATH')) {
            define('APP_BASE_PATH', $basePath);
        }
    }

    /**
     * Initialize the container
     *
     * @param Container|null $container
     * @return Container
     */
    private static function initContainer(?Container $container = null): Container
    {
        // Create container if not provided
        $container = $container ?? new Container();

        // Set as global instance
        Container::setInstance($container);

        return $container;
    }

    /**
     * Create and bootstrap the application
     *
     * @param Container $container
     * @param ServiceProviderManager $providerManager
     * @return Application
     * @throws BindingResolutionException
     */
    private static function createApplication(Container $container, ServiceProviderManager $providerManager): Application
    {
        // Create the application
        $application = $container->has(Application::class)
            ? $container->make(Application::class)
            : new Application($container, $providerManager);

        $container->instance(Application::class, $application);

        return $application;
    }
}