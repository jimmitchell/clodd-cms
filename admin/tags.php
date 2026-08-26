<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Helpers;

$errors  = [];
$editing = null; // tag row being edited (from ?edit=id)

// ── Handle POST ───────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name   = trim($_POST['name'] ?? '');
        $slug   = trim($_POST['slug'] ?? '');
        $editId = (int) ($_POST['edit_id'] ?? 0);

        $slug = Helpers::slugify($slug !== '' ? $slug : $name);

        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($slug === '' || $slug === 'untitled') {
            $errors[] = 'A valid slug is required.';
        }

        // Slug uniqueness check (allow saving over itself on edit).
        if (empty($errors)) {
            $existing = $db->selectOne(
                "SELECT id FROM tags WHERE slug = :slug AND id != :id",
                [':slug' => $slug, ':id' => $editId]
            );
            if ($existing) {
                $errors[] = 'That slug is already used by another tag.';
            }
        }

        if (empty($errors)) {
            if ($action === 'add') {
                $db->insert('tags', ['name' => $name, 'slug' => $slug]);
                $auth->flash('Tag added.');
            } else {
                $db->update('tags', ['name' => $name, 'slug' => $slug], 'id = :id', [':id' => $editId]);
                $auth->flash('Tag updated.');
            }
            header('Location: /admin/tags.php');
            exit;
        }

        if ($action === 'edit') {
            $editing = ['id' => $editId, 'name' => $name, 'slug' => $slug];
        }
    }

    if ($action === 'bulk_add') {
        $raw   = trim($_POST['bulk_names'] ?? '');
        $names = array_filter(array_map('trim', explode(',', $raw)));
        $added = 0;
        foreach ($names as $tagName) {
            $tagSlug  = Helpers::slugify($tagName);
            if ($tagSlug === '' || $tagSlug === 'untitled') {
                continue;
            }
            $exists = $db->selectOne("SELECT id FROM tags WHERE slug = :slug", [':slug' => $tagSlug]);
            if (!$exists) {
                $db->insert('tags', ['name' => $tagName, 'slug' => $tagSlug]);
                $added++;
            }
        }
        $auth->flash($added > 0 ? "Added {$added} tag(s)." : 'No new tags added (duplicates skipped).');
        header('Location: /admin/tags.php');
        exit;
    }

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['delete_id'] ?? 0);
        if ($deleteId > 0) {
            $db->delete('tags', 'id = :id', [':id' => $deleteId]);
            $builder->buildAllTaxonomyArchives();
            $auth->flash('Tag deleted.', 'info');
        }
        header('Location: /admin/tags.php');
        exit;
    }
}

// ── GET: load editing state from query string ─────────────────────────────────

if ($editing === null && isset($_GET['edit'])) {
    $editing = $db->selectOne("SELECT * FROM tags WHERE id = :id", [':id' => (int) $_GET['edit']]);
}

// ── Load data ─────────────────────────────────────────────────────────────────

$tags = $db->select(
    "SELECT t.id, t.name, t.slug, t.created_at,
            COUNT(pt.post_id) AS post_count
       FROM tags t
  LEFT JOIN post_tags pt ON pt.tag_id = t.id
   GROUP BY t.id
   ORDER BY t.name"
);

$flash     = $auth->getFlash();
$flashMsg  = $flash['message'] ?? '';
$flashType = $flash['type']    ?? 'success';
$csrf      = $auth->csrfToken();
$siteTitle = $db->getSetting('site_title', 'My CMS');

?>
<?php
$adminTitle = 'Tags — ' . $siteTitle;
require __DIR__ . '/partials/head.php';
?>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1>Tags</h1>
    </header>

    <?php foreach ($errors as $e): ?>
        <p class="alert alert--error"><?= Helpers::e($e) ?></p>
    <?php endforeach; ?>

    <?php if ($flashMsg !== ''): ?>
        <p class="alert alert--<?= Helpers::e($flashType) ?>"><?= Helpers::e($flashMsg) ?></p>
    <?php endif; ?>

    <div class="tags-form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;align-items:start">

        <!-- Add / Edit single tag -->
        <div class="panel">
            <h2><?= $editing ? 'Edit Tag' : 'Add New Tag' ?></h2>
            <form method="post" action="/admin/tags.php">
                <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                <input type="hidden" name="action"     value="<?= $editing ? 'edit' : 'add' ?>">
                <?php if ($editing): ?>
                <input type="hidden" name="edit_id" value="<?= (int) $editing['id'] ?>">
                <?php endif; ?>

                <label for="tag-name">Name</label>
                <input type="text" id="tag-name" name="name"
                       value="<?= Helpers::e($editing['name'] ?? '') ?>"
                       placeholder="e.g. tutorial"
                       required>

                <label for="tag-slug">Slug</label>
                <input type="text" id="tag-slug" name="slug"
                       value="<?= Helpers::e($editing['slug'] ?? '') ?>"
                       placeholder="auto-generated">
                <p class="form-hint">URL: /tag/{slug}/</p>

                <div style="display:flex;gap:.5rem;margin-top:.75rem">
                    <button type="submit" class="btn"><?= $editing ? 'Update tag' : 'Add tag' ?></button>
                    <?php if ($editing): ?>
                    <a href="/admin/tags.php" class="btn btn--secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Bulk add -->
        <div class="panel panel--bulk-add">
            <h2>Bulk Add</h2>
            <form method="post" action="/admin/tags.php">
                <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                <input type="hidden" name="action"     value="bulk_add">
                <label for="bulk-names">Tag names (comma-separated)</label>
                <textarea id="bulk-names" name="bulk_names"
                          style="min-height:70px"
                          placeholder="php, tutorial, open-source"></textarea>
                <p class="form-hint">Existing tags are skipped automatically.</p>
                <button type="submit" class="btn" style="margin-top:.75rem">Add tags</button>
            </form>
        </div>
    </div>

    <!-- Tag list -->
    <?php if (empty($tags)): ?>
    <p style="color:var(--color-muted)">No tags yet.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Slug</th>
                <th style="text-align:right">Posts</th>
                <th style="text-align:right">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tags as $tag): ?>
        <tr>
            <td><strong><?= Helpers::e($tag['name']) ?></strong></td>
            <td><code>/tag/<?= Helpers::e($tag['slug']) ?>/</code></td>
            <td style="text-align:right"><?= (int) $tag['post_count'] ?></td>
            <td>
                <div class="actions">
                    <a href="/admin/tags.php?edit=<?= (int) $tag['id'] ?>" class="btn btn--secondary btn--sm">Edit</a>
                    <form method="post" action="/admin/tags.php" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                        <input type="hidden" name="action"    value="delete">
                        <input type="hidden" name="delete_id" value="<?= (int) $tag['id'] ?>">
                        <button type="submit" class="btn btn--danger btn--sm"
                                onclick="return confirm(<?= json_encode('Delete tag "' . $tag['name'] . '"? Posts will not be deleted.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)">
                            Delete
                        </button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

</main>

<script src="/admin/assets/admin.js?v=<?= rawurlencode(CMS_VERSION) ?>"></script>
<script>
// Slug auto-gen for the tag name field.
(function () {
    const nameEl = document.getElementById('tag-name');
    const slugEl = document.getElementById('tag-slug');
    if (!nameEl || !slugEl) return;
    let touched = slugEl.value !== '';
    slugEl.addEventListener('input', () => { touched = true; });
    nameEl.addEventListener('input', function () {
        if (touched) return;
        slugEl.value = this.value
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    });
})();
</script>

</body>
</html>
