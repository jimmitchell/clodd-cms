<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Helpers;

$siteTitle = $db->getSetting('site_title', 'My CMS');
$siteUrl   = rtrim($db->getSetting('site_url', ''), '/');

// Timezone for display and day-boundary grouping.
$tzName = $db->getSetting('timezone', '');
$tz     = ($tzName !== '' && @timezone_open($tzName)) ? new DateTimeZone($tzName) : new DateTimeZone('UTC');
// Compute UTC offset in seconds for SQLite's unixepoch modifier.
$tzOffsetSec = $tz->getOffset(new DateTime('now', $tz));
$tzOffsetStr = ($tzOffsetSec >= 0 ? '+' : '') . $tzOffsetSec . ' seconds';

// Date range: 7, 30, or 90 days (default 30).
$range = (int) ($_GET['range'] ?? 7);
if (!in_array($range, [7, 30, 90], true)) {
    $range = 7;
}
// Anchor $since to midnight of the oldest day in the user's timezone so the
// query window and the chart labels cover exactly the same set of days.
// Using (time() - range*86400) can reach one extra calendar day behind the
// chart's oldest label, causing that orphaned day to be appended at the end
// of the date map and appear as the "latest" entry in the chart.
$oldestDay = new DateTime('today midnight', $tz);
$oldestDay->modify('-' . ($range - 1) . ' days');
$since = $oldestDay->getTimestamp();

// ── Aggregate queries ──────────────────────────────────────────────────────

// Total page views in range (excluding 404s for the main count).
$totalViews = (int) ($db->selectOne(
    "SELECT COUNT(*) AS cnt FROM page_views WHERE timestamp >= :since AND is_404 = 0",
    ['since' => $since]
)['cnt'] ?? 0);

// Unique pages viewed.
$uniquePages = (int) ($db->selectOne(
    "SELECT COUNT(DISTINCT url) AS cnt FROM page_views WHERE timestamp >= :since AND is_404 = 0",
    ['since' => $since]
)['cnt'] ?? 0);

// 404 error count.
$total404 = (int) ($db->selectOne(
    "SELECT COUNT(*) AS cnt FROM page_views WHERE timestamp >= :since AND is_404 = 1",
    ['since' => $since]
)['cnt'] ?? 0);

// Daily views for line chart — fill every day in the range.
$dailyRows = $db->select(
    "SELECT date(timestamp, 'unixepoch', :tz) AS day, COUNT(*) AS views
       FROM page_views
      WHERE timestamp >= :since AND is_404 = 0
      GROUP BY day
      ORDER BY day ASC",
    ['since' => $since, 'tz' => $tzOffsetStr]
);
// Build a complete day-keyed map so the chart has no gaps.
$dailyMap = [];
for ($i = $range - 1; $i >= 0; $i--) {
    $d = new DateTime('now', $tz);
    $d->modify("-{$i} days");
    $dailyMap[$d->format('Y-m-d')] = 0;
}
foreach ($dailyRows as $row) {
    $dailyMap[$row['day']] = (int) $row['views'];
}
$chartLabels = array_keys($dailyMap);
$chartData   = array_values($dailyMap);

// Top 10 pages.
$topPages = $db->select(
    "SELECT url, COUNT(*) AS views
       FROM page_views
      WHERE timestamp >= :since AND is_404 = 0
      GROUP BY url
      ORDER BY views DESC
      LIMIT 10",
    ['since' => $since]
);

// Top 10 referrers (exclude empty/self-referrals).
$selfHost = parse_url($siteUrl, PHP_URL_HOST) ?? '';
// Escape LIKE wildcards so a site_url containing '%' or '_' can't skew the pattern.
$selfHostLike = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $selfHost) . '%';
$topReferrers = $db->select(
    "SELECT referrer, COUNT(*) AS views
       FROM page_views
      WHERE timestamp >= :since
        AND is_404 = 0
        AND referrer IS NOT NULL
        AND referrer != ''
        AND referrer NOT LIKE :self ESCAPE '\\'
      GROUP BY referrer
      ORDER BY views DESC
      LIMIT 10",
    ['since' => $since, 'self' => $selfHostLike]
);

// Device type split.
$deviceRows = $db->select(
    "SELECT device_type, COUNT(*) AS cnt
       FROM page_views
      WHERE timestamp >= :since AND is_404 = 0
      GROUP BY device_type",
    ['since' => $since]
);
$deviceMap = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0];
foreach ($deviceRows as $row) {
    $deviceMap[$row['device_type']] = (int) $row['cnt'];
}

// Recent 404 errors: group by URL, show count and last seen.
$recent404s = $db->select(
    "SELECT url, COUNT(*) AS cnt, MAX(timestamp) AS last_seen
       FROM page_views
      WHERE timestamp >= :since AND is_404 = 1
      GROUP BY url
      ORDER BY last_seen DESC
      LIMIT 20",
    ['since' => $since]
);

// ── JSON for Chart.js ──────────────────────────────────────────────────────
// JSON_HEX_TAG prevents </script> injection if a stored URL/referrer contains that sequence.
$jsonFlags      = JSON_HEX_TAG | JSON_HEX_AMP;
$jsonLabels     = json_encode($chartLabels,                                        $jsonFlags);
$jsonData       = json_encode($chartData,                                          $jsonFlags);
$jsonPageUrls   = json_encode(array_column($topPages,     'url'),                  $jsonFlags);
$jsonPageViews  = json_encode(array_map('intval', array_column($topPages, 'views')));
$jsonRefUrls    = json_encode(array_column($topReferrers, 'referrer'),             $jsonFlags);
$jsonRefViews   = json_encode(array_map('intval', array_column($topReferrers, 'views')));
$jsonDevLabels  = json_encode(['Desktop', 'Mobile', 'Tablet'],                     $jsonFlags);
$jsonDevData    = json_encode([$deviceMap['desktop'], $deviceMap['mobile'], $deviceMap['tablet']]);

// Self-exclusion link.
$excludeUrl = $siteUrl . '/?ti=exclude';

?>
<?php
$adminTitle = 'Analytics — ' . $siteTitle;
require __DIR__ . '/partials/head.php';
?>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1>Analytics</h1>
        <span class="page-header__meta">Last <?= (int) $range ?> days</span>
    </header>

    <section class="panel" style="margin-bottom:1rem">
        <p class="form-hint" style="margin:0">
            <strong>Exclude your own visits:</strong>
            Visit <a href="<?= Helpers::e($excludeUrl) ?>" target="_blank"><?= Helpers::e($excludeUrl) ?></a>
            once in each browser you use. Your visits will be silently skipped from that point on.
            To re-enable tracking, visit <code><?= Helpers::e($siteUrl) ?>/?ti=include</code>.
        </p>
    </section>

    <nav class="tab-nav" style="margin-bottom:1.5rem">
        <?php foreach ([7, 30, 90] as $r): ?>
        <a href="?range=<?= (int) $r ?>"
           class="btn <?= $range === $r ? 'btn--primary' : 'btn--secondary' ?>"
           style="margin-right:.25rem"><?= (int) $r ?>d</a>
        <?php endforeach; ?>
    </nav>

    <section class="stats-grid" style="margin-bottom:2rem">
        <div class="stat-card">
            <span class="stat-card__number"><?= number_format($totalViews) ?></span>
            <span class="stat-card__label">Page Views</span>
        </div>
        <div class="stat-card">
            <span class="stat-card__number"><?= number_format($uniquePages) ?></span>
            <span class="stat-card__label">Unique Pages</span>
        </div>
        <div class="stat-card">
            <span class="stat-card__number"><?= number_format($total404) ?></span>
            <span class="stat-card__label">404 Errors</span>
        </div>
    </section>

    <section class="panel" style="margin-bottom:2rem">
        <h2>Views per day</h2>
        <div style="position:relative;height:260px">
            <canvas id="chart-daily"></canvas>
        </div>
    </section>

    <div class="analytics-two-col" style="margin-bottom:2rem">
        <section class="panel">
            <h2>Top pages</h2>
            <?php if ($topPages): ?>
            <div style="position:relative;height:300px">
                <canvas id="chart-pages"></canvas>
            </div>
            <?php else: ?>
            <p class="form-hint">No data yet.</p>
            <?php endif; ?>
        </section>
        <section class="panel">
            <h2>Device types</h2>
            <?php if (array_sum($deviceMap) > 0): ?>
            <div style="position:relative;height:300px;display:flex;align-items:center;justify-content:center">
                <canvas id="chart-devices"></canvas>
            </div>
            <?php else: ?>
            <p class="form-hint">No data yet.</p>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($topReferrers): ?>
    <section class="panel" style="margin-bottom:2rem">
        <h2>Top referrers</h2>
        <div style="position:relative;height:<?= count($topReferrers) * 36 + 40 ?>px">
            <canvas id="chart-referrers"></canvas>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($recent404s): ?>
    <section class="panel" style="margin-bottom:2rem">
        <h2>Recent 404 errors</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>URL</th>
                    <th style="text-align:right">Hits</th>
                    <th style="text-align:right">Last seen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent404s as $row): ?>
                <tr>
                    <td><?= Helpers::e($row['url']) ?></td>
                    <td style="text-align:right"><?= (int) $row['cnt'] ?></td>
                    <td style="text-align:right"><?= Helpers::e((new DateTime('@' . (int) $row['last_seen']))->setTimezone($tz)->format('Y-m-d H:i')) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

</main>

<script src="/admin/assets/chart.min.js"></script>
<script>
(function () {
    var accent  = getComputedStyle(document.documentElement).getPropertyValue('--color-accent').trim() || '#3b82f6';
    var muted   = getComputedStyle(document.documentElement).getPropertyValue('--color-border').trim()  || '#e2e8f0';
    var textCol = getComputedStyle(document.documentElement).getPropertyValue('--color-text').trim()    || '#1e293b';

    Chart.defaults.font.family = 'inherit';
    Chart.defaults.color       = textCol;

    // ── Daily line chart ──────────────────────────────────────────────────
    var ctxDaily = document.getElementById('chart-daily');
    if (ctxDaily) {
        new Chart(ctxDaily, {
            type: 'line',
            data: {
                labels:   <?= $jsonLabels ?>,
                datasets: [{
                    label:           'Page views',
                    data:            <?= $jsonData ?>,
                    borderColor:     accent,
                    backgroundColor: accent + '22',
                    fill:            true,
                    tension:         0.3,
                    pointRadius:     3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: muted } },
                    y: { beginAtZero: true, grid: { color: muted }, ticks: { precision: 0 } }
                }
            }
        });
    }

    // ── Top pages horizontal bar ──────────────────────────────────────────
    var ctxPages = document.getElementById('chart-pages');
    if (ctxPages) {
        new Chart(ctxPages, {
            type: 'bar',
            data: {
                labels:   <?= $jsonPageUrls ?>,
                datasets: [{
                    label:           'Views',
                    data:            <?= $jsonPageViews ?>,
                    backgroundColor: accent + 'bb'
                }]
            },
            options: {
                indexAxis:           'y',
                responsive:          true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, grid: { color: muted }, ticks: { precision: 0 } },
                    y: { grid: { display: false } }
                }
            }
        });
    }

    // ── Device doughnut ───────────────────────────────────────────────────
    var ctxDev = document.getElementById('chart-devices');
    if (ctxDev) {
        new Chart(ctxDev, {
            type: 'doughnut',
            data: {
                labels:   <?= $jsonDevLabels ?>,
                datasets: [{
                    data:            <?= $jsonDevData ?>,
                    backgroundColor: [accent, accent + '88', accent + '44'],
                    borderWidth:     2
                }]
            },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    // ── Referrers horizontal bar ──────────────────────────────────────────
    var ctxRef = document.getElementById('chart-referrers');
    if (ctxRef) {
        new Chart(ctxRef, {
            type: 'bar',
            data: {
                labels:   <?= $jsonRefUrls ?>,
                datasets: [{
                    label:           'Visits',
                    data:            <?= $jsonRefViews ?>,
                    backgroundColor: accent + 'bb'
                }]
            },
            options: {
                indexAxis:           'y',
                responsive:          true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, grid: { color: muted }, ticks: { precision: 0 } },
                    y: { grid: { display: false } }
                }
            }
        });
    }
})();
</script>
<script src="/admin/assets/admin.js?v=<?= rawurlencode(CMS_VERSION) ?>"></script>
</body>
</html>
