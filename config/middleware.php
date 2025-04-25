<?php

use Ody\Foundation\Middleware\ErrorHandlerMiddleware;
use Ody\Foundation\Middleware\JsonBodyParserMiddleware;

return [
    'global' => [
        ErrorHandlerMiddleware::class,
        JsonBodyParserMiddleware::class,
    ],
    'named' => [
//        'auth' => AuthMiddleware::class,
    ],
    'groups' => [
        'api' => [
//            AuthMiddleware::class,
        ]
    ]
];