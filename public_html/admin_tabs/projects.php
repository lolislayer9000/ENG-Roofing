<?php
// This file is included inside admin.php
// REVISION: Project completion email with a private, login-free feedback link.
require_once __DIR__ . "/../feedback_helpers.php";

// =============================================================================
// HANDLE ACTIONS
// =============================================================================

// Edit project
if (isset($_POST['edit_project'])) {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $id = (int)$_POST['edit_id'];

    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND is_client_approved = 1");
    $stmt->execute([$id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        header('Location: admin.php?tab=projects&msg=error');
        exit;
    }
    if ($project['status'] === 'completed') {
        header('Location: admin.php?tab=projects&msg=cannot_edit_completed');
        exit;
    }

    // Project name is UNEDITABLE — always use the existing value.
    $project_name = $project['project_name'];

    // These stay hidden+preserved from the original row.
    $client_id    = (int)$project['client_id'];
    $service_type = $project['service_type'];

    $foreman_id = !empty($_POST['foreman_id']) ? (int)$_POST['foreman_id'] : null;

    // Start date is now OPTIONAL. Empty means "not yet assigned".
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $today = (new DateTimeImmutable(
        'today',
        new DateTimeZone('Asia/Manila')
    ))->format('Y-m-d');
    
    $originalStartDate = $project['start_date'] ?: null;
    $currentStatus = strtolower(trim((string)$project['status']));

    $dateLocked = in_array(
        $currentStatus,
        ['scheduled', 'active', 'completed'],
        true
    );    
    
    // Confirmed schedules cannot be changed through ordinary editing.
    if ($dateLocked && $start_date !== $originalStartDate) {
        header('Location: admin.php?tab=projects&msg=date_locked');
        exit;
    }
    // Validate a newly entered or changed date.
    if ($start_date !== null && $start_date !== $originalStartDate) {
        $parsedDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $start_date,
            new DateTimeZone('Asia/Manila')
        );
    
        if (
            !$parsedDate ||
            $parsedDate->format('Y-m-d') !== $start_date ||
            $start_date < $today
        ) {
            header('Location: admin.php?tab=projects&msg=invalid_start_date');
            exit;
        }
    }
    
    
    // Expected end date is OPTIONAL too.
    $expected_end_date = !empty($_POST['expected_end_date']) ? $_POST['expected_end_date'] : null;

    $employees = isset($_POST['employees']) ? $_POST['employees'] : [];

    // ---------------------------------------------------------------------
    // Recompute status.
    //
    // Rules:
    //   - Completed projects stay completed (already blocked above).
    //   - If the payment is NOT yet "Down Payment Paid" (or "Fully Paid"),
    //     the project stays "Waiting for Down Payment".
    //   - After downpayment, a valid date and personnel confirm Scheduled.
    //   - Only Start Project changes Scheduled to active.
    //   - Without a date or personnel, payment-cleared projects stay pending.
    // ---------------------------------------------------------------------
    $stmt = $pdo->prepare("SELECT status FROM payments WHERE project_id = ? LIMIT 1");
    $stmt->execute([$id]);
    $paymentStatus = $stmt->fetchColumn();

    $downPaymentPaid = in_array(
        (string)$paymentStatus,
        ['Down Payment Paid', 'Fully Paid'],
        true
    );
    
    $startingProject = isset($_POST['start_project']);
    $hasPersonnel = !empty($foreman_id) && !empty($employees);
    
    if ($startingProject) {
        if (
            $currentStatus !== 'scheduled' ||
            !$downPaymentPaid ||
            !$hasPersonnel ||
            empty($start_date) ||
            $start_date > $today
        ) {
            header('Location: admin.php?tab=projects&msg=start_requirements');
            exit;
        }
    
        $status = 'active';
    
    } elseif ($currentStatus === 'active') {
        $status = 'active';
    
    } elseif ($currentStatus === 'scheduled') {
        if (!$downPaymentPaid || !$hasPersonnel || empty($start_date)) {
            header('Location: admin.php?tab=projects&msg=start_requirements');
            exit;
        }
    
        $status = 'Scheduled';
    
    } elseif (!$downPaymentPaid) {
        $status = 'Waiting for Down Payment';
    
    } elseif (empty($start_date) || !$hasPersonnel) {
        $status = 'pending';
    
    } elseif ($start_date < $today) {
        header('Location: admin.php?tab=projects&msg=invalid_start_date');
        exit;
    
    } else {
        $status = 'Scheduled';
    }
    
    // Validate employee assignments before saving.
        $employees = array_values(array_unique(array_filter(
            array_map('intval', (array)$employees),
            static fn($employeeId) => $employeeId > 0
        )));
        sort($employees);
        $stmt = $pdo->prepare("
            SELECT labor_requirement
            FROM site_inspections
            WHERE project_id = ?
              AND labor_requirement IS NOT NULL
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $requiredEmployees = max(0, (int)$stmt->fetchColumn());
        
        if (
            in_array(strtolower($status), ['scheduled', 'active'], true)
            && count($employees) < $requiredEmployees
        ) {
            header('Location: admin.php?tab=projects&msg=insufficient_employees');
            exit;
        }
        
        $pdo->beginTransaction();
        
        if (!empty($employees)) {
            $placeholders = implode(',', array_fill(0, count($employees), '?'));
        
            // Lock selected employees so simultaneous assignments are checked in order.
            $stmt = $pdo->prepare("
                SELECT id
                FROM users
                WHERE id IN ($placeholders)
                ORDER BY id
                FOR UPDATE
            ");
            $stmt->execute($employees);
            $stmt->fetchAll(PDO::FETCH_COLUMN);
        
            $stmt = $pdo->prepare("
                SELECT pe.employee_id
                FROM project_employees pe
                INNER JOIN projects p ON p.id = pe.project_id
                WHERE pe.employee_id IN ($placeholders)
                  AND p.id <> ?
                  AND p.is_client_approved = 1
                  AND LOWER(TRIM(p.status)) IN (
                      'waiting for down payment',
                      'scheduled',
                      'active',
                      'pending'
                  )
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute(array_merge($employees, [$id]));
        
            if ($stmt->fetchColumn() !== false) {
                $pdo->rollBack();
                header('Location: admin.php?tab=projects&msg=employee_conflict');
                exit;
            }
        }
        
    $sql = "UPDATE projects SET
            project_name      = :project_name,
            client_id         = :client_id,
            service_type      = :service_type,
            foreman_id        = :foreman_id,
            start_date        = :start_date,
            expected_end_date = :expected_end_date,
            status            = :status
            WHERE id = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':project_name'      => $project_name,
        ':client_id'         => $client_id,
        ':service_type'      => $service_type,
        ':foreman_id'        => $foreman_id,
        ':start_date'        => $start_date,       // can be NULL
        ':expected_end_date' => $expected_end_date,
        ':status'            => $status,
        ':id'                => $id
    ]);

    // Update employees
    $stmt = $pdo->prepare("DELETE FROM project_employees WHERE project_id = ?");
    $stmt->execute([$id]);

    if (!empty($employees)) {
        $sql = "INSERT INTO project_employees (project_id, employee_id) VALUES (?, ?)";
        $stmt = $pdo->prepare($sql);

        foreach ($employees as $emp_id) {
            $stmt->execute([$id, (int)$emp_id]);
        }
    }

    $pdo->commit();
    // ---- Notifications ----
    if ($foreman_id) {
        $message = "Project assignment updated: you are now foreman for $project_name (ID: {$project['project_id']}).";
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, message) VALUES (?, ?, ?)");
        $stmt->execute([$foreman_id, $id, $message]);
    }

    if (!empty($employees)) {
        $message = "Project assignment updated: you are now assigned to $project_name (ID: {$project['project_id']}).";
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, message) VALUES (?, ?, ?)");
        foreach ($employees as $emp_id) {
            $stmt->execute([$emp_id, $id, $message]);
        }
    }

    header('Location: admin.php?tab=projects&msg=updated');
    exit;
}

// REVISION: Retry only a failed/unsent feedback invitation; successful sends are skipped.
if (isset($_POST['send_feedback_invitation'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $feedbackProjectId = (int)$_POST['send_feedback_invitation'];
    $feedbackSent = sendProjectFeedbackInvitation($pdo, $feedbackProjectId);
    header('Location: admin.php?tab=projects&view=' . $feedbackProjectId
        . '&msg=' . ($feedbackSent ? 'feedback_sent' : 'feedback_email_failed'));
    exit;
}

// Mark project as complete (admin only)
if (isset($_POST['mark_complete'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $id = (int)$_POST['mark_complete'];

    $stmt = $pdo->prepare("
        SELECT id, status, project_id, project_name, foreman_id, progress
        FROM projects
        WHERE id = ? AND is_client_approved = 1
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        header('Location: admin.php?tab=projects&msg=error');
        exit;
    }

    if ($project['status'] === 'completed') {
        header('Location: admin.php?tab=projects&msg=already_completed');
        exit;
    }

    // ---- Rule 1: all tasks must be finished (progress must be 100%) ----
    if ((int)$project['progress'] < 100) {
        header('Location: admin.php?tab=projects&msg=progress_incomplete');
        exit;
    }

    // ---- Rule 2: the linked payment must be Fully Paid ----
    $stmt = $pdo->prepare("SELECT status FROM payments WHERE project_id = ? LIMIT 1");
    $stmt->execute([$id]);
    $paymentStatus = $stmt->fetchColumn();

    if ($paymentStatus === false || strcasecmp((string)$paymentStatus, 'Fully Paid') !== 0) {
        header('Location: admin.php?tab=projects&msg=payment_incomplete');
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE projects
        SET status = 'completed', completed_at = NOW()
        WHERE id = ? AND status <> 'completed'
    ");
    $stmt->execute([$id]);

    // REVISION: Only the request that changed the status sends an invitation.
    if ($stmt->rowCount() === 0) {
        header('Location: admin.php?tab=projects&msg=already_completed');
        exit;
    }
    $feedbackSent = sendProjectFeedbackInvitation($pdo, $id);

    // Notify the assigned foreman.
    if (!empty($project['foreman_id'])) {
        try {
            $message = "Project {$project['project_id']} ({$project['project_name']}) has been marked as completed and is now locked for editing.";
            $notif = $pdo->prepare("
                INSERT INTO notifications (user_id, project_id, message)
                VALUES (?, ?, ?)
            ");
            $notif->execute([(int)$project['foreman_id'], $id, $message]);
        } catch (Throwable $e) {
            error_log("Failed to send project completion notification: " . $e->getMessage());
        }
    }

    header('Location: admin.php?tab=projects&msg='
        . ($feedbackSent ? 'completed' : 'completed_email_failed'));
    exit;
}

// Add progress log entry
if (isset($_POST['add_progress_log'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $project_id = (int)$_POST['project_id'];

    $stmt = $pdo->prepare("SELECT status FROM projects WHERE id = ? LIMIT 1");
    $stmt->execute([$project_id]);
    if ($stmt->fetchColumn() === 'completed') {
        header('Location: admin.php?tab=projects&view=' . $project_id . '&msg=cannot_edit_completed');
        exit;
    }

    $materials_used     = trim($_POST['materials_used'] ?? '');
    $issues_encountered = trim($_POST['issues_encountered'] ?? '');
    $remarks            = trim($_POST['remarks'] ?? '');

    $stmt = $pdo->prepare("SELECT progress FROM projects WHERE id = ?");
    $stmt->execute([$project_id]);
    $currentProgress = $stmt->fetchColumn();

    $sql = "INSERT INTO project_progress_log
            (project_id, progress, work_completed, materials_used, issues_encountered, remarks)
            VALUES (?, ?, '', ?, ?, ?)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $project_id,
        $currentProgress,
        $materials_used,
        $issues_encountered,
        $remarks
    ]);

    header('Location: admin.php?tab=projects&view=' . $project_id);
    exit;
}

// Add task
if (isset($_POST['add_task'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $project_id  = (int)$_POST['project_id'];
    $description = trim($_POST['task_description']);

    $stmt = $pdo->prepare("SELECT status FROM projects WHERE id = ? LIMIT 1");
    $stmt->execute([$project_id]);
    if ($stmt->fetchColumn() === 'completed') {
        header('Location: admin.php?tab=projects&view=' . $project_id . '&msg=cannot_edit_completed');
        exit;
    }

    if (!empty($description)) {
        addProjectTask($pdo, $project_id, $description);
    }

    header('Location: admin.php?tab=projects&view=' . $project_id);
    exit;
}

// Toggle task (POST)
if (isset($_POST['toggle_task'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $task_id = (int)$_POST['toggle_task'];

    $stmt = $pdo->prepare("
        SELECT pt.project_id, p.status
        FROM project_tasks pt
        INNER JOIN projects p ON p.id = pt.project_id
        WHERE pt.id = ?
        LIMIT 1
    ");
    $stmt->execute([$task_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        header('Location: admin.php?tab=projects&msg=error');
        exit;
    }

    if ($row['status'] === 'completed') {
        header('Location: admin.php?tab=projects&view=' . (int)$row['project_id'] . '&msg=cannot_edit_completed');
        exit;
    }

    toggleProjectTask($pdo, $task_id);

    header('Location: admin.php?tab=projects&view=' . (int)$row['project_id']);
    exit;
}

if (isset($_POST['delete_task'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $task_id = (int)$_POST['delete_task'];

    $stmt = $pdo->prepare("
        SELECT pt.project_id, p.status
        FROM project_tasks pt
        INNER JOIN projects p ON p.id = pt.project_id
        WHERE pt.id = ?
        LIMIT 1
    ");
    $stmt->execute([$task_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        header('Location: admin.php?tab=projects&msg=error');
        exit;
    }

    if ($row['status'] === 'completed') {
        header('Location: admin.php?tab=projects&view=' . (int)$row['project_id'] . '&msg=cannot_edit_completed');
        exit;
    }

    deleteProjectTask($pdo, $task_id);

    header('Location: admin.php?tab=projects&view=' . (int)$row['project_id']);
    exit;
}

// =============================================================================
// FETCH DATA
// =============================================================================

$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : 'all';

$allowed_status = [
    'Waiting for Down Payment',
    'Scheduled',
    'active',
    'pending',
    'completed',
    'cancelled',
    'all'
];

if (!in_array($status_filter, $allowed_status, true)) {
    $status_filter = 'all';
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$sql = "SELECT p.*,
        c.full_name AS client_name,
        f.full_name AS foreman_name,
        (SELECT COUNT(*) FROM project_employees WHERE project_id = p.id) AS employee_count,
        (SELECT status FROM payments WHERE project_id = p.id LIMIT 1) AS payment_status
        FROM projects p
        LEFT JOIN users c ON p.client_id = c.id
        LEFT JOIN users f ON p.foreman_id = f.id
        WHERE p.is_client_approved = 1";

$params = [];

if ($status_filter !== 'all') {
    $sql .= " AND p.status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($search)) {
    $sql .= " AND p.project_name LIKE :search";
    $params[':search'] = '%' . $search . '%';
}

$sql .= " ORDER BY p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get assigned employees for each project
$project_employees = [];

if (!empty($projects)) {
    $ids = array_column($projects, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare(
        "SELECT project_id, employee_id
         FROM project_employees
         WHERE project_id IN ($placeholders)"
    );

    $stmt->execute($ids);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $project_employees[$row['project_id']][] = $row['employee_id'];
    }
}

// Lists for dropdowns
$clients = $pdo->query(
    "SELECT id, full_name
     FROM users
     WHERE role = 'client'
     AND status = 'active'
     ORDER BY full_name"
)->fetchAll(PDO::FETCH_ASSOC);

$foremen = $pdo->query(
    "SELECT id, full_name
     FROM users
     WHERE role = 'foreman'
     AND status = 'active'
     ORDER BY full_name"
)->fetchAll(PDO::FETCH_ASSOC);

$employees = $pdo->query(
    "SELECT id, full_name, status
     FROM users
     WHERE role = 'employee'
     AND (status = 'active' OR id IN (
         SELECT employee_id FROM project_employees
     ))
     ORDER BY full_name"
)->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------
// Employees already on an in-flight project.
// Now includes the two new in-flight statuses so the same
// person isn't double-booked on two concurrent jobs.
// ---------------------------------------------------------
$employeesBusyElsewhere = [];
$stmt = $pdo->query("
    SELECT pe.employee_id, pe.project_id
    FROM project_employees pe
    INNER JOIN projects p ON p.id = pe.project_id
    WHERE p.is_client_approved = 1
      AND p.status IN ('Waiting for Down Payment', 'Scheduled', 'active', 'pending')
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $employeesBusyElsewhere[(int)$row['employee_id']][] = (int)$row['project_id'];
}

// Message handling
$msg = '';

if (isset($_GET['msg'])) {
    $msgs = [
        'insufficient_employees' => 'Not enough employees. Assign the required number shown in the site inspection before scheduling or starting the project.',
        'updated'               => 'Project updated successfully.',
        'progress_updated'      => 'Progress updated successfully.',
        'feedback_sent'         => 'Feedback invitation has been sent already or was sent successfully.',
        'feedback_email_failed' => 'Feedback email was not sent. Check the client email and mail settings, then retry.',
        'completed_email_failed'=> 'Project completed and locked, but feedback email was not sent. Open project details to retry.',
        'completed'             => 'Project marked as completed. It is now locked for editing.',
        'already_completed'     => 'This project is already completed.',
        'cannot_edit_completed' => 'This project is completed and cannot be edited.',
        'progress_incomplete'   => 'Cannot mark this project as complete: all tasks must be finished (100% progress).',
        'payment_incomplete'    => 'Cannot mark this project as complete: the linked payment must be Fully Paid.',
        'date_locked'           => 'The confirmed start date is locked.',
        'invalid_start_date'    => 'Please choose a valid start date today or later.',
        'start_requirements'    => 'Starting requires a Scheduled project, confirmed downpayment, assigned foreman and employees, and a start date today or earlier.',
        'error'                 => 'An error occurred.'
    ];

    $msg = $msgs[$_GET['msg']] ?? '';
}

// For editing
$editProject = null;
$editEmployees = [];

if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];

    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND is_client_approved = 1");
    $stmt->execute([$id]);

    $editProject = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($editProject) {
        $stmt = $pdo->prepare(
            "SELECT employee_id
             FROM project_employees
             WHERE project_id = ?"
        );

        $stmt->execute([$id]);
        $editEmployees = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        unset($_GET['edit']);
    }
}

// For detail modal
$detailProject = null;
$detailLogs = [];

if (isset($_GET['view'])) {
    $id = (int)$_GET['view'];

    $stmt = $pdo->prepare(
        "SELECT p.*, c.full_name AS client_name, f.full_name AS foreman_name
         FROM projects p
         LEFT JOIN users c ON p.client_id = c.id
         LEFT JOIN users f ON p.foreman_id = f.id
         WHERE p.id = ? AND p.is_client_approved = 1"
    );

    $stmt->execute([$id]);
    $detailProject = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($detailProject) {
        $stmt = $pdo->prepare(
            "SELECT *
             FROM project_progress_log
             WHERE project_id = ?
             ORDER BY created_at DESC"
        );

        $stmt->execute([$id]);
        $detailLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Backfill missing predefined tasks for approved projects.
    if ($detailProject) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM project_tasks WHERE project_id = ? AND task_code IS NOT NULL");
        $check->execute([(int)$detailProject['id']]);

        if ((int)$check->fetchColumn() === 0) {
            try {
                require_once __DIR__ . '/../task_filter.php';

                $stmt = $pdo->prepare("SELECT id FROM quotations WHERE project_id = ? AND status = 'Approved' ORDER BY is_selected DESC, id DESC LIMIT 1");
                $stmt->execute([(int)$detailProject['id']]);
                $approvedQuotationId = (int)$stmt->fetchColumn();

                if ($approvedQuotationId > 0) {
                    $taskResult = generateProjectTasksForApprovedQuotation($pdo, (int)$detailProject['id'], $approvedQuotationId);
                    if (!$taskResult['success']) {
                        error_log("Task backfill: {$taskResult['message']} (project {$detailProject['id']})");
                    }
                }
            } catch (Throwable $e) {
                error_log("Task backfill failed for project {$detailProject['id']}: " . $e->getMessage());
            }
        }
    }

    // Attachments per task
    $detailTasks = $detailProject ? getProjectTasks($pdo, $detailProject['id']) : [];
    $detailAttachmentsByTask = [];

    if (!empty($detailTasks)) {
        $ids = array_column($detailTasks, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            SELECT a.*, u.full_name AS uploaded_by_name
            FROM project_task_attachments a
            LEFT JOIN users u ON u.id = a.uploaded_by
            WHERE a.task_id IN ($ph)
            ORDER BY a.id ASC
        ");
        $stmt->execute($ids);

        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $detailAttachmentsByTask[(int)$r['task_id']][] = [
                'id'               => (int)$r['id'],
                'url'              => 'task_attachment.php?id=' . (int)$r['id'],
                'original_name'    => $r['original_name'],
                'uploaded_by_name' => $r['uploaded_by_name'] ?? '',
                'file_size'        => (int)$r['file_size'],
                'uploaded_at'      => $r['uploaded_at'],
            ];
        }
    }
}

// Latest site inspection linked to this project.
$detailInspection = null;

if ($detailProject) {
    $stmt = $pdo->prepare("
        SELECT si.*, u.full_name AS inspector_name
        FROM site_inspections si
        LEFT JOIN users u ON si.inspector_id = u.id
        WHERE si.project_id = ?
        ORDER BY si.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$detailProject['id']]);
    $detailInspection = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$detailInspection) {
        $stmt = $pdo->prepare("
            SELECT si.*, u.full_name AS inspector_name
            FROM site_inspections si
            INNER JOIN bookings b ON si.booking_id = b.id
            LEFT JOIN users u ON si.inspector_id = u.id
            WHERE b.project_id = ?
            ORDER BY si.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$detailProject['id']]);
        $detailInspection = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Helper: slug the raw status → CSS badge class
$projectStatusClass = function (string $status): string {
    return strtolower(str_replace(' ', '-', trim($status)));
};

$projectStatusLabel = function (string $status): string {
    // Normalize the display of the two new statuses.
    if ($status === 'Waiting for Down Payment') return 'Waiting for Down Payment';
    if ($status === 'Scheduled')                return 'Scheduled';
    if (strtolower(trim($status)) === 'pending') {
    return 'Pending Scheduling';
}
    return ucfirst($status);
};
?>

<div class="projects-page">
<?php if ($msg): ?>
    <div class="msg"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Top bar -->
<div class="top-bar">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <form method="GET" class="d-flex align-items-center gap-2">
            <input type="hidden" name="tab" value="projects">
            <label for="status_filter">Status</label>
            <select name="status_filter" id="status_filter" onchange="this.form.submit()">
                <option value="all"                       <?= $status_filter == 'all'                       ? 'selected' : '' ?>>All Projects</option>
                <option value="Waiting for Down Payment"     <?= $status_filter == 'Waiting for Down Payment'     ? 'selected' : '' ?>>Waiting for Down Paymentt</option>
                <option value="Scheduled"                 <?= $status_filter == 'Scheduled'                 ? 'selected' : '' ?>>Scheduled</option>
                <option value="active"                    <?= $status_filter == 'active'                    ? 'selected' : '' ?>>Active</option>
                <option value="pending"                   <?= $status_filter == 'pending'                   ? 'selected' : '' ?>>Pending (Legacy)</option>
                <option value="completed"                 <?= $status_filter == 'completed'                 ? 'selected' : '' ?>>Completed</option>
                <option value="cancelled"                 <?= $status_filter == 'cancelled'                 ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </form>
        <form method="GET" class="d-flex gap-2">
            <input type="hidden" name="tab" value="projects">
            <input type="text" name="search" placeholder="Search project name..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-primary">Search</button>
        </form>
    </div>
</div>

<!-- Table -->
<div class="table-wrap">
    <!-- REVISION: Keep task descriptions readable; scroll on smaller screens. -->
        <table style="width: 100%; min-width: 850px; table-layout: auto;">
        <thead>
            <tr>
                <th>Project ID</th><th>Project Name</th><th>Client</th><th>Service</th>
                <th>Foreman</th><th>Team</th><th>Start Date</th><th>Expected End</th>
                <th>Progress</th><th>Status</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (count($projects) === 0): ?>
            <tr><td colspan="11" class="text-center py-5" style="color: var(--fr-muted);">No projects found.</td></tr>
        <?php else: ?>
            <?php foreach ($projects as $proj):
                $team_size = 1 + ($proj['employee_count'] ?? 0);
                $stmt_labor = $pdo->prepare("
                    SELECT labor_requirement
                    FROM site_inspections
                    WHERE project_id = ?
                      AND labor_requirement IS NOT NULL
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $stmt_labor->execute([$proj['id']]);
                $labor_requirement = $stmt_labor->fetchColumn();

                $assignedIds = array_map('intval', $project_employees[$proj['id']] ?? []);

                $availableEmployees = [];
                foreach ($employees as $e) {
                    $empId = (int)$e['id'];

                    if (in_array($empId, $assignedIds, true)) {
                        $availableEmployees[] = $e;
                        continue;
                    }

                    // Inactive employees remain visible only on their current projects.
                    if (strtolower((string)$e['status']) !== 'active') continue;

                    $busy = false;
                    foreach (($employeesBusyElsewhere[$empId] ?? []) as $pid) {
                        if ($pid !== (int)$proj['id']) {
                            $busy = true;
                            break;
                        }
                    }
                    if (!$busy) {
                        $availableEmployees[] = $e;
                    }
                }

                $badgeClass = $projectStatusClass($proj['status']);
                $badgeLabel = $projectStatusLabel($proj['status']);
            ?>
                <tr>
                    <td><strong><?= htmlspecialchars($proj['project_id']) ?></strong></td>
                    <td><?= htmlspecialchars($proj['project_name']) ?></td>
                    <td><?= htmlspecialchars($proj['client_name'] ?? '—') ?></td>
                    <td><?= ucfirst($proj['service_type']) ?></td>
                    <td><?= htmlspecialchars($proj['foreman_name'] ?? '—') ?></td>
                    <td><?= $team_size ?></td>
                    <td>
                        <?php if (!empty($proj['start_date'])): ?>
                            <?= htmlspecialchars(date('M d, Y', strtotime($proj['start_date']))) ?>
                        <?php else: ?>
                            <span class="muted" style="font-style: italic;">Not set</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= !empty($proj['expected_end_date'])
                            ? htmlspecialchars(date('M d, Y', strtotime($proj['expected_end_date'])))
                            : '<span class="muted">—</span>' ?>
                    </td>
                    <td style="min-width: 130px;">
                        <div class="d-flex align-items-center gap-2">
                            <div class="fr-progress flex-grow-1" style="max-width: 90px;">
                                <div style="width: <?= (int)$proj['progress'] ?>%;"></div>
                            </div>
                            <span style="font-weight: 600; font-size: 12px;"><?= (int)$proj['progress'] ?>%</span>
                        </div>
                    </td>
                    <td>
                        <span class="fr-badge <?= htmlspecialchars($badgeClass) ?>"><?= htmlspecialchars($badgeLabel) ?></span>
                    </td>
                    <td class="actions">
                        <div class="dropdown">
                            <button type="button" class="action-btn dropdown-toggle" data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-expanded="false" title="Actions">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a class="dropdown-item" href="admin.php?tab=projects&view=<?= (int)$proj['id'] ?>">
                                        <i class="bi bi-eye me-2"></i>View Details
                                    </a>
                                </li>
                                <li>
                                    <?php if ($proj['status'] !== 'completed'): ?>
                                        <a class="dropdown-item" href="admin.php?tab=projects&edit=<?= (int)$proj['id'] ?>">
                                            <i class="bi bi-pencil me-2"></i>Manage Project
                                        </a>
                                    <?php else: ?>
                                        <span class="dropdown-item disabled">
                                            <i class="bi bi-lock me-2"></i>Manage (Locked)
                                        </span>
                                    <?php endif; ?>
                                </li>
                                <?php if ($proj['status'] !== 'completed'): ?>
                                    <?php
                                    $progressOK  = ((int)$proj['progress'] >= 100);
                                    $paymentOK   = (isset($proj['payment_status'])
                                                    && strcasecmp((string)$proj['payment_status'], 'Fully Paid') === 0);
                                    $canComplete = $progressOK && $paymentOK;

                                    if (!$canComplete) {
                                        $reasons = [];
                                        if (!$progressOK) {
                                            $reasons[] = 'progress is ' . (int)$proj['progress'] . '%';
                                        }
                                        if (!$paymentOK) {
                                            $reasons[] = 'payment is ' . ($proj['payment_status'] ?: 'missing');
                                        }
                                        $disableTip = 'Cannot mark as complete — ' . implode(' · ', $reasons) . '.';
                                    }
                                    ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <?php if ($canComplete): ?>
                                            <form method="POST" action="admin.php?tab=projects" class="m-0">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="mark_complete" value="<?= (int)$proj['id'] ?>">
                                                <button type="submit" class="dropdown-item"
                                                        onclick="return confirm('Mark this project as completed? This will lock it from further edits (tasks, progress logs, attachments).');">
                                                    <i class="bi bi-check2-circle me-2"></i>Mark as Complete
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="dropdown-item disabled" title="<?= htmlspecialchars($disableTip) ?>">
                                                <i class="bi bi-lock me-2"></i>Mark as Complete
                                            </span>
                                        <?php endif; ?>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </td>
                </tr>

                <!-- Edit Modal -->
                <div id="editModal<?= $proj['id'] ?>" class="ad-modal">
                    <div class="ad-modal-content">
                        <button type="button" class="close" onclick="document.getElementById('editModal<?= $proj['id'] ?>').classList.remove('show')">&times;</button>
                        <h2>Manage Project – <?= htmlspecialchars($proj['project_id']) ?></h2>
                        <form method="POST" action="admin.php?tab=projects">
                            <?= csrf_field() ?>
                            <input type="hidden" name="edit_id" value="<?= $proj['id'] ?>">
                            <input type="hidden" name="edit_project" value="1">

                            <!-- Project Name is uneditable: rendered as a locked field -->
                            <div class="form-group">
                                <label>Project Name</label>
                                <input type="text"
                                       name="project_name"
                                       class="form-control locked-field"
                                       value="<?= htmlspecialchars($proj['project_name']) ?>"
                                       readonly
                                       tabindex="-1">
                                <small class="form-text">Project name is fixed at creation and cannot be changed.</small>
                            </div>

                            <input type="hidden" name="client_id" value="<?= (int)$proj['client_id'] ?>">
                            <input type="hidden" name="service_type" value="<?= htmlspecialchars($proj['service_type']) ?>">

                            <div class="form-row">
                                <div class="form-group"><label>Foreman</label>
                                    <select name="foreman_id">
                                        <option value="">— None —</option>
                                        <?php foreach ($foremen as $f): ?>
                                            <option value="<?= $f['id'] ?>" <?= $f['id'] == $proj['foreman_id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php
                                $startDateLocked = in_array(
                                    strtolower(trim((string)$proj['status'])),
                                    ['scheduled', 'active', 'completed'],
                                    true
                                );
                                
                                $todayManila = (new DateTimeImmutable(
                                    'today',
                                    new DateTimeZone('Asia/Manila')
                                ))->format('Y-m-d');
                                ?>
                                
                                <div class="form-group">
                                    <label>
                                        <?= $startDateLocked
                                            ? 'Confirmed Start Date'
                                            : 'Start Date / Tentative Before Downpayment' ?>
                                    </label>
                                
                                    <input type="date"
                                           name="start_date"
                                           class="form-control"
                                           value="<?= htmlspecialchars($proj['start_date'] ?? '') ?>"
                                           <?= $startDateLocked ? 'readonly' : '' ?>
                                           <?= !$startDateLocked &&
                                               (empty($proj['start_date']) ||
                                                $proj['start_date'] >= $todayManila)
                                               ? 'min="' . $todayManila . '"'
                                               : '' ?>>
                                
                                    <small class="form-text">
                                        <?php if ($startDateLocked): ?>
                                            The confirmed start date is locked.
                                        <?php else: ?>
                                            Before the confirmed 50% downpayment, this date is tentative.
                                            After payment, saving a valid date and personnel confirms
                                            the schedule and locks the start date.
                                        <?php endif; ?>
                                        <?php if (strtolower(trim((string)$proj['status'])) === 'scheduled'): ?>
                                            Click Start Project when actual work begins.
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Expected End Date</label>
                                <input type="date"
                                       name="expected_end_date"
                                       class="form-control"
                                       value="<?= htmlspecialchars($proj['expected_end_date'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label>
                                    Employees
                                    <?php if ($labor_requirement !== null && $labor_requirement !== false && (int)$labor_requirement > 0): ?>
                                        <span style="font-weight: 600; color: var(--fr-red); margin-left: 4px;">(<?= (int)$labor_requirement ?> Required)</span>
                                    <?php endif; ?>
                                </label>
                                <?php $hiddenCount = count($employees) - count($availableEmployees); ?>
                                <?php if ($hiddenCount > 0): ?>
                                    <div class="form-text" style="margin-bottom: 6px;">
                                        <?= (int)$hiddenCount ?>
                                        <?= $hiddenCount === 1 ? 'employee is' : 'employees are' ?>
                                        hidden because they are already assigned to another in-flight project.
                                    </div>
                                <?php endif; ?>
                                <div class="p-3 rounded-3" style="max-height: 200px; overflow-y: auto; background: var(--fr-bg); border: 1px solid var(--fr-border);">
                                    <div class="row g-2">
                                        <?php
                                        $assigned_emps = array_map('intval', $project_employees[$proj['id']] ?? []);
                                        foreach ($availableEmployees as $e):
                                            $checked = in_array((int)$e['id'], $assigned_emps, true) ? 'checked' : '';
                                        ?>
                                            <div class="col-md-6">
                                                <label class="d-flex align-items-center gap-2 p-2 rounded-2" style="cursor: pointer;">
                                                    <input type="checkbox" name="employees[]" value="<?= $e['id'] ?>" <?= $checked ?>>
                                                    <?= htmlspecialchars($e['full_name']) ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                                <?php
                                $projectMessages = [
                                    'date_locked' =>
                                        'The confirmed start date is locked.',
                                    'invalid_start_date' =>
                                        'Please choose a valid start date today or later.',
                                    'employee_conflict' =>
                                        'An employee is already assigned to another ongoing project. Refresh the page and select another employee.',
                                    'start_requirements' =>
                                        'Starting requires a Scheduled project, confirmed downpayment, '
                                        . 'assigned foreman and employees, and a start date today or earlier.',
                                ];
                                
                                $projectMessage = $projectMessages[$_GET['msg'] ?? ''] ?? null;
                                ?>
                                
                                <?php if ($projectMessage): ?>
                                    <div class="alert alert-warning">
                                        <?= htmlspecialchars($projectMessage) ?>
                                    </div>
                                <?php endif; ?>
                            <div class="form-actions">
                                <?php if (strtolower(trim((string)$proj['status'])) === 'scheduled'): ?>
                                    <button type="submit" name="start_project" value="1" class="btn btn-primary">
                                        Start Project
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('editModal<?= $proj['id'] ?>').classList.remove('show')">Cancel</button>
                                <button type="submit" name="edit_project" class="btn btn-primary">Update Project</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Detail Modal -->
<div id="detailModal" class="ad-modal">
    <div class="ad-modal-content" style="max-width: 900px;">
        <button type="button" class="close" onclick="document.getElementById('detailModal').classList.remove('show')">&times;</button>

        <?php if ($detailProject): ?>
            <h2>Project Details – <?= htmlspecialchars($detailProject['project_id']) ?></h2>

            <div class="row g-2 mb-4">
                <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--fr-muted); margin-bottom: 4px;">Project Name</div><div style="font-weight: 600;"><?= htmlspecialchars($detailProject['project_name']) ?></div></div></div>
                <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--fr-muted); margin-bottom: 4px;">Client</div><div style="font-weight: 600;"><?= htmlspecialchars($detailProject['client_name'] ?? '—') ?></div></div></div>
                <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--fr-muted); margin-bottom: 4px;">Service</div><div style="font-weight: 600;"><?= ucfirst($detailProject['service_type']) ?></div></div></div>
                <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--fr-muted); margin-bottom: 4px;">Foreman</div><div style="font-weight: 600;"><?= htmlspecialchars($detailProject['foreman_name'] ?? '—') ?></div></div></div>
                <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--fr-muted); margin-bottom: 4px;">Start Date</div><div style="font-weight: 600;"><?= !empty($detailProject['start_date']) ? date('M d, Y', strtotime($detailProject['start_date'])) : '<em class="muted">Not set</em>' ?></div></div></div>
                <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--fr-muted); margin-bottom: 4px;">Expected End</div><div style="font-weight: 600;"><?= !empty($detailProject['expected_end_date']) ? date('M d, Y', strtotime($detailProject['expected_end_date'])) : '—' ?></div></div></div>
                <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--fr-muted); margin-bottom: 4px;">Status</div><span class="fr-badge <?= htmlspecialchars($projectStatusClass($detailProject['status'])) ?>"><?= htmlspecialchars($projectStatusLabel($detailProject['status'])) ?></span></div></div>
                <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--fr-muted); margin-bottom: 4px;">Progress</div><div style="font-weight: 600;"><?= $detailProject['progress'] ?>%</div></div></div>
                <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--fr-muted); margin-bottom: 4px;">Completed At</div><div style="font-weight: 600;"><?= !empty($detailProject['completed_at']) ? date('M d, Y g:i A', strtotime($detailProject['completed_at'])) : '—' ?></div></div></div>
            </div>

            <div class="fr-progress mb-4" style="height: 10px;">
                <div style="width: <?= $detailProject['progress'] ?>%;"></div>
            </div>

            <?php if ($detailInspection): ?>
                <div class="p-3 rounded-3 mb-4" style="background: var(--fr-red-soft); border-left: 4px solid var(--fr-red);">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2" style="font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.2px; color: var(--fr-red);">
                            <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--fr-red);"></span>
                            Site Inspection Data
                        </div>
                        <div class="fr-badge followup"><?= htmlspecialchars($detailInspection['inspection_id']) ?> · <?= htmlspecialchars($detailInspection['status']) ?></div>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-4"><div class="p-2"><div style="font-size: 10px; color: var(--fr-muted); font-weight: 700; text-transform: uppercase;">Product Category</div><div style="font-weight: 600;"><?= htmlspecialchars(ucfirst($detailInspection['product_category'] ?? '—')) ?></div></div></div>
                        <div class="col-md-4"><div class="p-2"><div style="font-size: 10px; color: var(--fr-muted); font-weight: 700; text-transform: uppercase;">Roofing Category</div><div style="font-weight: 600;"><?= htmlspecialchars($detailInspection['roofing_category'] ?? '—') ?></div></div></div>
                        <div class="col-md-4"><div class="p-2"><div style="font-size: 10px; color: var(--fr-muted); font-weight: 700; text-transform: uppercase;">Product / Profile</div><div style="font-weight: 600;"><?= htmlspecialchars($detailInspection['roofing_product'] ?? '—') ?></div></div></div>
                        <div class="col-md-4"><div class="p-2"><div style="font-size: 10px; color: var(--fr-muted); font-weight: 700; text-transform: uppercase;">Roof Area</div><div style="font-weight: 600;"><?= number_format((float)$detailInspection['roof_area'], 2) ?> sqm</div></div></div>
                        <div class="col-md-4"><div class="p-2"><div style="font-size: 10px; color: var(--fr-muted); font-weight: 700; text-transform: uppercase;">Est. Quantity</div><div style="font-weight: 600;"><?= (int)$detailInspection['estimated_qty'] ?> sheets</div></div></div>
                        <div class="col-md-4"><div class="p-2"><div style="font-size: 10px; color: var(--fr-muted); font-weight: 700; text-transform: uppercase;">Labor Requirement</div><div style="font-weight: 600;"><?= (int)$detailInspection['labor_requirement'] ?> workers</div></div></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($detailProject['status'] === 'completed'): ?>
                <!-- REVISION: Safe retry for old completed projects or failed email delivery. -->
                <form method="POST" action="admin.php?tab=projects" class="mb-3">
                    <?= csrf_field() ?>
                    <button type="submit" name="send_feedback_invitation"
                            value="<?= (int)$detailProject['id'] ?>"
                            class="btn btn-outline-secondary btn-sm">
                        Send / Retry Feedback Email
                    </button>
                </form>
                <div class="alert alert-secondary d-flex align-items-center mb-3"
                     style="background:#f3f4f6;border-color:#e5e7eb;color:#374151;">
                    <i class="bi bi-lock me-2"></i>
                    <div>
                        <strong>This project is completed and locked.</strong>
                        Tasks, progress logs, and attachments are view-only.
                    </div>
                </div>
            <?php endif; ?>

            <h3 class="fr-section-title">Task Checklist</h3>
            <p style="color: var(--fr-muted); font-size: 13px;">Progress is automatically calculated from completed tasks.</p>
            <?php
            $tasks = getProjectTasks($pdo, $detailProject['id']);
            $total = count($tasks);
            $done = 0;
            foreach ($tasks as $t) if ($t['is_completed']) $done++;
            $progress = ($total > 0) ? round(($done / $total) * 100) : 0;
            ?>
            <div class="mb-3">
                <div style="font-size: 13px; color: var(--fr-text-soft);"><?= $done ?> / <?= $total ?> tasks completed</div>
                <div class="fr-progress mt-2"><div style="width: <?= $progress ?>%;"></div></div>
            </div>

            <div class="table-wrap mb-3" style="box-shadow: none;">
                <table>
                    <thead>
                        <tr>
                            <th style="min-width: 300px; width: 45%;">Task</th>
                            <th style="width: 220px;">Assigned To</th>
                            <th style="width: 100px;">Status</th>
                            <th style="width: 90px;">Photos</th>
                            <th style="width: 60px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($tasks)): ?>
                        <tr><td colspan="5" class="text-center py-3" style="color: var(--fr-muted);">No tasks added yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($tasks as $task):
                            $cnt = count($detailAttachmentsByTask[(int)$task['id']] ?? []);
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($task['description']) ?></td>
                                <td>
                                    <?php if (empty($task['assignees'])): ?>
                                        <span style="color: var(--fr-muted); font-style: italic;">Unassigned</span>
                                    <?php else: ?>
                                        <?php foreach ($task['assignees'] as $a): ?>
                                            <span class="fr-badge followup" style="margin: 2px 4px 2px 0; font-weight: 600;">
                                                <?= htmlspecialchars($a['name']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($detailProject['status'] === 'completed'): ?>
                                        <span class="btn btn-sm <?= $task['is_completed'] ? 'btn-primary' : 'btn-outline-secondary' ?>" style="cursor:default;">
                                            <?= $task['is_completed'] ? '<i class="bi bi-check2 me-1"></i>Done' : 'Pending' ?>
                                        </span>
                                    <?php else: ?>
                                        <form method="POST" action="admin.php?tab=projects" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="toggle_task" value="<?= $task['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $task['is_completed'] ? 'btn-primary' : 'btn-outline-secondary' ?>">
                                                <?= $task['is_completed'] ? '<i class="bi bi-check2 me-1"></i>Done' : 'Pending' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary admin-attachment-open-btn"
                                            data-task-id="<?= (int)$task['id'] ?>"
                                            data-task-desc="<?= htmlspecialchars($task['description']) ?>"
                                            <?= $cnt === 0 ? 'disabled' : '' ?>>
                                        <i class="bi bi-image"></i>
                                        <span class="ms-1"><?= $cnt ?></span>
                                    </button>
                                </td>
                                <td>
                                    <?php if ($detailProject['status'] !== 'completed'): ?>
                                        <form method="POST" action="admin.php?tab=projects" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="delete_task" value="<?= $task['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger border-0" onclick="return confirm('Delete this task?')"><i class="bi bi-trash"></i></button>
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
                <form method="POST" action="admin.php?tab=projects" class="mb-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="project_id" value="<?= $detailProject['id'] ?>">
                    <div class="d-flex gap-2">
                        <input type="text" name="task_description" class="form-control" placeholder="Enter new task..." required>
                        <button type="submit" name="add_task" class="btn btn-primary text-nowrap">Add Task</button>
                    </div>
                </form>
            <?php endif; ?>

            <h3 class="fr-section-title">Progress Log</h3>
            <div class="table-wrap" style="box-shadow: none; max-height: 330px; overflow-y: auto;">
                <table>
                    <thead style="position: sticky; top: 0;">
                        <tr><th>Date</th><th>Progress</th><th>Work Completed</th><th>Issues</th><th>Remarks</th></tr>
                    </thead>
                    <tbody>
                    <?php if (count($detailLogs) === 0): ?>
                        <tr><td colspan="5" class="text-center py-3" style="color: var(--fr-muted);">No progress updates yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($detailLogs as $log): ?>
                            <tr>
                                <td><?= date('M d, Y H:i', strtotime($log['created_at'])) ?></td>
                                <td><strong><?= $log['progress'] ?>%</strong></td>
                                <td><?= nl2br(htmlspecialchars($log['work_completed'])) ?></td>
                                <td><?= nl2br(htmlspecialchars($log['issues_encountered'])) ?></td>
                                <td><?= nl2br(htmlspecialchars($log['remarks'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="color: var(--fr-muted);">Project not found.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Auto-open scripts -->
<?php if (isset($_GET['edit']) && $editProject): ?>
<script>document.addEventListener('DOMContentLoaded', function () { const m = document.getElementById('editModal<?= (int)$_GET['edit'] ?>'); if (m) m.classList.add('show'); });</script>
<?php endif; ?>
<?php if (isset($_GET['view']) && $detailProject): ?>
<script>document.addEventListener('DOMContentLoaded', function () { const m = document.getElementById('detailModal'); if (m) m.classList.add('show'); });</script>
<?php endif; ?>

<!-- Read-only attachment gallery -->
<div class="modal fade" id="adminAttachmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Task Photos</h5>
                    <div class="admin-attachment-task-desc text-muted small"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="admin-attachment-empty text-center text-muted py-5 d-none">
                    No photos attached to this task.
                </div>
                <div class="admin-attachment-stage position-relative rounded-3 mb-3 d-none" style="background:#111; overflow:hidden;">
                    <img class="admin-attachment-main" src="" alt="" style="width:100%; display:block; max-height:65vh; object-fit:contain; background:#111;">
                    <button type="button" class="admin-attachment-prev btn btn-dark position-absolute top-50 start-0 translate-middle-y ms-2" aria-label="Previous">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <button type="button" class="admin-attachment-next btn btn-dark position-absolute top-50 end-0 translate-middle-y me-2" aria-label="Next">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                    <div class="admin-attachment-meta position-absolute bottom-0 start-0 end-0 p-2 small text-white-50" style="background: linear-gradient(to top, rgba(0,0,0,.7), transparent);"></div>
                </div>
                <div class="admin-attachment-strip d-flex gap-2 overflow-auto d-none" style="padding-bottom: 4px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
window.__adminTaskAttachments = <?= json_encode($detailAttachmentsByTask, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('adminAttachmentModal');
    if (!modalEl) return;

    const modal = new bootstrap.Modal(modalEl);

    const descEl   = modalEl.querySelector('.admin-attachment-task-desc');
    const emptyEl  = modalEl.querySelector('.admin-attachment-empty');
    const stageEl  = modalEl.querySelector('.admin-attachment-stage');
    const stripEl  = modalEl.querySelector('.admin-attachment-strip');
    const mainImg  = modalEl.querySelector('.admin-attachment-main');
    const metaEl   = modalEl.querySelector('.admin-attachment-meta');
    const prevBtn  = modalEl.querySelector('.admin-attachment-prev');
    const nextBtn  = modalEl.querySelector('.admin-attachment-next');

    let items = [];
    let index = 0;

    function formatBytes(n) {
        if (!n) return '0 B';
        const u = ['B','KB','MB','GB'];
        let i = 0, v = n;
        while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
        return v.toFixed(v >= 10 || i === 0 ? 0 : 1) + ' ' + u[i];
    }

    function render() {
        if (!items.length) {
            stageEl.classList.add('d-none');
            stripEl.classList.add('d-none');
            emptyEl.classList.remove('d-none');
            return;
        }
        emptyEl.classList.add('d-none');
        stageEl.classList.remove('d-none');
        stripEl.classList.remove('d-none');

        if (index < 0) index = 0;
        if (index >= items.length) index = items.length - 1;

        const cur = items[index];
        mainImg.src = cur.url;
        mainImg.alt = cur.original_name;

        metaEl.textContent =
            (index + 1) + ' / ' + items.length +
            ' · ' + cur.original_name +
            (cur.uploaded_by_name ? ' · uploaded by ' + cur.uploaded_by_name : '') +
            ' · ' + formatBytes(cur.file_size);

        prevBtn.style.display = items.length > 1 ? '' : 'none';
        nextBtn.style.display = items.length > 1 ? '' : 'none';

        stripEl.innerHTML = '';
        items.forEach((a, i) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn p-0 border rounded-2 flex-shrink-0';
            btn.style.width = '72px';
            btn.style.height = '72px';
            btn.style.overflow = 'hidden';
            if (i === index) { btn.classList.add('border-primary'); btn.style.borderWidth = '2px'; }
            btn.innerHTML = '<img src="' + a.url + '" alt="" style="width:100%;height:100%;object-fit:cover;display:block;">';
            btn.addEventListener('click', () => { index = i; render(); });
            stripEl.appendChild(btn);
        });
    }

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.admin-attachment-open-btn');
        if (!btn || btn.disabled) return;
        const taskId = parseInt(btn.dataset.taskId, 10);
        const desc   = btn.dataset.taskDesc || '';
        items = (window.__adminTaskAttachments && window.__adminTaskAttachments[taskId]) || [];
        index = 0;
        descEl.textContent = desc;
        render();
        modal.show();
    });

    prevBtn.addEventListener('click', () => { index--; render(); });
    nextBtn.addEventListener('click', () => { index++; render(); });

    document.addEventListener('keydown', function (e) {
        if (!modalEl.classList.contains('show')) return;
        if (e.key === 'ArrowLeft')  { index--; render(); }
        if (e.key === 'ArrowRight') { index++; render(); }
    });
});
</script>
</div>