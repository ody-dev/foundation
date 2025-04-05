<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware;

use Ody\Foundation\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CORS Middleware (PSR-15)
 */
class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @var array CORS configuration
     */
    private array $config;

    /**
     * CorsMiddleware constructor
     *
     * @param array $config CORS configuration
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'origin' => '*',
            'methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'headers' => 'Content-Type, Authorization, X-API-Key',
            'max_age' => 86400, // 24 hours
        ], $config);
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
        // For preflight OPTIONS requests
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response();
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $this->config['origin'])
                ->withHeader('Access-Control-Allow-Methods', $this->config['methods'])
                ->withHeader('Access-Control-Allow-Headers', $this->config['headers'])
                ->withHeader('Access-Control-Max-Age', (string)$this->config['max_age'])
                ->withStatus(204);

            return $response;
        }

        // For regular requests
        $response = $handler->handle($request);

        return $response
            ->withHeader('Access-Control-Allow-Origin', $this->config['origin'])
            ->withHeader('Access-Control-Allow-Methods', $this->config['methods'])
            ->withHeader('Access-Control-Allow-Headers', $this->config['headers']);
    }
}