<?php

declare(strict_types=1);

namespace CMS;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\SmartPunct\SmartPunctExtension;
use League\CommonMark\MarkdownConverter;

class Builder
{
    /** Marker comment that separates critical CSS from deferred CSS in theme.css. */
    private const CRITICAL_MARKER = '/* =END CRITICAL= */';

    private Database           $db;
    private ShortcodeRenderer $shortcodes;
    private MarkdownConverter $md;
    private string             $outputDir;
    private string             $templateDir;
    private string             $mediaDir;
    private string             $fontDir;
    private array              $settings;
    private array              $navPages;
    private string             $criticalCss = '';
    private ?string            $assetVersion = null;

    /** Display size of the header avatar in CSS pixels; see .site-header__avatar. */
    private const AVATAR_CSS_PX = 32;

    /** How many related posts a titled post shows, when the setting is on. */
    private const RELATED_COUNT = 3;

    /** Encoded header avatar, memoised per build. Empty string = none, null = not yet resolved. */
    private ?string $headerAvatar = null;

    /**
     * When non-null, buildCategoryArchive()/buildTagArchive() record the term
     * instead of rebuilding it. buildPost() rebuilds the archives of every term
     * its post belongs to, which is right for a single edit but quadratic
     * during a full build: a category holding 100 posts was rebuilt 100 times,
     * and buildAll() then rebuilt every archive again anyway.
     */
    private bool $deferTaxonomy = false;

    /**
     * Suppress buildPost()'s related-posts neighbour rebuild.
     *
     * A related-posts block makes a post's output depend on *other* posts,
     * which the content_hash short-circuit knows nothing about — so buildPost()
     * re-renders everything sharing a term with the post it just built. That is
     * right for a single edit and pure waste during a bulk build, where every
     * post is being re-rendered anyway.
     *
     * It doubles as the recursion guard: without it, each neighbour would
     * rebuild its own neighbours and the pass would never terminate.
     */
    private bool $deferRelated = false;

    public function __construct(array $config, Database $db)
    {
        $this->db          = $db;
        $this->outputDir   = rtrim($config['paths']['output'],   '/\\');
        $this->templateDir = rtrim($config['paths']['templates'], '/\\');
        $this->mediaDir    = rtrim($config['paths']['content'],   '/\\') . '/media';
        // Not where the card's font lives — where one *may* be pinned. See
        // OgImage::__construct(); with nothing there the host's sans is used.
        $this->fontDir     = $this->outputDir . '/fonts/og';
        $this->shortcodes  = new ShortcodeRenderer($db, $this->mediaDir);

        // Allow trusted admin to embed <video>/<audio> in Markdown.
        $env = new Environment([
            'html_input'         => 'allow',
            'allow_unsafe_links' => false,
        ]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new GithubFlavoredMarkdownExtension());
        $env->addExtension(new FootnoteExtension());
        $env->addExtension(new SmartPunctExtension());
        $env->addRenderer(FencedCode::class, new HighlightFencedCodeRenderer());
        $env->addRenderer(Image::class, new ImageRenderer($this->mediaDir));
        $this->md = new MarkdownConverter($env);

        $this->refreshContext();
    }

    // ── Public build API ──────────────────────────────────────────────────────

    /**
     * Rebuild a single published post.
     * If the post is not published, removes its output file instead.
     */
    public function buildPost(Post $post): void
    {
        $dir  = $this->outputDir . '/posts/' . Post::datePath($post->published_at ?? date('Y-m-d H:i:s'), $post->slug, $this->settings['timezone'] ?? '');
        $path = $dir . '/index.html';

        if ($post->status !== 'published' || $post->deleted_at !== null) {
            $this->removePostOutput($dir);
            // Rebuild any archives this post was in so it no longer appears there.
            foreach ($post->categories as $cat) {
                $this->buildCategoryArchive((int) $cat['id']);
            }
            foreach ($post->tags as $tag) {
                $this->buildTagArchive((int) $tag['id']);
            }
            // A post leaving the public site has to leave everyone else's
            // related list with it, so the neighbour pass runs here too.
            $this->rebuildRelatedNeighbours($post);
            return;
        }

        // $post may have come from findPrev()/findNext(), which skip hydration
        // since they're usually only used for nav-link title/slug — but here
        // $post is the page being rendered, so photos/categories/tags/contexts
        // must be real.
        Post::ensureHydrated($this->db, $post);

        // Generate OG image first so the URL is available to the HTML template.
        $ogImageUrl = $this->buildOgImage($post);

        // renderableContent(), not ->content: when the stored featured image is
        // the same picture the body opens with, the body's copy comes out — the
        // featured figure above is already showing it.
        $html = $this->renderBody($post->renderableContent());

        // On a photo post the picture is the Largest Contentful Paint element,
        // and it is as often written inline in the body as attached as a
        // u-photo row — templates/post.php can only reach the attached kind.
        if ($post->isPhoto() && $post->photos === []) {
            $html = ResponsiveImages::promoteFirstImage($html);
        }

        $prevPost = Post::findPrev($this->db, $post);
        $nextPost = Post::findNext($this->db, $post);

        // Notes carry no title to label a link with, and are excluded from the
        // candidate set for the same reason — so they neither show the block
        // nor appear in one.
        $relatedPosts = ($this->relatedEnabled() && !$post->isNote())
            ? Post::findRelated($this->db, $post, self::RELATED_COUNT)
            : [];

        $rendered = $this->render('post.php', [
            'post'         => $post,
            'html'         => $html,
            'prevPost'     => $prevPost,
            'nextPost'     => $nextPost,
            'relatedPosts' => $relatedPosts,
            'ogImageUrl'   => $ogImageUrl,
        ]);
        $hash     = hash('sha256', $rendered);

        // The hash records what was last rendered, not what is on disk, and the
        // two part company whenever a file goes away without the hash going with
        // it — unpublishing removes the output but leaves the hash, so a post
        // re-published unchanged renders identically and would never be written
        // back. Check the file too, the same way buildOgImage() does.
        if ($hash !== $post->content_hash || !file_exists($path)) {
            if ($this->writeFile($path, $rendered)) {
                $post->markBuilt($hash);
            }
        }

        // Rebuild taxonomy archive pages for this post's terms.
        foreach ($post->categories as $cat) {
            $this->buildCategoryArchive((int) $cat['id']);
        }
        foreach ($post->tags as $tag) {
            $this->buildTagArchive((int) $tag['id']);
        }

        $this->rebuildRelatedNeighbours($post);
    }

    /** Whether the Related posts block is switched on in Settings → Content. */
    private function relatedEnabled(): bool
    {
        return ($this->settings['show_related_posts'] ?? '0') === '1';
    }

    /**
     * Re-render every post whose related list could have changed because
     * $post did.
     *
     * buildPost() hashes its own rendered HTML into content_hash and skips the
     * write when it matches, which assumes a post's output depends only on the
     * post. The related block breaks that assumption: publish a new post
     * sharing a category and every existing post in it is now wrong, with no
     * change of its own to trigger a rebuild.
     *
     * This lives inside buildPost() rather than at the call sites on purpose.
     * The prev/next rebuild is duplicated by hand across admin/post-edit.php,
     * admin/posts.php, admin/api.php, micropub.php, XmlRpcServer and
     * bin/publish-scheduled.php, and a rule that has to be remembered in eight
     * places is a rule that will be missed in one.
     *
     * Bounded by the titled corpus, and writeFile() no-ops when the bytes are
     * unchanged, so a neighbour that did not actually move costs a render and
     * no disk write.
     *
     * Known gap: saveTerms() has already replaced the junction rows by the time
     * this runs, so a post that *lost* a term is no longer connected to the
     * posts under it and they keep a stale entry until the next full rebuild.
     * Saving settings forces one, as does bin/build.php.
     */
    private function rebuildRelatedNeighbours(Post $post): void
    {
        if ($this->deferRelated || !$this->relatedEnabled()) {
            return;
        }

        $this->deferRelated = true;
        try {
            foreach (Post::findRelatedNeighbours($this->db, $post) as $neighbour) {
                $this->buildPost($neighbour);
            }
        } finally {
            $this->deferRelated = false;
        }
    }

    /**
     * Remove every generated file in a post's output directory, then prune the
     * directory itself.
     *
     * Both leftovers have to go in one pass: buildOgImage() writes og.png
     * alongside index.html, and removeFile()'s rmdir only fires on an empty
     * directory — so unlinking index.html on its own orphans the og.png *and*
     * strands the directory holding it.
     *
     * Public because a rename cleans up the pre-rename directory, whose path
     * can no longer be derived from the post. Any path is safe to pass:
     * removeFile() refuses to touch anything outside the output root.
     */
    public function removePostOutput(string $dir): void
    {
        $this->removeFile($dir . '/og.png');
        $this->removeFile($dir . '/index.html');
    }

    /**
     * The output directory a post occupies, or null if it has no public one.
     *
     * Takes the values rather than the Post so a caller can ask where a post
     * *used* to live, from a snapshot taken before the save. Pass null for
     * $publishedAt when the post was not published, so nothing is claimed.
     */
    public function postOutputDir(?string $publishedAt, string $slug): ?string
    {
        if ($publishedAt === null || $slug === '') {
            return null;
        }

        return $this->outputDir . '/posts/' . Post::datePath($publishedAt, $slug, $this->settings['timezone'] ?? '');
    }

    /**
     * Remove the output directory a post has moved away from.
     *
     * Snapshot the old location with postOutputDir() before saving, then pass
     * it here afterwards: if the post has since moved — a renamed slug or a
     * changed publication date — the directory it left is removed.
     *
     * Pair it with buildPost(), which handles only the location derived from
     * the post's *current* values. That division is why this exists: without
     * it a rename leaves the old URL serving the old HTML indefinitely, and it
     * is also why an unpublish needs no special case here — buildPost() clears
     * the current location, this clears the vacated one, and between them
     * nothing survives.
     */
    public function removeVacatedPostOutput(?string $oldDir, Post $post): void
    {
        if ($oldDir === null) {
            return;
        }

        if ($this->postOutputDir($post->published_at, $post->slug) !== $oldDir) {
            $this->removePostOutput($oldDir);
        }
    }

    /**
     * Rebuild a single published page.
     * If the page is not published, removes its output file instead.
     */
    public function buildPage(Page $page): void
    {
        $dir  = $this->outputDir . '/pages/' . $page->slug;
        $path = $dir . '/index.html';

        if ($page->status !== 'published') {
            $this->removeFile($path);
            return;
        }

        $html     = $this->renderBody($page->content);
        $rendered = $this->render('page.php', ['page' => $page, 'html' => $html]);
        $hash     = hash('sha256', $rendered);

        // See buildPost(): the stored hash can outlive the file it describes.
        if ($hash !== $page->content_hash || !file_exists($path)) {
            if ($this->writeFile($path, $rendered)) {
                $page->markBuilt($hash);
            }
        }
    }

    /**
     * Rebuild all paginated index pages and remove stale ones.
     */
    public function buildIndex(): void
    {
        $perPage = max(1, (int) ($this->settings['posts_per_page'] ?? 10));

        $allPosts = Post::findAll($this->db, 'published');
        $total    = count($allPosts);
        $pages    = max(1, (int) ceil($total / $perPage));

        for ($p = 1; $p <= $pages; $p++) {
            $slice    = array_slice($allPosts, ($p - 1) * $perPage, $perPage);
            $rendered = $this->render('index.php', [
                'posts'       => $slice,
                'postHtml'    => $this->renderNoteHtmlMap($slice, $p === 1),
                'currentPage' => $p,
                'totalPages'  => $pages,
                'totalPosts'  => $total,
            ]);

            $path = $p === 1
                ? $this->outputDir . '/index.html'
                : $this->outputDir . '/page/' . $p . '/index.html';

            $this->writeFile($path, $rendered);
        }

        // Remove stale paginated pages beyond the new total.
        $this->removeStalePaginationPages($pages);

        // Keep the search index, search page, and 404 in sync with published posts.
        $this->buildSearchIndex();
        $this->buildSearchPage();
        $this->build404();
    }

    /**
     * Render and write feed.xml.
     */
    public function buildFeed(): void
    {
        $feed = new Feed($this->db, $this->settings);
        $xml  = $feed->render();
        $this->writeFile($this->outputDir . '/feed.xml', $xml);
    }

    /**
     * Render and write feed.json (JSON Feed 1.1).
     */
    public function buildJsonFeed(): void
    {
        $feed = new JsonFeed($this->db, $this->settings);
        $this->writeFile($this->outputDir . '/feed.json', $feed->render());
    }

    /**
     * Render and write feed.rss (RSS 2.0 with Byline spec).
     */
    public function buildRssFeed(): void
    {
        $feed = new RssFeed($this->db, $this->settings);
        $this->writeFile($this->outputDir . '/feed.rss', $feed->render());
    }

    /**
     * Generate sitemap.xml listing all published posts and pages.
     * Skipped silently when site_url is not configured.
     */
    public function buildSitemap(): void
    {
        $siteUrl = rtrim($this->settings['site_url'] ?? '', '/');
        if ($siteUrl === '') {
            return;
        }

        $posts = Post::findAll($this->db, 'published');
        $pages = Page::findAll($this->db, 'published');

        $x = fn(string $v) => htmlspecialchars($v, ENT_XML1, 'UTF-8');

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        // Homepage
        $lines[] = '  <url>';
        $lines[] = '    <loc>' . $x($siteUrl . '/') . '</loc>';
        $lines[] = '  </url>';

        // Published posts
        foreach ($posts as $post) {
            if ($post->published_at === null) {
                continue;
            }
            $url     = $siteUrl . '/' . Post::datePath($post->published_at, $post->slug, $this->settings['timezone'] ?? '') . '/';
            $lastmod = substr($post->updated_at ?: $post->published_at, 0, 10);
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . $x($url) . '</loc>';
            $lines[] = '    <lastmod>' . $x($lastmod) . '</lastmod>';
            $lines[] = '  </url>';
        }

        // Published pages
        foreach ($pages as $page) {
            $url     = $siteUrl . '/' . $page->slug . '/';
            $lastmod = substr($page->updated_at, 0, 10);
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . $x($url) . '</loc>';
            $lines[] = '    <lastmod>' . $x($lastmod) . '</lastmod>';
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        $this->writeFile($this->outputDir . '/sitemap.xml', implode("\n", $lines) . "\n");
    }

    /**
     * Write search.json — an array of published posts for client-side search.
     */
    public function buildSearchIndex(): void
    {
        $posts   = Post::findAll($this->db, 'published');
        $siteUrl = rtrim($this->settings['site_url'] ?? '', '/');
        $locale  = $this->settings['locale']   ?? '';
        $tz      = $this->settings['timezone'] ?? '';

        $data = [];
        foreach ($posts as $post) {
            $excerpt = $post->effectiveExcerpt();
            $entry = [
                'title'   => $post->title,
                'url'     => $siteUrl . '/' . Post::datePath($post->published_at ?? date('Y-m-d H:i:s'), $post->slug, $this->settings['timezone'] ?? '') . '/',
                'excerpt' => $excerpt !== null ? strip_tags($excerpt) : '',
                'date'    => $post->published_at
                    ? Helpers::formatDate($post->published_at, 'M j, Y', $locale, $tz)
                    : '',
                // 'standard' | 'aside' | 'photo' — the search page branches on
                // this the same way the list templates do.
                'kind'    => $post->post_kind,
            ];
            if ($post->isNote()) {
                // noteText() rather than the raw body: a photo post's content is
                // only the picture, which strips to nothing, so it would be
                // unsearchable and render a blank result card.
                $entry['body_text'] = $post->noteText();
            }
            $data[] = $entry;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            error_log('[Builder] Failed to encode search index: ' . json_last_error_msg());
            return;
        }
        $this->writeFile($this->outputDir . '/search.json', $json);
    }

    /**
     * Pre-render Markdown bodies for the note-kind posts in $posts (asides and
     * photo posts), keyed by post id. Standard posts are skipped — list
     * templates use the excerpt for them.
     *
     * @param Post[] $posts
     * @return array<int,string>
     */
    private function renderNoteHtmlMap(array $posts, bool $firstIsAboveTheFold = false): array
    {
        $map   = [];
        $first = true;

        foreach ($posts as $post) {
            if (!$post->isNote() || $post->id === null) {
                $first = false;   // a standard post still occupies the lead slot
                continue;
            }

            $html = $this->renderBody($post->content);

            // The lead card's picture is the Largest Contentful Paint element,
            // and a photo post's image is as often written inline in the body
            // as attached as a u-photo row — this is the only place that path
            // passes through.
            if ($first && $firstIsAboveTheFold) {
                $html = ResponsiveImages::promoteFirstImage($html);
            }

            $map[$post->id] = $html;
            $first = false;
        }

        return $map;
    }

    /**
     * Write search/index.html — the static search results page.
     */
    public function buildSearchPage(): void
    {
        $rendered = $this->render('search.php', []);
        $this->writeFile($this->outputDir . '/search/index.html', $rendered);
    }

    /**
     * Render and write 404.html — the themed not-found page.
     */
    public function build404(): void
    {
        $rendered = $this->render('404.php', []);
        $this->writeFile($this->outputDir . '/404.html', $rendered);
    }

    /**
     * Generate theme.min.css (full) and theme.critical.css (above-the-fold subset)
     * from theme.css. The critical portion is everything before the marker comment
     * "=END CRITICAL="; the full file is always written regardless.
     * Safe to call repeatedly — skips silently if theme.css is absent.
     */
    public function buildCss(): void
    {
        $src      = $this->outputDir . '/theme.css';
        $dest     = $this->outputDir . '/theme.min.css';
        $critDest = $this->outputDir . '/theme.critical.css';

        if (!file_exists($src)) {
            return;
        }

        $css = (string) file_get_contents($src);
        $pos = strpos($css, self::CRITICAL_MARKER);

        if ($pos !== false) {
            // Minify each half once; concatenate for the full file so the critical
            // prefix is never processed twice.
            $critMinified = $this->minifyCss(substr($css, 0, $pos));
            $restMinified = $this->minifyCss(substr($css, $pos + strlen(self::CRITICAL_MARKER)));

            $this->writeFile($critDest, $critMinified);
            $this->writeFile($dest, $critMinified . $restMinified);

            // The deferred half on its own. base.php inlines the critical CSS
            // into every page and then linked theme.min.css, which *starts*
            // with that same critical CSS — so a first visit downloaded it
            // twice, 4.6 KB of the 10.8 KB gzipped homepage. This is what the
            // page actually links; theme.min.css stays whole for anything that
            // wants the stylesheet on its own, and for the no-marker fallback
            // below.
            $this->writeFile($this->outputDir . '/theme.deferred.css', $restMinified);

            $this->criticalCss = $critMinified;
        } else {
            $this->writeFile($dest, $this->minifyCss($css));
        }
    }

    /**
     * Rebuild the CSS bundles without re-rendering the site.
     *
     * theme.min.css is linked, so it takes effect on its own. The critical
     * subset is inlined into each page's <head> at render time, which is why a
     * theme change normally needs a full rebuild — so when that subset changes,
     * swap the old <style> block for the new one directly in the generated HTML.
     *
     * @return array{changed:bool, updated:int, current:int, linked:int}
     */
    public function rebuildCss(): array
    {
        $critFile = $this->outputDir . '/theme.critical.css';
        $before   = is_file($critFile) ? (string) file_get_contents($critFile) : '';

        $this->buildCss();

        $after = is_file($critFile) ? (string) file_get_contents($critFile) : '';

        if ($before === $after) {
            return ['changed' => false, 'updated' => 0, 'current' => 0, 'linked' => 0];
        }

        return ['changed' => true] + $this->syncInlineCriticalCss($after);
    }

    /**
     * Replace the inlined critical CSS in every generated page.
     *
     * Identifies the block by the preload link that always follows it in
     * base.php, so the separate custom_css <style> tag is never touched and the
     * page's own age doesn't matter. Pages that link the stylesheet instead of
     * inlining it need no patching — they pick up theme.min.css on their own.
     *
     * @return array{updated:int, current:int, linked:int}
     */
    private function syncInlineCriticalCss(string $new): array
    {
        // Either filename: base.php linked theme.min.css before 1.19.0 split the
        // stylesheet, and pages generated back then are still on disk untouched.
        $pattern = '/(<style>)(.*?)(<\/style>\s*<link rel="preload" href="\/theme\.(?:deferred|min)\.css")/s';

        $updated = 0;
        $current = 0;
        $linked  = 0;

        foreach ($this->generatedHtmlFiles() as $file) {
            $html = (string) file_get_contents($file);

            if (!preg_match($pattern, $html, $m)) {
                $linked++;
                continue;
            }

            if ($m[2] === $new) {
                $current++;
                continue;
            }

            $patched = preg_replace_callback(
                $pattern,
                static fn(array $parts): string => $parts[1] . $new . $parts[3],
                $html,
                1
            );
            // Through writeFile() rather than file_put_contents(), so this
            // shares the output-root guard with every other write the builder
            // makes. $file comes from generatedHtmlFiles()' own glob today, but
            // this was the one write that would not have been stopped had that
            // ever changed.
            $this->writeFile($file, $patched);
            $updated++;
        }

        return ['updated' => $updated, 'current' => $current, 'linked' => $linked];
    }

    /**
     * Every HTML file this builder generates: the root pages plus the
     * post, page, pagination, search, and taxonomy trees.
     *
     * @return list<string>
     */
    private function generatedHtmlFiles(): array
    {
        $files = glob($this->outputDir . '/*.html') ?: [];

        foreach (['posts', 'pages', 'page', 'search', 'category', 'tag'] as $sub) {
            $dir = $this->outputDir . '/' . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            $walk = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($walk as $entry) {
                if ($entry->isFile() && strtolower($entry->getExtension()) === 'html') {
                    $files[] = $entry->getPathname();
                }
            }
        }

        return array_values($files);
    }

    /**
     * Rebuild index pages, all feeds, and the sitemap.
     * Call once after any operation that changes which posts are publicly visible.
     */
    public function rebuildSharedResources(): void
    {
        $this->buildIndex();
        $this->buildFeed();
        $this->buildJsonFeed();
        $this->buildRssFeed();
        $this->buildSitemap();
    }

    /**
     * Rebuild all published posts using the latest settings from the DB.
     * Called after settings are saved so changes to custom_css, site_title,
     * footer text, etc. are immediately reflected in every post.
     */
    public function rebuildPosts(): void
    {
        $this->refreshContext();
        $this->db->exec("UPDATE posts SET content_hash = NULL");
        // Both guards, for the same reasons buildAll() sets them.
        //
        // deferTaxonomy: buildPost() rebuilds the archives of every term its
        // post belongs to, which is right for one edit and quadratic here — the
        // 105-post Photos archive, 18 pagination pages and three feeds, was
        // rebuilt 105 times. Every caller of this method follows it with
        // buildAllTaxonomyArchives(), which covers each term exactly once, so
        // the whole quadratic pass was thrown away as soon as it finished.
        //
        // deferRelated: every post is being re-rendered here, so each one
        // already picks up its own related block — the neighbour pass would
        // only re-render the same posts again, once per term they share.
        $this->deferTaxonomy = true;
        $this->deferRelated  = true;
        try {
            foreach (Post::findAll($this->db, 'published') as $post) {
                $this->buildPost($post);
            }
        } finally {
            $this->deferTaxonomy = false;
            $this->deferRelated  = false;
        }
    }

    /**
     * Rebuild all pages using the latest settings from the DB.
     * Called after settings are saved so changes to custom_css, site_title,
     * footer text, etc. are immediately reflected in every page.
     */
    public function rebuildPages(): void
    {
        $this->refreshContext();
        $this->db->exec("UPDATE pages SET content_hash = NULL");
        foreach (Page::findAll($this->db) as $page) {
            $this->buildPage($page);
        }
    }

    /**
     * Full site rebuild: all published posts, pages, index, feed, search.
     */
    public function buildAll(): void
    {
        $this->buildCss();       // must run before refreshContext so criticalCss is current
        $this->refreshContext();
        $this->migrateOldPagePaths();
        $this->migrateOldPostPaths();

        // Clear stored hashes so every file is force-regenerated.
        $this->db->exec("UPDATE posts SET content_hash = NULL");
        $this->db->exec("UPDATE pages SET content_hash = NULL");

        // Suppress buildPost()'s per-post archive rebuilds for the duration of
        // the loop; buildAllTaxonomyArchives() below covers every term once.
        // The related-posts neighbour pass goes with it, for the same reason:
        // the loop below already re-renders every post exactly once.
        $this->deferTaxonomy = true;
        $this->deferRelated  = true;
        try {
            foreach (Post::findAll($this->db) as $post) {
                $this->buildPost($post);
            }
        } finally {
            $this->deferTaxonomy = false;
            $this->deferRelated  = false;
        }

        foreach (Page::findAll($this->db) as $page) {
            $this->buildPage($page);
        }
        $this->buildIndex();
        $this->buildFeed();
        $this->buildJsonFeed();
        $this->buildRssFeed();
        $this->buildSitemap();
        $this->buildAllTaxonomyArchives();
    }

    /**
     * Rebuild the static archive page(s) and feeds for a single category.
     */
    public function buildCategoryArchive(int $categoryId): void
    {
        if ($this->deferTaxonomy) {
            return; // buildAll() sweeps every term once the post loop finishes.
        }

        $cat = $this->db->selectOne("SELECT * FROM categories WHERE id = :id", [':id' => $categoryId]);
        if ($cat === null) {
            return;
        }

        $posts = Post::findByCategory($this->db, $categoryId);
        $this->buildTaxonomyArchive('category', $cat, $posts);
        $this->buildTaxonomyFeed('category', $cat, $posts);
    }

    /**
     * Rebuild the static archive page(s) and feeds for a single tag.
     */
    public function buildTagArchive(int $tagId): void
    {
        if ($this->deferTaxonomy) {
            return; // buildAll() sweeps every term once the post loop finishes.
        }

        $tag = $this->db->selectOne("SELECT * FROM tags WHERE id = :id", [':id' => $tagId]);
        if ($tag === null) {
            return;
        }

        $posts = Post::findByTag($this->db, $tagId);
        $this->buildTaxonomyArchive('tag', $tag, $posts);
        $this->buildTaxonomyFeed('tag', $tag, $posts);
    }

    /**
     * Write feed.xml and feed.json for a taxonomy term.
     *
     * @param Post[] $posts
     */
    private function buildTaxonomyFeed(string $type, array $term, array $posts): void
    {
        $baseDir = $this->outputDir . '/' . $type . '/' . $term['slug'];

        $atomFeed = new Feed($this->db, $this->settings);
        $this->writeFile($baseDir . '/feed.xml', $atomFeed->renderForTerm($type, $term, $posts));

        $jsonFeed = new JsonFeed($this->db, $this->settings);
        $this->writeFile($baseDir . '/feed.json', $jsonFeed->renderForTerm($type, $term, $posts));

        $rssFeed = new RssFeed($this->db, $this->settings);
        $this->writeFile($baseDir . '/feed.rss', $rssFeed->renderForTerm($type, $term, $posts));
    }

    /**
     * Render and write all paginated pages for a taxonomy term archive.
     * Stale pagination pages beyond the new total are removed.
     */
    private function buildTaxonomyArchive(string $type, array $term, array $allPosts): void
    {
        $perPage    = max(1, (int) ($this->settings['posts_per_page'] ?? 10));
        $total      = count($allPosts);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $baseDir    = $this->outputDir . '/' . $type . '/' . $term['slug'];

        for ($p = 1; $p <= $totalPages; $p++) {
            $slice    = array_slice($allPosts, ($p - 1) * $perPage, $perPage);
            $rendered = $this->render('taxonomy.php', [
                'type'        => $type,
                'term'        => $term,
                'posts'       => $slice,
                'postHtml'    => $this->renderNoteHtmlMap($slice, $p === 1),
                'currentPage' => $p,
                'totalPages'  => $totalPages,
                'totalPosts'  => $total,
            ]);

            $path = $p === 1
                ? $baseDir . '/index.html'
                : $baseDir . '/page/' . $p . '/index.html';

            $this->writeFile($path, $rendered);
        }

        // Remove stale pagination pages beyond the new total.
        $pageDir     = $baseDir . '/page';
        $pageEntries = is_dir($pageDir) ? scandir($pageDir) : false;
        if ($pageEntries !== false) {
            foreach ($pageEntries as $entry) {
                if (!is_numeric($entry) || (int) $entry <= $totalPages) {
                    continue;
                }
                $this->removeFile($pageDir . '/' . $entry . '/index.html');
                $dir     = $pageDir . '/' . $entry;
                $entries = is_dir($dir) ? scandir($dir) : false;
                if ($entries !== false && count($entries) === 2) {
                    @rmdir($dir);
                }
            }
        }
    }

    /**
     * Rebuild all category and tag archive pages, removing any stale directories
     * whose terms have been deleted.
     */
    public function buildAllTaxonomyArchives(): void
    {
        $categories = $this->db->select("SELECT * FROM categories ORDER BY name");
        $validCatSlugs = [];
        foreach ($categories as $cat) {
            $this->buildCategoryArchive((int) $cat['id']);
            $validCatSlugs[] = $cat['slug'];
        }

        $tags = $this->db->select("SELECT * FROM tags ORDER BY name");
        $validTagSlugs = [];
        foreach ($tags as $tag) {
            $this->buildTagArchive((int) $tag['id']);
            $validTagSlugs[] = $tag['slug'];
        }

        // Remove stale category archive directories.
        $catDir     = $this->outputDir . '/category';
        $catEntries = is_dir($catDir) ? scandir($catDir) : false;
        if ($catEntries !== false) {
            foreach ($catEntries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (!in_array($entry, $validCatSlugs, true)) {
                    $dir = $catDir . '/' . $entry;
                    $this->removeFile($dir . '/index.html');
                    $this->removeFile($dir . '/feed.xml');
                    $this->removeFile($dir . '/feed.json');
                    $this->removeFile($dir . '/feed.rss');
                    $entries = is_dir($dir) ? scandir($dir) : false;
                    if ($entries !== false && count($entries) === 2) {
                        @rmdir($dir);
                    }
                }
            }
        }

        // Remove stale tag archive directories.
        $tagDir     = $this->outputDir . '/tag';
        $tagEntries = is_dir($tagDir) ? scandir($tagDir) : false;
        if ($tagEntries !== false) {
            foreach ($tagEntries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (!in_array($entry, $validTagSlugs, true)) {
                    $dir = $tagDir . '/' . $entry;
                    $this->removeFile($dir . '/index.html');
                    $this->removeFile($dir . '/feed.xml');
                    $this->removeFile($dir . '/feed.json');
                    $this->removeFile($dir . '/feed.rss');
                    $entries = is_dir($dir) ? scandir($dir) : false;
                    if ($entries !== false && count($entries) === 2) {
                        @rmdir($dir);
                    }
                }
            }
        }
    }

    /**
     * Render a draft post through the full theme template without writing to disk.
     * Safe to call on any post status — does not modify the post record.
     * OG image generation and prev/next navigation are skipped.
     */
    public function renderPostPreview(Post $post): string
    {
        // Drafts with no publish date need a placeholder so the template
        // can call Post::datePath() and date() without warnings.
        $originalPublishedAt = $post->published_at;
        if ($post->published_at === null) {
            $post->published_at = date('Y-m-d H:i:s');
        }

        // See buildPost() — the same de-duplication, so a preview matches.
        $html = $this->renderBody($post->renderableContent());

        $rendered = $this->render('post.php', [
            'post'         => $post,
            'html'         => $html,
            'prevPost'     => null,
            'nextPost'     => null,
            // A preview shows the post being edited, not the site around it —
            // the same reasoning that leaves prev/next null here.
            'relatedPosts' => [],
            'ogImageUrl'   => '',
        ]);

        $post->published_at = $originalPublishedAt;

        return $rendered;
    }

    /**
     * Convert Markdown to HTML using the same converter configuration used for
     * building posts and pages. Useful for export and preview features.
     */
    public function markdownToHtml(string $markdown): string
    {
        return $this->md->convert($markdown)->getContent();
    }

    /**
     * A post or page body, from Markdown to the HTML that reaches a template:
     * convert, expand shortcodes, then upgrade images.
     *
     * One method because the three call sites had drifted — renderNoteHtmlMap()
     * converted without expanding shortcodes, so a [youtube] in an aside became
     * an embed on its permalink and literal text on the home page.
     *
     * Image upgrading goes last, after shortcodes have produced their own
     * markup, so a gallery gets the same treatment as a body image.
     */
    private function renderBody(string $markdown): string
    {
        $html = $this->md->convert($markdown)->getContent();
        $html = $this->shortcodes->render($html);

        return ResponsiveImages::upgrade($html, $this->mediaDir);
    }

    /**
     * Generate an OG image for a published post if the title (or site title)
     * has changed since the image was last generated.
     * Silently skips if the GD extension is unavailable.
     *
     * Returns the absolute public URL of the OG image, or '' on failure/skip.
     */
    private function buildOgImage(Post $post): string
    {
        // Notes have no title; OG cards rendered from a missing title look broken.
        // Skip image generation for them — social previews fall back to the site OG
        // (or, for a photo post, to the photo itself via the og:image meta).
        if ($post->isNote()) {
            return '';
        }

        $datePath = Post::datePath($post->published_at ?? date('Y-m-d H:i:s'), $post->slug, $this->settings['timezone'] ?? '');
        $ogPath   = $this->outputDir . '/posts/' . $datePath . '/og.png';
        $siteUrl  = rtrim($this->settings['site_url'] ?? '', '/');

        // A real picture beats a text card in every preview that shows one, so
        // a featured image is what Mastodon and every other OG consumer gets.
        // The title card is still generated below as the fallback — removing
        // the featured image has to leave something behind.
        $featuredUrl = $this->featuredOgUrl($post, $siteUrl);

        if (!extension_loaded('gd')) {
            // Return existing URL if the image was previously generated.
            return $featuredUrl
                ?? (file_exists($ogPath) ? $siteUrl . '/' . $datePath . '/og.png' : '');
        }

        $siteTitle = $this->settings['site_title'] ?? '';

        // Only a local upload is drawn, for the reason headerAvatar() gives: a
        // remote one would mean fetching an arbitrary URL on the build path.
        $avatarPath  = $this->localMediaPath((string) ($this->settings['author_avatar_url'] ?? ''));
        $avatarStamp = $avatarPath === null
            ? ''
            : $avatarPath . '@' . (@filemtime($avatarPath) ?: 0);

        try {
            // Built before the hash rather than inside the redraw below: the
            // card is set in whichever sans the host provides, so the font
            // stamp that decides whether a redraw is needed can only be asked
            // of the instance that resolved it.
            $og        = new OgImage($this->fontDir);
            $fontStamp = $og->fontStamp();

            // The design version is in the hash because nothing else in it moves
            // when only the card's palette or type changes — a retheme would
            // otherwise leave every post already on disk showing the old card
            // forever. The avatar is stamped by path *and* mtime for the same
            // reason: replacing the picture behind an unchanged setting has to
            // redraw the set too, and so does moving to a host whose default
            // sans is a different file.
            $ogHash = hash(
                'sha256',
                OgImage::DESIGN_VERSION . '|' . $fontStamp . '|' . $avatarStamp . '|' . $siteTitle . '|' . $post->title
            );

            if ($ogHash !== $post->og_image_hash || !file_exists($ogPath)) {
                $og->generate($siteTitle, $post->title, $ogPath, $avatarPath ?? '');
                $post->markOgBuilt($ogHash);
            }
        } catch (\RuntimeException $e) {
            // Non-fatal: log to stderr and continue. A host with no usable font
            // lands here on every post, and the build still succeeds.
            error_log('[OgImage] ' . $e->getMessage());
        }

        return $featuredUrl
            ?? (file_exists($ogPath) ? $siteUrl . '/' . $datePath . '/og.png' : '');
    }

    /**
     * The post's featured image as an absolute URL, or null when it has none.
     *
     * effectiveFeaturedImage(), so a post that was written with its picture at
     * the top of the body — every WordPress import, and anything MarsEdit sent
     * before the thumbnail field existed — advertises that picture too.
     *
     * An og:image has to be a URL a crawler can actually fetch, so one of our
     * own paths is checked against the media directory first — a stored path
     * whose file has gone missing would advertise a 404 in place of a title card
     * that still works. A remote URL (Micropub may send one) is taken on trust:
     * its scheme was allowlisted on the way in, and there is nothing to check
     * here that would not mean an outbound request on the build path.
     */
    private function featuredOgUrl(Post $post, string $siteUrl): ?string
    {
        $featured = $post->effectiveFeaturedImage();
        if ($featured === null) {
            return null;
        }

        $url = $featured['url'];
        if (!str_starts_with($url, '/')) {
            return $url;
        }

        return SyndicationMedia::localPath($url, $this->mediaDir, $siteUrl) !== null
            ? $siteUrl . $url
            : null;
    }

    /**
     * Remove post output files that were written as posts/{slug}/index.html
     * before URLs were changed to posts/{year}/{month}/{day}/{slug}/index.html.
     * Safe to call repeatedly — only removes flat (non-date) slug directories.
     */
    private function migrateOldPostPaths(): void
    {
        $postsDir = $this->outputDir . '/posts';
        if (!is_dir($postsDir)) {
            return;
        }

        $migrated = false;
        foreach (scandir($postsDir) as $entry) {
            // Skip dots and any entry that looks like a 4-digit year (new format).
            if ($entry === '.' || $entry === '..' || is_numeric($entry)) {
                continue;
            }
            $oldDir = $postsDir . '/' . $entry;
            if (!is_dir($oldDir)) {
                continue;
            }
            // Clean up either leftover regardless of whether index.html still
            // exists — an earlier build may have removed index.html but left an
            // orphaned og.png, which would otherwise strand the flat dir forever.
            if (file_exists($oldDir . '/index.html') || file_exists($oldDir . '/og.png')) {
                $this->removePostOutput($oldDir);
                $migrated = true;
            }
        }

        if ($migrated) {
            $this->db->exec("UPDATE posts SET content_hash = NULL, built_at = NULL, og_image_hash = NULL");
        }
    }

    /**
     * Remove page output files that were written to the project root before
     * pages were moved to the pages/ subdirectory. Safe to call repeatedly —
     * only deletes {outputDir}/{slug}/index.html, never anything inside pages/.
     */
    private function migrateOldPagePaths(): void
    {
        $migrated = false;
        foreach (Page::findAll($this->db) as $page) {
            $oldPath = $this->outputDir . '/' . $page->slug . '/index.html';
            if (file_exists($oldPath)) {
                $this->removeFile($oldPath);
                $migrated = true;
            }
        }
        // If any old files were removed, clear all page hashes so buildPage()
        // is forced to write to the new pages/ location on this run.
        if ($migrated) {
            $this->db->exec("UPDATE pages SET content_hash = NULL, built_at = NULL");
        }
    }

    // ── Rendering ─────────────────────────────────────────────────────────────

    /**
     * Render a template file to a string.
     * The template has access to: all $vars keys, $settings, $navPages, $siteUrl, $builder.
     *
     * @param array<string,mixed> $vars
     */
    /**
     * The header avatar, inlined as a data URI.
     *
     * The setting holds a full-size upload — Jim's is a 768px PNG at 856KB — and
     * the header draws it at 32px. Fetching that to fill a circle the size of a
     * word left the placeholder background visible while it downloaded, which
     * read as a flash on every page load. Encoding a 64px square (2x for retina)
     * into the markup costs about 1.5KB a page and removes the request, so the
     * avatar is there in the first paint alongside the inlined critical CSS.
     *
     * Only local uploads qualify: a remote avatar would mean fetching an
     * arbitrary URL during the build. Returns '' when that is the case or when
     * GD cannot encode it, and base.php falls back to the plain URL.
     */
    private function headerAvatar(): string
    {
        if ($this->headerAvatar !== null) {
            return $this->headerAvatar;
        }

        $this->headerAvatar = '';

        $source = $this->localMediaPath((string) ($this->settings['author_avatar_url'] ?? ''));
        if ($source === null) {
            return $this->headerAvatar;
        }

        $encoded = Media::squareWebpDataUri($source, self::AVATAR_CSS_PX * 2);

        return $this->headerAvatar = $encoded ?? '';
    }

    /**
     * Resolve a URL to the file it names inside the media directory, or null if
     * it names anything else. Public /media/ is an alias for that directory, so
     * a URL is local when its path sits under it — the host is not checked,
     * because site_url changes between dev and prod while the uploads do not.
     *
     * The filename is taken with basename() and the result confirmed to be
     * inside the media root, so no encoded traversal in the setting can walk out
     * of it.
     */
    private function localMediaPath(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '' || !str_starts_with($path, '/media/')) {
            return null;
        }

        $name = basename(rawurldecode($path));
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        $real = realpath($this->mediaDir . '/' . $name);
        $root = realpath($this->mediaDir);
        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($real) ? $real : null;
    }

    /**
     * Cache-busting stamp for the theme assets, appended as ?v= in base.php.
     *
     * Nginx serves theme.js, theme.min.css, theme.deferred.css and webmentions.js
     * with `expires 7d, must-revalidate`, and inside that window a browser does not
     * ask whether they changed — so an edit reached returning readers up to a week
     * late, on a deploy that reported success.
     *
     * Read here rather than taken from the CMS_VERSION constant alone: the constant
     * is defined by the entry points that expect to be a CLI or the admin, and
     * micropub.php builds posts without it. A page built by one path and a page
     * built by the other must not disagree about the stamp.
     */
    private function assetVersion(): string
    {
        if ($this->assetVersion !== null) {
            return $this->assetVersion;
        }

        $version = defined('CMS_VERSION') ? trim((string) CMS_VERSION) : '';
        if ($version === '') {
            $version = trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION'));
        }

        return $this->assetVersion = ($version !== '' ? $version : 'dev');
    }

    private function render(string $template, array $vars): string
    {
        // Make shared context available inside the template scope.
        $vars['settings'] = $this->settings;
        $vars['navPages'] = $this->navPages;
        $vars['siteUrl']  = rtrim($this->settings['site_url'] ?? '', '/');

        $templateDir = $this->templateDir;
        $assetVersion = $this->assetVersion();

        /* Injected by the closure rather than added to each template's compact()
           list: base.php is rendered from six templates, and one that forgot it
           would emit an unstamped asset URL — a silent return of the very caching
           problem the stamp exists to fix. */
        $render = static function (string $tpl, array $v) use ($templateDir, $assetVersion): string {
            $v['assetVersion'] = $assetVersion;
            extract($v, EXTR_SKIP);
            ob_start();
            include $templateDir . '/' . $tpl;
            return (string) ob_get_clean();
        };

        // Give templates access to a $render closure for including base.php.
        $vars['render']      = $render;
        $vars['criticalCss'] = $this->criticalCss;
        // Photo templates build their <img>/<picture> through ImageTag, which
        // needs the media directory to find dimensions and WebP companions.
        $vars['mediaDir']    = $this->mediaDir;
        $vars['headerAvatar'] = $this->headerAvatar();

        return $render($template, $vars);
    }

    // ── File I/O ──────────────────────────────────────────────────────────────

    private function writeFile(string $path, string $content): bool
    {
        if (!$this->isInsideOutputDir($path)) {
            error_log('[Builder] Refusing to write outside the output root: ' . $path);
            return false;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (str_ends_with($path, '.html')) {
            $content = $this->minifyHtml($content);
        }

        // Only posts and pages are hash-guarded upstream; the index, feeds,
        // sitemap, search index and taxonomy archives are re-rendered on every
        // build and were previously rewritten even when byte-identical. Beyond
        // the wasted I/O that moved every mtime, which makes rsync and CDN
        // syncs treat the whole site as changed. Compare after minifying so the
        // comparison sees exactly what would be written.
        if (is_file($path) && file_get_contents($path) === $content) {
            return true;
        }

        return file_put_contents($path, $content) !== false;
    }

    /**
     * True when $path resolves inside the configured output root.
     *
     * Slugs reach the output path through Post::datePath() / Page slugs, so a
     * slug that ever escaped sanitisation would otherwise let a build write
     * anywhere the PHP user can reach. Resolution is lexical because the target
     * usually does not exist yet (realpath() would return false).
     */
    private function isInsideOutputDir(string $path): bool
    {
        $root = realpath($this->outputDir);
        if ($root === false) {
            return false;
        }

        $root     = self::normalizePath($root);
        $resolved = self::normalizePath($path);

        return $resolved === $root || str_starts_with($resolved, $root . '/');
    }

    /**
     * Collapse ".", "..", and repeated separators in an absolute path without
     * touching the filesystem. A leading ".." is dropped rather than climbing
     * above the root, so the result can never escape by underflow.
     */
    private static function normalizePath(string $path): string
    {
        $path       = str_replace('\\', '/', $path);
        $isAbsolute = str_starts_with($path, '/');

        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        return ($isAbsolute ? '/' : '') . implode('/', $out);
    }

    /**
     * Strip insignificant whitespace from HTML output.
     * Protects <pre>, <script>, and <style> blocks verbatim.
     */
    private function minifyHtml(string $html): string
    {
        $tokens = [];
        $i      = 0;

        // Preserve blocks where whitespace is significant.
        $html = preg_replace_callback(
            '/<(pre|script|style|textarea)(\s[^>]*)?>[\s\S]*?<\/\1>/i',
            static function (array $m) use (&$tokens, &$i): string {
                $key          = "\x02BLOCK{$i}\x03";
                $tokens[$key] = $m[0];
                $i++;
                return $key;
            },
            $html
        );

        // Remove HTML comments (keep IE conditionals: <!--[if ...>).
        $html = preg_replace('/<!--(?!\[if\s)[\s\S]*?-->/i', '', $html);

        // Strip leading whitespace (indentation) from every line.
        $html = preg_replace('/^\s+/m', '', $html);

        // Collapse runs of blank lines to nothing.
        $html = preg_replace('/\n{2,}/', "\n", $html);

        // Restore protected blocks.
        return strtr(trim($html), $tokens);
    }

    /**
     * Minify a CSS string: strip comments, collapse whitespace,
     * remove spaces around structural characters.
     */
    private function minifyCss(string $css): string
    {
        // Remove /* ... */ comments.
        $css = preg_replace('/\/\*[\s\S]*?\*\//', '', $css);

        // Protect calc()/clamp()/min()/max() — they contain arithmetic
        // operators (+ - * /) whose surrounding whitespace is significant
        // and would otherwise be stripped by the structural-char regex below.
        $tokens = [];
        $css = preg_replace_callback(
            '/\b(?:calc|clamp|min|max)\((?:[^()]+|\([^()]*\))*\)/i',
            function ($m) use (&$tokens) {
                $key          = "\0CSSFN" . count($tokens) . "\0";
                $tokens[$key] = preg_replace('/\s+/', ' ', $m[0]);
                return $key;
            },
            $css
        );

        // Collapse all whitespace (spaces, tabs, newlines) to a single space.
        $css = preg_replace('/\s+/', ' ', $css);

        // Remove spaces around structural characters.
        $css = preg_replace('/\s*([{}:;,>+~])\s*/', '$1', $css);

        // Drop the redundant semicolon before a closing brace.
        $css = str_replace(';}', '}', $css);

        return trim(strtr($css, $tokens));
    }

    private function removeFile(string $path): void
    {
        // Unpublishing routes a slug-derived path here, so the same containment
        // check writeFile() applies guards the delete side too.
        if (!$this->isInsideOutputDir($path)) {
            error_log('[Builder] Refusing to delete outside the output root: ' . $path);
            return;
        }

        if (file_exists($path)) {
            unlink($path);
            // Remove parent directory if now empty.
            $dir     = dirname($path);
            $entries = is_dir($dir) ? scandir($dir) : false;
            if ($entries !== false && count($entries) === 2) {
                @rmdir($dir);
            }
        }
    }

    private function removeStalePaginationPages(int $validPageCount): void
    {
        $pageDir     = $this->outputDir . '/page';
        $pageEntries = is_dir($pageDir) ? scandir($pageDir) : false;
        if ($pageEntries === false) {
            return;
        }
        foreach ($pageEntries as $entry) {
            if (!is_numeric($entry)) {
                continue;
            }
            $n = (int) $entry;
            if ($n > $validPageCount) {
                $stale = $pageDir . '/' . $entry . '/index.html';
                $this->removeFile($stale);
                $dir     = $pageDir . '/' . $entry;
                $entries = is_dir($dir) ? scandir($dir) : false;
                if ($entries !== false && count($entries) === 2) {
                    @rmdir($dir);
                }
            }
        }
    }

    // ── Context ───────────────────────────────────────────────────────────────

    /**
     * (Re)load site settings and published nav pages from the DB.
     * Called on construction and before buildAll().
     */
    private function refreshContext(): void
    {
        $this->settings = $this->db->getAllSettings();

        $navAll = array_values(array_filter(
            Page::findAll($this->db, 'published'),
            fn($p) => $p->nav_order > 0
        ));

        $byParent = [];
        foreach ($navAll as $p) {
            if ($p->parent_id !== null) {
                $byParent[$p->parent_id][] = $p;
            }
        }

        $top = [];
        foreach ($navAll as $p) {
            if ($p->parent_id === null) {
                $p->children = $byParent[$p->id] ?? [];
                $top[] = $p;
            }
        }
        $this->navPages = $top;

        $this->criticalCss = @file_get_contents($this->outputDir . '/theme.critical.css') ?: '';
    }
}
