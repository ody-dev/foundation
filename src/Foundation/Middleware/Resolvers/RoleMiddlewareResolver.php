<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware\Resolvers;

use Ody\Foundation\Http\Request;
use Ody\Foundation\Middleware\RoleMiddleware;
use Ody\Foundation\Middleware\Adapters\CallableHandlerAdapter;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolver for role middleware
 */
class RoleMiddlewareResolver implements MiddlewareResolverInterface
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

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
     * Check if this resolver can handle the given middleware name
     *
     * @param string $name
     * @return bool
     */
    public function supports(string $name): bool
    {
        return $name === 'role' || strpos($name, 'role:') === 0;
    }

    /**
     * Resolve middleware to a callable
     *
     * @param string $name
     * @param array $options
     * @return callable
     */
    public function resolve(string $name, array $options = []): callable
    {
        // Extract default role if specified in the name
        $defaultRole = $name === 'role' ? 'user' : substr($name, 5);

        return function (ServerRequestInterface $request, callable $next) use ($defaultRole) {
            // Get role from request parameters or use default
            $role = $request instanceof Request && isset($request->middlewareParams['role'])
                ? $request->middlewareParams['role']
                : $defaultRole;

            // Create the middleware with the resolved role
            $roleMiddleware = new RoleMiddleware($role, $this->logger);

            // Use our adapter instead of anonymous class
            $handler = new CallableHandlerAdapter($next);

            // Process the request
            return $roleMiddleware->process($request, $handler);
        };
    }
}