<?php
/**
 * CoroutineRedisProxy
 *
 * @author camera360_server@camera360.com
 * @copyright Chengdu pinguo Technology Co.,Ltd.
 */

namespace PG\MSF\DataBase;

use PG\MSF\Coroutine\Redis;
use PG\MSF\Helpers\Context;
use PG\MSF\Marco;

class CoroutineRedisProxy
{
    private $redisAsynPool;


    public $keyPrefix = '';
    public $hashKey = false;
    public $phpSerialize = false;
    public $redisSerialize = false;

    public function __construct(RedisAsynPool $redisAsynPool)
    {
        $this->redisAsynPool = $redisAsynPool;
        $this->hashKey = $redisAsynPool->hashKey;
        $this->phpSerialize = $redisAsynPool->phpSerialize;
        $this->keyPrefix = $redisAsynPool->keyPrefix;
        $this->redisSerialize = $redisAsynPool->redisSerialize;
    }

    /**
     * redis cache 操作封装
     *
     * @param $context
     * @param string $key
     * @param string $value
     * @param int $expire
     * @return mixed|Redis
     */
    public function cache($context, string $key, $value = '', int $expire = 0)
    {
        if ($value === '') {
            $commandData = [$context, $key];
            $command = 'get';
        } else {
            if (!empty($expire)) {
                $command = 'setex';
                $commandData = [$context, $key, $expire, $value];
            } else {
                $command = 'set';
                $commandData = [$context, $key, $value];
            }
        }

        return $this->__call($command, $commandData);
    }

    /**
     * 在redis执行lua脚本，因为redis原生方法eval是php的保留关键字，所以用该方法名代替
     * 会根据$args参数中的缓存key进行redis实例选择
     *
     * @author niulingyun
     *
     * @param Context $context 上下文
     * @param string $script Lua脚本代码
     * @param array $args 参数
     * @param int $numKeys 脚本用到的redis的key的数量
     *
     * @return array
     *
     * redis中执行的Lua脚本，不支持直接返回多维数组，需要将多维数组转成json返回
     * 本方法的返回值会对Lua脚本的返回值进行封装：
     * 1. 如果脚本中需要返回多个key的操作结果，Lua脚本的返回值格式请如下，需要是json：
     * @example {
     *              key1 : '' or array(),
     *              key2 : '' or array(),
     *              ...
     *          }
     * 本方法会将各个实例的返回值按照缓存key进行合并，然后返作为数组返回
     * @example [
     *              key1 => '' or array(),
     *              key2 => '' or array(),
     *              ...
     *          ]
     *
     * 2. 如果只有一个key的操作结果，Lua脚本的返回值就无需按照key封装成数组，比如：
     * @example true 或者 array(value1, value2, ...)
     * 本方法会将这些值直接返回
     *
     * 3. 如果redis中本身存的就是未做序列化的json，该方法会自己将json解析成数组返回：
     * @example array(value1, value2, ...)
     *
     * @throws
     */
    public function evalMock($context, string $script, array $args = array(), int $numKeys = 0)
    {
        $keys = array_slice($args, 0, $numKeys);
        $argvs = array_slice($args, $numKeys);

        if (!empty($keys)) {
            foreach ($keys as $i => $key) {
                $keys[$i] = $this->generateUniqueKey($key);
            }
        }

        if (getInstance()->isTaskWorker()) {//task进程
            $commandData = [$context, $script, array_merge($keys, $argvs), $numKeys];
        } else {
            $commandData = array_merge([$context, $script, $numKeys], $keys, $argvs);
        }

        return $this->__call('eval', $commandData);
    }

    public function __call(string $name, array $arguments)
    {
        // key start， 排除eval
        if (isset($arguments[1]) && $name !== 'eval') {
            $key = $arguments[1];
            if (is_array($key)) {
                // mset mget mdelete等为空
                if (empty($key)) {
                    return false;
                }
                $isAssoc = array_keys($key) !== range(0, count($key) - 1); //true关联 false索引
                $newKey = [];
                foreach ($key as $k => $v) {
                    if ($isAssoc) {
                        $newKey[$this->generateUniqueKey($k)] = $v;
                    } else {
                        $newKey[$k] = $this->generateUniqueKey($v);
                    }
                }
            } else {
                $newKey = $this->generateUniqueKey($key);
            }
            $arguments[1] = $newKey;
        }

        //多key操作特殊逻辑
        switch (strtolower($name)) {
            //kkk...
            case 'sdiff':
            case 'sdiffstore':
            case 'sunion':
            case 'sunionstore':
                foreach ($arguments as $k => $argument) {
                    if ($k >= 2) {
                        $arguments[$k] = $this->generateUniqueKey($k);
                    }
                }
                break;
            //kkv or kk
            case 'smove':
            case 'rpoplpush':
            case 'brpoplpush':
                $arguments[2] = $this->generateUniqueKey(2);
                break;
        }
        // key end

        // value serialize start
        switch (strtolower($name)) {
            case 'set':
                $arguments[2] = $this->serializeHandler($arguments[2], true);
                break;
            case 'setex':
                $arguments[3] = $this->serializeHandler($arguments[3], true);
                break;
            case 'mset':
                $keysValues = $arguments[1];
                $newValues = [];
                foreach ($keysValues as $k => $v) {
                    $newValues[$k] = $this->serializeHandler($v, true);
                }
                $arguments[1] = $newValues;
                break;
            case 'sadd':
                //member是array
                if (is_array($arguments[2])) {
                    $newValues = [];
                    foreach ($arguments[2] as $v) {
                        $newValues[] = $this->serializeHandler($v);
                    }
                    $arguments[2] = $newValues;
                } else {
                    foreach ($arguments as $k => $argument) {
                        if ($k >= 2) {
                            $arguments[$k] = $this->serializeHandler($argument);
                        }
                    }
                }
                break;
            case 'zadd':
            case 'hset':
                $argument[3] = $this->serializeHandler($arguments[3]);
                break;
            case 'hmset':
                $keysValues = $arguments[2];
                $newValues = [];
                foreach ($keysValues as $k => $v) {
                    $newValues[$k] = $this->serializeHandler($v);
                }
                $arguments[2] = $newValues;
                break;
        }
        // value serialize end

        if (getInstance()->isTaskWorker()) {//如果是task进程自动转换为同步模式
            /**
             * @var Context $context
             */
            $context = array_shift($arguments);
            $context->getLog()->profileStart('redis.' . $name);
            $value = $this->redisAsynPool->getSync()->$name(...$arguments);
            $context->getLog()->profileEnd('redis.' . $name);
            // return value unserialize start
            switch ($name) {
                case 'mget';
                    $keys = $this->arguments[0];
                    $len = strlen($this->keyPrefix);
                    $value = $this->unSerializeHandler($value, $keys, $len);
                    break;
                case 'eval':
                    //如果redis中的数据本身没有进行序列化，同时返回值是json，那么解析成array
                    $decodeVal = @json_decode($value, true);
                    if (is_array($decodeVal)) {
                        $value = $decodeVal;
                    }
                    $value = $this->unSerializeHandler($value);
                    break;
                default:
                    $value = $this->unSerializeHandler($value);
            }
            // return value unserialize end

            return $value;
        } else {
            return $arguments[0]->getObjectPool()->get(Redis::class)->initialization($this->redisAsynPool, $name, array_slice($arguments, 1));
        }
    }

    /**
     * @param string $key a key identifying a value to be cached
     * @return string a key generated from the provided key which ensures the uniqueness across applications
     */
    protected function generateUniqueKey(string $key)
    {
        return $this->hashKey ? md5($this->keyPrefix . $key) : $this->keyPrefix . $key;
    }

    /**
     * 序列化
     * @param $data
     * @return string
     */
    protected function serializeHandler($data, $phpSerialize = false)
    {
        try {
            //只有set、mset等才需要
            if ($this->phpSerialize && $phpSerialize) {
                $data = [$data, null];
                switch ($this->phpSerialize) {
                    case Marco::SERIALIZE_PHP:
                        $data = serialize($data);
                        break;
                    case Marco::SERIALIZE_IGBINARY:
                        $data = @igbinary_serialize($data);
                        break;
                }
            }

            switch ($this->redisSerialize) {
                case Marco::SERIALIZE_PHP:
                    $data = serialize($data);
                    break;
                case Marco::SERIALIZE_IGBINARY:
                    $data = @igbinary_serialize($data);
                    break;
            }
        } catch (\Exception $exception) {
            // do noting
        }

        return $data;
    }

    /**
     * 反序列化
     * @param $data
     * @param array $keys
     * @param int $len
     * @return array|bool|mixed
     */
    protected function unSerializeHandler($data, $keys = [], $len = 0)
    {
        // 如果值是null，直接返回false
        if (null === $data) {
            return false;
        }

        if ('OK' === $data) {
            return $data;
        }

        try {
            //mget
            if (!empty($keys) && is_array($data)) {
                $ret = [];
                array_walk($data, function ($val, $k) use ($keys, $len, &$ret) {
                    $key = substr($keys[$k], $len);
                    $val = $this->realUnserialize($val);
                    $ret[$key] = $val;
                });

                $data = $ret;
            } elseif (is_array($data) && empty($keys)) {
                //eval sRandMember...
                $ret = [];
                array_walk($data, function ($val, $k) use (&$ret) {
                    if (is_array($val)) {
                        foreach ($val as $i => $v) {
                            $val[$i] = $this->realUnserialize($v);
                        }
                    } else {
                        $val = $this->realUnserialize($val);
                    }

                    $ret[$k] = $val;
                });

                $data = $ret;
            } else {
                //get
                $data = $this->realUnserialize($data);
            }
        } catch (\Exception $exception) {
            // do noting
        }

        return $data;
    }

    /**
     * 是否可以反序列化
     * @param string $string
     * @return bool
     */
    private function canUnserialize(string $string)
    {
        return in_array(substr($string, 0, 2), ['s:', 'i:', 'b:', 'N', 'a:', 'O:', 'd:']);
    }

    /**
     * 真正反序列化
     * @param string $data
     * @return mixed|string
     */
    private function realUnserialize($data)
    {
        //get
        if (is_string($data) && $this->redisSerialize) {
            switch ($this->redisSerialize) {
                case Marco::SERIALIZE_PHP:
                    if ($this->canUnserialize($data)) {
                        $data = unserialize($data);
                    }
                    break;
                case Marco::SERIALIZE_IGBINARY:
                    $data = @igbinary_unserialize($data);
                    break;
            }
        }

        if (is_string($data) && $this->phpSerialize) {
            switch ($this->phpSerialize) {
                case Marco::SERIALIZE_PHP:
                    if ($this->canUnserialize($data)) {
                        $data = unserialize($data);
                    }
                    break;
                case Marco::SERIALIZE_IGBINARY:
                    $data = @igbinary_unserialize($data);
                    break;
            }

            //兼容yii逻辑
            if (is_array($data) && count($data) === 2 && array_key_exists(1, $data) && $data[1] === null) {
                $data = $data[0];
            }
        }
        return $data;
    }
}