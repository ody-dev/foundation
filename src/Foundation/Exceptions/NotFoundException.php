<?php
/*
 * This file is part of ODY framework
 *
 * @link https://ody.dev
 * @documentation https://ody.dev/docs
 * @license https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Exceptions;

class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not Found', array $headers = [], ?string $title = 'Not Found')
    {
        parent::__construct($message, 404, $headers, $title);
    }
}