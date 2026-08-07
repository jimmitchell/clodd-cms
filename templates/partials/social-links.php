<?php
/**
 * The rel="me" links to the author's profiles elsewhere. Rendered in two places
 * — the site footer and the home page h-card — from this one file, so adding a
 * network here adds it to both and the two can never drift apart.
 *
 * Every link carries rel="me", which is what lets an IndieAuth or mf2 parser
 * tie those profiles back to the site. In the home page h-card they are inside
 * the representative h-card, which is where a parser looks for them first.
 *
 * A network can add tokens of its own through $socialRelExtra — Bluesky's link
 * also carries rel="atproto", which is how an atproto crawler recognises the
 * link as a handle it can resolve rather than one more outbound URL.
 *
 * Required in scope: $settings, $siteUrl
 * Optional in scope:
 *   $socialFeed — true to append the RSS link (footer only; the home page
 *                 already discovers the feed via <link rel="alternate">)
 *
 * The option is read once and unset, so an earlier include cannot leak its
 * setting into a later one.
 */

use CMS\Helpers;

$socialShowFeed = !empty($socialFeed);
unset($socialFeed);

$socialMastodon = Helpers::mastodonProfileUrl($settings['mastodon_handle'] ?? '') ?? '';
$socialBluesky  = $settings['bluesky_url'] ?? '';
$socialGithub   = $settings['github_url']  ?? '';

/**
 * name => [url, inline SVG]. Built as a list so the markup below stays one loop
 * rather than one near-identical block per network.
 */
$socialLinks = [];
if ($socialMastodon !== '') {
    $socialLinks['mastodon'] = ['Mastodon', $socialMastodon,
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M23.193 7.879c0-5.206-3.411-6.732-3.411-6.732C18.062.357 15.108.025 12.041 0h-.076c-3.069.025-6.02.357-7.74 1.147 0 0-3.411 1.526-3.411 6.732 0 1.192-.023 2.618.015 4.129.124 5.092.934 10.109 5.641 11.355 2.17.574 4.034.695 5.535.612 2.722-.15 4.25-.972 4.25-.972l-.09-1.975s-1.945.613-4.13.539c-2.165-.074-4.449-.233-4.801-2.891a5.499 5.499 0 0 1-.048-.745s2.125.52 4.818.643c1.646.075 3.19-.096 4.758-.283 3.007-.359 5.625-2.212 5.954-3.905.517-2.665.475-6.507.475-6.507zm-4.024 6.709h-2.497v-6.12c0-2.666-3.43-2.769-3.43.37v3.35H10.76v-3.35c0-3.139-3.43-3.036-3.43-.37v6.12H4.833c0-6.546-.28-7.919.985-9.374 1.388-1.55 4.28-1.652 5.561.327l.635 1.046.635-1.046c1.282-1.98 4.172-1.878 5.562-.327 1.265 1.455.985 2.828.985 9.374z" fill="currentColor"/></svg>'];
}
if ($socialBluesky !== '') {
    $socialLinks['bluesky'] = ['Bluesky', $socialBluesky,
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M12 10.8c-1.087-2.114-4.046-6.053-6.798-7.995C2.566.944 1.561 1.266.902 1.565.139 1.908 0 3.08 0 3.768c0 .69.378 5.65.624 6.479.815 2.736 3.713 3.66 6.383 3.364.136-.02.275-.039.415-.056-.138.022-.276.04-.415.056-3.912.58-7.387 2.005-2.83 7.078 5.013 5.19 6.87-1.113 7.823-4.308.953 3.195 2.05 9.271 7.733 4.308 4.267-4.308 1.172-6.498-2.74-7.078a8.741 8.741 0 0 1-.415-.056c.14.017.279.036.415.056 2.67.297 5.568-.628 6.383-3.364.246-.828.624-5.79.624-6.478 0-.69-.139-1.861-.902-2.206-.659-.298-1.664-.62-4.3 1.24C16.046 4.748 13.087 8.687 12 10.8Z" fill="currentColor"/></svg>'];
}
if ($socialGithub !== '') {
    $socialLinks['github'] = ['GitHub', $socialGithub,
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12" fill="currentColor"/></svg>'];
}

/**
 * Extra rel tokens a given network wants on top of the shared "me noopener".
 */
$socialRelExtra = ['bluesky' => 'atproto'];

foreach ($socialLinks as $socialKey => [$socialName, $socialUrl, $socialIcon]):
    $socialRel = implode(' ', array_filter(
        ['me', $socialRelExtra[$socialKey] ?? '', 'noopener'],
        static fn (string $t): bool => $t !== ''
    ));
?>
<a href="<?= Helpers::e($socialUrl) ?>" class="social-link social-link--<?= Helpers::e($socialKey) ?>"
   rel="<?= Helpers::e($socialRel) ?>" target="_blank" aria-label="<?= Helpers::e($socialName) ?>"><?= $socialIcon ?></a>
<?php endforeach; ?>
<?php if ($socialShowFeed): ?>
<a href="<?= Helpers::e(rtrim($siteUrl, '/') . '/feed.rss') ?>" class="social-link social-link--feed" aria-label="RSS feed">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1.5" fill="currentColor" stroke="none"/></svg>
</a>
<?php endif; ?>
