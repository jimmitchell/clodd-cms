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
    private MarkdownConverter $md;
    private string             $outputDir;
    private string             $templateDir;
    private string             $mediaDir;
    private string             $fontDir;
    private array              $settings;
    private array              $navPages;
    private string             $criticalCss = '';

    public function __construct(array $config, Database $db)
    {
        $this->db          = $db;
        $this->outputDir   = rtrim($config['paths']['output'],   '/\\');
        $this->templateDir = rtrim($config['paths']['templates'], '/\\');
        $this->mediaDir    = rtrim($config['paths']['content'],   '/\\') . '/media';
        $this->fontDir     = $this->outputDir . '/fonts/og';

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

        if ($post->status !== 'published') {
            $this->removeFile($path);
            // Rebuild any archives this post was in so it no longer appears there.
            foreach ($post->categories as $cat) {
                $this->buildCategoryArchive((int) $cat['id']);
            }
            foreach ($post->tags as $tag) {
                $this->buildTagArchive((int) $tag['id']);
            }
            return;
        }

        // Generate OG image first so the URL is available to the HTML template.
        $ogImageUrl = $this->buildOgImage($post);

        $html       = $this->md->convert($post->content)->getContent();
        $html       = $this->processShortcodes($html);
        // Contract: gallery wrappers must include data-gallery so the lightbox JS
        // in base.php is loaded. Preserve this attribute if changing renderGallery().
        $hasGallery = str_contains($html, 'data-gallery');
        $prevPost   = Post::findPrev($this->db, $post);
        $nextPost   = Post::findNext($this->db, $post);
        $rendered   = $this->render('post.php', [
            'post'        => $post,
            'html'        => $html,
            'hasGallery'  => $hasGallery,
            'prevPost'    => $prevPost,
            'nextPost'    => $nextPost,
            'ogImageUrl'  => $ogImageUrl,
        ]);
        $hash     = hash('sha256', $rendered);

        if ($hash !== $post->content_hash) {
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

        $html     = $this->md->convert($page->content)->getContent();
        $html     = $this->processShortcodes($html);
        $rendered = $this->render('page.php', ['page' => $page, 'html' => $html]);
        $hash     = hash('sha256', $rendered);

        if ($hash !== $page->content_hash) {
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
                'postHtml'    => $this->renderAsideHtmlMap($slice),
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
            $isAside = $post->isAside();
            $entry = [
                'title'    => $post->title,
                'url'      => $siteUrl . '/' . Post::datePath($post->published_at ?? date('Y-m-d H:i:s'), $post->slug, $this->settings['timezone'] ?? '') . '/',
                'excerpt'  => $excerpt !== null ? strip_tags($excerpt) : '',
                'date'     => $post->published_at
                    ? Helpers::formatDate($post->published_at, 'M j, Y', $locale, $tz)
                    : '',
                'is_aside' => $isAside,
            ];
            if ($isAside) {
                $entry['body_text'] = Post::plaintextFromMarkdown($post->content);
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
     * Pre-render Markdown bodies for any aside posts in $posts, keyed by post id.
     * Standard posts are skipped — list templates use the excerpt for them.
     *
     * @param Post[] $posts
     * @return array<int,string>
     */
    private function renderAsideHtmlMap(array $posts): array
    {
        $map = [];
        foreach ($posts as $post) {
            if ($post->isAside() && $post->id !== null) {
                $map[$post->id] = $this->md->convert($post->content)->getContent();
            }
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
            $this->criticalCss = $critMinified;
        } else {
            $this->writeFile($dest, $this->minifyCss($css));
        }
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
        foreach (Post::findAll($this->db, 'published') as $post) {
            $this->buildPost($post);
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

        foreach (Post::findAll($this->db) as $post) {
            $this->buildPost($post);
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
                'postHtml'    => $this->renderAsideHtmlMap($slice),
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

        $html       = $this->md->convert($post->content)->getContent();
        $html       = $this->processShortcodes($html);
        // Contract: gallery wrappers must include data-gallery so the lightbox JS
        // in base.php is loaded. Preserve this attribute if changing renderGallery().
        $hasGallery = str_contains($html, 'data-gallery');

        $rendered = $this->render('post.php', [
            'post'       => $post,
            'html'       => $html,
            'hasGallery' => $hasGallery,
            'prevPost'   => null,
            'nextPost'   => null,
            'ogImageUrl' => '',
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
     * Generate an OG image for a published post if the title (or site title)
     * has changed since the image was last generated.
     * Silently skips if the GD extension is unavailable.
     *
     * Returns the absolute public URL of the OG image, or '' on failure/skip.
     */
    private function buildOgImage(Post $post): string
    {
        // Asides have no title; OG cards rendered from a missing title look broken.
        // Skip image generation for asides — social previews fall back to the site OG.
        if ($post->isAside()) {
            return '';
        }

        $datePath = Post::datePath($post->published_at ?? date('Y-m-d H:i:s'), $post->slug, $this->settings['timezone'] ?? '');
        $ogPath   = $this->outputDir . '/posts/' . $datePath . '/og.png';
        $siteUrl  = rtrim($this->settings['site_url'] ?? '', '/');

        if (!extension_loaded('gd')) {
            // Return existing URL if the image was previously generated.
            return file_exists($ogPath) ? $siteUrl . '/' . $datePath . '/og.png' : '';
        }

        $siteTitle  = $this->settings['site_title'] ?? '';
        $fontStamp  = (string) (@filemtime($this->fontDir . '/Figtree-Bold.ttf') ?: 0);
        $ogHash     = hash('sha256', $fontStamp . '|' . $siteTitle . '|' . $post->title);

        if ($ogHash !== $post->og_image_hash || !file_exists($ogPath)) {
            try {
                $og = new OgImage($this->fontDir);
                $og->generate($siteTitle, $post->title, $ogPath);
                $post->markOgBuilt($ogHash);
            } catch (\RuntimeException $e) {
                // Non-fatal: log to stderr and continue.
                error_log('[OgImage] ' . $e->getMessage());
            }
        }

        return file_exists($ogPath) ? $siteUrl . '/' . $datePath . '/og.png' : '';
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
                $this->removeFile($oldDir . '/index.html');
                $this->removeFile($oldDir . '/og.png');
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

    // ── Shortcodes ────────────────────────────────────────────────────────────

    /**
     * Replace supported shortcodes in rendered HTML.
     * Must be called on the HTML output of the Markdown converter, not on raw Markdown.
     *
     * Supported shortcodes (placed alone on a paragraph line in Markdown):
     *   [gallery ids="1,2,3"]
     *   [youtube id="dQw4w9WgXcQ"]
     *   [vimeo id="123456789"]
     *   [gist url="https://gist.github.com/user/abc123"]
     *   [mastodon url="https://mastodon.social/@user/123456789"]
     *   [instagram url="https://www.instagram.com/p/ABC123/"]
     *   [tweet url="https://x.com/user/status/123456789"]
     *   [linkedin urn="urn:li:share:1234567890"]
     */
    private function processShortcodes(string $html): string
    {
        // CommonMark wraps a bare shortcode paragraph in <p>…</p>.
        // SmartPunctExtension may convert ASCII " to Unicode curly quotes,
        // so parseShortcodeAttrs() handles both forms.
        $result = (string) preg_replace_callback(
            '/<p>\s*\[([a-z][a-z0-9_-]*)([^\]]*)\]\s*<\/p>/iu',
            function (array $m): string {
                $tag   = strtolower($m[1]);
                $attrs = $this->parseShortcodeAttrs($m[2]);
                return match ($tag) {
                    'gallery'   => $this->renderGallery(
                                       array_filter(array_map('intval', explode(',', $attrs['ids'] ?? '')))
                                   ),
                    'youtube'   => $this->renderYouTube($attrs),
                    'vimeo'     => $this->renderVimeo($attrs),
                    'gist'      => $this->renderGist($attrs),
                    'mastodon'  => $this->renderMastodon($attrs),
                    'instagram' => $this->renderInstagram($attrs),
                    'tweet'     => $this->renderTweet($attrs),
                    'linkedin'  => $this->renderLinkedIn($attrs),
                    default     => $m[0], // unknown tag — leave as-is
                };
            },
            $html
        );

        // Deduplicate external embed scripts that may appear multiple times
        // when several embeds of the same type are on one page.
        foreach ([
            '<script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>',
            '<script async defer src="https://www.instagram.com/embed.js"></script>',
        ] as $script) {
            $count = substr_count($result, $script);
            if ($count > 1) {
                $result = str_replace($script, '', $result);
                $result = rtrim($result) . "\n" . $script . "\n";
            }
        }

        return $result;
    }

    /**
     * Parse shortcode attribute string into a key→value array.
     * Handles both ASCII quotes and Unicode curly/smart quotes produced
     * by SmartPunctExtension (", ", ', ').
     */
    private function parseShortcodeAttrs(string $attrStr): array
    {
        $attrs = [];
        preg_match_all(
            '/([a-z][a-z0-9_-]*)\s*=\s*[\x{201C}\x{201D}\x{2018}\x{2019}"\'](.*?)[\x{201C}\x{201D}\x{2018}\x{2019}"\']/iu',
            $attrStr,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $m) {
            $attrs[strtolower($m[1])] = $m[2];
        }
        return $attrs;
    }

    /** [youtube id="VIDEO_ID"] — privacy-enhanced embed via youtube-nocookie.com */
    private function renderYouTube(array $attrs): string
    {
        $id = trim($attrs['id'] ?? '');
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            return '';
        }
        $x = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<div class="embed-video">'
             . '<iframe src="https://www.youtube-nocookie.com/embed/' . $x($id) . '" '
             . 'loading="lazy" allowfullscreen '
             . 'referrerpolicy="strict-origin-when-cross-origin" '
             . 'title="YouTube video"></iframe>'
             . '</div>' . "\n";
    }

    /** [vimeo id="VIDEO_ID"] — privacy-friendly embed with dnt=1 */
    private function renderVimeo(array $attrs): string
    {
        $id = trim($attrs['id'] ?? '');
        if ($id === '' || !preg_match('/^\d+$/', $id)) {
            return '';
        }
        $x = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<div class="embed-video">'
             . '<iframe src="https://player.vimeo.com/video/' . $x($id) . '?dnt=1" '
             . 'loading="lazy" allowfullscreen '
             . 'referrerpolicy="strict-origin-when-cross-origin" '
             . 'title="Vimeo video"></iframe>'
             . '</div>' . "\n";
    }

    /**
     * [gist url="https://gist.github.com/user/abc123"]
     * Optionally: file="filename.php" to highlight a single file.
     * Uses the static .pibb render — no external JS required in the page.
     */
    private function renderGist(array $attrs): string
    {
        $url = trim($attrs['url'] ?? '');
        if ($url === '' || !preg_match('#^https://gist\.github\.com/[A-Za-z0-9_-]+/[a-f0-9]+$#i', $url)) {
            return '';
        }
        $x   = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $src = $x($url) . '.pibb';
        if (!empty($attrs['file'])) {
            $src .= '?file=' . $x($attrs['file']);
        }
        return '<div class="embed-gist">'
             . '<iframe src="' . $src . '" loading="lazy" '
             . 'sandbox="allow-scripts allow-same-origin" '
             . 'title="GitHub Gist"></iframe>'
             . '</div>' . "\n";
    }

    /**
     * [mastodon url="https://mastodon.social/@user/123456789"]
     * Embeds the post via the instance's native /embed URL — no external JS needed.
     */
    private function renderMastodon(array $attrs): string
    {
        $url = trim($attrs['url'] ?? '');
        if ($url === '' || !preg_match('#^https://[^/]+/@[^/]+/\d+$#i', $url)) {
            return '';
        }
        $x = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<div class="embed-social">'
             . '<iframe src="' . $x($url) . '/embed" class="mastodon-embed" '
             . 'loading="lazy" allowfullscreen '
             . 'title="Mastodon post"></iframe>'
             . '</div>' . "\n";
    }

    /**
     * [instagram url="https://www.instagram.com/p/ABC123/"]
     * Renders a blockquote fallback + Instagram's embed.js (loaded once per page).
     */
    private function renderInstagram(array $attrs): string
    {
        $url = trim($attrs['url'] ?? '');
        if ($url === '' || !preg_match('#^https://(?:www\.)?instagram\.com/p/[A-Za-z0-9_-]+#i', $url)) {
            return '';
        }
        $x = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<blockquote class="instagram-media embed-card" '
             . 'data-instgrm-permalink="' . $x($url) . '" '
             . 'data-instgrm-version="14">'
             . '<a href="' . $x($url) . '">View on Instagram</a>'
             . '</blockquote>'
             . '<script async defer src="https://www.instagram.com/embed.js"></script>' . "\n";
    }

    /**
     * [tweet url="https://x.com/user/status/123456789"]
     * Also accepts twitter.com URLs. Renders a blockquote fallback +
     * Twitter/X widgets.js (loaded once per page).
     */
    private function renderTweet(array $attrs): string
    {
        $url = trim($attrs['url'] ?? '');
        if ($url === '' || !preg_match('#^https://(?:twitter|x)\.com/[^/]+/status/\d+#i', $url)) {
            return '';
        }
        $x = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<blockquote class="twitter-tweet">'
             . '<a href="' . $x($url) . '">View on X / Twitter</a>'
             . '</blockquote>'
             . '<script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>' . "\n";
    }

    /**
     * [linkedin urn="urn:li:share:1234567890"]
     * Accepted URN types: share, activity, ugcPost.
     * Get the URN from LinkedIn's "Embed this post" option.
     */
    private function renderLinkedIn(array $attrs): string
    {
        $urn = trim($attrs['urn'] ?? '');
        if ($urn === '' || !preg_match('#^urn:li:(?:share|activity|ugcPost):\d+$#', $urn)) {
            return '';
        }
        $x   = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $src = 'https://www.linkedin.com/embed/feed/update/' . $x($urn);
        return '<div class="embed-social embed-social--linkedin">'
             . '<iframe src="' . $src . '" loading="lazy" allowfullscreen '
             . 'title="LinkedIn post"></iframe>'
             . '</div>' . "\n";
    }

    /**
     * Build the HTML for a [gallery ids="…"] shortcode.
     *
     * Renders a Mondrian-style tiled gallery: images cropped to fit pre-designed
     * CSS Grid templates per image count. Galleries with 8+ images are split into
     * sibling chunks (e.g. 11 → 6+5). The lightbox JS in base.php collects items
     * across all .gallery[data-gallery] blocks on the page, so chunking is invisible
     * to navigation.
     *
     * @param int[] $ids  Ordered list of media IDs.
     */
    private function renderGallery(array $ids): string
    {
        if (empty($ids)) {
            return '';
        }

        $media = new Media($this->db, $this->mediaDir);
        $items = array_values(array_filter(
            $media->findByIds($ids),
            fn($i) => Media::isImage($i['mime_type'])
        ));

        $n = count($items);
        if ($n === 0) {
            return '';
        }

        $html = '';
        foreach ($this->chunkCounts($n) as $size) {
            $chunk = array_splice($items, 0, $size);
            $html .= $this->renderGalleryChunk($chunk);
        }
        return $html;
    }

    /**
     * Split N images into chunks sized between 1 and 7 (inclusive) for the
     * available tiled-gallery templates. Aim for a balanced last chunk.
     *
     *   1..7   → [n]
     *   8..14  → split into two chunks (e.g. 8→[4,4], 11→[6,5], 14→[7,7])
     *   15+    → peel 6s until remainder ≤ 7
     *
     * @return int[]
     */
    private function chunkCounts(int $n): array
    {
        if ($n <= 7) {
            return [$n];
        }
        if ($n <= 14) {
            $first = (int) ceil($n / 2);
            return [$first, $n - $first];
        }
        $chunks = [];
        while ($n > 7) {
            $chunks[] = 6;
            $n -= 6;
        }
        $chunks[] = $n;
        return $chunks;
    }

    /**
     * Render one .gallery--tiles-N block.
     *
     * @param array<array<string,mixed>> $items  Image-only media records, length 1-7.
     */
    private function renderGalleryChunk(array $items): string
    {
        $count = count($items);
        $x     = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<div class="gallery gallery--tiles-' . $count . '" data-gallery>' . "\n";

        foreach ($items as $i => $item) {
            $url     = '/media/' . rawurlencode($item['filename']);
            $href    = $x($url);
            $alt     = $x($item['original_name']);
            $area    = chr(ord('a') + $i);
            $webpUrl = Media::webpUrlFor($item['filename'], $this->mediaDir);

            $html .= '  <a href="' . $href . '" class="gallery__item" data-gallery-item style="grid-area:' . $area . '">'
                   . '<picture>';
            if ($webpUrl !== null) {
                $html .= '<source srcset="' . $x($webpUrl) . '" type="image/webp">';
            }
            $html .= '<img src="' . $href . '" alt="' . $alt . '" loading="lazy" decoding="async">'
                   . '</picture></a>' . "\n";
        }

        $html .= '</div>' . "\n";
        return $html;
    }

    // ── Rendering ─────────────────────────────────────────────────────────────

    /**
     * Render a template file to a string.
     * The template has access to: all $vars keys, $settings, $navPages, $siteUrl, $builder.
     *
     * @param array<string,mixed> $vars
     */
    private function render(string $template, array $vars): string
    {
        // Make shared context available inside the template scope.
        $vars['settings'] = $this->settings;
        $vars['navPages'] = $this->navPages;
        $vars['siteUrl']  = rtrim($this->settings['site_url'] ?? '', '/');

        $templateDir = $this->templateDir;

        $render = static function (string $tpl, array $v) use ($templateDir): string {
            extract($v, EXTR_SKIP);
            ob_start();
            include $templateDir . '/' . $tpl;
            return (string) ob_get_clean();
        };

        // Give templates access to a $render closure for including base.php.
        $vars['render']      = $render;
        $vars['criticalCss'] = $this->criticalCss;

        return $render($template, $vars);
    }

    // ── File I/O ──────────────────────────────────────────────────────────────

    private function writeFile(string $path, string $content): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (str_ends_with($path, '.html')) {
            $content = $this->minifyHtml($content);
        }
        return file_put_contents($path, $content) !== false;
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
