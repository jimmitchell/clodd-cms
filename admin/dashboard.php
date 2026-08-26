<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Helpers;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rebuild') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    // Full rebuild can take many minutes on large sites — long enough that
    // nginx's fastcgi_read_timeout would otherwise cut the response off.
    // Send the redirect immediately, then keep building after FastCGI hangs
    // up so the user gets instant feedback. Completion is recorded in the
    // activity log.
    $auth->flash('Full site rebuild started — this may take several minutes. Check the activity log to confirm completion.');
    header('Location: /admin/dashboard.php');

    ignore_user_abort(true);
    set_time_limit(0);
    session_write_close();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    $builder->buildAll();
    $activityLog->log('rebuild', 'site');
    exit;
}

$flash     = $auth->getFlash();
$flashMsg  = $flash['message'] ?? '';
$flashType = $flash['type']    ?? 'success';

// Quick stats.
$postStats = $db->selectOne(
    "SELECT
        SUM(CASE WHEN status = 'published'  THEN 1 ELSE 0 END) AS published,
        SUM(CASE WHEN status = 'draft'      THEN 1 ELSE 0 END) AS draft,
        SUM(CASE WHEN status = 'scheduled'  THEN 1 ELSE 0 END) AS scheduled,
        COUNT(*) AS total
     FROM posts
    WHERE deleted_at IS NULL"
);

$pageCount  = (int) ($db->selectOne("SELECT COUNT(*) AS cnt FROM pages")['cnt'] ?? 0);
$mediaCount = (int) ($db->selectOne("SELECT COUNT(*) AS cnt FROM media")['cnt'] ?? 0);

// Scheduled posts due soon (next 24 h).
$dueSoon = $db->select(
    "SELECT id, title, published_at
       FROM posts
      WHERE status = 'scheduled'
        AND deleted_at IS NULL
        AND published_at <= datetime('now', '+24 hours')
      ORDER BY published_at ASC"
);

// All drafts, newest first.
$drafts = $db->select(
    "SELECT id, title, updated_at
       FROM posts
      WHERE status = 'draft'
        AND deleted_at IS NULL
      ORDER BY updated_at DESC"
);

// All scheduled posts, soonest first.
$scheduled = $db->select(
    "SELECT id, title, published_at
       FROM posts
      WHERE status = 'scheduled'
        AND deleted_at IS NULL
      ORDER BY published_at ASC"
);

// Is the cron runner alive? Only asked when something is actually scheduled —
// see Scheduler::heartbeatIsStale().
$schedulerIsStale = $scheduler->heartbeatIsStale();
$schedulerLastRan = 'never';
if ($schedulerIsStale) {
    $lastRan = $db->getSetting(\CMS\Scheduler::HEARTBEAT_KEY, '');
    if ($lastRan !== '') {
        $mins = intdiv($scheduler->heartbeatAgeSeconds(), 60);
        $schedulerLastRan = $mins >= 120
            ? intdiv($mins, 60) . ' hours ago'
            : $mins . ' minutes ago';
    }
}

$siteTitle = $db->getSetting('site_title', 'My CMS');
$csrf      = $auth->csrfToken();

?>
<?php
$adminTitle = 'Dashboard — ' . $siteTitle;
require __DIR__ . '/partials/head.php';
?>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1>Dashboard</h1>
        <span class="page-header__meta">Clodd CMS v<?= Helpers::e(CMS_VERSION) ?></span>
    </header>

    <?php if ($flashMsg !== ''): ?>
        <p class="alert alert--<?= Helpers::e($flashType) ?>"><?= Helpers::e($flashMsg) ?></p>
    <?php endif; ?>

    <section class="stats-grid">
        <div class="stat-card">
            <span class="stat-card__number"><?= (int) ($postStats['published'] ?? 0) ?></span>
            <span class="stat-card__label">Published Posts</span>
        </div>
        <div class="stat-card">
            <span class="stat-card__number"><?= (int) ($postStats['draft'] ?? 0) ?></span>
            <span class="stat-card__label">Drafts</span>
        </div>
        <div class="stat-card">
            <span class="stat-card__number"><?= (int) ($postStats['scheduled'] ?? 0) ?></span>
            <span class="stat-card__label">Scheduled</span>
        </div>
        <div class="stat-card">
            <span class="stat-card__number"><?= $pageCount ?></span>
            <span class="stat-card__label">Pages</span>
        </div>
        <div class="stat-card">
            <span class="stat-card__number"><?= $mediaCount ?></span>
            <span class="stat-card__label">Media Files</span>
        </div>
    </section>

    <?php if ($dueSoon): ?>
    <section class="panel">
        <h2>Scheduled — due within 24 h</h2>
        <ul class="item-list">
            <?php foreach ($dueSoon as $post): ?>
            <li>
                <a href="/admin/post-edit.php?id=<?= (int) $post['id'] ?>">
                    <?= Helpers::e($post['title']) ?>
                </a>
                <span class="meta"><?= Helpers::e($post['published_at']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($drafts): ?>
    <section class="panel">
        <h2>Drafts</h2>
        <ul class="item-list">
            <?php foreach ($drafts as $post): ?>
            <li>
                <a href="/admin/post-edit.php?id=<?= (int) $post['id'] ?>">
                    <?= Helpers::e($post['title']) ?>
                </a>
                <span class="meta">Updated <?= Helpers::e($post['updated_at']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($scheduled): ?>
    <section class="panel">
        <h2>Scheduled</h2>
        <?php if ($schedulerIsStale): ?>
        <?php /* Without this a dead cron is invisible: posts simply stop going
                 live at their time, and the only symptom is a date that has
                 quietly passed in the list below. */ ?>
        <p class="alert alert--error">
            The scheduled-post runner last ran <?= Helpers::e($schedulerLastRan) ?>.
            These posts will not publish on time until it is running again —
            check the <code>bin/publish-scheduled.php</code> cron job.
        </p>
        <?php endif; ?>
        <ul class="item-list">
            <?php foreach ($scheduled as $post): ?>
            <li>
                <a href="/admin/post-edit.php?id=<?= (int) $post['id'] ?>">
                    <?= Helpers::e($post['title']) ?>
                </a>
                <span class="meta">Publishes <?= Helpers::e($post['published_at']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Actions</h2>
        <form method="post" action="/admin/dashboard.php" style="display:inline-block;margin-right:.5rem">
            <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
            <input type="hidden" name="action" value="rebuild">
            <button type="submit" class="btn btn--secondary">Rebuild entire site</button>
        </form>
        <p class="form-hint" style="margin-top:.75rem">
            To send outgoing webmentions run:<br>
            <code>php bin/send-webmentions.php</code>
        </p>
    </section>
</main>
<script src="/admin/assets/admin.js?v=<?= rawurlencode(CMS_VERSION) ?>"></script>
</body>
</html>
