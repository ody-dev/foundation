<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Ody\Foundation;

use Ody\Container\Container;
use Ody\Container\Contracts\BindingResolutionException;
use Ody\Foundation\Providers\ServiceProviderManager;

/**
 * Application Bootstrap
 *
 * Central bootstrap point for both web and console entry points.
 */
class Bootstrap
{
    /**
     * Initialize the application
     */
    public static function init(
        ?Container $container = null,
        ?string $basePath = null,
    ): Application
    {
        return (new Bootstrap)->handle(
            $container,
            $basePath,
        );
    }

    public function handle($container, $basePath)
    {
        $this->initBasePath($basePath);
        $container = $this->initContainer($container);

        $providerManager = new ServiceProviderManager($container);
        $container->instance(ServiceProviderManager::class, $providerManager);

        $container->instance('runningInConsole', false);

        $application = $this->createApplication($container, $providerManager);

        return $application;
    }

    /**
     * Create and bootstrap the application
     *
     * @param Container $container
     * @param ServiceProviderManager $providerManager
     * @return Application
     * @throws BindingResolutionException
     */
    private function createApplication(Container $container, ServiceProviderManager $providerManager): Application
    {
        // Create the application
        $application = $container->has(Application::class)
            ? $container->make(Application::class)
            : new Application($container, $providerManager);

        $container->instance(Application::class, $application);
        $container->alias(Application::class, 'app');

        return $application->bootstrap();
    }

    /**
     * Initialize the base path
     *
     * @param string|null $basePath
     * @return void
     */
    private function initBasePath(?string $basePath = null): void
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
    private function initContainer(?Container $container = null): Container
    {
        // Create container if not provided
        $container = $container ?? new Container();

        // Set as global instance
        Container::setInstance($container);

        return $container;
    }
}