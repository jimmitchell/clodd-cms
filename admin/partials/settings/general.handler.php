<?php
// POST handler + GET-side data prep for Settings → General.
// Included from admin/settings.php after auth check. Exits on POST success.

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    $fields = [
        'site_title'         => trim($_POST['site_title']         ?? ''),
        'author_name'        => trim($_POST['author_name']        ?? ''),
        'author_bio'         => trim($_POST['author_bio']         ?? ''),
        'home_intro'         => trim($_POST['home_intro']         ?? ''),
        'author_avatar_url'  => trim($_POST['author_avatar_url']  ?? ''),
        'site_description'   => trim($_POST['site_description']   ?? ''),
        'site_url'           => rtrim(trim($_POST['site_url'] ?? ''), '/'),
        'footer_text'        => trim($_POST['footer_text']        ?? ''),
        'timezone'           => trim($_POST['timezone']           ?? ''),
        'locale'             => trim($_POST['locale']             ?? ''),
        'posts_per_page'     => (string) max(1, (int) ($_POST['posts_per_page'] ?? 10)),
        'feed_post_count'    => (string) max(1, (int) ($_POST['feed_post_count'] ?? 20)),
        'mastodon_handle'    => trim($_POST['mastodon_handle']    ?? ''),
        'mastodon_instance'  => rtrim(trim($_POST['mastodon_instance'] ?? ''), '/'),
        'mastodon_token'     => trim($_POST['mastodon_token']     ?? ''),
        'bluesky_url'          => rtrim(trim($_POST['bluesky_url']          ?? ''), '/'),
        'bluesky_handle'       => trim($_POST['bluesky_handle']       ?? ''),
        'bluesky_app_password' => trim($_POST['bluesky_app_password'] ?? ''),
        'reply_email'          => trim($_POST['reply_email']          ?? ''),
        'github_url'           => rtrim(trim($_POST['github_url']           ?? ''), '/'),
        'tinylytics_code'        => trim($_POST['tinylytics_code']        ?? ''),
        'tinylytics_kudos_emoji' => trim($_POST['tinylytics_kudos_emoji'] ?? ''),
        'ga_measurement_id'    => trim($_POST['ga_measurement_id']    ?? ''),
        'webmention_domain'        => trim($_POST['webmention_domain']        ?? ''),
        'google_site_verification' => trim($_POST['google_site_verification'] ?? ''),
        'custom_css'               => $_POST['custom_css'] ?? '',
        'favicon_url'              => trim($_POST['favicon_url'] ?? ''),
    ];

    if ($fields['site_title'] === '') {
        $errors[] = 'Site title is required.';
    }

    if ($fields['site_url'] !== '' && !filter_var($fields['site_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Site URL must be a valid URL (e.g. https://example.com).';
    }

    if ($fields['author_avatar_url'] !== '' && !filter_var($fields['author_avatar_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Author avatar URL must be a valid URL.';
    }

    if ($fields['timezone'] !== '' && !in_array($fields['timezone'], timezone_identifiers_list(), true)) {
        $errors[] = 'Timezone must be a valid PHP timezone identifier (e.g. America/New_York).';
    }

    if ($fields['mastodon_handle'] !== '') {
        $stripped = ltrim($fields['mastodon_handle'], '@');
        if (substr_count($stripped, '@') !== 1 || str_starts_with($stripped, '@') || str_ends_with($stripped, '@')) {
            $errors[] = 'Mastodon handle must be in the form @username@instance.social.';
        }
    }

    if ($fields['reply_email'] !== '' && !filter_var($fields['reply_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Reply email must be a valid email address.';
    }

    if ($fields['mastodon_instance'] !== '') {
        $parsedMasto = parse_url($fields['mastodon_instance']);
        if (!$parsedMasto || ($parsedMasto['scheme'] ?? '') !== 'https' || empty($parsedMasto['host'])) {
            $errors[] = 'Mastodon instance URL must use https:// (e.g. https://mastodon.social).';
        } else {
            $resolvedIp = gethostbyname($parsedMasto['host']);
            if (filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                $errors[] = 'Mastodon instance URL must resolve to a public IP address.';
            }
        }
    }

    if (empty($errors)) {
        // Secret fields are left blank to keep the saved value — skip them when empty.
        $secretFields = ['mastodon_token', 'bluesky_app_password'];
        foreach ($fields as $key => $value) {
            if (in_array($key, $secretFields, true) && $value === '') {
                continue;
            }
            $db->upsertSetting($key, $value);
        }

        // Rebuilding posts, pages, shared resources, and taxonomy archives can take
        // many minutes — long enough that nginx's fastcgi_read_timeout would cut the
        // response off and the admin UI would appear locked. Send the redirect
        // immediately, then keep building after FastCGI hangs up. Completion is
        // recorded in the activity log.
        $auth->flash('Settings saved — site rebuild started in the background. Check the activity log to confirm completion.');
        header('Location: /admin/settings.php?tab=general');

        ignore_user_abort(true);
        set_time_limit(0);
        session_write_close();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Rebuild posts, pages + index/feeds/sitemap so settings changes (custom CSS,
        // site title, footer text, etc.) are reflected everywhere. Taxonomy archives
        // are rebuilt too so byline-related changes propagate into per-term feeds.
        $builder->rebuildPosts();
        $builder->rebuildPages();
        $builder->rebuildSharedResources();
        $builder->buildAllTaxonomyArchives();

        $activityLog->log('settings', 'settings');
        exit;
    }
}

$settings = $db->getAllSettings();
$csrf     = $auth->csrfToken();
