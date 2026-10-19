<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once 'config.php';
require_once __DIR__ . '/foreman_tabs/_quotation_section.php';
requireLogin();

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { ob_end_flush(); }
        echo '<pre style="background:#111;color:#f88;padding:12px;margin:12px;border-radius:6px;">'
           . htmlspecialchars($err['message'])
           . "\n" . htmlspecialchars($err['file']) . ':' . (int)$err['line']
           . '</pre>';
    }
});


// ============================================================================
// ACCESS CONTROL
// ============================================================================

if (($_SESSION['role'] ?? '') !== 'foreman') {
    die('Access denied.');
}


// ============================================================================
// LOAD COMPANY SETTINGS
// ============================================================================

$stmt = $pdo->query("
    SELECT *
    FROM system_settings
    ORDER BY id ASC
    LIMIT 1
");

$company_settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company_settings) {
    $company_settings = [
        'company_name' => 'Eng Roofing',
        'company_subtitle' => 'Supply and Installation',
        'company_logo' => '',
        'standard_start_time' => '08:00:00',
        'late_threshold' => '00:15:00'
    ];
}


// ============================================================================
// FACIAL-RECOGNITION DESCRIPTOR ENDPOINT
//
// f_attendance.php calls:
// foreman.php?face_employee=email@example.com
//
// Only returns the enrolled face descriptor.
// ============================================================================

if (isset($_GET['face_employee'])) {

    header('Content-Type: application/json; charset=utf-8');

    $email = trim($_GET['face_employee']);
    $foreman_id = (int)($_SESSION['user_id'] ?? 0);

    if ($email === '' || $foreman_id <= 0) {
        echo json_encode([
            'success' => false,
            'face_descriptor' => null
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT u.face_descriptor
        FROM users u
        INNER JOIN project_employees pe
            ON pe.employee_id = u.id
        INNER JOIN projects p
            ON p.id = pe.project_id
           AND p.foreman_id = ?
           AND p.status = 'active'
        WHERE u.email = ?
          AND u.role = 'employee'
          AND u.status = 'active'
        LIMIT 1
    ");

    $stmt->execute([$foreman_id, $email]);

    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee || empty($employee['face_descriptor'])) {
        echo json_encode([
            'success' => false,
            'face_descriptor' => null
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'face_descriptor' => $employee['face_descriptor']
    ]);

    exit;
}

// ============================================================================
// FACIAL-RECOGNITION: ALL EMPLOYEES FOR A PROJECT
//
// f_attendance.php calls:
// foreman.php?face_employees_for_project=5
//
// Returns every employee assigned to the given project who has at least one
// enrolled face descriptor. Used by the foreman's attendance tab to auto-
// identify an employee from a single photo.
// ============================================================================

if (isset($_GET['face_employees_for_project'])) {

    header('Content-Type: application/json; charset=utf-8');

    $foreman_id = (int)($_SESSION['user_id'] ?? 0);
    $project_id = (int)$_GET['face_employees_for_project'];

    if ($foreman_id <= 0 || $project_id <= 0) {
        echo json_encode(['success' => false, 'employees' => []]);
        exit;
    }

    // The project must belong to this foreman and be active.
    $stmt = $pdo->prepare("
        SELECT id FROM projects
        WHERE id = ? AND foreman_id = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$project_id, $foreman_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'employees' => []]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT
            u.id,
            u.full_name,
            u.email,
            u.employee_id,
            u.face_descriptor
        FROM users u
        INNER JOIN project_employees pe
            ON pe.employee_id = u.id
        WHERE pe.project_id = ?
          AND u.role = 'employee'
          AND u.status = 'active'
          AND u.face_descriptor IS NOT NULL
          AND u.face_descriptor <> ''
        ORDER BY u.full_name ASC
    ");
    $stmt->execute([$project_id]);

    $employees = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $employees[] = [
            'id'              => (int)$row['id'],
            'full_name'       => $row['full_name'],
            'email'           => $row['email'],
            'employee_id'     => $row['employee_id'],
            'face_descriptor' => $row['face_descriptor'],
        ];
    }

    echo json_encode(['success' => true, 'employees' => $employees]);
    exit;
}

// ============================================================================
// AJAX: TASK ACTIONS
//
// Used by f_projects.php to add / toggle / delete project tasks without a
// full page reload. Returns JSON.
// ============================================================================

if (isset($_GET['ajax']) && $_GET['ajax'] === 'task') {

    header('Content-Type: application/json; charset=utf-8');

    $foreman_id = (int)($_SESSION['user_id'] ?? 0);

    if ($foreman_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid foreman account.']);
        exit;
    }

    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'CSRF token validation failed.']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    /**
     * Reload the project's task counts and current progress.
     * Returns [total, done, progress].
     */
    $reloadProjectStats = function (PDO $pdo, int $projectId): array {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total, SUM(is_completed) AS done
            FROM project_tasks
            WHERE project_id = ?
        ");
        $stmt->execute([$projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $total = (int)($row['total'] ?? 0);
        $done  = (int)($row['done']  ?? 0);

        $stmt = $pdo->prepare("SELECT progress FROM projects WHERE id = ? LIMIT 1");
        $stmt->execute([$projectId]);
        $progress = (int)$stmt->fetchColumn();

        return [$total, $done, $progress];
    };
    
    /**
     * Validate that an employee is assigned to the given project.
     * Returns the employee's full_name and employee_code on success,
     * or null if the employee is not on this project.
     */
    $resolveAssignedEmployee = function (PDO $pdo, int $projectId, int $employeeId) {
        if ($employeeId <= 0) return null;

        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.employee_id AS employee_code
            FROM project_employees pe
            INNER JOIN users u ON u.id = pe.employee_id
            WHERE pe.project_id = ?
              AND pe.employee_id = ?
              AND u.role = 'employee'
              AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$projectId, $employeeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    };


    // ---------------------------------------------------------------------
    // ADD TASK
    // ---------------------------------------------------------------------
    if ($action === 'add') {

        $project_id  = (int)($_POST['project_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');

        if ($project_id <= 0 || $description === '') {
            echo json_encode(['success' => false, 'message' => 'Missing project or description.']);
            exit;
        }

        // Ownership + completion check.
        $stmt = $pdo->prepare("
            SELECT id, status FROM projects
            WHERE id = ? AND foreman_id = ?
            LIMIT 1
        ");
        $stmt->execute([$project_id, $foreman_id]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$project) {
            echo json_encode(['success' => false, 'message' => 'Project not found or access denied.']);
            exit;
        }

        if ($project['status'] === 'completed') {
            echo json_encode(['success' => false, 'message' => 'This project is completed and locked.']);
            exit;
        }
        

        $task_id = (int)addProjectTask($pdo, $project_id, $description);

        [$total, $done, $progress] = $reloadProjectStats($pdo, $project_id);

        echo json_encode([
            'success' => true,
            'action'  => 'add',
            'task' => [
                'id'          => $task_id,
                'description' => $description,
                'is_completed'=> 0,
                'assignees'   => [],
            ],
            'total'    => $total,
            'done'     => $done,
            'progress' => $progress
        ]);
        exit;
    }


    // ---------------------------------------------------------------------
    // TOGGLE TASK
    // ---------------------------------------------------------------------
    if ($action === 'toggle') {

        $task_id = (int)($_POST['task_id'] ?? 0);

        if ($task_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid task.']);
            exit;
        }

        // Ownership + completion check.
        $stmt = $pdo->prepare("
            SELECT pt.project_id, p.status
            FROM project_tasks pt
            INNER JOIN projects p ON p.id = pt.project_id
            WHERE pt.id = ? AND p.foreman_id = ?
            LIMIT 1
        ");
        $stmt->execute([$task_id, $foreman_id]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            echo json_encode(['success' => false, 'message' => 'Task not found or access denied.']);
            exit;
        }

        if ($task['status'] === 'completed') {
            echo json_encode(['success' => false, 'message' => 'This project is completed and locked.']);
            exit;
        }

        toggleProjectTask($pdo, $task_id);

        $project_id = (int)$task['project_id'];

        $stmt = $pdo->prepare("SELECT is_completed FROM project_tasks WHERE id = ? LIMIT 1");
        $stmt->execute([$task_id]);
        $is_completed = (int)$stmt->fetchColumn();

        [$total, $done, $progress] = $reloadProjectStats($pdo, $project_id);

        echo json_encode([
            'success'      => true,
            'action'       => 'toggle',
            'task_id'      => $task_id,
            'is_completed' => $is_completed,
            'total'        => $total,
            'done'         => $done,
            'progress'     => $progress
        ]);
        exit;
    }

    // ---------------------------------------------------------------------
    // ASSIGN EMPLOYEE
    // ---------------------------------------------------------------------
    if ($action === 'assign') {

        $task_id      = (int)($_POST['task_id'] ?? 0);
        $employee_ids = $_POST['employee_ids'] ?? [];

        if (!is_array($employee_ids)) $employee_ids = [];
        $employee_ids = array_values(array_unique(
            array_filter(array_map('intval', $employee_ids))
        ));

        if ($task_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid task.']);
            exit;
        }

        // Ownership + completion check.
        $stmt = $pdo->prepare("
            SELECT pt.project_id, p.status
            FROM project_tasks pt
            INNER JOIN projects p ON p.id = pt.project_id
            WHERE pt.id = ? AND p.foreman_id = ?
            LIMIT 1
        ");
        $stmt->execute([$task_id, $foreman_id]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            echo json_encode(['success' => false, 'message' => 'Task not found or access denied.']);
            exit;
        }

        if ($task['status'] === 'completed') {
            echo json_encode(['success' => false, 'message' => 'This project is completed and locked.']);
            exit;
        }

        // Validate every employee belongs to this project.
        $validAssignees = [];

        if (!empty($employee_ids)) {
            $ph = implode(',', array_fill(0, count($employee_ids), '?'));
            $stmt = $pdo->prepare("
                SELECT u.id, u.full_name, u.employee_id AS employee_code
                FROM project_employees pe
                INNER JOIN users u ON u.id = pe.employee_id
                WHERE pe.project_id = ?
                  AND pe.employee_id IN ($ph)
                  AND u.role = 'employee'
                  AND u.status = 'active'
                ORDER BY u.full_name ASC
            ");
            $stmt->execute(array_merge([(int)$task['project_id']], $employee_ids));

            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $validAssignees[] = [
                    'id'   => (int)$r['id'],
                    'name' => $r['full_name'],
                    'code' => $r['employee_code'],
                ];
            }

            if (count($validAssignees) !== count($employee_ids)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'One or more employees are not assigned to this project.',
                ]);
                exit;
            }
        }

        setTaskAssignees($pdo, $task_id, array_column($validAssignees, 'id'));

        echo json_encode([
            'success'   => true,
            'action'    => 'assign',
            'task_id'   => $task_id,
            'assignees' => $validAssignees,
        ]);
        exit;
    }

    // ---------------------------------------------------------------------
    // DELETE TASK
    // ---------------------------------------------------------------------
    if ($action === 'delete') {

        $task_id = (int)($_POST['task_id'] ?? 0);

        if ($task_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid task.']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT pt.project_id, p.status
            FROM project_tasks pt
            INNER JOIN projects p ON p.id = pt.project_id
            WHERE pt.id = ? AND p.foreman_id = ?
            LIMIT 1
        ");
        $stmt->execute([$task_id, $foreman_id]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            echo json_encode(['success' => false, 'message' => 'Task not found or access denied.']);
            exit;
        }

        if ($task['status'] === 'completed') {
            echo json_encode(['success' => false, 'message' => 'This project is completed and locked.']);
            exit;
        }

        $project_id = (int)$task['project_id'];

        deleteProjectTask($pdo, $task_id);

        [$total, $done, $progress] = $reloadProjectStats($pdo, $project_id);

        echo json_encode([
            'success'  => true,
            'action'   => 'delete',
            'task_id'  => $task_id,
            'total'    => $total,
            'done'     => $done,
            'progress' => $progress
        ]);
        exit;
    }


    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ============================================================================
// AJAX: TASK ATTACHMENT ACTIONS
//
// Used by foreman_tabs/f_projects.php for upload / list / delete.
// Returns JSON.
// ============================================================================

if (isset($_GET['ajax']) && $_GET['ajax'] === 'task_attachment') {

    header('Content-Type: application/json; charset=utf-8');

    $foreman_id = (int)($_SESSION['user_id'] ?? 0);

    if ($foreman_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid foreman account.']);
        exit;
    }

    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'CSRF token validation failed.']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ---------------------------------------------------------------------
    // LIST
    // ---------------------------------------------------------------------
    if ($action === 'list') {

        $task_id = (int)($_POST['task_id'] ?? 0);
        if ($task_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid task.']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT pt.id FROM project_tasks pt
            INNER JOIN projects p ON p.id = pt.project_id
            WHERE pt.id = ? AND p.foreman_id = ?
            LIMIT 1
        ");
        $stmt->execute([$task_id, $foreman_id]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Task not found or access denied.']);
            exit;
        }

        $rows = getTaskAttachments($pdo, $task_id);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'               => (int)$r['id'],
                'url'              => 'task_attachment.php?id=' . (int)$r['id'] . '&v=' . urlencode($r['stored_name']),
                'original_name'    => $r['original_name'],
                'uploaded_by_name' => $r['uploaded_by_name'] ?? '',
                'file_size'        => (int)$r['file_size'],
                'uploaded_at'      => $r['uploaded_at'],
            ];
        }

        echo json_encode(['success' => true, 'attachments' => $out]);
        exit;
    }

    // ---------------------------------------------------------------------
    // UPLOAD
    // ---------------------------------------------------------------------
    if ($action === 'upload') {

        $task_id = (int)($_POST['task_id'] ?? 0);
        if ($task_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid task.']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT pt.id, p.status
            FROM project_tasks pt
            INNER JOIN projects p ON p.id = pt.project_id
            WHERE pt.id = ? AND p.foreman_id = ?
            LIMIT 1
        ");
        $stmt->execute([$task_id, $foreman_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Task not found or access denied.']);
            exit;
        }
        if ($row['status'] === 'completed') {
            echo json_encode(['success' => false, 'message' => 'This project is completed and locked.']);
            exit;
        }

        if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
            echo json_encode(['success' => false, 'message' => 'No file received.']);
            exit;
        }

        try {
            $row = addTaskAttachment($pdo, $task_id, $_FILES['image'], $foreman_id);
            echo json_encode([
                'success'    => true,
                'action'     => 'upload',
                'attachment' => [
                    'id'               => (int)$row['id'],
                    'url'              => 'task_attachment.php?id=' . (int)$row['id'] . '&v=' . urlencode($row['stored_name']),
                    'original_name'    => $row['original_name'],
                    'uploaded_by_name' => $row['uploaded_by_name'] ?? '',
                    'file_size'        => (int)$row['file_size'],
                    'uploaded_at'      => $row['uploaded_at'],
                ],
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ---------------------------------------------------------------------
    // DELETE
    // ---------------------------------------------------------------------
    if ($action === 'delete') {

        $attachment_id = (int)($_POST['attachment_id'] ?? 0);
        if ($attachment_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid attachment.']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT a.id, a.task_id, p.status
            FROM project_task_attachments a
            INNER JOIN project_tasks pt ON pt.id = a.task_id
            INNER JOIN projects p ON p.id = pt.project_id
            WHERE a.id = ? AND p.foreman_id = ?
            LIMIT 1
        ");
        $stmt->execute([$attachment_id, $foreman_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Attachment not found or access denied.']);
            exit;
        }
        if ($row['status'] === 'completed') {
            echo json_encode(['success' => false, 'message' => 'This project is completed and locked.']);
            exit;
        }

        try {
            deleteTaskAttachment($pdo, $attachment_id);
            echo json_encode([
                'success'       => true,
                'action'        => 'delete',
                'attachment_id' => $attachment_id,
                'task_id'       => (int)$row['task_id'],
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ============================================================================
// AJAX: SITE INSPECTION ATTACHMENT ACTIONS
//
// Used by foreman_tabs/f_site_inspection.php for upload / list / delete.
// Returns JSON.
// ============================================================================

if (isset($_GET['ajax']) && $_GET['ajax'] === 'inspection_attachment') {

    header('Content-Type: application/json; charset=utf-8');

    $foreman_id = (int)($_SESSION['user_id'] ?? 0);

    if ($foreman_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid foreman account.']);
        exit;
    }

    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'CSRF token validation failed.']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ---------------------------------------------------------------------
    // LIST
    // ---------------------------------------------------------------------
    if ($action === 'list') {

        $inspection_id = (int)($_POST['inspection_id'] ?? 0);
        if ($inspection_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid inspection.']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT id FROM site_inspections
            WHERE id = ? AND inspector_id = ?
            LIMIT 1
        ");
        $stmt->execute([$inspection_id, $foreman_id]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Inspection not found or access denied.']);
            exit;
        }

        $rows = getInspectionAttachments($pdo, $inspection_id);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'               => (int)$r['id'],
                'url'              => 'inspection_attachment.php?id=' . (int)$r['id'] . '&v=' . urlencode($r['stored_name']),
                'original_name'    => $r['original_name'],
                'uploaded_by_name' => $r['uploaded_by_name'] ?? '',
                'file_size'        => (int)$r['file_size'],
                'uploaded_at'      => $r['uploaded_at'],
            ];
        }

        echo json_encode(['success' => true, 'attachments' => $out]);
        exit;
    }

    // ---------------------------------------------------------------------
    // UPLOAD
    // ---------------------------------------------------------------------
    if ($action === 'upload') {

        $inspection_id = (int)($_POST['inspection_id'] ?? 0);
        if ($inspection_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid inspection.']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT id FROM site_inspections
            WHERE id = ? AND inspector_id = ?
            LIMIT 1
        ");
        $stmt->execute([$inspection_id, $foreman_id]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Inspection not found or access denied.']);
            exit;
        }

        if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
            echo json_encode(['success' => false, 'message' => 'No file received.']);
            exit;
        }

        try {
            $row = addInspectionAttachment($pdo, $inspection_id, $_FILES['image'], $foreman_id);
            echo json_encode([
                'success'    => true,
                'action'     => 'upload',
                'attachment' => [
                    'id'               => (int)$row['id'],
                    'url'              => 'inspection_attachment.php?id=' . (int)$row['id'] . '&v=' . urlencode($row['stored_name']),
                    'original_name'    => $row['original_name'],
                    'uploaded_by_name' => $row['uploaded_by_name'] ?? '',
                    'file_size'        => (int)$row['file_size'],
                    'uploaded_at'      => $row['uploaded_at'],
                ],
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ---------------------------------------------------------------------
    // DELETE
    // ---------------------------------------------------------------------
    if ($action === 'delete') {

        $attachment_id = (int)($_POST['attachment_id'] ?? 0);
        if ($attachment_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid attachment.']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT a.id, a.inspection_id
            FROM site_inspection_attachments a
            INNER JOIN site_inspections si ON si.id = a.inspection_id
            WHERE a.id = ? AND si.inspector_id = ?
            LIMIT 1
        ");
        $stmt->execute([$attachment_id, $foreman_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Attachment not found or access denied.']);
            exit;
        }

        try {
            deleteInspectionAttachment($pdo, $attachment_id);
            echo json_encode([
                'success'       => true,
                'action'        => 'delete',
                'attachment_id' => $attachment_id,
                'inspection_id' => (int)$row['inspection_id'],
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ============================================================================
// AJAX: ADD QUOTATION
//
// The foreman's Site Inspection modal posts here when the "Add Quotation"
// button is clicked. Creates the quotation server-side and returns the
// full re-rendered quotation section HTML so the modal can swap it in
// without a page reload.
// ============================================================================

if (isset($_GET['ajax']) && $_GET['ajax'] === 'add_quotation') {

    header('Content-Type: application/json; charset=utf-8');

    $foreman_id = (int)($_SESSION['user_id'] ?? 0);

    if ($foreman_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid foreman account.']);
        exit;
    }

    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'CSRF token validation failed.']);
        exit;
    }

    $inspection_id = (int)($_POST['inspection_id'] ?? 0);
    if ($inspection_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid inspection.']);
        exit;
    }

    // Ownership check + fetch the full row for the renderer.
    $stmt = $pdo->prepare("
        SELECT * FROM site_inspections
        WHERE id = ? AND inspector_id = ?
        LIMIT 1
    ");
    $stmt->execute([$inspection_id, $foreman_id]);
    $ins = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ins) {
        echo json_encode(['success' => false, 'message' => 'Inspection not found or access denied.']);
        exit;
    }

    try {
        $newQuotationId = createAdditionalQuotationForInspection($pdo, $inspection_id);
    } catch (Throwable $e) {
        error_log("Add quotation (AJAX) failed for inspection {$inspection_id}: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Could not create the quotation.']);
        exit;
    }

    // Render the whole section with the new quotation included.
    $sectionHtml = renderForemanQuotationSection($pdo, $ins);

    echo json_encode([
        'success'      => true,
        'quotation_id' => (int)$newQuotationId,
        'html'         => $sectionHtml,
    ]);
    exit;
}

// ============================================================================
// DETERMINE ACTIVE TAB
// ============================================================================

$tab = $_GET['tab'] ?? 'dashboard';

$allowed = [
    'dashboard',
    'attendance',
    'site_inspection',
    'projects',
    'materials',
    'profile'
];

if (!in_array($tab, $allowed, true)) {
    $tab = 'dashboard';
}


// ============================================================================
// HANDLE PROJECT PROGRESS UPDATE
// BEFORE HTML OUTPUT
// ============================================================================

if ($tab === 'projects' && isset($_POST['update_progress'])) {

    $foreman_id = (int)($_SESSION['user_id'] ?? 0);
    $project_db_id = (int)($_POST['project_id'] ?? 0);
    $progress = (int)($_POST['progress'] ?? 0);

    $work_completed = trim($_POST['work_completed'] ?? '');
    $materials_used = trim($_POST['materials_used'] ?? '');
    $issues_encountered = trim($_POST['issues_encountered'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    $redirect = 'foreman.php?tab=projects&view=' . $project_db_id;

    // Verify project belongs to this foreman.
    $stmt = $pdo->prepare("
        SELECT id, project_id, start_date, status
        FROM projects
        WHERE id = ?
          AND foreman_id = ?
        LIMIT 1
    ");

    $stmt->execute([$project_db_id, $foreman_id]);

    $assigned_project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assigned_project) {
        header('Location: foreman.php?tab=projects&msg=not_allowed');
        exit;
    }

    // Completed projects are locked.
    if ($assigned_project['status'] === 'completed') {
        header('Location: ' . $redirect . '&msg=not_allowed');
        exit;
    }

    // Validate progress.
    if ($progress < 0 || $progress > 100) {
        header('Location: ' . $redirect . '&msg=error');
        exit;
    }

    // Status is now managed manually by the admin. This handler only
    // updates progress and appends a progress log. The project's
    // existing status is preserved.
    $status = $assigned_project['status'];

    try {

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE projects
            SET progress = ?, status = ?
            WHERE id = ? AND foreman_id = ?
        ");
        
        // If progress has dropped below 100%, clear any stale completion date.
        if ($progress < 100) {
            $stmt = $pdo->prepare("
                UPDATE projects
                SET completed_at = NULL
                WHERE id = ? AND completed_at IS NOT NULL
            ");
            $stmt->execute([$project_db_id]);
        }

        $stmt->execute([$progress, $status, $project_db_id, $foreman_id]);

        $stmt = $pdo->prepare("
            INSERT INTO project_progress_log
            (project_id, progress, work_completed, materials_used,
             issues_encountered, remarks)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $project_db_id, $progress, $work_completed,
            $materials_used, $issues_encountered, $remarks
        ]);

        $pdo->commit();

        header('Location: ' . $redirect . '&msg=progress_updated');
        exit;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        header('Location: ' . $redirect . '&msg=error');
        exit;
    }
}


// ============================================================================
// HANDLE EMPLOYEE ATTENDANCE
//
// This handles:
// - Employee email/password authentication
// - Active-project validation
// - Facial-recognition result
// - Face distance
// - Captured photo
// - GPS latitude/longitude/accuracy
// - Time In
// - Time Out
// ============================================================================

if ($tab === 'attendance' && isset($_POST['attendance_action'])) {

    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $attendance_action = $_POST['attendance_action'] ?? '';
    $employee_email = trim($_POST['employee_email'] ?? '');
    $project_db_id = (int)($_POST['project_id'] ?? 0);
    $captured_photo = $_POST['captured_photo'] ?? '';

    $face_verified = !empty($_POST['face_verified']) ? 1 : 0;

    $face_distance = (
        isset($_POST['face_distance']) &&
        $_POST['face_distance'] !== ''
    ) ? (float)$_POST['face_distance'] : null;

    $latitude = (
        isset($_POST['latitude']) &&
        $_POST['latitude'] !== ''
    ) ? (float)$_POST['latitude'] : null;

    $longitude = (
        isset($_POST['longitude']) &&
        $_POST['longitude'] !== ''
    ) ? (float)$_POST['longitude'] : null;

    $location_accuracy = (
        isset($_POST['location_accuracy']) &&
        $_POST['location_accuracy'] !== ''
    ) ? (float)$_POST['location_accuracy'] : null;

    $today = date('Y-m-d');
    $now = date('H:i:s');

    // Configurable standard start time (e.g. 08:00:00).
    $standard_start_time =
        $company_settings['standard_start_time'] ?? '08:00:00';

    // Only Time In / Time Out allowed.
    if (!in_array($attendance_action, ['time_in', 'time_out'], true)) {
        header('Location: foreman.php?tab=attendance&msg=error');
        exit;
    }

    // Authenticate employee.
    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE email = ?
          AND role = 'employee'
          AND status = 'active'
        LIMIT 1
    ");

    $stmt->execute([$employee_email]);

    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        header('Location: foreman.php?tab=attendance&msg=invalid_credentials');
        exit;
    }

    $employee_id = (int)$employee['id'];

    // Confirm the selected employee is assigned to at least one of
    // this foreman's active projects. The dropdown is server-filtered,
    // but a tampered POST could still send an arbitrary email.
    $stmt = $pdo->prepare("
        SELECT 1
        FROM project_employees pe
        INNER JOIN projects p
            ON p.id = pe.project_id
        WHERE pe.employee_id = ?
          AND p.foreman_id = ?
          AND " . projectAttendanceEligibleSql('CURDATE()', 'p') . "
        LIMIT 1
    ");

    $stmt->execute([
        $employee_id,
        (int)$_SESSION['user_id']
    ]);

    if (!$stmt->fetchColumn()) {
        header('Location: foreman.php?tab=attendance&msg=not_allowed');
        exit;
    }

    // Employee join date validation.
    if (
        !empty($employee['join_date']) &&
        $employee['join_date'] > $today
    ) {
        header('Location: foreman.php?tab=attendance&msg=not_allowed');
        exit;
    }

    // Facial recognition must succeed.
    if (!$face_verified) {
        header('Location: foreman.php?tab=attendance&msg=face_not_verified');
        exit;
    }

    // Face distance validation.
    if (
        $face_distance !== null &&
        ($face_distance < 0 || $face_distance > 1)
    ) {
        header('Location: foreman.php?tab=attendance&msg=face_not_verified');
        exit;
    }

    // Coordinate validation.
    if ($latitude !== null && ($latitude < -90 || $latitude > 90)) {
        $latitude = null;
    }

    if ($longitude !== null && ($longitude < -180 || $longitude > 180)) {
        $longitude = null;
    }

    // Geolocation is required.
    if ($latitude === null || $longitude === null) {
        header('Location: foreman.php?tab=attendance&msg=location_required');
        exit;
    }

    // Verify the selected project.
    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.project_name,
            p.start_date
        FROM projects p
        INNER JOIN project_employees pe
            ON pe.project_id = p.id
           AND pe.employee_id = ?
        WHERE p.id = ?
          AND p.foreman_id = ?
          AND " . projectAttendanceEligibleSql('CURDATE()', 'p') . "
        LIMIT 1
    ");

    $stmt->execute([
        $employee_id,
        $project_db_id,
        (int)$_SESSION['user_id']
    ]);

    $assigned_project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assigned_project) {
        header('Location: foreman.php?tab=attendance&msg=not_allowed');
        exit;
    }

    // Find today's attendance record.
    $stmt = $pdo->prepare("
        SELECT *
        FROM attendance
        WHERE employee_id = ?
          AND attendance_date = ?
        LIMIT 1
    ");

    $stmt->execute([$employee_id, $today]);

    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);


    // =========================================================================
    // TIME IN
    // =========================================================================

    if ($attendance_action === 'time_in') {

        // Already timed in.
        if ($attendance && !empty($attendance['time_in'])) {
            header('Location: foreman.php?tab=attendance&msg=already_in');
            exit;
        }

        // --------------------------------------------------------------------
        // Normalize early Time In.
        //
        // If the employee arrives before the standard start time, record
        // their Time In as the standard start time instead.
        // --------------------------------------------------------------------

        if ($now < $standard_start_time) {
            $now = $standard_start_time;
        }

        // --------------------------------------------------------------------
        // Calculate Late/Present using configurable threshold.
        // --------------------------------------------------------------------

        $late_threshold = '08:15:00';

        $stmt = $pdo->query("
            SELECT late_threshold
            FROM system_settings
            ORDER BY id ASC
            LIMIT 1
        ");

        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($settings && !empty($settings['late_threshold'])) {

            $parts = explode(':', $settings['late_threshold']);

            $hours = isset($parts[0]) ? (int)$parts[0] : 0;
            $minutes = isset($parts[1]) ? (int)$parts[1] : 0;
            $seconds = isset($parts[2]) ? (int)$parts[2] : 0;

            $threshold_seconds =
                ($hours * 3600) +
                ($minutes * 60) +
                $seconds;

            $late_threshold = date(
                'H:i:s',
                strtotime($standard_start_time) + $threshold_seconds
            );
        }

        $attendance_status =
            ($now > $late_threshold) ? 'Late' : 'Present';

        // --------------------------------------------------------------------
        // Existing attendance row.
        // --------------------------------------------------------------------

        if ($attendance) {

            $stmt = $pdo->prepare("
                UPDATE attendance
                SET
                    project_id = ?,
                    time_in = ?,
                    status = ?,
                    captured_photo = ?,
                    face_verified = ?,
                    face_distance = ?,
                    latitude = ?,
                    longitude = ?,
                    location_accuracy = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $project_db_id,
                $now,
                $attendance_status,
                $captured_photo ?: null,
                $face_verified,
                $face_distance,
                $latitude,
                $longitude,
                $location_accuracy,
                $attendance['id']
            ]);

        }

        // --------------------------------------------------------------------
        // New attendance row.
        // --------------------------------------------------------------------

        else {

            $stmt = $pdo->prepare("
                INSERT INTO attendance
                (
                    employee_id, project_id, attendance_date,
                    time_in, time_out, hours_worked, status,
                    captured_photo, face_verified, face_distance,
                    latitude, longitude, location_accuracy
                )
                VALUES
                (
                    :employee_id, :project_id, :attendance_date,
                    :time_in, NULL, NULL, :status,
                    :captured_photo, :face_verified, :face_distance,
                    :latitude, :longitude, :location_accuracy
                )
            ");

            $stmt->execute([
                ':employee_id' => $employee_id,
                ':project_id' => $project_db_id,
                ':attendance_date' => $today,
                ':time_in' => $now,
                ':status' => $attendance_status,
                ':captured_photo' => $captured_photo ?: null,
                ':face_verified' => $face_verified,
                ':face_distance' => $face_distance,
                ':latitude' => $latitude,
                ':longitude' => $longitude,
                ':location_accuracy' => $location_accuracy
            ]);
        }

        $_SESSION['attendance_flash'] = [
            'employee_code' => $employee['employee_id'] ?? '',
            'employee_name' => $employee['full_name'] ?? '',
            'action'        => $attendance_action,
            'time'          => $now,
        ];

        header(
            'Location: foreman.php?tab=attendance&date=' .
            $today .
            '&msg=attendance_recorded'
        );

        exit;
    }


    // =========================================================================
    // TIME OUT
    // =========================================================================

    if ($attendance_action === 'time_out') {

        // Must already have timed in.
        if (!$attendance || empty($attendance['time_in'])) {
            header('Location: foreman.php?tab=attendance&msg=error');
            exit;
        }

        // Already timed out.
        if (!empty($attendance['time_out'])) {
            header('Location: foreman.php?tab=attendance&msg=already_out');
            exit;
        }

        // Calculate hours.
        $time_in_timestamp = strtotime($attendance['time_in']);
        $time_out_timestamp = strtotime($now);

        $seconds_worked = $time_out_timestamp - $time_in_timestamp;

        if ($seconds_worked < 0) {
            $seconds_worked = 0;
        }

        $hours_worked = round($seconds_worked / 3600, 2);

        // Preserve the Time In project.
        $stmt = $pdo->prepare("
            UPDATE attendance
            SET
                time_out = ?,
                hours_worked = ?,
                captured_photo = COALESCE(?, captured_photo),
                face_verified = ?,
                face_distance = ?,
                latitude_time_out = ?,
                longitude_time_out = ?,
                location_accuracy_time_out = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $now,
            $hours_worked,
            $captured_photo ?: null,
            $face_verified,
            $face_distance,
            $latitude,
            $longitude,
            $location_accuracy,
            $attendance['id']
        ]);

        $_SESSION['attendance_flash'] = [
            'employee_code' => $employee['employee_id'] ?? '',
            'employee_name' => $employee['full_name'] ?? '',
            'action'        => $attendance_action,
            'time'          => $now,
        ];

        header(
            'Location: foreman.php?tab=attendance&date=' .
            $today .
            '&msg=attendance_recorded'
        );

        exit;
    }
}


// ============================================================================
// PAGE
// ============================================================================

$tab_file = (
    $tab === 'projects'
        ? 'f_projects.php'
        : 'f_' . $tab . '.php'
);

$tab_path = __DIR__ . '/foreman_tabs/' . $tab_file;

if (!file_exists($tab_path)) {
    die('Foreman tab not found: ' . htmlspecialchars($tab_file));
}


// ============================================================================
// EXECUTE TAB WITH OUTPUT BUFFERING
// ============================================================================

ob_start();

include $tab_path;

$tabContent = ob_get_clean();

?>
<?php
// ... [ALL PHP LOGIC ABOVE STAYS UNCHANGED — including the face endpoint,
// the AJAX task endpoint, tab determination, POST handling, and the
// ob_start/include/$tabContent block] ...
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($company_settings['company_name']) ?> – Foreman Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <!-- QR code generator — used by the Profile tab -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.1/build/qrcode.min.js"></script>

    <style>
    :root {
        --fr-red: #d92027;
        --fr-red-dark: #b0161c;
        --fr-red-soft: #fef2f2;
        --fr-red-bright: #ef4444;      /* red used on the dark sidebar */
        --fr-bg: #f6f7f9;
        --fr-surface: #ffffff;
        --fr-border: #e5e7eb;
        --fr-border-soft: #f0f1f3;
        --fr-text: #111827;
        --fr-text-soft: #374151;
        --fr-muted: #6b7280;
        --fr-shadow-sm: 0 1px 2px rgba(16,24,40,.05);
        --fr-shadow: 0 1px 3px rgba(16,24,40,.08), 0 1px 2px rgba(16,24,40,.04);
    
        /* Sidebar palette (dark) */
        --fr-sidebar-bg: #1a1d21;
        --fr-sidebar-hover: #24282e;
        --fr-sidebar-border: #2b2f35;
        --fr-sidebar-text: #9da2a8;
        --fr-sidebar-text-bright: #f3f4f6;
    }

    * { -webkit-tap-highlight-color: transparent; }
    html, body { height: 100%; }
    body {
        background: var(--fr-bg);
        color: var(--fr-text);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        font-size: 14px;
        margin: 0;
        -webkit-font-smoothing: antialiased;
    }

    /* ---------- Layout ---------- */
    .fr-layout { display: flex; min-height: 100vh; }

    /* ---------- Sidebar (dark) ---------- */
    .fr-sidebar {
        width: 248px;
        background: var(--fr-sidebar-bg);
        border-right: 1px solid var(--fr-sidebar-border);
        display: flex;
        flex-direction: column;
        padding: 22px 0 16px;
        position: sticky;
        top: 0;
        height: 100vh;
        flex-shrink: 0;
    }

    .fr-brand {
        padding: 0 20px 22px;
        display: flex; align-items: center; gap: 12px;
        border-bottom: 1px solid var(--fr-sidebar-border);
        margin-bottom: 14px;
    }
    .fr-brand-logo {
        width: 44px; height: 44px; border-radius: 10px;
        object-fit: contain;
        background: rgba(217,32,39,.15);
        border: 1px solid rgba(217,32,39,.25);
        padding: 4px; flex-shrink: 0;
    }
    .fr-brand-name {
        font-size: 15px; font-weight: 700;
        color: var(--fr-sidebar-text-bright);
        line-height: 1.2;
    }
    .fr-brand-name small {
        display: block; font-size: 11px;
        color: var(--fr-sidebar-text);
        font-weight: 500; margin-top: 2px;
    }

    .fr-nav {
        display: flex; flex-direction: column; gap: 2px;
        padding: 0 12px; flex: 1;
    }
    .fr-nav a {
        display: flex; align-items: center; gap: 10px;
        padding: 10px 14px; border-radius: 8px;
        color: var(--fr-sidebar-text); text-decoration: none;
        font-weight: 500; font-size: 13.5px;
        transition: background .15s, color .15s;
        border-left: 3px solid transparent;
        padding-left: 11px; /* compensate for border */
    }
    .fr-nav a:hover {
        background: var(--fr-sidebar-hover);
        color: var(--fr-sidebar-text-bright);
    }
    .fr-nav a.active {
        background: rgba(217,32,39,.15);
        color: #ffffff;
        font-weight: 600;
        border-left-color: var(--fr-red-bright);
    }
    .fr-nav a.active .bi {
        color: var(--fr-red-bright);
    }
    .fr-nav a .bi {
        font-size: 16px; width: 18px;
        text-align: center;
        transition: color .15s;
    }

    .fr-user {
        margin: 16px 12px 0; padding: 16px 20px 0;
        border-top: 1px solid var(--fr-sidebar-border);
        display: flex; flex-direction: column;
    }
    .fr-user .name {
        font-weight: 600; font-size: 13px;
        color: var(--fr-sidebar-text-bright);
    }
    .fr-user .role {
        font-size: 10.5px;
        color: var(--fr-red-bright);
        font-weight: 700; text-transform: uppercase;
        letter-spacing: .5px; margin-top: 2px;
    }
    .fr-user a {
        font-size: 12.5px;
        color: var(--fr-sidebar-text);
        text-decoration: none;
        margin-top: 8px;
        display: inline-flex; align-items: center;
        transition: color .15s;
    }
    .fr-user a:hover {
        color: var(--fr-red-bright);
    }

    /* ---------- Main ---------- */
    .fr-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }

    .fr-header {
        background: var(--fr-surface);
        border-bottom: 1px solid var(--fr-border);
        padding: 16px 28px;
        display: flex; align-items: center; justify-content: space-between;
        gap: 16px; position: sticky; top: 0; z-index: 50;
    }
    .fr-header h1 { font-size: 17px; font-weight: 700; margin: 0; color: var(--fr-text); }
    .fr-header .meta {
        display: flex; align-items: center; gap: 10px;
        font-size: 13px; color: var(--fr-muted);
    }
    .fr-header .icon-btn {
        width: 36px; height: 36px; border-radius: 8px;
        display: inline-flex; align-items: center; justify-content: center;
        color: var(--fr-muted); text-decoration: none;
        transition: background .15s, color .15s;
    }
    .fr-header .icon-btn:hover { background: #f3f4f6; color: var(--fr-text); }
    .fr-header .icon-btn.logout:hover { color: var(--fr-red); background: var(--fr-red-soft); }

    .fr-content { padding: 24px 28px 40px; flex: 1; }

    /* ---------- Shared components ---------- */
    .fr-card {
        background: var(--fr-surface);
        border: 1px solid var(--fr-border);
        border-radius: 12px;
        box-shadow: var(--fr-shadow-sm);
    }
    .fr-card-body { padding: 20px 22px; }
    .fr-card-pad { padding: 20px 22px; }

    .fr-section-title {
        font-size: 11px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .8px; color: var(--fr-muted);
        margin: 0 0 14px;
        display: flex; align-items: center; gap: 10px;
    }
    .fr-section-title::before {
        content: ''; width: 3px; height: 14px;
        background: var(--fr-red); border-radius: 2px;
        flex-shrink: 0;
    }

    .fr-stat {
        background: var(--fr-surface);
        border: 1px solid var(--fr-border);
        border-radius: 12px;
        padding: 18px 20px;
        box-shadow: var(--fr-shadow-sm);
        position: relative;
        overflow: hidden;
    }
    .fr-stat::before {
        content: ''; position: absolute; left: 0; top: 0; bottom: 0;
        width: 3px; background: var(--fr-red);
        opacity: 0; transition: opacity .2s;
    }
    .fr-stat:hover::before { opacity: 1; }
    .fr-stat-label {
        font-size: 11.5px; font-weight: 600; text-transform: uppercase;
        letter-spacing: .5px; color: var(--fr-muted); margin-bottom: 8px;
    }
    .fr-stat-value {
        font-size: 28px; font-weight: 700; line-height: 1;
        color: var(--fr-text); font-variant-numeric: tabular-nums;
    }
    .fr-stat-value.red { color: var(--fr-red); }
    .fr-stat-note { font-size: 12px; color: var(--fr-muted); margin-top: 8px; line-height: 1.5; }

    /* ---------- Buttons ---------- */
    .btn { font-size: 13px; font-weight: 600; border-radius: 8px; padding: 8px 14px; transition: all .15s; }
    .btn:focus { box-shadow: 0 0 0 3px rgba(217,32,39,.15); }
    .btn-primary { background: var(--fr-red); border-color: var(--fr-red); color: #fff; }
    .btn-primary:hover, .btn-primary:focus { background: var(--fr-red-dark); border-color: var(--fr-red-dark); color: #fff; }
    .btn-outline-secondary { color: #374151; border-color: #d1d5db; background: #fff; }
    .btn-outline-secondary:hover { background: #f9fafb; color: var(--fr-text); border-color: #9ca3af; }
    .btn-sm { padding: 5px 10px; font-size: 12px; border-radius: 6px; }
    
    /* ---------- Table action buttons (colored) ---------- */
    .btn-action {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 5px 11px;
        font-size: 12px;
        font-weight: 600;
        line-height: 1.4;
        border-radius: 7px;
        border: 1px solid transparent;
        text-decoration: none;
        cursor: pointer;
        transition: background .15s, color .15s, border-color .15s, filter .15s;
        white-space: nowrap;
    }
    .btn-action:focus-visible {
        outline: none;
        box-shadow: 0 0 0 3px rgba(0,0,0,.08);
    }
    .btn-action .bi { font-size: 14px; }
    
    /* Details — info blue */
    .btn-action-info {
        background: #eff6ff;
        color: #1d4ed8;
        border-color: #bfdbfe;
    }
    .btn-action-info:hover {
        background: #dbeafe;
        color: #1e40af;
        border-color: #93c5fd;
    }
    
    /* Photos — violet */
    .btn-action-photo {
        background: #f5f3ff;
        color: #6d28d9;
        border-color: #ddd6fe;
    }
    .btn-action-photo:hover {
        background: #ede9fe;
        color: #5b21b6;
        border-color: #c4b5fd;
    }
    
    /* Edit — brand red (solid) */
    .btn-action-edit {
        background: var(--fr-red);
        color: #fff;
        border-color: var(--fr-red);
    }
    .btn-action-edit:hover {
        background: var(--fr-red-dark);
        border-color: var(--fr-red-dark);
        color: #fff;
    }
    
    /* Complete — green */
    .btn-action-complete {
        background: #ecfdf5;
        color: #047857;
        border-color: #a7f3d0;
    }
    .btn-action-complete:hover {
        background: #d1fae5;
        color: #065f46;
        border-color: #6ee7b7;
    }
    .btn-action-complete.is-done {
        background: #f3f4f6;
        color: #6b7280;
        border-color: #e5e7eb;
        cursor: default;
        pointer-events: none;
        opacity: .75;
    }
    
    /* Follow-up — amber */
    .btn-action-followup {
        background: #fffbeb;
        color: #b45309;
        border-color: #fde68a;
    }
    .btn-action-followup:hover {
        background: #fef3c7;
        color: #92400e;
        border-color: #fcd34d;
    }

    /* ---------- Badges ---------- */
    .fr-badge {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 3px 10px; border-radius: 99px;
        font-size: 11px; font-weight: 600; line-height: 1.4;
        border: 1px solid transparent; white-space: nowrap;
    }
    .fr-badge::before {
        content: ''; width: 5px; height: 5px;
        border-radius: 50%; background: currentColor;
    }
    .fr-badge.scheduled, .fr-badge.pending { background: #fffbeb; color: #b45309; border-color: #fde68a; }
    .fr-badge.completed, .fr-badge.active { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
    .fr-badge.followup { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
    .fr-badge.cancelled { background: #f3f4f6; color: #4b5563; border-color: #e5e7eb; }
    .fr-badge.present { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
    .fr-badge.late { background: #fffbeb; color: #b45309; border-color: #fde68a; }
    .fr-badge.absent { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
    
    .fr-badge.waiting-for-admin-confirmation,
    .status-waiting-for-admin-confirmation {
        background: #f0f9ff;
        color: #0369a1;
        border-color: #bae6fd;
    }

    /* ---------- Tables ---------- */
    .fr-table-wrap {
        background: var(--fr-surface);
        border: 1px solid var(--fr-border);
        border-radius: 12px;
        overflow-x: auto;
        box-shadow: var(--fr-shadow-sm);
    }
    /* ---------- TABLE WRAP (alias) ---------- */
    /* The modals (Quotations, Payments) use the plain .table-wrap / <table>
       pair, matching the admin theme. Foreman pages use .fr-table-wrap
       for top-level tables; both should look identical. */
    .table-wrap {
        background: var(--fr-surface);
        border: 1px solid var(--fr-border);
        border-radius: 12px;
        overflow-x: auto;
        box-shadow: var(--fr-shadow-sm);
    }
    .table-wrap table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    .table-wrap table th {
        text-align: left;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
        color: var(--fr-muted);
        padding: 12px 18px;
        border-bottom: 1px solid var(--fr-border);
        background: #fafbfc;
        white-space: nowrap;
    }
    .table-wrap table td {
        padding: 12px 14px;
        border-bottom: 1px solid var(--fr-border-soft);
        color: var(--fr-text);
        vertical-align: middle;
    }
    .table-wrap table tbody tr:last-child td { border-bottom: none; }
    .table-wrap table tbody tr:hover { background: #fafbfc; }
    .fr-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    .fr-table thead th {
        text-align: left; font-size: 11px; font-weight: 700;
        letter-spacing: .5px; text-transform: uppercase;
        color: var(--fr-muted); padding: 12px 18px;
        border-bottom: 1px solid var(--fr-border);
        background: #fafbfc; white-space: nowrap;
    }
    .fr-table tbody td {
        padding: 14px 18px;
        border-bottom: 1px solid var(--fr-border-soft);
        color: var(--fr-text); vertical-align: middle;
    }
    .fr-table tbody tr:last-child td { border-bottom: none; }
    .fr-table tbody tr { transition: background .1s; }
    .fr-table tbody tr:hover { background: #fafbfc; }
    .fr-table .muted { color: var(--fr-muted); }
    .fr-table .fw-num { font-variant-numeric: tabular-nums; }

    /* ---------- Forms ---------- */
    .form-control, .form-select {
        border-color: #d1d5db; font-size: 13.5px;
        border-radius: 8px; padding: 8px 12px; background: #fff;
    }
    .form-control:focus, .form-select:focus {
        border-color: var(--fr-red);
        box-shadow: 0 0 0 3px rgba(217,32,39,.1);
    }
    .form-label { font-size: 13px; font-weight: 600; color: var(--fr-text-soft); margin-bottom: 6px; }
    .form-text { font-size: 11.5px; color: var(--fr-muted); }

    /* ---------- FORM GROUPS ---------- */
    /* Needed by the Quotations and Payments modals, which wrap each
       field in .form-group. Without these rules, labels render inline
       next to the input and inputs don't stretch to full width. */
    .form-group { margin-bottom: 15px; }

    .form-group > label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: var(--fr-text-soft);
        margin-bottom: 6px;
    }

    .form-group input:not([type=checkbox]):not([type=radio]),
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        background: #fff;
        color: var(--fr-text);
        font-size: 13.5px;
        box-sizing: border-box;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        border-color: var(--fr-red);
        outline: none;
        box-shadow: 0 0 0 3px rgba(217,32,39,.1);
    }

    /* ---------- Modals ---------- */
    .modal-content { border-radius: 14px; border: 1px solid var(--fr-border); }
    .modal-header { border-bottom: 1px solid var(--fr-border); padding: 18px 22px; }
    .modal-title { font-size: 16px; font-weight: 700; color: var(--fr-text); }
    .modal-body { padding: 22px; }
    .modal-footer { border-top: 1px solid var(--fr-border); padding: 16px 22px; gap: 8px; }

    /* ---------- CUSTOM MODAL (JS-compatible) ---------- */
    /* .classList.add('show') / .classList.remove('show') keeps working.
       Applied by the Quotations tab's Edit modal and the Payments modals. */
    .ad-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1055;
        background: rgba(17, 24, 39, 0.5);
        padding: 30px 18px;
        overflow-y: auto;
        justify-content: center;
        align-items: flex-start;
        backdrop-filter: blur(2px);
    }
    .ad-modal.show { display: flex; }

    .ad-modal-content {
        background: #fff;
        border-radius: 14px;
        width: 100%;
        max-width: 720px;
        margin: 20px auto;
        padding: 26px;
        position: relative;
        box-shadow: 0 20px 60px rgba(0,0,0,.2);
        color: var(--fr-text);
        border: 1px solid var(--fr-border);
    }
    .ad-modal-content h2 {
        font-size: 18px;
        font-weight: 700;
        margin: 0 0 16px;
        color: var(--fr-text);
    }

    .ad-modal .close {
        position: absolute;
        top: 12px; right: 18px;
        font-size: 28px;
        line-height: 1;
        color: var(--fr-muted);
        cursor: pointer;
        text-decoration: none;
        background: none;
        border: none;
    }
    .ad-modal .close:hover { color: var(--fr-text); }

    /* ---------- Progress bars ---------- */
    .fr-progress {
        height: 8px; background: #eef0f3;
        border-radius: 99px; overflow: hidden;
    }
    .fr-progress > div {
        height: 100%; background: var(--fr-red);
        border-radius: 99px; transition: width .3s ease;
    }
    
    /* ---------- Sidebar separator ---------- */
    .fr-nav .separator {
        height: 1px;
        background: var(--fr-sidebar-border);
        margin: 10px 12px;
    }

    /* ---------- Profile tab ---------- */
    .profile-photo-frame {
        width: 140px;
        height: 140px;
        border-radius: 50%;
        overflow: hidden;
        border: 3px solid var(--fr-red);
        background: var(--fr-bg);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .profile-photo-frame img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .profile-photo-fallback {
        font-size: 46px;
        font-weight: 800;
        color: var(--fr-red);
        letter-spacing: -1px;
    }

    .profile-qr-card {
        background: var(--fr-bg);
        border: 1px solid var(--fr-border);
        border-radius: 12px;
        padding: 20px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
    }
    .profile-qr-card canvas {
        display: block;
        border-radius: 6px;
        background: #fff;
    }
    .profile-qr-caption {
        font-size: 12px;
        color: var(--fr-muted);
        text-align: center;
        line-height: 1.5;
        margin: 0;
    }

    /* ---------- Attendance: identified-employee card ---------- */
    .attendance-identified {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px 16px;
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        border-radius: 10px;
        margin-bottom: 12px;
    }
    .attendance-identified .bi {
        font-size: 32px;
        color: #047857;
        flex-shrink: 0;
    }
    .attendance-identified .name {
        font-weight: 700;
        font-size: 15px;
        color: #065f46;
        line-height: 1.2;
    }
    .attendance-identified .code {
        font-size: 12px;
        color: #047857;
        opacity: .8;
        margin-top: 2px;
    }
    .attendance-identified.is-warn {
        background: #fffbeb;
        border-color: #fde68a;
    }
    .attendance-identified.is-warn .bi,
    .attendance-identified.is-warn .code { color: #b45309; }
    .attendance-identified.is-warn .name { color: #92400e; }

    .attendance-status-line {
        padding: 8px 12px;
        border-radius: 8px;
        background: var(--fr-bg);
        font-size: 13px;
        margin-bottom: 6px;
    }
    
    /* ---------- MESSAGES ---------- */
    .msg {
        padding: 12px 16px;
        border-radius: 8px;
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #047857;
        margin-bottom: 16px;
        font-size: 13.5px;
    }
    .msg.error { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
    .msg.warning { background: #fffbeb; border-color: #fde68a; color: #b45309; }
    
    /* ---------- Attendance confirmation notice ---------- */
    .attendance-confirm-notice {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        border-radius: 10px;
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #065f46;
        font-size: 13.5px;
        line-height: 1.5;
        margin-bottom: 16px;
        animation: frFadeInDown .25s ease;
    }
    .attendance-confirm-notice .bi {
        font-size: 18px;
        color: #047857;
        flex-shrink: 0;
    }
    .attendance-confirm-notice strong {
        font-weight: 700;
        color: #065f46;
    }
    /* ---------- Collapsible quotation-prep inside inspection modal ---------- */
    details.quotation-prep summary { list-style: none; }
    details.quotation-prep summary::-webkit-details-marker { display: none; }
    details.quotation-prep[open] summary .bi-chevron-down { transform: rotate(180deg); }
    details.quotation-prep .q-row:not(.is-selected) td { opacity: .55; }
    details.quotation-prep .q-row input[type="number"] { min-width: 80px; }
    @keyframes frFadeInDown {
        from { opacity: 0; transform: translateY(-6px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    
    /* =========================================================
    QUOTATION MATERIAL PICKER — split-pane picker
    ========================================================= */
    .qp-split {
        display: grid;
        grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr);
        gap: 12px;
        margin-bottom: 12px;
    }
    @media (max-width: 720px) { .qp-split { grid-template-columns: 1fr; } }
    
    .qp-panel {
        border: 1px solid var(--fr-border);
        border-radius: 10px;
        background: #fff;
        display: flex;
        flex-direction: column;
        min-height: 240px;
        max-height: 380px;
        overflow: hidden;
    }
    .qp-panel-header {
        padding: 10px 12px;
        border-bottom: 1px solid var(--fr-border);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: var(--fr-muted);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        background: #fafbfc;
    }
    
    .qp-search {
        width: 100%;
        padding: 6px 10px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 12.5px;
    }
    .qp-search:focus {
        border-color: var(--fr-red);
        outline: none;
        box-shadow: 0 0 0 3px rgba(217,32,39,.08);
    }
    
    .qp-chips { display: flex; flex-wrap: wrap; gap: 4px; padding: 8px 10px 4px; }
    .qp-chip {
        padding: 4px 9px;
        font-size: 11px;
        font-weight: 600;
        border-radius: 99px;
        border: 1px solid var(--fr-border);
        background: #fff;
        color: var(--fr-text-soft);
        cursor: pointer;
    }
    .qp-chip.is-active { background: var(--fr-red); border-color: var(--fr-red); color: #fff; }
    
    .qp-list { flex: 1; overflow-y: auto; padding: 6px; }
    
    .qp-selected-row {
        padding: 10px 12px;
        border-bottom: 1px solid var(--fr-border-soft);
    }
    .qp-selected-row:last-child { border-bottom: none; }
    .qp-row-header {
        display: flex; align-items: flex-start; justify-content: space-between;
        gap: 8px; margin-bottom: 2px;
    }
    .qp-row-header strong { font-size: 13px; font-weight: 600; color: var(--fr-text); }
    .qp-row-meta { font-size: 11px; color: var(--fr-muted); margin-bottom: 8px; }
    .qp-row-remove {
        background: none; border: none; color: var(--fr-muted);
        font-size: 18px; line-height: 1; cursor: pointer; padding: 0 4px;
        border-radius: 4px;
    }
    .qp-row-remove:hover { color: #dc3545; background: #fef2f2; }
    .qp-row-inputs {
        display: grid; grid-template-columns: 1fr 1fr auto;
        gap: 8px; align-items: end;
    }
    .qp-row-inputs label {
        font-size: 10px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .4px; color: var(--fr-muted);
        display: flex; flex-direction: column; gap: 2px;
    }
    .qp-row-inputs input {
        width: 100%; padding: 5px 8px; font-size: 12.5px;
        border: 1px solid #d1d5db; border-radius: 6px;
    }
    .qp-row-inputs input:focus {
        border-color: var(--fr-red); outline: none;
        box-shadow: 0 0 0 3px rgba(217,32,39,.08);
    }
    /* Read-only price display — matches the disabled input it replaced */
    .qp-row-price {
        display: block;
        padding: 5px 8px;
        font-size: 12.5px;
        font-weight: 600;
        color: var(--fr-text);
        background: var(--fr-bg);
        border: 1px solid var(--fr-border);
        border-radius: 6px;
        text-align: right;
        font-variant-numeric: tabular-nums;
        line-height: 1.5;
        min-height: 30px;
        box-sizing: border-box;
    }
    .qp-row-amount {
        font-size: 13px; font-weight: 700; color: var(--fr-text);
        padding-bottom: 5px; white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }
    
    .qp-empty {
        padding: 24px 16px; text-align: center;
        font-size: 12.5px; color: var(--fr-muted); font-style: italic;
    }
    
    .qp-available-row {
        display: flex; gap: 8px; align-items: flex-start;
        padding: 8px 10px; border-radius: 6px; cursor: pointer; margin-bottom: 2px;
    }
    .qp-available-row:hover { background: var(--fr-bg); }
    .qp-available-row input[type="checkbox"] { margin-top: 3px; flex-shrink: 0; }
    .qp-available-row span { display: flex; flex-direction: column; min-width: 0; }
    .qp-available-row strong {
        font-size: 12.5px; font-weight: 600; color: var(--fr-text);
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .qp-available-row small {
        font-size: 11px; color: var(--fr-muted);
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .qp-available-row.is-checked { background: #fef2f2; }
    
    .qp-show-more {
        display: block; width: 100%; padding: 8px; margin: 6px 0;
        font-size: 12px; font-weight: 600; color: var(--fr-red);
        background: none; border: 1px dashed var(--fr-border);
        border-radius: 6px; cursor: pointer;
    }
    .qp-show-more:hover { background: #fef2f2; border-color: var(--fr-red); }
    
    .qp-subtotal {
        display: flex; justify-content: space-between; align-items: center;
        padding: 10px 14px; background: var(--fr-bg); border-radius: 8px;
        font-size: 13px; margin-bottom: 18px;
    }
    .qp-subtotal strong {
        font-size: 15px; color: var(--fr-text);
        font-variant-numeric: tabular-nums;
    }
    .qp-context-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 8px 12px;
        font-size: 11.5px;
        color: var(--fr-muted);
        border-bottom: 1px solid var(--fr-border-soft);
        background: #fafbfc;
    }
    .qp-context-bar strong { color: var(--fr-text); font-weight: 600; }
    .qp-context-toggle {
        background: none;
        border: none;
        color: var(--fr-red);
        font-size: 11px;
        font-weight: 600;
        cursor: pointer;
        padding: 2px 6px;
        border-radius: 4px;
        white-space: nowrap;
    }
    .qp-context-toggle:hover { background: #fef2f2; }
    .qp-context-bar.is-showing-all .qp-context-toggle { color: var(--fr-muted); }
    
    /* ===== Quotation tabs ===== */
    .quotation-tabs {
        display: flex;
        gap: 4px;
        padding: 4px;
        background: var(--fr-bg);
        border: 1px solid var(--fr-border);
        border-radius: 10px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }
    .quotation-tab {
        padding: 8px 14px;
        border-radius: 8px;
        border: 1px solid transparent;
        background: transparent;
        color: var(--fr-text-soft);
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: background .15s, border-color .15s, color .15s;
    }
    .quotation-tab:hover {
        background: #fff;
        border-color: var(--fr-border);
    }
    .quotation-tab.is-active {
        background: #fff;
        border-color: var(--fr-red);
        color: var(--fr-red);
    }
    .quotation-tab .tab-status {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .4px;
        color: var(--fr-muted);
        padding: 1px 6px;
        border-radius: 99px;
        background: rgba(0,0,0,.04);
    }
    .quotation-tab.is-active .tab-status {
        color: var(--fr-red);
        background: rgba(217,32,39,.08);
    }
    
    /* =========================================================
       COLLAPSIBLE QUOTATION SECTION (foreman Site Inspection modal)
       ========================================================= */
    .quotation-section-wrapper {
        margin-top: 24px;
        border: 1px solid var(--fr-border);
        border-radius: 10px;
        background: #fff;
        overflow: hidden;
    }
    .quotation-section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 12px 16px;
        background: var(--fr-bg);
        border-bottom: 1px solid var(--fr-border);
        flex-wrap: wrap;
    }
    .quotation-section-header .fr-section-title {
        margin: 0;
    }
    .quotation-section-header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        margin-left: auto;
    }
    .quotation-collapse-btn {
        background: #fff;
        border: 1px solid var(--fr-border);
        color: var(--fr-muted);
        width: 30px;
        height: 30px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background .15s, color .15s, border-color .15s;
    }
    .quotation-collapse-btn:hover {
        background: #f9fafb;
        color: var(--fr-text);
        border-color: #9ca3af;
    }
    .quotation-collapse-btn .bi {
        transition: transform .2s;
        font-size: 14px;
    }
    .quotation-section-wrapper.is-collapsed .quotation-section-body {
        display: none;
    }
    .quotation-section-wrapper.is-collapsed .quotation-collapse-btn .bi {
        transform: rotate(-90deg);
    }
    .quotation-section-body {
        padding: 16px;
    }

    /* ---------- Mobile bottom nav ---------- */
    .fr-mobile-nav { display: none; }

    @media (max-width: 768px) {
        .fr-sidebar { display: none; }
        .fr-header { padding: 12px 16px; }
        .fr-header h1 { font-size: 15px; }
        .fr-header .meta { font-size: 11px; }
        .fr-content { padding: 16px 14px calc(90px + env(safe-area-inset-bottom, 0px)); }
        .fr-table thead th { padding: 10px 12px; }
        .fr-table tbody td { padding: 12px; }
        .fr-stat { padding: 14px 16px; }
        .fr-stat-value { font-size: 22px; }

        .fr-mobile-nav {
            display: flex;
            position: fixed; bottom: 0; left: 0; right: 0;
            background: var(--fr-surface);
            border-top: 1px solid var(--fr-border);
            padding: 6px 0 calc(6px + env(safe-area-inset-bottom, 0px));
            z-index: 1000; justify-content: space-around;
            box-shadow: 0 -2px 12px rgba(16,24,40,.06);
        }
        .fr-mobile-nav a {
            display: flex; flex-direction: column;
            align-items: center; gap: 3px;
            flex: 1; min-width: 0; padding: 6px 4px;
            color: var(--fr-muted); text-decoration: none;
            font-size: 10.5px; font-weight: 600;
            transition: color .15s;
        }
        .fr-mobile-nav a.active { color: var(--fr-red); }
        .fr-mobile-nav a .bi { font-size: 20px; line-height: 1; }
    }
    </style>
</head>

<body>

<div class="fr-layout">

    <aside class="fr-sidebar">
        <div class="fr-brand">
            <?php if (!empty($company_settings['company_logo'])): ?>
                <img src="<?= htmlspecialchars($company_settings['company_logo']) ?>" alt="Logo" class="fr-brand-logo">
            <?php else: ?>
                <div class="fr-brand-logo d-flex align-items-center justify-content-center"
                     style="color: var(--fr-red-bright); font-weight: 800;">ENG</div>
            <?php endif; ?>
            <div class="fr-brand-name">
                <?= htmlspecialchars($company_settings['company_name']) ?>
                <?php if (!empty($company_settings['company_subtitle'])): ?>
                    <small><?= htmlspecialchars($company_settings['company_subtitle']) ?></small>
                <?php endif; ?>
            </div>
        </div>

        <nav class="fr-nav">
            <a href="foreman.php?tab=dashboard" class="<?= $tab === 'dashboard' ? 'active' : '' ?>">
                <i class="bi bi-house-door"></i><span>Dashboard</span>
            </a>
            <a href="foreman.php?tab=attendance" class="<?= $tab === 'attendance' ? 'active' : '' ?>">
                <i class="bi bi-check2-square"></i><span>Attendance</span>
            </a>
            <a href="foreman.php?tab=site_inspection" class="<?= $tab === 'site_inspection' ? 'active' : '' ?>">
                <i class="bi bi-clipboard-check"></i><span>Site Inspections</span>
            </a>
            <a href="foreman.php?tab=projects" class="<?= $tab === 'projects' ? 'active' : '' ?>">
                <i class="bi bi-kanban"></i><span>Projects</span>
            </a>
            <a href="foreman.php?tab=materials" class="<?= $tab === 'materials' ? 'active' : '' ?>">
                <i class="bi bi-box-seam"></i><span>Material Tracking</span>
            </a>

            <div class="separator"></div>

            <a href="foreman.php?tab=profile" class="<?= $tab === 'profile' ? 'active' : '' ?>">
                <i class="bi bi-person"></i><span>My Profile</span>
            </a>
        </nav>

        <div class="fr-user">
            <div class="name"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
            <div class="role">Foreman</div>
            <a href="logout.php"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
        </div>
    </aside>

    <main class="fr-main">
        <header class="fr-header">
            <h1><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $tab))) ?></h1>
            <div class="meta">
                <span class="d-none d-md-inline"><?= date('l, F j, Y') ?></span>
                <a href="foreman.php?tab=profile" class="icon-btn" title="My Profile" aria-label="My Profile">
                    <i class="bi bi-person"></i>
                </a>
                <?php include_once 'notification_bell.php'; ?>
                <a href="logout.php" class="icon-btn logout" title="Logout" aria-label="Logout">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </header>

        <div class="fr-content tab-foreman-<?= htmlspecialchars($tab) ?>">
            <?= $tabContent ?>
        </div>
    </main>

</div>

<nav class="fr-mobile-nav">
    <a href="foreman.php?tab=dashboard" class="<?= $tab === 'dashboard' ? 'active' : '' ?>">
        <i class="bi bi-house-door"></i><span>Home</span>
    </a>
    <a href="foreman.php?tab=attendance" class="<?= $tab === 'attendance' ? 'active' : '' ?>">
        <i class="bi bi-check2-square"></i><span>Attend</span>
    </a>
    <a href="foreman.php?tab=site_inspection" class="<?= $tab === 'site_inspection' ? 'active' : '' ?>">
        <i class="bi bi-clipboard-check"></i><span>Inspect</span>
    </a>
    <a href="foreman.php?tab=projects" class="<?= $tab === 'projects' ? 'active' : '' ?>">
        <i class="bi bi-kanban"></i><span>Projects</span>
    </a>
    <a href="foreman.php?tab=materials" class="<?= $tab === 'materials' ? 'active' : '' ?>">
        <i class="bi bi-box-seam"></i><span>Materials</span>
    </a>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    function fmtMoney(v) {
        var raw = String(v == null ? '' : v).replace(/[^\d.]/g, '');
        var firstDot = raw.indexOf('.');
        if (firstDot !== -1) {
            raw = raw.slice(0, firstDot + 1) + raw.slice(firstDot + 1).replace(/\./g, '');
        }
        var parts = raw.split('.');
        var intPart = (parts[0] || '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        var decPart = parts.length > 1 ? parts[1].slice(0, 2) : null;
        return decPart !== null ? intPart + '.' + decPart : intPart;
    }

    document.addEventListener('input', function (e) {
        var el = e.target;
        if (!el.matches || !el.matches('input, textarea')) return;
        if (!el.classList.contains('money-input') && el.dataset.numeric !== '1') return;

        if (el.classList.contains('money-input')) {
            var formatted = fmtMoney(el.value);
            if (el.value !== formatted) el.value = formatted;
        } else {
            var raw = String(el.value).replace(/[^\d.]/g, '');
            var firstDot = raw.indexOf('.');
            if (firstDot !== -1) {
                raw = raw.slice(0, firstDot + 1) + raw.slice(firstDot + 1).replace(/\./g, '');
            }
            if (raw !== el.value) el.value = raw;
        }
        el.dispatchEvent(new CustomEvent('fr:numeric-change', { bubbles: true }));
    });

    window.frParseMoney = function (v) {
        return parseFloat(String(v == null ? '' : v).replace(/,/g, '')) || 0;
    };
})();
</script>
</body>
</html>