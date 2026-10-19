<?php
// This file is included inside admin.php.
// The admin.php wrapper already verifies admin access.


// =========================================================
// HELPERS
// =========================================================

function adminAttendanceGetMonday($date)
{
    $dt = new DateTime($date);

    if ((int)$dt->format('N') !== 1) {
        $dt->modify('monday this week');
    }

    return $dt->format('Y-m-d');
}


function adminAttendanceGetFriday($monday)
{
    $dt = new DateTime($monday);
    $dt->modify('+4 days');

    return $dt->format('Y-m-d');
}


function adminAttendanceCalculateHours($timeIn, $timeOut)
{
    if (empty($timeIn) || empty($timeOut)) {
        return 0;
    }

    $start = new DateTime($timeIn);
    $end = new DateTime($timeOut);

    if ($end <= $start) {
        return 0;
    }

    $seconds = $end->getTimestamp() - $start->getTimestamp();

    return round($seconds / 3600, 2);
}


function adminAttendanceFormatMoney($value)
{
    return 'P' . number_format((float)$value, 2);
}


function adminAttendanceGetLateThreshold($pdo)
{
    $stmt = $pdo->query("
        SELECT late_threshold
        FROM system_settings
        ORDER BY id ASC
        LIMIT 1
    ");

    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    return $settings['late_threshold'] ?? '00:15:00';
}




/**
 * Get the effective project completion date.
 *
 * Priority:
 * 1. projects.completed_at
 * 2. latest progress log reaching 100%
 * 3. expected_end_date for already-completed projects
 * 4. null = still active indefinitely
 */
function adminAttendanceProjectCompletionExpression()
{
    return projectCompletionExpression('p');
}


/**
 * Determines whether a project counts as active during a date.
 */
function adminAttendanceProjectIsEligibleSql($dateParameter)
{
    return projectIsEligibleSql($dateParameter, 'p');
}



// =========================================================
// POST: SAVE PAYROLL / DEDUCTIONS
// =========================================================

if (isset($_POST['save_payroll'])) {

    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $weekStart = adminAttendanceGetMonday($_POST['week_start'] ?? date('Y-m-d'));
    $weekEnd = adminAttendanceGetFriday($weekStart);

    $deductions = $_POST['deductions'] ?? [];

    // Fetch payroll records for week.
    $stmt = $pdo->prepare("
        SELECT pr.id, pr.employee_id, pr.status
        FROM payroll_records pr
        WHERE pr.week_start = ?
    ");

    $stmt->execute([$weekStart]);

    $payrollRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    try {

        $pdo->beginTransaction();

        foreach ($payrollRows as $row) {

            // Released payroll cannot be modified.
            if ($row['status'] === 'Released') {
                continue;
            }

            $recordId = (int)$row['id'];
            $employeeId = (int)$row['employee_id'];

            $employeeDeductionValues = $deductions[$employeeId] ?? [];

            foreach ($employeeDeductionValues as $typeId => $amount) {

                $typeId = (int)$typeId;
                $amount = max(0, (float)$amount);

                if ($typeId <= 0) {
                    continue;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO payroll_deductions (
                        payroll_record_id, deduction_type_id, amount
                    )
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE amount = VALUES(amount)
                ");

                $stmt->execute([$recordId, $typeId, $amount]);
            }

            // Recalculate total deduction.
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(amount), 0)
                FROM payroll_deductions
                WHERE payroll_record_id = ?
            ");

            $stmt->execute([$recordId]);

            $totalDeductions = (float)$stmt->fetchColumn();

            // Fetch gross.
            $stmt = $pdo->prepare("
                SELECT gross_pay
                FROM payroll_records
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$recordId]);

            $grossPay = (float)$stmt->fetchColumn();

            $netPay = max(0.0, round($grossPay - $totalDeductions, 2));

            $stmt = $pdo->prepare("
                UPDATE payroll_records
                SET total_deductions = ?, net_pay = ?
                WHERE id = ?
            ");

            $stmt->execute([$totalDeductions, $netPay, $recordId]);
        }

        $pdo->commit();

        header(
            'Location: admin.php?tab=attendance&view=payroll&week_start='
            . urlencode($weekStart)
            . '&msg=payroll_saved'
        );
        exit;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        header(
            'Location: admin.php?tab=attendance&view=payroll&week_start='
            . urlencode($weekStart)
            . '&msg=payroll_error'
        );
        exit;
    }
}


// =========================================================
// POST: REMOVE DEDUCTION TYPE
// =========================================================
// NOTE: Only non-released payroll rows are affected. Released
// payroll is preserved as historical data.

if (isset($_POST['delete_deduction_type'])) {

    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $typeId = (int)($_POST['deduction_type_id'] ?? 0);
    $weekStart = adminAttendanceGetMonday($_POST['week_start'] ?? date('Y-m-d'));

    if ($typeId <= 0) {
        header(
            'Location: admin.php?tab=attendance&view=payroll&week_start='
            . urlencode($weekStart)
            . '&msg=deduction_delete_error'
        );
        exit;
    }

    try {

        $pdo->beginTransaction();

        // Remove stored values only for non-released payroll records.
        $stmt = $pdo->prepare("
            DELETE pd
            FROM payroll_deductions pd
            INNER JOIN payroll_records pr
                ON pr.id = pd.payroll_record_id
            WHERE pd.deduction_type_id = ?
              AND pr.status <> 'Released'
        ");

        $stmt->execute([$typeId]);

        // Deactivate the column so it no longer appears in payroll.
        $stmt = $pdo->prepare("
            UPDATE payroll_deduction_types
            SET is_active = 0
            WHERE id = ?
        ");

        $stmt->execute([$typeId]);

        // Recalculate non-released payroll records only.
        $stmt = $pdo->query("
            SELECT
                pr.id,
                pr.gross_pay,
                COALESCE(SUM(pd.amount), 0) AS total_deductions
            FROM payroll_records pr
            LEFT JOIN payroll_deductions pd
                ON pd.payroll_record_id = pr.id
            WHERE pr.status <> 'Released'
            GROUP BY pr.id, pr.gross_pay
        ");

        $payrollTotals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $updateStmt = $pdo->prepare("
            UPDATE payroll_records
            SET total_deductions = ?, net_pay = ?
            WHERE id = ?
        ");

        foreach ($payrollTotals as $payroll) {
            $totalDeductions = (float)$payroll['total_deductions'];

            $netPay = max(0, round(
                (float)$payroll['gross_pay'] - $totalDeductions,
                2
            ));

            $updateStmt->execute([
                $totalDeductions,
                $netPay,
                (int)$payroll['id']
            ]);
        }

        $pdo->commit();

        header(
            'Location: admin.php?tab=attendance&view=payroll&week_start='
            . urlencode($weekStart)
            . '&msg=deduction_removed'
        );
        exit;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        header(
            'Location: admin.php?tab=attendance&view=payroll&week_start='
            . urlencode($weekStart)
            . '&msg=deduction_delete_error'
        );
        exit;
    }
}


// =========================================================
// POST: ADD DEDUCTION TYPE
// =========================================================

if (isset($_POST['add_deduction_type'])) {

    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $label = trim($_POST['deduction_label'] ?? '');
    $weekStart = adminAttendanceGetMonday($_POST['week_start'] ?? date('Y-m-d'));

    if ($label === '') {
        header(
            'Location: admin.php?tab=attendance&view=payroll&week_start='
            . urlencode($weekStart)
            . '&msg=deduction_required'
        );
        exit;
    }

    try {

        $stmt = $pdo->prepare("
            INSERT INTO payroll_deduction_types (label, is_active)
            VALUES (?, 1)
        ");

        $stmt->execute([$label]);

        header(
            'Location: admin.php?tab=attendance&view=payroll&week_start='
            . urlencode($weekStart)
            . '&msg=deduction_added'
        );
        exit;

    } catch (PDOException $e) {

        header(
            'Location: admin.php?tab=attendance&view=payroll&week_start='
            . urlencode($weekStart)
            . '&msg=deduction_exists'
        );
        exit;
    }
}


// =========================================================
// POST: APPROVE PAYROLL (SINGLE)
// =========================================================

if (isset($_POST['approve_payroll'])) {

    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $id = (int)($_POST['approve_payroll'] ?? $_POST['payroll_id'] ?? 0);
    $weekStart = adminAttendanceGetMonday($_POST['week_start'] ?? date('Y-m-d'));

    $stmt = $pdo->prepare("
        UPDATE payroll_records
        SET status = 'Approved',
            approved_by = ?,
            approved_at = NOW()
        WHERE id = ?
          AND status = 'For Review'
    ");

    $stmt->execute([$_SESSION['user_id'], $id]);

    header(
        'Location: admin.php?tab=attendance&view=payroll&week_start='
        . urlencode($weekStart)
        . '&msg=payroll_approved'
    );
    exit;
}


// =========================================================
// POST: APPROVE ALL PAYROLL
// =========================================================

if (isset($_POST['approve_all_payroll'])) {

    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $weekStart = adminAttendanceGetMonday($_POST['week_start'] ?? date('Y-m-d'));

    $stmt = $pdo->prepare("
        UPDATE payroll_records
        SET status = 'Approved',
            approved_by = ?,
            approved_at = NOW()
        WHERE week_start = ?
          AND status = 'For Review'
    ");

    $stmt->execute([$_SESSION['user_id'], $weekStart]);

    header(
        'Location: admin.php?tab=attendance&view=payroll&week_start='
        . urlencode($weekStart)
        . '&msg=all_approved'
    );
    exit;
}


// =========================================================
// POST: RELEASE PAYROLL (SINGLE)
// =========================================================

if (isset($_POST['release_payroll'])) {

    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $id = (int)($_POST['release_payroll'] ?? $_POST['payroll_id'] ?? 0);
    $weekStart = adminAttendanceGetMonday($_POST['week_start'] ?? date('Y-m-d'));

    // Fetch employee_id and week_start before updating.
    $stmt = $pdo->prepare("
        SELECT employee_id, week_start
        FROM payroll_records
        WHERE id = ?
    ");

    $stmt->execute([$id]);
    $payroll = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($payroll) {

        $employeeId = (int)$payroll['employee_id'];
        $recordWeekStart = $payroll['week_start'];

        $stmt = $pdo->prepare("
            UPDATE payroll_records
            SET status = 'Released',
                released_by = ?,
                released_at = NOW()
            WHERE id = ?
              AND status = 'Approved'
        ");

        $stmt->execute([$_SESSION['user_id'], $id]);

        // Send notification to the employee.
        try {
            $message = "Your payroll for the week of "
                . date('F d, Y', strtotime($recordWeekStart))
                . " has been released.";

            $notif_stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, project_id, message)
                VALUES (?, NULL, ?)
            ");

            $notif_stmt->execute([$employeeId, $message]);
        } catch (Throwable $e) {
            error_log(
                "Failed to send payroll release notification for employee "
                . $employeeId . ": " . $e->getMessage()
            );
        }

    } else {

        header(
            'Location: admin.php?tab=attendance&view=payroll&week_start='
            . urlencode($weekStart)
            . '&msg=payroll_error'
        );
        exit;
    }

    header(
        'Location: admin.php?tab=attendance&view=payroll&week_start='
        . urlencode($weekStart)
        . '&msg=payroll_released'
    );
    exit;
}


// =========================================================
// POST: RELEASE ALL PAYROLL
// =========================================================

if (isset($_POST['release_all_payroll'])) {

    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $weekStart = adminAttendanceGetMonday($_POST['week_start'] ?? date('Y-m-d'));

    // First get the employee IDs of records that will be released.
    $stmt = $pdo->prepare("
        SELECT employee_id
        FROM payroll_records
        WHERE week_start = ?
          AND status = 'Approved'
    ");

    $stmt->execute([$weekStart]);
    $employeeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare("
        UPDATE payroll_records
        SET status = 'Released',
            released_by = ?,
            released_at = NOW()
        WHERE week_start = ?
          AND status = 'Approved'
    ");

    $stmt->execute([$_SESSION['user_id'], $weekStart]);

    // Send notification to each affected employee.
    if (!empty($employeeIds)) {

        $message = "Your payroll for the week of "
            . date('F d, Y', strtotime($weekStart))
            . " has been released.";

        $notif_stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, project_id, message)
            VALUES (?, NULL, ?)
        ");

        foreach ($employeeIds as $empId) {
            try {
                $notif_stmt->execute([(int)$empId, $message]);
            } catch (Throwable $e) {
                error_log(
                    "Failed to send payroll release notification for employee "
                    . $empId . ": " . $e->getMessage()
                );
            }
        }
    }

    header(
        'Location: admin.php?tab=attendance&view=payroll&week_start='
        . urlencode($weekStart)
        . '&msg=all_released'
    );
    exit;
}


// =========================================================
// GET: GENERATE PAYROLL PDF
// =========================================================

if (
    isset($_GET['generate_payroll_pdf']) &&
    $_GET['generate_payroll_pdf'] === '1'
) {

    $weekStart = adminAttendanceGetMonday($_GET['week_start'] ?? date('Y-m-d'));
    $weekEnd = adminAttendanceGetFriday($weekStart);

    // -----------------------------------------------------
    // Load TCPDF
    // -----------------------------------------------------

    $tcpdfPaths = [
        __DIR__ . '/../tcpdf/tcpdf.php',
        __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php',
        __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf/tcpdf.php'
    ];

    $tcpdfLoaded = false;

    foreach ($tcpdfPaths as $tcpdfPath) {
        if (is_file($tcpdfPath)) {
            require_once $tcpdfPath;
            $tcpdfLoaded = true;
            break;
        }
    }

    if (!$tcpdfLoaded) {
        header(
            'Location: admin.php?tab=attendance&view=payroll&week_start='
            . urlencode($weekStart)
            . '&msg=tcpdf_missing'
        );
        exit;
    }

    // -----------------------------------------------------
    // Company settings
    // -----------------------------------------------------

    $stmt = $pdo->query("
        SELECT company_name, company_subtitle, company_logo
        FROM system_settings
        ORDER BY id ASC
        LIMIT 1
    ");

    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        $company = [
            'company_name' => 'Eng Roofing',
            'company_subtitle' => 'Supply and Installation',
            'company_logo' => ''
        ];
    }

    // -----------------------------------------------------
    // Payroll rows
    // -----------------------------------------------------

    $stmt = $pdo->prepare("
        SELECT pr.*, u.full_name, u.employee_id AS employee_code
        FROM payroll_records pr
        INNER JOIN users u ON u.id = pr.employee_id
        WHERE pr.week_start = ?
        ORDER BY u.full_name ASC
    ");

    $stmt->execute([$weekStart]);

    $pdfRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // -----------------------------------------------------
    // Deduction types
    // -----------------------------------------------------

    $stmt = $pdo->query("
        SELECT id, label
        FROM payroll_deduction_types
        WHERE is_active = 1
        ORDER BY id ASC
    ");

    $pdfDeductionTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // -----------------------------------------------------
    // Build PDF
    // -----------------------------------------------------

    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

    $pdf->SetCreator('ENG Roofing');
    $pdf->SetAuthor($company['company_name']);
    $pdf->SetTitle('Payroll - ' . $weekStart . ' to ' . $weekEnd);
    $pdf->SetMargins(8, 10, 8);
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->AddPage();

    // -----------------------------------------------------
    // Build HTML using output buffering
    // -----------------------------------------------------

    ob_start();
    ?>

    <h1 style="color:#c00000;"><?= htmlspecialchars($company['company_name']) ?></h1>

    <?php if (!empty($company['company_subtitle'])): ?>
        <div style="font-size:11px; color:#555;"><?= htmlspecialchars($company['company_subtitle']) ?></div>
    <?php endif; ?>

    <h2>Weekly Payroll</h2>

    <p>
        <strong>Payroll Period:</strong>
        <?= htmlspecialchars(date('F d, Y', strtotime($weekStart))) ?>
        to
        <?= htmlspecialchars(date('F d, Y', strtotime($weekEnd))) ?>
    </p>

    <table border="1" cellpadding="5" cellspacing="0" width="100%">

        <thead>
            <tr bgcolor="#eeeeee">
                <th>Employee</th>
                <th>Employee ID</th>
                <th>Daily Rate</th>
                <th>Hours Worked</th>
                <th>Gross Pay</th>

                <?php foreach ($pdfDeductionTypes as $type): ?>
                    <th><?= htmlspecialchars($type['label']) ?></th>
                <?php endforeach; ?>

                <th>Total Deductions</th>
                <th>Net Pay</th>
                <th>Status</th>
            </tr>
        </thead>

        <tbody>

            <?php $pdfTotalNet = 0; ?>

            <?php foreach ($pdfRows as $row): ?>

                <tr>
                    <td><?= htmlspecialchars($row['full_name']) ?></td>
                    <td><?= htmlspecialchars($row['employee_code'] ?? $row['employee_id']) ?></td>
                    <td align="right">P<?= number_format((float)$row['daily_rate'], 2) ?></td>
                    <td align="right"><?= number_format((float)$row['hours_worked'], 2) ?></td>
                    <td align="right">P<?= number_format((float)$row['gross_pay'], 2) ?></td>

                    <?php foreach ($pdfDeductionTypes as $type): ?>

                        <?php
                        $stmt = $pdo->prepare("
                            SELECT amount
                            FROM payroll_deductions
                            WHERE payroll_record_id = ?
                              AND deduction_type_id = ?
                            LIMIT 1
                        ");

                        $stmt->execute([$row['id'], $type['id']]);

                        $deductionAmount = (float)$stmt->fetchColumn();
                        ?>

                        <td align="right">P<?= number_format($deductionAmount, 2) ?></td>

                    <?php endforeach; ?>

                    <td align="right">P<?= number_format((float)$row['total_deductions'], 2) ?></td>
                    <td align="right">P<?= number_format((float)$row['net_pay'], 2) ?></td>
                    <td><?= htmlspecialchars($row['status']) ?></td>
                </tr>

                <?php $pdfTotalNet += (float)$row['net_pay']; ?>

            <?php endforeach; ?>

        </tbody>

        <tfoot>
            <tr bgcolor="#eeeeee">
                <th colspan="<?= 5 + count($pdfDeductionTypes) + 1 ?>" align="right">Total Payroll</th>
                <th align="right">P<?= number_format($pdfTotalNet, 2) ?></th>
                <th></th>
            </tr>
        </tfoot>

    </table>

    <br>

    <p>Generated: <?= date('F d, Y h:i A') ?></p>

    <?php

    $html = ob_get_clean();

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output(
        'Payroll_' .
        date('Ymd', strtotime($weekStart)) . '_' .
        date('Ymd', strtotime($weekEnd)) .
        '.pdf',
        'I'
    );
    exit;
}


// =========================================================
// PAGE STATE
// =========================================================

$activeView = $_GET['view'] ?? 'attendance';

if (!in_array($activeView, ['attendance', 'payroll'], true)) {
    $activeView = 'attendance';
}


// =========================================================
// ATTENDANCE DATE
// =========================================================

$attendanceDate = $_GET['attendance_date'] ?? date('Y-m-d');

$dateObject = DateTime::createFromFormat('Y-m-d', $attendanceDate);

if (
    !$dateObject ||
    $dateObject->format('Y-m-d') !== $attendanceDate
) {
    $attendanceDate = date('Y-m-d');
}


// =========================================================
// PAYROLL WEEK
// =========================================================

$weekStart = adminAttendanceGetMonday($_GET['week_start'] ?? date('Y-m-d'));
$weekEnd = adminAttendanceGetFriday($weekStart);


// =========================================================
// LOAD ATTENDANCE
// =========================================================

$attendanceSql = "
    SELECT
        e.id,
        e.employee_id,
        e.full_name,
        e.email,
        e.join_date,

        a.id AS attendance_id,
        a.time_in,
        a.time_out,
        a.status AS attendance_status,
        a.project_id AS attendance_project_id,

        a.latitude,
        a.longitude,
        a.latitude_time_out,
        a.longitude_time_out,

        ap.project_name AS attendance_project_name,

        GROUP_CONCAT(
            DISTINCT p.project_name
            ORDER BY p.project_name
            SEPARATOR ', '
        ) AS active_projects

    FROM users e

    INNER JOIN project_employees pe
        ON pe.employee_id = e.id

    INNER JOIN projects p
        ON p.id = pe.project_id

    LEFT JOIN attendance a
        ON a.employee_id = e.id
       AND a.attendance_date = :attendance_date_join

    LEFT JOIN projects ap
        ON ap.id = a.project_id

    WHERE e.role = 'employee'
      AND e.status = 'active'
      AND (
            e.join_date IS NULL
            OR e.join_date <= :attendance_date_join_2
      )

      AND " . adminAttendanceProjectIsEligibleSql(':attendance_date_where') . "

    GROUP BY
        e.id, e.employee_id, e.full_name, e.email, e.join_date,
        a.id, a.time_in, a.time_out, a.status, a.project_id,
        a.latitude, a.longitude, a.latitude_time_out, a.longitude_time_out,
        ap.project_name

    ORDER BY e.full_name ASC
";

$stmt = $pdo->prepare($attendanceSql);

$stmt->execute([
    ':attendance_date_join' => $attendanceDate,
    ':attendance_date_join_2' => $attendanceDate,
    ':attendance_date_where' => $attendanceDate
]);

$attendanceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);


// =========================================================
// ATTENDANCE TALLY
// =========================================================

$attendanceCounts = [
    'Total Employees' => 0,
    'Present' => 0,
    'Late' => 0,
    'Absent' => 0
];

$attendanceCounts['Total Employees'] = count($attendanceRows);

foreach ($attendanceRows as &$row) {

    if (!empty($row['time_in'])) {
        $status = $row['attendance_status'] ?? 'Present';
    } else {
        $status = 'Absent';
    }

    $row['display_status'] = $status;

    if (isset($attendanceCounts[$status])) {
        $attendanceCounts[$status]++;
    }
}

unset($row);


// =========================================================
// LOAD DEDUCTION TYPES
// =========================================================

$stmt = $pdo->query("
    SELECT id, label
    FROM payroll_deduction_types
    WHERE is_active = 1
    ORDER BY id ASC
");

$deductionTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);


// =========================================================
// LOAD ELIGIBLE PAYROLL EMPLOYEES
// =========================================================

$payrollEligibilitySql = "
    SELECT DISTINCT
        e.id,
        e.employee_id,
        e.full_name,
        e.daily_rate

    FROM users e

    INNER JOIN project_employees pe
        ON pe.employee_id = e.id

    INNER JOIN projects p
        ON p.id = pe.project_id

    WHERE e.role = 'employee'
      AND e.status = 'active'

      AND (
            e.join_date IS NULL
            OR e.join_date <= :week_end_join
      )

      AND p.start_date <= :week_end_project
      AND p.status <> 'cancelled'

      AND (
            " . adminAttendanceProjectCompletionExpression() . " IS NULL
            OR DATE(" . adminAttendanceProjectCompletionExpression() . ") >= :week_start_completion
      )

    ORDER BY e.full_name ASC
";

$stmt = $pdo->prepare($payrollEligibilitySql);

$stmt->execute([
    ':week_end_join' => $weekEnd,
    ':week_end_project' => $weekEnd,
    ':week_start_completion' => $weekStart
]);

$payrollEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);


// =========================================================
// ENSURE PAYROLL RECORDS EXIST
// =========================================================

foreach ($payrollEmployees as $employee) {

    $employeeId = (int)$employee['id'];

    // Calculate weekly hours.
    $stmt = $pdo->prepare("
        SELECT time_in, time_out
        FROM attendance
        WHERE employee_id = ?
          AND attendance_date BETWEEN ?
          AND ?
    ");

    $stmt->execute([$employeeId, $weekStart, $weekEnd]);

    $weekAttendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hoursWorked = 0;

    foreach ($weekAttendance as $attendance) {
        $hoursWorked += adminAttendanceCalculateHours(
            $attendance['time_in'],
            $attendance['time_out']
        );
    }

    $hoursWorked = round($hoursWorked, 2);

    $dailyRate = (float)($employee['daily_rate'] ?? 0);

    $grossPay = round(($hoursWorked / 8) * $dailyRate, 2);

    // If existing payroll is still For Review, synchronize hours/gross.
    $stmt = $pdo->prepare("
        SELECT id, status
        FROM payroll_records
        WHERE employee_id = ?
          AND week_start = ?
        LIMIT 1
    ");

    $stmt->execute([$employeeId, $weekStart]);

    $existingPayroll = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingPayroll) {

        $stmt = $pdo->prepare("
            INSERT INTO payroll_records (
                employee_id, week_start, week_end, daily_rate,
                hours_worked, gross_pay, total_deductions, net_pay, status
            )
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, 'For Review')
        ");

        $stmt->execute([
            $employeeId, $weekStart, $weekEnd, $dailyRate,
            $hoursWorked, $grossPay, $grossPay
        ]);

    } elseif ($existingPayroll['status'] !== 'Released') {

        // Preserve deductions; only update attendance-driven values.
        $stmt = $pdo->prepare("
            UPDATE payroll_records
            SET daily_rate = ?, hours_worked = ?, gross_pay = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $dailyRate, $hoursWorked, $grossPay, $existingPayroll['id']
        ]);

        // Recalculate net.
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM payroll_deductions
            WHERE payroll_record_id = ?
        ");

        $stmt->execute([$existingPayroll['id']]);

        $totalDeductions = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            UPDATE payroll_records
            SET total_deductions = ?, net_pay = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $totalDeductions,
            max(0, $grossPay - $totalDeductions),
            $existingPayroll['id']
        ]);
    }
}


// =========================================================
// FETCH PAYROLL TABLE
// =========================================================

$stmt = $pdo->prepare("
    SELECT pr.*, u.full_name, u.employee_id AS employee_code
    FROM payroll_records pr
    INNER JOIN users u ON u.id = pr.employee_id
    WHERE pr.week_start = ?
    ORDER BY u.full_name ASC
");

$stmt->execute([$weekStart]);

$payrollRows = $stmt->fetchAll(PDO::FETCH_ASSOC);


// =========================================================
// LOAD DEDUCTION VALUES
// =========================================================

$payrollDeductionValues = [];

if (!empty($payrollRows)) {

    $ids = array_column($payrollRows, 'id');

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare("
        SELECT payroll_record_id, deduction_type_id, amount
        FROM payroll_deductions
        WHERE payroll_record_id IN ($placeholders)
    ");

    $stmt->execute($ids);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $payrollDeductionValues[$row['payroll_record_id']][$row['deduction_type_id']]
            = (float)$row['amount'];
    }
}


// =========================================================
// PAYROLL TOTAL
// =========================================================

$totalPayroll = 0;

foreach ($payrollRows as $row) {
    $totalPayroll += (float)$row['net_pay'];
}

$totalPayroll = round($totalPayroll, 2);


// =========================================================
// MESSAGES
// =========================================================

$msg = '';
$msgType = '';

if (isset($_GET['msg'])) {

    $messages = [
        'time_in_success' => ['text' => 'Time In recorded successfully.', 'type' => ''],
        'time_out_success' => ['text' => 'Time Out recorded successfully.', 'type' => ''],
        'invalid_credentials' => ['text' => 'Invalid employee email or password.', 'type' => 'error'],
        'invalid_attendance_action' => ['text' => 'Invalid attendance action.', 'type' => 'error'],
        'not_eligible' => ['text' => 'The employee is not eligible for attendance on the selected date.', 'type' => 'error'],
        'project_not_allowed' => ['text' => 'The selected project is not active for this employee on the selected date.', 'type' => 'error'],
        'invalid_photo' => ['text' => 'The captured photo could not be processed.', 'type' => 'error'],
        'photo_save_error' => ['text' => 'The attendance photo could not be saved.', 'type' => 'error'],
        'payroll_saved' => ['text' => 'Payroll deductions saved successfully.', 'type' => ''],
        'payroll_error' => ['text' => 'Payroll changes could not be saved.', 'type' => 'error'],
        'payroll_approved' => ['text' => 'Payroll approved.', 'type' => ''],
        'payroll_released' => ['text' => 'Payroll released.', 'type' => ''],
        'all_approved' => ['text' => 'All For Review payroll records have been approved.', 'type' => ''],
        'all_released' => ['text' => 'All approved payroll records have been released.', 'type' => ''],
        'deduction_required' => ['text' => 'Please enter a deduction column label.', 'type' => 'error'],
        'deduction_added' => ['text' => 'Deduction column added successfully.', 'type' => ''],
        'deduction_removed' => ['text' => 'Deduction column removed successfully.', 'type' => ''],
        'deduction_delete_error' => ['text' => 'The deduction column could not be removed.', 'type' => 'error'],
        'deduction_exists' => ['text' => 'That deduction column already exists.', 'type' => 'error'],
        'tcpdf_missing' => ['text' => 'TCPDF was not found. Install TCPDF in the project before generating payroll PDFs.', 'type' => 'error']
    ];

    if (isset($messages[$_GET['msg']])) {
        $msg = $messages[$_GET['msg']]['text'];
        $msgType = $messages[$_GET['msg']]['type'];
    }
}

?>

<?php if ($msg): ?><div class="msg <?= htmlspecialchars($msgType) ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- Switch -->
<div class="d-inline-flex p-1 rounded-pill mb-4" style="background: #f3f4f6; gap: 4px;">
    <a href="admin.php?tab=attendance&view=attendance&attendance_date=<?= urlencode($attendanceDate) ?>"
       class="text-decoration-none px-4 py-2 rounded-pill fw-bold"
       style="<?= $activeView === 'attendance' ? 'background: var(--fr-red); color: #fff;' : 'color: var(--fr-muted);' ?>">Attendance</a>
    <a href="admin.php?tab=attendance&view=payroll&week_start=<?= urlencode($weekStart) ?>"
       class="text-decoration-none px-4 py-2 rounded-pill fw-bold"
       style="<?= $activeView === 'payroll' ? 'background: var(--fr-red); color: #fff;' : 'color: var(--fr-muted);' ?>">Payroll</a>
</div>

<?php if ($activeView === 'attendance'): ?>

<!-- Attendance -->
<div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
    <div>
        <h2 class="fw-bold mb-1" style="font-size: 20px;">Employee Attendance</h2>
        <p class="mb-0" style="color: var(--fr-muted); font-size: 13.5px;">View and record attendance for employees assigned to active projects.</p>
    </div>
    <form method="GET" class="d-flex align-items-end gap-2">
        <input type="hidden" name="tab" value="attendance">
        <input type="hidden" name="view" value="attendance">
        <div><label for="attendance_date" class="form-label mb-1" style="font-size: 11.5px;">Date</label>
            <input type="date" id="attendance_date" name="attendance_date" class="form-control form-control-sm" value="<?= htmlspecialchars($attendanceDate) ?>" onchange="this.form.submit()"></div>
    </form>
</div>

<div class="stats">
    <div class="stat-card"><div class="number"><?= $attendanceCounts['Total Employees'] ?></div><div class="label">Total Employees</div></div>
    <div class="stat-card"><div class="number"><?= $attendanceCounts['Present'] ?></div><div class="label">Present</div></div>
    <div class="stat-card"><div class="number"><?= $attendanceCounts['Late'] ?></div><div class="label">Late</div></div>
    <div class="stat-card"><div class="number"><?= $attendanceCounts['Absent'] ?></div><div class="label">Absent</div></div>
</div>

<div class="fr-card mb-3">
    <div class="fr-card-pad" style="font-size: 12.5px; color: var(--fr-muted);">
        Standard start time: <strong style="color: var(--fr-text);">8:00 AM</strong> · Late threshold: <strong style="color: var(--fr-text);"><?= htmlspecialchars(substr(adminAttendanceGetLateThreshold($pdo), 0, 5)) ?></strong>
    </div>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr><th>Employee ID</th><th>Employee</th><th>Time In</th><th>Time In Coords</th><th>Time Out</th><th>Time Out Coords</th><th>Hours</th><th>Project</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php if (empty($attendanceRows)): ?>
            <tr><td colspan="9" class="text-center py-5" style="color: var(--fr-muted);">No employees are assigned to an active project on this date.</td></tr>
        <?php else: ?>
            <?php foreach ($attendanceRows as $row):
                $hours = adminAttendanceCalculateHours($row['time_in'], $row['time_out']);
                $status = $row['display_status'];
                $statusClass = strtolower($status);
            ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['employee_id']) ?></strong></td>
                    <td>
                        <strong><?= htmlspecialchars($row['full_name']) ?></strong>
                        <small style="display: block; color: var(--fr-muted); font-size: 11px;"><?= htmlspecialchars($row['email']) ?></small>
                    </td>
                    <td><?= !empty($row['time_in']) ? htmlspecialchars(date('h:i A', strtotime($row['time_in']))) : '<span class="muted">—</span>' ?></td>
                    <td>
                        <?php $lat = $row['latitude'] ?? null; $lng = $row['longitude'] ?? null;
                        if ($lat && $lng && (float)$lat != 0 && (float)$lng != 0): $coord = (float)$lat . ',' . (float)$lng; $mapUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($coord); ?>
                            <a href="<?= $mapUrl ?>" target="_blank" rel="noopener"><?= htmlspecialchars($coord) ?></a>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </td>
                    <td><?= !empty($row['time_out']) ? htmlspecialchars(date('h:i A', strtotime($row['time_out']))) : '<span class="muted">—</span>' ?></td>
                    <td>
                        <?php $lat = $row['latitude_time_out'] ?? null; $lng = $row['longitude_time_out'] ?? null;
                        if ($lat && $lng && (float)$lat != 0 && (float)$lng != 0): $coord = (float)$lat . ',' . (float)$lng; $mapUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($coord); ?>
                            <a href="<?= $mapUrl ?>" target="_blank" rel="noopener"><?= htmlspecialchars($coord) ?></a>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </td>
                    <td><?= $hours > 0 ? number_format($hours, 2) . ' hrs' : '—' ?></td>
                    <td>
                        <?php if (!empty($row['attendance_project_name'])): ?>
                            <strong><?= htmlspecialchars($row['attendance_project_name']) ?></strong>
                        <?php else: ?>
                            <?= htmlspecialchars($row['active_projects'] ?? '—') ?>
                        <?php endif; ?>
                    </td>
                    <td><span class="fr-badge <?= $statusClass ?>"><?= htmlspecialchars($status) ?></span></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php else: ?>

<!-- Payroll -->
<div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
    <div>
        <h2 class="fw-bold mb-1" style="font-size: 20px;">Weekly Payroll</h2>
        <p class="mb-0" style="color: var(--fr-muted); font-size: 13.5px;">Payroll covers Monday through Friday.</p>
    </div>
    <form method="GET" class="d-flex align-items-end gap-2">
        <input type="hidden" name="tab" value="attendance">
        <input type="hidden" name="view" value="payroll">
        <div><label class="form-label mb-1" style="font-size: 11.5px;">Week</label>
            <input type="date" name="week_start" class="form-control form-control-sm" value="<?= htmlspecialchars($weekStart) ?>" onchange="this.form.submit()"></div>
    </form>
</div>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 p-3 rounded-3 mb-3" style="background: var(--fr-surface); border: 1px solid var(--fr-border);">
    <div><strong><?= date('M d, Y', strtotime($weekStart)) ?></strong> to <strong><?= date('M d, Y', strtotime($weekEnd)) ?></strong></div>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('deductionModal').classList.add('show')">+ Add Deduction</button>
        <form method="POST" class="d-inline"><?= csrf_field() ?><input type="hidden" name="week_start" value="<?= htmlspecialchars($weekStart) ?>"><button type="submit" name="approve_all_payroll" class="btn btn-primary btn-sm" onclick="return confirm('Approve all?')">Approve All</button></form>
        <form method="POST" class="d-inline"><?= csrf_field() ?><input type="hidden" name="week_start" value="<?= htmlspecialchars($weekStart) ?>"><button type="submit" name="release_all_payroll" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Release all?')">Release All</button></form>
        <a href="admin.php?tab=attendance&view=payroll&week_start=<?= urlencode($weekStart) ?>&generate_payroll_pdf=1" class="btn btn-primary btn-sm">Generate PDF</a>
    </div>
</div>

<form method="POST" action="admin.php?tab=attendance&view=payroll">
    <?= csrf_field() ?>
    <input type="hidden" name="week_start" value="<?= htmlspecialchars($weekStart) ?>">

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th rowspan="2">Employee</th><th rowspan="2">Daily Rate</th><th rowspan="2">Hours</th><th rowspan="2">Gross Pay</th>
                    <th colspan="<?= max(1, count($deductionTypes)) ?>">Deductions</th>
                    <th rowspan="2">Net Pay</th><th rowspan="2">Status</th><th rowspan="2">Actions</th>
                </tr>
                <tr>
                    <?php if (empty($deductionTypes)): ?><th>No deduction columns</th>
                    <?php else: foreach ($deductionTypes as $type): ?>
                        <th>
                            <?= htmlspecialchars($type['label']) ?>
                            <button type="button" class="btn btn-sm btn-outline-danger border-0 ms-1" onclick="removeDeductionColumn(<?= (int)$type['id'] ?>, <?= htmlspecialchars(json_encode($type['label'])) ?>)" title="Remove">&times;</button>
                        </th>
                    <?php endforeach; endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($payrollRows)): ?>
                <tr><td colspan="<?= 7 + max(1, count($deductionTypes)) ?>" class="text-center py-5" style="color: var(--fr-muted);">No employees qualified for payroll during this week.</td></tr>
            <?php else: foreach ($payrollRows as $row): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['full_name']) ?></strong><small style="display: block; color: var(--fr-muted); font-size: 11px;"><?= htmlspecialchars($row['employee_code'] ?? $row['employee_id']) ?></small></td>
                    <td><?= adminAttendanceFormatMoney($row['daily_rate']) ?></td>
                    <td><?= number_format((float)$row['hours_worked'], 2) ?></td>
                    <td><?= adminAttendanceFormatMoney($row['gross_pay']) ?></td>
                    <?php if (empty($deductionTypes)): ?><td>—</td>
                    <?php else: foreach ($deductionTypes as $type):
                        $deductionValue = $payrollDeductionValues[$row['id']][$type['id']] ?? 0; ?>
                        <td><input type="number" name="deductions[<?= (int)$row['employee_id'] ?>][<?= (int)$type['id'] ?>]" value="<?= htmlspecialchars(number_format((float)$deductionValue, 2, '.', '')) ?>" min="0" step="0.01" <?= $row['status'] === 'Released' ? 'readonly' : '' ?> class="form-control form-control-sm deduction-input" style="width: 90px;"></td>
                    <?php endforeach; endif; ?>
                    <td class="fw-bold"><?= adminAttendanceFormatMoney($row['net_pay']) ?></td>
                    <td>
                        <span class="fr-badge <?= $row['status'] === 'For Review' ? 'scheduled' : ($row['status'] === 'Approved' ? 'completed' : 'cancelled') ?>"><?= htmlspecialchars($row['status']) ?></span>
                    </td>
                    <td class="actions">
                        <?php if ($row['status'] === 'For Review'): ?>
                            <button type="submit" name="approve_payroll" value="<?= (int)$row['id'] ?>" class="action-btn approve" onclick="return confirm('Approve this payroll?')">Approve</button>
                        <?php elseif ($row['status'] === 'Approved'): ?>
                            <button type="submit" name="release_payroll" value="<?= (int)$row['id'] ?>" class="action-btn edit" onclick="return confirm('Release this payroll?')">Release</button>
                        <?php else: ?>
                            <span class="muted" style="font-size: 12px;">Released</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="<?= 4 + max(1, count($deductionTypes)) ?>" style="text-align: right; font-weight: 700;">Total Payroll</td>
                    <td style="font-size: 17px; font-weight: 700; color: var(--fr-red);"><?= adminAttendanceFormatMoney($totalPayroll) ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="d-flex justify-content-end mt-3">
        <button type="submit" name="save_payroll" id="savePayrollButton" class="btn btn-primary">Save Payroll</button>
    </div>
</form>

<!-- Add Deduction Modal -->
<div id="deductionModal" class="ad-modal">
    <div class="ad-modal-content" style="max-width: 500px;">
        <button type="button" class="close" onclick="document.getElementById('deductionModal').classList.remove('show')">&times;</button>
        <h2>Add Deduction Column</h2>
        <p style="color: var(--fr-muted); font-size: 13px;">Add a label such as SSS, PhilHealth, Pag-IBIG, Cash Advance, etc.</p>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="week_start" value="<?= htmlspecialchars($weekStart) ?>">
            <div class="form-group"><label>Deduction Label</label><input type="text" name="deduction_label" maxlength="100" required></div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('deductionModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="add_deduction_type" class="btn btn-primary">Add Column</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<div id="csrfTemplate" style="display: none;"><?= csrf_field() ?></div>


<script>

/* =========================================================
   ATTENDANCE CAMERA / AUTHENTICATION
   ========================================================= */

let attendanceMode = 'time_in';
let attendanceStream = null;
let verifiedEmployee = null;
let attendancePhotoData = null;


function getCsrfToken()
{
    const el = document.querySelector(
        '#csrfTemplate input[name="csrf_token"]'
    );

    return el ? el.value : '';
}



function removeDeductionColumn(typeId, label)
{
    if (!confirm('Remove the "' + label + '" deduction column? This will also remove the saved amounts for this deduction and recalculate Net Pay.')) {
        return;
    }

    const form = document.createElement('form');

    form.method = 'POST';
    form.action = 'admin.php?tab=attendance&view=payroll';

    const fields = {
        csrf_token: getCsrfToken(),
        delete_deduction_type: '1',
        deduction_type_id: String(typeId),
        week_start: <?= json_encode($weekStart) ?>
    };

    Object.keys(fields).forEach(function(name)
    {
        const input = document.createElement('input');

        input.type = 'hidden';
        input.name = name;
        input.value = fields[name];

        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
}


document.addEventListener('DOMContentLoaded', function()
{
    const payrollForm = document.querySelector(
        'form[action="admin.php?tab=attendance&view=payroll"]'
    );

    const saveButton = document.getElementById('savePayrollButton');

    if (!payrollForm || !saveButton) {
        return;
    }

    payrollForm.querySelectorAll('.deduction-input').forEach(function(input)
    {
        input.addEventListener('keydown', function(event)
        {
            if (event.key !== 'Enter') {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            payrollForm.requestSubmit(saveButton);
        });
    });
});

</script>