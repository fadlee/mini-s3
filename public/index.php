<?php

/**
 * Mini-S3 - Lightweight S3-compatible object storage server
 * Entry Point
 */

error_log("=== REQUEST: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI'] . " ===");

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Set timezone
date_default_timezone_set(config('app.timezone', 'UTC'));

// Initialize Leaf app
$app = new Leaf\App();

// Load routes
require_once __DIR__ . '/../app/routes.php';

// Run the application
$app->run();
