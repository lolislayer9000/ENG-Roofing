<?php
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { die('Invalid employee account.'); }

$sql = "SELECT
            p.id, p.project_id, p.project_name, p.service_type, p.start_date,
            p.expected_end_date, p.progress, p.status,
            f.full_name AS foreman_name,
            COALESCE(
                (SELECT si.location FROM site_inspections si WHERE si.project_id = p.id ORDER BY si.id DESC LIMIT 1),
                (SELECT b.project_location FROM bookings b WHERE b.project_id = p.id ORDER BY b.id DESC LIMIT 1)
            ) AS project_address
        FROM project_employees pe
        INNER JOIN projects p ON p.id = pe.project_id
        LEFT JOIN users f ON f.id = p.foreman_id
        WHERE pe.employee_id = ?
        ORDER BY CASE p.status WHEN 'active' THEN 1 WHEN 'pending' THEN 2 WHEN 'completed' THEN 3 ELSE 4 END, p.start_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$userId]);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="top-bar">
    <div>
        <h2>My Projects</h2>
        <p>Projects currently assigned to you and their latest shared progress.</p>
    </div>
</div>

<?php if (!$projects): ?>
    <div class="section-card text-center py-5" style="color: var(--fr-muted);">
        <i class="bi bi-kanban" style="font-size: 32px; opacity: .4;"></i>
        <div style="margin-top: 10px; font-size: 13px;">You are not currently assigned to any projects.</div>
    </div>
<?php else: ?>
    <?php foreach ($projects as $project):
        $statusLabel = $project['status'] === 'completed' ? 'Complete' : ucfirst($project['status']);
        $progress = max(0, min(100, (int)$project['progress']));
    ?>
        <div class="section-card">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <h3 style="margin: 0 0 4px; font-size: 18px; font-weight: 700;"><?= htmlspecialchars($project['project_name']) ?></h3>
                    <div style="color: var(--fr-muted); font-size: 12px;"><?= htmlspecialchars($project['project_id']) ?></div>
                </div>
                <span class="status-badge status-<?= htmlspecialchars($project['status']) ?>"><?= htmlspecialchars($statusLabel) ?></span>
            </div>

            <div class="detail-grid mb-3">
                <div class="detail-item"><span class="label">Service Type</span><span class="value"><?= htmlspecialchars(ucfirst($project['service_type'])) ?></span></div>
                <div class="detail-item"><span class="label">Project Address</span><span class="value"><?= htmlspecialchars($project['project_address'] ?? '—') ?></span></div>
                <div class="detail-item"><span class="label">Foreman</span><span class="value"><?= htmlspecialchars($project['foreman_name'] ?? '—') ?></span></div>
                <div class="detail-item"><span class="label">Start Date</span><span class="value"><?= htmlspecialchars(date('M d, Y', strtotime($project['start_date']))) ?></span></div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong style="font-size: 13px;">Project Progress</strong>
                <strong style="color: var(--fr-red);"><?= $progress ?>%</strong>
            </div>
            <div class="big-progress mb-3"><div class="big-progress-fill" style="width: <?= $progress ?>%;"><?= $progress ?>%</div></div>

            <h4 class="fr-section-title" style="margin-top: 20px;">Tasks</h4>
            <?php $tasks = getProjectTasks($pdo, $project['id']); ?>
            <?php if (empty($tasks)): ?>
                <div style="color: var(--fr-muted); font-size: 13px;">No tasks defined.</div>
            <?php else: ?>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <?php foreach ($tasks as $task):
                        $isMine = false;
                        foreach ($task['assignees'] as $a) {
                            if ((int)$a['id'] === (int)$userId) { $isMine = true; break; }
                        }
                    ?>
                        <li style="padding: 8px 10px; border-bottom: 1px solid var(--fr-border-soft); display: flex; align-items: center; gap: 8px; font-size: 13px;
                                   <?= $isMine ? 'background: #eff6ff; border-left: 3px solid #1d4ed8; border-radius: 4px;' : '' ?>">
                            <i class="bi <?= $task['is_completed'] ? 'bi-check-circle-fill' : 'bi-circle' ?>"
                               style="color: <?= $task['is_completed'] ? '#10b981' : 'var(--fr-muted)' ?>; font-size: 15px; flex-shrink: 0;"></i>
                            <div style="flex: 1; min-width: 0;">
                                <div style="color: var(--fr-text-soft); <?= $isMine ? 'font-weight: 700;' : '' ?>">
                                    <?= htmlspecialchars($task['description']) ?>
                                </div>
                                <?php if (empty($task['assignees'])): ?>
                                    <div style="font-size: 11.5px; color: var(--fr-muted); margin-top: 2px; font-style: italic;">
                                        Unassigned
                                    </div>
                                <?php else: ?>
                                    <div style="font-size: 11.5px; color: var(--fr-muted); margin-top: 2px;">
                                        Assigned to
                                        <?= htmlspecialchars(implode(', ', array_column($task['assignees'], 'name'))) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($isMine): ?>
                                <span class="status-badge status-active" style="flex-shrink: 0;">Your Task</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
