<?php
/*
 * This file is part of ODY framework
 *
 * @link https://ody.dev
 * @documentation https://ody.dev/docs
 * @license https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Tests\Unit\Foundation\Middleware;

use Ody\Container\Container;
use Ody\Foundation\Http\Request;
use Ody\Foundation\Http\Response;
use Ody\Foundation\Middleware\AuthMiddleware;
use Ody\Foundation\Middleware\CorsMiddleware;
use Ody\Foundation\Middleware\JsonBodyParserMiddleware;
use Ody\Foundation\Middleware\MiddlewareRegistry;
use Ody\Foundation\Middleware\ThrottleMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;

class MiddlewareRegistryTest extends TestCase
{
    /**
     * @var MiddlewareRegistry
     */
    protected $registry;

    /**
     * @var Container
     */
    protected $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container();
        $this->registry = new MiddlewareRegistry($this->container, new NullLogger());
    }

    public function testAddNamedMiddleware()
    {
        $middleware = new JsonBodyParserMiddleware();
        $this->registry->add('json', $middleware);

        $this->assertTrue($this->registry->has('json'));
        $this->assertSame($middleware, $this->registry->get('json'));
    }

    public function testAddGlobalMiddleware()
    {
        $middleware = new JsonBodyParserMiddleware();
        $this->registry->addGlobal($middleware);

        $middlewareList = $this->registry->getGlobalMiddleware();
        $this->assertContains($middleware, $middlewareList);
    }

    public function testAddToRoute()
    {
        $middleware = new JsonBodyParserMiddleware();
        $this->registry->addToRoute('GET', '/api/users', $middleware);

        $middlewareList = $this->registry->getMiddlewareForRoute('GET', '/api/users');
        $this->assertContains($middleware, $middlewareList);
    }

    public function testAddToPattern()
    {
        $middleware = new JsonBodyParserMiddleware();
        $this->registry->addToPattern('GET:/api/*', $middleware);

        // This should match the pattern
        $middlewareList = $this->registry->getMiddlewareForRoute('GET', '/api/users');
        $this->assertContains($middleware, $middlewareList);

        // This should not match the pattern
        $otherList = $this->registry->getMiddlewareForRoute('POST', '/other/path');
        $this->assertNotContains($middleware, $otherList);
    }

    public function testAddGroup()
    {
        $middleware1 = new JsonBodyParserMiddleware();
        $middleware2 = new CorsMiddleware();

        $this->registry->addGroup('api', [$middleware1, $middleware2]);

        $groups = $this->registry->getMiddlewareGroups();
        $this->assertArrayHasKey('api', $groups);
        $this->assertCount(2, $groups['api']);
    }

    public function testWithParameters()
    {
        $this->registry->add('auth', new AuthMiddleware());
        $this->registry->withParameters('auth', ['guard' => 'api']);

        $params = $this->registry->getParameters('auth');
        $this->assertEquals(['guard' => 'api'], $params);
    }

    public function testResolveMiddleware()
    {
        // Resolve PSR-15 middleware
        $middleware = new JsonBodyParserMiddleware();
        $resolved = $this->registry->resolveMiddleware($middleware);
        $this->assertSame($middleware, $resolved);

        // Resolve closure middleware
        $closure = function ($request, $handler) {
            return $handler->handle($request);
        };
        $resolved = $this->registry->resolveMiddleware($closure);
        $this->assertInstanceOf(MiddlewareInterface::class, $resolved);

        // Resolve named middleware
        $this->registry->add('json', new JsonBodyParserMiddleware());
        $resolved = $this->registry->resolveMiddleware('json');
        $this->assertInstanceOf(JsonBodyParserMiddleware::class, $resolved);

        // Resolve from container
        $this->container->bind(JsonBodyParserMiddleware::class, function () {
            return new JsonBodyParserMiddleware();
        });
        $resolved = $this->registry->resolveMiddleware(JsonBodyParserMiddleware::class);
        $this->assertInstanceOf(JsonBodyParserMiddleware::class, $resolved);
    }

    public function testProcess()
    {
        // Create middleware that adds a header
        $middleware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);
                return $response->withHeader('X-Test', 'Middleware-Test');
            }
        };

        $this->registry->addGlobal($middleware);

        // Create a test request and handler
        $request = new Request(new \Nyholm\Psr7\ServerRequest('GET', '/'));
        $handler = function (ServerRequestInterface $request) {
            return new Response();
        };

        // Process the request through middleware
        $response = $this->registry->process($request, $handler);

        // Check that the middleware executed
        $this->assertTrue($response->hasHeader('X-Test'));
        $this->assertEquals('Middleware-Test', $response->getHeaderLine('X-Test'));
    }

    public function testParameterizedMiddleware()
    {
        // Add a middleware with a parameter
        $this->registry->add('throttle', ThrottleMiddleware::class);
        $result = $this->registry->resolveMiddleware('throttle:60,1');

        $this->assertInstanceOf(MiddlewareInterface::class, $result);

        // Check the parameter was stored
        $params = $this->registry->getParameters('throttle');
        $this->assertEquals(['value' => '60,1'], $params);
    }

    public function testMiddlewareOrder()
    {
        // Create middleware that adds a header with order information
        $middleware1 = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);
                return $response->withHeader('X-Order', 'First');
            }
        };

        $middleware2 = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);

                // This should override the first middleware's header
                // if middleware execution order is correct
                return $response->withHeader('X-Order', 'Second');
            }
        };

        // Add middleware in order
        $this->registry->addGlobal($middleware1);
        $this->registry->addGlobal($middleware2);

        // Test handler
        $handler = function (ServerRequestInterface $request) {
            return new Response();
        };

        // Process
        $request = new Request(new \Nyholm\Psr7\ServerRequest('GET', '/'));
        $response = $this->registry->process($request, $handler);

        // Since middleware stack is processed in reverse order (LIFO),
        // the first added middleware should execute last
        $this->assertEquals('First', $response->getHeaderLine('X-Order'));
    }
}