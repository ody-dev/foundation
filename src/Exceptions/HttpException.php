<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Exceptions;

use Exception;

class HttpException extends Exception
{
    /**
     * @var int HTTP status code
     */
    protected int $statusCode = 500;

    /**
     * @var string Error title
     */
    protected string $title = 'Server Error';

    /**
     * @var array Additional headers
     */
    protected array $headers = [];

    /**
     * Constructor
     *
     * @param string $message
     * @param int $statusCode
     * @param array $headers
     * @param string|null $title
     * @param int $code
     * @param Exception|null $previous
     */
    public function __construct(
        string $message = 'Server Error',
        int $statusCode = 500,
        array $headers = [],
        ?string $title = null,
        int $code = 0,
        Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        $this->statusCode = $statusCode;
        $this->headers = $headers;

        if ($title !== null) {
            $this->title = $title;
        }
    }

    /**
     * Get the status code
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get the error title
     *
     * @return string
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Get the headers
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}