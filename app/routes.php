<?php

/**
 * Application Routes
 * 
 * Define all S3 API routes here
 */

use App\Controllers\S3Controller;

$controller = new S3Controller();

// Health check route (no auth required)
app()->get('/', function() {
    response()->json([
        'app' => 'Mini-S3',
        'version' => '2.0.0-refactored',
        'status' => 'operational',
        'framework' => 'Leaf PHP',
        'php' => PHP_VERSION
    ]);
});

// S3 API routes (authentication handled in controller)
// Object operations - /{bucket}/{key}
app()->put('/{bucket}/{key}', [$controller, 'putObject']);
app()->get('/{bucket}/{key}', [$controller, 'getObject']);
app()->delete('/{bucket}/{key}', [$controller, 'deleteObject']);
app()->head('/{bucket}/{key}', [$controller, 'headObject']);
app()->post('/{bucket}/{key}', [$controller, 'postObject']);

// Bucket operations - /{bucket}/
app()->get('/{bucket}/', [$controller, 'listObjects']);
app()->post('/{bucket}/', [$controller, 'postBucket']);

// 404 handler
app()->set404(function() {
    response()->status(404)->json([
        'error' => 'Not Found',
        'message' => 'The requested resource was not found'
    ]);
});
