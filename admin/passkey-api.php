<?php

declare(strict_types=1);

// This is a JSON API — never send PHP error HTML to the client.
ini_set('display_errors', '0');

// Buffer everything so stray output (e.g. deprecations) never corrupts JSON.
ob_start();

// Catch fatal errors that bypass try/catch and return a JSON error instead.
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Internal server error']);
    }
});

/**
 * Passkey (WebAuthn) JSON API — session-based, not Basic Auth.
 *
 * POST ?action=passkey_register_options   Generate registration challenge (password required)
 * POST ?action=passkey_register           Complete registration (auth + fresh re-auth)
 * GET  ?action=passkey_auth_options       Generate authentication challenge (public)
 * POST ?action=passkey_auth               Verify assertion and log in (public)
 *
 * A passkey outranks what it is registered from: once added it signs the owner
 * in on its own, past both the password and TOTP. So registration is gated the
 * way `totp_disable` is — CSRF plus the password — and the two halves of the
 * ceremony are tied together by a short-lived session flag, so completing it
 * needs the same re-authentication that started it. The options call is POST
 * for that reason: a password does not belong in a query string.
 */

/** How long a verified re-auth authorises a registration for. */
const PASSKEY_REAUTH_TTL = 300;

require __DIR__ . '/bootstrap.php';

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Decode a base64url string to a raw binary string. */
function b64url_decode(string $data): string
{
    $remainder = strlen($data) % 4;
    if ($remainder !== 0) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return (string) base64_decode(strtr($data, '-_', '+/'));
}

function passkey_json(mixed $data, int $status = 200): never
{
    ob_end_clean(); // discard any stray PHP notices/deprecations
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function passkey_error(string $message, int $status = 400): never
{
    passkey_json(['error' => $message], $status);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

/** The JSON request body, decoded once. */
function passkey_body(): array
{
    static $body = null;
    if ($body === null) {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    }
    return is_array($body) ? $body : [];
}

// ── Register options (password required) ─────────────────────────────────────

if ($action === 'passkey_register_options' && $method === 'POST') {
    $auth->check();

    $body = passkey_body();

    if (!$auth->isCsrfValid((string) ($body['csrf_token'] ?? ''))) {
        passkey_error('Invalid request token. Reload the page and try again.', 403);
    }

    // Metered: this is a password check reachable from a live session, so it is
    // a guessing oracle if left uncounted.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (\CMS\Auth::isLockedOutIn($db, $config, $ip, \CMS\Auth::SCOPE_PASSKEY)) {
        passkey_error('Too many failed attempts. Try again later.', 429);
    }

    if (!password_verify((string) ($body['confirm_pw'] ?? ''), $config['admin']['password_hash'] ?? '')) {
        \CMS\Auth::recordFailureIn($db, $ip, \CMS\Auth::SCOPE_PASSKEY);
        passkey_error('Password is incorrect.', 403);
    }

    $_SESSION['passkey_reauth_at'] = time();

    try {
        passkey_json(json_decode($auth->passkeyRegisterOptions()));
    } catch (\Throwable $e) {
        passkey_error($e->getMessage(), 500);
    }
}

// ── Register complete (auth + fresh re-auth) ─────────────────────────────────

if ($action === 'passkey_register' && $method === 'POST') {
    $auth->check();

    $body = passkey_body();

    if (!$auth->isCsrfValid((string) ($body['csrf_token'] ?? ''))) {
        passkey_error('Invalid request token. Reload the page and try again.', 403);
    }

    // Consume the re-auth regardless of what happens below, so one password
    // check can never authorise more than the one registration it started.
    $reauthAt = (int) ($_SESSION['passkey_reauth_at'] ?? 0);
    unset($_SESSION['passkey_reauth_at']);

    if ($reauthAt === 0 || (time() - $reauthAt) > PASSKEY_REAUTH_TTL) {
        passkey_error('Confirm your password again to register a passkey.', 403);
    }

    $name              = substr(trim($body['name'] ?? 'Passkey'), 0, 100) ?: 'Passkey';
    $clientDataJSON    = b64url_decode($body['response']['clientDataJSON']    ?? '');
    $attestationObject = b64url_decode($body['response']['attestationObject'] ?? '');

    if ($clientDataJSON === '' || $attestationObject === '') {
        passkey_error('Missing credential data.');
    }

    try {
        $cred = $auth->passkeyRegisterComplete($clientDataJSON, $attestationObject);
        $db->insert('passkeys', [
            'credential_id' => $cred['credential_id'],
            'public_key'    => $cred['public_key'],
            'sign_count'    => $cred['sign_count'],
            'name'          => $name,
        ]);
        $activityLog->log('passkey_add', 'security', null, 'Passkey: ' . $name);
        passkey_json(['ok' => true]);
    } catch (\Throwable $e) {
        passkey_error($e->getMessage(), 400);
    }
}

// ── Auth options (public) ─────────────────────────────────────────────────────

if ($action === 'passkey_auth_options' && $method === 'GET') {
    // Public and unauthenticated: metered under the same scope as the verify
    // step so a caller cannot mint unlimited challenges.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (\CMS\Auth::isLockedOutIn($db, $config, $ip, \CMS\Auth::SCOPE_PASSKEY)) {
        passkey_error('Too many failed attempts. Try again later.', 429);
    }

    try {
        passkey_json(json_decode($auth->passkeyAuthOptions()));
    } catch (\Throwable $e) {
        passkey_error($e->getMessage(), 500);
    }
}

// ── Auth verify (public) ──────────────────────────────────────────────────────

if ($action === 'passkey_auth' && $method === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true) ?? [];

    $credentialId      = $body['id'] ?? '';
    $clientDataJSON    = b64url_decode($body['response']['clientDataJSON']    ?? '');
    $authenticatorData = b64url_decode($body['response']['authenticatorData'] ?? '');
    $signature         = b64url_decode($body['response']['signature']         ?? '');

    // This endpoint is public, so without a lockout every WebAuthn assertion is
    // an unmetered public-key verification an anonymous caller can trigger.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (\CMS\Auth::isLockedOutIn($db, $config, $ip, \CMS\Auth::SCOPE_PASSKEY)) {
        passkey_error('Too many failed attempts. Try again later.', 429);
    }

    if ($credentialId === '' || $clientDataJSON === '' || $authenticatorData === '' || $signature === '') {
        passkey_error('Missing credential data.');
    }

    try {
        $ok = $auth->passkeyAuthVerify($credentialId, $clientDataJSON, $authenticatorData, $signature);
    } catch (\Throwable $e) {
        // A throwing assertion is still a failed attempt. Recording it here too
        // stops malformed input from being an unmetered way past the counter.
        \CMS\Auth::recordFailureIn($db, $ip, \CMS\Auth::SCOPE_PASSKEY);
        passkey_error($e->getMessage(), 400);
    }

    $db->insert('login_attempts', [
        'ip'      => $ip,
        'scope'   => \CMS\Auth::SCOPE_PASSKEY,
        'success' => $ok ? 1 : 0,
    ]);

    if ($ok) {
        $activityLog->log('passkey_login', 'security', null, 'Authenticated via passkey');
        passkey_json(['ok' => true]);
    } else {
        passkey_error('Passkey authentication failed.', 401);
    }
}

passkey_error('Not found.', 404);
