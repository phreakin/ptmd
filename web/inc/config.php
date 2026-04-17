<?php

/**
 * PTMD Core Configuration
 *
 * All sensitive or environment-specific values are pulled from env vars so
 * this file can be committed safely.  Business / site config lives in the
 * site_settings database table and is loaded via site_setting().
 */

return [
    'db' => [
        'host'    => getenv('PTMD_DB_HOST')    ?: '127.0.0.1',
        'port'    => getenv('PTMD_DB_PORT')    ?: '3306',
        'name'    => getenv('PTMD_DB_NAME')    ?: 'ptmd',
        'user'    => getenv('PTMD_DB_USER')    ?: 'root',
        'pass'    => getenv('PTMD_DB_PASS')    ?: '',
        'charset' => 'utf8mb4',
    ],

    'session_name' => 'PTMDSESSID',
    'timezone'     => getenv('PTMD_TIMEZONE') ?: 'America/Phoenix',

    // Upload paths (relative to /web)
    'upload_dir' => __DIR__ . '/../uploads',

    // Max upload size for video files (bytes) – default 500 MB
    'max_video_upload' => 500 * 1024 * 1024,

    // Max upload size for images – default 10 MB
    'max_image_upload' => 10 * 1024 * 1024,

    // Allowed image extensions
    'allowed_image_ext' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],

    // Allowed video extensions
    'allowed_video_ext' => ['mp4', 'mov', 'webm', 'avi', 'mkv'],

    // FFmpeg binary path (override via env)
    'ffmpeg_path'  => getenv('PTMD_FFMPEG')  ?: 'ffmpeg',
    'ffprobe_path' => getenv('PTMD_FFPROBE') ?: 'ffprobe',
];
