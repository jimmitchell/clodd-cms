<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Media;
use CMS\XmlRpcServer;
use PHPUnit\Framework\TestCase;

/**
 * The upload limit is set in four places that have to agree: Clodd's own,
 * PHP's two, and nginx's. One left behind refuses the file before the others
 * are asked, with an error that names none of them.
 */
final class UploadLimitsTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    public function testTheDefaultIsTwoHundredMegabytes(): void
    {
        $this->assertSame(200 * 1_048_576, Media::DEFAULT_MAX_BYTES);
    }

    /** Every upload path falls back to the one constant, not a number of its own. */
    public function testNoUploadPathHasALimitOfItsOwn(): void
    {
        foreach (['micropub.php', 'media.php', 'admin/media.php', 'admin/partials/tools/import.handler.php',
                  'admin/partials/tools/import-media.handler.php'] as $file) {
            $source = (string) file_get_contents(self::ROOT . '/' . $file);
            $this->assertStringNotContainsString('52_428_800', $source, $file);
            $this->assertStringContainsString('Media::DEFAULT_MAX_BYTES', $source, $file);
        }
    }

    /** The shipped configs let through what Clodd takes, with room for the form around it. */
    public function testTheShippedConfigsAllowIt(): void
    {
        $limitMb = Media::DEFAULT_MAX_BYTES / 1_048_576;

        foreach (['nginx.conf.example', 'docker/nginx.conf'] as $file) {
            $conf = (string) file_get_contents(self::ROOT . '/' . $file);
            $this->assertMatchesRegularExpression('/^\s{4}client_max_body_size (\d+)M;/m', $conf, $file);
            preg_match('/^\s{4}client_max_body_size (\d+)M;/m', $conf, $m);
            $this->assertGreaterThan($limitMb, (int) $m[1], $file);
        }

        $ini = (string) file_get_contents(self::ROOT . '/docker/php.ini');
        $this->assertMatchesRegularExpression('/^upload_max_filesize\s*=\s*' . $limitMb . 'M$/m', $ini);
        preg_match('/^post_max_size\s*=\s*(\d+)M$/m', $ini, $m);
        $this->assertGreaterThan($limitMb, (int) ($m[1] ?? 0));
    }

    /** XML-RPC holds the whole file in memory as base64, so it stays at 50 MB. */
    public function testXmlRpcKeepsItsOwnLowerLimit(): void
    {
        $limit = (new \ReflectionClassConstant(XmlRpcServer::class, 'XMLRPC_MAX_BYTES'))->getValue();
        $this->assertSame(50 * 1_048_576, $limit);

        $source = (string) file_get_contents(self::ROOT . '/src/XmlRpcServer.php');
        $this->assertMatchesRegularExpression('/\$maxBytes = min\(\s*.*DEFAULT_MAX_BYTES\),\s*self::XMLRPC_MAX_BYTES\s*\);/s', $source);
    }
}
