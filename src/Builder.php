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

    /**
     * When non-null, buildCategoryArchive()/buildTagArchive() record the term
     * instead of rebuilding it. buildPost() rebuilds the archives of every term
     * its post belongs to, which is right for a single edit but quadratic
     * during a full build: a category holding 100 posts was rebuilt 100 times,
     * and buildAll() then rebuilt every archive again anyway.
     */
    private bool $deferTaxonomy = false;

    public function __construct(array $config, Database $db)
    {
        $this->db          = $db;
        $this->outputDir   = rtrim($config['paths']['output'],   '/\\');
        $this->templateDir = rtrim($config['paths']['templates'], '/\\');
        $this->mediaDir    = rtrim($config['paths']['content'],   '/\\') . '/media';
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
            return;
        }

        // Generate OG image first so the URL is available to the HTML template.
        $ogImageUrl = $this->buildOgImage($post);

        $html       = $this->md->convert($post->content)->getContent();
        $html       = $this->shortcodes->render($html);
        // Contract: gallery wrappers must include data-gallery so the lightbox JS
        // in base.php is loaded. Preserve this attribute if changing
        // ShortcodeRenderer::renderGallery().
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

        $html     = $this->md->convert($page->content)->getContent();
        $html     = $this->shortcodes->render($html);
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
                'postHtml'    => $this->renderNoteHtmlMap($slice),
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
    private function renderNoteHtmlMap(array $posts): array
    {
        $map = [];
        foreach ($posts as $post) {
            if ($post->isNote() && $post->id !== null) {
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
        $pattern = '/(<style>)(.*?)(<\/style>\s*<link rel="preload" href="\/theme\.min\.css")/s';

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

        // Suppress buildPost()'s per-post archive rebuilds for the duration of
        // the loop; buildAllTaxonomyArchives() below covers every term once.
        $this->deferTaxonomy = true;
        try {
            foreach (Post::findAll($this->db) as $post) {
                $this->buildPost($post);
            }
        } finally {
            $this->deferTaxonomy = false;
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
                'postHtml'    => $this->renderNoteHtmlMap($slice),
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
        $html       = $this->shortcodes->render($html);
        // Contract: gallery wrappers must include data-gallery so the lightbox JS
        // in base.php is loaded. Preserve this attribute if changing
        // ShortcodeRenderer::renderGallery().
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
        // Notes have no title; OG cards rendered from a missing title look broken.
        // Skip image generation for them — social previews fall back to the site OG
        // (or, for a photo post, to the photo itself via the og:image meta).
        if ($post->isNote()) {
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
        // Photo templates build their <img>/<picture> through ImageTag, which
        // needs the media directory to find dimensions and WebP companions.
        $vars['mediaDir']    = $this->mediaDir;

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
