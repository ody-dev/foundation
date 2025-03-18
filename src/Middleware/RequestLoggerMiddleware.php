<?php

namespace Ody\Foundation\Middleware;

use Ody\Foundation\Middleware\TerminatingMiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class RequestLoggerMiddleware implements MiddlewareInterface, TerminatingMiddlewareInterface
{
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var float Request start time
     */
    private float $startTime;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
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
        // Record the start time
        $this->startTime = microtime(true);

        // Process the request
        return $handler->handle($request);
    }

    /**
     * Handle tasks after the response has been sent
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return void
     */
    public function terminate(ServerRequestInterface $request, ResponseInterface $response): void
    {
        // Calculate request duration
        $duration = microtime(true) - $this->startTime;

        // Log request details asynchronously
        $this->logger->info('Request completed', [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round($duration * 1000, 2),
            'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown'
        ]);

        // You could perform other async operations here:
        // - Update usage statistics
        // - Send metrics to monitoring systems
        // - Perform cleanup
    }
}