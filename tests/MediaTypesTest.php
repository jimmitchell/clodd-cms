<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Media;
use PHPUnit\Framework\TestCase;

/**
 * What Media::ALLOWED_MIME lets in, judged the way an upload is: by fileinfo
 * reading the bytes, never by the name or the Content-Type the client sent.
 */
final class MediaTypesTest extends TestCase
{
    /** The opening `ftyp` box of an MPEG-4 family file, with its brand. */
    private function ftypFile(string $brand, string $compatible): string
    {
        $box  = $brand . "\0\0\0\0" . $compatible;
        $data = pack('N', 8 + strlen($box)) . 'ftyp' . $box
              . pack('N', 8) . 'free'
              . str_repeat("\0", 64);
        $path = tempnam(sys_get_temp_dir(), 'clodd_mime_');
        file_put_contents($path, $data);
        return $path;
    }

    /** @return array<string, array{string, string, string}> */
    public static function iphoneFiles(): array
    {
        return [
            'a QuickTime movie from Photos' => ['qt  ', 'qt  ', 'mov'],
            'a voice memo'                  => ['M4A ', 'M4A isommp42', 'm4a'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('iphoneFiles')]
    public function testWhatAnIphoneSendsIsAccepted(string $brand, string $compatible, string $extension): void
    {
        $path = $this->ftypFile($brand, $compatible);
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        } finally {
            unlink($path);
        }

        $this->assertArrayHasKey($mime, Media::ALLOWED_MIME, "fileinfo read it as {$mime}");
        $this->assertSame($extension, Media::ALLOWED_MIME[$mime]);
    }

    public function testEveryAcceptedTypeIsAnImageAVideoOrASound(): void
    {
        foreach (array_keys(Media::ALLOWED_MIME) as $mime) {
            $this->assertMatchesRegularExpression('#^(image|video|audio)/#', $mime);
        }
        $this->assertArrayNotHasKey('image/svg+xml', Media::ALLOWED_MIME, 'SVG can carry script');
    }
}
