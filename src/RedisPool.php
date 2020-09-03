<?php
namespace Swango\Cache;
/**
 *
 * @author fdrea
 */
final class RedisPool {
    private static $config, $queue, $timer_isset = false;
    public static function initInWorker() {
        self::$config = \Swango\Environment::getFrameworkConfig('redis');
        self::$queue = new \SplQueue();
    }
    private static function newConnection(): \Swoole\Coroutine\Redis {
        $connection = new \Swoole\Coroutine\Redis();
        $connection->connect(self::$config['host'], self::$config['port']);

        if (! self::$timer_isset) {
            self::$timer_isset = true;
            \swoole_timer_after(rand(50, 5000), '\\swoole_timer_tick', 10000,
                '\\Swango\\Cache\\RedisPool::checkConnection');
        }

        return $connection;
    }
    public static function push(\Swoole\Coroutine\Redis $db): void {
        // 因为各种原因，push失败了，要抛弃该条连接，总连接数减1
        if ($db->connected)
            self::$queue->push($db);
    }
    public static function pop(): \Swoole\Coroutine\Redis {
        // 如果通道为空，则试图创建，若已达到最大连接数，则注册消费者，等待新的连接
        do {
            if (self::$queue === null || self::$queue->isEmpty())
                return self::newConnection();
            $db = self::$queue->pop();
        } while ( ! $db->connected );
        return $db;
    }
    public static function clearQueue(): void {
        self::$queue = new \SplQueue();
    }
    public static function checkConnection() {
        if (self::$queue->count() > 2)
            self::$queue->pop()->close();
    }
}