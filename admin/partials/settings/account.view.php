<?php
// View partial for Settings → Account. Variables come from account.handler.php.
use CMS\Helpers;
?>
<?php foreach ($errors as $e): ?>
    <p class="alert alert--error"><?= Helpers::e($e) ?></p>
<?php endforeach; ?>

<!-- ── Change Password ──────────────────────────────────────────────── -->
<form method="post" action="/admin/settings.php?tab=account">
    <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
    <input type="hidden" name="action" value="change_password">

    <div class="panel">
        <h2>Change Password</h2>

        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password"
               autocomplete="current-password">

        <label for="new_password" style="margin-top:1rem">New password</label>
        <input type="password" id="new_password" name="new_password"
               autocomplete="new-password">
        <p class="form-hint">Minimum 12 characters.</p>

        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password"
               autocomplete="new-password">

        <div style="margin-top:1.25rem">
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                <input type="checkbox" name="revoke_tokens" value="1" checked>
                Disconnect apps as well
            </label>
            <p style="font-size:.8125rem;color:var(--muted, #666);margin:.35rem 0 0">
                Revokes every IndieAuth token and the Micropub token, so apps you have
                connected stop publishing until you reconnect them. Your password also
                signs in to the REST API and XML-RPC, so leave this on if you are
                changing it because the old one may be known. Passkeys and two-factor
                settings are not affected.
            </p>
        </div>

        <div style="margin-top:1.25rem">
            <button type="submit" class="btn">Change password</button>
        </div>
    </div>
</form>

<!-- ── Two-Factor Authentication ────────────────────────────────────── -->
<div class="panel" style="margin-top:1.5rem">
    <h2>Two-Factor Authentication</h2>

    <?php if (!$totpEnabled && !$setupMode): ?>

        <p style="margin-bottom:1rem">
            <span class="badge badge--error">Not configured</span>
            &nbsp; Add a second layer of security using an authenticator app
            (Google Authenticator, Authy, 1Password, etc.).
        </p>

        <form method="post" action="/admin/settings.php?tab=account">
            <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
            <input type="hidden" name="action" value="totp_setup_begin">
            <button type="submit" class="btn">Set up two-factor authentication</button>
        </form>

    <?php elseif ($setupMode): ?>

        <p style="margin-bottom:1rem">
            Scan this QR code with your authenticator app, then enter the
            6-digit code below to confirm setup.
        </p>

        <div style="margin-bottom:1rem">
            <?= $setupQrSvg ?>
        </div>

        <p class="form-hint" style="margin-bottom:1rem">
            <strong>Manual entry key:</strong><br>
            <code style="font-size:.9rem;letter-spacing:.1em"><?= Helpers::e($setupSecret) ?></code>
        </p>

        <div style="display:flex;gap:.75rem;align-items:flex-end">
            <form method="post" action="/admin/settings.php?tab=account">
                <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                <input type="hidden" name="action" value="totp_setup_confirm">

                <label for="totp_verify_code">Verification code</label>
                <input
                    type="text"
                    id="totp_verify_code"
                    name="totp_code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    required
                    placeholder="000000"
                    style="max-width:10rem">

                <div style="margin-top:1rem">
                    <button type="submit" class="btn">Confirm &amp; enable</button>
                </div>
            </form>
            <form method="post" action="/admin/settings.php?tab=account" style="margin-bottom:.4rem">
                <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                <input type="hidden" name="action" value="totp_setup_cancel">
                <button type="submit" class="btn-link" style="font-size:.875rem;background:none;border:0;padding:0;color:var(--color-muted);cursor:pointer;text-decoration:underline">Cancel</button>
            </form>
        </div>

    <?php else: ?>

        <p style="margin-bottom:1.25rem">
            <span class="badge badge--success">Enabled</span>
            &nbsp; Your account is protected with two-factor authentication.
        </p>

        <?php if (!empty($newBackupCodes)): ?>
            <div class="alert alert--info" style="margin-bottom:1.25rem">
                <strong>Save these backup codes now.</strong>
                They will not be shown again. Each code can only be used once.
                <pre style="margin-top:.75rem;font-family:monospace;font-size:.9rem;line-height:1.8"><?php
                    foreach ($newBackupCodes as $c) echo Helpers::e($c) . "\n";
                ?></pre>
                <button type="button" class="btn btn--secondary" style="margin-top:.5rem"
                    onclick="navigator.clipboard.writeText(<?= htmlspecialchars(json_encode(implode("\n", $newBackupCodes)), ENT_QUOTES) ?>)">
                    Copy codes
                </button>
            </div>
        <?php endif; ?>

        <!-- Regenerate backup codes -->
        <details style="margin-bottom:1rem">
            <summary style="cursor:pointer;font-weight:600;margin-bottom:.75rem">
                Regenerate backup codes
            </summary>
            <p class="form-hint" style="margin-bottom:.75rem">
                This will invalidate all existing backup codes.
            </p>
            <form method="post" action="/admin/settings.php?tab=account">
                <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                <input type="hidden" name="action" value="totp_regen_codes">
                <label for="confirm_pw_regen">Confirm with your password</label>
                <input type="password" id="confirm_pw_regen" name="confirm_pw"
                       autocomplete="current-password" style="max-width:20rem">
                <div style="margin-top:.75rem">
                    <button type="submit" class="btn btn--secondary">Regenerate codes</button>
                </div>
            </form>
        </details>

        <!-- Disable 2FA -->
        <details>
            <summary style="cursor:pointer;font-weight:600;color:var(--danger,#dc2626);margin-bottom:.75rem">
                Disable two-factor authentication
            </summary>
            <p class="form-hint" style="margin-bottom:.75rem">
                This will remove 2FA from your account. You will only need your password to log in.
            </p>
            <form method="post" action="/admin/settings.php?tab=account">
                <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                <input type="hidden" name="action" value="totp_disable">
                <label for="confirm_pw_disable">Confirm with your password</label>
                <input type="password" id="confirm_pw_disable" name="confirm_pw"
                       autocomplete="current-password" style="max-width:20rem">
                <div style="margin-top:.75rem">
                    <button type="submit" class="btn btn--danger">Disable 2FA</button>
                </div>
            </form>
        </details>

    <?php endif; ?>
</div>

<!-- ── Passkeys ─────────────────────────────────────────────────────── -->
<div class="panel" style="margin-top:1.5rem">
    <h2>Passkeys</h2>

    <p style="margin-bottom:1rem;color:var(--text-muted,#6b7280)">
        Passkeys use your device's biometrics or PIN to sign in without a password.
        You can register one passkey per device.
    </p>

    <p id="passkey-register-error" class="alert alert--error" style="display:none"></p>

    <?php if (!empty($passkeys)): ?>
        <table class="data-table" style="margin-bottom:1.25rem">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Registered</th>
                    <th>Last used</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($passkeys as $pk): ?>
                    <tr>
                        <td><?= Helpers::e($pk['name']) ?></td>
                        <td><?= Helpers::e($pk['created_at']) ?></td>
                        <td><?= Helpers::e($pk['last_used_at'] ?? '—') ?></td>
                        <td>
                            <form method="post" action="/admin/settings.php?tab=account"
                                  onsubmit="return confirm('Remove this passkey?')">
                                <input type="hidden" name="csrf_token"
                                       value="<?= Helpers::e($csrf) ?>">
                                <input type="hidden" name="action" value="passkey_remove">
                                <input type="hidden" name="passkey_id"
                                       value="<?= (int) $pk['id'] ?>">
                                <button type="submit" class="btn btn--danger btn--sm">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="margin-bottom:1rem">No passkeys registered yet.</p>
    <?php endif; ?>

    <button type="button" class="btn btn--secondary" id="passkey-register-btn">
        Register a new passkey
    </button>
</div>

<script src="/admin/assets/admin.js"></script>
<script>
(function () {
    var btn   = document.getElementById('passkey-register-btn');
    var errEl = document.getElementById('passkey-register-error');
    if (!btn) return;

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

    function showError(msg) {
        errEl.textContent = msg;
        errEl.style.display = '';
        btn.disabled = false;
    }

    btn.addEventListener('click', async function () {
        btn.disabled = true;
        errEl.style.display = 'none';

        try {
            var optResp = await fetch('/admin/passkey-api.php?action=passkey_register_options');
            if (!optResp.ok) throw new Error('Could not start passkey registration.');
            var options = await optResp.json();

            options.publicKey.challenge = b64urlToArrayBuffer(options.publicKey.challenge);
            options.publicKey.user.id   = b64urlToArrayBuffer(options.publicKey.user.id);
            if (options.publicKey.excludeCredentials) {
                options.publicKey.excludeCredentials = options.publicKey.excludeCredentials.map(function (c) {
                    return Object.assign({}, c, { id: b64urlToArrayBuffer(c.id) });
                });
            }

            var credential = await navigator.credentials.create(options);

            var name = window.prompt('Name this passkey (e.g. "MacBook Touch ID"):', 'Passkey');
            if (name === null) { btn.disabled = false; return; } // cancelled

            var regResp = await fetch('/admin/passkey-api.php?action=passkey_register', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id:   credential.id,
                    type: credential.type,
                    name: name.trim() || 'Passkey',
                    response: {
                        clientDataJSON:    arrayBufferToB64url(credential.response.clientDataJSON),
                        attestationObject: arrayBufferToB64url(credential.response.attestationObject)
                    }
                })
            });

            var result = await regResp.json();
            if (result.ok) {
                window.location.reload();
            } else {
                showError(result.error || 'Registration failed.');
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
