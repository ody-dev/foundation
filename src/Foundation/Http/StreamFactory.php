<?php

namespace Ody\Foundation\Http;

use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use function fopen;
use function fwrite;
use function rewind;

class StreamFactory implements StreamFactoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function createStream(string $content = ''): StreamInterface
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $content);
        rewind($resource);

        var_dump($this->createStreamFromResource($resource));

        return $this->createStreamFromResource($resource);
    }

    /**
     * {@inheritDoc}
     */
    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return new Stream($filename, $mode);
    }

    /**
     * {@inheritDoc}
     */
    public function createStreamFromResource($resource): StreamInterface
    {
        return new Stream($resource);
    }
}