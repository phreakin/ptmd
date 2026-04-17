<?php

/**
 * PTMD Application Bootstrap
 *
 * Loaded by every entry point.  Sets up config, timezone, session, and
 * pulls in the DB layer + helper functions.
 */

// Load config into global so db.php / functions.php can reference it
$GLOBALS['config'] = require __DIR__ . '/config.php';

// Timezone
date_default_timezone_set($GLOBALS['config']['timezone']);

// Session
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($GLOBALS['config']['session_name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false,   // set true behind HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
