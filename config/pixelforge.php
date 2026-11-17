<?php

return [
    'base_url' => rtrim(env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'), '/'),
    'api_key' => env('PIXELFORGE_API_KEY'),
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Reject webhooks whose signed timestamp is older (or newer) than this many seconds.
    'webhook_tolerance' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),

    // Users pay Pixelforge's cost plus this markup, in basis points (2500 = 25%).
    'markup_bps' => (int) env('PIXELFORGE_MARKUP_BPS', 2500),

    // How long we keep trying to hand a generation to Pixelforge (rate limits, overloads).
    'submit_window_minutes' => (int) env('PIXELFORGE_SUBMIT_WINDOW_MINUTES', 10),

    // Comma-separated emails that may open the operator report.
    'admin_emails' => array_values(array_filter(array_map(
        fn ($email) => strtolower(trim($email)),
        explode(',', (string) env('ADMIN_EMAILS', ''))
    ))),
];
