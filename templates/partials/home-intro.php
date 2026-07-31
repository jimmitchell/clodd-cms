<?php
/**
 * The site's representative h-card, shown above the feed on page 1 of the home
 * page. Two jobs at once:
 *
 *  1. It tells a first-time visitor who they're reading.
 *  2. It is the h-card that IndieAuth clients, webmention senders, and mf2
 *     parsers look for. `u-url` and `u-uid` both resolve to the home page, which
 *     is what makes it unambiguously *representative* rather than just an h-card
 *     that happens to be on the page. Without it, the h-entry cards below have
 *     no author for a parser to discover.
 *
 * Copy is settings-driven — `home_intro` if set, otherwise the author bio. Renders
 * nothing at all when there is no author name to introduce.
 *
 * Required in scope: $settings, $siteUrl
 */

use CMS\Helpers;

$introName = trim((string) ($settings['author_name'] ?? ''));
$introText = trim((string) ($settings['home_intro'] ?? ''));
if ($introText === '') {
    $introText = trim((string) ($settings['author_bio'] ?? ''));
}

// Buffered so the wrapper — and its margin — disappear entirely when no social
// profiles are configured, rather than leaving an empty gap under the greeting.
ob_start();
include __DIR__ . '/social-links.php';
$introSocial = trim(ob_get_clean());

if ($introName !== ''):
?>
<div class="home-intro h-card">
    <p class="home-intro__text">
        Hey, I&rsquo;m <a class="p-name u-url u-uid" rel="me author"
           href="<?= Helpers::e(rtrim($siteUrl, '/') . '/') ?>"><?= Helpers::e($introName) ?></a>.<?php
        if ($introText !== ''): ?>
        <span class="p-note"><?= Helpers::e($introText) ?></span><?php
        endif; ?>
    </p>
    <?php /* The same icons as the footer, minus the feed — the home page already
             advertises that through <link rel="alternate">. They sit inside the
             h-card so their rel="me" is found on the representative h-card, not
             only down in the footer. */ ?>
    <?php if ($introSocial !== ''): ?>
    <div class="home-intro__social"><?= $introSocial ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>
