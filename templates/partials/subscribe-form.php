<?php
/**
 * Newsletter signup form. Posts to /subscribe.php, which adds the address to
 * the EmailOctopus list as pending; see that file for how it is protected.
 *
 * Variables: $subscribeReturn (string, a path on this site for the no-JS reply
 *            page to link back to).
 *
 * Rendered by templates/post.php at the foot of an article and by the
 * [subscribe] shortcode. The caller decides whether to render it at all —
 * EmailOctopus::isConfigured() — so a half-configured site shows no form.
 *
 * A real form with a native POST, like the webmention form: with JS off the
 * reader lands on subscribe.php's own reply page. theme.js upgrades it to an
 * inline reply.
 */

use CMS\Helpers;
?>
<form class="subscribe" id="subscribe" method="post" action="/subscribe.php">
    <input type="hidden" name="return" value="<?= Helpers::e($subscribeReturn ?? '/') ?>">
    <?php /* A <p> named by aria-labelledby rather than a <label>: it holds a
             link, and a click on a link inside a label also moves focus to the
             field, which reads as the page jumping. */ ?>
    <p class="subscribe__label" id="subscribe-label">Subscribe to get new articles in your inbox (or grab the <a href="/feed.rss">RSS feed</a>)</p>
    <div class="subscribe__row">
        <input class="subscribe__input" type="email" name="email" id="subscribe-email"
               aria-labelledby="subscribe-label"
               required placeholder="Enter your email…" autocomplete="email" spellcheck="false">
        <button class="subscribe__send" type="submit">Subscribe</button>
    </div>
    <?php /* The honeypot. Off-screen rather than display:none, which some bots
             know to skip; tabindex and autocomplete keep people and password
             managers out of it. */ ?>
    <div class="subscribe__hp" aria-hidden="true">
        <label for="subscribe-website">Leave this empty</label>
        <input type="text" name="website" id="subscribe-website" tabindex="-1" autocomplete="off">
    </div>
    <p class="subscribe__status" role="status" aria-live="polite" hidden></p>
</form>
