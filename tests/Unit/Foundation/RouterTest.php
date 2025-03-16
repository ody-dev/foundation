<?php
/*
 * This file is part of ODY framework
 *
 * @link https://ody.dev
 * @documentation https://ody.dev/docs
 * @license https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Tests\Unit\Foundation;

use Ody\Container\Container;
use Ody\Foundation\Middleware\MiddlewareRegistry;
use Ody\Foundation\Router;
use Tests\TestCase;

class RouterTest extends TestCase
{
    /**
     * @var Router
     */
    protected $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container();
        $middlewareRegistry = new MiddlewareRegistry($this->container);
        $this->router = new Router($this->container, $middlewareRegistry);
    }

    public function testRegisterBasicRoute()
    {
        $route = $this->router->get('/test', function () {
            return 'Hello World';
        });

        $this->assertEquals('GET', $route->getMethod());
        $this->assertEquals('/test', $route->getPath());
    }

    public function testRegisterMultipleRoutes()
    {
        $this->router->get('/users', 'UsersController@index');
        $this->router->post('/users', 'UsersController@store');
        $this->router->put('/users/{id}', 'UsersController@update');
        $this->router->delete('/users/{id}', 'UsersController@destroy');

        // Test route matching
        $getMatch = $this->router->match('GET', '/users');
        $this->assertEquals('found', $getMatch['status']);

        $postMatch = $this->router->match('POST', '/users');
        $this->assertEquals('found', $postMatch['status']);

        $putMatch = $this->router->match('PUT', '/users/1');
        $this->assertEquals('found', $putMatch['status']);
        $this->assertEquals(['id' => '1'], $putMatch['vars']);

        $deleteMatch = $this->router->match('DELETE', '/users/1');
        $this->assertEquals('found', $deleteMatch['status']);
        $this->assertEquals(['id' => '1'], $deleteMatch['vars']);
    }

    public function testMatchNotFound()
    {
        $this->router->get('/users', function () {});

        $match = $this->router->match('GET', '/not-found');
        $this->assertEquals('not_found', $match['status']);
    }

    public function testMatchMethodNotAllowed()
    {
        $this->router->get('/users', function () {});

        $match = $this->router->match('POST', '/users');
        $this->assertEquals('method_not_allowed', $match['status']);
        $this->assertContains('GET', $match['allowed_methods']);
    }

    public function testRouteWithPattern()
    {
        $this->router->get('/users/{id:\\d+}', function ($request, $response, $args) {
            return $args['id'];
        });

        // Match with digit
        $match = $this->router->match('GET', '/users/123');
        $this->assertEquals('found', $match['status']);
        $this->assertEquals(['id' => '123'], $match['vars']);

        // Doesn't match with non-digit
        $match = $this->router->match('GET', '/users/abc');
        $this->assertEquals('not_found', $match['status']);
    }

    public function testRouteGroupWithPrefix()
    {
        $this->router->group(['prefix' => '/api'], function ($router) {
            $router->get('/users', function () {
                return 'API Users';
            });
        });

        // Should match with prefix
        $match = $this->router->match('GET', '/api/users');
        $this->assertEquals('found', $match['status']);

        // Shouldn't match without prefix
        $match = $this->router->match('GET', '/users');
        $this->assertEquals('not_found', $match['status']);
    }

    public function testRouteMiddleware()
    {
        $route = $this->router->get('/protected', function () {})
            ->middleware('auth')
            ->middleware('throttle');

        $middlewares = $this->router->getMiddlewareRegistry()
            ->getMiddlewareForRoute('GET', '/protected');

        $this->assertCount(2, $middlewares);
    }

    public function testNestedRouteGroups()
    {
        $this->router->group(['prefix' => '/api'], function ($router) {
            $router->group(['prefix' => '/v1'], function ($router) {
                $router->get('/users', function () {
                    return 'API v1 Users';
                });
            });
        });

        $match = $this->router->match('GET', '/api/v1/users');
        $this->assertEquals('found', $match['status']);
    }

    public function testRouteGroupWithMiddleware()
    {
        $this->router->group(['middleware' => ['auth', 'throttle']], function ($router) {
            $router->get('/dashboard', function () {});
        });

        $middlewares = $this->router->getMiddlewareRegistry()
            ->getMiddlewareForRoute('GET', '/dashboard');

        $this->assertCount(2, $middlewares);
    }

    public function testControllerRouteResolution()
    {
        // Define a test controller class
        $this->container->bind('TestController', function () {
            return new class {
                public function index($request, $response) {
                    return 'Controller Index';
                }
            };
        });

        $this->router->get('/controller-test', 'TestController@index');

        $match = $this->router->match('GET', '/controller-test');
        $this->assertEquals('found', $match['status']);

        // Call the handler to ensure it resolves
        $handler = $match['handler'];
        $this->assertIsCallable($handler);
    }

    public function testRegisterOptionsRoute()
    {
        $route = $this->router->options('/cors-test', function () {
            return 'CORS Response';
        });

        $this->assertEquals('OPTIONS', $route->getMethod());
        $this->assertEquals('/cors-test', $route->getPath());

        $match = $this->router->match('OPTIONS', '/cors-test');
        $this->assertEquals('found', $match['status']);
    }

    public function testRegisterPatchRoute()
    {
        $route = $this->router->patch('/patch-test', function () {
            return 'Patch Response';
        });

        $this->assertEquals('PATCH', $route->getMethod());
        $this->assertEquals('/patch-test', $route->getPath());

        $match = $this->router->match('PATCH', '/patch-test');
        $this->assertEquals('found', $match['status']);
    }
}