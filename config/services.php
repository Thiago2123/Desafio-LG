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
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // A chave fica no servidor; o navegador conhece apenas a rota interna do Laravel.
    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'endpoint' => env('GROQ_API_ENDPOINT', 'https://api.groq.com/openai/v1/chat/completions'),
        'model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
        'timeout_seconds' => (int) env('GROQ_TIMEOUT_SECONDS', 20),
        'cache_seconds' => (int) env('GROQ_CACHE_SECONDS', 300),
    ],

];
