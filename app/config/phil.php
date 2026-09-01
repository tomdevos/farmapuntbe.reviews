<?php

return [
    // SSO credentials for phil.apb.be. Read through config (not env()) so the
    // scraper keeps working when the server runs `php artisan config:cache`.
    'user' => env('PHIL_USER'),
    'pass' => env('PHIL_PASS'),
    'headless' => env('PHIL_HEADLESS', true),
];
