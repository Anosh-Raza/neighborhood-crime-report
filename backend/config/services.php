<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'nominatim' => [
        'base_url' => rtrim((string) env('NOMINATIM_BASE_URL', 'https://nominatim.openstreetmap.org'), '/'),
        'user_agent' => env('NOMINATIM_USER_AGENT', 'NeighborhoodCrimeReport/1.0'),
    ],

    'overpass' => [
        'url' => env('OVERPASS_URL', 'https://overpass-api.de/api/interpreter'),
    ],

    'gdelt' => [
        'base_url' => env('GDELT_BASE_URL', 'https://api.gdeltproject.org/api/v2/doc/doc'),
    ],

    'newsapi' => [
        'key' => env('NEWSAPI_KEY'),
        'base_url' => 'https://newsapi.org/v2',
    ],

];
