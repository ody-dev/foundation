<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware\Resolvers;

use Ody\Container\Container;
use Ody\Support\Config;
use Psr\Log\LoggerInterface;

/**
 * Factory for middleware resolvers
 */
class MiddlewareResolverFactory
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var Container
     */
    protected $container;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var array
     */
    protected $resolvers = [];

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param Container $container
     * @param Config $config
     */
    public function __construct(LoggerInterface $logger, Container $container, Config $config)
    {
        $this->logger = $logger;
        $this->container = $container;
        $this->config = $config;

        $this->registerDefaultResolvers();
    }

    /**
     * Register default resolvers
     *
     * @return void
     */
    protected function registerDefaultResolvers(): void
    {
        // Register built-in resolvers
        $this->addResolver(new AuthMiddlewareResolver($this->logger));
        $this->addResolver(new RoleMiddlewareResolver($this->logger));
        $this->addResolver(new ThrottleMiddlewareResolver($this->logger));

        // Get middleware map from config
        $middlewareMap = $this->config->get('app.middleware.named', []);

        // Add generic class resolver
        $this->addResolver(new ClassMiddlewareResolver($this->logger, $this->container, $middlewareMap));
    }

    /**
     * Add a resolver
     *
     * @param MiddlewareResolverInterface $resolver
     * @return self
     */
    public function addResolver(MiddlewareResolverInterface $resolver): self
    {
        $this->resolvers[] = $resolver;
        return $this;
    }

    /**
     * Resolve middleware by name
     *
     * @param string $name
     * @param array $options
     * @return callable
     * @throws \InvalidArgumentException
     */
    public function resolve(string $name, array $options = []): callable
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($name)) {
                return $resolver->resolve($name, $options);
            }
        }

        throw new \InvalidArgumentException("No resolver found for middleware: {$name}");
    }
}