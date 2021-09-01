<?php

namespace Cache;
require_once DIR_SYSTEM . 'library' . DIRECTORY_SEPARATOR . 'smartlock.php';

class SmartCache
{
    private $version = '0.4';
    private $expire;
    private $lockTime = 5;
    private $tmpExt = '.tmp';
    private $ext = '.cache';
    private $path = null;
    private $delta = 60;
    private $expireByPrefix = [
        'language' => 86400,
        'currency' => 86400,
        'store' => 86400,
        'tax_class' => 604800,
        'weight_class' => 604800,
        'zone' => 604800,
        'order_status' => 86400,
        'category' => 7200,
        'product' => 7200,
        'information' => 10800,
        'seo_pro' => 604800,
    ];

    public function __construct($expire = 3600)
    {
        $this->path = DIR_CACHE . 'smartcache' . DIRECTORY_SEPARATOR;
        if (!is_dir($this->path)) {
            try {
                if (!mkdir($this->path, 0755)) {
                    throw new \Exception('Unable to create SmartCache dir');
                }
            } catch (\Exception $e) {
                echo $e->getMessage();
            }
        }

        $this->expire = $expire;
    }

    public function __destruct()
    {
        //$this->garbage();
    }

    public function get($key)
    {
        $file = $this->getFileName($key);

        if (!$this->isValid($file, $key)) {
            if (\Vladzimir\SmartLock::instance($key)->lock()) {
                //Cache expired and lock success
                return false;
            } elseif (\Vladzimir\SmartLock::instance($key)->lock($this->lockTime)) {
                //Waiting locking
                \Vladzimir\SmartLock::instance($key)->unlock();
                //Unlock
            }
        }
        return $this->getData($file);
    }

    private function getData($file)
    {
        if ($this->isExists($file)) {
            $handle = fopen($file, 'r');
            flock($handle, LOCK_SH);

            $size = filesize($file);
            if ($size > 0) {
                $data = fread($handle, $size);
            } else {
                $data = '';
            }

            flock($handle, LOCK_UN);
            fclose($handle);

            return json_decode($data, true);
        }

        return false;
    }

    public function set($key, $value)
    {
        $tmp = $this->path . uniqid('', true) . '.' . basename($key) . $this->tmpExt;
        $new = $this->getFileName($key);

        //Atomic data recording
        file_put_contents($tmp, json_encode($value, JSON_UNESCAPED_UNICODE), LOCK_EX);
        if (!rename($tmp, $new)) {
            try {
                if (!unlink($tmp)) {
                    clearstatcache(false, $tmp);
                }
                if (!unlink($new)) {
                    clearstatcache(false, $new);
                }
            } catch (\Exception $e) {
                //echo $e->getMessage();
            }
        }
        \Vladzimir\SmartLock::instance($key)->unlock();
    }

    public function delete($key)
    {
        $pattern = $this->getFileName($key, true);
        $files = glob($pattern);

        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    try {
                        if (!unlink($file)) {
                            clearstatcache(false, $file);
                        }
                    } catch (\Exception $e) {
                        //echo $e->getMessage();
                    }
                }
            }
        }
    }

    private function isExists($file)
    {
        if (file_exists($file)) {
            return true;
        } else {
            return false;
        }
    }

    private function isValid($file, $key)
    {
        if (!$this->isExists($file)) {
            return false;
        }

        try {
            $fileTime = filemtime($file);
            if (!$fileTime) {
                return false;
            }
        } catch (\Exception $e) {
            return false;
        }

        if (($fileTime + $this->getExpire($key)) < time()) {
            return false;
        }

        return true;
    }

    private function getExpire($key)
    {
        $arr = explode('.', $key);
        $prefix = $arr[0];
        if (isset($this->expireByPrefix[$prefix])) {
            return $this->expireByPrefix[$prefix] + rand(0, $this->delta);
        } else {
            return $this->expire + rand(0, $this->delta);
        }
    }

    private function getFileName($key, $pattern = false)
    {
        $baseName = basename($key);
        $end = $this->ext;
        if ($pattern) {
            $end = '.*';
        }
        $file = $this->path . $baseName . $end;
        return $file;
    }
}