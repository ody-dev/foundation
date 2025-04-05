<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Exceptions;

class ValidationException extends HttpException
{
    /**
     * @var array Validation errors
     */
    protected array $errors = [];

    /**
     * Constructor
     *
     * @param array $errors
     * @param string $message
     * @param array $headers
     */
    public function __construct(
        array $errors = [],
        string $message = 'The given data was invalid',
        array $headers = []
    ) {
        parent::__construct($message, 422, $headers, 'Validation Error');

        $this->errors = $errors;
    }

    /**
     * Get validation errors
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Create a new validation exception from errors array
     *
     * @param array $errors
     * @return static
     */
    public static function withErrors(array $errors): self
    {
        return new static($errors);
    }
}