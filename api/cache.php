<?php
// File: cache.php
// Path: /api/cache.php

define('CACHE_DIR', __DIR__ . '/cache');

if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

function get_cache($key) {
    $file = CACHE_DIR . '/' . $key;
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if (time() > $data['expires']) {
            unlink($file);
            return null;
        }
        return $data['content'];
    }
    return null;
}

function set_cache($key, $content, $ttl = 3600) {
    $file = CACHE_DIR . '/' . $key;
    $data = [
        'content' => $content,
        'expires' => time() + $ttl,
    ];
    file_put_contents($file, json_encode($data));
}
?>
