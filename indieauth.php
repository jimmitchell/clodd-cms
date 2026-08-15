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

// Never render a PHP notice or fatal into the response: it would leak absolute
// filesystem paths, and on the JSON endpoints it also corrupts the body. Errors
// still reach the server log.
ini_set('display_errors', '0');

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

/**
 * Syntactic validation of a client_id / redirect_uri pair. Costs no outbound
 * request, so it is safe to run before the login gate.
 *
 * Terminates with an error page when the pair is unacceptable.
 *
 * @return array{cid: array, sameOrigin: bool}
 */
function ia_validate_syntax(string $clientId, string $redirectUri): array
{
    // client_id: absolute http(s) URL without a fragment.
    $cid = parse_url($clientId);
    if (
        $clientId === ''
        || $cid === false
        || filter_var($clientId, FILTER_VALIDATE_URL) === false
        || !in_array(strtolower($cid['scheme'] ?? ''), ['http', 'https'], true)
        || isset($cid['fragment'])
    ) {
        ia_error_page('client_id must be an absolute http(s) URL without a fragment.');
    }

    // redirect_uri: an absolute URL. Native apps are allowed an application-
    // specific scheme (e.g. org.example.app://callback) per IndieAuth §5.2.1;
    // those can never be same-origin with an http(s) client_id, so they always
    // fall through to the registration check. A few schemes are refused
    // outright because they would turn the Location header into script or a
    // local file read.
    $ru       = parse_url($redirectUri);
    $ruScheme = strtolower($ru['scheme'] ?? '');
    if (
        $redirectUri === ''
        || $ru === false
        || filter_var($redirectUri, FILTER_VALIDATE_URL) === false
        || $ruScheme === ''
        || in_array($ruScheme, ['javascript', 'data', 'vbscript', 'file'], true)
    ) {
        ia_error_page('redirect_uri must be an absolute URL with a safe scheme.');
    }

    // Same origin as client_id is auto-trusted; anything else must be declared
    // by the client, which is what ia_validate_registration() checks.
    $sameOrigin =
        strtolower($cid['scheme'] ?? '') === $ruScheme
        && strtolower($cid['host'] ?? '') === strtolower($ru['host'] ?? '')
        && ($cid['port'] ?? null) === ($ru['port'] ?? null);

    return ['cid' => $cid, 'sameOrigin' => $sameOrigin];
}

/**
 * Fetch the client's metadata and confirm the redirect_uri is one it declared.
 * Never redirect to an unverified URI.
 *
 * Both the GET (consent screen) and POST (consent approval) paths run this. The
 * approval POST carries client_id and redirect_uri in hidden fields, so trusting
 * them would let a crafted form redirect an authorization code anywhere.
 *
 * @return array{name: string, redirect_uris: string[]}
 */
function ia_validate_registration(string $clientId, string $redirectUri, bool $sameOrigin): array
{
    $info = IndieAuth::fetchClientInfo($clientId);

    if (!$sameOrigin && !in_array($redirectUri, $info['redirect_uris'], true)) {
        ia_error_page('redirect_uri is not registered for this client.');
    }

    return $info;
}

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

    // Metered under the same scope as token.php: this is the other door onto
    // redeemCode(), and leaving one of the two uncounted would make the meter
    // on the first pointless.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (\CMS\Auth::isLockedOutIn($db, $config, $ip, \CMS\Auth::SCOPE_INDIEAUTH)) {
        MicropubAuth::error('invalid_request', 'too many failed attempts; try again later', 429);
    }

    $row = $indie->redeemCode(
        (string) $_POST['code'],
        (string) ($_POST['client_id'] ?? ''),
        (string) ($_POST['redirect_uri'] ?? ''),
        (string) ($_POST['code_verifier'] ?? '')
    );
    if (!$row) {
        \CMS\Auth::recordFailureIn($db, $ip, \CMS\Auth::SCOPE_INDIEAUTH);
        MicropubAuth::error('invalid_grant', 'authorization code is invalid, expired, or already used');
    }
    MicropubAuth::json(['me' => (string) $row['me']]);
}

// ── POST: consent approval (authenticated admin session) ────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->startSession();
    if (!$auth->sessionIsLive()) {
        http_response_code(403);
        exit('Not authenticated.');
    }
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    $clientId    = trim((string) ($_POST['client_id'] ?? ''));
    $redirectUri = trim((string) ($_POST['redirect_uri'] ?? ''));
    $state       = (string) ($_POST['state'] ?? '');
    $challenge   = (string) ($_POST['code_challenge'] ?? '');
    $method      = strtoupper((string) ($_POST['code_challenge_method'] ?? ''));

    // Re-validate rather than trusting the hidden fields. Without this, a form
    // submitted with a rewritten redirect_uri would send the authorization code
    // to an address the client never registered.
    $syntax = ia_validate_syntax($clientId, $redirectUri);
    $info   = ia_validate_registration($clientId, $redirectUri, $syntax['sameOrigin']);

    // Take the display name from discovery too — the posted one is cosmetic and
    // spoofable, and it is what the admin token list shows.
    $clientName = $info['name'];

    if ($challenge !== '' && $method !== 'S256') {
        ia_error_page('code_challenge_method must be S256.');
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

// Syntax first: this costs no outbound request, so it runs before the login
// gate and rejects malformed requests without touching the network.
$syntax = ia_validate_syntax($clientId, $redirectUri);
$cid    = $syntax['cid'];

// ── Login gate ───────────────────────────────────────────────────────────────
//
// Deliberately ahead of the client-metadata fetch. Discovery makes the server
// issue an HTTP request to a URL the caller chose, so it must not be reachable
// by an unauthenticated visitor. An anonymous request cannot produce a code
// anyway, which is why nothing below here needs to redirect errors back.

$auth->startSession();
if (!$auth->sessionIsLive()) {
    header('Location: /admin/?return_to=' . urlencode($_SERVER['REQUEST_URI'] ?? '/indieauth.php'));
    exit;
}

$clientInfo = ia_validate_registration($clientId, $redirectUri, $syntax['sameOrigin']);

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
// Spec allows accepting requests without PKCE for backwards compatibility
// with older clients (e.g. micropub.rocks); when a challenge is present the
// method must be S256.
if ($responseType === 'code' && $challenge !== '' && $method !== 'S256') {
    $redirectError('invalid_request', 'code_challenge_method must be S256');
}

$requestedScopes = IndieAuth::filterScopes($scopeParam);

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
        <?php // No client_name field: the approval handler re-derives it from
              // discovery rather than trusting a value posted back. ?>
        <input type="hidden" name="csrf_token"            value="<?= $e($csrf) ?>">
        <input type="hidden" name="client_id"             value="<?= $e($clientId) ?>">
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
