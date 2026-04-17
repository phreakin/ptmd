<?php

/**
 * PTMD Database Connection
 *
 * Returns a singleton PDO instance.  Returns null on failure so callers can
 * degrade gracefully rather than crashing the whole page.
 */

function get_db(): ?PDO
{
    static $pdo      = null;
    static $attempted = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if ($attempted) {
        return null;
    }

    $attempted = true;
    $cfg = $GLOBALS['config']['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['name'],
        $cfg['charset']
    );

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('[PTMD] DB connection failed: ' . $e->getMessage());
        $pdo = null;
    }

    return $pdo;
}
