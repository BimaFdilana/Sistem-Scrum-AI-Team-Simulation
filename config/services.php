<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

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

    'hermes' => [
        'base_url' => env('HERMES_API_BASE_URL'),
        'api_key' => env('HERMES_API_KEY'),
        'model' => env('HERMES_API_MODEL', 'scrumagent'),
        'timeout' => (int) env('HERMES_API_TIMEOUT', 120),
    ],

    'agent_profiles' => [
        'backend-developer' => [
            'base_url' => env('AI_AGENT_BACKEND_API_BASE_URL'),
            'api_key' => env('AI_AGENT_BACKEND_API_KEY'),
            'model' => env('AI_AGENT_BACKEND_MODEL', 'backend-developer'),
        ],
        'frontend-developer' => [
            'base_url' => env('AI_AGENT_FRONTEND_API_BASE_URL'),
            'api_key' => env('AI_AGENT_FRONTEND_API_KEY'),
            'model' => env('AI_AGENT_FRONTEND_MODEL', 'frontend-developer'),
        ],
        'qa-engineer' => [
            'base_url' => env('AI_AGENT_QA_API_BASE_URL'),
            'api_key' => env('AI_AGENT_QA_API_KEY'),
            'model' => env('AI_AGENT_QA_MODEL', 'qa-engineer'),
        ],
    ],

];
