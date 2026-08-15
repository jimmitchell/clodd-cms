<?php
// POST handler + GET-side data prep for Settings → Account.
// Included from admin/settings.php after auth check. Exits on POST success.

use OTPHP\TOTP;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? 'change_password';

    // ── Change password ───────────────────────────────────────────────────────
    if ($action === 'change_password') {
        $currentPw = $_POST['current_password'] ?? '';
        $newPw     = $_POST['new_password']     ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';

        if ($currentPw === '' || $newPw === '' || $confirmPw === '') {
            $errors[] = 'All fields are required.';
        } elseif (!password_verify($currentPw, $config['admin']['password_hash'] ?? '')) {
            $errors[] = 'Current password is incorrect.';
        } elseif ($newPw !== $confirmPw) {
            $errors[] = 'New passwords do not match.';
        } elseif (strlen($newPw) < 12) {
            $errors[] = 'New password must be at least 12 characters.';
        }

        if (empty($errors)) {
            $hash       = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);
            $configPath = dirname(__DIR__, 2) . '/config.php';
            $fp         = fopen($configPath, 'r');
            if ($fp === false || !flock($fp, LOCK_EX)) {
                if ($fp !== false) { fclose($fp); }
                $errors[] = 'Could not lock config.php — check file permissions.';
            } else {
                $src     = stream_get_contents($fp);
                $updated = preg_replace(
                    "/'password_hash'\s*=>\s*'[^']*'/",
                    "'password_hash' => '" . str_replace(['\\', '$'], ['\\\\', '\\$'], $hash) . "'",
                    $src
                );

                if ($updated === null || $updated === $src) {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    $errors[] = 'Could not write config.php — check file permissions.';
                } else {
                    // tempnam() creates at 0600, and rename() carries the temp
                    // file's mode onto the target — so writing through it used
                    // to silently reset config.php's permissions. Copy the
                    // existing mode across so a deployment that grants the
                    // group read access keeps it after a password change.
                    $mode = @fileperms($configPath);
                    $mode = $mode === false ? 0600 : ($mode & 0777);

                    $tmp = tempnam(dirname($configPath), '.cfg_');
                    $ok  = $tmp !== false
                        && file_put_contents($tmp, $updated) !== false
                        && chmod($tmp, $mode)
                        && rename($tmp, $configPath);
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    if (!$ok) {
                        if ($tmp !== false && file_exists($tmp)) { unlink($tmp); }
                        $errors[] = 'Could not write config.php — check file permissions.';
                    } else {
                        // Rotate the session identifier and CSRF token so a
                        // session captured before the change cannot be replayed
                        // with it. `true` drops the old session file, which also
                        // invalidates any other browser still holding that id.
                        session_regenerate_id(true);
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        $_SESSION['last_seen']  = time();

                        // The password is also the REST API and XML-RPC
                        // credential, so a change made in response to a
                        // suspected compromise has to be able to take the
                        // separately-issued publishing tokens with it.
                        $revoked = 0;
                        if (!empty($_POST['revoke_tokens'])) {
                            $revoked = (new \CMS\IndieAuth($db))->revokeAllTokens();
                            if ($db->getSetting('micropub_token', '') !== '') {
                                $db->upsertSetting('micropub_token', '');
                                $revoked++;
                            }
                            $activityLog->log('settings', 'security', null, "all app tokens revoked ({$revoked})");
                        }

                        $activityLog->log('password', 'account');
                        $auth->flash($revoked > 0
                            ? "Password changed. {$revoked} app token(s) revoked."
                            : 'Password changed successfully.');
                        header('Location: /admin/settings.php?tab=account');
                        exit;
                    }
                }
            }
        }

    // ── Begin 2FA setup: generate secret, store in session, redirect ──────────
    } elseif ($action === 'totp_setup_begin') {
        $_SESSION['totp_setup_secret']    = $auth->generateTotpSecret();
        $_SESSION['totp_setup_secret_ts'] = time();
        header('Location: /admin/settings.php?tab=account');
        exit;

    // ── Confirm 2FA setup: verify code, enable, generate backup codes ─────────
    } elseif ($action === 'totp_setup_confirm') {
        $secret = $_SESSION['totp_setup_secret'] ?? '';
        if ($secret !== '' && (time() - ($_SESSION['totp_setup_secret_ts'] ?? 0)) > 900) {
            unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_secret_ts']);
            $secret = '';
        }
        $code = trim($_POST['totp_code'] ?? '');

        if ($secret === '') {
            $errors[] = 'Setup session expired. Please start again.';
        } else {
            $totp = TOTP::createFromSecret($secret);
            if ($totp->verify($code, null, 1)) {
                $auth->enableTotp($secret);
                $_SESSION['totp_new_codes'] = $auth->generateBackupCodes();
                unset($_SESSION['totp_setup_secret']);
                $activityLog->log('2fa_enable', 'account');
                $auth->flash('Two-factor authentication enabled.', 'success');
                header('Location: /admin/settings.php?tab=account');
                exit;
            } else {
                $errors[] = 'Invalid verification code. Please try again.';
            }
        }

    // ── Regenerate backup codes (requires password) ───────────────────────────
    } elseif ($action === 'totp_regen_codes') {
        $pw = $_POST['confirm_pw'] ?? '';
        if (!password_verify($pw, $config['admin']['password_hash'] ?? '')) {
            $errors[] = 'Password is incorrect.';
        } else {
            $_SESSION['totp_new_codes'] = $auth->generateBackupCodes();
            $activityLog->log('2fa_regen_codes', 'account');
            header('Location: /admin/settings.php?tab=account');
            exit;
        }

    // ── Cancel 2FA setup ─────────────────────────────────────────────────────
    } elseif ($action === 'totp_setup_cancel') {
        unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_secret_ts']);
        header('Location: /admin/settings.php?tab=account');
        exit;

    // ── Disable 2FA (requires password) ──────────────────────────────────────
    } elseif ($action === 'totp_disable') {
        $pw = $_POST['confirm_pw'] ?? '';
        if (!password_verify($pw, $config['admin']['password_hash'] ?? '')) {
            $errors[] = 'Password is incorrect.';
        } else {
            $auth->disableTotp();
            unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_secret_ts']);
            $activityLog->log('2fa_disable', 'account');
            $auth->flash('Two-factor authentication disabled.', 'success');
            header('Location: /admin/settings.php?tab=account');
            exit;
        }

    // ── Remove a passkey (requires password) ─────────────────────────────────
    // Gated like totp_disable: a passkey is a sign-in credential, so adding or
    // dropping one is an account-security change, not a preference. Removing
    // the last one can lock the owner out of a device they were relying on.
    } elseif ($action === 'passkey_remove') {
        $pw = $_POST['confirm_pw'] ?? '';
        if (!password_verify($pw, $config['admin']['password_hash'] ?? '')) {
            $errors[] = 'Password is incorrect.';
        } else {
            $id = (int) ($_POST['passkey_id'] ?? 0);
            if ($id > 0) {
                $passkey = $db->selectOne("SELECT name FROM passkeys WHERE id = :id", ['id' => $id]);
                if ($passkey !== null) {
                    $db->delete('passkeys', 'id = :id', ['id' => $id]);
                    $activityLog->log('passkey_remove', 'security', null, 'Passkey: ' . $passkey['name']);
                    $auth->flash('Passkey removed.', 'success');
                }
            }
            header('Location: /admin/settings.php?tab=account');
            exit;
        }
    }
}

$csrf = $auth->csrfToken();

// 2FA state — expire the in-progress setup secret after 15 minutes
if (isset($_SESSION['totp_setup_secret_ts']) && (time() - $_SESSION['totp_setup_secret_ts']) > 900) {
    unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_secret_ts']);
}
$totpEnabled    = $auth->isTotpEnabled();
$setupSecret    = $_SESSION['totp_setup_secret'] ?? '';
$setupMode      = $setupSecret !== '';
$newBackupCodes = $_SESSION['totp_new_codes'] ?? [];
unset($_SESSION['totp_new_codes']);

$passkeys = $auth->getPasskeys();

// Generate QR SVG when in setup mode
$setupQrSvg = '';
if ($setupMode) {
    $totp = TOTP::createFromSecret($setupSecret);
    $totp->setLabel($config['admin']['username'] ?? 'admin');
    $totp->setIssuer($db->getSetting('site_title', 'My CMS'));
    $provisioningUri = $totp->getProvisioningUri();

    $renderer   = new ImageRenderer(new RendererStyle(280), new SvgImageBackEnd());
    $writer     = new Writer($renderer);
    $setupQrSvg = $writer->writeString($provisioningUri);
}
