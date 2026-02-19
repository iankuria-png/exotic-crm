<?php

// config/kopokopo.php

return [
    /*
    |--------------------------------------------------------------------------
    | Kopo Kopo Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration settings for Kopo Kopo payment integration
    |
    */

    'client_id' => env('KOPOKOPO_CLIENT_ID'),
    'client_secret' => env('KOPOKOPO_CLIENT_SECRET'),
    'api_key' => env('KOPOKOPO_API_KEY'),
    'base_url' => env('KOPOKOPO_BASE_URL', 'https://sandbox.kopokopo.com'),
    'till_number' => env('KOPOKOPO_TILL_NUMBER'),
    
    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    */
    'webhook_secret' => env('KOPOKOPO_WEBHOOK_SECRET'),
    
    /*
    |--------------------------------------------------------------------------
    | Environment Settings
    |--------------------------------------------------------------------------
    */
    'environment' => env('KOPOKOPO_ENVIRONMENT', 'sandbox'), // 'sandbox' or 'production'
    
    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    */
    'currency' => env('KOPOKOPO_CURRENCY', 'KES'),
    
    /*
    |--------------------------------------------------------------------------
    | Payment Channel
    |--------------------------------------------------------------------------
    */
    'payment_channel' => env('KOPOKOPO_PAYMENT_CHANNEL', 'M-PESA STK Push'),
];