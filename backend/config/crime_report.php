<?php

return [

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    'free_report_credits' => (int) env('FREE_REPORT_CREDITS', 3),

    'free_radius_km' => (int) env('FREE_RADIUS_KM', 1),

    // Open MVP: skip login/credit/plan gates until billing ships.
    'enforce_billing' => (bool) env('ENFORCE_BILLING', false),

    'allowed_radii_km' => [1, 3, 5, 10],

    'paid_radii_km' => array_map(
        'intval',
        array_filter(explode(',', (string) env('PAID_RADII_KM', '3,5,10')))
    ),

    'rings' => [
        ['key' => '0-1', 'min' => 0.0, 'max' => 1.0],
        ['key' => '1-3', 'min' => 1.0, 'max' => 3.0],
        ['key' => '3-5', 'min' => 3.0, 'max' => 5.0],
        ['key' => '5-10', 'min' => 5.0, 'max' => 10.0],
    ],

    'max_search_radius_km' => 10,

    'news_max_articles_per_report' => (int) env('NEWS_MAX_ARTICLES_PER_REPORT', 20),

    // Legacy GDELT/RSS fallback is slow — off by default.
    'news_legacy_fallback' => (bool) env('NEWS_LEGACY_FALLBACK', false),

    'news_providers' => array_filter(explode(',', (string) env('NEWS_PROVIDERS', ''))),

    'disclaimer' => 'News reports cover only part of actual crime. A low count does not mean an area is safe.',

    'geocoder' => env('GEOCODER_DRIVER', 'nominatim'),

    'llm' => [
        'base_url' => rtrim((string) env('LLM_BASE_URL', 'https://api.openai.com/v1'), '/'),
        'api_key' => env('LLM_API_KEY'),
        'model' => env('LLM_MODEL', 'gpt-4o-mini'),
        'max_tokens' => (int) env('LLM_MAX_TOKENS', 1200),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'prices' => [
            'starter' => env('STRIPE_PRICE_STARTER'),
            'pro' => env('STRIPE_PRICE_PRO'),
            'credits_pack' => env('STRIPE_PRICE_CREDITS_PACK'),
        ],
    ],

    'plans' => [
        'free' => [
            'name' => 'Free',
            'credits' => 3,
            'max_radius_km' => 1,
            'price_monthly' => 0,
        ],
        'starter' => [
            'name' => 'Starter',
            'credits' => 20,
            'max_radius_km' => 5,
            'price_monthly' => 19,
        ],
        'pro' => [
            'name' => 'Pro',
            'credits' => 100,
            'max_radius_km' => 10,
            'price_monthly' => 49,
        ],
    ],

];
