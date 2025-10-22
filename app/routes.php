<?php

/**
 * Application Routes
 * 
 * Define all S3 API routes here
 */

use Leaf\App;

// Temporary health check route
app()->get('/', function() {
    response()->json([
        'app' => 'Mini-S3',
        'version' => '2.0.0-alpha',
        'status' => 'refactoring in progress',
        'framework' => 'Leaf PHP ' . App::VERSION
    ]);
});

// TODO: Add S3 API routes
// app()->put('/{bucket}/{key}', [S3Controller::class, 'putObject']);
// app()->get('/{bucket}/{key}', [S3Controller::class, 'getObject']);
// etc...
