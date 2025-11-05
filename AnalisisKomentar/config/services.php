<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

        'apify' => [
        'token' => env('APIFY_TOKEN'),
        'actor' => env('APIFY_ACTOR', 'clockworks/tiktok-comments-scraper'),
        'session'  => env('APIFY_TT_SESSION', ''),
    ],

    'huggingface' => [
        'token' => env('HUGGINGFACE_TOKEN'),
        'model' => env('HUGGINGFACE_MODEL', 'w11wo/indonesian-roberta-base-sentiment-classifier'),
    ],


];
