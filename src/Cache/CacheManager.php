<?php

namespace Ody\Foundation\Cache;

use Ody\Foundation\Cache\Adapters\ArrayAdapter;
use Ody\Foundation\Cache\Adapters\MemcachedAdapter;
use Ody\Foundation\Cache\Adapters\RedisAdapter;
use Ody\Foundation\Cache\Exceptions\CacheException;
use Ody\Foundation\Cache\PSR16\SimpleCache;
use Psr\SimpleCache\CacheInterface;

class CacheManager
{
    /**
     * @var array
     */
    protected array $config;

    /**
     * @var array
     */
    protected array $drivers = [];

    /**
     * @var array
     */
    protected array $customDrivers = [];

    /**
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Get a cache driver instance
     *
     * @param string|null $driver
     * @return CacheInterface
     * @throws CacheException
     */
    public function driver(?string $driver = null): CacheInterface
    {
        $driver = $driver ?? $this->config['default'] ?? 'array';

        if (isset($this->drivers[$driver])) {
            return $this->drivers[$driver];
        }

        return $this->drivers[$driver] = $this->resolve($driver);
    }

    /**
     * Resolve the given driver
     *
     * @param string $driver
     * @return CacheInterface
     * @throws CacheException
     */
    protected function resolve(string $driver): CacheInterface
    {
        $method = 'create' . ucfirst($driver) . 'Driver';

        if (isset($this->customDrivers[$driver])) {
            return $this->customDrivers[$driver]($this->getDriverConfig($driver));
        }

        if (method_exists($this, $method)) {
            return $this->$method();
        }

        throw new CacheException("Driver [{$driver}] is not supported.");
    }

    /**
     * Get configuration for a specific driver
     *
     * @param string $driver
     * @return array
     */
    protected function getDriverConfig(string $driver): array
    {
        return $this->config['drivers'][$driver] ?? [];
    }

    /**
     * Register a custom driver creator
     *
     * @param string $driver
     * @param callable $callback
     * @return self
     */
    public function extend(string $driver, callable $callback): self
    {
        $this->customDrivers[$driver] = $callback;

        return $this;
    }

    /**
     * Create an instance of the Redis cache driver
     *
     * @return CacheInterface
     */
    protected function createRedisDriver(): CacheInterface
    {
        $config = $this->getDriverConfig('redis');
        $adapter = new RedisAdapter($config);

        return new SimpleCache($adapter);
    }

    /**
     * Create an instance of the Memcached cache driver
     *
     * @return CacheInterface
     */
    protected function createMemcachedDriver(): CacheInterface
    {
        $config = $this->getDriverConfig('memcached');
        $adapter = new MemcachedAdapter($config);

        return new SimpleCache($adapter);
    }

    /**
     * Create an instance of the array cache driver (for testing)
     *
     * @return CacheInterface
     */
    protected function createArrayDriver(): CacheInterface
    {
        $config = $this->getDriverConfig('array');
        $adapter = new ArrayAdapter($config);

        return new SimpleCache($adapter);
    }
}