<?php

namespace Ody\Foundation\Http;

use Swoole\Table;

class ControllerCache
{
    private static ?Table $table = null;

    public static function get(string $class)
    {
        self::init();
        if (self::$table->exists($class)) {
            // In a real implementation, you'd need serialization/deserialization
            return unserialize(self::$table->get($class, 'instance'));
        }
        return null;
    }

    public static function init(): void
    {
        if (self::$table === null) {
            self::$table = new Table(1024);
            self::$table->column('instance', Table::TYPE_STRING, 64); // Store class name
            self::$table->create();
        }
    }

    public static function set(string $class, object $instance): void
    {
        self::init();
        // In a real implementation, you'd need serialization/deserialization
        self::$table->set($class, ['instance' => serialize($instance)]);
    }

    public static function has(string $class): bool
    {
        self::init();
        return self::$table->exists($class);
    }
}