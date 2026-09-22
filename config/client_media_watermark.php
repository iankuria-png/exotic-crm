<?php

return [
    /*
     * The CRM stamps client photos before relaying them to WordPress. Keep the
     * logo in the application so every market receives the same approved mark
     * and the upload never depends on a remote URL being available.
     */
    'enabled' => env('CLIENT_MEDIA_WATERMARK_ENABLED', true),
    'logo_path' => resource_path('watermarks/exotic-escorts.png'),
    'default_position' => env('CLIENT_MEDIA_WATERMARK_POSITION', 'br'),
    'default_size' => env('CLIENT_MEDIA_WATERMARK_SIZE', 'medium'),
    'opacity' => (int) env('CLIENT_MEDIA_WATERMARK_OPACITY', 82),
];
