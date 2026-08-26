<?php

/**
 * The <head> every admin page shares.
 *
 * Twelve pages hand-rolled their own, which is why they drifted: Font Awesome
 * was linked from inside <body> by partials/nav.php *and* again in <head> by
 * three of them, and none of the twelve carried a cache-busting stamp while
 * nginx serves /admin/assets/ with `expires 7d`. An edit to admin.css could
 * take a week to reach the browser — the exact failure the public theme fixed
 * in 1.31.0 and the admin never did.
 *
 * Variables:
 *   $adminTitle    string   page title, raw — escaped here
 *   $adminExtraCss string[] optional vendored stylesheets under /admin/assets/
 *   $adminChrome   bool     set false on pages with no nav (the login screen),
 *                           which are the only ones that do not need the icons
 *
 * Only admin.css and admin.js are stamped. The vendored files never change
 * between releases, and Font Awesome alone is 31 KB of CSS plus a 98 KB woff2
 * that there is no reason to re-fetch every time the version ticks over.
 */

$_adminV = '?v=' . rawurlencode(CMS_VERSION);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= \CMS\Helpers::e($adminTitle ?? 'Admin') ?></title>
    <link rel="stylesheet" href="/admin/assets/admin.css<?= $_adminV ?>">
<?php if ($adminChrome ?? true): ?>
    <link rel="stylesheet" href="/admin/assets/font-awesome.min.css">
<?php endif; ?>
<?php foreach (($adminExtraCss ?? []) as $_adminCss): ?>
    <link rel="stylesheet" href="/admin/assets/<?= \CMS\Helpers::e($_adminCss) ?>">
<?php endforeach; ?>
</head>
