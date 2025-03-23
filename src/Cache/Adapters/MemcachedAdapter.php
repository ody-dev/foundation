<?php

namespace Ody\Foundation\Cache\Adapters;

use DateInterval;
use Memcached;
use Ody\Foundation\Cache\Exceptions\CacheException;

class MemcachedAdapter implements AdapterInterface
{
    /**
     * @var Memcached
     */
    protected Memcached $memcached;

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
     * Connect to Memcached server using native Memcached with Swoole hooks
     *
     * @param array $config
     * @throws CacheException
     */
    protected function connect(array $config): void
    {
        try {
            $persistentId = $config['persistent_id'] ?? '';
            $this->memcached = new Memcached($persistentId);

            // Only add servers if we don't have any (important for persistent connections)
            if (!count($this->memcached->getServerList())) {
                $servers = $config['servers'] ?? [
                    [
                        'host' => $config['host'] ?? '127.0.0.1',
                        'port' => $config['port'] ?? 11211,
                        'weight' => 1
                    ]
                ];

                $this->memcached->addServers(array_map(function ($server) {
                    return [
                        $server['host'] ?? '127.0.0.1',
                        $server['port'] ?? 11211,
                        $server['weight'] ?? 1
                    ];
                }, $servers));
            }

            // Set options
            if (!empty($config['options']) && is_array($config['options'])) {
                foreach ($config['options'] as $option => $value) {
                    $this->memcached->setOption($option, $value);
                }
            }

            // Always set prefix
            $this->memcached->setOption(Memcached::OPT_PREFIX_KEY, $this->prefix);

            // Set binary protocol by default for better performance
            if (!isset($config['options'][Memcached::OPT_BINARY_PROTOCOL])) {
                $this->memcached->setOption(Memcached::OPT_BINARY_PROTOCOL, true);
            }

            // Set serializer if not specified
            if (!isset($config['options'][Memcached::OPT_SERIALIZER])) {
                // Use igbinary if available, otherwise use PHP serializer
                $serializer = extension_loaded('igbinary')
                    ? Memcached::SERIALIZER_IGBINARY
                    : Memcached::SERIALIZER_PHP;
                $this->memcached->setOption(Memcached::OPT_SERIALIZER, $serializer);
            }
        } catch (\Exception $e) {
            throw new CacheException("Memcached error: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $ttl = $this->normalizeTtl($ttl);

        try {
            return $this->memcached->set($key, $value, $ttl);
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
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        try {
            return $this->memcached->delete($key);
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
            return $this->memcached->flush();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        if (!is_array($keys)) {
            $keys = iterator_to_array($keys);
        }

        try {
            $values = $this->memcached->getMulti($keys);
            $result = [];

            foreach ($keys as $key) {
                $result[$key] = $values[$key] ?? $default;
            }

            return $result;
        } catch (\Exception $e) {
            $result = [];

            foreach ($keys as $key) {
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
        if (!is_array($values)) {
            $values = iterator_to_array($values);
        }

        $ttl = $this->normalizeTtl($ttl);

        try {
            return $this->memcached->setMulti($values, $ttl);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(iterable $keys): bool
    {
        if (!is_array($keys)) {
            $keys = iterator_to_array($keys);
        }

        try {
            $result = $this->memcached->deleteMulti($keys);

            // deleteMulti returns an array of results
            // Only return true if all deletions were successful
            return !in_array(false, $result, true);
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
            $this->memcached->get($key);
            return $this->memcached->getResultCode() !== Memcached::RES_NOTFOUND;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = $this->memcached->get($key);

            if ($this->memcached->getResultCode() === Memcached::RES_NOTFOUND) {
                return $default;
            }

            return $value;
        } catch (\Exception $e) {
            return $default;
        }
    }
}