<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Optional post-login destination. Only the IndieAuth consent screen may be a
// target — everything else lands on the dashboard (open-redirect guard).
$returnTo = (string) ($_GET['return_to'] ?? $_POST['return_to'] ?? '');
if (!str_starts_with($returnTo, '/indieauth.php')) {
    $returnTo = '';
}
$loginDest = $returnTo !== '' ? $returnTo : '/admin/analytics.php';

// Already signed in → go to dashboard (or back to the consent screen). An idle
// session is expired here rather than sent on to a page whose check() would
// only bounce it straight back.
if ($auth->sessionIsLive()) {
    header('Location: ' . $loginDest);
    exit;
}

// Expire a stale TOTP-pending state (5-minute window).
if ($auth->isTotpPending() && (time() - ($_SESSION['totp_pending_at'] ?? 0)) > 300) {
    unset($_SESSION['totp_pending'], $_SESSION['totp_pending_user'], $_SESSION['totp_pending_at']);
}

// Allow cancelling a pending TOTP step (returns to password form).
if (isset($_GET['cancel']) && $auth->isTotpPending()) {
    unset($_SESSION['totp_pending'], $_SESSION['totp_pending_user'], $_SESSION['totp_pending_at']);
    header('Location: /admin/');
    exit;
}

$error   = '';
$lockout = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if ($auth->isTotpPending()) {
        // Step 2: verify TOTP code or backup code.
        $code = trim($_POST['totp_code'] ?? '');

        if ($auth->isTotpLockedOut($ip)) {
            $lockout = true;
            $error   = 'Too many failed attempts. Try again later.';
        } elseif ($auth->verifyTotp($code) || $auth->verifyBackupCode($code)) {
            $auth->recordTotpAttempt($ip, true);
            $auth->completeTotpLogin();
            header('Location: ' . $loginDest);
            exit;
        } else {
            $auth->recordTotpAttempt($ip, false);
            if ($auth->isTotpLockedOut($ip)) {
                $lockout = true;
                $error   = 'Too many failed attempts. Try again later.';
            } else {
                $error = 'Invalid code. Please try again.';
            }
        }
    } else {
        // Step 1: verify password.
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($auth->isLockedOut($ip)) {
            $lockout = true;
            $secs    = $auth->lockoutSecondsRemaining($ip);
            $mins    = (int) ceil($secs / 60);
            $error   = "Too many failed attempts. Try again in {$mins} minute(s).";
        } elseif ($auth->login($username, $password)) {
            if ($auth->isTotpPending()) {
                // 2FA required — fall through to render TOTP form.
            } else {
                header('Location: ' . $loginDest);
                exit;
            }
        } else {
            if ($auth->isLockedOut($ip)) {
                $lockout = true;
                $secs    = $auth->lockoutSecondsRemaining($ip);
                $mins    = (int) ceil($secs / 60);
                $error   = "Too many failed attempts. Try again in {$mins} minute(s).";
            } else {
                $error = 'Invalid username or password.';
            }
        }
    }
}

$step         = $auth->isTotpPending() ? 'totp' : 'password';
$csrf         = $auth->csrfToken();
$siteTitle    = $db->getSetting('site_title', 'My CMS');
$hasPasskeys  = $auth->hasPasskeys();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login</title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="login-page">

<div class="login-box">
    <h1>Login to <?= htmlspecialchars($siteTitle) ?></h1>

    <?php if ($error !== ''): ?>
        <p class="alert alert--error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if ($hasPasskeys && $step === 'password'): ?>

        <button type="button" class="btn btn--passkey" id="passkey-btn">
            Sign in with a passkey
        </button>

        <p id="passkey-error" class="alert alert--error" style="display:none"></p>

        <div class="login-divider"><span>or</span></div>

    <?php endif; ?>

    <?php if ($step === 'totp'): ?>

        <p style="margin-bottom:1rem;color:var(--text-muted,#6b7280)">
            Enter the 6-digit code from your authenticator app, or a backup code.
        </p>

        <form method="post" action="/admin/" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <?php if ($returnTo !== ''): ?>
            <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">
            <?php endif; ?>

            <label for="totp_code">Authentication code</label>
            <input
                type="text"
                id="totp_code"
                name="totp_code"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="20"
                required
                autofocus
                <?= $lockout ? 'disabled' : '' ?>>

            <button type="submit" class="btn" <?= $lockout ? 'disabled' : '' ?>>Verify</button>
        </form>

        <p style="margin-top:1rem;font-size:.875rem">
            <a href="/admin/?cancel=1">← Use a different account</a>
        </p>

    <?php else: ?>

        <form method="post" action="/admin/" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <?php if ($returnTo !== ''): ?>
            <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">
            <?php endif; ?>

            <label for="username">Username</label>
            <input
                type="text"
                id="username"
                name="username"
                autocomplete="username"
                required
                <?= $lockout ? 'disabled' : '' ?>
                value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">

            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                autocomplete="current-password"
                required
                <?= $lockout ? 'disabled' : '' ?>>

            <button type="submit" class="btn" <?= $lockout ? 'disabled' : '' ?>>Log in</button>
        </form>

    <?php endif; ?>
</div>


<?php if ($hasPasskeys && $step === 'password'): ?>
<script>
(function () {
    function b64urlToArrayBuffer(b64) {
        var pad = (4 - b64.length % 4) % 4;
        var b64std = b64.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice(0, pad);
        var bin = atob(b64std);
        var buf = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
        return buf.buffer;
    }

    function arrayBufferToB64url(buf) {
        var bin = String.fromCharCode.apply(null, new Uint8Array(buf));
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }

    var btn = document.getElementById('passkey-btn');
    var errEl = document.getElementById('passkey-error');

    function showError(msg) {
        errEl.textContent = msg;
        errEl.style.display = '';
        btn.disabled = false;
    }

    btn.addEventListener('click', async function () {
        btn.disabled = true;
        errEl.style.display = 'none';

        try {
            var optResp = await fetch('/admin/passkey-api.php?action=passkey_auth_options');
            if (!optResp.ok) throw new Error('Could not start passkey authentication.');
            var options = await optResp.json();

            options.publicKey.challenge = b64urlToArrayBuffer(options.publicKey.challenge);
            if (options.publicKey.allowCredentials) {
                options.publicKey.allowCredentials = options.publicKey.allowCredentials.map(function (c) {
                    return Object.assign({}, c, { id: b64urlToArrayBuffer(c.id) });
                });
            }

            var credential = await navigator.credentials.get(options);

            var assertResp = await fetch('/admin/passkey-api.php?action=passkey_auth', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id:    credential.id,
                    rawId: arrayBufferToB64url(credential.rawId),
                    type:  credential.type,
                    response: {
                        clientDataJSON:    arrayBufferToB64url(credential.response.clientDataJSON),
                        authenticatorData: arrayBufferToB64url(credential.response.authenticatorData),
                        signature:         arrayBufferToB64url(credential.response.signature),
                        userHandle: credential.response.userHandle
                            ? arrayBufferToB64url(credential.response.userHandle) : null
                    }
                })
            });

            var result = await assertResp.json();
            if (result.ok) {
                window.location = <?= json_encode($loginDest, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            } else {
                showError(result.error || 'Passkey authentication failed.');
            }
        } catch (e) {
            if (e.name !== 'NotAllowedError') {
                showError('Passkey error: ' + e.message);
            } else {
                btn.disabled = false;
            }
        }
    });
}());
</script>
<?php endif; ?>

</body>
</html>
