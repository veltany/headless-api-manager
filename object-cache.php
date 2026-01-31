<?php
/** BY: Headless API
 * Pure Object Cache (No DB Fallback)
 * Backends: Redis → Memcached → APCu → Runtime-only
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wp_object_cache;
$wp_object_cache = new HK_Object_Cache();

/**
 * HK Pure Object Cache
 */
class HK_Object_Cache {

    private $backend = 'runtime';
    private $redis = null;
    private $memcached = null;

    /** Runtime (request-only) cache */
    private $local = [];

     /** Debug mode */
    private $debug = false;

    public function __construct($debug = false) {
        $this->detect_backend();
          $this->debug = $debug;
    }

    /**
     * Detect available object cache backend
     */
    private function detect_backend() {

        /* ================= REDIS ================= */
        if (class_exists('Redis')) {
            try {
                $r = new Redis();
                if ($r->connect('127.0.0.1', 6379, 0.5)) {
                    $this->redis = $r;
                    $this->backend = 'redis';
                    $this->log("Using Redis object cache");
                    return;
                }
            } catch (\Throwable $e) {}
        }

        /* ============== MEMCACHED ============== */
        if (class_exists('Memcached')) {
            $m = new Memcached();
            $m->addServer('127.0.0.1', 11211);

            $stats = $m->getStats();
            if (!empty($stats)) {
                $this->memcached = $m;
                $this->backend = 'memcached';
                $this->log("Using Memcached object cache");
                return;
            }
        }

        /* ================= APCu ================= */
        if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            $this->backend = 'apcu';
            $this->log("Using APCu object cache");
            return;
        }

        /* ================= RUNTIME ONLY ================= */
        $this->backend = 'runtime';
        $this->log("Using Runtime-only object cache");
    }

    /**
     * Log message if debug mode is enabled
     */
    private function log($message) {
        if ($this->debug) {
            $message .= "\n";
            error_log(current_time('mysql') . ": $message");
        }
    }

    /**
     * Namespaced cache key
     */
    private function key($key, $group) {
        return 'wp:' . get_current_blog_id() . ':' . $group . ':' . $key;
    }

    /* ==================================================
     * Core Object Cache API
     * ================================================== */

    public function get($key, $group = 'default') {
        $k = $this->key($key, $group);

        switch ($this->backend) {

            case 'redis':
                $v = $this->redis->get($k);
                return $v === false ? false : maybe_unserialize($v);

            case 'memcached':
                return $this->memcached->get($k);

            case 'apcu':
                $success = false;
                $v = apcu_fetch($k, $success);
                return $success ? $v : false;

            case 'runtime':
                return $this->local[$k] ?? false;
        }

        return false;
    }

    public function set($key, $value, $group = 'default', $ttl = 0) {
        $k = $this->key($key, $group);
        $ttl = (int) $ttl;

        switch ($this->backend) {

            case 'redis':
                return $this->redis->setex(
                    $k,
                    $ttl ?: 3600,
                    maybe_serialize($value)
                );

            case 'memcached':
                return $this->memcached->set($k, $value, $ttl);

            case 'apcu':
                return apcu_store($k, $value, $ttl);

            case 'runtime':
                $this->local[$k] = $value;
                return true;
        }

        return false;
    }

    public function delete($key, $group = 'default') {
        $k = $this->key($key, $group);

        switch ($this->backend) {

            case 'redis':
                return (bool) $this->redis->del($k);

            case 'memcached':
                return $this->memcached->delete($k);

            case 'apcu':
                return apcu_delete($k);

            case 'runtime':
                unset($this->local[$k]);
                return true;
        }

        return false;
    }

    public function flush() {
        switch ($this->backend) {

            case 'redis':
                return $this->redis->flushDB();

            case 'memcached':
                return $this->memcached->flush();

            case 'apcu':
                return apcu_clear_cache();

            case 'runtime':
                $this->local = [];
                return true;
        }

        return false;
    }
}

/* ==================================================
 * WordPress Required Wrapper Functions
 * ================================================== */

// function wp_cache_get($key, $group = 'default') {
//     global $wp_object_cache;
//     return $wp_object_cache->get($key, $group);
// }

// function wp_cache_set($key, $value, $group = 'default', $ttl = 0) {
//     global $wp_object_cache;
//     return $wp_object_cache->set($key, $value, $group, $ttl);
// }

// function wp_cache_delete($key, $group = 'default') {
//     global $wp_object_cache;
//     return $wp_object_cache->delete($key, $group);
// }

// function wp_cache_flush() {
//     global $wp_object_cache;
//     return $wp_object_cache->flush();
// }
