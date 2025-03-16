<?php
namespace Ody\Foundation\Providers;

use Ody\Support\Config;

/**
 * Service provider for configuration
 */
class ConfigServiceProvider extends ServiceProvider
{
    /**
     * Register custom services
     *
     * @return void
     */
    public function register(): void
    {
        // Create a new config instance
        $config = new Config();

        // Load config files from possible paths
        $this->loadConfigFromPossiblePaths($config);

        // Register in container
        $this->container->instance('config', $config);
        $this->container->instance(Config::class, $config);
    }

    /**
     * Load configuration from multiple possible paths
     *
     * @param Config $config
     * @return bool True if configuration was loaded successfully
     */
    protected function loadConfigFromPossiblePaths(Config $config): bool
    {
        // First check for environment-defined config path
        $configPath = env('CONFIG_PATH');

        if ($configPath && is_dir($configPath)) {
            $config->loadFromDirectory($configPath);
            return count($config->all()) > 0;
        }

        // List of possible config paths in order of priority
        $possiblePaths = [
            // From APP_BASE_PATH constant
            defined('APP_BASE_PATH') ? rtrim(APP_BASE_PATH, '/') . '/config' : null,

            // Relative to current directory
            getcwd() . '/config',

            // Up from src/Foundation/Providers
            dirname(__DIR__, 3) . '/config',

            // In case we're in vendor directory
            dirname(__DIR__, 5) . '/config',
        ];

        // Filter out null paths
        $possiblePaths = array_filter($possiblePaths);

        // Try each path
        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                $config->loadFromDirectory($path);

                // If we found configs, no need to keep searching
                if (count($config->all()) > 0) {
                    return true;
                }
            }
        }

        // If no directory-based loading worked, try individual files
        if (count($config->all()) === 0) {
            return $this->loadIndividualConfigFiles($config, $possiblePaths);
        }

        return count($config->all()) > 0;
    }

    /**
     * Load individual important config files
     *
     * @param Config $config
     * @param array $basePaths
     * @return bool
     */
    protected function loadIndividualConfigFiles(Config $config, array $basePaths): bool
    {
        // Core config files to load
        $configFiles = ['app', 'database', 'logging'];
        $loaded = false;

        foreach ($configFiles as $configName) {
            foreach ($basePaths as $path) {
                $filePath = $path . '/' . $configName . '.php';
                if (file_exists($filePath)) {
                    $config->loadFile($configName, $filePath);
                    $loaded = true;
                    break;
                }
            }
        }

        return $loaded;
    }

    /**
     * Bootstrap any application services
     *
     * @return void
     */
    public function boot(): void
    {
        // No bootstrapping needed for config
    }
}