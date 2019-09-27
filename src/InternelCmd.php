<?php
namespace Swango\Cache;
class InternelCmd {
    public const REDIS_KEY = 'InternelCmdPublisher';
    /**
     *
     * @var \Swoole\Coroutine\Redis
     */
    private static $redis;
    protected static function sendBroadcast(string $class_name, string $cmd_data): int {
        if (defined('WORKING_MODE') && defined('WORKING_MODE_SWOOLE_COR') && WORKING_MODE === WORKING_MODE_SWOOLE_COR &&
             defined('LOCAL_IP'))
            $srcip = LOCAL_IP;
        else
            $srcip = 0;
        return \cache::publish(self::REDIS_KEY,
            \Json::encode(
                [
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
        do {
            do {
                if (! isset($redis) || ! $redis->connected) {
                    // 因为 subscribe 和 publish 运行在同一个连接时会报错，这里获取到的连接不再push回连接池中
                    self::$redis = $redis = RedisPool::pop();
                    $redis->select(1);
                    $redis->subscribe([
                        self::REDIS_KEY
                    ]);
                }
                $arr = $redis->recv();
                if ($arr === false) {
                    self::$redis = null;
                    unset($redis);
                    return;
                }
                if (! is_array($arr) || count($arr) !== 3) {
                    self::$redis = null;
                    $redis->close();
                    unset($redis);
                    continue;
                } else
                    break;
            } while ( true );
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

                if ((! defined('LOCAL_IP') || $srcip !== LOCAL_IP) && class_exists($class_name)) {
                    \Swlib\Archer::task("$class_name::handle",
                        [
                            $cmd_data
                        ]);
                    unset($cmd_data);
                    unset($class_name);
                }
                unset($srcip);
                unset($cmd);
            }
            unset($arr);
            unset($cmdpack);
        } while ( true );
    }
    final public static function stopLoop() {
        if (isset(self::$redis) && self::$redis->connected)
            self::$redis->close();
    }
}