<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as PsrResponse;

/**
 * PSR-7 compatible HTTP Response
 */
class Response implements ResponseInterface
{
    /**
     * @var ResponseInterface PSR-7 response
     */
    private ResponseInterface $psrResponse;

    /**
     * @var bool Whether the response has been sent
     */
    private bool $sent = false;

    /**
     * Response constructor
     *
     * @param ResponseInterface|null $response
     */
    public function __construct(?ResponseInterface $response = null)
    {
        $this->psrResponse = $response ?? new PsrResponse();
    }

    /**
     * Set HTTP status code
     *
     * @param int $statusCode
     * @return self
     */
    public function status(int $statusCode): self
    {
        $new = clone $this;
        $new->psrResponse = $this->psrResponse->withStatus($statusCode);
        return $new;
    }

    /**
     * Set response header
     *
     * @param string $name
     * @param string $value
     * @return self
     */
    public function header(string $name, string $value): self
    {
        $new = clone $this;
        $new->psrResponse = $this->psrResponse->withHeader($name, $value);
        return $new;
    }

    /**
     * Set content type
     *
     * @param string $contentType
     * @return self
     */
    public function contentType(string $contentType): self
    {
        return $this->header('Content-Type', $contentType);
    }

    /**
     * Set JSON content type
     *
     * @return self
     */
    public function json(): self
    {
        return $this->contentType('application/json');
    }

    /**
     * Set plain text content type
     *
     * @return self
     */
    public function text(): self
    {
        return $this->contentType('text/plain');
    }

    /**
     * Set HTML content type
     *
     * @return self
     */
    public function html(): self
    {
        return $this->contentType('text/html');
    }

    /**
     * Set response body
     *
     * @param string $content
     * @return self
     */
    public function body(string $content): self
    {
        $factory = new Psr17Factory();
        $body = $factory->createStream($content);

        $new = clone $this;
        $new->psrResponse = $this->psrResponse->withBody($body);
        return $new;
    }

    /**
     * Set JSON response
     *
     * @param mixed $data
     * @param int $options JSON encoding options
     * @return self
     */
    public function withJson($data, int $options = 0): self
    {
        $json = json_encode($data, $options);

        return $this
            ->json()
            ->body($json);
    }

    /**
     * End the response
     *
     * @param string|null $content
     * @return void
     */
    public function end(?string $content = null): void
    {
        if ($content !== null) {
            $this->body($content);
        }

        $this->send();
    }

    /**
     * Send the response
     *
     * @return void
     */
    public function send(): void
    {
        if ($this->sent) {
            return;
        }

        // Set status code
        http_response_code($this->psrResponse->getStatusCode());

        // Set headers
        foreach ($this->psrResponse->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value), false);
            }
        }

        // Output body
        echo (string) $this->psrResponse->getBody();

        $this->sent = true;
    }

    /**
     * Check if response has been sent
     *
     * @return bool
     */
    public function isSent(): bool
    {
        return $this->sent;
    }

    /**
     * Get response body
     *
     * @return string
     */
    public function getBodyAsString(): string
    {
        return (string) $this->psrResponse->getBody();
    }

    /**
     * Get status code
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->psrResponse->getStatusCode();
    }

    /* PSR-7 ResponseInterface methods */

    public function getProtocolVersion(): string
    {
        return $this->psrResponse->getProtocolVersion();
    }

    public function withProtocolVersion($version): \Psr\Http\Message\MessageInterface
    {
        $new = clone $this;
        $new->psrResponse = $this->psrResponse->withProtocolVersion($version);
        return $new;
    }

    public function getHeaders(): array
    {
        return $this->psrResponse->getHeaders();
    }

    public function hasHeader($name): bool
    {
        return $this->psrResponse->hasHeader($name);
    }

    public function getHeader($name): array
    {
        return $this->psrResponse->getHeader($name);
    }

    public function getHeaderLine($name): string
    {
        return $this->psrResponse->getHeaderLine($name);
    }

    public function withHeader($name, $value): \Psr\Http\Message\MessageInterface
    {
        $new = clone $this;
        $new->psrResponse = $this->psrResponse->withHeader($name, $value);
        return $new;
    }

    public function withAddedHeader($name, $value): \Psr\Http\Message\MessageInterface
    {
        $new = clone $this;
        $new->psrResponse = $this->psrResponse->withAddedHeader($name, $value);
        return $new;
    }

    public function withoutHeader($name): \Psr\Http\Message\MessageInterface
    {
        $new = clone $this;
        $new->psrResponse = $this->psrResponse->withoutHeader($name);
        return $new;
    }

    public function getBody(): StreamInterface
    {
        return $this->psrResponse->getBody();
    }

    public function withBody(StreamInterface $body): \Psr\Http\Message\MessageInterface
    {
        $new = clone $this;
        $new->psrResponse = $this->psrResponse->withBody($body);
        return $new;
    }

    public function withStatus($code, $reasonPhrase = ''): ResponseInterface
    {
        $new = clone $this;
        $new->psrResponse = $this->psrResponse->withStatus($code, $reasonPhrase);
        return $new;
    }

    public function getReasonPhrase(): string
    {
        return $this->psrResponse->getReasonPhrase();
    }

    /**
     * Get the underlying PSR-7 response
     *
     * @return ResponseInterface
     */
    public function getPsrResponse(): ResponseInterface
    {
        return $this->psrResponse;
    }
}