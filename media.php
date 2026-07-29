<?php

declare(strict_types=1);

/**
 * Micropub media endpoint — accepts multipart file uploads from Micropub
 * clients and returns the URL of the stored file. Advertised via q=config on
 * /micropub.php.
 *
 * Auth: Bearer token (legacy shared token or IndieAuth token with the
 * `media` or `create` scope).
 *
 * Request:  POST multipart/form-data with a `file` part
 * Response: 201 Created + Location: <file URL> + JSON {"url": "<file URL>"}
 *           400 / 401 / 403 / 422 + JSON {error, error_description}
 *
 * There is no upload-rate limit here. The only throttling is the failed-token
 * counter in MicropubAuth::authenticate() (429, 'micropub' scope), which bounds
 * token guessing but not upload volume from a valid token. Request-rate limiting
 * is nginx's job — see the `limit_req` zone in nginx.conf.example.
 */

// Never render a PHP notice or fatal into the response: it would leak absolute
// filesystem paths, and on the JSON endpoints it also corrupts the body. Errors
// still reach the server log.
ini_set('display_errors', '0');

define('CMS_ROOT', __DIR__);
require CMS_ROOT . '/vendor/autoload.php';

use CMS\MicropubAuth;

$config = require CMS_ROOT . '/config.php';
$db     = new \CMS\Database($config['paths']['data'] . '/cms.db');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    MicropubAuth::error('invalid_request', 'Method not allowed', 405);
}

$authz = MicropubAuth::authenticate($db, $config);
MicropubAuth::requireScope($authz, 'media', 'create');

$f = MicropubAuth::firstUploadedFile($_FILES['file'] ?? null);
if ($f === null) {
    MicropubAuth::error('invalid_request', 'multipart request with a file part is required');
}
if ($f['error'] !== UPLOAD_ERR_OK) {
    MicropubAuth::error('invalid_request', 'file upload error');
}

try {
    $mediaService = new \CMS\Media($db, $config['paths']['content'] . '/media');
    $result       = $mediaService->upload($f);
} catch (\RuntimeException $e) {
    MicropubAuth::error('invalid_request', $e->getMessage(), 422);
}

$siteUrl = rtrim($db->getSetting('site_url', ''), '/');
$url     = $siteUrl . $result['url'];

http_response_code(201);
header('Location: ' . $url);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['url' => $url], JSON_UNESCAPED_SLASHES);
exit;
