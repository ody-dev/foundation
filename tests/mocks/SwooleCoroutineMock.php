<?php
/**
 * Mocks for Swoole classes to enable testing in environments without Swoole installed
 */

namespace Swoole {
    class Coroutine {
        public static function getCid() {
            return 1; // Mock coroutine ID
        }

        public static function create(callable $callable) {
            return $callable(); // Just execute the callable directly in tests
        }

        public static function getContext() {
            static $context = null;
            if ($context === null) {
                $context = [];
            }
            return $context;
        }
    }

    namespace Swoole\Coroutine {
        class System {
            public static function fopen($filename, $mode) {
                return fopen($filename, $mode);
            }

            public static function fwrite($handle, $data) {
                return fwrite($handle, $data);
            }

            public static function fclose($handle) {
                return fclose($handle);
            }
        }
    }

    namespace Swoole\Http {
        class Response {
            private $headers = [];
            private $status = 200;
            private $sent = false;
            private $body = '';

            public function header($name, $value, $format = true) {
                $this->headers[$name] = $value;
                return true;
            }

            public function status($code) {
                $this->status = $code;
                return true;
            }

            public function write($content) {
                $this->body .= $content;
                return true;
            }

            public function end($content = null) {
                if ($content !== null) {
                    $this->body .= $content;
                }
                $this->sent = true;
                return true;
            }

            public function isWritable() {
                return !$this->sent;
            }

            public function getBody() {
                return $this->body;
            }

            public function getHeaders() {
                return $this->headers;
            }

            public function getStatusCode() {
                return $this->status;
            }
        }

        class Request {
            private $server = [];
            private $headers = [];
            private $get = [];
            private $post = [];
            private $cookies = [];
            private $files = [];
            private $tmpfiles = [];
            private $body = '';

            public function __construct() {
                $this->server = [
                    'request_method' => 'GET',
                    'request_uri' => '/',
                    'path_info' => '/',
                    'remote_addr' => '127.0.0.1',
                ];
            }

            public function rawContent() {
                return $this->body;
            }

            public function getData() {
                return $this->get;
            }

            public function getMethod() {
                return $this->server['request_method'];
            }

            public function getHeaders() {
                return $this->headers;
            }

            public function getCookieParams() {
                return $this->cookies;
            }

            public function getServerParams() {
                return $this->server;
            }
        }
    }

    namespace Swoole\Table {
        class Table implements \ArrayAccess, \Iterator {
            private $size;
            private $data = [];
            private $columns = [];
            private $position = 0;
            private $keys = [];

            // Constants to match Swoole's
            const TYPE_INT = 1;
            const TYPE_FLOAT = 2;
            const TYPE_STRING = 3;

            public function __construct($size) {
                $this->size = $size;
            }

            public function column($name, $type, $size) {
                $this->columns[$name] = [
                    'type' => $type,
                    'size' => $size
                ];
                return true;
            }

            public function create() {
                return true;
            }

            public function set($key, array $value) {
                $this->data[$key] = $value;
                $this->keys = array_keys($this->data);
                return true;
            }

            public function get($key) {
                return $this->data[$key] ?? false;
            }

            public function del($key) {
                if (isset($this->data[$key])) {
                    unset($this->data[$key]);
                    $this->keys = array_keys($this->data);
                    return true;
                }
                return false;
            }

            // ArrayAccess implementation
            public function offsetExists($offset): bool {
                return isset($this->data[$offset]);
            }

            public function offsetGet($offset) {
                return $this->data[$offset] ?? null;
            }

            public function offsetSet($offset, $value): void {
                if (is_array($value)) {
                    $this->set($offset, $value);
                }
            }

            public function offsetUnset($offset): void {
                $this->del($offset);
            }

            // Iterator implementation
            public function current() {
                $key = $this->keys[$this->position];
                return $this->data[$key];
            }

            public function key() {
                return $this->keys[$this->position];
            }

            public function next(): void {
                ++$this->position;
            }

            public function rewind(): void {
                $this->position = 0;
            }

            public function valid(): bool {
                return isset($this->keys[$this->position]);
            }
        }
    }
}