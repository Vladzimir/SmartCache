<?php

namespace Vladzimir;
class SmartLock
{
    private $locked = false;
    private $key = null;
    private $filename = null;
    private $handle = null;
    private $interval = 50;
    private $path = null;

    private static $instances = [];

    public static function instance($key)
    {
        if (!array_key_exists($key, self::$instances)) {
            self::$instances[$key] = new self($key);
        }

        return self::$instances[$key];
    }

    private function __construct($key)
    {
        $this->key = $key;
        $this->path = DIR_CACHE . 'smartlock' . DIRECTORY_SEPARATOR;
        if (!is_dir($this->path)) {
            try {
                if (!mkdir($this->path, 0755)) {
                    throw new \Exception('Unable to create SmartLock dir');
                }
            } catch (\Exception $e) {
                echo $e->getMessage();
                return false;
            }
        }
        $this->filename = $this->path . sha1($key) . '.lock';
    }

    public function __destruct()
    {
        $this->unlock();
    }

    public function lock($wait = 0)
    {
        $this->handle = fopen($this->filename, 'c');

        if ($this->handle === false) {
            return false;
        }

        if ($wait) {
            $flags = LOCK_EX | LOCK_NB;
        } else {
            $flags = LOCK_EX;
        }

        $endTime = microtime(true) + $wait;

        while (1) {
            if (flock($this->handle, $flags)) {
//See https://github.com/yiisoft/mutex-file/blob/master/src/FileMutex.php#L132
                if (fstat($this->handle)['ino'] !== @fileinode($this->filename)) {
                    clearstatcache(false, $this->filename);
                    flock($this->handle, LOCK_UN);
                    fclose($this->handle);
                    return false;
                }
                $this->locked = true;
                return true;
            } else {
                if (!$wait OR microtime(true) >= $endTime) {
                    fclose($this->handle);
                    return false;
                }
                $sleep = mt_rand(ceil($this->interval / 2), ceil($this->interval * 1.5));
                usleep($sleep);
            }
        }
    }

    public function unlock()
    {
        if ($this->locked) {
            try {
//See to https://github.com/yiisoft/mutex-file/blob/master/src/FileMutex.php#L178
                unlink($this->filename);
                flock($this->handle, LOCK_UN);
                fclose($this->handle);
                $this->locked = false;
            } catch (\Exception $e) {
                clearstatcache(false, $this->filename);
            }
        }
    }

    private function __clone()
    {
    }

    private function __wakeup()
    {
    }
}