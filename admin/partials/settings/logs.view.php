<?php
// View partial for Settings → Logs.
?>
<section class="stats-grid" style="margin-bottom:1.5rem">
    <div class="stat-card">
        <span class="stat-card__number"><?= (int) ($stats['total'] ?? 0) ?></span>
        <span class="stat-card__label">Attempts (24 h)</span>
    </div>
    <div class="stat-card">
        <span class="stat-card__number"><?= (int) ($stats['successes'] ?? 0) ?></span>
        <span class="stat-card__label">Successful (24 h)</span>
    </div>
    <div class="stat-card">
        <span class="stat-card__number"><?= (int) ($stats['failures'] ?? 0) ?></span>
        <span class="stat-card__label">Failed (24 h)</span>
    </div>
</section>

<section class="panel">
    <h2>Activity log (last 200)</h2>
    <?php if (empty($activity)): ?>
        <p class="form-hint">No activity recorded yet.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Date / Time</th>
                <th>Action</th>
                <th>Detail</th>
                <th style="text-align:right">IP Address</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($activity as $row): ?>
            <tr>
                <td><?= htmlspecialchars($fmtDate($row['created_at'])) ?></td>
                <td>
                    <?= htmlspecialchars($actionLabels[$row['action']] ?? ucfirst($row['action'])) ?>
                    <span class="meta" style="display:block;font-size:.75rem">
                        <?= htmlspecialchars($typeLabels[$row['object_type']] ?? $row['object_type']) ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($row['detail']) ?></td>
                <td style="text-align:right"><?= htmlspecialchars($row['ip']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Login attempts (last 200)</h2>
    <?php if (empty($attempts)): ?>
        <p class="form-hint">No login attempts recorded yet.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Date / Time</th>
                <th>IP Address</th>
                <th>Surface</th>
                <th style="text-align:right">Result</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($attempts as $row): ?>
            <tr>
                <td><?= htmlspecialchars($fmtDate($row['attempted_at'])) ?></td>
                <td><?= htmlspecialchars($row['ip']) ?></td>
                <td><?= htmlspecialchars($scopeLabels[$row['scope']] ?? (string) $row['scope']) ?></td>
                <td style="text-align:right">
                    <?php if ($row['success']): ?>
                        <span class="badge badge--success">Success</span>
                    <?php else: ?>
                        <span class="badge badge--error">Failed</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
