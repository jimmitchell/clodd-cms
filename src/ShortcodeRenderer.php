<?php

declare(strict_types=1);

namespace CMS;

/**
 * Expands `[shortcode …]` tags in rendered post/page HTML into embeds.
 *
 * CommonMark wraps a bare shortcode paragraph in <p>…</p>; render() matches
 * that wrapper and replaces it with the embed markup. Supported tags:
 * gallery, youtube, vimeo, gist, mastodon, instagram, tweet, linkedin.
 *
 * Each provider renderer validates its input and returns '' on a bad value
 * so malformed shortcodes silently drop rather than emit broken embeds.
 */
class ShortcodeRenderer
{
    public function __construct(
        private Database $db,
        private string $mediaDir
    ) {
    }

    public function render(string $html): string
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
}
