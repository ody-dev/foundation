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
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

readonly class HandlerResolver
{
    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param HandlerPool $handlerPool
     */
    public function __construct(
        private LoggerInterface $logger,
        private HandlerPool $handlerPool
    )
    {
    }

    /**
     * Create a handler instance with all dependencies resolved
     *
     * @param string $class handler class name
     * @return RequestHandlerInterface PSR-15 handler instance
     * @throws Exception If handler cannot be created
     */
    public function createHandler(string $class): RequestHandlerInterface
    {
        try {
            // First check if the handler exists
            if (!class_exists($class)) {
                throw new \RuntimeException("Handler class '{$class}' does not exist");
            }

            // Get handler from pool
            return $this->handlerPool->get($class);
        } catch (\Throwable $e) {
            $this->logger->error("Error creating handler", [
                'handler' => $class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException("Error creating handler: {$e->getMessage()}", 0, $e);
        }
    }
}