<?php

declare(strict_types=1);

namespace CMS;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * The Markdown converter shared by the Atom, JSON and RSS feed builders.
 *
 * Each of the three used to construct its own in its constructor, and
 * Builder::buildTaxonomyFeed() constructs all three per term per rebuild — so a
 * site with 18 taxonomy terms built 54 identical converters, each of which
 * assembles the full CommonMark environment and extension set.
 *
 * The converter is stateless, so one instance serves every caller.
 *
 * Note the 'html_input' => 'allow' setting, which is deliberate and documented
 * in CLAUDE.md: it is what lets the author embed raw HTML (video, audio,
 * embeds) in post bodies. Keeping it in one place means the three feeds cannot
 * drift apart on it.
 */
final class FeedMarkdown
{
    private static ?GithubFlavoredMarkdownConverter $converter = null;

    public static function converter(): GithubFlavoredMarkdownConverter
    {
        return self::$converter ??= new GithubFlavoredMarkdownConverter(['html_input' => 'allow']);
    }

    /**
     * The Tinylytics counting pixel for one entry, or '' when no code is set.
     *
     * Here for the same reason the converter is: this was written inline in
     * Feed::render() only, so a reader on the RSS feed, the JSON feed, or any
     * per-category or per-tag Atom feed was never counted, while a reader on
     * the main Atom feed was. The numbers were not wrong so much as quietly
     * partial, which is worse.
     *
     * @param array<string,mixed> $settings
     * @param string $path Site-absolute path of the entry, e.g. /posts/2026/01/02/slug/
     */
    public static function trackingPixel(array $settings, string $path): string
    {
        $code = (string) ($settings['tinylytics_code'] ?? '');
        if ($code === '') {
            return '';
        }

        $pixelUrl = 'https://tinylytics.app/pixel/' . rawurlencode($code)
            . '.gif?path=' . rawurlencode($path);

        return '<img src="' . htmlspecialchars($pixelUrl, ENT_QUOTES | ENT_XML1, 'UTF-8') . '"'
            . ' alt="" style="width:1px;height:1px;border:0;" />';
    }
}
