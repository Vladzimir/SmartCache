<?php

namespace Cache;
require_once DIR_SYSTEM . 'library' . DIRECTORY_SEPARATOR . 'smartlock.php';

class SmartCache
{
    public $expire;
    public $lockTime = 5;
    private $tmpExt = '.tmp';
    private $ext = '.cache';
    private $path = null;

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

        if (!$this->isValid($file)) {
            if (\Vladzimir\SmartLock::instance($key)->lock()) {
                //Cache expired and lock success
                return false;
            } elseif (!$this->isExists($file)) {
                if (!\Vladzimir\SmartLock::instance($key)->lock($this->lockTime)) {
                    //File cache not exist and lock false
                    return false;
                }
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

        //Atomic write data
        file_put_contents($tmp, json_encode($value), LOCK_EX);
        rename($tmp, $new);
        \Vladzimir\SmartLock::instance($key)->unlock();
    }

    public function delete($key)
    {
        $pattern = $this->getFileName($key, true);
        $files = glob($pattern);

        if ($files) {
            foreach ($files as $file) {
                if (!@unlink($file)) {
                    clearstatcache(false, $file);
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

    private function isValid($file)
    {
        if (!$this->isExists($file)) {
            return false;
        }

        if ((filemtime($file) + $this->expire) < time()) {
            return false;
        }

        return true;
    }

    public function getFileName($key, $pattern = false)
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
