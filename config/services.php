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


    'moniepoint' => [
        'key' => env('MONIEPOINT_KEY'),
        'cookie' => env('MONIEPOINT_COOKIE'),
        'terminal_serial' => env('TERMINAL_SERIAL'),
        'api_url' => env('MONIEPOINT_API_URL', 'https://api.pos.moniepoint.com/v1/transactions'),
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'renewal_grace_days' => max(0, (int) env('PAYSTACK_RENEWAL_GRACE_DAYS', 3)),
    ],

    'webpush' => [
        'subject' => env('WEBPUSH_SUBJECT', 'mailto:support@clickinvoice.app'),
        'public_key' => env('WEBPUSH_PUBLIC_KEY'),
        'private_key' => env('WEBPUSH_PRIVATE_KEY'),
        'app_url' => env('WEBPUSH_APP_URL', 'https://app.clickinvoice.app'),
    ],


];
