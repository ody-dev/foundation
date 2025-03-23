<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Logging Middleware (PSR-15) with route filtering
 */
class LoggingMiddleware implements MiddlewareInterface
{
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var array Routes to exclude from logging
     */
    private array $excludedRoutes = [];

    /**
     * LoggingMiddleware constructor
     *
     * @param LoggerInterface $logger
     * @param array $excludedRoutes Routes to exclude from logging
     */
    public function __construct(
        LoggerInterface $logger,
        array $excludedRoutes = []
    ) {
        $this->logger = $logger;
        $this->excludedRoutes = $excludedRoutes;
    }

    /**
     * Process an incoming server request
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $startTime = microtime(true);
        $path = $request->getUri()->getPath();

        // Check if this path should be excluded from logging
        $shouldLog = !$this->isPathExcluded($path);

        if ($shouldLog) {
            $this->logger->info('Request started', [
                'method' => $request->getMethod(),
                'uri' => $path,
                'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown'
            ]);
        }

        try {
            $response = $handler->handle($request);

            if ($shouldLog) {
                $duration = microtime(true) - $startTime;
                $this->logger->info('Request completed', [
                    'method' => $request->getMethod(),
                    'uri' => $path,
                    'status' => $response->getStatusCode(),
                    'duration' => round($duration * 1000, 2) . 'ms'
                ]);
            }

            return $response;
        } catch (\Throwable $e) {
            $duration = microtime(true) - $startTime;

            // Always log errors, even for excluded paths
            $this->logger->error('Request failed', [
                'method' => $request->getMethod(),
                'uri' => $path,
                'error' => $e->getMessage(),
                'duration' => round($duration * 1000, 2) . 'ms'
            ]);

            throw $e;
        }
    }

    /**
     * Check if a path should be excluded from logging
     *
     * @param string $path
     * @return bool
     */
    private function isPathExcluded(string $path): bool
    {
        foreach ($this->excludedRoutes as $excludedRoute) {
            // Support for direct path matching
            if ($excludedRoute === $path) {
                return true;
            }

            // Support for pattern matching with wildcards
            if (strpos($excludedRoute, '*') !== false) {
                $pattern = str_replace('*', '.*', $excludedRoute);
                if (preg_match('#^' . $pattern . '$#', $path)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Add a path to the exclusion list
     *
     * @param string $path
     * @return self
     */
    public function excludePath(string $path): self
    {
        $this->excludedRoutes[] = $path;
        return $this;
    }

    /**
     * Add multiple paths to the exclusion list
     *
     * @param array $paths
     * @return self
     */
    public function excludePaths(array $paths): self
    {
        $this->excludedRoutes = array_merge($this->excludedRoutes, $paths);
        return $this;
    }
}