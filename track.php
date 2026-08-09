<?php

/**
 * Analytics tracking beacon.
 *
 * Receives POST requests (via navigator.sendBeacon) from public site pages and
 * records page view data in the page_views table.
 *
 * Privacy:
 *  - IP address is stored as an HMAC-SHA256 hash only (never raw).
 *  - No cookies are set or read.
 *  - Owner opt-out: visit /?ti=exclude to set a localStorage flag that
 *    prevents the beacon JS from sending requests.
 */

declare(strict_types=1);

// This endpoint is public and returns no body — never let PHP render an error
// page carrying the data path to a caller.
ini_set('display_errors', '0');

// Only accept POST from navigator.sendBeacon.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Parse JSON body sent by sendBeacon.
$raw  = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);

if (!is_array($data)) {
    http_response_code(400);
    exit;
}

// Sanitise URL — keep only the path component, strip control characters.
$url = (string) ($data['url'] ?? '');
$url = parse_url($url, PHP_URL_PATH) ?: '/';
$url = preg_replace('/[\x00-\x1F\x7F]/', '', $url);
$url = mb_substr($url, 0, 500);

// Sanitise referrer — keep origin + path only, strip query strings and control characters.
$referrer = (string) ($data['referrer'] ?? '');
if ($referrer !== '') {
    $parts    = parse_url($referrer);
    $referrer = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '');
    $referrer = preg_replace('/[\x00-\x1F\x7F]/', '', $referrer);
    $referrer = mb_substr($referrer, 0, 500);
    if ($referrer === '://') {
        $referrer = '';
    }
}

$is404 = !empty($data['is404']) ? 1 : 0;

// Detect device type from User-Agent.
$ua         = $_SERVER['HTTP_USER_AGENT'] ?? '';
$deviceType = 'desktop';
if (preg_match('/tablet|ipad|playbook|silk/i', $ua)) {
    $deviceType = 'tablet';
} elseif (preg_match('/mobile|android|iphone|ipod|blackberry|opera mini|iemobile|wpdesktop/i', $ua)) {
    $deviceType = 'mobile';
}

$timestamp = time();

// Raw PDO — skips the autoloader and migration check for minimal overhead.
try {
    $pdo = new PDO(
        'sqlite:' . __DIR__ . '/data/cms.db',
        null,
        null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec('PRAGMA journal_mode=WAL');
    // A build holding the write lock would otherwise make the beacon fail
    // instantly with SQLITE_BUSY and silently drop the view.
    $pdo->exec('PRAGMA busy_timeout=3000');
    // Under WAL this is crash-safe against process death and only risks losing
    // the last commits on a host power cut — an acceptable trade for page-view
    // rows, and it removes an fsync from every beacon.
    $pdo->exec('PRAGMA synchronous=NORMAL');

    // The salt never changes once seeded (Database::migrate() creates it), so
    // the per-request SELECT is cached on disk beside the database. data/ is
    // denied by nginx, and the file is written 0600.
    $saltFile = __DIR__ . '/data/analytics_salt';
    $salt     = is_file($saltFile) ? (string) file_get_contents($saltFile) : '';

    if ($salt === '') {
        $saltRow = $pdo->query("SELECT value FROM settings WHERE key = 'analytics_salt'")->fetch();
        $salt    = (string) ($saltRow['value'] ?? '');

        // Only reachable on an install that has never run a migration.
        if ($salt === '') {
            $salt = bin2hex(random_bytes(32));
            $pdo->prepare(
                "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('analytics_salt', ?, CURRENT_TIMESTAMP)"
            )->execute([$salt]);
            // Re-read: INSERT OR IGNORE is a no-op if another request won the
            // race, and that request's salt is the one already hashed against.
            $saltRow = $pdo->query("SELECT value FROM settings WHERE key = 'analytics_salt'")->fetch();
            $salt    = (string) ($saltRow['value'] ?? $salt);
        }

        // Write via a temp file so a concurrent reader never sees a partial salt.
        $tmp = $saltFile . '.' . getmypid();
        if (@file_put_contents($tmp, $salt) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $saltFile);
        }
    }

    // Hash IP with HMAC-SHA256 using the server-side salt.
    // The salt makes enumeration of the IPv4 space infeasible without the secret.
    $ipHash = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', $salt);

    // Rate limit: max 30 beacons per IP per minute.
    $rateStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM page_views WHERE ip_hash = ? AND timestamp > ?"
    );
    $rateStmt->execute([$ipHash, $timestamp - 60]);
    if ((int) $rateStmt->fetchColumn() > 30) {
        http_response_code(429);
        exit;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO page_views (url, referrer, device_type, is_404, ip_hash, timestamp)
         VALUES (:url, :referrer, :device_type, :is_404, :ip_hash, :timestamp)'
    );
    $stmt->execute([
        ':url'         => $url,
        ':referrer'    => $referrer !== '' ? $referrer : null,
        ':device_type' => $deviceType,
        ':is_404'      => $is404,
        ':ip_hash'     => $ipHash,
        ':timestamp'   => $timestamp,
    ]);
} catch (\Throwable $e) {
    // Log the error but never expose DB path or details to the caller.
    error_log('track.php error: ' . $e->getMessage());
}

http_response_code(204);
