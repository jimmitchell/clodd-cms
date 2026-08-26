<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Post;
use CMS\Helpers;

$post    = null;
$isNew   = true;
$errors  = [];
$flash   = '';

// Mastodon config — loaded once, used in POST handler and template.
$mastodonInstance = $db->getSetting('mastodon_instance');
$mastodonToken    = $db->getSetting('mastodon_token');
$hasMastodon      = $mastodonInstance !== '' && $mastodonToken !== '';

// Bluesky config — loaded once, used in POST handler and template.
$blueskyHandle      = $db->getSetting('bluesky_handle');
$blueskyAppPassword = $db->getSetting('bluesky_app_password');
$hasBluesky         = $blueskyHandle !== '' && $blueskyAppPassword !== '';

// Pixelfed config — loaded once, used in POST handler and template.
$pixelfedInstance = $db->getSetting('pixelfed_instance');
$pixelfedToken    = $db->getSetting('pixelfed_token');
$hasPixelfed      = $pixelfedInstance !== '' && $pixelfedToken !== '';

// Timezone — loaded once, used in POST handler and template.
$cfgTz = $db->getSetting('timezone', '');

// Load existing post if ?id= given.
if (isset($_GET['id'])) {
    $post = Post::findById($db, (int) $_GET['id']);
    if (!$post) {
        header('Location: /admin/posts.php');
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
        if ($post && $post->id) {
            $publisher->deletePost($post);
            $auth->flash('Post deleted.', 'info');
            header('Location: /admin/posts.php');
            exit;
        }
    }

    // Everything the pending write is about to destroy — the old date-path, the
    // old-position neighbours, the old terms, and the fields the index, feeds
    // and syndicated copies are derived from. Taken before the form is applied
    // and before saveTerms(), which are both load-bearing; PostPublisher's
    // docblock says why.
    $before       = $publisher->snapshot($post ?? new Post($db));
    $wasPublished = $before['wasPublished'];

    // Populate from form.
    if ($post === null) {
        $post = new Post($db);
    }

    $submittedKind   = (string) ($_POST['post_kind'] ?? 'standard');
    $post->post_kind = in_array($submittedKind, ['aside', 'photo'], true) ? $submittedKind : 'standard';
    $post->title     = trim($_POST['title']   ?? '');
    $post->slug      = trim($_POST['slug']    ?? '');
    $post->content   = $_POST['content'] ?? '';
    $post->excerpt   = trim($_POST['excerpt'] ?? '') ?: null;

    // The featured image comes from the media picker, so it is always a
    // site-relative /media/ path — but it reaches an href on the public page,
    // so it goes through the same scheme allowlist as a Micropub photo rather
    // than being trusted for arriving over a session. Post::save() clears both
    // columns when the post is a note, so the panel being hidden is enough.
    $post->featured_image_url = Post::normaliseImageUrl(
        (string) ($_POST['featured_image_url'] ?? ''),
        $db->getSetting('site_url', '')
    );
    $post->featured_image_alt = $post->featured_image_url !== null
        ? trim($_POST['featured_image_alt'] ?? '')
        : '';

    if ($post->slug !== '') {
        $post->slug = Helpers::slugify($post->slug);
        // Digit-only slugs belong to legacy asides, which used the bare post id.
        // A legacy aside re-saved here is submitting its *own* slug back, so guard
        // only when the slug isn't already this post's — otherwise opening one in
        // the editor and pressing Save would rewrite "234" to "234-post".
        if (ctype_digit($post->slug) && $post->slug !== $before['slug']) {
            $post->slug .= '-post';
        }
    } elseif ($post->isNote()) {
        // No slug typed: derive one from the opening words of the note. Auto-derived
        // slugs resolve collisions silently — the author didn't choose them, so an
        // error would be about a string they never typed. Empty means there was
        // nothing to slug from (photo-only note); finalized to the id after save().
        $derived = Post::slugFromContent($post->content);
        if ($derived !== '') {
            $post->slug = Post::resolveUniqueSlug($db, $derived, $post->id);
        }
    } else {
        $post->slug = Helpers::slugify($post->title);
        if (ctype_digit($post->slug)) {
            $post->slug .= '-post';
        }
    }

    // Validation.
    if ($post->title === '' && !$post->isNote()) {
        $errors[] = 'Title is required.';
    }
    // A note may end up with an empty slug — that's the id fallback. Every other
    // case needs a real one, including a typed slug that slugified to nothing.
    if ((!$post->isNote() && $post->slug === '') || $post->slug === 'untitled') {
        $errors[] = 'A valid slug is required.';
    }

    // A typed slug that collides is an error rather than a silent rename, so the
    // author sees what happened. Notes are included now that they carry real
    // slugs; only the empty id-fallback case skips the check.
    if ($post->slug !== '') {
        $existing = Post::findBySlug($db, $post->slug);
        if ($existing && $existing->id !== $post->id) {
            $errors[] = 'That slug is already used by another post.';
        }
    }

    if (empty($errors)) {
        // Parse the manual publish date if provided.
        // Interpret the input as local time in the configured timezone so it
        // round-trips correctly when displayed back in the list.
        $publishDateInput = trim($_POST['publish_date'] ?? '');
        $publishTs        = false;
        if ($publishDateInput !== '') {
            if ($cfgTz !== '') {
                $dtParsed  = \DateTime::createFromFormat('Y-m-d\TH:i', $publishDateInput, new \DateTimeZone($cfgTz));
                $publishTs = $dtParsed !== false ? $dtParsed->getTimestamp() : false;
            }
            if ($publishTs === false) {
                $publishTs = strtotime($publishDateInput);
            }
        }

        // Apply status logic.
        if ($action === 'publish') {
            $ts                 = ($publishTs !== false) ? $publishTs : time();
            $post->status       = $ts > time() ? 'scheduled' : 'published';
            $post->published_at = date('Y-m-d H:i:s', $ts);
        } elseif ($action === 'unpublish') {
            $post->status = 'draft';
        } elseif ($post->status === 'published' && $publishTs !== false) {
            // 'draft' save on a published post — allow reordering by updating published_at.
            $post->published_at = date('Y-m-d H:i:s', $publishTs);
        }

        if ($action === 'draft' && $isNew) {
            $post->status = 'draft';
        }

        // Persist the user's opt-out choices so checkboxes stay unchecked on re-edit.
        $post->mastodon_skip = empty($_POST['send_to_mastodon']) ? 1 : 0;
        $post->bluesky_skip  = empty($_POST['send_to_bluesky'])  ? 1 : 0;

        // The Pixelfed checkbox is only rendered for photo posts, so an absent
        // field on any other kind means "not offered", not "opted out" — read it
        // only when it was on the form, or changing a photo post to an article
        // and back would silently leave it skipped.
        if ($post->isPhoto()) {
            $post->pixelfed_skip = empty($_POST['send_to_pixelfed']) ? 1 : 0;
        }

        // Only toot on first publish (not when the result is 'scheduled').
        $isFirstPublish = $post->status === 'published'
            && $action === 'publish'
            && $post->tooted_at === null
            && $hasMastodon
            && $post->mastodon_skip === 0;

        // Only post to Bluesky on first publish (same idempotency pattern).
        $isFirstBluesky = $post->status === 'published'
            && $action === 'publish'
            && $post->bluesky_at === null
            && $hasBluesky
            && $post->bluesky_skip === 0;

        // Same again for Pixelfed, which additionally only ever takes photo
        // posts. Syndication::wantsPixelfed() is the authority on that; this is
        // only deciding whether to call it at all.
        $isFirstPixelfed = $post->status === 'published'
            && $action === 'publish'
            && $post->pixelfed_at === null
            && $hasPixelfed
            && $post->pixelfed_skip === 0
            && $post->isPhoto();

        $wasNew = $isNew || !$post->id;
        $post->save();

        // An aside with no words to slug from falls back to the autoincrement id.
        // Re-save once the id is known.
        if ($post->slug === '' && $post->id !== null) {
            $post->slug = (string) $post->id;
            $post->save();
        }

        // Save category and tag associations.
        $categoryIds = array_values(array_filter(array_map('intval', $_POST['category_ids'] ?? [])));

        $tagIds   = [];
        $tagNames = array_filter(array_map('trim', explode(',', $_POST['tags_csv'] ?? '')));
        foreach ($tagNames as $tagName) {
            $tagSlug = Helpers::slugify($tagName);
            $existing = $db->selectOne("SELECT id FROM tags WHERE slug = :slug", [':slug' => $tagSlug]);
            if ($existing) {
                $tagIds[] = (int) $existing['id'];
            } else {
                $tagIds[] = $db->insert('tags', ['name' => $tagName, 'slug' => $tagSlug]);
            }
        }

        // The terms this replaces are already in $before, captured before the
        // form was applied — PostPublisher rebuilds the archives the post is
        // leaving from that, so there is nothing to diff here.
        $post->saveTerms($categoryIds, $tagIds);

        // Update syndication URLs if the user edited them. Pointing the field at
        // a different status re-points the edits and deletes that follow it, so
        // the remote id is re-read from the URL rather than left pointing at
        // whichever status was there before.
        if (isset($_POST['mastodon_url'])) {
            $newMastodonUrl = trim($_POST['mastodon_url']) ?: null;
            if ($newMastodonUrl !== $post->mastodon_url) {
                $post->mastodon_url       = $newMastodonUrl;
                $post->mastodon_status_id = $newMastodonUrl !== null
                    ? Helpers::mastodonStatusId($newMastodonUrl)
                    : null;
                $db->update('posts', [
                    'mastodon_url'       => $post->mastodon_url,
                    'mastodon_status_id' => $post->mastodon_status_id,
                ], 'id = :id', ['id' => $post->id]);
            }
        }
        if (isset($_POST['bluesky_url'])) {
            $newBlueskyUrl = trim($_POST['bluesky_url']) ?: null;
            if ($newBlueskyUrl !== $post->bluesky_url) {
                $post->bluesky_url  = $newBlueskyUrl;
                $post->bluesky_rkey = $newBlueskyUrl !== null
                    ? Helpers::blueskyRkey($newBlueskyUrl)
                    : null;
                $db->update('posts', [
                    'bluesky_url'  => $post->bluesky_url,
                    'bluesky_rkey' => $post->bluesky_rkey,
                ], 'id = :id', ['id' => $post->id]);
            }
        }
        if (isset($_POST['pixelfed_url'])) {
            $newPixelfedUrl = trim($_POST['pixelfed_url']) ?: null;
            if ($newPixelfedUrl !== $post->pixelfed_url) {
                $post->pixelfed_url       = $newPixelfedUrl;
                $post->pixelfed_status_id = $newPixelfedUrl !== null
                    ? Helpers::pixelfedStatusId($newPixelfedUrl)
                    : null;
                $db->update('posts', [
                    'pixelfed_url'       => $post->pixelfed_url,
                    'pixelfed_status_id' => $post->pixelfed_status_id,
                ], 'id = :id', ['id' => $post->id]);
            }
        }

        // Everything the author needs to know is settled: the row is saved.
        // What follows — syndication and the static rebuild — is work the
        // browser has no reason to wait for, and waiting was expensive.
        //
        // A photo post's first publish is 7 to 17 HTTP round-trips with 10 to
        // 30 second timeouts each, plus a guaranteed five seconds of sleep()
        // while Mastodon processes the media. nginx gives this endpoint the
        // default 60s fastcgi_read_timeout, so a slow instance produced a 504
        // for the author with the post half-syndicated and the rebuild never
        // run. Sending the redirect first turns that into a background problem
        // instead of a lost save.
        $logAction = match (true) {
            $action === 'unpublish'                                => 'unpublish',
            $action === 'publish' && $post->status === 'scheduled' => 'schedule',
            $action === 'publish'                                   => 'publish',
            $wasNew                                                => 'create',
            default                                                => 'update',
        };
        $activityLog->log($logAction, 'post', $post->id, $post->title);

        $label = match (true) {
            $action === 'unpublish'                                        => 'Post unpublished.',
            $action === 'publish' && $post->status === 'scheduled'         => 'Post scheduled.',
            $action === 'publish'                                           => 'Post published.',
            $action === 'draft'   && $post->status === 'published'         => 'Post updated.',
            default                                                        => 'Draft saved.',
        };
        $auth->flash($label);

        header('Location: /admin/post-edit.php?id=' . $post->id);

        ignore_user_abort(true);
        set_time_limit(0);
        session_write_close();   // the flash must be stored before the flush
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Everything the save invalidated: the post, both neighbour positions,
        // the archives it joined and left, the index and all three feeds.
        $publisher->rebuildAfterSave($post, $before);

        // Then, and only then, the copies. PostPublisher::syndicateAfterBuild()
        // carries the reason the order is this way round.
        if ($isFirstPublish || $isFirstBluesky || $isFirstPixelfed) {
            $publisher->syndicateAfterBuild($post);
        }

        $publisher->resyndicateAfterBuild($post, $before);

        exit;
    }
}


$flash     = $auth->getFlash();
$flashMsg  = $flash['message'] ?? '';
$flashType = $flash['type']    ?? 'success';

// ── Defaults for new post ─────────────────────────────────────────────────────

if ($post === null) {
    $post = new Post($db);
}

// Load media for sidebar insert panel.
$mediaItems = $db->select(
    "SELECT id, filename, mime_type, original_name
       FROM media
      ORDER BY uploaded_at DESC
      LIMIT 50"
);

// Load all categories and tags for the sidebar panels.
$allCategories  = $db->select("SELECT id, name FROM categories ORDER BY name");
$allTags        = $db->select("SELECT id, name FROM tags ORDER BY name");
$selectedCatIds = array_map('intval', array_column($post->categories, 'id'));
$tagsCsv        = implode(', ', array_column($post->tags, 'name'));

$siteUrl   = $db->getSetting('site_url', '');
$siteTitle = $db->getSetting('site_title', 'My CMS');
$csrf      = $auth->csrfToken();

// Convert stored UTC publish date to local time for the datetime-local input.
$pubInputVal = '';
if ($post->published_at) {
    $dt = new \DateTime($post->published_at, new \DateTimeZone('UTC'));
    if ($cfgTz !== '') {
        $dt->setTimezone(new \DateTimeZone($cfgTz));
    }
    $pubInputVal = $dt->format('Y-m-d\TH:i');
}

?>
<?php
$adminTitle = ($isNew ? 'New Post' : $post->title) . ' — ' . $siteTitle;
$adminExtraCss = ['easymde.min.css'];
require __DIR__ . '/partials/head.php';
?>
<body class="admin-page" data-slug-type="post"<?= $post?->id ? ' data-slug-id="' . $post->id . '"' : '' ?>>

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1><?= $isNew ? 'New Post' : 'Edit Post' ?></h1>
        <?php if (!$isNew && $post->status === 'published'): ?>
        <a href="/<?= Helpers::e(Post::datePath($post->published_at, $post->slug, $cfgTz)) ?>/" target="_blank" class="btn btn--secondary">View post</a>
        <?php endif; ?>
    </header>

    <?php foreach ($errors as $e): ?>
        <p class="alert alert--error"><?= Helpers::e($e) ?></p>
    <?php endforeach; ?>

    <?php if ($flashMsg !== ''): ?>
        <p class="alert alert--<?= Helpers::e($flashType) ?>"><?= Helpers::e($flashMsg) ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/post-edit.php<?= $post->id ? '?id=' . $post->id : '' ?>" id="post-form">
        <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
        <input type="hidden" name="action"     value="draft" id="form-action">

        <div class="editor-layout">

            <!-- Left: main content -->
            <div class="editor-main">
                <label for="title">Title <span data-kind-only="aside photo" hidden style="font-weight:400;color:var(--color-muted)">(optional for notes)</span></label>
                <input type="text" id="title" name="title"
                       value="<?= Helpers::e($post->title) ?>"
                       placeholder="Post title"
                       data-slug-source>

                <label for="slug">Slug</label>
                <div style="display:flex;gap:.5rem;align-items:center">
                    <span style="color:var(--color-muted);font-size:.85rem;white-space:nowrap">/<?php
                        if ($post->published_at && $cfgTz !== '') {
                            $dt = new \DateTime($post->published_at, new \DateTimeZone('UTC'));
                            $dt->setTimezone(new \DateTimeZone($cfgTz));
                            echo $dt->format('Y/m/d');
                        } else {
                            echo date('Y/m/d', strtotime($post->published_at ?? 'now'));
                        }
                    ?>/</span>
                    <input type="text" id="slug" name="slug"
                           value="<?= Helpers::e($post->slug) ?>"
                           placeholder="auto-generated"
                           aria-describedby="slug-hint"
                           style="flex:1">
                </div>
                <p class="form-hint" id="slug-hint" data-kind-only="standard"<?= $post->isNote() ? ' hidden' : '' ?>>Leave blank to auto-generate from title. Only lowercase letters, numbers, and hyphens.</p>
                <p class="form-hint" data-kind-only="aside photo"<?= $post->isNote() ? '' : ' hidden' ?>>Leave blank to auto-generate from the first few words of the note.</p>

                <label for="content" style="margin-top:1.25rem">Content</label>
                <textarea id="content" name="content"><?= Helpers::e($post->content) ?></textarea>

                <p class="form-hint" data-kind-only="photo"<?= $post->isPhoto() ? '' : ' hidden' ?>>Put only the image or gallery here — the words go in the excerpt below.</p>

                <p class="form-hint" id="note-length-hint" data-kind-only="aside photo"<?= $post->isNote() ? '' : ' hidden' ?>>
                    <span id="note-length-status"></span>
                </p>

                <label for="excerpt">Excerpt <span style="font-weight:400;color:var(--color-muted)">(optional)</span></label>
                <textarea id="excerpt" name="excerpt" style="min-height:80px"
                          aria-describedby="excerpt-hint"><?= Helpers::e($post->excerpt ?? '') ?></textarea>
                <p class="form-hint" id="excerpt-hint" data-kind-only="standard aside"<?= $post->isPhoto() ? ' hidden' : '' ?>>Shown on the post index. Leave blank to use the start of the post content.</p>
                <p class="form-hint" data-kind-only="photo"<?= $post->isPhoto() ? '' : ' hidden' ?>>The caption for this photo. Shown under the picture on the post page and in feeds, and syndicated to Mastodon and Bluesky. Not shown on the home page.</p>
            </div>

            <!-- Right: sidebar -->
            <div class="editor-sidebar">

                <!-- Publish controls -->
                <div class="panel">
                    <h2>Publish</h2>

                    <div style="margin-bottom:.75rem">
                        <span class="badge badge--<?= Helpers::e($post->status) ?>"><?= Helpers::e($post->status) ?></span>
                    </div>

                    <label for="post_kind" style="margin-top:0">Post kind</label>
                    <select id="post_kind" name="post_kind" aria-describedby="post-kind-hint">
                        <option value="standard"<?= $post->isNote() ? '' : ' selected' ?>>Standard</option>
                        <option value="aside"<?= $post->isAside() ? ' selected' : '' ?>>Aside (note)</option>
                        <option value="photo"<?= $post->isPhoto() ? ' selected' : '' ?>>Photo</option>
                    </select>
                    <p class="form-hint" id="post-kind-hint">Asides and photo posts are titleless notes shown in full on the home page. A photo post leads with its image.</p>

                    <?php if ($hasMastodon && $post->tooted_at === null): ?>
                    <?php $mastodonDisabled = $post->status === 'published'; ?>
                    <label for="send_to_mastodon" style="display:flex;gap:.5rem;align-items:center;font-size:.875rem;font-weight:400;margin-bottom:.75rem;<?= $mastodonDisabled ? 'opacity:.45;cursor:not-allowed' : '' ?>">
                        <input type="checkbox" id="send_to_mastodon" name="send_to_mastodon" value="1"
                               <?= $post->mastodon_skip === 0 ? 'checked' : '' ?>
                               <?= $mastodonDisabled ? 'disabled title="Post is already published — syndication only happens on first publish"' : '' ?>>
                        Post to Mastodon on publish
                    </label>
                    <?php if ($mastodonDisabled): ?>
                    <div style="margin-bottom:.75rem">
                        <label for="mastodon_url" style="font-size:.8rem;font-weight:400;color:var(--color-muted)">Toot URL</label>
                        <input type="url" id="mastodon_url" name="mastodon_url"
                               value="<?= Helpers::e($post->mastodon_url ?? '') ?>"
                               placeholder="https://mastodon.social/@user/123456"
                               style="font-size:.8rem;margin-top:.15rem">
                    </div>
                    <?php endif; ?>
                    <?php elseif ($hasMastodon && $post->tooted_at !== null): ?>
                    <div style="margin-bottom:.75rem">
                        <p class="form-hint" style="margin-bottom:.25rem">&#10003; Shared to Mastodon</p>
                        <p class="form-hint" style="margin-bottom:.5rem">Saving updates the toot. Unpublishing or deleting removes it.</p>
                        <label for="mastodon_url" style="font-size:.8rem;font-weight:400;color:var(--color-muted)">Toot URL</label>
                        <input type="url" id="mastodon_url" name="mastodon_url"
                               value="<?= Helpers::e($post->mastodon_url ?? '') ?>"
                               placeholder="https://mastodon.social/@user/123456"
                               style="font-size:.8rem;margin-top:.15rem">
                    </div>
                    <?php endif; ?>

                    <?php if ($hasBluesky && $post->bluesky_at === null): ?>
                    <?php $blueskyDisabled = $post->status === 'published'; ?>
                    <label for="send_to_bluesky" style="display:flex;gap:.5rem;align-items:center;font-size:.875rem;font-weight:400;margin-bottom:.75rem;<?= $blueskyDisabled ? 'opacity:.45;cursor:not-allowed' : '' ?>">
                        <input type="checkbox" id="send_to_bluesky" name="send_to_bluesky" value="1"
                               <?= $post->bluesky_skip === 0 ? 'checked' : '' ?>
                               <?= $blueskyDisabled ? 'disabled title="Post is already published — syndication only happens on first publish"' : '' ?>>
                        Post to Bluesky on publish
                    </label>
                    <?php if ($blueskyDisabled): ?>
                    <div style="margin-bottom:.75rem">
                        <label for="bluesky_url" style="font-size:.8rem;font-weight:400;color:var(--color-muted)">Bluesky post URL</label>
                        <input type="url" id="bluesky_url" name="bluesky_url"
                               value="<?= Helpers::e($post->bluesky_url ?? '') ?>"
                               placeholder="https://bsky.app/profile/user/post/abc123"
                               style="font-size:.8rem;margin-top:.15rem">
                    </div>
                    <?php endif; ?>
                    <?php elseif ($hasBluesky && $post->bluesky_at !== null): ?>
                    <div style="margin-bottom:.75rem">
                        <p class="form-hint" style="margin-bottom:.25rem">&#10003; Shared to Bluesky</p>
                        <p class="form-hint" style="margin-bottom:.5rem">Saving updates the Bluesky post. Unpublishing or deleting removes it.</p>
                        <?php if ($post->bluesky_stale === 1): ?>
                        <p class="form-hint form-hint--warn" style="margin-bottom:.5rem">
                            bsky.app is still showing the version before your last edit. The record in
                            your Bluesky repo is up to date &mdash; Bluesky does not re-index a post
                            once it has been edited, so readers there keep seeing the original.
                        </p>
                        <?php endif; ?>
                        <label for="bluesky_url" style="font-size:.8rem;font-weight:400;color:var(--color-muted)">Bluesky post URL</label>
                        <input type="url" id="bluesky_url" name="bluesky_url"
                               value="<?= Helpers::e($post->bluesky_url ?? '') ?>"
                               placeholder="https://bsky.app/profile/user/post/abc123"
                               style="font-size:.8rem;margin-top:.15rem">
                    </div>
                    <?php endif; ?>

                    <?php if ($hasPixelfed && $post->pixelfed_at === null): ?>
                    <?php $pixelfedDisabled = $post->status === 'published'; ?>
                    <!-- Photo posts only, so this follows the Post kind select above
                         rather than waiting for a save to reveal itself. -->
                    <div id="pixelfed-block" <?= $post->isPhoto() ? '' : 'hidden' ?>>
                        <label for="send_to_pixelfed" style="display:flex;gap:.5rem;align-items:center;font-size:.875rem;font-weight:400;margin-bottom:.75rem;<?= $pixelfedDisabled ? 'opacity:.45;cursor:not-allowed' : '' ?>">
                            <input type="checkbox" id="send_to_pixelfed" name="send_to_pixelfed" value="1"
                                   <?= $post->pixelfed_skip === 0 ? 'checked' : '' ?>
                                   <?= $pixelfedDisabled ? 'disabled title="Post is already published — syndication only happens on first publish"' : '' ?>>
                            Post to Pixelfed on publish
                        </label>
                        <?php if ($pixelfedDisabled): ?>
                        <div style="margin-bottom:.75rem">
                            <label for="pixelfed_url" style="font-size:.8rem;font-weight:400;color:var(--color-muted)">Pixelfed post URL</label>
                            <input type="url" id="pixelfed_url" name="pixelfed_url"
                                   value="<?= Helpers::e($post->pixelfed_url ?? '') ?>"
                                   placeholder="https://pixelfed.social/p/user/123456"
                                   style="font-size:.8rem;margin-top:.15rem">
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($hasPixelfed && $post->pixelfed_at !== null): ?>
                    <div style="margin-bottom:.75rem">
                        <p class="form-hint" style="margin-bottom:.25rem">&#10003; Shared to Pixelfed</p>
                        <p class="form-hint" style="margin-bottom:.5rem">Saving updates the Pixelfed post. Unpublishing or deleting removes it.</p>
                        <label for="pixelfed_url" style="font-size:.8rem;font-weight:400;color:var(--color-muted)">Pixelfed post URL</label>
                        <input type="url" id="pixelfed_url" name="pixelfed_url"
                               value="<?= Helpers::e($post->pixelfed_url ?? '') ?>"
                               placeholder="https://pixelfed.social/p/user/123456"
                               style="font-size:.8rem;margin-top:.15rem">
                    </div>
                    <?php endif; ?>

                    <label for="publish_date" style="margin-top:0">Publish date<?php if ($cfgTz !== ''): ?> <span style="font-weight:400;color:var(--color-muted)">(<?= Helpers::e($cfgTz) ?>)</span><?php endif; ?></label>
                    <input type="datetime-local" id="publish_date" name="publish_date"
                           value="<?= Helpers::e($pubInputVal) ?>"
                           aria-describedby="publish-date-hint">
                    <p class="form-hint" id="publish-date-hint">Leave blank to use the current time. A future date will schedule the post.</p>

                    <div style="display:flex;flex-direction:column;gap:.5rem;margin-top:.75rem">
                        <?php if ($post->status !== 'published'): ?>
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
                            Update post
                        </button>
                        <button type="submit" class="btn btn--secondary"
                                onclick="setAction('unpublish')">
                            Unpublish
                        </button>
                        <?php endif; ?>
                        <?php if (!$isNew): ?>
                        <a href="/admin/post-preview.php?id=<?= $post->id ?>" target="_blank" rel="noopener"
                           class="btn btn--secondary" style="text-align:center">
                            Preview
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Categories panel -->
                <?php if (!empty($allCategories)): ?>
                <div class="panel">
                    <h2>Categories</h2>
                    <ul class="term-checklist">
                        <?php foreach ($allCategories as $cat): ?>
                        <li>
                            <label>
                                <input type="checkbox" name="category_ids[]" value="<?= (int) $cat['id'] ?>"
                                       <?= in_array((int) $cat['id'], $selectedCatIds, true) ? 'checked' : '' ?>>
                                <?= Helpers::e($cat['name']) ?>
                            </label>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="/admin/categories.php" class="form-hint" style="display:block;margin-top:.5rem">Manage categories →</a>
                </div>
                <?php else: ?>
                <div class="panel">
                    <h2>Categories</h2>
                    <p class="form-hint">No categories yet. <a href="/admin/categories.php">Create some →</a></p>
                </div>
                <?php endif; ?>

                <!-- Tags panel -->
                <div class="panel">
                    <h2>Tags</h2>
                    <input type="text" name="tags_csv"
                           value="<?= Helpers::e($tagsCsv) ?>"
                           placeholder="Add a tag…">
                    <p class="form-hint">Press Enter or comma to add. Backspace removes the last tag. New tags are created automatically.</p>
                    <a href="/admin/tags.php" class="form-hint" style="display:block;margin-top:.25rem">Manage tags →</a>
                </div>

                <!-- Featured image panel -->
                <?php /* Titled posts only — a note leads with its own photos and
                         has no featured slot. Hidden rather than omitted so the
                         inputs stay in the form and a post flipped back to an
                         article keeps the picture it had. Post::save() clears
                         the columns for a note either way, so this is
                         presentation, not enforcement — the same arrangement the
                         Pixelfed checkbox uses. */ ?>
                <div class="panel" id="featured-block"<?= $post->isNote() ? ' hidden' : '' ?>>
                    <h2>Featured image</h2>
                    <input type="hidden" name="featured_image_url" id="featured_image_url"
                           value="<?= Helpers::e((string) $post->featured_image_url) ?>">

                    <div id="featured-preview"<?= ($post->featured_image_url ?? '') === '' ? ' hidden' : '' ?>>
                        <?php /* No src attribute at all when there is nothing to
                                 show — src="" re-requests the current page. */ ?>
                        <img id="featured-preview-img"
                             <?php $featuredPreview = Helpers::safeUrl((string) $post->featured_image_url); ?>
                             <?= $featuredPreview !== '' ? 'src="' . Helpers::e($featuredPreview) . '"' : '' ?>
                             alt=""
                             style="display:block;width:100%;height:auto;border-radius:var(--radius);margin-bottom:.5rem">
                        <label for="featured_image_alt">Alt text</label>
                        <input type="text" name="featured_image_alt" id="featured_image_alt"
                               value="<?= Helpers::e($post->featured_image_alt) ?>"
                               placeholder="Describe the picture…">
                        <p class="form-hint">Shown to anyone who cannot see the image, and read out by screen readers.</p>
                    </div>

                    <p class="form-hint" id="featured-empty"<?= ($post->featured_image_url ?? '') !== '' ? ' hidden' : '' ?>>
                        No featured image. It leads the post page and illustrates the post everywhere else — the home page card, the feeds, and the preview card on Mastodon and Bluesky.
                    </p>

                    <?php if (!empty($mediaItems)): ?>
                    <button type="button" id="featured-choose-btn" class="btn btn--secondary btn--sm"
                            style="margin-top:.5rem">Choose image</button>
                    <?php endif; ?>
                    <button type="button" id="featured-remove-btn" class="btn btn--secondary btn--sm"
                            style="margin-top:.5rem"<?= ($post->featured_image_url ?? '') === '' ? ' hidden' : '' ?>>Remove</button>
                </div>

                <!-- Media insert panel -->
                <?php if (!empty($mediaItems)): ?>
                <div class="panel">
                    <h2>Insert media</h2>
                    <p class="form-hint" style="margin-bottom:.5rem">Click to insert at cursor.</p>
                    <button type="button" id="gallery-select-btn" class="btn btn--secondary btn--sm"
                            style="margin-bottom:.5rem"
                            aria-label="Select multiple images to insert as a gallery">Select for gallery</button>
                    <p class="form-hint" id="gallery-hint" style="margin-bottom:.5rem">Select 2+ images, then click Insert gallery.</p>
                    <p class="form-hint" id="featured-pick-hint" style="margin-bottom:.5rem" hidden>Click an image to make it the featured image.</p>
                    <div class="media-grid" id="media-insert-grid">
                        <?php foreach ($mediaItems as $m): ?>
                        <?php
                            $url      = '/media/' . rawurlencode($m['filename']);
                            $isImage  = str_starts_with($m['mime_type'], 'image/');
                            $isVideo  = str_starts_with($m['mime_type'], 'video/');
                            $isAudio  = str_starts_with($m['mime_type'], 'audio/');
                        ?>
                        <button type="button" class="media-thumb"
                                data-id="<?= (int) $m['id'] ?>"
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
                    <button type="button" id="gallery-insert-btn" class="btn" style="display:none;width:100%;margin-top:.5rem">Insert gallery</button>
                    <a href="/admin/media.php" class="form-hint" style="display:block;margin-top:.5rem">Manage media →</a>
                </div>
                <?php endif; ?>

                <!-- Danger zone -->
                <?php if (!$isNew): ?>
                <div class="panel">
                    <h2>Danger</h2>
                    <button type="submit" class="btn btn--danger"
                            style="width:100%"
                            onclick="return confirm('Delete this post? This cannot be undone.') && setAction('delete')">
                        Delete post
                    </button>
                </div>
                <?php endif; ?>

            </div><!-- /editor-sidebar -->
        </div><!-- /editor-layout -->
    </form>
</main>

<script>
window._existingTags = <?= json_encode(array_values(array_map(fn($t) => ['name' => $t['name']], $allTags)), JSON_HEX_TAG | JSON_HEX_AMP) ?>;

// Pixelfed only takes photo posts, so its checkbox follows the kind select.
// The server decides too — an unchecked box on a post saved as an article is
// never read — so this is presentation, not enforcement.
// A featured image belongs to a titled post, so its panel follows the kind
// select the other way round. Same rule: the server clears the columns for a
// note regardless (Post::save()), so this is presentation, not enforcement.
(function () {
    var kind     = document.getElementById('post_kind');
    var pixelfed = document.getElementById('pixelfed-block');
    var featured = document.getElementById('featured-block');
    if (!kind) return;

    kind.addEventListener('change', function () {
        if (pixelfed) pixelfed.hidden = kind.value !== 'photo';
        if (featured) featured.hidden = kind.value !== 'standard';
    });
})();
</script>
<script src="/admin/assets/easymde.min.js"></script>
<script src="/admin/assets/admin.js?v=<?= rawurlencode(CMS_VERSION) ?>"></script>

</body>
</html>
