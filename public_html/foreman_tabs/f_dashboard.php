<?php
// This file is included inside foreman.php.

$foreman_id = (int)($_SESSION['user_id'] ?? 0);

if ($foreman_id <= 0) {
    die('Invalid foreman account.');
}

$today = date('Y-m-d');


// -----------------------------------------------------------------------------
// Total assigned projects
// -----------------------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM projects
    WHERE foreman_id = ?
");

$stmt->execute([$foreman_id]);

$project_count = (int)$stmt->fetchColumn();


// -----------------------------------------------------------------------------
// Check whether this foreman currently has an ACTIVE project
// -----------------------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM projects
    WHERE foreman_id = ?
      AND status = 'active'
");

$stmt->execute([$foreman_id]);

$active_project_count = (int)$stmt->fetchColumn();


// -----------------------------------------------------------------------------
// Today's present count.
//
// Late employees are included because they are still present.
// When there are no active projects, the value is NULL so the dashboard
// displays a blank instead of 0.
// -----------------------------------------------------------------------------
$present_today = null;

if ($active_project_count > 0) {

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT a.employee_id)
        FROM attendance a

        INNER JOIN project_employees pe
            ON pe.employee_id = a.employee_id
           AND pe.project_id = a.project_id

        INNER JOIN projects p
            ON p.id = a.project_id
           AND p.foreman_id = ?
           AND p.status = 'active'

        WHERE a.attendance_date = ?
          AND a.status IN ('Present', 'Late')
    ");

    $stmt->execute([
        $foreman_id,
        $today
    ]);

    $present_today = (int)$stmt->fetchColumn();
}


// -----------------------------------------------------------------------------
// Today's attendance summary
// -----------------------------------------------------------------------------
$attendance_summary = [
    'Present' => 0,
    'Late' => 0,
    'Absent' => 0
];

if ($active_project_count > 0) {

    $stmt = $pdo->prepare("
        SELECT
            u.id AS employee_id,
            a.status

        FROM users u

        INNER JOIN project_employees pe
            ON pe.employee_id = u.id

        INNER JOIN projects p
            ON p.id = pe.project_id
           AND p.foreman_id = ?
           AND p.status = 'active'

        LEFT JOIN attendance a
            ON a.employee_id = u.id
           AND a.project_id = p.id
           AND a.attendance_date = ?

        WHERE u.role = 'employee'
          AND u.status = 'active'
          AND u.join_date <= ?

        GROUP BY
            u.id,
            a.status
    ");

    $stmt->execute([
        $foreman_id,
        $today,
        $today
    ]);

    $summary_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($summary_rows as $row) {

        if ($row['status'] === 'Present') {

            $attendance_summary['Present']++;

        } elseif ($row['status'] === 'Late') {

            $attendance_summary['Late']++;

        } else {

            $attendance_summary['Absent']++;

        }
    }
}


// -----------------------------------------------------------------------------
// Assigned projects
// -----------------------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.project_id,
        p.project_name,
        p.service_type,
        p.progress,
        p.status,

        (
            SELECT COUNT(*)
            FROM project_employees pe
            WHERE pe.project_id = p.id
        ) AS employee_count

    FROM projects p

    WHERE p.foreman_id = ?

    ORDER BY
        CASE p.status
            WHEN 'active' THEN 1
            WHEN 'pending' THEN 2
            WHEN 'completed' THEN 3
            WHEN 'cancelled' THEN 4
            ELSE 5
        END,

        p.start_date ASC,
        p.created_at DESC
");

$stmt->execute([$foreman_id]);

$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>


<!-- Welcome -->
<div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
    <div>
        <h2 class="fw-bold mb-1" style="font-size: 22px;">Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></h2>
        <p class="mb-0" style="color: var(--fr-muted); font-size: 13.5px;">
            Here's your current project and attendance overview.
        </p>
    </div>
    <div style="color: var(--fr-muted); font-size: 13px;">
        <?= date('l, F j, Y') ?>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="fr-stat">
            <div class="fr-stat-label">Assigned Projects</div>
            <div class="fr-stat-value"><?= $project_count ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="fr-stat">
            <div class="fr-stat-label">Present Today</div>
            <div class="fr-stat-value">
                <?php if ($active_project_count > 0): ?>
                    <?= $present_today ?>
                <?php else: ?>
                    <span style="color: var(--fr-muted); font-size: 20px;">—</span>
                <?php endif; ?>
            </div>
            <?php if ($active_project_count === 0): ?>
                <div class="fr-stat-note">No active project</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="fr-stat">
            <div class="fr-stat-label">Late Today</div>
            <div class="fr-stat-value"><?= $attendance_summary['Late'] ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="fr-stat">
            <div class="fr-stat-label">Absent Today</div>
            <div class="fr-stat-value"><?= $attendance_summary['Absent'] ?></div>
        </div>
    </div>
</div>

<!-- Today's Attendance -->
<div class="fr-card mb-4">
    <div class="fr-card-pad">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <h3 class="fr-section-title mb-1">Today's Attendance</h3>
                <p class="mb-0" style="color: var(--fr-muted); font-size: 13px;">
                    Attendance for employees assigned to your active projects.
                </p>
            </div>
            <a href="foreman.php?tab=attendance" class="btn btn-primary btn-sm">
                View Attendance
            </a>
        </div>

        <?php if ($active_project_count === 0): ?>
            <div class="text-center py-4" style="color: var(--fr-muted);">
                <i class="bi bi-inbox" style="font-size: 32px; opacity: .4;"></i>
                <p class="mt-2 mb-1 fw-semibold" style="color: var(--fr-text-soft);">No Active Project</p>
                <p class="mb-0" style="font-size: 13px;">There is currently no active project assigned to you.</p>
            </div>
        <?php else: ?>
            <div class="row g-2">
                <div class="col-4">
                    <div class="text-center p-3 rounded-3" style="background: #ecfdf5;">
                        <div class="fw-bold" style="font-size: 24px; color: #047857;"><?= $attendance_summary['Present'] ?></div>
                        <div style="font-size: 11.5px; color: #065f46; font-weight: 600; text-transform: uppercase; letter-spacing: .4px;">Present</div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="text-center p-3 rounded-3" style="background: #fffbeb;">
                        <div class="fw-bold" style="font-size: 24px; color: #b45309;"><?= $attendance_summary['Late'] ?></div>
                        <div style="font-size: 11.5px; color: #92400e; font-weight: 600; text-transform: uppercase; letter-spacing: .4px;">Late</div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="text-center p-3 rounded-3" style="background: #fef2f2;">
                        <div class="fw-bold" style="font-size: 24px; color: #b91c1c;"><?= $attendance_summary['Absent'] ?></div>
                        <div style="font-size: 11.5px; color: #991b1b; font-weight: 600; text-transform: uppercase; letter-spacing: .4px;">Absent</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Assigned Projects -->
<div class="fr-card">
    <div class="fr-card-pad">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <h3 class="fr-section-title mb-1">Assigned Projects</h3>
                <p class="mb-0" style="color: var(--fr-muted); font-size: 13px;">
                    Active projects are shown first.
                </p>
            </div>
            <a href="foreman.php?tab=projects" class="btn btn-outline-secondary btn-sm">
                View All Projects
            </a>
        </div>

        <?php if (empty($projects)): ?>
            <div class="text-center py-4" style="color: var(--fr-muted);">
                <i class="bi bi-kanban" style="font-size: 32px; opacity: .4;"></i>
                <p class="mt-2 mb-0 fw-semibold" style="color: var(--fr-text-soft);">No assigned projects</p>
            </div>
        <?php else: ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($projects as $project):
                    $status_key = $project['status'] === 'completed' ? 'completed'
                                : ($project['status'] === 'pending' ? 'pending'
                                : ($project['status'] === 'cancelled' ? 'cancelled' : 'active'));
                    $progress = max(0, min(100, (int)$project['progress']));
                ?>
                    <div class="p-3 rounded-3" style="border: 1px solid var(--fr-border);">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div class="min-w-0">
                                <div style="font-size: 11px; color: var(--fr-muted); font-weight: 600; letter-spacing: .3px;">
                                    <?= htmlspecialchars($project['project_id']) ?>
                                </div>
                                <div class="fw-bold" style="font-size: 15px; color: var(--fr-text);">
                                    <?= htmlspecialchars($project['project_name']) ?>
                                </div>
                            </div>
                            <span class="fr-badge <?= $status_key ?>">
                                <?= htmlspecialchars(ucfirst($project['status'])) ?>
                            </span>
                        </div>

                        <div class="row g-2 my-2" style="font-size: 12.5px;">
                            <div class="col-6">
                                <div style="color: var(--fr-muted); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .3px;">Service</div>
                                <div class="fw-semibold" style="color: var(--fr-text-soft);"><?= htmlspecialchars(ucfirst($project['service_type'])) ?></div>
                            </div>
                            <div class="col-6">
                                <div style="color: var(--fr-muted); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .3px;">Employees</div>
                                <div class="fw-semibold" style="color: var(--fr-text-soft);"><?= (int)$project['employee_count'] ?></div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-1" style="font-size: 12px;">
                            <span style="color: var(--fr-muted); font-weight: 600;">Progress</span>
                            <span class="fw-bold" style="color: var(--fr-text);"><?= $progress ?>%</span>
                        </div>
                        <div class="fr-progress">
                            <div style="width: <?= $progress ?>%;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>


