<?php

declare(strict_types=1);

/**
 * IndieAuth server metadata document (rel=indieauth-metadata).
 * https://indieauth.spec.indieweb.org/#indieauth-server-metadata
 */

// Never render a PHP notice or fatal into the response: it would leak absolute
// filesystem paths, and on the JSON endpoints it also corrupts the body. Errors
// still reach the server log.
ini_set('display_errors', '0');

define('CMS_ROOT', __DIR__);
require CMS_ROOT . '/vendor/autoload.php';

$config  = require CMS_ROOT . '/config.php';
$db      = new \CMS\Database($config['paths']['data'] . '/cms.db');
$siteUrl = rtrim($db->getSetting('site_url', ''), '/');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    'issuer'                 => $siteUrl . '/',
    'authorization_endpoint' => $siteUrl . '/indieauth.php',
    'token_endpoint'         => $siteUrl . '/token.php',
    'introspection_endpoint' => $siteUrl . '/token.php?action=introspect',
    'revocation_endpoint'    => $siteUrl . '/token.php?action=revoke',
    'scopes_supported'       => \CMS\IndieAuth::SCOPES,
    'response_types_supported' => ['code'],
    'grant_types_supported'    => ['authorization_code'],
    'code_challenge_methods_supported' => ['S256'],
    'authorization_response_iss_parameter_supported' => true,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
