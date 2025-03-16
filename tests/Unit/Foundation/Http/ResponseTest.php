<?php
/*
 * This file is part of ODY framework
 *
 * @link https://ody.dev
 * @documentation https://ody.dev/docs
 * @license https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Tests\Unit\Foundation\Http;

use Ody\Foundation\Http\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class ResponseTest extends TestCase
{
    /**
     * @var Response
     */
    protected $response;

    protected function setUp(): void
    {
        parent::setUp();
        $this->response = new Response();
    }

    public function testImplementsPsr7ResponseInterface()
    {
        $this->assertInstanceOf(ResponseInterface::class, $this->response);
    }

    public function testDefaultValues()
    {
        // Default status code should be 200
        $this->assertEquals(200, $this->response->getStatusCode());

        // Default reason phrase should be OK
        $this->assertEquals('OK', $this->response->getReasonPhrase());

        // Body should be empty by default
        $this->assertEquals('', (string)$this->response->getBody());
    }

    public function testWithStatus()
    {
        $response = $this->response->status(404);

        // Original should be unchanged
        $this->assertEquals(200, $this->response->getStatusCode());

        // New response should have the new status
        $this->assertEquals(404, $response->getStatusCode());
        $this->assertEquals('Not Found', $response->getReasonPhrase());
    }

    public function testWithHeader()
    {
        $response = $this->response->header('X-Test', 'Value');

        // Original should be unchanged
        $this->assertFalse($this->response->hasHeader('X-Test'));

        // New response should have the header
        $this->assertTrue($response->hasHeader('X-Test'));
        $this->assertEquals('Value', $response->getHeaderLine('X-Test'));
    }

    public function testContentTypeMethods()
    {
        $jsonResponse = $this->response->json();
        $this->assertEquals('application/json', $jsonResponse->getHeaderLine('Content-Type'));

        $textResponse = $this->response->text();
        $this->assertEquals('text/plain', $textResponse->getHeaderLine('Content-Type'));

        $htmlResponse = $this->response->html();
        $this->assertEquals('text/html', $htmlResponse->getHeaderLine('Content-Type'));

        $customResponse = $this->response->contentType('application/xml');
        $this->assertEquals('application/xml', $customResponse->getHeaderLine('Content-Type'));
    }

    public function testBody()
    {
        $response = $this->response->body('Hello World');

        // Original should be unchanged
        $this->assertEquals('', (string)$this->response->getBody());

        // New response should have the body
        $this->assertEquals('Hello World', (string)$response->getBody());
    }

    public function testWithJson()
    {
        $data = ['name' => 'John', 'age' => 30];
        $response = $this->response->withJson($data);

        // Check content type
        $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));

        // Check body
        $this->assertEquals(json_encode($data), (string)$response->getBody());
    }

    public function testMultipleModifications()
    {
        $response = $this->response
            ->status(201)
            ->header('X-API-Key', 'abc123')
            ->json()
            ->body('{"success":true}');

        $this->assertEquals(201, $response->getStatusCode());
        $this->assertEquals('abc123', $response->getHeaderLine('X-API-Key'));
        $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertEquals('{"success":true}', (string)$response->getBody());
    }

    public function testGetPsrResponse()
    {
        $this->assertInstanceOf(ResponseInterface::class, $this->response->getPsrResponse());
    }

    public function testGetBodyAsString()
    {
        $response = $this->response->body('Test Content');
        $this->assertEquals('Test Content', $response->getBodyAsString());
    }

    public function testWithJsonWithOptions()
    {
        $data = ['name' => 'John & Doe'];

        // Default JSON encoding
        $response1 = $this->response->withJson($data);
        $this->assertEquals('{"name":"John & Doe"}', (string)$response1->getBody());

        // With JSON_HEX_AMP option
        $response2 = $this->response->withJson($data, JSON_HEX_AMP);
        $this->assertEquals('{"name":"John \\u0026 Doe"}', (string)$response2->getBody());
    }

    public function testPsr7MethodsReturnNewInstances()
    {
        $newResponse = $this->response->withStatus(404);
        $this->assertNotSame($this->response, $newResponse);
        $this->assertEquals(404, $newResponse->getStatusCode());
        $this->assertEquals(200, $this->response->getStatusCode());
    }

    public function testHeaderMethods()
    {
        $response = $this->response->withHeader('X-API-Key', 'abc123');

        $this->assertTrue($response->hasHeader('X-API-Key'));
        $this->assertEquals(['abc123'], $response->getHeader('X-API-Key'));
        $this->assertEquals('abc123', $response->getHeaderLine('X-API-Key'));

        $response2 = $response->withAddedHeader('X-API-Key', 'def456');
        $this->assertEquals(['abc123', 'def456'], $response2->getHeader('X-API-Key'));

        $response3 = $response2->withoutHeader('X-API-Key');
        $this->assertFalse($response3->hasHeader('X-API-Key'));
    }

    public function testProtocolVersion()
    {
        $this->assertEquals('1.1', $this->response->getProtocolVersion());

        $response = $this->response->withProtocolVersion('2.0');
        $this->assertEquals('2.0', $response->getProtocolVersion());
    }
}