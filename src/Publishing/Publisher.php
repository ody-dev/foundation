<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Publishing;

use Ody\Container\Container;
use Psr\Log\LoggerInterface;

class Publisher
{
    protected Container $container;
    protected LoggerInterface $logger;
    protected array $publishGroups = [];

    public function __construct(Container $container, LoggerInterface $logger)
    {
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * Register publishable assets from a package
     */
    public function registerPackage(string $packageName, array $assets): self
    {
        $this->publishGroups[$packageName] = $assets;
        return $this;
    }

    /**
     * Publish assets from all or specific packages
     */
    public function publish(?string $package = null, bool $force = false): array
    {
        $published = [];

        $groups = $package ? [$package => $this->publishGroups[$package] ?? []] : $this->publishGroups;

        foreach ($groups as $packageName => $assets) {
            foreach ($assets as $type => $paths) {
                $method = 'publish' . ucfirst($type);
                if (method_exists($this, $method)) {
                    $result = $this->$method($paths, $force);
                    if ($result) {
                        $published[] = [
                            'package' => $packageName,
                            'type' => $type,
                            'items' => $result
                        ];
                    }
                }
            }
        }

        return $published;
    }

    /**
     * Publish config files
     */
    protected function publishConfig(array $paths, bool $force = false): array
    {
        $published = [];
        $configPath = base_path('config');

        foreach ($paths as $source => $destination) {
            $destinationPath = $configPath . '/' . $destination;

            if (file_exists($destinationPath) && !$force) {
                $this->logger->info("Config file already exists: {$destination}");
                continue;
            }

            if ($this->copyFile($source, $destinationPath)) {
                $published[] = $destination;
            }
        }

        return $published;
    }

    /**
     * Copy a file, creating directories as needed
     */
    protected function copyFile(string $source, string $destination): bool
    {
        try {
            $directory = dirname($destination);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            return copy($source, $destination);
        } catch (\Throwable $e) {
            $this->logger->error("Failed to copy file", [
                'source' => $source,
                'destination' => $destination,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Publish migration files
     */
    protected function publishMigrations(array $paths, bool $force = false): array
    {
        $published = [];
        $migrationsPath = base_path('database/migrations');

        foreach ($paths as $source => $destination) {
            // Allow for timestamp prefixing of migrations
            if (is_numeric($source) && is_string($destination)) {
                $filename = basename($destination);
                $source = $destination;
                $destination = date('Y_m_d_His') . '_' . $filename;
            }

            $destinationPath = $migrationsPath . '/' . $destination;

            if (file_exists($destinationPath) && !$force) {
                $this->logger->info("Migration already exists: {$destination}");
                continue;
            }

            if ($this->copyFile($source, $destinationPath)) {
                $published[] = $destination;
            }
        }

        return $published;
    }
}