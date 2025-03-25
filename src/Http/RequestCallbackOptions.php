<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Http;

use Psr\Http\Message\StreamFactoryInterface;

final class RequestCallbackOptions
{
    private int $responseChunkSize = 2097152; // 2 MB

    private StreamFactoryInterface $streamFactory;

    public function __construct()
    {
        $this->streamFactory = new StreamFactory();
    }

    public function getResponseChunkSize(): int
    {
        return $this->responseChunkSize;
    }

    /**
     * @psalm-api
     */
    public function setResponseChunkSize(int $responseChunkSize): self
    {
        $this->responseChunkSize = $responseChunkSize;
        return $this;
    }

    public function getStreamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory;
    }

    /**
     * @psalm-api
     */
    public function setStreamFactory(StreamFactoryInterface $streamFactory): self
    {
        $this->streamFactory = $streamFactory;
        return $this;
    }
}