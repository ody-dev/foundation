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

/**
 * Rate Limiting/Throttling Middleware (PSR-15)
 */
class ThrottleMiddleware implements MiddlewareInterface
{
    /**
     * @var int Maximum requests
     */
    private int $maxRequests;

    /**
     * @var int Time window in minutes
     */
    private int $minutes;

    /**
     * ThrottleMiddleware constructor
     *
     * @param int $maxRequests
     * @param int $minutes
     */
    public function __construct(int $maxRequests = 60, int $minutes = 1)
    {
        $this->maxRequests = $maxRequests;
        $this->minutes = $minutes;
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
        // In a real implementation, you would check a database or cache for rate limiting
        // This is a simplified example

        // Process the request
        $response = $handler->handle($request);

        // Add rate limit headers (for demonstration)
        return $response
            ->withHeader('X-RateLimit-Limit', $this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', $this->maxRequests - 1)
            ->withHeader('X-RateLimit-Reset', time() + ($this->minutes * 60));
    }
}