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

namespace Ody\Foundation\Http;

use Ody\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * ControllerResolver
 *
 * Resolves string controller references to callable instances
 */
class ControllerResolver
{
    /**
     * @var Container
     */
    protected Container $container;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var array
     */
    private array $resolvedControllers = [];

    /**
     * Constructor
     *
     * @param Container $container
     * @param LoggerInterface $logger
     */
    public function __construct(Container $container, LoggerInterface $logger)
    {
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * Create a controller instance with all dependencies resolved
     *
     * @param string $class Controller class name
     * @return object Controller instance
     * @throws \Exception If controller cannot be created
     */
    public function createController(string $class): object
    {
        try {
            // First check if the controller exists
            if (!class_exists($class)) {
                throw new \RuntimeException("Controller class '{$class}' does not exist");
            }

            // Get controller from pool
            return ControllerPool::get($class, $this->container);
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