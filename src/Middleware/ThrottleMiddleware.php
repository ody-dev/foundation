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
 * Rate Limiting/Throttling Middleware (PSR-15)
 */
class ThrottleMiddleware implements MiddlewareInterface
{
    /**
     * @var int Default maximum requests
     */
    private int $maxRequests;

    /**
     * @var int Default time window in minutes
     */
    private int $minutes;

    /**
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger;

    /**
     * ThrottleMiddleware constructor
     *
     * @param int $defaultMaxRequests
     * @param int $defaultMinutes
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        int $maxRequests = 60,
        int $minutes = 1,
        ?LoggerInterface $logger = null
    ) {
        $this->maxRequests = $maxRequests;
        $this->minutes = $minutes;
        $this->logger = $logger ?? (function_exists('app') ? app(LoggerInterface::class) : null);
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
        // Get rate limits from request attributes or use defaults
        $maxRequests = $this->maxRequests;
        $minutes = $this->minutes;

        if ($this->logger) {
            $this->logger->debug("Throttle middleware using limits: {$maxRequests} requests per {$minutes} minute(s)");
        }

        // In a real implementation, you would check a database or cache for rate limiting
        // This is a simplified example

        // TODO: Implement rate limiting

        // Process the request
        $response = $handler->handle($request);

        // Add rate limit headers (for demonstration)
        return $response
            ->withHeader('X-RateLimit-Limit', $maxRequests)
            ->withHeader('X-RateLimit-Remaining', $maxRequests - 1)
            ->withHeader('X-RateLimit-Reset', time() + ($minutes * 60));
    }
}