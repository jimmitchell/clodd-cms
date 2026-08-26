<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Page;
use CMS\Helpers;

$page   = null;
$isNew  = true;
$errors = [];
$flash  = '';

// Load existing page if ?id= given.
if (isset($_GET['id'])) {
    $page = Page::findById($db, (int) $_GET['id']);
    if (!$page) {
        header('Location: /admin/pages.php');
        exit;
    }
    $isNew = false;
}

// ── Handle POST ───────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    $action = $_POST['action'] ?? 'draft';

    // Handle delete before any save logic.
    if ($action === 'delete') {
        if ($page && $page->id) {
            if (Page::hasChildren($db, $page->id)) {
                $auth->flash('Cannot delete: this page has sub-pages. Reparent or delete them first.', 'error');
                header('Location: /admin/page-edit.php?id=' . $page->id);
                exit;
            }
            $activityLog->log('delete', 'page', $page->id, $page->title);
            $wasPublished = $page->status === 'published';
            $page->delete();
            $page->status = 'draft'; // so buildPage() removes the static file
            $builder->buildPage($page);
            if ($wasPublished) {
                $builder->buildIndex();
                $builder->buildSitemap();
                $builder->rebuildPages(); // refresh nav on remaining pages
            }
            $auth->flash('Page deleted.', 'info');
            header('Location: /admin/pages.php');
            exit;
        }
    }

    if ($page === null) {
        $page = new Page($db);
    }
    $wasNew = $isNew || !$page->id;

    // Snapshot nav-affecting fields before mutating $page so we can detect changes.
    $origStatus    = $wasNew ? null : $page->status;
    $origParent    = $wasNew ? null : $page->parent_id;
    $origNavOrder  = $wasNew ? null : $page->nav_order;
    $origTitle     = $wasNew ? null : $page->title;
    $origSlug      = $wasNew ? null : $page->slug;

    $page->title     = trim($_POST['title']     ?? '');
    $page->slug      = trim($_POST['slug']      ?? '');
    $page->content   = $_POST['content'] ?? '';
    $page->nav_order = (int) ($_POST['nav_order'] ?? 0);

    $rawParent = $_POST['parent_id'] ?? '';
    $page->parent_id = ($rawParent === '' || $rawParent === '0') ? null : (int) $rawParent;

    $page->slug = Helpers::slugify($page->slug !== '' ? $page->slug : $page->title);

    if ($page->title === '') {
        $errors[] = 'Title is required.';
    }
    if ($page->slug === '' || $page->slug === 'untitled') {
        $errors[] = 'A valid slug is required.';
    }

    $existing = Page::findBySlug($db, $page->slug);
    if ($existing && $existing->id !== $page->id) {
        $errors[] = 'That slug is already used by another page.';
    }

    if ($page->parent_id !== null) {
        if ($page->id !== null && $page->parent_id === $page->id) {
            $errors[] = 'A page cannot be its own parent.';
        } else {
            $proposedParent = Page::findById($db, $page->parent_id);
            if ($proposedParent === null) {
                $errors[] = 'Selected parent page does not exist.';
            } elseif ($proposedParent->parent_id !== null) {
                $errors[] = 'Selected parent is itself a sub-page (only one level of nesting is allowed).';
            } elseif ($page->id !== null && Page::hasChildren($db, $page->id)) {
                $errors[] = 'This page has sub-pages, so it cannot itself be assigned a parent.';
            }
        }
    }

    if (empty($errors)) {
        $wasPublished = $page->status === 'published';
        $page->status = match ($action) {
            'publish'   => 'published',
            'unpublish' => 'draft',
            default     => $page->status ?: 'draft',
        };

        $page->save();

        $navAffectingChange =
               $wasNew
            || $origStatus    !== $page->status
            || $origParent    !== $page->parent_id
            || $origNavOrder  !== $page->nav_order
            || $origTitle     !== $page->title
            || $origSlug      !== $page->slug;

        if ($navAffectingChange && ($page->status === 'published' || $wasPublished)) {
            $builder->rebuildPages(); // refreshes $navPages + clears all page hashes
        } else {
            $builder->buildPage($page); // content-only edit: cheap path
        }

        if ($page->status === 'published' || $wasPublished) {
            $builder->buildIndex();
            $builder->buildSitemap();
        }

        $logAction = match ($action) {
            'publish'   => 'publish',
            'unpublish' => 'unpublish',
            default     => $wasNew ? 'create' : 'update',
        };
        $activityLog->log($logAction, 'page', $page->id, $page->title);

        $label = match ($action) {
            'publish'   => 'Page published.',
            'unpublish' => 'Page unpublished.',
            default     => 'Draft saved.',
        };
        $auth->flash($label);
        header('Location: /admin/page-edit.php?id=' . $page->id);
        exit;
    }
}

$flash     = $auth->getFlash();
$flashMsg  = $flash['message'] ?? '';
$flashType = $flash['type']    ?? 'success';

if ($page === null) {
    $page = new Page($db);
}

// Media sidebar.
$mediaItems = $db->select(
    "SELECT id, filename, mime_type, original_name
       FROM media
      ORDER BY uploaded_at DESC
      LIMIT 50"
);

$siteTitle = $db->getSetting('site_title', 'My CMS');
$csrf      = $auth->csrfToken();

// Parent-page select options: only top-level published pages, never self.
// If this page already has children, parent must remain empty (no grandchildren).
$cantHaveParent = $page->id !== null && Page::hasChildren($db, $page->id);
$parentChoices  = [];
if (!$cantHaveParent) {
    foreach (Page::findAll($db, 'published') as $candidate) {
        if ($candidate->parent_id !== null) continue;
        if ($page->id !== null && $candidate->id === $page->id) continue;
        $parentChoices[] = $candidate;
    }
}

?>
<?php
$adminTitle = ($isNew ? 'New Page' : $page->title) . ' — ' . $siteTitle;
$adminExtraCss = ['easymde.min.css'];
require __DIR__ . '/partials/head.php';
?>
<body class="admin-page" data-slug-type="page"<?= $page?->id ? ' data-slug-id="' . $page->id . '"' : '' ?>>

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1><?= $isNew ? 'New Page' : 'Edit Page' ?></h1>
        <?php if (!$isNew && $page->status === 'published'): ?>
        <a href="/<?= Helpers::e($page->slug) ?>/" target="_blank" class="btn btn--secondary">View page</a>
        <?php endif; ?>
    </header>

    <?php foreach ($errors as $e): ?>
        <p class="alert alert--error"><?= Helpers::e($e) ?></p>
    <?php endforeach; ?>

    <?php if ($flashMsg !== ''): ?>
        <p class="alert alert--<?= Helpers::e($flashType) ?>"><?= Helpers::e($flashMsg) ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/page-edit.php<?= $page->id ? '?id=' . $page->id : '' ?>" id="post-form">
        <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
        <input type="hidden" name="action"     value="draft" id="form-action">

        <div class="editor-layout">

            <div class="editor-main">
                <label for="title">Title</label>
                <input type="text" id="title" name="title"
                       value="<?= Helpers::e($page->title) ?>"
                       placeholder="Page title"
                       required
                       data-slug-source>

                <label for="slug">Slug</label>
                <div style="display:flex;gap:.5rem;align-items:center">
                    <span style="color:var(--color-muted);font-size:.85rem;white-space:nowrap">/</span>
                    <input type="text" id="slug" name="slug"
                           value="<?= Helpers::e($page->slug) ?>"
                           placeholder="auto-generated"
                           style="flex:1">
                </div>
                <p class="form-hint">Leave blank to auto-generate from title.</p>

                <label for="content" style="margin-top:1.25rem">Content</label>
                <textarea id="content" name="content"><?= Helpers::e($page->content) ?></textarea>
            </div>

            <div class="editor-sidebar">

                <!-- Publish controls -->
                <div class="panel">
                    <h2>Publish</h2>

                    <div style="margin-bottom:.75rem">
                        <span class="badge badge--<?= Helpers::e($page->status) ?>"><?= Helpers::e($page->status) ?></span>
                    </div>

                    <div style="display:flex;flex-direction:column;gap:.5rem">
                        <?php if ($page->status !== 'published'): ?>
                        <button type="submit" class="btn"
                                onclick="setAction('publish')">
                            Publish
                        </button>
                        <button type="submit" class="btn btn--secondary"
                                onclick="setAction('draft')">
                            Save draft
                        </button>
                        <?php else: ?>
                        <button type="submit" class="btn" id="update-btn"
                                onclick="setAction('draft')" disabled>
                            Update page
                        </button>
                        <button type="submit" class="btn btn--secondary"
                                onclick="setAction('unpublish')">
                            Unpublish
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Nav order -->
                <div class="panel">
                    <h2>Navigation</h2>

                    <label for="parent_id">Parent page</label>
                    <select id="parent_id" name="parent_id"<?= $cantHaveParent ? ' disabled' : '' ?>>
                        <option value="">— None (top level) —</option>
                        <?php foreach ($parentChoices as $c): ?>
                        <option value="<?= $c->id ?>"<?= $page->parent_id === $c->id ? ' selected' : '' ?>>
                            <?= Helpers::e($c->title) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">
                        <?php if ($cantHaveParent): ?>
                        This page has sub-pages, so it must remain top-level.
                        <?php else: ?>
                        Optional. Sub-pages appear in a dropdown under their parent in site nav.
                        <?php endif; ?>
                    </p>

                    <label for="nav_order">Nav order</label>
                    <input type="number" id="nav_order" name="nav_order"
                           value="<?= $page->nav_order ?>" min="0" step="1">
                    <p class="form-hint">Lower numbers appear first in the header navigation.</p>
                </div>

                <!-- Media insert -->
                <?php if (!empty($mediaItems)): ?>
                <div class="panel">
                    <h2>Insert media</h2>
                    <p class="form-hint" style="margin-bottom:.75rem">Click to insert at cursor.</p>
                    <div class="media-grid" id="media-insert-grid">
                        <?php foreach ($mediaItems as $m): ?>
                        <?php
                            $url     = '/media/' . rawurlencode($m['filename']);
                            $isImage = str_starts_with($m['mime_type'], 'image/');
                            $isVideo = str_starts_with($m['mime_type'], 'video/');
                        ?>
                        <button type="button" class="media-thumb"
                                data-url="<?= Helpers::e($url) ?>"
                                data-type="<?= $isImage ? 'image' : ($isVideo ? 'video' : 'audio') ?>"
                                data-name="<?= Helpers::e($m['original_name']) ?>"
                                title="<?= Helpers::e($m['original_name']) ?>">
                            <?php if ($isImage): ?>
                                <img src="<?= Helpers::e($url) ?>" alt="<?= Helpers::e($m['original_name']) ?>">
                            <?php elseif ($isVideo): ?>
                                <span class="media-icon">▶</span>
                            <?php else: ?>
                                <span class="media-icon">♪</span>
                            <?php endif; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <a href="/admin/media.php" class="form-hint" style="display:block;margin-top:.5rem">Manage media →</a>
                </div>
                <?php endif; ?>

                <!-- Danger zone -->
                <?php if (!$isNew): ?>
                <div class="panel">
                    <h2>Danger</h2>
                    <button type="submit" class="btn btn--danger"
                            style="width:100%"
                            onclick="return confirm('Delete this page? This cannot be undone.') && setAction('delete')">
                        Delete page
                    </button>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </form>
</main>

<script src="/admin/assets/easymde.min.js"></script>
<script src="/admin/assets/admin.js?v=<?= rawurlencode(CMS_VERSION) ?>"></script>

</body>
</html>
