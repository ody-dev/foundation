<?php
// src/Foundation/RouteRegistry.php

namespace Ody\Foundation;

class RouteRegistry
{
    /**
     * Flag to track if routes have been loaded
     */
    private static bool $routesLoaded = false;

    /**
     * Array of loaded route files to prevent duplicates
     */
    private static array $loadedFiles = [];

    /**
     * Load routes if not already loaded
     */
    public static function loadRoutesIfNeeded(Router $router = null): void
    {
        if (self::$routesLoaded) {
            return;
        }

        error_log("RouteRegistry: Loading routes on demand");

        $routesPath = base_path('routes');

        // Load core route files
        self::loadRouteFile($routesPath . '/web.php');
        self::loadRouteFile($routesPath . '/api.php');

        // Load any other PHP files in the routes directory
        if (is_dir($routesPath)) {
            $files = scandir($routesPath);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || $file === 'web.php' || $file === 'api.php') {
                    continue;
                }

                $path = $routesPath . '/' . $file;
                if (is_file($path) && pathinfo($path, PATHINFO_EXTENSION) === 'php') {
                    self::loadRouteFile($path);
                }
            }
        }

        self::$routesLoaded = true;

        // Mark routes as loaded in the router if provided
        if ($router !== null && method_exists($router, 'markRoutesLoaded')) {
            error_log("RouteRegistry loadRoutesIneeded; router->markRoutesLoaded()");
            $router->markRoutesLoaded();
        }
    }

    /**
     * Include a route file just once
     */
    private static function loadRouteFile(string $file): void
    {
        if (!file_exists($file) || in_array($file, self::$loadedFiles)) {
            return;
        }

        error_log("RouteRegistry: Loading routes file: {$file}");
        self::$loadedFiles[] = $file;

        // Simply include the file
        include_once $file;
    }
}