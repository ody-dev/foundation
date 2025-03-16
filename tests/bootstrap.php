<?php
/*
 * This file is part of ODY framework
 *
 * @link https://ody.dev
 * @documentation https://ody.dev/docs
 * @license https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

/**
 * Bootstrap file for PHPUnit tests
 */

// Include Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Define testing environment constants
if (!defined('APP_BASE_PATH')) {
    define('APP_BASE_PATH', realpath(__DIR__ . '/..'));
}

// Load test environment values from .env.testing if present
if (file_exists(__DIR__ . '/../.env.testing')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..', '.env.testing');
    $dotenv->load();
}

// Set up Swoole mock if testing in a non-Swoole environment
if (!extension_loaded('swoole') && !class_exists('\Swoole\Coroutine')) {
    include_once __DIR__ . '/mocks/SwooleCoroutineMock.php';
}