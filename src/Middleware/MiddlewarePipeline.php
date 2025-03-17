<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware;

use Ody\Foundation\Middleware\Adapters\CallableHandlerAdapter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Middleware Pipeline
 *
 * Executes a middleware pipeline and produces a response.
 * Implements PSR-15 RequestHandlerInterface for use as the entry point.
 */
class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @var MiddlewareRegistry
     */
    protected MiddlewareRegistry $registry;

    /**
     * @var array List of middleware to execute
     */
    protected array $middleware;

    /**
     * @var RequestHandlerInterface|callable The final handler
     */
    protected $finalHandler;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param MiddlewareRegistry $registry
     * @param array $middleware
     * @param RequestHandlerInterface|callable $finalHandler
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        MiddlewareRegistry $registry,
        array              $middleware,
                           $finalHandler,
        ?LoggerInterface   $logger = null
    )
    {
        $this->registry = $registry;
        $this->middleware = $middleware;
        $this->finalHandler = $finalHandler;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Process the request through the middleware pipeline
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // If no middleware, just execute the final handler
        if (empty($this->middleware)) {
            return $this->executeFinalHandler($request);
        }

        // Create a handler stack
        return $this->createHandlerStack($request);
    }

    /**
     * Execute the final handler
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    protected function executeFinalHandler(ServerRequestInterface $request): ResponseInterface
    {
        $this->logger->debug("No middleware in pipeline, executing final handler directly");

        $handler = $this->getFinalHandler();
        return $handler->handle($request);
    }

    /**
     * Get the final handler as a RequestHandlerInterface
     *
     * @return RequestHandlerInterface
     */
    protected function getFinalHandler(): RequestHandlerInterface
    {
        if ($this->finalHandler instanceof RequestHandlerInterface) {
            return $this->finalHandler;
        }

        if (is_callable($this->finalHandler)) {
            return new CallableHandlerAdapter($this->finalHandler);
        }

        // Default handler just in case
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('No final handler defined for middleware pipeline');
            }
        };
    }

    /**
     * Create and execute the handler stack
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    protected function createHandlerStack(ServerRequestInterface $request): ResponseInterface
    {
        // Take a copy of the middleware stack (we'll be processing it in order)
        $stack = array_values($this->middleware);

        // Start with the final handler at the bottom of the stack
        $handler = $this->getFinalHandler();

        // Wrap handlers from bottom to top (last to first middleware)
        foreach (array_reverse($stack) as $middlewareKey => $middleware) {
            $index = count($stack) - $middlewareKey - 1; // Reverse index for logging
            $handler = $this->createLayerHandler($middleware, $handler, $index);
        }

        // Execute the stack from the top
        return $handler->handle($request);
    }

    /**
     * Create a handler for a single middleware layer
     *
     * @param mixed $middleware
     * @param RequestHandlerInterface $next
     * @param int $index
     * @return RequestHandlerInterface
     */
    protected function createLayerHandler($middleware, RequestHandlerInterface $next, int $index): RequestHandlerInterface
    {
        // Resolve the middleware instance
        try {
            $instance = $this->registry->resolve($middleware);

            // Return a handler that processes this middleware
            return new class($instance, $next, $this->logger, $index) implements RequestHandlerInterface {
                private MiddlewareInterface $middleware;
                private RequestHandlerInterface $next;
                private LoggerInterface $logger;
                private int $index;

                public function __construct(
                    MiddlewareInterface     $middleware,
                    RequestHandlerInterface $next,
                    LoggerInterface         $logger,
                    int                     $index
                )
                {
                    $this->middleware = $middleware;
                    $this->next = $next;
                    $this->logger = $logger;
                    $this->index = $index;
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $middlewareName = get_class($this->middleware);
                    $this->logger->debug("Executing middleware [{$this->index}]: {$middlewareName}");

                    try {
                        return $this->middleware->process($request, $this->next);
                    } catch (\Throwable $e) {
                        $this->logger->error("Error in middleware {$middlewareName}", [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        throw $e;
                    }
                }
            };
        } catch (\Throwable $e) {
            logger()->error("Failed to create middleware layer", [
                'middleware' => is_string($middleware) ? $middleware : gettype($middleware),
                'error' => $e->getMessage()
            ]);

            // Return a pass-through handler that skips this middleware
            return new class($next, $this->logger, $index, $e) implements RequestHandlerInterface {
                private RequestHandlerInterface $next;
                private LoggerInterface $logger;
                private int $index;
                private \Throwable $error;

                public function __construct(
                    RequestHandlerInterface $next,
                    LoggerInterface         $logger,
                    int                     $index,
                    \Throwable              $error
                )
                {
                    $this->next = $next;
                    $this->logger = $logger;
                    $this->index = $index;
                    $this->error = $error;
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $this->logger->warning("Skipping middleware [{$this->index}] due to error", [
                        'error' => $this->error->getMessage()
                    ]);

                    // Pass through to next handler
                    return $this->next->handle($request);
                }
            };
        }
    }

    /**
     * Returns the middleware in this pipeline
     *
     * @return array
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }
}