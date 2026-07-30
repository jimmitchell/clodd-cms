<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Database;
use CMS\Helpers;
use CMS\Page;
use CMS\Post;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The slug → path chain.
 *
 * A slug is not just a URL component: Post::datePath() splices it into the
 * on-disk output path, so an unsanitised slug is an arbitrary-file-write
 * primitive. These tests pin every link in that chain.
 */
final class SlugPathTest extends TestCase
{
    private Database $db;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'clodd_test_') . '.db';
        $this->db     = new Database($this->dbPath);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    // ── slugify ───────────────────────────────────────────────────────────────

    /** @return array<string, array{string, string}> */
    public static function traversalPayloads(): array
    {
        return [
            'parent traversal'   => ['../../../../var/www/evil', 'var-www-evil'],
            'nested dot-dot'     => ['....//....//x',            'x'],
            'encoded slashes'    => ['..%2f..%2fetc',            '2f-2fetc'],
            'bare dot-dot'       => ['..',                       'untitled'],
            'leading slash'      => ['/etc/passwd',              'etc-passwd'],
            'null byte'          => ["evil\x00.php",             'evil-php'],
            'backslashes'        => ['..\\..\\windows',          'windows'],
            'empty'              => ['',                         'untitled'],
        ];
    }

    #[DataProvider('traversalPayloads')]
    public function testSlugifyNeutralisesTraversal(string $input, string $expected): void
    {
        $slug = Helpers::slugify($input);

        $this->assertSame($expected, $slug);
        $this->assertDoesNotMatchRegularExpression(
            '#[/\\\\]|\.\.#',
            $slug,
            'a slug must never contain a path separator or a parent reference'
        );
    }

    public function testSlugifyKeepsOrdinaryTitlesReadable(): void
    {
        $this->assertSame('hello-world-its-alive', Helpers::slugify("Hello, World! It's Alive"));
        $this->assertSame('cafe-deja-vu', Helpers::slugify('Café déjà vu'));
    }

    // ── resolveUniqueSlug ─────────────────────────────────────────────────────

    public function testResolveUniqueSlugAlwaysSlugifies(): void
    {
        $slug = Post::resolveUniqueSlug($this->db, '../../../../etc/passwd');

        $this->assertSame('etc-passwd', $slug);
    }

    public function testResolveUniqueSlugDedupesOnCollision(): void
    {
        $this->makePost('taken');

        $this->assertSame('taken-2', Post::resolveUniqueSlug($this->db, 'taken'));
    }

    public function testResolveUniqueSlugSkipsItself(): void
    {
        $post = $this->makePost('mine');

        $this->assertSame(
            'mine',
            Post::resolveUniqueSlug($this->db, 'mine', $post->id),
            'a post re-saving its own slug must not be renamed'
        );
    }

    public function testDigitOnlySlugsAreSuffixed(): void
    {
        // Legacy asides own the bare-id URLs, so a new post must not land on one.
        $this->assertSame('2026-post', Post::resolveUniqueSlug($this->db, '2026'));
    }

    public function testEmptyBaseFallsBackRatherThanBecomingUntitled(): void
    {
        $this->assertSame('post', Post::resolveUniqueSlug($this->db, ''));
        $this->assertSame('page', Page::resolveUniqueSlug($this->db, ''));
    }

    public function testPageResolveUniqueSlugSanitisesAndDedupes(): void
    {
        $page = new Page($this->db);
        $page->title   = 'Taken';
        $page->slug    = 'taken';
        $page->content = 'x';
        $page->status  = 'published';
        $page->save();

        $this->assertSame('etc-passwd', Page::resolveUniqueSlug($this->db, '../../etc/passwd'));
        $this->assertSame('taken-2', Page::resolveUniqueSlug($this->db, 'taken'));
        $this->assertSame('taken', Page::resolveUniqueSlug($this->db, 'taken', $page->id));
    }

    // ── datePath ──────────────────────────────────────────────────────────────

    public function testDatePathShapeIsDateThenSlug(): void
    {
        $this->assertSame(
            '2026/07/29/my-slug',
            Post::datePath('2026-07-29 12:00:00', 'my-slug')
        );
    }

    /**
     * The end-to-end property that matters: a hostile post_name cannot produce
     * an output path outside the site root, because the slug is sanitised
     * before it ever reaches datePath().
     */
    public function testSanitisedSlugCannotEscapeTheOutputRoot(): void
    {
        $outputRoot = '/var/www/cms';
        $hostile    = '../../../../../../tmp/evil';

        $slug = Post::resolveUniqueSlug($this->db, $hostile);
        $path = $outputRoot . '/posts/' . Post::datePath('2026-07-29 12:00:00', $slug) . '/index.html';

        $this->assertStringStartsWith($outputRoot . '/posts/', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertSame(
            '/var/www/cms/posts/2026/07/29/tmp-evil/index.html',
            $path
        );
    }

    // ── addressablePath / Micropub Location round-trip ────────────────────────

    public function testAddressablePathIsTheDatePathOncePublished(): void
    {
        $post = $this->makePost('published-post');

        $this->assertSame('2026/07/29/published-post', $post->addressablePath());
    }

    public function testAddressablePathFallsBackToTheSlugWithoutADate(): void
    {
        $post = new Post($this->db);
        $post->title  = 'Draft';
        $post->slug   = 'my-draft';
        $post->status = 'draft';   // published_at stays null

        $this->assertSame('my-draft', $post->addressablePath());
    }

    /**
     * The property that matters: whatever addressablePath() produces has to be
     * resolvable by the same last-segment lookup Micropub uses to turn a
     * client-supplied URL back into a post. A draft used to be handed
     * `/admin/post-edit.php?id=N`, which resolved to nothing, so every
     * subsequent update or delete from the client 404'd.
     */
    public function testAddressablePathAlwaysResolvesBackToItsOwnPost(): void
    {
        $published = $this->makePost('round-trip-published');

        $draft = new Post($this->db);
        $draft->title   = 'D';
        $draft->slug    = 'round-trip-draft';
        $draft->content = 'x';
        $draft->status  = 'draft';
        $draft->save();

        foreach ([$published, $draft] as $post) {
            $path = $post->addressablePath();

            // Exactly what mp_resolve_post_by_url() does with the URL path.
            $segments = array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));
            $resolved = Post::findBySlug($this->db, (string) end($segments));

            $this->assertNotNull($resolved, "addressable path '{$path}' resolved to nothing");
            $this->assertSame($post->id, $resolved->id);
            $this->assertStringNotContainsString('.php', $path);
        }
    }

    public function testAddressablePathHonoursTheSiteTimezone(): void
    {
        $post = new Post($this->db);
        $post->slug         = 'tz-post';
        $post->published_at = '2026-07-30 02:00:00';   // stored UTC

        // In UTC-7 that instant is still the 29th, so the date path must shift.
        $this->assertSame('2026/07/29/tz-post', $post->addressablePath('America/Los_Angeles'));
        $this->assertSame('2026/07/30/tz-post', $post->addressablePath());
    }

    private function makePost(string $slug): Post
    {
        $post = new Post($this->db);
        $post->title        = 'T';
        $post->slug         = $slug;
        $post->content      = 'x';
        $post->status       = 'published';
        $post->published_at = '2026-07-29 12:00:00';
        $post->save();

        return $post;
    }
}
