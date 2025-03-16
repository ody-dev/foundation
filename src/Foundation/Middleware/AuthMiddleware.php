<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware;

use Ody\Foundation\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Authentication Middleware (PSR-15)
 */
class AuthMiddleware implements MiddlewareInterface
{
    /**
     * @var string The default guard type (e.g., 'api', 'jwt', 'web')
     */
    private string $defaultGuard;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * AuthMiddleware constructor
     *
     * @param string $defaultGuard
     * @param LoggerInterface|null $logger
     */
    public function __construct(string $defaultGuard = 'web', ?LoggerInterface $logger = null)
    {
        $this->defaultGuard = $defaultGuard;
        $this->logger = $logger ?? app(LoggerInterface::class);
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
        // Get the guard from request attribute or use the default
        $guard = $request->getAttribute('middleware_guard', $this->defaultGuard);

        // Log which guard we're using
        $this->logger->debug("Auth middleware using guard: {$guard}");

        $authHeader = $request->getHeaderLine('Authorization');

        // Based on the guard type, implement different authentication strategies
        if ($guard === 'api') {
            // API token authentication
            if (strpos($authHeader, 'Bearer ') === 0) {
                $token = substr($authHeader, 7);
                if ($token === 'valid-api-token') {
                    return $handler->handle($request);
                }
            }
        } elseif ($guard === 'jwt') {
            // JWT authentication
            if (strpos($authHeader, 'Bearer ') === 0) {
                $token = substr($authHeader, 7);
                // Verify JWT token (simplified)
                if (strpos($token, 'jwt-') === 0) {
                    return $handler->handle($request);
                }
            }
        } else {
            // Regular authentication (web)
            if (strpos($authHeader, 'Bearer ') === 0) {
                $token = substr($authHeader, 7);
                if ($token === 'valid-token') {
                    return $handler->handle($request);
                }
            }
        }

        // Authentication failed
        $this->logger->warning('Unauthorized access attempt', [
            'guard' => $guard,
            'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown'
        ]);

        $response = new Response();
        return $response
            ->withStatus(401)
            ->json()
            ->withJson([
                'error' => 'Unauthorized',
                'guard' => $guard
            ]);
    }
}