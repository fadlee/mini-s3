<?php

/**
 * Helper functions for Mini-S3
 */

if (!function_exists('env')) {
    /**
     * Get environment variable with optional default
     */
    function env(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        
        if ($value === false) {
            return $default;
        }
        
        // Convert string booleans
        if (in_array(strtolower($value), ['true', '(true)'])) {
            return true;
        }
        
        if (in_array(strtolower($value), ['false', '(false)'])) {
            return false;
        }
        
        return $value;
    }
}

if (!function_exists('config')) {
    /**
     * Get configuration value
     */
    function config(string $key, $default = null)
    {
        static $config = [];
        
        if (empty($config)) {
            $configPath = __DIR__ . '/../config';
            foreach (glob($configPath . '/*.php') as $file) {
                $name = basename($file, '.php');
                $config[$name] = require $file;
            }
        }
        
        $keys = explode('.', $key);
        $value = $config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
}

if (!function_exists('base_path')) {
    /**
     * Get base path of the application
     */
    function base_path(string $path = ''): string
    {
        return __DIR__ . '/../' . ltrim($path, '/');
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get storage path
     */
    function storage_path(string $path = ''): string
    {
        return config('storage.data_dir') . '/' . ltrim($path, '/');
    }
}
