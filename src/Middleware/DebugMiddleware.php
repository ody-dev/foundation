<?php

namespace Ody\Foundation\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Debug Middleware
 *
 * Helps with debugging the middleware pipeline by logging information about the request
 * and showing what middleware is being executed.
 */
class DebugMiddleware implements MiddlewareInterface
{
    /**
     * @var string An identifier for this instance
     */
    protected $id;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Create a new debug middleware instance.
     *
     * @param string $id An identifier for this middleware instance
     * @param LoggerInterface|null $logger
     */
    public function __construct(string $id = 'debug', ?LoggerInterface $logger = null)
    {
        $this->id = $id;
        $this->logger = $logger ?? (function_exists('app') ? app(LoggerInterface::class) : new NullLogger());
    }

    /**
     * Process an incoming server request.
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Log that we're entering this middleware
        $this->logger->info("➡️ ENTERING middleware: {$this->id}", [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ]);

        // Process the request
        $response = $handler->handle($request);

        // Log that we're exiting this middleware
        $this->logger->info("⬅️ EXITING middleware: {$this->id}", [
            'status' => $response->getStatusCode()
        ]);

        return $response;
    }
}