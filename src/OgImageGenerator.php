<?php

declare(strict_types=1);

namespace CMS;

/**
 * Decides whether a post's OG title card needs drawing, and which image its
 * og:image should advertise.
 *
 * OgImage does the drawing; this class owns everything around it — the redraw
 * hash, the featured-image override and the GD-missing fallback. Unlike the
 * feed and sitemap generators it writes its own output: OgImage::generate()
 * saves the PNG through GD, which has no string form to hand back to
 * Builder::writeFile().
 *
 * See .claude/rules/og-card.md before changing the hash below — a card change
 * that does not move it is silently never drawn.
 */
class OgImageGenerator
{
    private string  $outputDir;
    private string  $mediaDir;
    private string  $fontDir;
    private array   $settings;
    private ?string $avatarPath;

    /**
     * @param ?string $avatarPath The author avatar as a local file, or null.
     *                            Only a local upload is drawn, for the reason
     *                            Builder::headerAvatar() gives: a remote one
     *                            would mean fetching an arbitrary URL on the
     *                            build path.
     */
    public function __construct(
        string $outputDir,
        string $mediaDir,
        string $fontDir,
        array $settings,
        ?string $avatarPath
    ) {
        $this->outputDir  = $outputDir;
        $this->mediaDir   = $mediaDir;
        $this->fontDir    = $fontDir;
        $this->settings   = $settings;
        $this->avatarPath = $avatarPath;
    }

    /**
     * Generate an OG image for a published post if the title (or site title)
     * has changed since the image was last generated.
     * Silently skips if the GD extension is unavailable.
     *
     * Returns the absolute public URL of the OG image, or '' on failure/skip.
     */
    public function forPost(Post $post): string
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

        $avatarPath  = $this->avatarPath;
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
}
