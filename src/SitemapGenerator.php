<?php

declare(strict_types=1);

namespace CMS;

/**
 * Renders sitemap.xml: the homepage, every published post and every published
 * page.
 *
 * Renders only — Builder::buildSitemap() writes the result, so the file goes
 * through writeFile()'s output-root guard and unchanged-content check like
 * everything else the build produces.
 */
class SitemapGenerator
{
    private Database $db;
    private array    $settings;

    public function __construct(Database $db, array $settings)
    {
        $this->db       = $db;
        $this->settings = $settings;
    }

    /**
     * The sitemap XML, or null when site_url is not configured — a sitemap of
     * relative URLs is not one, so there is nothing to write.
     */
    public function render(): ?string
    {
        $siteUrl = rtrim($this->settings['site_url'] ?? '', '/');
        if ($siteUrl === '') {
            return null;
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

        return implode("\n", $lines) . "\n";
    }
}
