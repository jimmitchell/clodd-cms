<?php

declare(strict_types=1);

/**
 * Newsletter signup: puts a reader on the EmailOctopus list as `pending`, and
 * EmailOctopus sends them the confirmation email.
 *
 * The form lives on static pages (templates/partials/subscribe-form.php), so
 * there is no session and no CSRF token. What stands in for them:
 *
 *   - Double opt-in. Nobody is emailed an article until they have clicked the
 *     link EmailOctopus sent them, so a forged signup costs its victim one
 *     confirmation email they can ignore. EmailOctopus answers 409 for an
 *     address already on the list, so a second signup sends nothing.
 *   - An Origin check. A browser posting from another site says so.
 *   - A honeypot field that people never see and form-filling bots do.
 *   - nginx's `subscribe` limit_req zone, which meters every request, plus
 *     Auth::SCOPE_SUBSCRIBE, which locks out an IP after repeated bad input.
 *
 * The reply is the same whether the address was new or already subscribed, so
 * the form cannot be used to find out who is on the list.
 *
 * With JavaScript (theme.js sends `Accept: application/json`) the answer is
 * shown inline under the form. Without it, this renders a small page with the
 * same message and a link back.
 */

// Public and unauthenticated: never render an error into the response.
// Database's exception message carries the absolute data path.
ini_set('display_errors', '0');

define('CMS_ROOT', __DIR__);
require CMS_ROOT . '/vendor/autoload.php';

use CMS\Auth;
use CMS\Database;
use CMS\EmailOctopus;

// Cache-Control and nosniff come from the nginx location, as for track.php.

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

$config = require CMS_ROOT . '/config.php';
$db     = new Database($config['paths']['data'] . '/cms.db');
$client = EmailOctopus::fromSettings($db);

if ($client === null) {
    http_response_code(404);
    exit;
}

$siteUrl   = rtrim($db->getSetting('site_url'), '/');
$siteTitle = $db->getSetting('site_title');
$wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

// Where the no-JS page links back to: a path on this site, and nothing else.
$return = (string) ($_POST['return'] ?? '/');
if (preg_match('#^/(?!/)[^\s\\\\]*$#', $return) !== 1) {
    $return = '/';
}

/**
 * Answer and stop. $ok decides the status code; the message is what the reader sees.
 */
$respond = static function (bool $ok, string $message, int $status = 200) use ($wantsJson, $return, $siteTitle): never {
    http_response_code($status);

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    $heading = $ok ? 'Almost there' : 'That didn’t work';
    echo <<<HTML
    <!doctype html>
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{$e($heading)} — {$e($siteTitle)}</title>
    <link rel="stylesheet" href="/theme.min.css">
    </head>
    <body>
    <main class="site-main">
    <section class="subscribe-result">
    <h1>{$e($heading)}</h1>
    <p>{$e($message)}</p>
    <p><a href="{$e($return)}">&larr; Back</a></p>
    </section>
    </main>
    </body>
    </html>
    HTML;
    exit;
};

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (Auth::isLockedOutIn($db, $config, $ip, Auth::SCOPE_SUBSCRIBE)) {
    $respond(false, 'Too many attempts. Please try again in a little while.', 429);
}

// A browser posting from someone else's page names that page's origin. A
// missing header is let through: curl and some privacy extensions omit it, and
// double opt-in is what actually protects the list.
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && $siteUrl !== '') {
    $site = parse_url($siteUrl);
    $want = strtolower(($site['scheme'] ?? '') . '://' . ($site['host'] ?? '') . (isset($site['port']) ? ':' . $site['port'] : ''));
    if (strtolower(rtrim($origin, '/')) !== $want) {
        Auth::recordFailureIn($db, $ip, Auth::SCOPE_SUBSCRIBE);
        $respond(false, 'Signups are only accepted from this site.', 403);
    }
}

// The honeypot. A person never sees this field; a bot fills in every field it
// finds. Answer as if it worked, so the bot learns nothing.
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    Auth::recordFailureIn($db, $ip, Auth::SCOPE_SUBSCRIBE);
    $respond(true, 'Thanks! Check your inbox for a link to confirm your subscription.');
}

$email = trim((string) ($_POST['email'] ?? ''));
if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    Auth::recordFailureIn($db, $ip, Auth::SCOPE_SUBSCRIBE);
    $respond(false, 'That doesn’t look like an email address. Please check it and try again.', 422);
}

// Our failure, not the reader's, so it does not count against their IP.
if (!$client->subscribe($email)) {
    $respond(false, 'Something went wrong on our end. Please try again later.', 502);
}

$respond(true, 'Thanks! Check your inbox for a link to confirm your subscription.');
