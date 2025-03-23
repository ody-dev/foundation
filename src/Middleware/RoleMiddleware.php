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
 * Role-based Access Control Middleware (PSR-15)
 */
class RoleMiddleware implements MiddlewareInterface
{
    /**
     * @var string Default required role
     */
    private string $defaultRole;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * RoleMiddleware constructor
     *
     * @param string $defaultRole
     * @param LoggerInterface|null $logger
     */
    public function __construct(string $defaultRole = 'user', ?LoggerInterface $logger = null)
    {
        $this->defaultRole = $defaultRole;
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
        // Get required role from request attribute or use default
        $requiredRole = $request->getAttribute('middleware_requiredRole', $this->defaultRole);

        $this->logger->debug("Role middleware checking for role: {$requiredRole}");

        // Get the user role (in a real app, fetch from JWT token or session)
        // This is a simplified example
        $userRole = 'admin'; // Example user role

        if ($userRole === $requiredRole) {
            return $handler->handle($request);
        }

        $this->logger->warning('Insufficient permissions', [
            'required_role' => $requiredRole,
            'user_role' => $userRole,
            'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown'
        ]);

        $response = new Response();
        return $response
            ->withStatus(403)
            ->json()
            ->withJson([
                'error' => 'Forbidden - Insufficient permissions',
                'required_role' => $requiredRole
            ]);
    }
}