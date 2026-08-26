<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Helpers;

$errors  = [];
$editing = null; // category row being edited (from ?edit=id)

// ── Handle POST ───────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name        = trim($_POST['name'] ?? '');
        $slug        = trim($_POST['slug'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $editId      = (int) ($_POST['edit_id'] ?? 0);

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
                "SELECT id FROM categories WHERE slug = :slug AND id != :id",
                [':slug' => $slug, ':id' => $editId]
            );
            if ($existing) {
                $errors[] = 'That slug is already used by another category.';
            }
        }

        if (empty($errors)) {
            if ($action === 'add') {
                $db->insert('categories', [
                    'name'        => $name,
                    'slug'        => $slug,
                    'description' => $description,
                ]);
                $auth->flash('Category added.');
            } else {
                $db->update(
                    'categories',
                    ['name' => $name, 'slug' => $slug, 'description' => $description],
                    'id = :id',
                    [':id' => $editId]
                );
                $auth->flash('Category updated.');
            }
            header('Location: /admin/categories.php');
            exit;
        }

        // Restore editing state so the form stays open with errors.
        if ($action === 'edit') {
            $editing = [
                'id'          => $editId,
                'name'        => $name,
                'slug'        => $slug,
                'description' => $description,
            ];
        }
    }

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['delete_id'] ?? 0);
        if ($deleteId > 0) {
            // Junction rows deleted automatically via ON DELETE CASCADE.
            $db->delete('categories', 'id = :id', [':id' => $deleteId]);
            // Rebuild archives since this category's page is now gone.
            $builder->buildAllTaxonomyArchives();
            $auth->flash('Category deleted.', 'info');
        }
        header('Location: /admin/categories.php');
        exit;
    }
}

// ── GET: load editing state from query string ─────────────────────────────────

if ($editing === null && isset($_GET['edit'])) {
    $editing = $db->selectOne("SELECT * FROM categories WHERE id = :id", [':id' => (int) $_GET['edit']]);
}

// ── Load data ─────────────────────────────────────────────────────────────────

$categories = $db->select(
    "SELECT c.id, c.name, c.slug, c.description, c.created_at,
            COUNT(pc.post_id) AS post_count
       FROM categories c
  LEFT JOIN post_categories pc ON pc.category_id = c.id
   GROUP BY c.id
   ORDER BY c.name"
);

$flash     = $auth->getFlash();
$flashMsg  = $flash['message'] ?? '';
$flashType = $flash['type']    ?? 'success';
$csrf      = $auth->csrfToken();
$siteTitle = $db->getSetting('site_title', 'My CMS');

?>
<?php
$adminTitle = 'Categories — ' . $siteTitle;
require __DIR__ . '/partials/head.php';
?>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1>Categories</h1>
    </header>

    <?php foreach ($errors as $e): ?>
        <p class="alert alert--error"><?= Helpers::e($e) ?></p>
    <?php endforeach; ?>

    <?php if ($flashMsg !== ''): ?>
        <p class="alert alert--<?= Helpers::e($flashType) ?>"><?= Helpers::e($flashMsg) ?></p>
    <?php endif; ?>

    <!-- Add / Edit form -->
    <div class="panel" style="margin-bottom:1.5rem">
        <h2><?= $editing ? 'Edit Category' : 'Add New Category' ?></h2>
        <form method="post" action="/admin/categories.php">
            <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
            <input type="hidden" name="action"     value="<?= $editing ? 'edit' : 'add' ?>">
            <?php if ($editing): ?>
            <input type="hidden" name="edit_id" value="<?= (int) $editing['id'] ?>">
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem">
                <div>
                    <label for="cat-name">Name</label>
                    <input type="text" id="cat-name" name="name"
                           value="<?= Helpers::e($editing['name'] ?? '') ?>"
                           placeholder="e.g. PHP"
                           required
                           data-slug-source="cat-slug">
                </div>
                <div>
                    <label for="cat-slug">Slug</label>
                    <input type="text" id="cat-slug" name="slug"
                           value="<?= Helpers::e($editing['slug'] ?? '') ?>"
                           placeholder="auto-generated">
                    <p class="form-hint">URL: /category/{slug}/</p>
                </div>
            </div>
            <div style="margin-bottom:.75rem">
                <label for="cat-description">Description <span style="font-weight:400;color:var(--color-muted)">(optional)</span></label>
                <textarea id="cat-description" name="description"
                          style="min-height:60px"><?= Helpers::e($editing['description'] ?? '') ?></textarea>
            </div>
            <div style="display:flex;gap:.5rem">
                <button type="submit" class="btn"><?= $editing ? 'Update category' : 'Add category' ?></button>
                <?php if ($editing): ?>
                <a href="/admin/categories.php" class="btn btn--secondary">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Category list -->
    <?php if (empty($categories)): ?>
    <p style="color:var(--color-muted)">No categories yet.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Slug</th>
                <th>Description</th>
                <th style="text-align:right">Posts</th>
                <th style="text-align:right">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $cat): ?>
        <tr>
            <td><strong><?= Helpers::e($cat['name']) ?></strong></td>
            <td><code>/category/<?= Helpers::e($cat['slug']) ?>/</code></td>
            <td><?= Helpers::e($cat['description']) ?></td>
            <td style="text-align:right"><?= (int) $cat['post_count'] ?></td>
            <td>
                <div class="actions">
                    <a href="/admin/categories.php?edit=<?= (int) $cat['id'] ?>" class="btn btn--secondary btn--sm">Edit</a>
                    <form method="post" action="/admin/categories.php" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                        <input type="hidden" name="action"    value="delete">
                        <input type="hidden" name="delete_id" value="<?= (int) $cat['id'] ?>">
                        <button type="submit" class="btn btn--danger btn--sm"
                                onclick="return confirm(<?= json_encode('Delete category "' . $cat['name'] . '"? Posts will not be deleted.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)">
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
// Slug auto-gen for the category name field.
(function () {
    const nameEl = document.getElementById('cat-name');
    const slugEl = document.getElementById('cat-slug');
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
