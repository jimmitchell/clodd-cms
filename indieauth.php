<?php

declare(strict_types=1);

/**
 * IndieAuth authorization endpoint.
 *
 *   GET  — validates the authorization request and renders the consent screen
 *          (requires an authenticated admin session; redirects to the admin
 *          login with ?return_to= otherwise).
 *   POST (csrf_token)  — consent approval: issues an authorization code and
 *          redirects back to the client with code, state, and iss.
 *   POST (code)        — profile-only redemption: exchanges a code for {"me"}.
 *          Clients that only need sign-in redeem here; clients that need an
 *          access token redeem at /token.php.
 */

define('CMS_ROOT', __DIR__);
require CMS_ROOT . '/vendor/autoload.php';

use CMS\IndieAuth;
use CMS\MicropubAuth;

$config = require CMS_ROOT . '/config.php';
$db     = new \CMS\Database($config['paths']['data'] . '/cms.db');
$auth   = new \CMS\Auth($config, $db);
$indie  = new IndieAuth($db);

$siteUrl = rtrim($db->getSetting('site_url', ''), '/');
$me      = $siteUrl . '/';

$indie->purgeExpiredCodes();

/** Render a terminal HTML error page (used when redirecting back is unsafe). */
function ia_error_page(string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Authorization error</title>
<link rel="stylesheet" href="/admin/assets/admin.css"></head>
<body class="login-page"><div class="login-box">
<h1>Authorization error</h1>
<p class="alert alert--error">{$safe}</p>
</div></body></html>
HTML;
    exit;
}

// ── POST: profile-only code redemption (no session) ─────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code']) && !isset($_POST['csrf_token'])) {
    header('Cache-Control: no-store');
    $row = $indie->redeemCode(
        (string) $_POST['code'],
        (string) ($_POST['client_id'] ?? ''),
        (string) ($_POST['redirect_uri'] ?? ''),
        (string) ($_POST['code_verifier'] ?? '')
    );
    if (!$row) {
        MicropubAuth::error('invalid_grant', 'authorization code is invalid, expired, or already used');
    }
    MicropubAuth::json(['me' => (string) $row['me']]);
}

// ── POST: consent approval (authenticated admin session) ────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->startSession();
    if (!$auth->isAuthenticated()) {
        http_response_code(403);
        exit('Not authenticated.');
    }
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    $clientId    = (string) ($_POST['client_id'] ?? '');
    $clientName  = (string) ($_POST['client_name'] ?? '');
    $redirectUri = (string) ($_POST['redirect_uri'] ?? '');
    $state       = (string) ($_POST['state'] ?? '');
    $challenge   = (string) ($_POST['code_challenge'] ?? '');
    $method      = (string) ($_POST['code_challenge_method'] ?? '');

    if ($clientId === '' || $redirectUri === '') {
        ia_error_page('Missing client_id or redirect_uri.');
    }

    $sep = str_contains($redirectUri, '?') ? '&' : '?';

    if (!empty($_POST['deny'])) {
        header('Location: ' . $redirectUri . $sep . http_build_query([
            'error' => 'access_denied',
            'state' => $state,
            'iss'   => $me,
        ]));
        exit;
    }

    $granted = array_values(array_intersect(
        array_map('strval', (array) ($_POST['scopes'] ?? [])),
        IndieAuth::SCOPES
    ));

    $code = $indie->createCode($clientId, $clientName, $redirectUri, $me, implode(' ', $granted), $challenge, $method);

    header('Location: ' . $redirectUri . $sep . http_build_query([
        'code'  => $code,
        'state' => $state,
        'iss'   => $me,
    ]));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    ia_error_page('Method not allowed.', 405);
}

// ── GET: validate the authorization request and render consent ──────────────

$responseType = (string) ($_GET['response_type'] ?? 'id'); // legacy clients omit it
$clientId     = trim((string) ($_GET['client_id'] ?? ''));
$redirectUri  = trim((string) ($_GET['redirect_uri'] ?? ''));
$state        = (string) ($_GET['state'] ?? '');
$challenge    = (string) ($_GET['code_challenge'] ?? '');
$method       = strtoupper((string) ($_GET['code_challenge_method'] ?? ''));
$scopeParam   = (string) ($_GET['scope'] ?? '');

// client_id: absolute http(s) URL without a fragment.
$cid = parse_url($clientId);
if (
    $clientId === ''
    || filter_var($clientId, FILTER_VALIDATE_URL) === false
    || !in_array(strtolower($cid['scheme'] ?? ''), ['http', 'https'], true)
    || isset($cid['fragment'])
) {
    ia_error_page('client_id must be an absolute http(s) URL without a fragment.');
}

// redirect_uri: absolute http(s) URL.
$ru = parse_url($redirectUri);
if (
    $redirectUri === ''
    || filter_var($redirectUri, FILTER_VALIDATE_URL) === false
    || !in_array(strtolower($ru['scheme'] ?? ''), ['http', 'https'], true)
) {
    ia_error_page('redirect_uri must be an absolute http(s) URL.');
}

// redirect_uri registration: same origin as client_id is auto-trusted;
// otherwise it must be declared by the client (rel=redirect_uri link or
// client-metadata redirect_uris). Never redirect to an unverified URI.
$sameOrigin =
    strtolower($cid['scheme'] ?? '') === strtolower($ru['scheme'] ?? '')
    && strtolower($cid['host'] ?? '') === strtolower($ru['host'] ?? '')
    && ($cid['port'] ?? null) === ($ru['port'] ?? null);

$clientInfo = IndieAuth::fetchClientInfo($clientId);
if (!$sameOrigin && !in_array($redirectUri, $clientInfo['redirect_uris'], true)) {
    ia_error_page('redirect_uri is not registered for this client.');
}

// From here on the redirect_uri is trusted — protocol errors go back to it.
$redirectError = function (string $error, string $description) use ($redirectUri, $state, $me): never {
    $sep = str_contains($redirectUri, '?') ? '&' : '?';
    header('Location: ' . $redirectUri . $sep . http_build_query([
        'error'             => $error,
        'error_description' => $description,
        'state'             => $state,
        'iss'               => $me,
    ]));
    exit;
};

if (!in_array($responseType, ['code', 'id'], true)) {
    $redirectError('unsupported_response_type', 'response_type must be code');
}
if ($state === '') {
    $redirectError('invalid_request', 'state is required');
}
if ($responseType === 'code' && ($challenge === '' || $method !== 'S256')) {
    $redirectError('invalid_request', 'PKCE is required: code_challenge with code_challenge_method=S256');
}

$requestedScopes = IndieAuth::filterScopes($scopeParam);

// ── Login gate ───────────────────────────────────────────────────────────────

$auth->startSession();
if (!$auth->isAuthenticated()) {
    header('Location: /admin/?return_to=' . urlencode($_SERVER['REQUEST_URI'] ?? '/indieauth.php'));
    exit;
}

$csrf        = $auth->csrfToken();
$clientName  = $clientInfo['name'];
$displayName = $clientName !== '' ? $clientName : (string) ($cid['host'] ?? $clientId);

$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$scopeDescriptions = [
    'profile' => 'See your profile URL (sign-in only)',
    'create'  => 'Create posts and upload media',
    'update'  => 'Edit existing posts',
    'delete'  => 'Delete and restore posts',
    'media'   => 'Upload files to the media endpoint',
];

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authorize <?= $e($displayName) ?></title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="login-page">

<div class="login-box">
    <h1>Authorize application</h1>

    <p style="margin-bottom:1rem">
        <strong><?= $e($displayName) ?></strong><br>
        <a href="<?= $e($clientId) ?>" target="_blank" rel="noopener noreferrer"><?= $e($clientId) ?></a>
    </p>
    <p style="margin-bottom:1rem;font-size:.875rem;color:var(--text-muted,#6b7280)">
        You will be redirected to<br><code><?= $e($redirectUri) ?></code>
    </p>

    <form method="post" action="/indieauth.php">
        <input type="hidden" name="csrf_token"            value="<?= $e($csrf) ?>">
        <input type="hidden" name="client_id"             value="<?= $e($clientId) ?>">
        <input type="hidden" name="client_name"           value="<?= $e($clientName) ?>">
        <input type="hidden" name="redirect_uri"          value="<?= $e($redirectUri) ?>">
        <input type="hidden" name="state"                 value="<?= $e($state) ?>">
        <input type="hidden" name="code_challenge"        value="<?= $e($challenge) ?>">
        <input type="hidden" name="code_challenge_method" value="<?= $e($method) ?>">

        <?php if ($requestedScopes !== []): ?>
        <fieldset style="border:0;padding:0;margin:0 0 1rem">
            <legend style="font-weight:600;margin-bottom:.5rem">This application requests permission to:</legend>
            <?php foreach ($requestedScopes as $scope): ?>
            <label style="display:block;font-weight:400;margin-bottom:.35rem">
                <input type="checkbox" name="scopes[]" value="<?= $e($scope) ?>" checked>
                <?= $e($scopeDescriptions[$scope] ?? $scope) ?>
                <span style="color:var(--text-muted,#6b7280)">(<?= $e($scope) ?>)</span>
            </label>
            <?php endforeach; ?>
        </fieldset>
        <?php else: ?>
        <p style="margin-bottom:1rem">This application only verifies your identity (sign-in). No permissions are granted.</p>
        <?php endif; ?>

        <button type="submit" class="btn">Approve</button>
        <button type="submit" class="btn btn--secondary" name="deny" value="1">Deny</button>
    </form>
</div>

</body>
</html>
