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
 *           400 / 401 / 403 / 422 / 429 + JSON {error, error_description}
 */

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

if (empty($_FILES['file'])) {
    MicropubAuth::error('invalid_request', 'multipart request with a file part is required');
}

$f = $_FILES['file'];
if (is_array($f['name'])) {
    // Take the first file if a client sends file[].
    $f = [
        'name'     => $f['name'][0]     ?? '',
        'tmp_name' => $f['tmp_name'][0] ?? '',
        'size'     => (int) ($f['size'][0]  ?? 0),
        'error'    => (int) ($f['error'][0] ?? UPLOAD_ERR_NO_FILE),
    ];
}
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
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
