<?php
// $db and $auth are available from the including page's bootstrap.
$siteTitle  = $db->getSetting('site_title', 'My CMS');
$currentUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

$navItems = [
    '/admin/analytics.php'  => ['label' => 'Analytics',  'icon' => 'fa-bar-chart'],
    '/admin/dashboard.php'  => ['label' => 'Dashboard',  'icon' => 'fa-tachometer'],
    '/admin/posts.php'      => ['label' => 'Posts',      'icon' => 'fa-file-text-o'],
    '/admin/pages.php'      => ['label' => 'Pages',      'icon' => 'fa-files-o'],
    '/admin/categories.php' => ['label' => 'Categories', 'icon' => 'fa-tag'],
    '/admin/tags.php'       => ['label' => 'Tags',       'icon' => 'fa-tags'],
    '/admin/media.php'      => ['label' => 'Media',      'icon' => 'fa-picture-o'],
    '/admin/tools.php'      => [
        'label' => 'Tools',
        'icon'  => 'fa-wrench',
        'match' => ['/admin/import.php', '/admin/import-media.php', '/admin/export.php'],
    ],
    '/admin/settings.php'   => [
        'label' => 'Settings',
        'icon'  => 'fa-cog',
        'match' => ['/admin/micropub.php', '/admin/account.php', '/admin/login-log.php'],
    ],
];
?>
<?php /* Font Awesome is linked by partials/head.php, not here. A stylesheet
         emitted from inside <body> is discovered late and blocks rendering
         mid-document, and three pages linked it in <head> as well. */ ?>
<script>try{if(localStorage.getItem('cms_nav_collapsed')==='1')document.body.classList.add('nav-collapsed');}catch(e){}</script>
<nav class="admin-nav" id="admin-nav">
    <div class="admin-nav__brand">
        <div class="admin-nav__brand-inner">
            <a href="/admin/analytics.php" class="admin-nav__site-link"><?= htmlspecialchars($siteTitle) ?></a>
            <a href="/" target="_blank" class="admin-nav__view-site">
                <i class="fa fa-external-link"></i>
                <span class="nav-label">View site</span>
            </a>
        </div>
        <button class="admin-nav__toggle" id="nav-toggle" title="Toggle sidebar" aria-label="Toggle sidebar">
            <i class="fa fa-bars"></i>
        </button>
    </div>
    <ul class="admin-nav__links">
        <?php foreach ($navItems as $href => $item): ?>
        <?php $isActive = $currentUri === $href || in_array($currentUri, $item['match'] ?? [], true); ?>
        <li>
            <a href="<?= htmlspecialchars($href) ?>"
               class="<?= $isActive ? 'active' : '' ?>"
               data-label="<?= htmlspecialchars($item['label']) ?>">
                <i class="fa <?= htmlspecialchars($item['icon']) ?>"></i>
                <span class="nav-label"><?= htmlspecialchars($item['label']) ?></span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
    <div class="admin-nav__actions">
        <form method="post" action="/admin/logout.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($auth->csrfToken()) ?>">
            <button type="submit" class="btn-link admin-nav__logout" data-label="Log out">
                <i class="fa fa-sign-out"></i>
                <span class="nav-label">Log out</span>
            </button>
        </form>
    </div>
</nav>
