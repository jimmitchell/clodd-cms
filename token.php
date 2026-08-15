<?php

declare(strict_types=1);

/**
 * IndieAuth token endpoint.
 *
 *   POST grant_type=authorization_code — exchange a code (issued by
 *        /indieauth.php) for a scoped bearer access token.
 *   POST ?action=revoke   (or action=revoke in the body) — revoke a token.
 *        Always responds 200 per spec.
 *   POST ?action=introspect — token introspection for resource servers.
 *        Requires a valid bearer token to call.
 *   GET  with Authorization: Bearer — legacy token verification, returns
 *        {me, client_id, scope} for older Micropub clients.
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
$indie  = new IndieAuth($db);

$siteUrl = rtrim($db->getSetting('site_url', ''), '/');
$me      = $siteUrl . '/';

header('Cache-Control: no-store');
header('Pragma: no-cache');

// ── GET: legacy token verification ───────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $authz = MicropubAuth::authenticate($db, $config);
    MicropubAuth::json([
        'me'        => $authz['me'],
        'client_id' => $authz['client_id'] ?? '',
        'scope'     => implode(' ', $authz['scopes']),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    MicropubAuth::error('invalid_request', 'Method not allowed', 405);
}

$action = (string) ($_GET['action'] ?? ($_POST['action'] ?? ''));

// ── POST: revocation ─────────────────────────────────────────────────────────

if ($action === 'revoke') {
    $indie->revokeToken((string) ($_POST['token'] ?? ''));
    // Spec: the endpoint responds 200 whether or not the token was valid.
    MicropubAuth::json(new stdClass());
}

// ── POST: introspection ──────────────────────────────────────────────────────

if ($action === 'introspect') {
    MicropubAuth::authenticate($db, $config);

    $subject = (string) ($_POST['token'] ?? '');
    if (MicropubAuth::legacyTokenMatches($db->getSetting('micropub_token', ''), $subject)) {
        MicropubAuth::json([
            'active'    => true,
            'me'        => $me,
            'client_id' => '',
            'scope'     => implode(' ', IndieAuth::SCOPES),
        ]);
    }

    $row = $indie->verifyToken($subject);
    if (!$row) {
        MicropubAuth::json(['active' => false]);
    }
    MicropubAuth::json([
        'active'    => true,
        'me'        => (string) $row['me'],
        'client_id' => (string) $row['client_id'],
        'scope'     => (string) $row['scope'],
    ]);
}

// ── POST: authorization-code exchange ────────────────────────────────────────

$grantType = (string) ($_POST['grant_type'] ?? '');
if ($grantType !== 'authorization_code') {
    MicropubAuth::error('unsupported_grant_type', 'grant_type must be authorization_code');
}

// Code redemption is unauthenticated and hands back a scoped bearer token, so
// it is metered like every other auth surface. Codes are 256-bit, so this is
// not what stops a guess — it is what stops an unbounded stream of attempts
// from being free, and what puts the failures in front of the operator in
// Settings → Logs. Its own scope, so a broken client here cannot lock the owner
// out of /admin/.
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (\CMS\Auth::isLockedOutIn($db, $config, $ip, \CMS\Auth::SCOPE_INDIEAUTH)) {
    MicropubAuth::error('invalid_request', 'too many failed attempts; try again later', 429);
}

$row = $indie->redeemCode(
    (string) ($_POST['code'] ?? ''),
    (string) ($_POST['client_id'] ?? ''),
    (string) ($_POST['redirect_uri'] ?? ''),
    (string) ($_POST['code_verifier'] ?? '')
);
if (!$row) {
    \CMS\Auth::recordFailureIn($db, $ip, \CMS\Auth::SCOPE_INDIEAUTH);
    MicropubAuth::error('invalid_grant', 'authorization code is invalid, expired, or already used');
}

$scope = trim((string) $row['scope']);
if ($scope === '') {
    MicropubAuth::error('invalid_grant', 'no scope was granted; redeem sign-in codes at the authorization endpoint');
}

$token = $indie->issueToken(
    (string) $row['client_id'],
    (string) ($row['client_name'] ?? ''),
    (string) $row['me'],
    $scope
);

MicropubAuth::json([
    'access_token' => $token,
    'token_type'   => 'Bearer',
    'scope'        => $scope,
    'me'           => (string) $row['me'],
]);
