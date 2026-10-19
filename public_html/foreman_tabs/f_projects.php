<?php
// This file is included inside foreman.php.
// The logged-in foreman may only view/update projects assigned through projects.foreman_id.

$foreman_id = (int)($_SESSION['user_id'] ?? 0);

if ($foreman_id <= 0) {
    die('Invalid foreman account.');
}


// -----------------------------------------------------------------------------
// Read query parameters
// -----------------------------------------------------------------------------
$status_filter = $_GET['status_filter'] ?? 'all';
$allowed_status = ['all', 'pending', 'active', 'completed'];

if (!in_array($status_filter, $allowed_status, true)) {
    $status_filter = 'all';
}

$search = trim($_GET['search'] ?? '');
$view_id = isset($_GET['view']) ? (int)$_GET['view'] : 0;

// -----------------------------------------------------------------------------
// Handle POST actions with CSRF validation
// -----------------------------------------------------------------------------

// Add task
if (isset($_POST['add_task'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $project_id  = (int)$_POST['project_id'];
    $description = trim($_POST['task_description']);
    if (!empty($description)) {
        addProjectTask($pdo, $project_id, $description);
    }
    header('Location: foreman.php?tab=projects&view=' . $project_id);
    exit;
}

// Toggle task (now POST)
if (isset($_POST['toggle_task'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $task_id = (int)$_POST['task_id'];
    toggleProjectTask($pdo, $task_id);
    $stmt = $pdo->prepare("SELECT project_id FROM project_tasks WHERE id = ?");
    $stmt->execute([$task_id]);
    $project_id = $stmt->fetchColumn();
    header('Location: foreman.php?tab=projects&view=' . $project_id);
    exit;
}

// Delete task (now POST)
if (isset($_POST['delete_task'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $task_id = (int)$_POST['delete_task'];
    $stmt = $pdo->prepare("SELECT project_id FROM project_tasks WHERE id = ?");
    $stmt->execute([$task_id]);
    $project_id = $stmt->fetchColumn();
    deleteProjectTask($pdo, $task_id);
    header('Location: foreman.php?tab=projects&view=' . $project_id);
    exit;
}

// Add progress log entry
if (isset($_POST['add_progress_log'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $project_id = (int)$_POST['project_id'];
    $materials_used = trim($_POST['materials_used'] ?? '');
    $issues_encountered = trim($_POST['issues_encountered'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $stmt = $pdo->prepare("SELECT progress FROM projects WHERE id = ?");
    $stmt->execute([$project_id]);
    $currentProgress = $stmt->fetchColumn();
    $sql = "INSERT INTO project_progress_log (project_id, progress, work_completed, materials_used, issues_encountered, remarks)
            VALUES (?, ?, '', ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$project_id, $currentProgress, $materials_used, $issues_encountered, $remarks]);
    header('Location: foreman.php?tab=projects&view=' . $project_id);
    exit;
}

// -----------------------------------------------------------------------------
// Fetch assigned projects
// -----------------------------------------------------------------------------
$sql = "SELECT
            p.id,
            p.project_id,
            p.project_name,
            p.service_type,
            p.start_date,
            p.expected_end_date,
            p.progress,
            p.status,
            c.full_name AS client_name,
            (
                SELECT si.location
                FROM site_inspections si
                WHERE si.project_id = p.id
                ORDER BY si.id DESC
                LIMIT 1
            ) AS project_address
        FROM projects p
        LEFT JOIN users c ON p.client_id = c.id
        WHERE p.foreman_id = :foreman_id";

$params = [
    ':foreman_id' => $foreman_id
];

if ($status_filter !== 'all') {

    $sql .= " AND p.status = :status";

    $params[':status'] = $status_filter;
}

if ($search !== '') {

    $sql .= " AND (
        p.project_name LIKE :search
        OR p.project_id LIKE :search
    )";

    $params[':search'] = '%' . $search . '%';
}

$sql .= " ORDER BY
            CASE p.status
                WHEN 'active' THEN 1
                WHEN 'pending' THEN 2
                WHEN 'completed' THEN 3
                ELSE 4
            END,
            p.start_date ASC,
            p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);


// -----------------------------------------------------------------------------
// Fetch employee names assigned to the displayed projects
// -----------------------------------------------------------------------------
$projectEmployees = [];

if (!empty($projects)) {

    $project_ids = array_column($projects, 'id');

    $placeholders = implode(
        ',',
        array_fill(0, count($project_ids), '?')
    );

    $sql = "
        SELECT
            pe.project_id,
            u.id AS employee_id,
            u.full_name,
            u.employee_id AS employee_code

        FROM project_employees pe

        INNER JOIN users u
            ON u.id = pe.employee_id

        WHERE pe.project_id IN ($placeholders)

        ORDER BY
            u.full_name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($project_ids);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

        $projectEmployees[$row['project_id']][] = $row;
    }
}


// -----------------------------------------------------------------------------
// Fetch selected project details + progress updates
// -----------------------------------------------------------------------------
$detailProject = null;
$detailLogs = [];
$detailEmployees = [];
$detailMaterials = [];

if ($view_id > 0) {

    $stmt = $pdo->prepare("
        SELECT
            p.*,
            c.full_name AS client_name,
            (
                SELECT si.location
                FROM site_inspections si
                WHERE si.project_id = p.id
                ORDER BY si.id DESC
                LIMIT 1
            ) AS project_address

        FROM projects p

        LEFT JOIN users c
            ON p.client_id = c.id

        WHERE p.id = ?
          AND p.foreman_id = ?

        LIMIT 1
    ");

    $stmt->execute([
        $view_id,
        $foreman_id
    ]);

    $detailProject = $stmt->fetch(PDO::FETCH_ASSOC);


    if (!$detailProject) {

        $view_id = 0;

    } else {

        // -------------------------------------------------------------
        // Fetch progress logs
        // -------------------------------------------------------------
        $stmt = $pdo->prepare("
            SELECT *
            FROM project_progress_log
            WHERE project_id = ?
            ORDER BY created_at DESC
        ");

        $stmt->execute([
            $detailProject['id']
        ]);

        $detailLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);


        // -------------------------------------------------------------
        // Fetch employees assigned to selected project
        // -------------------------------------------------------------
        $stmt = $pdo->prepare("
            SELECT
                u.id AS employee_id,
                u.employee_id AS employee_code,
                u.full_name,
                u.email

            FROM project_employees pe

            INNER JOIN users u
                ON u.id = pe.employee_id

            WHERE pe.project_id = ?

            ORDER BY u.full_name ASC
        ");

        $stmt->execute([
            $detailProject['id']
        ]);

        $detailEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);


        // -------------------------------------------------------------
        // Fetch quotation materials for this project
        // -------------------------------------------------------------
        $stmt = $pdo->prepare("
            SELECT
                qm.item_product,
                qm.specification,
                qm.quantity,
                qm.unit
            FROM quotations q
            JOIN quotation_materials qm ON q.id = qm.quotation_id
            WHERE q.project_id = ?
            ORDER BY qm.id ASC
        ");

        $stmt->execute([
            $detailProject['id']
        ]);

        $detailMaterials = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}


// -----------------------------------------------------------------------------
// Build a daily progress series for the graph.
// -----------------------------------------------------------------------------
$dailyProgress = [];

if ($detailProject) {

    $start_date = $detailProject['start_date'];

    $today = date('Y-m-d');

    $end_date = ($start_date > $today)
        ? $start_date
        : $today;


    // Fetch progress logs in ascending order for chart construction.
    $stmt = $pdo->prepare("
        SELECT
            DATE(created_at) AS log_date,
            progress,
            created_at

        FROM project_progress_log

        WHERE project_id = ?

        ORDER BY
            created_at ASC,
            id ASC
    ");

    $stmt->execute([
        $detailProject['id']
    ]);

    $chartLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);


    $logsByDate = [];

    foreach ($chartLogs as $log) {

        // Latest log on each day wins.
        $logsByDate[$log['log_date']] =
            (int)$log['progress'];
    }


    $cursor = new DateTime($start_date);
    $end = new DateTime($end_date);

    $currentProgress = 0;


    // If there is a progress update before the formal start date, ignore it.
    while ($cursor <= $end) {

        $dateKey = $cursor->format('Y-m-d');


        if (array_key_exists($dateKey, $logsByDate)) {

            $currentProgress =
                $logsByDate[$dateKey];
        }


        // Always synchronize the final/current project percentage on the last point.
        if ($dateKey === $end_date) {

            $currentProgress =
                (int)$detailProject['progress'];
        }


        $dailyProgress[] = [

            'date' => $dateKey,

            'progress' => max(
                0,
                min(
                    100,
                    $currentProgress
                )
            )

        ];


        $cursor->modify('+1 day');
    }


    if (empty($dailyProgress)) {

        $dailyProgress[] = [

            'date' => $start_date,

            'progress' =>
                (int)$detailProject['progress']

        ];
    }
}


// -----------------------------------------------------------------------------
// Messages
// -----------------------------------------------------------------------------
$msg = '';
$msg_error = false;

if (isset($_GET['msg'])) {

    $messages = [

        'progress_updated' =>
            'Progress updated successfully. The change is now shared across the project records.',

        'error' =>
            'Unable to update the project progress.',

        'not_allowed' =>
            'You are not allowed to access that project.'
    ];


    $msg =
        $messages[$_GET['msg']]
        ?? '';


    $msg_error =
        in_array(
            $_GET['msg'],
            ['error', 'not_allowed'],
            true
        );
}


// -----------------------------------------------------------------------------
// SVG graph helper
// -----------------------------------------------------------------------------
function renderProgressChart(array $dailyProgress): string
{
    if (empty($dailyProgress)) {

        return '<div class="chart-empty">No progress data available.</div>';
    }


    $width = 1000;
    $height = 360;

    $left = 65;
    $right = 25;
    $top = 25;
    $bottom = 65;

    $chartWidth =
        $width - $left - $right;

    $chartHeight =
        $height - $top - $bottom;


    $count =
        count($dailyProgress);


    $xStep =
        $count > 1
            ? $chartWidth / ($count - 1)
            : 0;


    $points = [];


    foreach ($dailyProgress as $i => $item) {

        $x =
            $left +
            ($i * $xStep);

        $y =
            $top +
            $chartHeight -
            (($item['progress'] / 100) * $chartHeight);


        $points[] =
            sprintf(
                '%.2f,%.2f',
                $x,
                $y
            );
    }


    $polyline =
        implode(
            ' ',
            $points
        );


    ob_start();
    ?>

    <div class="chart-wrap">

        <svg
            class="chart-svg"
            viewBox="0 0 <?= $width ?> <?= $height ?>"
            role="img"
            aria-label="Project progress over time"
        >

            <rect
                x="0"
                y="0"
                width="<?= $width ?>"
                height="<?= $height ?>"
                fill="#22252a"
            ></rect>


            <?php foreach ([0, 25, 50, 75, 100] as $value):

                $y =
                    $top +
                    $chartHeight -
                    (($value / 100) * $chartHeight);

            ?>

                <line
                    x1="<?= $left ?>"
                    y1="<?= $y ?>"
                    x2="<?= $width - $right ?>"
                    y2="<?= $y ?>"
                    stroke="#3a3f44"
                    stroke-width="1"
                ></line>

                <text
                    x="<?= $left - 10 ?>"
                    y="<?= $y + 4 ?>"
                    text-anchor="end"
                    font-size="12"
                    fill="#9da2a8"
                >
                    <?= $value ?>%
                </text>

            <?php endforeach; ?>


            <line
                x1="<?= $left ?>"
                y1="<?= $top ?>"
                x2="<?= $left ?>"
                y2="<?= $height - $bottom ?>"
                stroke="#3a3f44"
                stroke-width="1"
            ></line>


            <line
                x1="<?= $left ?>"
                y1="<?= $height - $bottom ?>"
                x2="<?= $width - $right ?>"
                y2="<?= $height - $bottom ?>"
                stroke="#3a3f44"
                stroke-width="1"
            ></line>


            <?php if (count($dailyProgress) > 1): ?>

                <polyline
                    points="<?= htmlspecialchars($polyline, ENT_QUOTES) ?>"
                    fill="none"
                    stroke="#c00"
                    stroke-width="4"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                ></polyline>

            <?php endif; ?>


            <?php foreach ($dailyProgress as $i => $item):

                $x =
                    $left +
                    ($i * $xStep);

                $y =
                    $top +
                    $chartHeight -
                    (($item['progress'] / 100) * $chartHeight);


                $showLabel =
                    $count <= 12
                    || $i === 0
                    || $i === $count - 1
                    || $i % max(
                        1,
                        (int)ceil($count / 10)
                    ) === 0;


                if (!$showLabel) {
                    continue;
                }

            ?>

                <circle
                    cx="<?= $x ?>"
                    cy="<?= $y ?>"
                    r="4"
                    fill="#c00"
                ></circle>


                <text
                    x="<?= $x ?>"
                    y="<?= $height - 37 ?>"
                    text-anchor="middle"
                    font-size="11"
                    fill="#9da2a8"
                >
                    <?= htmlspecialchars(
                        date(
                            'M d',
                            strtotime($item['date'])
                        )
                    ) ?>
                </text>


                <text
                    x="<?= $x ?>"
                    y="<?= $y - 10 ?>"
                    text-anchor="middle"
                    font-size="11"
                    font-weight="bold"
                    fill="#eeeeee"
                >
                    <?= (int)$item['progress'] ?>%
                </text>

            <?php endforeach; ?>


            <text
                x="<?= $left ?>"
                y="<?= $height - 12 ?>"
                text-anchor="start"
                font-size="11"
                fill="#9da2a8"
            >
                Start:
                <?= htmlspecialchars(
                    date(
                        'M d, Y',
                        strtotime($dailyProgress[0]['date'])
                    )
                ) ?>
            </text>


            <text
                x="<?= $width - $right ?>"
                y="<?= $height - 12 ?>"
                text-anchor="end"
                font-size="11"
                fill="#9da2a8"
            >
                Latest:
                <?= htmlspecialchars(
                    date(
                        'M d, Y',
                        strtotime(
                            $dailyProgress[
                                count($dailyProgress) - 1
                            ]['date']
                        )
                    )
                ) ?>
            </text>

        </svg>

    </div>

    <?php

    return ob_get_clean();
}

?>

<?php if ($msg): ?>
    <div class="alert <?= $msg_error ? 'alert-danger' : 'alert-success' ?> d-flex align-items-center">
        <i class="bi <?= $msg_error ? 'bi-exclamation-triangle' : 'bi-check-circle' ?> me-2"></i>
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<?php if ($detailProject): ?>

<!-- ================= PROJECT DETAIL VIEW ================= -->
<a href="foreman.php?tab=projects" class="btn btn-outline-secondary btn-sm mb-3">
    <i class="bi bi-arrow-left me-1"></i>Back to Projects
</a>

<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
    <div>
        <h2 class="fw-bold mb-1" style="font-size: 22px;"><?= htmlspecialchars($detailProject['project_name']) ?></h2>
        <p class="mb-0" style="color: var(--fr-muted); font-size: 13.5px;">
            <?= htmlspecialchars($detailProject['project_id']) ?> · <?= htmlspecialchars($detailProject['client_name'] ?? 'Client') ?>
        </p>
    </div>
    <?php $st = $detailProject['status'] === 'completed' ? 'completed' : ($detailProject['status'] === 'pending' ? 'pending' : 'active'); ?>
    <span class="fr-badge <?= $st ?>"><?= htmlspecialchars(ucfirst($detailProject['status'])) ?></span>
</div>

<?php if ($detailProject['status'] === 'completed'): ?>
    <div class="alert alert-secondary d-flex align-items-center"
         style="background:#f3f4f6;border:1px solid #e5e7eb;color:#374151;">
        <i class="bi bi-lock me-2"></i>
        <div>
            <strong>This project is completed.</strong>
            All details below are view-only.
        </div>
    </div>
<?php endif; ?>

<!-- Progress -->
<div class="fr-card mb-4">
    <div class="fr-card-pad">
        <div class="d-flex justify-content-between mb-2">
            <span class="fw-semibold" style="font-size: 13px;">Project Progress</span>
            <span class="fw-bold" id="projectProgressPercent" style="color: var(--fr-red);"><?= (int)$detailProject['progress'] ?>%</span>
        </div>
        <div style="height: 12px; background: #eef0f3; border-radius: 99px; overflow: hidden;">
            <div id="projectProgressFill" style="height: 100%; background: var(--fr-red); border-radius: 99px; width: <?= (int)$detailProject['progress'] ?>%; transition: width .3s;"></div>
        </div>
    </div>
</div>

<!-- Info grid -->
<div class="row g-3 mb-4">
    <div class="col-md-4 col-6">
        <div class="fr-stat"><div class="fr-stat-label">Service Type</div>
            <div style="font-weight: 600; font-size: 14px;"><?= htmlspecialchars(ucfirst($detailProject['service_type'])) ?></div></div>
    </div>
    <div class="col-md-4 col-6">
        <div class="fr-stat"><div class="fr-stat-label">Address</div>
            <div style="font-weight: 600; font-size: 14px;"><?= htmlspecialchars($detailProject['project_address'] ?? '—') ?></div></div>
    </div>
    <div class="col-md-4 col-6">
        <div class="fr-stat"><div class="fr-stat-label">Start Date</div>
            <div style="font-weight: 600; font-size: 14px;"><?= htmlspecialchars(date('M d, Y', strtotime($detailProject['start_date']))) ?></div></div>
    </div>
    <div class="col-md-4 col-6">
        <div class="fr-stat"><div class="fr-stat-label">Expected End</div>
            <div style="font-weight: 600; font-size: 14px;"><?= !empty($detailProject['expected_end_date']) ? htmlspecialchars(date('M d, Y', strtotime($detailProject['expected_end_date']))) : '—' ?></div></div>
    </div>
</div>

<!-- Assigned Employees -->
<div class="fr-card mb-4">
    <div class="fr-card-pad">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="fr-section-title mb-0">Assigned Employees</h3>
            <span class="fr-badge followup"><?= count($detailEmployees) ?> member<?= count($detailEmployees) === 1 ? '' : 's' ?></span>
        </div>
        <?php if (empty($detailEmployees)): ?>
            <div class="text-center py-4" style="color: var(--fr-muted); font-size: 13px;">
                No employees assigned yet.
            </div>
        <?php else: ?>
            <div class="row g-2">
                <?php foreach ($detailEmployees as $employee): ?>
                    <div class="col-md-6">
                        <div class="d-flex align-items-center gap-3 p-3 rounded-3" style="background: var(--fr-bg);">
                            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                                 style="width: 38px; height: 38px; border-radius: 50%; background: var(--fr-red); color: #fff; font-weight: 700;">
                                <?= htmlspecialchars(strtoupper(substr($employee['full_name'], 0, 1))) ?>
                            </div>
                            <div class="min-w-0">
                                <div class="fw-semibold text-truncate"><?= htmlspecialchars($employee['full_name']) ?></div>
                                <?php if (!empty($employee['employee_code'])): ?>
                                    <div style="font-size: 11.5px; color: var(--fr-muted);"><?= htmlspecialchars($employee['employee_code']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Task Checklist -->
<div class="fr-card mb-4">
    <div class="fr-card-pad">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="fr-section-title mb-0">Task Checklist</h3>
            <span class="fr-badge followup" id="taskCountBadge">
                <?php
                $tasks = getProjectTasks($pdo, $detailProject['id']);
                $total = count($tasks); $done = 0;
                foreach ($tasks as $t) if ($t['is_completed']) $done++;
                $attachmentCounts = getTaskAttachmentCounts($pdo, array_column($tasks, 'id'));
                echo $done . ' / ' . $total . ' done';
                ?>
            </span>
        </div>
        <div class="mb-3">
            <div class="fr-progress">
                <div id="taskProgressFill" style="width: <?= ($total > 0) ? round(($done / $total) * 100) : 0 ?>%;"></div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="fr-table">
                <thead>
                    <tr>
                        <th>Task</th>
                        <th style="width: 240px;">Assigned To</th>
                        <th style="width: 130px;">Status</th>
                        <th style="width: 90px;">Photos</th>
                        <th style="width: 110px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="taskTableBody">
                    <?php if (empty($tasks)): ?>
                        <tr id="noTasksRow"><td colspan="5" class="text-center py-3" style="color: var(--fr-muted);">No tasks added yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($tasks as $task): ?>
                            <tr data-task-id="<?= (int)$task['id'] ?>">
                                <td><?= htmlspecialchars($task['description']) ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="task-assignees-text" data-for-task="<?= (int)$task['id'] ?>" style="font-size: 13px;">
                                            <?php if (empty($task['assignees'])): ?>
                                                <span style="color: var(--fr-muted); font-style: italic;">Unassigned</span>
                                            <?php else: ?>
                                                <?= htmlspecialchars(implode(', ', array_column($task['assignees'], 'name'))) ?>
                                            <?php endif; ?>
                                        </span>
                                        <?php if ($detailProject['status'] !== 'completed'): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-secondary task-assign-btn"
                                                    data-task-id="<?= (int)$task['id'] ?>"
                                                    data-task-desc="<?= htmlspecialchars($task['description']) ?>"
                                                    style="font-size: 11.5px; padding: 2px 8px;">
                                                Assign
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($detailProject['status'] === 'completed'): ?>
                                        <span class="btn btn-sm <?= $task['is_completed'] ? 'btn-primary' : 'btn-outline-secondary' ?>" style="cursor:default;">
                                            <?= $task['is_completed'] ? '<i class="bi bi-check2 me-1"></i>Done' : 'Pending' ?>
                                        </span>
                                    <?php else: ?>
                                        <form method="POST" action="foreman.php?tab=projects" class="task-toggle-form d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="toggle_task" value="<?= $task['id'] ?>">
                                            <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $task['is_completed'] ? 'btn-primary' : 'btn-outline-secondary' ?> task-toggle"
                                                    data-task-id="<?= (int)$task['id'] ?>" data-completed="<?= $task['is_completed'] ? '1' : '0' ?>">
                                                <?= $task['is_completed'] ? '<i class="bi bi-check2 me-1"></i>Done' : 'Pending' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary attachment-open-btn"
                                            data-task-id="<?= (int)$task['id'] ?>"
                                            data-task-desc="<?= htmlspecialchars($task['description']) ?>">
                                        <i class="bi bi-image"></i>
                                        <span class="attachment-count-badge ms-1"><?= (int)($attachmentCounts[(int)$task['id']] ?? 0) ?></span>
                                    </button>
                                </td>
                                <td>
                                    <?php if ($detailProject['status'] !== 'completed'): ?>
                                        <form method="POST" action="foreman.php?tab=projects" class="task-delete-form d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="delete_task" value="<?= $task['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger border-0 task-delete" data-task-id="<?= (int)$task['id'] ?>">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($detailProject['status'] !== 'completed'): ?>
            <form method="POST" action="foreman.php?tab=projects" id="addTaskForm" class="mt-3">
                <?= csrf_field() ?>
                <input type="hidden" name="project_id" value="<?= $detailProject['id'] ?>">
                <div class="d-flex gap-2 flex-wrap">
                    <input type="text" id="newTaskInput" name="task_description" class="form-control"
                           placeholder="Enter new task..." required style="min-width: 200px; flex: 1;">
                    <button type="submit" name="add_task" class="btn btn-primary text-nowrap">Add Task</button>
                </div>
                <div class="form-text mt-2">
                    New tasks start unassigned. Use <strong>Assign</strong> on the task row to pick employees.
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Assign Employees Modal (shared) -->
<div class="modal fade" id="taskAssignModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Assign Employees</h5>
                    <div class="task-assign-task-desc text-muted small"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($detailEmployees)): ?>
                    <div class="text-center text-muted py-4">
                        No employees are assigned to this project yet.
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($detailEmployees as $emp): ?>
                            <label class="d-flex align-items-center gap-2 p-2 rounded-2"
                                   style="cursor: pointer; background: var(--fr-bg); border: 1px solid var(--fr-border);">
                                <input type="checkbox"
                                       class="form-check-input m-0 task-assign-checkbox"
                                       value="<?= (int)$emp['employee_id'] ?>">
                                <span style="font-weight: 600;"><?= htmlspecialchars($emp['full_name']) ?></span>
                                <?php if (!empty($emp['employee_code'])): ?>
                                    <span style="color: var(--fr-muted); font-size: 12px;">
                                        (<?= htmlspecialchars($emp['employee_code']) ?>)
                                    </span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="taskAssignSaveBtn">
                    Save Assignments
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Quotation Materials -->
<div class="fr-card mb-4">
    <div class="fr-card-pad">
        <h3 class="fr-section-title">Quotation Materials</h3>
        <?php if (empty($detailMaterials)): ?>
            <div class="text-center py-4" style="color: var(--fr-muted); font-size: 13px;">No materials in the quotation yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="fr-table">
                    <thead>
                        <tr><th>Item / Product</th><th>Specification</th><th>Qty</th><th>Unit</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detailMaterials as $m): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($m['item_product']) ?></td>
                                <td style="color: var(--fr-muted);"><?= htmlspecialchars($m['specification'] ?: '—') ?></td>
                                <td class="fw-num"><?= number_format((float)$m['quantity'], 2) ?></td>
                                <td><?= htmlspecialchars($m['unit']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Progress Log History -->
<div class="fr-card mb-4">
    <div class="fr-card-pad">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="fr-section-title mb-0">Progress Log</h3>
            <span class="fr-badge followup">
                <?= count($detailLogs) ?> entr<?= count($detailLogs) === 1 ? 'y' : 'ies' ?>
            </span>
        </div>

        <?php if (empty($detailLogs)): ?>
            <div class="text-center py-4" style="color: var(--fr-muted); font-size: 13px;">
                No progress updates yet. Add one below to get started.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="fr-table">
                    <thead>
                        <tr>
                            <th style="width: 160px;">Date</th>
                            <th style="width: 90px;">Progress</th>
                            <th>Issues Encountered</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detailLogs as $log): ?>
                            <tr>
                                <td class="fw-num">
                                    <?= date('M d, Y g:i A', strtotime($log['created_at'])) ?>
                                </td>
                                <td>
                                    <strong><?= (int)$log['progress'] ?>%</strong>
                                </td>
                                <td style="word-break: break-word;">
                                    <?= !empty($log['issues_encountered'])
                                        ? nl2br(htmlspecialchars($log['issues_encountered']))
                                        : '<span class="muted">—</span>' ?>
                                </td>
                                <td style="word-break: break-word;">
                                    <?= !empty($log['remarks'])
                                        ? nl2br(htmlspecialchars($log['remarks']))
                                        : '<span class="muted">—</span>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Progress Log -->
<div class="fr-card mb-4">
    <div class="fr-card-pad">
        <?php if ($detailProject['status'] !== 'completed'): ?>
            <h3 class="fr-section-title">Add Progress Log Entry</h3>
            <form method="POST" action="foreman.php?tab=projects">
                <?= csrf_field() ?>
                <input type="hidden" name="project_id" value="<?= $detailProject['id'] ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Issues Encountered</label>
                        <textarea name="issues_encountered" rows="2" class="form-control"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" rows="2" class="form-control"></textarea>
                    </div>
                </div>
                <div class="d-flex justify-content-end mt-3">
                    <button type="submit" name="add_progress_log" class="btn btn-primary">Add Log Entry</button>
                </div>
            </form>
        <?php endif; ?>
        <!-- Attachments gallery modal -->
        <div class="modal fade" id="attachmentModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title mb-0">Task Photos</h5>
                            <div class="attachment-task-desc text-muted small"></div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="attachment-empty text-center text-muted py-5 d-none">
                            <i class="bi bi-images" style="font-size: 36px; opacity: .4; display: block; margin-bottom: 8px;"></i>
                            No photos attached yet.
                        </div>
        
                        <div class="attachment-stage position-relative rounded-3 mb-3 d-none" style="background:#111; overflow:hidden;">
                            <img class="attachment-main" src="" alt="" style="width:100%; display:block; max-height:65vh; object-fit:contain; background:#111;">
                            <button type="button" class="attachment-prev btn btn-dark position-absolute top-50 start-0 translate-middle-y ms-2" aria-label="Previous">
                                <i class="bi bi-chevron-left"></i>
                            </button>
                            <button type="button" class="attachment-next btn btn-dark position-absolute top-50 end-0 translate-middle-y me-2" aria-label="Next">
                                <i class="bi bi-chevron-right"></i>
                            </button>
                            <div class="attachment-meta position-absolute bottom-0 start-0 end-0 p-2 small text-white-50" style="background: linear-gradient(to top, rgba(0,0,0,.7), transparent);"></div>
                        </div>
        
                        <div class="attachment-strip d-flex gap-2 overflow-auto d-none" style="padding-bottom: 4px;">
                            <!-- thumbnails injected via JS -->
                        </div>
                    </div>
                    <div class="modal-footer justify-content-between flex-wrap gap-2">
                        <div class="d-flex gap-2 flex-wrap">
                            <input type="file" class="d-none attachment-file-input" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp">
                            <button type="button" class="btn btn-primary attachment-upload-btn">
                                <i class="bi bi-upload me-1"></i>Add Photo
                            </button>
                            <button type="button" class="btn btn-outline-danger attachment-delete-btn" disabled>
                                <i class="bi bi-trash me-1"></i>Remove Current
                            </button>
                        </div>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Progress Chart -->
<div class="fr-card">
    <div class="fr-card-pad">
        <h3 class="fr-section-title">Progress Over Time</h3>
        <p style="color: var(--fr-muted); font-size: 13px;">
            Daily timeline from the project start date. Days without an update carry forward the latest progress.
        </p>
        <div style="background: #fff; border: 1px solid var(--fr-border); border-radius: 10px; padding: 10px;">
            <?= renderProgressChart($dailyProgress) ?>
        </div>
    </div>
</div>

<!-- Chart SVG recolor — override the dark rect in renderProgressChart -->
<style>
    .chart-svg rect[fill="#22252a"] { fill: #ffffff !important; }
    .chart-svg text[fill="#eeeeee"] { fill: var(--fr-text) !important; }
    .chart-svg text[fill="#9da2a8"] { fill: var(--fr-muted) !important; }
    .chart-svg line[stroke="#3a3f44"] { stroke: #e5e7eb !important; }
</style>

<?php else: ?>

<!-- ================= PROJECT LIST ================= -->
<div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
    <div>
        <h2 class="fw-bold mb-1" style="font-size: 20px;">My Projects</h2>
        <p class="mb-0" style="color: var(--fr-muted); font-size: 13.5px;">All projects assigned to you.</p>
    </div>
    <form method="GET" action="foreman.php" class="d-flex flex-wrap gap-2 align-items-center">
        <input type="hidden" name="tab" value="projects">
        <select name="status_filter" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
            <option value="all"        <?= $status_filter === 'all'       ? 'selected' : '' ?>>All Projects</option>
            <option value="active"     <?= $status_filter === 'active'    ? 'selected' : '' ?>>Active</option>
            <option value="pending"    <?= $status_filter === 'pending'   ? 'selected' : '' ?>>Pending</option>
            <option value="completed"  <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
        </select>
        <input type="text" name="search" class="form-control form-control-sm"
               placeholder="Search..." style="width: 180px;"
               value="<?= htmlspecialchars($search) ?>">
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
    </form>
</div>

<div class="fr-table-wrap">
    <table class="fr-table">
        <thead>
            <tr>
                <th>Project</th>
                <th>Service</th>
                <th>Start</th>
                <th>Employees</th>
                <th>Progress</th>
                <th>Status</th>
                <th class="text-end">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($projects)): ?>
            <tr><td colspan="7" class="text-center py-5" style="color: var(--fr-muted);">
                <i class="bi bi-kanban" style="font-size: 28px; opacity: .4; display: block; margin-bottom: 8px;"></i>
                No projects match your filter.
            </td></tr>
        <?php else: ?>
            <?php foreach ($projects as $project):
                $employees = $projectEmployees[$project['id']] ?? [];
                $empCount = count($employees);
                $st = $project['status'] === 'completed' ? 'completed' : ($project['status'] === 'pending' ? 'pending' : 'active');
            ?>
                <tr style="cursor: pointer;"
                    onclick="window.location.href='foreman.php?tab=projects&view=<?= (int)$project['id'] ?>'">
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($project['project_name']) ?></div>
                        <div style="font-size: 11.5px; color: var(--fr-muted);"><?= htmlspecialchars($project['project_id']) ?></div>
                    </td>
                    <td><?= htmlspecialchars(ucfirst($project['service_type'])) ?></td>
                    <td class="fw-num"><?= htmlspecialchars(date('M d, Y', strtotime($project['start_date']))) ?></td>
                    <td>
                        <?php if ($empCount === 0): ?>
                            <span style="color: var(--fr-muted);">—</span>
                        <?php else: ?>
                            <span class="fr-badge followup"><?= $empCount ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="min-width: 130px;">
                        <div class="d-flex align-items-center gap-2">
                            <div class="fr-progress flex-grow-1" style="max-width: 90px;">
                                <div style="width: <?= (int)$project['progress'] ?>%;"></div>
                            </div>
                            <span class="fw-semibold" style="font-size: 12px;"><?= (int)$project['progress'] ?>%</span>
                        </div>
                    </td>
                    <td><span class="fr-badge <?= $st ?>"><?= htmlspecialchars(ucfirst($project['status'])) ?></span></td>
                    <td class="text-end">
                        <a href="foreman.php?tab=projects&view=<?= (int)$project['id'] ?>"
                           class="btn btn-sm btn-outline-secondary"
                           onclick="event.stopPropagation();">
                            View <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>


<!-- =========================================================================
     ADDITIONAL STYLES FOR EMPLOYEE DISPLAY
     ========================================================================= -->




<script>
function updateProgressDisplay(value) {

    const progressValue =
        document.getElementById(
            'progressValue'
        );

    if (progressValue) {

        progressValue.textContent =
            value + '%';

    }
}
</script>


<?php if ($detailProject): ?>
<script>
(function () {

    const taskApiUrl =
        'foreman.php?tab=projects&ajax=task';

    const csrfToken =
        <?= json_encode(csrf_token()) ?>;

    const projectEmployees = <?= json_encode(
        array_map(function ($e) {
            return [
                'id'   => (int)$e['employee_id'],
                'name' => $e['full_name'],
            ];
        }, $detailEmployees),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ) ?>;

    // taskId -> [employeeId, ...]
    const currentTaskAssignees = {};

    <?php foreach ($tasks as $task): ?>
        currentTaskAssignees[<?= (int)$task['id'] ?>] =
            <?= json_encode(array_map(function ($a) { return (int)$a['id']; }, $task['assignees'])) ?>;
    <?php endforeach; ?>

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    function postTaskAction(action, data) {

        const body = new FormData();

        body.append('csrf_token', csrfToken);
        body.append('action', action);

        Object.keys(data).forEach(function (key) {
            const value = data[key];
            if (Array.isArray(value)) {
                value.forEach(function (v) {
                    body.append(key, v);
                });
            } else {
                body.append(key, value);
            }
        });

        return fetch(taskApiUrl, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return response.json();
        });
    }


    function updateProgressUI(result) {

        // Task-count badge
        const badge = document.getElementById('taskCountBadge');
        if (badge) {
            badge.textContent = result.done + ' / ' + result.total + ' done';
        }

        // Task progress bar
        const taskFill = document.getElementById('taskProgressFill');
        if (taskFill) {
            const pct = result.total > 0
                ? Math.round((result.done / result.total) * 100)
                : 0;
            taskFill.style.width = pct + '%';
        }

        // Overall project progress — header + bar + label
        const pct = result.progress;

        const headPercent = document.getElementById('projectProgressPercent');
        if (headPercent) headPercent.textContent = pct + '%';

        const projectFill = document.getElementById('projectProgressFill');
        if (projectFill) projectFill.style.width = pct + '%';

        const projectLabel = document.getElementById('projectProgressLabel');
        if (projectLabel) projectLabel.textContent = pct + '%';
    }


    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function buildTaskRow(task) {
        const tr = document.createElement('tr');
        tr.dataset.taskId = task.id;

        tr.innerHTML = `
            <td></td>
            <td>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="task-assignees-text" data-for-task="${task.id}"
                          style="font-size: 13px;">
                        <span style="color: var(--fr-muted); font-style: italic;">Unassigned</span>
                    </span>
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary task-assign-btn"
                            data-task-id="${task.id}"
                            data-task-desc=""
                            style="font-size: 11.5px; padding: 2px 8px;">
                        Assign
                    </button>
                </div>
            </td>
            <td>
                <form method="POST" action="foreman.php?tab=projects" class="task-toggle-form d-inline">
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="toggle_task" value="${task.id}">
                    <input type="hidden" name="task_id" value="${task.id}">
                    <button type="submit" class="btn btn-sm btn-outline-secondary task-toggle"
                            data-task-id="${task.id}" data-completed="0">
                        Pending
                    </button>
                </form>
            </td>
            <td>
                <button type="button" class="btn btn-sm btn-outline-secondary attachment-open-btn"
                        data-task-id="${task.id}" data-task-desc="">
                    <i class="bi bi-image"></i>
                    <span class="attachment-count-badge ms-1">0</span>
                </button>
            </td>
            <td>
                <form method="POST" action="foreman.php?tab=projects" class="task-delete-form d-inline">
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="delete_task" value="${task.id}">
                    <button type="submit" class="btn btn-sm btn-outline-danger border-0 task-delete"
                            data-task-id="${task.id}">
                        <i class="bi bi-trash"></i>
                    </button>
                </form>
            </td>
        `;
        tr.cells[0].textContent = task.description;

        const descBtn = tr.querySelector('.attachment-open-btn');
        if (descBtn) descBtn.dataset.taskDesc = task.description;

        const assignBtn = tr.querySelector('.task-assign-btn');
        if (assignBtn) assignBtn.dataset.taskDesc = task.description;

        return tr;
    }


    // ------------------------------------------------------------------
    // Add task
    // ------------------------------------------------------------------

    const addForm = document.getElementById('addTaskForm');

    if (addForm) {

        addForm.addEventListener('submit', function (event) {

            event.preventDefault();

            const input = document.getElementById('newTaskInput');
            const description = input ? input.value.trim() : '';

            if (!description) return;

            const submitBtn = addForm.querySelector('button[type="submit"]');
            const originalText = submitBtn ? submitBtn.textContent : '';

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Adding...';
            }

            postTaskAction('add', {
                project_id:  addForm.querySelector('input[name="project_id"]').value,
                description: description
            })
            .then(function (result) {

                if (!result.success) {
                    alert(result.message || 'Unable to add task.');
                    return;
                }

                const noRow = document.getElementById('noTasksRow');
                if (noRow) noRow.remove();

                const tbody = document.getElementById('taskTableBody');
                if (tbody) tbody.appendChild(buildTaskRow(result.task));

                input.value = '';
                updateProgressUI(result);
            })
            .catch(function (err) {
                console.error(err);
                alert('Unable to add task. Please try again.');
            })
            .finally(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                }
            });
        });
    }


    // ------------------------------------------------------------------
    // Toggle + Delete (delegated on the table body)
    // ------------------------------------------------------------------

    const tbody = document.getElementById('taskTableBody');

    if (tbody) {

        tbody.addEventListener('submit', function (event) {

            const toggleForm = event.target.closest('.task-toggle-form');
            const deleteForm = event.target.closest('.task-delete-form');

            // ---- Toggle ----
            if (toggleForm) {

                event.preventDefault();

                const button = toggleForm.querySelector('.task-toggle');
                const idInput = toggleForm.querySelector('input[name="toggle_task"]');
                const taskId = idInput ? idInput.value : (button ? button.dataset.taskId : '');

                if (button) button.disabled = true;

                postTaskAction('toggle', { task_id: taskId })
                    .then(function (result) {

                        if (!result.success) {
                            alert(result.message || 'Unable to toggle task.');
                            return;
                        }

                        const isCompleted = result.is_completed === 1;

                        if (button) {
                            button.dataset.completed = isCompleted ? '1' : '0';
                        
                            button.classList.toggle('btn-primary', isCompleted);
                            button.classList.toggle('btn-outline-secondary', !isCompleted);
                        
                            button.innerHTML = isCompleted
                                ? '<i class="bi bi-check2 me-1"></i>Done'
                                : 'Pending';
                        }

                        updateProgressUI(result);
                    })
                    .catch(function (err) {
                        console.error(err);
                        alert('Unable to toggle task. Please try again.');
                    })
                    .finally(function () {
                        if (button) button.disabled = false;
                    });

                return;
            }

            // ---- Delete ----
            if (deleteForm) {

                event.preventDefault();

                if (!confirm('Delete this task?')) return;

                const button = deleteForm.querySelector('.task-delete');
                const idInput = deleteForm.querySelector('input[name="delete_task"]');
                const taskId = idInput ? idInput.value : (button ? button.dataset.taskId : '');

                if (button) button.disabled = true;

                postTaskAction('delete', { task_id: taskId })
                    .then(function (result) {

                        if (!result.success) {
                            alert(result.message || 'Unable to delete task.');
                            return;
                        }

                        const row = tbody.querySelector('tr[data-task-id="' + taskId + '"]');
                        if (row) row.remove();

                        // Restore empty-state message if needed.
                        if (!tbody.querySelector('tr[data-task-id]')) {
                            const tr = document.createElement('tr');
                            tr.id = 'noTasksRow';
                            tr.innerHTML = '<td colspan="4" style="text-align:center; padding:15px;">No tasks added yet.</td>';
                            tbody.appendChild(tr);
                        }

                        updateProgressUI(result);
                    })
                    .catch(function (err) {
                        console.error(err);
                        alert('Unable to delete task. Please try again.');
                    })
                    .finally(function () {
                        if (button) button.disabled = false;
                    });

                return;
            }
        });
    }

    // ------------------------------------------------------------------
    // Assign Employees Modal
    //
    // We must NOT do `new bootstrap.Modal(...)` at IIFE time, because
    // bootstrap.bundle.min.js loads at the bottom of foreman.php,
    // *after* this inline script runs. Building the instance lazily
    // via getOrCreateInstance sidesteps the ordering entirely.
    // ------------------------------------------------------------------
    const assignModalEl = document.getElementById('taskAssignModal');

    if (assignModalEl) {

        const descEl     = assignModalEl.querySelector('.task-assign-task-desc');
        const checkboxes = assignModalEl.querySelectorAll('.task-assign-checkbox');
        const saveBtn    = document.getElementById('taskAssignSaveBtn');

        let currentTaskId = 0;

        function showAssignModal() {
            if (typeof bootstrap === 'undefined') {
                console.error('Bootstrap is not loaded yet; cannot open the assign modal.');
                return;
            }
            bootstrap.Modal.getOrCreateInstance(assignModalEl).show();
        }

        function hideAssignModal() {
            if (typeof bootstrap === 'undefined') return;
            const inst = bootstrap.Modal.getInstance(assignModalEl);
            if (inst) inst.hide();
        }

        // Open modal from any .task-assign-btn (delegated so new rows work).
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.task-assign-btn');
            if (!btn) return;

            currentTaskId = parseInt(btn.dataset.taskId, 10);
            descEl.textContent = btn.dataset.taskDesc || '';

            const assigned = currentTaskAssignees[currentTaskId] || [];

            checkboxes.forEach(function (cb) {
                cb.checked = assigned.indexOf(parseInt(cb.value, 10)) !== -1;
            });

            showAssignModal();
        });

        saveBtn.addEventListener('click', function () {
            const ids = [];
            checkboxes.forEach(function (cb) {
                if (cb.checked) ids.push(cb.value);
            });

            saveBtn.disabled = true;
            const original = saveBtn.innerHTML;
            saveBtn.innerHTML = 'Saving...';

            postTaskAction('assign', {
                task_id:          currentTaskId,
                'employee_ids[]': ids
            })
            .then(function (result) {
                if (!result.success) {
                    alert(result.message || 'Unable to save assignments.');
                    return;
                }

                currentTaskAssignees[currentTaskId] = result.assignees.map(function (a) {
                    return a.id;
                });

                // Update the visible cell in place.
                const cell = document.querySelector(
                    '.task-assignees-text[data-for-task="' + currentTaskId + '"]'
                );
                if (cell) {
                    if (result.assignees.length === 0) {
                        cell.innerHTML = '<span style="color: var(--fr-muted); font-style: italic;">Unassigned</span>';
                    } else {
                        cell.textContent = result.assignees
                            .map(function (a) { return a.name; })
                            .join(', ');
                    }
                }

                hideAssignModal();
            })
            .catch(function (err) {
                console.error(err);
                alert('Unable to save assignments. Please try again.');
            })
            .finally(function () {
                saveBtn.disabled = false;
                saveBtn.innerHTML = original;
            });
        });
    }

})();
// ==================================================================
// Task attachments gallery
// ==================================================================
document.addEventListener('DOMContentLoaded', function () {
    const modalEl   = document.getElementById('attachmentModal');
    if (!modalEl) return;

    const modal     = new bootstrap.Modal(modalEl);
    const csrfToken = <?= json_encode(csrf_token()) ?>;
    const endpoint  = 'foreman.php?ajax=task_attachment';
    
    const projectLocked = <?= $detailProject['status'] === 'completed' ? 'true' : 'false' ?>;

    const descEl     = modalEl.querySelector('.attachment-task-desc');
    const emptyEl    = modalEl.querySelector('.attachment-empty');
    const stageEl    = modalEl.querySelector('.attachment-stage');
    const stripEl    = modalEl.querySelector('.attachment-strip');
    const mainImg    = modalEl.querySelector('.attachment-main');
    const metaEl     = modalEl.querySelector('.attachment-meta');
    const prevBtn    = modalEl.querySelector('.attachment-prev');
    const nextBtn    = modalEl.querySelector('.attachment-next');
    const uploadBtn  = modalEl.querySelector('.attachment-upload-btn');
    const delBtn     = modalEl.querySelector('.attachment-delete-btn');
    const fileInput  = modalEl.querySelector('.attachment-file-input');

    let currentTaskId  = 0;
    let currentIndex   = 0;
    let attachments    = [];
    if (projectLocked) {
        uploadBtn.style.display = 'none';
        delBtn.style.display = 'none';
    }

    function postAction(action, data) {
        const body = new FormData();
        body.append('csrf_token', csrfToken);
        body.append('action', action);
        Object.keys(data || {}).forEach(k => body.append(k, data[k]));
        return fetch(endpoint, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(r => r.json());
    }

    function formatBytes(n) {
        if (!n) return '0 B';
        const u = ['B','KB','MB','GB'];
        let i = 0, v = n;
        while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
        return v.toFixed(v >= 10 || i === 0 ? 0 : 1) + ' ' + u[i];
    }

    function updateCountBadge(taskId, count) {
        document.querySelectorAll('.attachment-open-btn[data-task-id="' + taskId + '"] .attachment-count-badge')
            .forEach(el => { el.textContent = count; });
    }

    function render() {
        // Empty
        if (!attachments.length) {
            stageEl.classList.add('d-none');
            stripEl.classList.add('d-none');
            emptyEl.classList.remove('d-none');
            delBtn.disabled = true;
            return;
        }

        emptyEl.classList.add('d-none');
        stageEl.classList.remove('d-none');
        stripEl.classList.remove('d-none');
        delBtn.disabled = false;

        if (currentIndex < 0) currentIndex = 0;
        if (currentIndex >= attachments.length) currentIndex = attachments.length - 1;

        const cur = attachments[currentIndex];
        mainImg.src = cur.url;
        mainImg.alt = cur.original_name;

        metaEl.textContent =
            (currentIndex + 1) + ' / ' + attachments.length +
            ' · ' + cur.original_name +
            (cur.uploaded_by_name ? ' · uploaded by ' + cur.uploaded_by_name : '') +
            ' · ' + formatBytes(cur.file_size);

        prevBtn.style.display = attachments.length > 1 ? '' : 'none';
        nextBtn.style.display = attachments.length > 1 ? '' : 'none';

        stripEl.innerHTML = '';
        attachments.forEach((a, i) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn p-0 border rounded-2 flex-shrink-0';
            btn.style.width = '72px';
            btn.style.height = '72px';
            btn.style.overflow = 'hidden';
            if (i === currentIndex) {
                btn.classList.add('border-primary');
                btn.style.borderWidth = '2px';
            }
            btn.innerHTML = '<img src="' + a.url + '" alt="" style="width:100%;height:100%;object-fit:cover;display:block;">';
            btn.addEventListener('click', () => { currentIndex = i; render(); });
            stripEl.appendChild(btn);
        });
    }

    function openForTask(taskId, taskDesc) {
        currentTaskId = taskId;
        currentIndex  = 0;
        attachments   = [];
        descEl.textContent = taskDesc || '';
        render();
        modal.show();
        postAction('list', { task_id: taskId })
            .then(res => {
                if (!res.success) {
                    console.warn(res.message);
                    return;
                }
                attachments = res.attachments || [];
                render();
                updateCountBadge(currentTaskId, attachments.length);
            })
            .catch(err => {
                console.error(err);
                alert('Unable to load photos.');
            });
    }

    // Open buttons (delegated so it survives dynamic task-row adds)
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.attachment-open-btn');
        if (!btn) return;
        openForTask(parseInt(btn.dataset.taskId, 10), btn.dataset.taskDesc || '');
    });

    prevBtn.addEventListener('click', () => { currentIndex--; render(); });
    nextBtn.addEventListener('click', () => { currentIndex++; render(); });

    document.addEventListener('keydown', function (e) {
        if (!modalEl.classList.contains('show')) return;
        if (e.key === 'ArrowLeft')  { currentIndex--; render(); }
        if (e.key === 'ArrowRight') { currentIndex++; render(); }
    });

    // Upload
    uploadBtn.addEventListener('click', () => fileInput.click());


    fileInput.addEventListener('change', function () {
        const file = this.files && this.files[0];
        if (!file) return;

        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('action', 'upload');
        fd.append('task_id', currentTaskId);
        fd.append('image', file);

        uploadBtn.disabled = true;
        const prevLabel = uploadBtn.innerHTML;
        uploadBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Uploading…';

        fetch(endpoint, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                alert(res.message || 'Upload failed.');
                return;
            }
            attachments.push(res.attachment);
            currentIndex = attachments.length - 1;
            render();
            updateCountBadge(currentTaskId, attachments.length);
        })
        .catch(err => {
            console.error(err);
            alert('Upload failed. Please try again.');
        })
        .finally(() => {
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = prevLabel;
            fileInput.value = '';
        });
    });

    // Delete current
    delBtn.addEventListener('click', function () {
        const cur = attachments[currentIndex];
        if (!cur) return;
        if (!confirm('Remove this photo?')) return;

        delBtn.disabled = true;
        postAction('delete', { attachment_id: cur.id })
            .then(res => {
                if (!res.success) {
                    alert(res.message || 'Unable to remove photo.');
                    return;
                }
                attachments.splice(currentIndex, 1);
                if (currentIndex >= attachments.length) {
                    currentIndex = Math.max(0, attachments.length - 1);
                }
                render();
                updateCountBadge(currentTaskId, attachments.length);
            })
            .catch(err => {
                console.error(err);
                alert('Unable to remove photo.');
            })
            .finally(() => {
                if (attachments.length) delBtn.disabled = false;
            });
    });
});
</script>
<?php endif; ?>