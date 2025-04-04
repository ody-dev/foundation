<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Http;

use Exception;
use Psr\Log\LoggerInterface;

/**
 * ControllerResolver
 *
 * Resolves string controller references to callable instances
 */
readonly class ControllerResolver
{
    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param ControllerPool $controllerPool
     */
    public function __construct(
        private LoggerInterface $logger,
        private ControllerPool  $controllerPool // Add injection
    )
    {
    }

    /**
     * Create a controller instance with all dependencies resolved
     *
     * @param string $class Controller class name
     * @return object Controller instance
     * @throws Exception If controller cannot be created
     */
    public function createController(string $class): object
    {
        try {
            // First check if the controller exists
            if (!class_exists($class)) {
                throw new \RuntimeException("Controller class '{$class}' does not exist");
            }

            // Get controller from pool
            return $this->controllerPool->get($class);
        } catch (\Throwable $e) {
            $this->logger->error("Error creating controller", [
                'controller' => $class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException("Error creating controller: {$e->getMessage()}", 0, $e);
        }
    }
}