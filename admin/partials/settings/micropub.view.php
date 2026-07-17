<?php
// View partial for Settings → Micropub. Variables come from micropub.handler.php.
use CMS\Helpers;
?>
<?php foreach ($errors as $e): ?>
    <p class="alert alert--error"><?= Helpers::e($e) ?></p>
<?php endforeach; ?>

<?php if ($siteUrl === ''): ?>
<p class="alert alert--error">
    Your <strong>Site URL</strong> is not set. iA Writer (and most Micropub clients) need an absolute,
    reachable site root URL to discover the Micropub endpoint.
    <a href="/admin/settings.php?tab=general">Set it in General settings →</a>
</p>
<?php endif; ?>

<div class="panel">
    <h2>What to enter in your client</h2>

    <label for="site-root-url">Site root URL <span style="font-weight:400;color:var(--color-muted)">(this is what you paste into iA Writer)</span></label>
    <input type="text" id="site-root-url" value="<?= Helpers::e($siteUrl !== '' ? $siteUrl : '(set Site URL in Settings)') ?>" readonly
           style="max-width:520px;font-family:monospace" onclick="this.select()">
    <p class="form-hint">
        <strong>Important:</strong> enter the site root URL above — <em>not</em> the endpoint URL.
        iA Writer auto-discovers the endpoint by reading <code>&lt;link rel="micropub"&gt;</code> from
        this page's HTML. Pasting the endpoint URL directly will hang iA Writer.
    </p>

    <label for="endpoint-url" style="margin-top:1rem">Endpoint URL <span style="font-weight:400;color:var(--color-muted)">(for reference only — clients discover this automatically)</span></label>
    <input type="text" id="endpoint-url" value="<?= Helpers::e($endpoint) ?>" readonly
           style="max-width:520px;font-family:monospace;opacity:.7" onclick="this.select()">
</div>

<div class="panel">
    <h2>Authorized apps (IndieAuth)</h2>
    <p class="form-hint" style="margin-bottom:1rem">
        Micropub clients can sign in with your site URL — you approve each app on a consent
        screen and it receives its own token with only the permissions you grant.
    </p>
    <?php if (empty($indieauthTokens)): ?>
    <p class="form-hint">No apps have been authorized yet.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>App</th>
                <th>Scopes</th>
                <th>Authorized</th>
                <th>Last used</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($indieauthTokens as $t): ?>
            <tr>
                <td>
                    <?= Helpers::e($t['client_name'] !== '' ? $t['client_name'] : $t['client_id']) ?>
                    <?php if ($t['client_name'] !== ''): ?>
                    <br><span class="meta" style="font-size:.8rem;color:var(--color-muted)"><?= Helpers::e($t['client_id']) ?></span>
                    <?php endif; ?>
                </td>
                <td><code><?= Helpers::e($t['scope']) ?></code></td>
                <td class="meta"><?= Helpers::e(Helpers::formatDate($t['created_at'], 'M j, Y')) ?></td>
                <td class="meta"><?= $t['last_used_at'] !== null ? Helpers::e(Helpers::formatDate($t['last_used_at'], 'M j, Y g:i a')) : '—' ?></td>
                <td>
                    <form method="post" action="/admin/settings.php?tab=micropub"
                          onsubmit="return confirm('Revoke this app\'s access? It will need to sign in again.')">
                        <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                        <input type="hidden" name="action"     value="revoke_token">
                        <input type="hidden" name="token_id"   value="<?= (int) $t['id'] ?>">
                        <button type="submit" class="btn btn--sm btn--danger">Revoke</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>Endpoints</h2>
    <p class="form-hint" style="margin-bottom:1rem">
        For reference — clients discover these automatically from the
        <code>&lt;link&gt;</code> tags on your site.
    </p>
    <?php foreach ($indieauthEndpoints as $label => $url): ?>
    <label style="margin-top:.5rem"><?= Helpers::e($label) ?></label>
    <input type="text" value="<?= Helpers::e($url) ?>" readonly
           style="max-width:520px;font-family:monospace;opacity:.8" onclick="this.select()">
    <?php endforeach; ?>
</div>

<div class="panel">
    <h2>Access token (manual fallback)</h2>

    <?php if ($justIssued !== ''): ?>
        <label for="new-token">Your new token</label>
        <input type="text" id="new-token" value="<?= Helpers::e($justIssued) ?>" readonly
               style="max-width:520px;font-family:monospace" onclick="this.select()">
        <p class="form-hint">
            Copy this now — it will not be shown again. Paste it into your Micropub client
            wherever an <em>App Token</em> or <em>Password</em> is requested.
        </p>
    <?php elseif ($hasToken): ?>
        <p class="form-hint" style="margin-bottom:1rem">
            A Micropub token is configured. The actual token is hidden — generate a new one to
            replace it, or revoke it to disable Micropub publishing.
        </p>
    <?php else: ?>
        <p class="form-hint" style="margin-bottom:1rem">
            No manual token is set. Clients that support IndieAuth can sign in without one;
            generate a token only for clients that ask for an <em>App Token</em> directly
            (it grants every permission).
        </p>
    <?php endif; ?>

    <form method="post" action="/admin/settings.php?tab=micropub" style="display:flex;gap:.5rem;flex-wrap:wrap">
        <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
        <button type="submit" name="action" value="generate" class="btn">
            <?= $hasToken ? 'Replace token' : 'Generate token' ?>
        </button>
        <?php if ($hasToken): ?>
        <button type="submit" name="action" value="revoke" class="btn btn--danger"
                onclick="return confirm('Revoke the Micropub token? Existing clients will stop working until you give them a new one.')">
            Revoke token
        </button>
        <?php endif; ?>
    </form>
</div>

<div class="panel">
    <h2>How categories are mapped</h2>
    <p>
        Micropub clients send all taxonomy as flat <code>category</code> values. The endpoint
        looks up each value's slug and decides:
    </p>
    <ul>
        <li>If a <strong>category</strong> already exists with that slug → attach as a category.</li>
        <li>Otherwise → attach as a <strong>tag</strong> (creating the tag row if needed).</li>
    </ul>
    <p>
        Pre-create your categories in the
        <a href="/admin/categories.php">Categories admin</a> to have matching tags promoted automatically.
    </p>
</div>

<div class="panel">
    <h2>iA Writer setup</h2>
    <ol>
        <li>In iA Writer, open <strong>Preferences → Accounts → Add Account</strong> and choose
            <strong>Micropub</strong> (not Micro.blog — Micro.blog only talks to micro.blog itself).</li>
        <li>For the URL, enter the <strong>site root URL</strong>:
            <code><?= Helpers::e($siteUrl !== '' ? $siteUrl : '(set Site URL in Settings first)') ?></code></li>
        <li>iA Writer will offer to authorize via the browser <strong>or</strong> let you
            <strong>Enter Token Manually</strong>. Choose <em>Enter Token Manually</em>
            and paste the token shown above.</li>
        <li>Write a post in iA Writer, then <strong>Publish → your account</strong>.</li>
    </ol>
    <p class="form-hint">
        Photos embedded in the iA Writer document are uploaded to your media library
        and inlined at the top of the post body. Tags become categories or tags per
        the mapping rule above.
    </p>
    <p class="form-hint">
        <strong>iOS / iPadOS note:</strong> <code>localhost</code> won't work from iA Writer on
        iPhone or iPad — use your Mac's LAN IP (e.g. <code>http://192.168.1.42:8080</code>) and
        make sure both devices are on the same network.
    </p>
</div>
