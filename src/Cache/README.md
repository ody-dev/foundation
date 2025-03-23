A high-performance caching package for Swoole applications that's compliant with PSR-6 (Cache) and PSR-16 (SimpleCache)
standards.

## Features

- PSR-6 and PSR-16 compliant interfaces
- Built for Swoole's coroutines for non-blocking I/O
- Multiple driver support:
    - Redis (using native phpredis with Swoole runtime hooks)
    - Memcached (using native Memcached extension with Swoole runtime hooks)
    - Array (in-memory, for testing)
    - Extensible with custom drivers
- Cache tagging for grouped invalidation
- Deferred item storage for improved performance
- Serialization of complex data types

## Installation

```bash
composer require yourname/cache-package

# Required extensions
pecl install redis
pecl install memcached
# Optional but recommended for better performance
pecl install igbinary
```

## Basic Usage

### PSR-16 (SimpleCache)

```php
// Create cache manager with configuration
$cacheManager = new CacheManager([
    'default' => 'redis',
    'drivers' => [
        'redis' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'prefix' => 'app:cache:',
            'ttl' => 3600 // Default TTL
        ]
    ]
]);

// Get a PSR-16 cache instance
$cache = $cacheManager->driver();

// Store an item
$cache->set('user:1', ['name' => 'John Doe'], 300); // 5 minutes

// Retrieve an item
$user = $cache->get('user:1');

// Delete an item
$cache->delete('user:1');

// Clear the entire cache
$cache->clear();
```

### PSR-6 (CacheItemPool)

```php
// Get a PSR-16 cache instance
$simpleCache = $cacheManager->driver();

// Wrap with a PSR-6 compatible pool
$pool = new CacheItemPool($simpleCache);

// Get an item
$item = $pool->getItem('user:1');

if (!$item->isHit()) {
    // Item wasn't found, set a new value
    $item->set(['name' => 'John Doe']);
    $item->expiresAfter(300); // 5 minutes
    $pool->save($item);
}

// Get the value
$user = $item->get();

// Save deferred (for bulk operations)
$item = $pool->getItem('user:2');
$item->set(['name' => 'Jane Doe']);
$pool->saveDeferred($item);

// Commit all deferred items
$pool->commit();
```

### Tagged Cache

Tagged cache allows you to tag related items and invalidate them as a group:

```php
$cache = $cacheManager->driver();

// Create a tagged cache
$userCache = new TaggedCache($cache, ['users']);
$adminCache = new TaggedCache($cache, ['users', 'admins']);

// Store items with tags
$userCache->set('user:1', ['name' => 'John']);
$adminCache->set('user:2', ['name' => 'Jane', 'role' => 'admin']);

// Clear all items with the 'users' tag
$userCache->clear(); // Both user:1 and user:2 are cleared
```

## Custom Drivers

You can easily extend the cache manager with your own driver:

```php
$cacheManager->extend('custom', function ($config) {
    return new class($config) implements AdapterInterface {
        // Implement the adapter interface methods
    };
});

// Use your custom driver
$cache = $cacheManager->driver('custom');
```

## Configuration

Example configuration with all available options:

```php
$config = [
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
```

## Testing

```bash
composer test
```
