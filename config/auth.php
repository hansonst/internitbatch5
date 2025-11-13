<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'sanctum'), // Changed to sanctum for API
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    */
    'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
    
    'sanctum' => [
        'driver' => 'sanctum',
        'provider' => 'users_sap', // Default for API authentication
    ],
],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    */
    'providers' => [
        // Provider for Production Order users (default database)
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],

        // Provider for SAP users (gr_po_it database)
        'users_sap' => [
            'driver' => 'eloquent',
            'model' => App\Models\UserSap::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    */
    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],

        // Password reset config for SAP users
        'users_sap' => [
            'provider' => 'users_sap',
            'table' => 'password_reset_tokens_sap', // Different table name
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    */
    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];