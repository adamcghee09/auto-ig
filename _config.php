<?php
declare(strict_types=1);

// Affiliate Instagram Automation - local configuration.
// Keep this file and /data outside public web access where possible.
define('APP_NAME', 'Affiliate IG Automation');
define('APP_BASE_PATH', __DIR__);
define('DATA_DIR', APP_BASE_PATH . '/data');
define('STORAGE_DIR', APP_BASE_PATH . '/storage');
define('DB_PATH', DATA_DIR . '/app.sqlite');
define('KEY_PATH', DATA_DIR . '/app.key');
define('LOG_PATH', STORAGE_DIR . '/logs/app.log');
define('VIDEO_DIR', STORAGE_DIR . '/videos');
define('RENDER_DIR', STORAGE_DIR . '/renders');
define('SESSION_NAME', 'affiliate_ig_admin');
define('FFMPEG_BIN', getenv('FFMPEG_BIN') ?: 'ffmpeg');
define('PUBLIC_STORAGE_URL', getenv('PUBLIC_STORAGE_URL') ?: ''); // Optional absolute URL to /storage for Instagram media containers.
define('APP_TIMEZONE', 'UTC');
