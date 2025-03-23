<?php

namespace Ody\Foundation\Cache\Adapters;

use DateInterval;
use Ody\Foundation\Cache\Exceptions\CacheException;
use Redis;

class RedisAdapter implements AdapterInterface
{
    /**
     * @var Redis
     */
    protected Redis $redis;

    /**
     * @var string
     */
    protected string $prefix;

    /**
     * @var int
     */
    protected int $defaultTtl;

    /**
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->prefix = $config['prefix'] ?? 'cache:';
        $this->defaultTtl = $config['ttl'] ?? 3600;

        $this->connect($config);
    }

    /**
     * Connect to Redis server using native phpredis with Swoole hooks
     *
     * @param array $config
     * @throws CacheException
     */
    protected function connect(array $config): void
    {
        $this->redis = new Redis();
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 6379;

        $timeout = $config['timeout'] ?? 0.0;
        $retry_interval = $config['retry_interval'] ?? 0;
        $read_timeout = $config['read_timeout'] ?? 0.0;

        try {
            $connected = $this->redis->connect($host, $port, $timeout, null, $retry_interval, $read_timeout);

            if (!$connected) {
                throw new CacheException("Could not connect to Redis server at {$host}:{$port}");
            }

            if (isset($config['auth']) && !empty($config['auth'])) {
                if (!$this->redis->auth($config['auth'])) {
                    throw new CacheException("Redis authentication failed");
                }
            }

            if (isset($config['db']) && is_numeric($config['db'])) {
                $this->redis->select((int)$config['db']);
            }

            if (isset($config['options']) && is_array($config['options'])) {
                foreach ($config['options'] as $option => $value) {
                    $this->redis->setOption($option, $value);
                }
            }
        } catch (\RedisException $e) {
            throw new CacheException("Redis error: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = $this->redis->get($this->prefix . $key);

            if ($value === false && !$this->redis->exists($this->prefix . $key)) {
                return $default;
            }

            return $this->unserialize($value);
        } catch (\Exception $e) {
            return $default;
        }
    }

    /**
     * Unserialize data after retrieving
     *
     * @param string $data
     * @return mixed
     */
    protected function unserialize(string $data): mixed
    {
        return unserialize($data);
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $ttl = $this->normalizeTtl($ttl);
        $value = $this->serialize($value);

        try {
            if ($ttl > 0) {
                return (bool)$this->redis->setex($this->prefix . $key, $ttl, $value);
            }

            return (bool)$this->redis->set($this->prefix . $key, $value);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Normalize TTL value to seconds
     *
     * @param null|int|\DateInterval $ttl
     * @return int
     */
    protected function normalizeTtl(null|int|\DateInterval $ttl = null): int
    {
        if ($ttl === null) {
            return $this->defaultTtl;
        }

        if ($ttl instanceof DateInterval) {
            return (new \DateTime())->add($ttl)->getTimestamp() - time();
        }

        return (int)$ttl;
    }

    /**
     * Serialize data before storing
     *
     * @param mixed $data
     * @return string
     */
    protected function serialize(mixed $data): string
    {
        return serialize($data);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        try {
            return (bool)$this->redis->del($this->prefix . $key);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        try {
            // Only clear keys with our prefix
            $iterator = null;
            $keys = [];

            do {
                // Scan for keys matching our prefix
                $scanKeys = $this->redis->scan($iterator, $this->prefix . '*', 1000);

                if ($scanKeys) {
                    $keys = array_merge($keys, $scanKeys);
                }
            } while ($iterator > 0);

            if (empty($keys)) {
                return true;
            }

            return (bool)$this->redis->del(...$keys);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $prefixedKeys = [];
        $originalKeys = [];

        foreach ($keys as $key) {
            $prefixedKeys[] = $this->prefix . $key;
            $originalKeys[] = $key;
        }

        try {
            $values = $this->redis->mGet($prefixedKeys);
            $result = [];

            foreach ($originalKeys as $i => $key) {
                $value = $values[$i] ?? false;
                $result[$key] = ($value === false)
                    ? $default
                    : $this->unserialize($value);
            }

            return $result;
        } catch (\Exception $e) {
            $result = [];

            foreach ($originalKeys as $key) {
                $result[$key] = $default;
            }

            return $result;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $ttl = $this->normalizeTtl($ttl);

        try {
            // If TTL is set, we can't use mSet directly
            if ($ttl > 0) {
                $success = true;

                // Start a transaction
                $this->redis->multi();

                foreach ($values as $key => $value) {
                    $this->redis->setex(
                        $this->prefix . $key,
                        $ttl,
                        $this->serialize($value)
                    );
                }

                $this->redis->exec();

                return $success;
            } else {
                // For no TTL, we can use mSet
                $serialized = [];

                foreach ($values as $key => $value) {
                    $serialized[$this->prefix . $key] = $this->serialize($value);
                }

                return (bool)$this->redis->mSet($serialized);
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $prefixedKeys = [];

        foreach ($keys as $key) {
            $prefixedKeys[] = $this->prefix . $key;
        }

        if (empty($prefixedKeys)) {
            return true;
        }

        try {
            return (bool)$this->redis->del(...$prefixedKeys);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        try {
            return (bool)$this->redis->exists($this->prefix . $key);
        } catch (\Exception $e) {
            return false;
        }
    }
}