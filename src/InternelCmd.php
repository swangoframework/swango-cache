<?php
namespace Swango\Cache;
class InternelCmd {
    public static $CMD_REDIS_KEY = 'InternelCmdPublisher';
    /**
     *
     * @var \Swoole\Coroutine\Redis
     */
    private static $redis;
    protected static function sendBroadcast(string $class_name, string $cmd_data): int {
        if (\Swango\Environment::getWorkingMode()->isInSwooleWorker()) {
            $srcip = \Swango\Environment::getServiceConfig()->local_ip;
        } else {
            $srcip = 0;
        }
        return \cache::publish(self::$CMD_REDIS_KEY, \Json::encode([
            'srcip' => $srcip,
            'class' => $class_name,
            'data' => $cmd_data
        ]));
    }
    final public function __construct() {
        go([
            $this,
            'loop'
        ]);
    }
    final public function loop(): void {
        $redis = null;
        $sleep_time = 0;
        do {
            do {
                if (! isset($redis) || ! $redis->connected) {
                    // 因为 subscribe 和 publish 运行在同一个连接时会报错，这里获取到的连接不再push回连接池中
                    self::$redis = $redis = RedisPool::pop();
                    $redis->setOptions(['timeout' => -1]);
                    $db = \Swango\Environment::getFrameworkConfig('redis')['cache_db'] ?? 1;
                    $redis->select($db);
                    $redis->subscribe([
                        self::$CMD_REDIS_KEY
                    ]);
                }
                $arr = $redis->recv();
                if (! is_array($arr) || count($arr) !== 3) {
                    self::$redis = null;
                    $redis->close();
                    unset($redis);
                    if ($sleep_time > 0) {
                        \co::sleep($sleep_time);
                    }
                    if (++$sleep_time > 10) {
                        $sleep_time = 10;
                    }
                    continue;
                } else {
                    $sleep_time = 0;
                    break;
                }
            } while (true);
            [
                $type,
                $name,
                $cmdpack
            ] = $arr;
            if ($type === 'subscribe') {
                // $name 频道订阅成功，订阅几个频道就有几条
            } elseif ($type === 'unsubscribe') {
                // 收到取消订阅消息，并且剩余订阅的频道数为0，不再接收，结束循环
                if ($cmdpack === 0) {
                    $redis->close();
                    break;
                }
            } elseif ($type === 'message') {
                [
                    'srcip' => $srcip,
                    'class' => $class_name,
                    'data' => $cmd_data
                ] = \Json::decodeAsArray($cmdpack);
                if ($srcip !== \Swango\Environment::getServiceConfig()->local_ip && class_exists($class_name)) {
                    go(function () use ($class_name, $cmd_data) {
                        try {
                            $class_name::handle($cmd_data);
                        } catch (\Throwable $e) {
                            \FileLog::logThrowable($e, \Swango\Environment::getDir()->log . 'error/', 'InternelCmd');
                        }
                    });
                    unset($cmd_data);
                    unset($class_name);
                }
                unset($srcip);
                unset($cmd);
            }
            unset($arr);
            unset($cmdpack);
        } while (true);
    }
    final public static function stopLoop() {
        if (isset(self::$redis) && self::$redis->connected) {
            self::$redis->close();
        }
    }
}