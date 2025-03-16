<?php
/*
 * This file is part of ODY framework
 *
 * @link https://ody.dev
 * @documentation https://ody.dev/docs
 * @license https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Tests\Unit\Foundation\Http;

use Nyholm\Psr7\ServerRequest;
use Ody\Foundation\Http\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

class RequestTest extends TestCase
{
    /**
     * @var Request
     */
    protected $request;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a PSR-7 ServerRequest
        $psr7Request = new ServerRequest(
            'GET',
            'https://example.com/test?q=search',
            ['Content-Type' => 'application/json'],
            '{"name":"John"}',
            '1.1',
            [
                'REMOTE_ADDR' => '127.0.0.1',
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'example.com',
            ]
        );

        // Create our Request wrapper
        $this->request = new Request($psr7Request);
    }

    public function testImplementsPsr7ServerRequestInterface()
    {
        $this->assertInstanceOf(ServerRequestInterface::class, $this->request);
    }

    public function testGetMethod()
    {
        $this->assertEquals('GET', $this->request->getMethod());
    }

    public function testGetUri()
    {
        $this->assertInstanceOf(UriInterface::class, $this->request->getUri());
        $this->assertEquals('/test', $this->request->getPath());
        $this->assertEquals('https://example.com/test?q=search', $this->request->getUriString());
    }

    public function testGetPsrRequest()
    {
        $this->assertInstanceOf(ServerRequestInterface::class, $this->request->getPsrRequest());
    }

    public function testRawContent()
    {
        $this->assertEquals('{"name":"John"}', $this->request->rawContent());
    }

    public function testJsonMethod()
    {
        $json = $this->request->json();
        $this->assertIsArray($json);
        $this->assertEquals('John', $json['name']);
    }

    public function testInputMethod()
    {
        // Add query params
        $request = $this->request->withQueryParams(['page' => '1']);
        $this->assertEquals('1', $request->input('page'));

        // Add body params
        $request = $this->request->withParsedBody(['email' => 'john@example.com']);
        $this->assertEquals('john@example.com', $request->input('email'));

        // Test default value
        $this->assertEquals('default', $request->input('nonexistent', 'default'));
    }

    public function testAllMethod()
    {
        $request = $this->request
            ->withQueryParams(['page' => '1'])
            ->withParsedBody(['email' => 'john@example.com']);

        $all = $request->all();
        $this->assertIsArray($all);
        $this->assertEquals('1', $all['page']);
        $this->assertEquals('john@example.com', $all['email']);
    }

    public function testCreateFromGlobals()
    {
        // This is hard to test completely, but we can check that it creates a valid request object
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/test';
        $_SERVER['HTTP_HOST'] = 'localhost';

        $request = Request::createFromGlobals();
        $this->assertInstanceOf(Request::class, $request);
        $this->assertEquals('GET', $request->getMethod());
    }

    public function testWithRouteParams()
    {
        $this->request->routeParams = ['id' => '123'];
        $this->assertEquals(['id' => '123'], $this->request->routeParams);
    }

    public function testWithMiddlewareParams()
    {
        $this->request->middlewareParams = ['auth' => 'api'];
        $this->assertEquals(['auth' => 'api'], $this->request->middlewareParams);
    }

    public function testPsr7MethodsReturnNewInstances()
    {
        $newRequest = $this->request->withMethod('POST');
        $this->assertNotSame($this->request, $newRequest);
        $this->assertEquals('POST', $newRequest->getMethod());
        $this->assertEquals('GET', $this->request->getMethod());
    }

    public function testHeaderMethods()
    {
        $this->assertTrue($this->request->hasHeader('Content-Type'));
        $this->assertEquals(['application/json'], $this->request->getHeader('Content-Type'));
        $this->assertEquals('application/json', $this->request->getHeaderLine('Content-Type'));

        $newRequest = $this->request->withHeader('X-API-Key', 'abc123');
        $this->assertTrue($newRequest->hasHeader('X-API-Key'));
        $this->assertEquals('abc123', $newRequest->getHeaderLine('X-API-Key'));

        $withoutHeader = $newRequest->withoutHeader('X-API-Key');
        $this->assertFalse($withoutHeader->hasHeader('X-API-Key'));
    }

    public function testAttributeMethods()
    {
        $withAttr = $this->request->withAttribute('user_id', 123);
        $this->assertEquals(123, $withAttr->getAttribute('user_id'));
        $this->assertEquals('default', $withAttr->getAttribute('nonexistent', 'default'));

        $attrs = $withAttr->getAttributes();
        $this->assertArrayHasKey('user_id', $attrs);

        $withoutAttr = $withAttr->withoutAttribute('user_id');
        $this->assertNull($withoutAttr->getAttribute('user_id'));
    }
}