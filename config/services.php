<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    
    
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],
    
    'cybersource' => [
        'access_key' => env('CYBERSOURCE_ACCESS_KEY'),
        'profile_id' => env('CYBERSOURCE_PROFILE_ID'),
        'secret_key' => env('CYBERSOURCE_SECRET_KEY'),
        'test_mode' => env('CYBERSOURCE_TEST_MODE', true),
    ],
 
    'kopokopo' => [
        'client_id' => env('KOPOKOPO_CLIENT_ID'),
        'client_secret' => env('KOPOKOPO_CLIENT_SECRET'),
        'api_key' => env('KOPOKOPO_API_KEY'),
        'base_url' => env('KOPOKOPO_BASE_URL'),
        'till_number' => env('KOPOKOPO_TILL_NUMBER'),
    ],
    
    'django' => [
        'base_url' => env('DJANGO_API_BASE', 'https://polytech.co.ke/payment_service/api/payments'),
    ],



];
