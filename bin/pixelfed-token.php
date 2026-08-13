#!/usr/bin/env php
<?php

/**
 * Get an access token for syndicating photo posts to Pixelfed.
 *
 * Usage:
 *   php bin/pixelfed-token.php [instance-url]
 *
 * Pixelfed's own personal-access-token screen has a long history of issuing
 * tokens with the wrong scopes, so this walks the OAuth flow that works: it
 * registers an application, sends you to the browser to approve it, and trades
 * the code you get back for a token.
 *
 * The token is printed, not saved. Writing it from here would mean this script
 * opening the database — and a CLI run as the wrong user leaves SQLite's WAL
 * sidecars owned by that user, which takes the site down. Paste it into
 * Settings → General → Pixelfed instead.
 *
 * Note: approving an application is known to fail on accounts with two-factor
 * authentication enabled (pixelfed/pixelfed#5502). If the browser step dead-ends,
 * that is the reason.
 */

define('CMS_ROOT', dirname(__DIR__));

require CMS_ROOT . '/vendor/autoload.php';

use CMS\SafeHttp;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is for the command line.\n");
}

if (!class_exists(SafeHttp::class)) {
    fwrite(STDERR, "Error: autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

/** The OAuth redirect that means "show the code on screen". */
const OOB_REDIRECT = 'urn:ietf:wg:oauth:2.0:oob';

/** Pixelfed checks tokenCan('write') for both statuses and media uploads. */
const SCOPES = 'read write';

/** Read one line from the operator, with a default for an empty answer. */
function prompt(string $question, string $default = ''): string
{
    $suffix = $default !== '' ? " [{$default}]" : '';
    fwrite(STDOUT, $question . $suffix . ': ');
    $answer = trim((string) fgets(STDIN));

    return $answer !== '' ? $answer : $default;
}

/**
 * POST a form to the instance.
 *
 * @param  array<string,string> $fields
 * @return array{status:int,headers:string,body:string,url:string,content_type:string}|null
 */
function post_raw(string $url, array $fields): ?array
{
    return SafeHttp::request($url, [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
}

/**
 * POST a form and decode the JSON that comes back, failing on anything else.
 *
 * @param  array<string,string> $fields
 * @return array<string,mixed>
 */
function post_form(string $url, array $fields): array
{
    return decode(post_raw($url, $fields), $url);
}

/** @return array<string,mixed> */
function get_json(string $url, string $token): array
{
    $response = SafeHttp::request($url, [
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token],
    ]);

    return decode($response, $url);
}

/**
 * The authorization code out of whatever the approval screen gave you.
 *
 * Pixelfed's out-of-band screen answers with a JSON object —
 * `{"code":"def502…","state":null}` — rather than the bare string Mastodon
 * displays, and the whole blob is what lands on the clipboard. Pasting it
 * verbatim reaches the token endpoint as a code that cannot be decrypted, and
 * the error it comes back with ("Cannot validate the provided authorization
 * code") describes a wrong code, not a malformed request, so there is nothing
 * in it to suggest the paste was the problem.
 *
 * A pasted redirect URL is read too, for an instance configured to send one.
 */
function extract_code(string $input): string
{
    $input = trim($input, " \t\n\r\0\x0B\"'");

    $decoded = json_decode($input, true);
    if (is_array($decoded) && isset($decoded['code']) && is_string($decoded['code'])) {
        return trim($decoded['code']);
    }

    $query = parse_url($input, PHP_URL_QUERY);
    if (is_string($query)) {
        parse_str($query, $params);
        if (isset($params['code']) && is_string($params['code'])) {
            return trim($params['code']);
        }
    }

    return $input;
}

/**
 * @param  array{status:int,headers:string,body:string,url:string,content_type:string}|null $response
 * @return array<string,mixed>
 */
function decode(?array $response, string $url): array
{
    if ($response === null) {
        fail("Could not reach {$url}. Check the instance URL — it must be https and resolve to a public address.");
    }
    if ($response['status'] < 200 || $response['status'] >= 300) {
        fail("{$url} answered HTTP {$response['status']}:\n" . trim($response['body']));
    }

    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        fail("{$url} did not answer with JSON:\n" . trim($response['body']));
    }

    return $data;
}

function fail(string $message): never
{
    fwrite(STDERR, "\n" . $message . "\n");
    exit(1);
}

// ── 1. Register the application ─────────────────────────────────────────────

$instance = rtrim($argv[1] ?? prompt('Pixelfed instance URL', 'https://pixelfed.social'), '/');

fwrite(STDOUT, "\nRegistering an application on {$instance}…\n");

$app = post_form($instance . '/api/v1/apps', [
    'client_name'   => 'Clodd CMS',
    'redirect_uris' => OOB_REDIRECT,
    'scopes'        => SCOPES,
    'website'       => '',
]);

$clientId     = (string) ($app['client_id'] ?? '');
$clientSecret = (string) ($app['client_secret'] ?? '');
if ($clientId === '' || $clientSecret === '') {
    fail("The instance registered the app but returned no client credentials:\n" . json_encode($app));
}

// ── 2. Approve it in a browser ──────────────────────────────────────────────

$authorizeUrl = $instance . '/oauth/authorize?' . http_build_query([
    'client_id'     => $clientId,
    'redirect_uri'  => OOB_REDIRECT,
    'response_type' => 'code',
    'scope'         => SCOPES,
]);

fwrite(STDOUT, <<<TEXT

Open this while signed in to Pixelfed, and approve the application:

{$authorizeUrl}

Pixelfed answers with something like {"code":"def502…","state":null}. Paste the
whole thing — the code is picked out of it.


TEXT);

// ── 3. Trade the code for a token ───────────────────────────────────────────

// A code is single use and lives about ten minutes, so a failed exchange is
// worth another go — with the same registered application, which is why this
// loops here rather than exiting and making you re-register.
$token = [];
for ($attempt = 1; $attempt <= 3; $attempt++) {
    $code = extract_code(prompt('Authorization code'));
    if ($code === '') {
        fail('No code given, so there is nothing to exchange.');
    }

    $response = post_raw($instance . '/oauth/token', [
        'grant_type'    => 'authorization_code',
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => OOB_REDIRECT,
        'code'          => $code,
        'scope'         => SCOPES,
    ]);

    if ($response !== null && $response['status'] >= 200 && $response['status'] < 300) {
        $token = json_decode($response['body'], true);
        $token = is_array($token) ? $token : [];
        break;
    }

    $detail = $response === null ? 'the instance could not be reached' : trim($response['body']);
    fwrite(STDOUT, "\nThat code was refused:\n{$detail}\n");

    if ($attempt === 3) {
        fail('Three attempts is enough — run the script again for a fresh application.');
    }

    fwrite(STDOUT, "\nApprove the application again at the URL above for a fresh code, then paste it.\n\n");
}

$accessToken = (string) ($token['access_token'] ?? '');
if ($accessToken === '') {
    fail("The instance accepted the code but returned no access token:\n" . json_encode($token));
}

// ── 4. Prove it works ───────────────────────────────────────────────────────

$account = get_json($instance . '/api/v1/accounts/verify_credentials', $accessToken);
$handle  = (string) ($account['username'] ?? '(unknown account)');

$expiry = '';
if (isset($token['expires_in']) && (int) $token['expires_in'] > 0) {
    $expiresAt = date('Y-m-d', time() + (int) $token['expires_in']);
    $expiry    = "\nIt expires on {$expiresAt}. After that, syndication fails with HTTP 401\n"
               . "in the error log and you run this again.\n";
}

fwrite(STDOUT, <<<TEXT

Token issued for @{$handle}:

{$accessToken}
{$expiry}
Paste it into Settings → General → Pixelfed, along with the instance URL
{$instance}, and photo posts will go there on first publish.


TEXT);
