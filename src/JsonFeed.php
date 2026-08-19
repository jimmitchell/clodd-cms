<?php

declare(strict_types=1);

namespace CMS;

use League\CommonMark\GithubFlavoredMarkdownConverter;

class JsonFeed
{
    private Database                        $db;
    private array                           $settings;
    private GithubFlavoredMarkdownConverter $converter;

    public function __construct(Database $db, array $settings)
    {
        $this->db        = $db;
        $this->settings  = $settings;
        $this->converter = FeedMarkdown::converter();
    }

    /**
     * Render JSON Feed 1.1 for the N most-recently published posts.
     * Returns the raw JSON string (UTF-8, trailing newline).
     */
    public function render(): string
    {
        $count   = (int) ($this->settings['feed_post_count'] ?? 20);
        $siteUrl = rtrim($this->settings['site_url'] ?? '', '/');
        $title   = $this->settings['site_title']       ?? 'My CMS';
        $desc    = $this->settings['site_description'] ?? '';

        $posts = $this->db->select(
            "SELECT id, title, slug, content, excerpt, published_at, updated_at, post_kind,
                    featured_image_url, featured_image_alt
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

        $feed = [
            'version'       => 'https://jsonfeed.org/version/1.1',
            'title'         => $title,
            'home_page_url' => $siteUrl . '/',
            'feed_url'      => $siteUrl . '/feed.json',
        ];

        if ($desc !== '') {
            $feed['description'] = $desc;
        }

        $items = [];
        foreach ($posts as $post) {
            $postUrl = $siteUrl . '/' . Post::datePath($post['published_at'], $post['slug'], $this->settings['timezone'] ?? '') . '/';
            $photos  = $photosById[(int) $post['id']] ?? [];
            $html    = Post::contextsHtml($contextsById[(int) $post['id']] ?? [])
                     . Post::storedFeaturedHtml($post['featured_image_url'] ?? null, (string) ($post['featured_image_alt'] ?? ''), $siteUrl)
                     . Post::photosHtml($photos, $siteUrl)
                     . $this->converter->convert(Post::contentForRender((string) $post['content'], $post['featured_image_url'] ?? null))->getContent()
                     . Post::photoCaptionHtml($post['post_kind'] ?? null, $post['excerpt'] ?? null);
            $isNote  = Post::isNoteKind($post['post_kind'] ?? null);

            $item = [
                'id'             => $postUrl,
                'url'            => $postUrl,
                'content_html'   => $html,
                'date_published' => $this->rfc3339($post['published_at']),
                'date_modified'  => $this->rfc3339($post['updated_at'] ?? $post['published_at']),
            ];
            if (!$isNote) {
                $item['title'] = $post['title'];
            }
            // The image a titled post is illustrated by is its featured one, so
            // that comes first; the effective photos are the fallback, and they
            // in turn fall back to the body for an admin-written photo post.
            // $photos above stays the raw rows: photosHtml() prepends them to
            // the content, so a body-derived image would render twice in
            // content_html.
            $itemFeatured = Post::featuredOrLeadingImage(
                $post['featured_image_url'] ?? null,
                (string) ($post['featured_image_alt'] ?? ''),
                (string) $post['content'],
                $post['post_kind'] ?? null
            );
            $itemPhotos = $itemFeatured !== null
                ? [$itemFeatured]
                : Post::photosOrBodyImages($photos, (string) $post['content'], $post['post_kind'] ?? null);
            if ($itemPhotos !== []) {
                $imageUrl = (string) $itemPhotos[0]['url'];
                $item['image'] = str_starts_with($imageUrl, '/') ? $siteUrl . $imageUrl : $imageUrl;
            }

            if (!empty($post['excerpt'])) {
                $item['summary'] = $post['excerpt'];
            }

            $items[] = $item;
        }

        $feed['items'] = $items;

        $json = json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            error_log('[JsonFeed] Failed to encode feed: ' . json_last_error_msg());
            return '';
        }
        return $json . "\n";
    }

    /**
     * Render JSON Feed 1.1 for a taxonomy term (category or tag).
     *
     * @param string  $type  'category' or 'tag'
     * @param array   $term  Assoc array with keys: id, name, slug
     * @param Post[]  $posts Published Post objects for this term
     */
    public function renderForTerm(string $type, array $term, array $posts): string
    {
        $count     = (int) ($this->settings['feed_post_count'] ?? 20);
        $siteUrl   = rtrim($this->settings['site_url'] ?? '', '/');
        $siteTitle = $this->settings['site_title'] ?? 'My CMS';
        $label     = $type === 'category' ? 'Category' : 'Tag';
        $title     = $siteTitle . ' — ' . $label . ': ' . $term['name'];
        $termUrl   = $siteUrl . '/' . $type . '/' . rawurlencode($term['slug']) . '/';
        $feedUrl   = $siteUrl . '/' . $type . '/' . rawurlencode($term['slug']) . '/feed.json';

        $posts = array_slice($posts, 0, $count);

        $feed = [
            'version'       => 'https://jsonfeed.org/version/1.1',
            'title'         => $title,
            'home_page_url' => $termUrl,
            'feed_url'      => $feedUrl,
        ];

        $items = [];
        foreach ($posts as $post) {
            $postUrl = $siteUrl . '/' . Post::datePath($post->published_at, $post->slug, $this->settings['timezone'] ?? '') . '/';
            $html    = Post::contextsHtml($post->contexts)
                     . Post::storedFeaturedHtml($post->featured_image_url, $post->featured_image_alt, $siteUrl)
                     . Post::photosHtml($post->photos, $siteUrl)
                     . $this->converter->convert($post->renderableContent())->getContent()
                     . Post::photoCaptionHtml($post->post_kind, $post->excerpt);

            $item = [
                'id'             => $postUrl,
                'url'            => $postUrl,
                'content_html'   => $html,
                'date_published' => $this->rfc3339($post->published_at),
                'date_modified'  => $this->rfc3339($post->updated_at ?? $post->published_at),
            ];
            if (!$post->isNote()) {
                $item['title'] = $post->title;
            }
            // See render() above: the featured image first, then the effective
            // photos, while photosHtml() above keeps the raw rows so nothing
            // renders twice.
            $itemFeatured = $post->effectiveFeaturedImage();
            $itemPhotos   = $itemFeatured !== null ? [$itemFeatured] : $post->effectivePhotos();
            if ($itemPhotos !== []) {
                $imageUrl = (string) $itemPhotos[0]['url'];
                $item['image'] = str_starts_with($imageUrl, '/') ? $siteUrl . $imageUrl : $imageUrl;
            }

            if (!empty($post->excerpt)) {
                $item['summary'] = $post->excerpt;
            }

            $items[] = $item;
        }

        $feed['items'] = $items;

        $json = json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            error_log('[JsonFeed] Failed to encode term feed: ' . json_last_error_msg());
            return '';
        }
        return $json . "\n";
    }

    /** Convert a SQLite datetime string to RFC 3339. */
    private function rfc3339(string $dt): string
    {
        $ts = strtotime($dt);
        return $ts !== false ? date('Y-m-d\TH:i:s\Z', $ts) : date('Y-m-d\TH:i:s\Z');
    }
}
