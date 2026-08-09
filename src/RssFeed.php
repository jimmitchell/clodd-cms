<?php

declare(strict_types=1);

namespace CMS;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * RSS 2.0 feed generator with the Byline spec
 * (https://bylinespec.org/1.0) embedded at channel and item level.
 *
 * Mirrors the surface of Feed.php (Atom): render() for the main feed,
 * renderForTerm() for taxonomy archives.
 */
class RssFeed
{
    private Database                            $db;
    private array                               $settings;
    private GithubFlavoredMarkdownConverter     $converter;

    public function __construct(Database $db, array $settings)
    {
        $this->db        = $db;
        $this->settings  = $settings;
        $this->converter = FeedMarkdown::converter();
    }

    public function render(): string
    {
        $count    = (int) ($this->settings['feed_post_count'] ?? 20);
        $siteUrl  = rtrim($this->settings['site_url'] ?? '', '/');
        $title    = $this->settings['site_title']       ?? 'My CMS';
        $desc     = $this->settings['site_description'] ?? '';
        $feedUrl  = $siteUrl . '/feed.rss';
        $homeUrl  = $siteUrl . '/';

        $posts = $this->db->select(
            "SELECT id, title, slug, content, excerpt, published_at, updated_at, post_kind
               FROM posts
              WHERE status = 'published'
                AND deleted_at IS NULL
              ORDER BY published_at DESC
              LIMIT :limit",
            ['limit' => $count]
        );

        $postIds      = array_map(fn($p) => (int) $p['id'], $posts);
        $photosById   = Post::photosForPostIds($this->db, $postIds);
        $contextsById = Post::contextsForPostIds($this->db, $postIds);

        $lastBuild = !empty($posts)
            ? $this->rfc822($posts[0]['updated_at'] ?? $posts[0]['published_at'])
            : $this->rfc822(date('Y-m-d H:i:s'));

        $xml  = $this->channelOpen($title, $homeUrl, $desc, $feedUrl, $lastBuild);

        foreach ($posts as $post) {
            $tz      = $this->settings['timezone'] ?? '';
            $postUrl = $siteUrl . '/' . Post::datePath($post['published_at'], $post['slug'], $tz) . '/';
            $html    = Post::contextsHtml($contextsById[(int) $post['id']] ?? [])
                     . Post::photosHtml($photosById[(int) $post['id']] ?? [], $siteUrl)
                     . $this->converter->convert($post['content'])->getContent()
                     . Post::photoCaptionHtml($post['post_kind'] ?? null, $post['excerpt'] ?? null);
            $isNote  = Post::isNoteKind($post['post_kind'] ?? null);
            $xml    .= $this->itemXml(
                title:       $isNote ? null : $post['title'],
                url:         $postUrl,
                publishedAt: $post['published_at'],
                html:        $html
            );
        }

        $xml .= '  </channel>' . "\n";
        $xml .= '</rss>' . "\n";

        return $xml;
    }

    /**
     * @param Post[] $posts
     */
    public function renderForTerm(string $type, array $term, array $posts): string
    {
        $count     = (int) ($this->settings['feed_post_count'] ?? 20);
        $siteUrl   = rtrim($this->settings['site_url'] ?? '', '/');
        $siteTitle = $this->settings['site_title'] ?? 'My CMS';
        $label     = $type === 'category' ? 'Category' : 'Tag';
        $title     = $siteTitle . ' — ' . $label . ': ' . $term['name'];
        $termUrl   = $siteUrl . '/' . $type . '/' . rawurlencode($term['slug']) . '/';
        $feedUrl   = $siteUrl . '/' . $type . '/' . rawurlencode($term['slug']) . '/feed.rss';
        $desc      = $siteTitle . ' posts ' . ($type === 'category' ? 'in category ' : 'tagged ') . $term['name'];

        $posts = array_slice($posts, 0, $count);

        $lastBuild = !empty($posts)
            ? $this->rfc822($posts[0]->updated_at ?? $posts[0]->published_at)
            : $this->rfc822(date('Y-m-d H:i:s'));

        $xml = $this->channelOpen($title, $termUrl, $desc, $feedUrl, $lastBuild);

        foreach ($posts as $post) {
            $postUrl = $siteUrl . '/' . Post::datePath($post->published_at, $post->slug, $this->settings['timezone'] ?? '') . '/';
            $html    = Post::contextsHtml($post->contexts)
                     . Post::photosHtml($post->photos, $siteUrl)
                     . $this->converter->convert($post->content)->getContent()
                     . Post::photoCaptionHtml($post->post_kind, $post->excerpt);
            $xml    .= $this->itemXml(
                title:       $post->isNote() ? null : $post->title,
                url:         $postUrl,
                publishedAt: $post->published_at,
                html:        $html
            );
        }

        $xml .= '  </channel>' . "\n";
        $xml .= '</rss>' . "\n";

        return $xml;
    }

    private function channelOpen(string $title, string $linkUrl, string $desc, string $selfUrl, string $lastBuild): string
    {
        $generator = 'Clodd CMS ' . (defined('CMS_VERSION') ? CMS_VERSION : '1.0.0');
        $locale    = trim((string) ($this->settings['locale'] ?? ''));
        // RSS <language> is loosely RFC 1766 — `en_US` style locales must be
        // converted to `en-us` form. Empty locales are omitted.
        $language  = $locale !== '' ? strtolower(str_replace('_', '-', $locale)) : '';

        $authorName  = trim((string) ($this->settings['author_name'] ?? ''));
        $replyEmail  = trim((string) ($this->settings['reply_email'] ?? ''));

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0"'
              . ' xmlns:byline="https://bylinespec.org/1.0"'
              . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
              . ' xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '  <channel>' . "\n";
        $xml .= '    <title>' . $this->x($title) . '</title>' . "\n";
        $xml .= '    <link>' . $this->x($linkUrl) . '</link>' . "\n";
        $xml .= '    <description>' . $this->x($desc !== '' ? $desc : $title) . '</description>' . "\n";
        $xml .= '    <atom:link href="' . $this->x($selfUrl) . '" rel="self" type="application/rss+xml"/>' . "\n";
        if ($language !== '') {
            $xml .= '    <language>' . $this->x($language) . '</language>' . "\n";
        }
        $xml .= '    <lastBuildDate>' . $lastBuild . '</lastBuildDate>' . "\n";
        $xml .= '    <generator>' . $this->x($generator) . '</generator>' . "\n";
        if ($authorName !== '' && $replyEmail !== '') {
            $xml .= '    <managingEditor>' . $this->x($replyEmail . ' (' . $authorName . ')') . '</managingEditor>' . "\n";
        }

        // Indent the Byline channel block to match the 4-space channel-child indent.
        $byline = Byline::channelXml($this->settings, '    ');
        if ($byline !== '') {
            $xml .= $byline;
        }

        return $xml;
    }

    private function itemXml(?string $title, string $url, ?string $publishedAt, string $html): string
    {
        $authorName = trim((string) ($this->settings['author_name'] ?? ''));

        $xml  = '    <item>' . "\n";
        if ($title !== null && $title !== '') {
            $xml .= '      <title>' . $this->x($title) . '</title>' . "\n";
        }
        $xml .= '      <link>' . $this->x($url) . '</link>' . "\n";
        $xml .= '      <guid isPermaLink="true">' . $this->x($url) . '</guid>' . "\n";
        if ($publishedAt !== null && $publishedAt !== '') {
            $xml .= '      <pubDate>' . $this->rfc822($publishedAt) . '</pubDate>' . "\n";
        }
        if ($authorName !== '') {
            $xml .= '      <dc:creator>' . $this->x($authorName) . '</dc:creator>' . "\n";
        }
        $xml .= '      <description><![CDATA[' . $html . ']]></description>' . "\n";

        $bylineRef = Byline::authorRefXml($this->settings, '      ');
        if ($bylineRef !== '') {
            $xml .= $bylineRef;
        }

        $xml .= '    </item>' . "\n";
        return $xml;
    }

    private function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Convert a SQLite datetime string to an RFC 822 date (RSS pubDate format). */
    private function rfc822(string $dt): string
    {
        $ts = strtotime($dt);
        if ($ts === false) {
            error_log('[RssFeed] Invalid datetime value: ' . $dt);
            return gmdate('D, d M Y H:i:s') . ' GMT';
        }
        return gmdate('D, d M Y H:i:s', $ts) . ' GMT';
    }
}
