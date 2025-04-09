<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Providers;

use ErrorException;
use Ody\Container\Container;
use Ody\Foundation\Exceptions\Handler;
use Ody\Support\Config;
use Psr\Log\LoggerInterface;

class ErrorServiceProvider extends ServiceProvider
{
    /**
     * Register the error handler
     *
     * @return void
     */
    public function register(): void
    {
        $this->container->singleton(Handler::class, function (Container $container) {
            $logger = $container->make(LoggerInterface::class);
            $config = $container->make(Config::class);

            $debug = $config->get('app.debug', false);

            return new Handler($logger, $debug);
        });

        // Also register as 'error.handler' for easier access
        $this->container->alias(Handler::class, 'error.handler');
    }

    /**
     * Bootstrap the error handler
     *
     * @return void
     * @throws ErrorException
     */
    public function boot(): void
    {
        // Set up PHP error and exception handlers
        $this->registerErrorHandlers();
    }

    /**
     * Register PHP error and exception handlers
     *
     * @return void
     */
    protected function registerErrorHandlers(): void
    {
        // Get the handler instance
        $handler = $this->container->make(Handler::class);

        // Convert PHP errors to exceptions
        set_error_handler(function ($level, $message, $file = '', $line = 0) {
            if (error_reporting() & $level) {
                throw new ErrorException($message, 0, $level, $file, $line);
            }

            return true;
        });

        // Handle uncaught exceptions
        set_exception_handler(function (\Throwable $e) use ($handler) {
            $handler->report($e);

            // Create a simple response since we don't have a request here
            $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            $request = $factory->createServerRequest('GET', '/', []);
            $response = $handler->render($request, $e);

            // Send response headers
            http_response_code($response->getStatusCode());

            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    header("$name: $value", false);
                }
            }

            // Output body
            echo $response->getBody();
        });

        // Handle fatal errors
        register_shutdown_function(function () use ($handler) {
            $error = error_get_last();

            if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
                // Create an exception from the error
                $exception = new ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line']
                );

                $handler->report($exception);

                // Create a simple response
                $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
                $request = $factory->createServerRequest('GET', '/', []);
                $response = $handler->render($request, $exception);

                // Send response headers (if possible)
                if (!headers_sent()) {
                    http_response_code($response->getStatusCode());

                    foreach ($response->getHeaders() as $name => $values) {
                        foreach ($values as $value) {
                            header("$name: $value", false);
                        }
                    }
                }

                // Output body
                echo $response->getBody();
            }
        });
    }
}