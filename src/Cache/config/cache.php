<?php

return [
    'default' => 'redis', // Default driver
    'drivers' => [
        'redis' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'auth' => null, // Optional password
            'db' => 0,      // Database index
            'prefix' => 'cache:',
            'ttl' => 3600   // Default TTL in seconds
        ],
        'memcached' => [
            'servers' => [
                ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 100],
                // Add more servers for distributed setups
            ],
            'options' => [
                // Memcached options here
            ],
            'prefix' => 'cache:',
            'ttl' => 3600
        ],
        'array' => [
            'ttl' => 3600
        ]
    ]
];