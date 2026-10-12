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

    // OAuth apps for connecting Gmail / Google Workspace and Microsoft 365
    // mailboxes without app passwords. Redirect URI:
    // {APP_URL}/mailboxes/oauth/{google|microsoft}/callback
    'google_mail' => [
        'client_id' => env('GOOGLE_MAIL_CLIENT_ID'),
        'client_secret' => env('GOOGLE_MAIL_CLIENT_SECRET'),
    ],

    'microsoft_mail' => [
        'client_id' => env('MICROSOFT_MAIL_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_MAIL_CLIENT_SECRET'),
        'tenant' => env('MICROSOFT_MAIL_TENANT', 'common'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
