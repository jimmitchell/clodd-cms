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
 * GET  ?action=passkey_register_options   Generate registration challenge (auth required)
 * POST ?action=passkey_register           Complete registration (auth required)
 * GET  ?action=passkey_auth_options       Generate authentication challenge (public)
 * POST ?action=passkey_auth              Verify assertion and log in (public)
 */

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

// ── Register options (auth required) ─────────────────────────────────────────

if ($action === 'passkey_register_options' && $method === 'GET') {
    $auth->check();

    try {
        passkey_json(json_decode($auth->passkeyRegisterOptions()));
    } catch (\Throwable $e) {
        passkey_error($e->getMessage(), 500);
    }
}

// ── Register complete (auth required) ────────────────────────────────────────

if ($action === 'passkey_register' && $method === 'POST') {
    $auth->check();

    $body = json_decode((string) file_get_contents('php://input'), true) ?? [];

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

    if ($credentialId === '' || $clientDataJSON === '' || $authenticatorData === '' || $signature === '') {
        passkey_error('Missing credential data.');
    }

    try {
        $ok = $auth->passkeyAuthVerify($credentialId, $clientDataJSON, $authenticatorData, $signature);
    } catch (\Throwable $e) {
        passkey_error($e->getMessage(), 400);
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
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
