<?php
namespace Swango\Cache;
final class RedisPool {
    private static array $config;
    private static \SplQueue $queue;
    private static int $timer;
    public static function initInWorker(): void {
        if (! isset(self::$queue)) {
            self::$config = \Swango\Environment::getFrameworkConfig('redis');
            self::$queue = new \SplQueue();
        }
    }
    private static function newConnection(): \Swoole\Coroutine\Redis {
        $connection = new \Swoole\Coroutine\Redis();
        $connection->connect(self::$config['host'], self::$config['port']);
        if (! isset(self::$timer)) {
            self::$timer = \swoole_timer_after(mt_rand(50, 5000), function () {
                self::$timer = \swoole_timer_tick(10000, '\\Swango\\Cache\\RedisPool::checkConnection');
            });
        }
        return $connection;
    }
    public static function push(\Swoole\Coroutine\Redis $db): void {
        // 因为各种原因，push失败了，要抛弃该条连接，总连接数减1
        if ($db->connected) {
            isset(self::$queue) && self::$queue->push($db);
        }
    }
    public static function pop(): \Swoole\Coroutine\Redis {
        // 如果通道为空，则试图创建，若已达到最大连接数，则注册消费者，等待新的连接
        self::initInWorker();
        do {
            if (self::$queue->isEmpty()) {
                return self::newConnection();
            }
            $db = self::$queue->pop();
        } while (! $db->connected);
        return $db;
    }
    public static function clearQueue(): void {
        self::$queue = new \SplQueue();
        if (isset(self::$timer)) {
            \swoole_timer_clear(self::$timer);
        }
    }
    public static function checkConnection() {
        if (self::$queue->count() > 2) {
            self::$queue->pop()->close();
        }
    }
}