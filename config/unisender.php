<?php

return [
    'api_key' => env('UNISENDER_API_KEY'),
    'retry_count' => env('UNISENDER_RETRY_COUNT', 3),
    'retry_delay' => env('UNISENDER_RETRY_DELAY', 100),
    'timeout' => env('UNISENDER_TIMEOUT', 30),
    'api_url' => env('UNISENDER_API_URL', 'https://api.unisender.com/ru/api'),
    'default_sender_name' => env('UNISENDER_DEFAULT_SENDER_NAME'),
    'default_sender_email' => env('UNISENDER_DEFAULT_SENDER_EMAIL'),
    'default_sender_phone' => env('UNISENDER_DEFAULT_SENDER_PHONE'),
];
