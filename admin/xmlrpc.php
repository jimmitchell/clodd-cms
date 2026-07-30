<?php

declare(strict_types=1);

/**
 * WordPress + MetaWeblog XML-RPC API endpoint — front controller.
 *
 * Parses the request, hands it to CMS\XmlRpcServer, and renders whatever comes
 * back. The handlers themselves live in src/XmlRpcServer.php.
 *
 * MarsEdit setup (WordPress mode — recommended, enables page management):
 *   API type    : WordPress
 *   Endpoint URL: https://example.com/admin/xmlrpc.php
 *   Username    : admin username from config.php
 *   Password    : admin plaintext password
 *
 * MarsEdit setup (MetaWeblog mode — posts only, no pages):
 *   API type    : MetaWeblog
 *   Endpoint URL: https://example.com/admin/xmlrpc.php
 *
 * Supported methods:
 *   WordPress API  : wp.getUsersBlogs, wp.getOptions, wp.getAuthors,
 *                    wp.getPostFormats, wp.getTaxonomies, wp.getTerms,
 *                    wp.getPosts, wp.getPost, wp.newPost, wp.editPost, wp.deletePost,
 *                    wp.getPages, wp.getPageList, wp.getPageStatusList, wp.getPage, wp.newPage, wp.editPage, wp.deletePage,
 *                    wp.getMediaLibrary, wp.uploadFile
 *   MetaWeblog API : blogger.getUsersBlogs, metaWeblog.getRecentPosts,
 *                    metaWeblog.getPost, metaWeblog.newPost, metaWeblog.editPost,
 *                    metaWeblog.deletePost, metaWeblog.getCategories,
 *                    metaWeblog.newMediaObject
 */

// PHP errors must never appear in an XML-RPC response — they corrupt the XML.
// Errors are still logged server-side; they just won't break the response body.
ini_set('display_errors', '0');

// Read the raw request body BEFORE bootstrap.php starts a session or does
// anything that could consume php://input on some PHP-FPM configurations.
$_xmlrpcBody = file_get_contents('php://input');

require __DIR__ . '/bootstrap.php';
// Note: $auth->check() is intentionally NOT called.
// XML-RPC authenticates per-request via the username/password params.

use CMS\XmlRpc;
use CMS\XmlRpcFault;
use CMS\XmlRpcServer;

header('Content-Type: text/xml; charset=utf-8');

/** Render a fault envelope and stop. */
function xmlrpc_fault(int $code, string $msg): never
{
    echo XmlRpc::encodeFault($code, $msg);
    exit;
}

// Guard: simplexml is in the php8.3-xml package (separate from php8.3-fpm).
if (!function_exists('simplexml_load_string')) {
    xmlrpc_fault(500, 'Server error: simplexml extension not installed (apt install php8.3-xml).');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    xmlrpc_fault(405, 'Method Not Allowed');
}

try {
    $req = XmlRpc::parseRequest($_xmlrpcBody ?: '');
} catch (\Throwable $e) {
    xmlrpc_fault(400, 'Bad Request: ' . $e->getMessage());
}

unset($_xmlrpcBody);

$server = new XmlRpcServer($db, $auth, $config, $builder);

try {
    $server->dispatch($req['method'], $req['params']);
} catch (XmlRpcFault $fault) {
    xmlrpc_fault($fault->getCode(), $fault->getMessage());
}
