#!/usr/bin/env php
<?php

/**
 * CMS Setup Script
 *
 * Usage:
 *   php bin/setup.php
 *
 * Prompts for an admin username and password, writes both into config.php,
 * then initializes the database schema.
 */

define('CMS_ROOT', dirname(__DIR__));
define('CMS_VERSION', trim(file_get_contents(CMS_ROOT . '/VERSION')));

require CMS_ROOT . '/vendor/autoload.php';

$config = require CMS_ROOT . '/config.php';

// ── Check autoloader ──────────────────────────────────────────────────────────

if (!class_exists(\CMS\Database::class)) {
    fwrite(STDERR, "Error: autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

// ── Prompt for username ───────────────────────────────────────────────────────

echo "\n=== Clodd CMS Setup (v" . CMS_VERSION . ") ===\n\n";

$currentUsername = $config['admin']['username'] ?? 'admin';
$username = '';
while ($username === '') {
    echo "Admin username [{$currentUsername}]: ";
    $input = trim((string) fgets(STDIN));
    $username = $input !== '' ? $input : $currentUsername;
    if ($username === '') {
        echo "Username cannot be empty. Please try again.\n";
    }
}

// ── Prompt for password ───────────────────────────────────────────────────────

$password = '';
while ($password === '') {
    echo 'Enter admin password: ';

    // Hide input on Unix-like systems.
    if (PHP_OS_FAMILY !== 'Windows') {
        system('stty -echo');
        $password = trim((string) fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $password = trim((string) fgets(STDIN));
    }

    if ($password === '') {
        echo "Password cannot be empty. Please try again.\n";
    }
}

$confirm = '';
echo 'Confirm password: ';
if (PHP_OS_FAMILY !== 'Windows') {
    system('stty -echo');
    $confirm = trim((string) fgets(STDIN));
    system('stty echo');
    echo "\n";
} else {
    $confirm = trim((string) fgets(STDIN));
}

if ($password !== $confirm) {
    fwrite(STDERR, "Error: Passwords do not match.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// ── Write username and hash into config.php ───────────────────────────────────

$configPath    = CMS_ROOT . '/config.php';
$configContent = file_get_contents($configPath);

// Write username.
// preg_replace_callback for the same reason as the hash below: a username
// containing "$0" or "\1" would otherwise be expanded as a backreference and
// write a corrupt config.php, locking the operator out of their own install.
$updatedUsername = preg_replace_callback(
    "/'username'\s*=>\s*'[^']*'/",
    fn($m) => "'username' => '" . addslashes($username) . "'",
    $configContent
);

if ($updatedUsername === null || $updatedUsername === $configContent) {
    echo "\nCould not auto-update username in config.php. Set it manually:\n";
    echo "    'username' => '" . addslashes($username) . "',\n";
    $updatedUsername = $configContent;
} else {
    echo "\nUsername written to config.php.\n";
}

// Write password hash.
// Use preg_replace_callback so the bcrypt hash ($2y$10$...) is never
// interpreted as a regex backreference by the replacement engine.
$updated = preg_replace_callback(
    "/'password_hash'\s*=>\s*'[^']*'/",
    fn($m) => "'password_hash' => '{$hash}'",
    $updatedUsername
);

if ($updated === null || $updated === $updatedUsername) {
    // Fallback: just print the hash for manual pasting.
    echo "Could not auto-update password_hash in config.php. Paste this hash manually:\n";
    echo "    'password_hash' => '{$hash}',\n";
} else {
    // config.php holds the bcrypt hash, so it must not stay world-readable if
    // it was created by hand with a default umask. 0640 keeps it readable by
    // the web server's group (INSTALL.md chowns the tree to deploy:www-data)
    // and closed to everyone else.
    $mode = @fileperms($configPath);
    $mode = $mode === false ? 0640 : ($mode & 0777) & ~0007;

    $tmp = tempnam(dirname($configPath), '.cfg_');
    if ($tmp === false
        || file_put_contents($tmp, $updated) === false
        || !chmod($tmp, $mode)
        || !rename($tmp, $configPath)) {
        if ($tmp !== false && file_exists($tmp)) { unlink($tmp); }
        echo "Could not write config.php atomically. Paste this hash manually:\n";
        echo "    'password_hash' => '{$hash}',\n";
    } else {
        echo "Password hash written to config.php.\n";
    }
}

// ── Initialize database ───────────────────────────────────────────────────────

$dataDir = $config['paths']['data'];
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0775, true);
    echo "Created data/ directory.\n";
}

$db = new \CMS\Database($dataDir . '/cms.db');
echo "Database schema initialized.\n";

// ── Seed default settings if not already present ──────────────────────────────

$title = $db->getSetting('site_title', '');
if ($title === '' || $title === 'My CMS') {
    echo "\nSite title [My CMS]: ";
    $input = trim((string) fgets(STDIN));
    $db->upsertSetting('site_title', $input ?: 'My CMS');

    echo 'Site URL (e.g. https://example.com): ';
    $url = rtrim(trim((string) fgets(STDIN)), '/');
    if ($url !== '') {
        $db->upsertSetting('site_url', $url);
    }
}

echo "\nSetup complete. Visit /admin/ to log in.\n\n";
