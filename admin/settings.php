<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Helpers;

$tabs = [
    'general'  => 'General',
    'micropub' => 'Micropub',
    'account'  => 'Account',
    'logs'     => 'Logs',
];

$activeTab = (string) ($_GET['tab'] ?? 'general');
if (!isset($tabs[$activeTab])) {
    $activeTab = 'general';
}

$basePath  = '/admin/settings.php';
$pageTitle = 'Settings';

// Handler runs first — it may exit (redirect after POST).
require __DIR__ . '/partials/settings/' . $activeTab . '.handler.php';

$siteTitle = $db->getSetting('site_title', 'My CMS');
$flash     = $auth->getFlash();
$flashMsg  = $flash['message'] ?? '';
$flashType = $flash['type']    ?? 'success';

?>
<?php
// The account tab's conditional Font Awesome link is gone: the shared head
// links it for every page carrying the nav, which this one does.
$adminTitle = $tabs[$activeTab] . ' — ' . $pageTitle . ' — ' . $siteTitle;
require __DIR__ . '/partials/head.php';
?>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1><?= Helpers::e($pageTitle) ?></h1>
    </header>

    <?php require __DIR__ . '/partials/page-tabs.php'; ?>

    <?php if ($flashMsg !== ''): ?>
        <p class="alert alert--<?= Helpers::e($flashType) ?>"><?= Helpers::e($flashMsg) ?></p>
    <?php endif; ?>

    <?php require __DIR__ . '/partials/settings/' . $activeTab . '.view.php'; ?>
</main>

<script src="/admin/assets/admin.js?v=<?= rawurlencode(CMS_VERSION) ?>"></script>
</body>
</html>
