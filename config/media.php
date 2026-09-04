<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Profile video transcoding
    |--------------------------------------------------------------------------
    |
    | WordPress only stores MP4 for profile videos, so anything staff upload in
    | another container (QuickTime .mov straight off a phone, typically) is
    | converted to MP4 by the CRM before it is pushed to the market site. That
    | keeps the sync plugin and the theme on a single format.
    |
    | Leave the paths null to auto-detect the binaries. Shared hosting that
    | carries no system ffmpeg can point these at a static build uploaded into
    | the account, e.g. FFMPEG_PATH=/home/<user>/bin/ffmpeg.
    |
    */

    'ffmpeg_path' => env('FFMPEG_PATH'),

    'ffprobe_path' => env('FFPROBE_PATH'),

    // Hard ceiling for one conversion, in seconds.
    'transcode_timeout' => (int) env('MEDIA_TRANSCODE_TIMEOUT', 900),

    // Longest edge of the converted video. Keeps shared-hosting CPU bounded and
    // matches what the market sites actually display.
    'transcode_max_height' => (int) env('MEDIA_TRANSCODE_MAX_HEIGHT', 1280),

];
