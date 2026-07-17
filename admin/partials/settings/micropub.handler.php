<?php
// POST handler + GET-side data prep for Settings → Micropub.
// Included from admin/settings.php after auth check. Exits on POST success.

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    $action = $_POST['action'] ?? '';

    if ($action === 'generate' || $action === 'revoke') {
        // The <link rel="micropub"> advert no longer depends on the token, so
        // toggling it requires no site rebuild.
        if ($action === 'generate') {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $db->upsertSetting('micropub_token', $token);
            $activityLog->log('settings', 'settings', null, 'micropub token issued');
            // One-time display after the redirect — the GET side below consumes it.
            $_SESSION['micropub_new_token'] = $token;
            $auth->flash('New Micropub token generated — copy it below.');
        } else {
            $db->upsertSetting('micropub_token', '');
            $activityLog->log('settings', 'settings', null, 'micropub token revoked');
            $auth->flash('Micropub token revoked.');
        }

        header('Location: /admin/settings.php?tab=micropub');
        exit;
    }

    if ($action === 'revoke_token') {
        $tokenId = (int) ($_POST['token_id'] ?? 0);
        if ($tokenId > 0 && (new \CMS\IndieAuth($db))->revokeTokenById($tokenId)) {
            $activityLog->log('settings', 'settings', null, "indieauth token #{$tokenId} revoked");
            $auth->flash('App authorization revoked.');
        } else {
            $auth->flash('Token not found or already revoked.', 'error');
        }
        header('Location: /admin/settings.php?tab=micropub');
        exit;
    }

    $errors[] = 'Unknown action.';
}

// One-time token display, set by the generate action before its redirect.
$justIssued = (string) ($_SESSION['micropub_new_token'] ?? '');
unset($_SESSION['micropub_new_token']);

$siteUrl  = rtrim($db->getSetting('site_url', ''), '/');
$endpoint = ($siteUrl !== '' ? $siteUrl : '') . '/micropub.php';
$hasToken = $db->getSetting('micropub_token', '') !== '';
$csrf     = $auth->csrfToken();

$indieauthTokens    = (new \CMS\IndieAuth($db))->listTokens();
$indieauthEndpoints = [
    'Micropub endpoint'      => $endpoint,
    'Media endpoint'         => ($siteUrl !== '' ? $siteUrl : '') . '/media.php',
    'Authorization endpoint' => ($siteUrl !== '' ? $siteUrl : '') . '/indieauth.php',
    'Token endpoint'         => ($siteUrl !== '' ? $siteUrl : '') . '/token.php',
    'Server metadata'        => ($siteUrl !== '' ? $siteUrl : '') . '/indieauth-metadata.php',
];
