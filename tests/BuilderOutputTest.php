<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Builder;
use CMS\Database;
use CMS\OgImage;
use CMS\Post;
use PHPUnit\Framework\TestCase;

/**
 * Teardown of a post's generated output.
 *
 * A published post writes two files into its own directory — index.html and
 * the og.png from buildOgImage(). Unpublishing used to remove only the first,
 * so the image was orphaned and its directory survived forever (removeFile()
 * prunes an empty directory, and og.png kept it non-empty). These tests pin
 * the invariant that matters: nothing generated for a post outlives it.
 */
final class BuilderOutputTest extends TestCase
{
    private Database $db;
    private string $dbPath;
    private string $root;
    private Builder $builder;
    /** @var array{paths: array{output: string, templates: string, content: string}} */
    private array $config;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'clodd_test_') . '.db';
        $this->db     = new Database($this->dbPath);

        // realpath() the temp dir: isInsideOutputDir() resolves the output root
        // but normalises the candidate path lexically, so a symlinked root (macOS
        // /var/folders -> /private/var/folders) fails the comparison.
        $this->root = realpath(sys_get_temp_dir()) . '/clodd_out_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/output', 0775, true);

        $this->config = [
            'paths' => [
                'output'    => $this->root . '/output',
                'templates' => $this->root . '/templates',
                'content'   => $this->root . '/content',
            ],
        ];

        $this->builder = new Builder($this->config, $this->db);
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

    // ── The regression ────────────────────────────────────────────────────────

    public function testUnpublishingRemovesTheOgImageAndPrunesTheDirectory(): void
    {
        $post = $this->makePublishedPost('leaving');
        $dir  = $this->root . '/output/posts/2026/07/29/leaving';
        $this->seedOutput($dir);

        $post->status = 'draft';
        $this->builder->buildPost($post);

        $this->assertDirectoryDoesNotExist($dir, 'unpublishing must leave nothing behind');
    }

    public function testSoftDeleteRemovesTheOgImageAndPrunesTheDirectory(): void
    {
        $post = $this->makePublishedPost('deleted');
        $dir  = $this->root . '/output/posts/2026/07/29/deleted';
        $this->seedOutput($dir);

        // A soft-deleted post stays 'published' — deleted_at is the signal.
        $post->deleted_at = '2026-07-30 09:00:00';
        $this->builder->buildPost($post);

        $this->assertDirectoryDoesNotExist($dir);
    }

    /**
     * The directory is only pruned once it is genuinely empty, so an og.png
     * left by an older build must not block cleanup of a later one.
     */
    public function testAnOrphanedOgImageFromAnEarlierBuildIsStillCollected(): void
    {
        $post = $this->makePublishedPost('orphan');
        $dir  = $this->root . '/output/posts/2026/07/29/orphan';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/og.png', 'PNG');   // no index.html

        $post->status = 'draft';
        $this->builder->buildPost($post);

        $this->assertDirectoryDoesNotExist($dir);
    }

    // ── removePostOutput as a unit ────────────────────────────────────────────

    public function testRemovePostOutputClearsBothGeneratedFiles(): void
    {
        $dir = $this->root . '/output/posts/2026/07/29/direct';
        $this->seedOutput($dir);

        $this->builder->removePostOutput($dir);

        $this->assertFileDoesNotExist($dir . '/index.html');
        $this->assertFileDoesNotExist($dir . '/og.png');
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testRemovePostOutputLeavesUnrelatedSiblingsAlone(): void
    {
        $dir = $this->root . '/output/posts/2026/07/29/keeps-extras';
        $this->seedOutput($dir);
        file_put_contents($dir . '/notes.txt', 'hand-placed');

        $this->builder->removePostOutput($dir);

        $this->assertFileExists($dir . '/notes.txt');
        $this->assertDirectoryExists($dir, 'a non-empty directory must survive');
    }

    /**
     * removePostOutput() is public and takes a path, so the containment guard
     * inside removeFile() is the thing standing between a caller-supplied
     * directory and the rest of the filesystem.
     */
    public function testRemovePostOutputRefusesToEscapeTheOutputRoot(): void
    {
        $outside = $this->root . '/outside';
        mkdir($outside, 0775, true);
        file_put_contents($outside . '/index.html', 'not ours');
        file_put_contents($outside . '/og.png', 'not ours');

        $this->builder->removePostOutput($this->root . '/output/posts/../../outside');

        $this->assertFileExists($outside . '/index.html');
        $this->assertFileExists($outside . '/og.png');
    }

    // ── Moving a post: the directory it vacates ───────────────────────────────

    public function testPostOutputDirIsNullWithoutAPublicationDate(): void
    {
        $this->assertNull($this->builder->postOutputDir(null, 'some-slug'));
        $this->assertNull($this->builder->postOutputDir('2026-07-29 12:00:00', ''));
    }

    public function testRenamingAPublishedPostClearsTheOldDirectory(): void
    {
        $post   = $this->makePublishedPost('before');
        $oldDir = $this->builder->postOutputDir($post->published_at, $post->slug);
        $this->seedOutput($oldDir);

        $post->slug = 'after';
        $this->builder->removeVacatedPostOutput($oldDir, $post);

        $this->assertDirectoryDoesNotExist($oldDir, 'the old URL must stop serving');
    }

    public function testChangingThePublicationDateClearsTheOldDirectory(): void
    {
        $post   = $this->makePublishedPost('moved');
        $oldDir = $this->builder->postOutputDir($post->published_at, $post->slug);
        $this->seedOutput($oldDir);

        $post->published_at = '2026-08-15 12:00:00';
        $this->builder->removeVacatedPostOutput($oldDir, $post);

        $this->assertDirectoryDoesNotExist($oldDir);
    }

    /**
     * buildPost() derives the directory to clean from the post's *current*
     * values, so an unpublish that also renames would otherwise clean a path
     * that was never written and leave the live one behind.
     */
    public function testUnpublishingAndRenamingInOneSaveStillClearsTheOldDirectory(): void
    {
        $post   = $this->makePublishedPost('both-at-once');
        $oldDir = $this->builder->postOutputDir($post->published_at, $post->slug);
        $this->seedOutput($oldDir);

        $post->slug   = 'renamed';
        $post->status = 'draft';
        $this->builder->removeVacatedPostOutput($oldDir, $post);

        $this->assertDirectoryDoesNotExist($oldDir);
    }

    /**
     * Re-scheduling a post that is already live is an unpublish in disguise:
     * the date moves into the future *and* the post leaves the site. The
     * directory it vacates has to go, and the future-dated one it now points
     * at must not be written — publishing it early is the failure mode.
     */
    public function testReSchedulingALivePostTakesItOffTheSite(): void
    {
        $post   = $this->makePublishedPost('back-in-the-oven');
        $oldDir = $this->builder->postOutputDir($post->published_at, $post->slug);
        $this->seedOutput($oldDir);

        $post->published_at = '2027-01-01 09:00:00';
        $post->status       = 'scheduled';
        $newDir             = $this->builder->postOutputDir($post->published_at, $post->slug);

        $this->builder->removeVacatedPostOutput($oldDir, $post);
        $this->builder->buildPost($post);

        $this->assertDirectoryDoesNotExist($oldDir, 'the live URL must stop serving');
        $this->assertDirectoryDoesNotExist($newDir, 'a scheduled post must not be written');
    }

    public function testAPostThatHasNotMovedKeepsItsOutput(): void
    {
        $post   = $this->makePublishedPost('staying');
        $oldDir = $this->builder->postOutputDir($post->published_at, $post->slug);
        $this->seedOutput($oldDir);

        // Saved with no change to slug or date — an edit to the body alone.
        $this->builder->removeVacatedPostOutput($oldDir, $post);

        $this->assertFileExists($oldDir . '/index.html', 'an unmoved post must not lose its output');
        $this->assertFileExists($oldDir . '/og.png');
    }

    /**
     * A create path has no previous location and must not attempt a removal at
     * all. Asserting on the filesystem alone would not catch a regression here:
     * removeFile()'s containment check quietly absorbs a bad path, so the only
     * evidence would be spurious "Refusing to delete" lines in the error log on
     * every new post. Spy on the call instead.
     */
    public function testANewPostAttemptsNoRemovalAtAll(): void
    {
        $spy = new class($this->config, $this->db) extends \CMS\Builder {
            /** @var list<string> */
            public array $removed = [];

            public function removePostOutput(string $dir): void
            {
                $this->removed[] = $dir;
            }
        };

        $post = $this->makePublishedPost('brand-new');
        $spy->removeVacatedPostOutput(null, $post);

        $this->assertSame([], $spy->removed);
    }

    // ── The build-skip guard ──────────────────────────────────────────────────

    /**
     * The write is skipped when the rendered hash matches the stored one, so a
     * post whose file has gone missing since that hash was recorded must not be
     * left unwritten. Unpublishing removes the file but not the hash, and the
     * publish date is pre-filled in the editor, so re-publishing unchanged
     * renders byte-identical HTML — the post would be listed everywhere with no
     * page behind it.
     */
    public function testRePublishingUnchangedContentWritesTheFileBackOut(): void
    {
        $this->writeTemplate('post.php', '<h1><?= $post->title ?></h1>');

        $post = $this->makePublishedPost('round-trip');
        $dir  = $this->builder->postOutputDir($post->published_at, $post->slug);

        $this->builder->buildPost($post);
        $this->assertFileExists($dir . '/index.html', 'precondition: the first build writes');
        $this->assertNotNull($post->content_hash, 'precondition: the hash is recorded');

        $post->status = 'draft';
        $this->builder->buildPost($post);
        $this->assertFileDoesNotExist($dir . '/index.html', 'precondition: unpublishing removes it');

        // Same content, same date — the render is byte-identical to the stored hash.
        $post->status = 'published';
        $this->builder->buildPost($post);

        $this->assertFileExists($dir . '/index.html', 'a missing file must be rebuilt');
    }

    public function testRePublishingAnUnchangedPageWritesTheFileBackOut(): void
    {
        $this->writeTemplate('page.php', '<h1><?= $page->title ?></h1>');

        $page = new \CMS\Page($this->db);
        $page->title   = 'About';
        $page->slug    = 'about';
        $page->content = 'x';
        $page->status  = 'published';
        $page->save();

        $path = $this->root . '/output/pages/about/index.html';

        $this->builder->buildPage($page);
        $this->assertFileExists($path, 'precondition: the first build writes');

        $page->status = 'draft';
        $this->builder->buildPage($page);
        $this->assertFileDoesNotExist($path, 'precondition: unpublishing removes it');

        $page->status = 'published';
        $this->builder->buildPage($page);

        $this->assertFileExists($path, 'a missing file must be rebuilt');
    }

    // ── Neighbor rebuilds must not drop relational data ────────────────────────

    /**
     * findPrev()/findNext() intentionally skip hydrating photos/categories/
     * tags/contexts — they're normally only used for a nav link's title and
     * slug. But every publish path also rebuilds whichever neighbor they
     * return, to keep that neighbor's own nav links current. If buildPost()
     * trusted the under-hydrated object, that rebuild would silently strip
     * the neighbor's photo from its own page. See Post::ensureHydrated().
     */
    public function testAPhotoPostKeepsItsImageWhenABuildIsTriggeredByALaterPost(): void
    {
        $this->writeTemplate('post.php', <<<'PHP'
<h1><?= $post->title ?></h1>
<?php foreach ($post->photos as $photo): ?><img src="<?= $photo['url'] ?>">
<?php endforeach; ?>
PHP);

        $photoPost = $this->makePublishedPost('photo-post');
        $photoPost->savePhotos([['url' => '/media/a.jpg', 'alt' => 'A']]);
        $this->builder->buildPost($photoPost);

        $dir = $this->builder->postOutputDir($photoPost->published_at, $photoPost->slug);
        $this->assertStringContainsString(
            '/media/a.jpg',
            file_get_contents($dir . '/index.html'),
            'precondition: the photo post is built with its image'
        );

        // Publish a later, unrelated post — this is what every real publish
        // path does: build the new post, then rebuild its findPrev() neighbor
        // (the photo post) to refresh its nav links.
        $notePost = new Post($this->db);
        $notePost->title        = 'A note';
        $notePost->slug         = 'note-post';
        $notePost->content      = 'x';
        $notePost->status       = 'published';
        $notePost->published_at = '2026-07-30 12:00:00';
        $notePost->save();
        $this->builder->buildPost($notePost);

        $prev = Post::findPrev($this->db, $notePost);
        $this->assertNotNull($prev, 'precondition: the photo post is findPrev()\'s neighbor');
        $this->builder->buildPost($prev);

        $this->assertStringContainsString(
            '/media/a.jpg',
            file_get_contents($dir . '/index.html'),
            'the photo post must keep its image after a rebuild triggered by a different post'
        );
    }

    // ── Featured image → og:image ─────────────────────────────────────────────

    /**
     * A real picture beats the generated title card in every preview that shows
     * one, so a post that has one advertises it as og:image. This is also what
     * puts the picture on the Mastodon preview card, which fetches the permalink
     * for exactly this tag.
     */
    public function testAFeaturedImageBecomesTheOgImage(): void
    {
        $this->db->upsertSetting('site_url', 'https://example.com');
        $this->writeTemplate('post.php', '<meta content="<?= $ogImageUrl ?>">');
        $this->writeMedia('lead.jpg');

        // Builder reads settings once, at construction.
        $builder = new Builder($this->config, $this->db);

        $post = $this->makePublishedPost('with-lead');
        $post->featured_image_url = '/media/lead.jpg';
        $post->save();

        $builder->buildPost($post);

        $html = file_get_contents($builder->postOutputDir($post->published_at, $post->slug) . '/index.html');
        $this->assertStringContainsString('https://example.com/media/lead.jpg', $html);
        $this->assertStringNotContainsString('og.png', $html);
    }

    /**
     * The title card is still generated and is still the fallback — removing a
     * featured image has to leave something behind rather than nothing.
     */
    public function testWithoutAFeaturedImageTheGeneratedCardIsStillUsed(): void
    {
        $this->db->upsertSetting('site_url', 'https://example.com');
        $this->writeTemplate('post.php', '<meta content="<?= $ogImageUrl ?>">');

        $post = $this->makePublishedPost('no-lead');
        $this->builder->buildPost($post);

        $dir  = $this->builder->postOutputDir($post->published_at, $post->slug);
        $html = file_get_contents($dir . '/index.html');
        // GD may be missing in CI, in which case no og.png exists and the tag is
        // empty — either way, what must not appear is a featured image URL.
        $this->assertStringNotContainsString('/media/', $html);
    }

    /**
     * A stored path whose file has gone missing must not be advertised: an
     * og:image is a URL a crawler fetches, and a 404 there is worse than the
     * title card that still works.
     */
    public function testAFeaturedImageWithNoFileOnDiskIsNotAdvertised(): void
    {
        $this->db->upsertSetting('site_url', 'https://example.com');
        $this->writeTemplate('post.php', '<meta content="<?= $ogImageUrl ?>">');

        $post = $this->makePublishedPost('missing-lead');
        $post->featured_image_url = '/media/gone.jpg';
        $post->save();

        $this->builder->buildPost($post);

        $html = file_get_contents($this->builder->postOutputDir($post->published_at, $post->slug) . '/index.html');
        $this->assertStringNotContainsString('gone.jpg', $html);
    }

    /**
     * A remote featured image (Micropub may send one) is advertised as-is. Its
     * scheme was allowlisted on the way in, and checking it here would mean an
     * outbound request on the build path. Deliberately asymmetric with the local
     * case above, which *is* checked because we can check it for free.
     */
    public function testARemoteFeaturedImageIsAdvertisedWithoutBeingChecked(): void
    {
        $this->db->upsertSetting('site_url', 'https://example.com');
        $this->writeTemplate('post.php', '<meta content="<?= $ogImageUrl ?>">');

        $builder = new Builder($this->config, $this->db);

        $post = $this->makePublishedPost('remote-lead');
        $post->featured_image_url = 'https://cdn.example.net/lead.jpg';
        $post->save();

        $builder->buildPost($post);

        $html = file_get_contents($builder->postOutputDir($post->published_at, $post->slug) . '/index.html');
        $this->assertStringContainsString('https://cdn.example.net/lead.jpg', $html);
    }

    /**
     * The double-draw guard, checked structurally because the failure is silent.
     *
     * effectiveFeaturedImage() falls back to the body's leading image, and
     * templates/post.php renders the body a few lines further down — so reading
     * the helper there would print a derived picture twice, once in the featured
     * figure and once in the content it was parsed out of. Only the *stored*
     * column has a slot of its own. The cards and the related-posts list may use
     * the helper freely: neither renders a titled post's body.
     */
    public function testThePostTemplateRendersOnlyTheStoredFeaturedImage(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/templates/post.php');

        $this->assertStringContainsString('post__featured', $template, 'precondition: the slot exists');

        // Comments stripped first — the template explains this rule in prose
        // directly above the markup, and naming the helper is not calling it.
        $code = '';
        foreach (token_get_all($template) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $this->assertStringNotContainsString(
            'effectiveFeaturedImage',
            $code,
            'templates/post.php must read featured_image_url directly, or a derived image renders twice'
        );
    }

    /**
     * A featured image is styled by sharing the body image's rules, never by
     * restating them.
     *
     * The two are the same object to a reader — same shadow, radius, width and
     * wide-viewport bleed — and a second copy of those declarations is how they
     * quietly stop matching. So the invariant is structural: no rule in
     * theme.css may target `.post__featured img` without also targeting
     * `.prose img` in the same selector list. The figure wrapper and the
     * lightbox anchor are exempt; they have no counterpart in the body.
     */
    public function testTheFeaturedImageSharesTheBodyImageStyling(): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/theme.css');
        $this->assertNotFalse($css);

        // Strip comments so prose about the rule is not mistaken for one.
        $css = preg_replace('!/\*.*?\*/!s', '', $css) ?? $css;

        preg_match_all('/([^{}]+)\{[^{}]*\}/', $css, $rules, PREG_SET_ORDER);

        $found = 0;
        foreach ($rules as [, $selectorList]) {
            if (!str_contains($selectorList, '.post__featured img')) {
                continue;
            }
            $found++;
            $this->assertStringContainsString(
                '.prose img',
                $selectorList,
                'A rule targets .post__featured img without .prose img: '
                . trim(preg_replace('/\s+/', ' ', $selectorList))
                . ' — the featured image must share the body image styling, not restate it.'
            );
        }

        $this->assertGreaterThan(0, $found, 'No .post__featured img rules found — has theme.css been restructured?');
    }

    /**
     * And that shared styling has to be in the critical half. The featured
     * image is the Largest Contentful Paint element on a post that has one, so
     * styling it from the deferred sheet would repaint the biggest thing on the
     * page after first paint.
     */
    public function testTheFeaturedImageStylingIsInTheCriticalHalf(): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/theme.css');
        $this->assertNotFalse($css);

        $marker = strpos($css, '/* =END CRITICAL= */');
        $this->assertNotFalse($marker, 'precondition: the critical-CSS marker exists');

        $lastRule = strrpos($css, '.post__featured img');
        $this->assertNotFalse($lastRule, 'precondition: the featured image is styled');
        $this->assertLessThan(
            $marker,
            $lastRule,
            '.post__featured img is styled after =END CRITICAL=, so the LCP image would restyle on deferred load.'
        );
    }

    /**
     * The OG card is the site's dark scheme, not a palette of its own.
     *
     * A social preview is the first thing most readers see of a post, so a card
     * drawn in colours that no longer match the site is a brand mismatch nobody
     * notices — it renders on someone else's timeline, never on a page anyone
     * here looks at. The three constants in OgImage are the dark-mode
     * `--color-bg`, `--color-text` and `--color-muted` from theme.css, and this
     * pins them to the values still declared there.
     */
    public function testTheOgCardUsesTheThemesDarkPalette(): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/theme.css');
        $this->assertNotFalse($css);

        // The [data-theme="dark"] block is the manual-toggle copy of the tokens;
        // the prefers-color-scheme block above it declares the same values.
        $start = strpos($css, '[data-theme="dark"]');
        $this->assertNotFalse($start, 'precondition: theme.css declares a dark token block');
        $block = substr($css, $start, (int) strpos($css, '}', $start) - $start);

        $expected = [];
        foreach (['bg', 'text', 'muted'] as $token) {
            $this->assertSame(
                1,
                preg_match('/--color-' . $token . ':\s*(#[0-9A-Fa-f]{6})/', $block, $m),
                "precondition: --color-$token is declared in the dark block"
            );
            $expected[$token] = strtoupper($m[1]);
        }

        $og  = new \ReflectionClass(OgImage::class);
        $map = [
            'BG_COLOR'    => 'bg',
            'TITLE_COLOR' => 'text',
            'META_COLOR'  => 'muted',
        ];

        foreach ($map as $constant => $token) {
            [$r, $g, $b] = $og->getConstant($constant);
            $this->assertSame(
                $expected[$token],
                sprintf('#%02X%02X%02X', $r, $g, $b),
                "OgImage::$constant has drifted from theme.css --color-$token."
            );
        }
    }

    /**
     * And a retheme has to reach the cards already on disk.
     *
     * Builder::buildOgImage() redraws only when the hash it stamped last time
     * changes, and that hash is over the text and the font file — neither of
     * which moves when only the palette or the type scale does. So the design
     * version is in the hash, and bumping it is what republishes the set.
     */
    public function testTheOgHashCoversTheCardDesign(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/src/Builder.php');
        $this->assertNotFalse($src);

        $src = preg_replace('!/\*.*?\*/!s', '', $src) ?? $src;
        $src = preg_replace('!//[^\n]*!', '', $src) ?? $src;

        $this->assertSame(
            1,
            preg_match('/\$ogHash\s*=\s*hash\(([^;]*)\);/s', $src, $m),
            'precondition: buildOgImage() still computes $ogHash with hash()'
        );

        $this->assertStringContainsString(
            'OgImage::DESIGN_VERSION',
            $m[1],
            'The OG hash omits OgImage::DESIGN_VERSION, so a card retheme would '
            . 'leave every post already built showing the old design.'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Put a file in the media directory so localPath() can resolve it. */
    private function writeMedia(string $filename): void
    {
        $dir = $this->root . '/content/media';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/' . $filename, 'JPEG');
    }


    /** Put a minimal template in place so render() has something to include. */
    private function writeTemplate(string $name, string $body): void
    {
        if (!is_dir($this->root . '/templates')) {
            mkdir($this->root . '/templates', 0775, true);
        }
        file_put_contents($this->root . '/templates/' . $name, $body);
    }


    /** Write the pair of files a published build produces. */
    private function seedOutput(string $dir): void
    {
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/index.html', '<html></html>');
        file_put_contents($dir . '/og.png', 'PNG');
    }

    private function makePublishedPost(string $slug): Post
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

    private function rmTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->rmTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
