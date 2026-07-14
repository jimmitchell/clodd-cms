<?php
// POST handler + GET-side data prep for Settings → Micropub.
// Included from admin/settings.php after auth check. Exits on POST success.

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    $action = $_POST['action'] ?? '';

    if ($action === 'generate' || $action === 'revoke') {
        if ($action === 'generate') {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $db->upsertSetting('micropub_token', $token);
            $activityLog->log('settings', 'settings', null, 'micropub token issued');
            // One-time display after the redirect — the GET side below consumes it.
            $_SESSION['micropub_new_token'] = $token;
            $auth->flash('New Micropub token generated — copy it below. Site rebuild started in the background; check the activity log to confirm completion.');
        } else {
            $db->upsertSetting('micropub_token', '');
            $activityLog->log('settings', 'settings', null, 'micropub token revoked');
            $auth->flash('Micropub token revoked — site rebuild started in the background. Check the activity log to confirm completion.');
        }

        // Toggling the token adds/removes <link rel="micropub"> on every static
        // page, and rebuilding them all can take many minutes — long enough that
        // nginx's fastcgi_read_timeout would cut the response off. Send the
        // redirect immediately, then keep building after FastCGI hangs up.
        // Completion is recorded in the activity log.
        header('Location: /admin/settings.php?tab=micropub');

        ignore_user_abort(true);
        set_time_limit(0);
        session_write_close();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $builder->rebuildPosts();
        $builder->rebuildPages();
        $builder->rebuildSharedResources();

        $activityLog->log('settings', 'settings', null, 'micropub rebuild finished');
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
