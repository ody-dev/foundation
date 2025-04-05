<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Providers;

use Ody\Support\Env;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Environment Service Provider
 *
 * Responsible for loading environment variables in a consistent way.
 */
class EnvServiceProvider extends ServiceProvider
{
    /**
     * Register environment-related services
     *
     * @return void
     */
    public function register(): void
    {
        // Get or create a logger
        $logger = $this->container->has(LoggerInterface::class)
            ? $this->container->make(LoggerInterface::class)
            : new NullLogger();

        // Create the environment manager
        $env = new Env(base_path());

        // Load environment files
        $environment = env('APP_ENV', 'production');
        $env->load($environment);

        // Register in container
        $this->container->instance(Env::class, $env);

        // Log which environment we're using
        $logger->info("Environment loaded: {$environment}");
    }

    /**
     * Determine the application base path
     *
     * @return string
     */
    protected function determineBasePath(): string
    {
        // If defined as a constant, use that
        if (defined('APP_BASE_PATH')) {
            return APP_BASE_PATH;
        }

        // Options for base path in descending priority
        $paths = [
            // Environment variable
            getenv('APP_BASE_PATH'),

            // Current working directory
            getcwd(),

            // Up from src/Foundation/Providers
            dirname(__DIR__, 3),

            // In case we're in vendor directory
            dirname(__DIR__, 5),
        ];

        // Return the first valid path
        foreach ($paths as $path) {
            if ($path && is_dir($path)) {
                return $path;
            }
        }

        // Fallback to current directory
        return getcwd();
    }

    /**
     * Bootstrap any services
     *
     * @return void
     */
    public function boot(): void
    {
        // Nothing to bootstrap
    }
}