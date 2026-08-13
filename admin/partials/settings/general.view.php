<?php
// View partial for Settings → General. Variables come from general.handler.php.
use CMS\Helpers;
?>
<?php foreach ($errors as $e): ?>
    <p class="alert alert--error"><?= Helpers::e($e) ?></p>
<?php endforeach; ?>

<form method="post" action="/admin/settings.php?tab=general">
    <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">

    <div class="panel">
        <h2>Site identity</h2>

        <label for="site_title">Site title <span class="required">*</span></label>
        <input type="text" id="site_title" name="site_title"
               value="<?= Helpers::e($_POST['site_title'] ?? $settings['site_title'] ?? '') ?>"
               required>

        <label for="author_name">Author name</label>
        <input type="text" id="author_name" name="author_name"
               value="<?= Helpers::e($_POST['author_name'] ?? $settings['author_name'] ?? '') ?>"
               placeholder="Your real name">
        <p class="form-hint">Used in JSON-LD structured data and as the author in feeds. Leave blank to omit the author field.</p>

        <label for="author_bio">Author bio</label>
        <textarea id="author_bio" name="author_bio" rows="3"
                  placeholder="A short bio (≤ 280 characters recommended)"><?= Helpers::e($_POST['author_bio'] ?? $settings['author_bio'] ?? '') ?></textarea>
        <p class="form-hint">Exposed as <code>&lt;byline:context&gt;</code> in the RSS and Atom feeds (per the <a href="https://www.bylinespec.org/spec" target="_blank" rel="noopener">Byline spec</a>). 280 characters or fewer recommended.</p>

        <label for="home_intro">Home page intro</label>
        <textarea id="home_intro" name="home_intro" rows="3"
                  placeholder="Leave blank to reuse the author bio"><?= Helpers::e($_POST['home_intro'] ?? $settings['home_intro'] ?? '') ?></textarea>
        <p class="form-hint">A sentence or two introducing yourself, shown above the feed on page 1 of the home page and marked up as your representative <code>h-card</code>. Needs an author name to appear. Leave blank to reuse the author bio.</p>

        <label for="author_avatar_url">Author avatar URL</label>
        <?php $_avatarCurrent = $_POST['author_avatar_url'] ?? $settings['author_avatar_url'] ?? ''; ?>
        <input type="url" id="author_avatar_url" name="author_avatar_url"
               value="<?= Helpers::e($_avatarCurrent) ?>"
               placeholder="https://example.com/avatar.jpg"
               style="max-width:400px">
        <?php if ($_avatarCurrent !== ''): ?>
        <img src="<?= Helpers::e($_avatarCurrent) ?>" alt="Avatar preview"
             style="display:block;width:64px;height:64px;margin-top:.5rem;object-fit:cover;border:1px solid var(--color-border);border-radius:50%">
        <?php endif; ?>
        <p class="form-hint">Exposed as <code>&lt;byline:avatar&gt;</code> in feeds. Upload an image to the <a href="/admin/media.php" target="_blank">Media Library</a> and paste its URL.</p>

        <label for="site_description">Site description</label>
        <input type="text" id="site_description" name="site_description"
               value="<?= Helpers::e($_POST['site_description'] ?? $settings['site_description'] ?? '') ?>"
               placeholder="Shown in <meta name=description> and the Atom feed">

        <label for="site_url">Site URL</label>
        <input type="url" id="site_url" name="site_url"
               value="<?= Helpers::e($_POST['site_url'] ?? $settings['site_url'] ?? '') ?>"
               placeholder="https://example.com">
        <p class="form-hint">Used for canonical URLs, OG tags, and the Atom feed. No trailing slash.</p>

        <label for="footer_text">Footer text</label>
        <input type="text" id="footer_text" name="footer_text"
               value="<?= Helpers::e($_POST['footer_text'] ?? $settings['footer_text'] ?? '') ?>"
               placeholder="© 2026 My Site  (leave blank for default)">

        <label for="timezone">Timezone</label>
        <input type="text" id="timezone" name="timezone"
               value="<?= Helpers::e($_POST['timezone'] ?? $settings['timezone'] ?? '') ?>"
               placeholder="America/New_York"
               style="max-width:240px">
        <p class="form-hint">
            PHP timezone identifier used for post date display.
            Examples: <code>America/New_York</code>, <code>America/Los_Angeles</code>,
            <code>Europe/London</code>, <code>Asia/Tokyo</code>.
            Leave blank to use the server default (UTC).
            <a href="https://www.php.net/manual/en/timezones.php" target="_blank" rel="noopener">Full list</a>.
        </p>

        <label for="locale">Locale</label>
        <input type="text" id="locale" name="locale"
               value="<?= Helpers::e($_POST['locale'] ?? $settings['locale'] ?? '') ?>"
               placeholder="en_US"
               style="max-width:160px">
        <p class="form-hint">
            BCP 47 / ICU locale code used for date formatting.
            Examples: <code>en_US</code>, <code>en_GB</code>, <code>fr_FR</code>,
            <code>de_DE</code>, <code>ja_JP</code>.
            Leave blank to use the server default (English).
        </p>

        <label for="favicon_url">Favicon URL</label>
        <?php $_faviconCurrent = $_POST['favicon_url'] ?? $settings['favicon_url'] ?? ''; ?>
        <input type="text" id="favicon_url" name="favicon_url"
               value="<?= Helpers::e($_faviconCurrent) ?>"
               placeholder="/media/my-icon_abc123.png"
               style="max-width:400px">
        <?php if ($_faviconCurrent !== ''): ?>
        <img src="<?= Helpers::e($_faviconCurrent) ?>" alt="Favicon preview"
             style="display:block;width:32px;height:32px;margin-top:.5rem;object-fit:contain;border:1px solid var(--color-border);border-radius:4px">
        <?php endif; ?>
        <p class="form-hint">
            Upload a PNG to the <a href="/admin/media.php" target="_blank">Media Library</a>,
            copy its URL, and paste it here. Leave blank to use the default SVG favicon.
        </p>
    </div>

    <div class="panel">
        <h2>Content</h2>

        <label for="posts_per_page">Posts per page</label>
        <input type="number" id="posts_per_page" name="posts_per_page"
               value="<?= (int) ($_POST['posts_per_page'] ?? $settings['posts_per_page'] ?? 10) ?>"
               min="1" max="100" style="max-width:120px">
        <p class="form-hint">Number of posts shown on each listing page.</p>

        <label for="feed_post_count">Posts in feeds</label>
        <input type="number" id="feed_post_count" name="feed_post_count"
               value="<?= (int) ($_POST['feed_post_count'] ?? $settings['feed_post_count'] ?? 20) ?>"
               min="1" max="100" style="max-width:120px">
        <p class="form-hint">Number of posts included in each feed (<code>feed.xml</code>, <code>feed.rss</code>, <code>feed.json</code>).</p>
    </div>

    <div class="panel">
        <h2>Mastodon</h2>
        <p class="form-hint" style="margin-bottom:1rem">
            When both fields are set, new posts will be automatically tooted on first publish.
        </p>

        <label for="mastodon_handle">Your Mastodon handle</label>
        <input type="text" id="mastodon_handle" name="mastodon_handle"
               value="<?= Helpers::e($_POST['mastodon_handle'] ?? $settings['mastodon_handle'] ?? '') ?>"
               placeholder="@username@mastodon.social"
               style="max-width:320px">
        <p class="form-hint">
            Adds a <code>fediverse:creator</code> meta tag to every page and shows a Mastodon link in the footer.
            Format: <code>@username@instance.social</code>
        </p>

        <label for="mastodon_instance">Instance URL</label>
        <input type="url" id="mastodon_instance" name="mastodon_instance"
               value="<?= Helpers::e($_POST['mastodon_instance'] ?? $settings['mastodon_instance'] ?? '') ?>"
               placeholder="https://indieweb.social"
               style="max-width:320px">

        <label for="mastodon_token">Access token</label>
        <input type="password" id="mastodon_token" name="mastodon_token"
               value=""
               placeholder="<?= ($settings['mastodon_token'] ?? '') !== '' ? '(saved — leave blank to keep)' : 'Paste your token here' ?>"
               autocomplete="new-password"
               style="max-width:360px">
        <p class="form-hint">
            Create a token in your Mastodon account under
            Preferences → Development → New application.
            It needs both <code>write:statuses</code> and <code>write:media</code> —
            without the second, a photo post arrives with its words and no picture.
            Changing an existing application's scopes means regenerating its token.
        </p>
    </div>

    <div class="panel">
        <h2>Bluesky</h2>

        <label for="bluesky_url">Your Bluesky profile URL</label>
        <input type="url" id="bluesky_url" name="bluesky_url"
               value="<?= Helpers::e($_POST['bluesky_url'] ?? $settings['bluesky_url'] ?? '') ?>"
               placeholder="https://bsky.app/profile/username.bsky.social"
               style="max-width:400px">
        <p class="form-hint">
            When set, a Bluesky link is shown in the site footer.
            Example: <code>https://bsky.app/profile/username.bsky.social</code>
        </p>

        <label for="bluesky_handle">Handle</label>
        <input type="text" id="bluesky_handle" name="bluesky_handle"
               value="<?= Helpers::e($_POST['bluesky_handle'] ?? $settings['bluesky_handle'] ?? '') ?>"
               placeholder="username.bsky.social"
               style="max-width:280px">
        <p class="form-hint">
            Used for crossposting. Format: <code>username.bsky.social</code>
        </p>

        <label for="bluesky_app_password">App password</label>
        <input type="password" id="bluesky_app_password" name="bluesky_app_password"
               value=""
               placeholder="<?= ($settings['bluesky_app_password'] ?? '') !== '' ? '(saved — leave blank to keep)' : 'Paste your app password here' ?>"
               autocomplete="new-password"
               style="max-width:360px">
        <p class="form-hint">
            Create one in Bluesky under Settings → Privacy and Security → App Passwords.
            Never use your main password. When both Handle and App password are set,
            new posts will be automatically shared on first publish.
        </p>
    </div>

    <div class="panel">
        <h2>Pixelfed</h2>

        <label for="pixelfed_url">Your Pixelfed profile URL</label>
        <input type="url" id="pixelfed_url" name="pixelfed_url"
               value="<?= Helpers::e($_POST['pixelfed_url'] ?? $settings['pixelfed_url'] ?? '') ?>"
               placeholder="https://pixelfed.social/username"
               style="max-width:400px">
        <p class="form-hint">
            When set, a Pixelfed link is shown in the site footer.
        </p>

        <label for="pixelfed_instance">Instance URL</label>
        <input type="url" id="pixelfed_instance" name="pixelfed_instance"
               value="<?= Helpers::e($_POST['pixelfed_instance'] ?? $settings['pixelfed_instance'] ?? '') ?>"
               placeholder="https://pixelfed.social"
               style="max-width:320px">

        <label for="pixelfed_token">Access token</label>
        <input type="password" id="pixelfed_token" name="pixelfed_token"
               value=""
               placeholder="<?= ($settings['pixelfed_token'] ?? '') !== '' ? '(saved — leave blank to keep)' : 'Paste your token here' ?>"
               autocomplete="new-password"
               style="max-width:360px">
        <p class="form-hint">
            Run <code>php bin/pixelfed-token.php</code> to get one — it walks through
            registering the app and exchanging the code. The token needs the
            <code>write</code> scope, which covers both the caption and the pictures.
        </p>
        <p class="form-hint">
            <strong>Photo posts only.</strong> When both fields are set, a photo post
            goes to Pixelfed on first publish. Articles, asides, and replies do not:
            Pixelfed is a photo account, and its API rejects a post with no picture.
        </p>
    </div>

    <div class="panel">
        <h2>Email Reply</h2>

        <label for="reply_email">Reply-to email address</label>
        <input type="email" id="reply_email" name="reply_email"
               value="<?= Helpers::e($_POST['reply_email'] ?? $settings['reply_email'] ?? '') ?>"
               placeholder="you@example.com"
               style="max-width:320px">
        <p class="form-hint">
            When set, an <strong>Email</strong> pill appears at the bottom of each post,
            allowing readers to reply via email. The subject will be pre-filled with
            <em>Re: [post title]</em>.
        </p>
    </div>

    <div class="panel">
        <h2>GitHub</h2>

        <label for="github_url">Your GitHub profile URL</label>
        <input type="url" id="github_url" name="github_url"
               value="<?= Helpers::e($_POST['github_url'] ?? $settings['github_url'] ?? '') ?>"
               placeholder="https://github.com/username"
               style="max-width:400px">
        <p class="form-hint">
            When set, a GitHub link is shown in the site footer.
            Example: <code>https://github.com/username</code>
        </p>
    </div>

    <div class="panel">
        <h2>IndieWeb</h2>

        <label for="webmention_domain">Webmention.io domain</label>
        <input type="text" id="webmention_domain" name="webmention_domain"
               value="<?= Helpers::e($_POST['webmention_domain'] ?? $settings['webmention_domain'] ?? '') ?>"
               placeholder="example.com"
               style="max-width:280px">
        <p class="form-hint">
            Your domain as registered on <a href="https://webmention.io" target="_blank" rel="noopener">webmention.io</a>
            (e.g. <code>example.com</code>). When set, webmention endpoint link tags are added to every page
            and incoming webmentions are fetched and displayed below each post.
            Leave blank to disable.
        </p>
    </div>

    <div class="panel">
        <h2>Analytics</h2>

        <label for="tinylytics_code">Tinylytics site ID</label>
        <input type="text" id="tinylytics_code" name="tinylytics_code"
               value="<?= Helpers::e($_POST['tinylytics_code'] ?? $settings['tinylytics_code'] ?? '') ?>"
               placeholder="MMxGnYf8Pf5h66K_eim4"
               style="max-width:280px">
        <p class="form-hint">
            Your Tinylytics site ID. When set, the tracking script is added to every page.
            Leave blank to disable tracking.
        </p>

        <label for="tinylytics_kudos_emoji">Tinylytics Kudos emoji</label>
        <input type="text" id="tinylytics_kudos_emoji" name="tinylytics_kudos_emoji"
               value="<?= Helpers::e($_POST['tinylytics_kudos_emoji'] ?? $settings['tinylytics_kudos_emoji'] ?? '') ?>"
               placeholder="👏"
               style="max-width:120px">
        <p class="form-hint">
            Emoji for the Kudos button shown below each post. When set, the tracking script
            is updated to enable Kudos and the button appears on post pages.
            Leave blank to disable.
        </p>

        <label for="ga_measurement_id">Google Analytics measurement ID</label>
        <input type="text" id="ga_measurement_id" name="ga_measurement_id"
               value="<?= Helpers::e($_POST['ga_measurement_id'] ?? $settings['ga_measurement_id'] ?? '') ?>"
               placeholder="G-XXXXXXXXXX"
               style="max-width:200px">
        <p class="form-hint">
            Your GA4 measurement ID (e.g. <code>G-XXXXXXXXXX</code>). When set, the Google Analytics
            tag is injected into every public page. Leave blank to disable.
        </p>

        <label for="google_site_verification">Google site verification</label>
        <input type="text" id="google_site_verification" name="google_site_verification"
               value="<?= Helpers::e($_POST['google_site_verification'] ?? $settings['google_site_verification'] ?? '') ?>"
               placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
               style="max-width:460px">
        <p class="form-hint">
            The content value from your Google Search Console verification meta tag
            (e.g. <code>xxxxxxx...</code>). When set, a <code>&lt;meta name="google-site-verification"&gt;</code>
            tag is added to the homepage only. Leave blank to disable.
        </p>
    </div>

    <div class="panel">
        <h2>Custom CSS</h2>

        <label for="custom_css">Additional styles</label>
        <textarea id="custom_css" name="custom_css" rows="10"
                  placeholder="/* Your custom CSS here */"
                  style="font-family:monospace;font-size:.85rem"><?= Helpers::e($_POST['custom_css'] ?? $settings['custom_css'] ?? '') ?></textarea>
        <p class="form-hint">Injected as a <code>&lt;style&gt;</code> tag on every public page. Leave blank to add nothing.</p>
    </div>

    <div style="display:flex; gap:.75rem; margin-top:1rem; margin-bottom:2rem">
        <button type="submit" class="btn">Save settings</button>
        <a href="/admin/dashboard.php" class="btn btn--secondary">Cancel</a>
    </div>
</form>
