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
use Ody\Foundation\Middleware\ThrottleMiddleware;
use Ody\Foundation\Middleware\Adapters\CallableHandlerAdapter;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolver for throttle middleware
 */
class ThrottleMiddlewareResolver implements MiddlewareResolverInterface
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
        return $name === 'throttle' || strpos($name, 'throttle:') === 0;
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
        // Extract default rate if specified in the name
        $defaultRate = $name === 'throttle' ? '60,1' : substr($name, 9);

        return function (ServerRequestInterface $request, callable $next) use ($defaultRate) {
            // Get rate from request parameters or use default
            $rate = $request instanceof Request && isset($request->middlewareParams['throttle'])
                ? $request->middlewareParams['throttle']
                : $defaultRate;

            // Parse rate configuration
            list($maxAttempts, $minutes) = explode(',', $rate);

            // Create throttle middleware with parsed configuration
            $throttleMiddleware = new ThrottleMiddleware((int)$maxAttempts, (int)$minutes);

            // Use our adapter instead of anonymous class
            $handler = new CallableHandlerAdapter($next);

            // Process the request
            return $throttleMiddleware->process($request, $handler);
        };
    }
}