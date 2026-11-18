<?php

/*
 * The ComplyRobin rotation runner, inside your Laravel app. Set the values in .env (or your host's secrets).
 *
 * The runner's AWS and Google Cloud access has its own names here, so it never uses your app's own AWS_ACCESS_KEY_ID
 * or Google credentials: those may be the very keys it rotates.
 */
return [

    // The runner token ComplyRobin showed you once, when you added the runner (crr_…).
    'token' => env('COMPLYROBIN_TOKEN'),

    // Leave empty unless ComplyRobin tells you otherwise.
    'api_url' => env('COMPLYROBIN_API_URL'),

    // Run `php artisan complyrobin:rotate` every hour from the app's scheduler. Turn off to schedule it yourself.
    'schedule' => (bool) env('COMPLYROBIN_SCHEDULE', true),

    // Where keys are made. Set only the providers you use.
    'openai_admin_key' => env('COMPLYROBIN_OPENAI_ADMIN_KEY'),

    'aws' => [
        'access_key_id' => env('COMPLYROBIN_AWS_ACCESS_KEY_ID'),
        'secret_access_key' => env('COMPLYROBIN_AWS_SECRET_ACCESS_KEY'),
        'session_token' => env('COMPLYROBIN_AWS_SESSION_TOKEN'),
    ],

    'google_cloud' => [
        'access_token' => env('COMPLYROBIN_GOOGLE_CLOUD_ACCESS_TOKEN'),
        'credentials' => env('COMPLYROBIN_GOOGLE_CLOUD_CREDENTIALS'),
    ],

    // Where keys go. AWS and Google Cloud secrets use the access above.
    'laravel_cloud_token' => env('COMPLYROBIN_LARAVEL_CLOUD_API_TOKEN'),
    'github_token' => env('COMPLYROBIN_GH_SECRETS_TOKEN'),
    'forge_token' => env('COMPLYROBIN_FORGE_API_TOKEN'),
    'vercel_token' => env('COMPLYROBIN_VERCEL_TOKEN'),

];
