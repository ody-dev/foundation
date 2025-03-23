<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Exceptions;

/**
 * Commonly used HTTP exceptions
 */

class BadRequestException extends HttpException
{
    public function __construct(string $message = 'Bad Request', array $headers = [], ?string $title = 'Bad Request')
    {
        parent::__construct($message, 400, $headers, $title);
    }
}

