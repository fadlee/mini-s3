<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Data Directory
    |--------------------------------------------------------------------------
    | 
    | The directory where all S3 objects will be stored
    */
    'data_dir' => env('DATA_DIR', base_path('data')),
    
    /*
    |--------------------------------------------------------------------------
    | Maximum Request Size
    |--------------------------------------------------------------------------
    | 
    | Maximum size for upload requests in bytes
    */
    'max_request_size' => (int) env('MAX_REQUEST_SIZE', 100 * 1024 * 1024),
    
    /*
    |--------------------------------------------------------------------------
    | Allowed Access Keys
    |--------------------------------------------------------------------------
    | 
    | List of allowed access keys for authentication
    */
    'allowed_access_keys' => array_filter(
        array_map('trim', explode(',', env('ALLOWED_ACCESS_KEYS', 'minioadmin')))
    ),
];
