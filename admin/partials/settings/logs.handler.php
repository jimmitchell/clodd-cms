<?php
// GET-side data prep for Settings → Logs. No POST actions.
// Included from admin/settings.php after auth check.

// Last 200 login attempts, newest first.
//
// The scope is worth showing: each auth surface — the admin form, TOTP,
// passkeys, Micropub, the REST API, XML-RPC — is rate-limited independently, so
// without it a run of failures here cannot be told apart from a broken client
// hammering one endpoint, and they call for different responses.
$attempts = $db->select(
    "SELECT ip, scope, success, attempted_at
       FROM login_attempts
      ORDER BY attempted_at DESC
      LIMIT 200"
);

// Summary stats for the last 24 h.
$stats = $db->selectOne(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS successes,
        SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failures
       FROM login_attempts
      WHERE attempted_at >= datetime('now', '-24 hours')"
);

// Last 200 activity log entries, newest first.
$activity = $db->select(
    "SELECT action, object_type, object_id, detail, ip, created_at
       FROM activity_log
      ORDER BY created_at DESC
      LIMIT 200"
);

$cfgTz = $db->getSetting('timezone', '');
$tz    = $cfgTz !== '' ? new \DateTimeZone($cfgTz) : new \DateTimeZone('UTC');

// Convert a stored UTC datetime string to the configured timezone for display.
$fmtDate = function (string $utc) use ($tz): string {
    $dt = new \DateTime($utc, new \DateTimeZone('UTC'));
    $dt->setTimezone($tz);
    return $dt->format('Y-m-d H:i:s');
};

$actionLabels = [
    'create'    => 'Created',
    'update'    => 'Updated',
    'publish'   => 'Published',
    'unpublish' => 'Unpublished',
    'schedule'  => 'Scheduled',
    'delete'    => 'Deleted',
    'upload'    => 'Uploaded',
    'settings'       => 'Settings saved',
    'password'       => 'Password changed',
    'rebuild'        => 'Site rebuilt',
    '2fa_enable'     => '2FA enabled',
    '2fa_disable'    => '2FA disabled',
    '2fa_regen_codes' => '2FA backup codes regenerated',
];

// Auth surfaces, named as the operator meets them rather than by their scope
// constant. Kept in step with Auth::SCOPE_*.
$scopeLabels = [
    \CMS\Auth::SCOPE_ADMIN     => 'Admin login',
    \CMS\Auth::SCOPE_TOTP      => 'Two-factor',
    \CMS\Auth::SCOPE_PASSKEY   => 'Passkey',
    \CMS\Auth::SCOPE_MICROPUB  => 'Micropub',
    \CMS\Auth::SCOPE_API       => 'REST API',
    \CMS\Auth::SCOPE_XMLRPC    => 'XML-RPC',
    \CMS\Auth::SCOPE_INDIEAUTH => 'IndieAuth',
];

$typeLabels = [
    'post'     => 'Post',
    'page'     => 'Page',
    'media'    => 'Media',
    'settings' => 'Settings',
    'account'  => 'Account',
    'site'     => 'Site',
];
