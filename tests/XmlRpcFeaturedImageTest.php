<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Auth;
use CMS\Builder;
use CMS\Database;
use CMS\Post;
use CMS\XmlRpcServer;
use PHPUnit\Framework\TestCase;

/**
 * Featured images over XML-RPC — how MarsEdit sets a post's lead picture.
 *
 * WordPress names the field `wp_post_thumbnail` and its value is an attachment
 * id, so a client can only send one if the upload response gave it an id to
 * send. Both halves are pinned here: the id comes back from newMediaObject, and
 * the struct is read on the way in.
 *
 * The rule that matters most is the last one. An edit struct that never mentions
 * the thumbnail must not clear it — most clients send back only the fields they
 * changed, and a save that silently dropped the picture would look like the CMS
 * losing data.
 */
final class XmlRpcFeaturedImageTest extends TestCase
{
    private Database $db;
    private string $dbPath;
    private string $root;
    private XmlRpcServer $server;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'clodd_test_') . '.db';
        $this->db     = new Database($this->dbPath);

        $this->root = realpath(sys_get_temp_dir()) . '/clodd_out_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/output', 0775, true);
        mkdir($this->root . '/templates', 0775, true);

        $config = [
            'paths' => [
                'output'    => $this->root . '/output',
                'templates' => $this->root . '/templates',
                'content'   => $this->root . '/content',
            ],
        ];

        $this->db->upsertSetting('site_url', 'https://example.com');

        $this->server = new XmlRpcServer(
            $this->db,
            new Auth($config, $this->db),
            $config,
            new Builder($config, $this->db),
        );
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->root);

        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    /**
     * Both apply*Struct methods are private — internals of a dispatch that would
     * otherwise need a full authenticated request built around it.
     */
    private function apply(string $method, Post $post, array $struct): void
    {
        $ref = new \ReflectionMethod(XmlRpcServer::class, $method);
        $ref->setAccessible(true);

        $args = $method === 'applyStruct'
            ? [$post, $struct, true, 'UTC']
            : [$post, $struct, 'UTC'];

        $ref->invokeArgs($this->server, $args);
    }

    private function post(): Post
    {
        $post          = new Post($this->db);
        $post->title   = 'An illustrated post';
        $post->slug    = 'an-illustrated-post';
        $post->content = 'Words.';

        return $post;
    }

    /** A media row, as metaWeblog.newMediaObject would have left it. */
    private function mediaRow(string $filename = 'lead.jpg', string $originalName = 'Lead.jpg'): int
    {
        return $this->db->insert('media', [
            'filename'      => $filename,
            'original_name' => $originalName,
            'mime_type'     => 'image/jpeg',
            'size'          => 4,
            'uploaded_at'   => '2026-08-01 09:00:00',
        ]);
    }

    // ── Reading the struct ────────────────────────────────────────────────────

    /** @return string[] */
    public static function mapperProvider(): array
    {
        return [
            'metaWeblog (wp_post_thumbnail)' => ['applyStruct', 'wp_post_thumbnail'],
            'wp (post_thumbnail)'            => ['applyWpPostStruct', 'post_thumbnail'],
        ];
    }

    /**
     * The offset id is the form a client echoes back: wp.getMediaLibrary reports
     * attachment ids offset by MEDIA_ID_OFFSET, so the two have to agree.
     *
     * @dataProvider mapperProvider
     */
    public function testAnAttachmentIdSetsTheFeaturedImage(string $mapper, string $key): void
    {
        $id   = $this->mediaRow();
        $post = $this->post();

        $this->apply($mapper, $post, [$key => (string) ($id + XmlRpcServer::MEDIA_ID_OFFSET)]);

        $this->assertSame('/media/lead.jpg', $post->featured_image_url);
        // No alt travels with a thumbnail id, so the original filename stands in
        // rather than leaving a screen reader with nothing at all.
        $this->assertSame('Lead.jpg', $post->featured_image_alt);
    }

    /**
     * A client that read the id straight out of an upload response may send the
     * bare row id instead of the offset one. Both resolve.
     *
     * @dataProvider mapperProvider
     */
    public function testABareMediaIdAlsoResolves(string $mapper, string $key): void
    {
        $id   = $this->mediaRow();
        $post = $this->post();

        $this->apply($mapper, $post, [$key => (string) $id]);

        $this->assertSame('/media/lead.jpg', $post->featured_image_url);
    }

    /**
     * Not every client that wants a lead picture speaks in attachment ids, and
     * the media endpoint hands out URLs.
     *
     * @dataProvider mapperProvider
     */
    public function testAnAbsoluteSameOriginUrlIsStoredSiteRelative(string $mapper, string $key): void
    {
        $post = $this->post();

        $this->apply($mapper, $post, [$key => 'https://example.com/media/lead.jpg']);

        $this->assertSame('/media/lead.jpg', $post->featured_image_url);
    }

    /**
     * The regression this whole shape exists to prevent: most clients send back
     * only what they changed, so an absent key must leave the field alone. The
     * same rule xmlrpc_kind_from_struct() follows for the post format.
     *
     * @dataProvider mapperProvider
     */
    public function testAnAbsentKeyLeavesAnExistingFeaturedImageAlone(string $mapper, string $key): void
    {
        $post = $this->post();
        $post->featured_image_url = '/media/lead.jpg';
        $post->featured_image_alt = 'A lead picture';

        $this->apply($mapper, $post, ['title' => 'Retitled', 'post_title' => 'Retitled']);

        $this->assertSame('/media/lead.jpg', $post->featured_image_url);
        $this->assertSame('A lead picture', $post->featured_image_alt);
    }

    /** An empty string is a client saying "none", and does clear it. */
    public function testAnEmptyValueClearsTheFeaturedImage(): void
    {
        $post = $this->post();
        $post->featured_image_url = '/media/lead.jpg';
        $post->featured_image_alt = 'A lead picture';

        $this->apply('applyStruct', $post, ['wp_post_thumbnail' => '']);

        $this->assertNull($post->featured_image_url);
        $this->assertSame('', $post->featured_image_alt);
    }

    /** An id for a picture that is not in the library changes nothing. */
    public function testAnUnknownAttachmentIdIsIgnored(): void
    {
        $post = $this->post();

        $this->apply('applyStruct', $post, ['wp_post_thumbnail' => '999999']);

        $this->assertNull($post->featured_image_url);
    }

    /** It reaches an href on the public page, so the scheme allowlist applies. */
    public function testAJavascriptUrlIsRefused(): void
    {
        $post = $this->post();

        $this->apply('applyStruct', $post, ['wp_post_thumbnail' => 'javascript:alert(1)']);

        $this->assertNull($post->featured_image_url);
    }

    // ── Writing it back out ───────────────────────────────────────────────────

    /**
     * A client has to be able to read back what it set, or the picture appears
     * to vanish the moment the post is reopened.
     */
    public function testTheFeaturedImageRoundTripsThroughBothOutgoingStructs(): void
    {
        $post = $this->post();
        $post->featured_image_url = '/media/lead.jpg';
        $post->status       = 'published';
        $post->published_at = '2026-08-01 09:00:00';
        $post->save();

        foreach ([['postToStruct', 'wp_post_thumbnail'], ['wpPostToStruct', 'post_thumbnail']] as [$method, $key]) {
            $ref = new \ReflectionMethod(XmlRpcServer::class, $method);
            $ref->setAccessible(true);
            $struct = $ref->invokeArgs($this->server, [$post, 'https://example.com', 'UTC']);

            $this->assertSame('https://example.com/media/lead.jpg', $struct[$key], $method);
        }
    }

    /** And a post with none says so, rather than omitting the key. */
    public function testAPostWithNoFeaturedImageReportsAnEmptyString(): void
    {
        $post = $this->post();
        $post->status       = 'published';
        $post->published_at = '2026-08-01 09:00:00';
        $post->save();

        $ref = new \ReflectionMethod(XmlRpcServer::class, 'postToStruct');
        $ref->setAccessible(true);
        $struct = $ref->invokeArgs($this->server, [$post, 'https://example.com', 'UTC']);

        $this->assertSame('', $struct['wp_post_thumbnail']);
    }

    // ── The upload response ───────────────────────────────────────────────────

    /**
     * Without an id in the upload response a WordPress client has nothing to put
     * in wp_post_thumbnail, so the whole feature is unreachable from MarsEdit.
     * The id is offset the same way wp.getMediaLibrary reports it.
     */
    public function testTheUploadResponseCarriesAnAttachmentId(): void
    {
        $ref = new \ReflectionMethod(XmlRpcServer::class, 'xmlrpc_media_struct');
        $ref->setAccessible(true);

        $struct = $ref->invokeArgs($this->server, [
            ['id' => 7, 'filename' => 'lead.jpg', 'url' => 'https://example.com/media/lead.jpg'],
            'image/jpeg',
        ]);

        $expected = (string) (7 + XmlRpcServer::MEDIA_ID_OFFSET);
        $this->assertSame($expected, $struct['id']);
        $this->assertSame($expected, $struct['attachment_id']);
        $this->assertSame('https://example.com/media/lead.jpg', $struct['url']);
        $this->assertSame('image/jpeg', $struct['type']);
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
