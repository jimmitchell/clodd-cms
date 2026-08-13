<?php
/**
 * Static page template.
 * Variables: $page (Page), $html (rendered Markdown), $settings, $navPages, $siteUrl, $render
 */

use CMS\Helpers;

$siteTitle   = $settings['site_title'] ?? 'My CMS';
$pageTitle   = $page->title . ' — ' . $siteTitle;
$description = Helpers::truncate($html, 160);
$canonical   = rtrim($siteUrl, '/') . '/' . rawurlencode($page->slug) . '/';
$ogType      = 'website';

ob_start();
?>
<section class="page">
    <header class="post__header">
        <h1 class="post__title"><?= htmlspecialchars($page->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    </header>

    <div class="post__content prose">
        <?= $html ?>
    </div>
</section>
<?php
$bodyContent = ob_get_clean();

echo $render('base.php', compact(
    'pageTitle', 'description', 'canonical', 'ogType', 'bodyContent',
    'settings', 'navPages', 'siteUrl', 'render', 'criticalCss', 'headerAvatar'
));
