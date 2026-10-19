<?php
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { die('Invalid employee account.'); }

function employeeMonday($date) { $d=new DateTime($date); if ((int)$d->format('N')!==1) $d->modify('monday this week'); return $d->format('Y-m-d'); }
function employeeFriday($monday) { $d=new DateTime($monday); $d->modify('+4 days'); return $d->format('Y-m-d'); }

$weekStart = employeeMonday($_GET['week_start'] ?? date('Y-m-d'));
$weekEnd = employeeFriday($weekStart);

// Fetch the payroll record for the week.
// Also fetch the CURRENT daily_rate and employee_id from users so that
// any change the admin made in User Management is reflected here even
// before the payroll record is regenerated.
$stmt = $pdo->prepare("
    SELECT
        pr.*,
        u.full_name,
        u.employee_id AS employee_code,
        u.daily_rate  AS user_daily_rate
    FROM payroll_records pr
    INNER JOIN users u ON u.id = pr.employee_id
    WHERE pr.employee_id = ? AND pr.week_start = ?
    LIMIT 1
");
$stmt->execute([$userId, $weekStart]);
$payroll = $stmt->fetch(PDO::FETCH_ASSOC);

// If the payroll row exists but the user's current rate differs, use
// the user's current rate for display AND recalculate gross/net using
// the stored hours. This keeps the tab responsive to admin changes
// without waiting for the admin to open the attendance tab.
if ($payroll) {
    $hoursWorked = (float)$payroll['hours_worked'];
    $currentRate = (float)($payroll['user_daily_rate'] ?? $payroll['daily_rate']);

    if ($currentRate != (float)$payroll['daily_rate']) {
        $payroll['daily_rate'] = $currentRate;
        $payroll['gross_pay']   = round(($hoursWorked / 8) * $currentRate, 2);
        $payroll['net_pay']     = max(
            0,
            round($payroll['gross_pay'] - (float)$payroll['total_deductions'], 2)
        );
    }
}

$deductionTypes = $pdo->query("SELECT id, label FROM payroll_deduction_types WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$deductionValues = [];
$totalDeductions = $payroll ? (float)$payroll['total_deductions'] : 0;
if ($payroll) {
    $stmt = $pdo->prepare("SELECT deduction_type_id, amount FROM payroll_deductions WHERE payroll_record_id = ?");
    $stmt->execute([$payroll['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $deductionValues[(int)$d['deduction_type_id']] = (float)$d['amount'];
    }
}
// =========================================================
// GENERATE PAYSLIP PDF
// =========================================================
if (isset($_GET['generate_payslip']) && $_GET['generate_payslip'] === '1') {

    // Clear any buffered output from employee.php's ob_start().
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // ---------------------------------------------------------
    // Load TCPDF
    // ---------------------------------------------------------
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
        die('TCPDF library not found. Install TCPDF in the project.');
    }

    if (!$payroll) {
        die('No payroll record is available for this week.');
    }

    // ---------------------------------------------------------
    // Company settings
    // ---------------------------------------------------------
    $stmt = $pdo->query("
        SELECT company_name, company_subtitle, company_logo,
               company_address, company_phone, company_email
        FROM system_settings
        ORDER BY id ASC
        LIMIT 1
    ");
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        $company = [
            'company_name'     => 'Eng Roofing',
            'company_subtitle' => 'Supply and Installation',
            'company_logo'     => '',
            'company_address'  => '',
            'company_phone'    => '',
            'company_email'    => ''
        ];
    }

    // Absolute path for the logo (TCPDF needs a real filesystem path).
    $logoSrc = '';
    if (!empty($company['company_logo'])) {
        $candidate = __DIR__ . '/../' . ltrim($company['company_logo'], '/');
        if (is_file($candidate)) {
            $logoData = @file_get_contents($candidate);
            if ($logoData !== false) {
                $logoExt = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
                if ($logoExt === 'jpg') $logoExt = 'jpeg';
                $logoSrc = 'data:image/' . $logoExt . ';base64,' . base64_encode($logoData);
            }
        }
    }

    // ---------------------------------------------------------
    // Build PDF
    // ---------------------------------------------------------
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    $pdf->SetCreator($company['company_name']);
    $pdf->SetAuthor($company['company_name']);
    $pdf->SetTitle('Payslip - ' . $weekStart . ' to ' . $weekEnd);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    ob_start();
    ?>

    <style>
        .doc-title    { color:#c00000; font-size:22px; font-weight:bold; }
        .sub          { color:#666; font-size:10px; }
        .section-title{ color:#333; font-size:13px; border-bottom:1px solid #ddd; padding-bottom:4px; margin-top:14px; }
        .money        { text-align:right; }
        .lbl          { background-color:#f5f5f5; font-weight:bold; }
    </style>

    <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td width="20%" align="left" valign="middle">
                <?php if ($logoSrc): ?>
                    <img src="<?= $logoSrc ?>" style="height:60px;">
                <?php endif; ?>
            </td>
            <td width="60%" align="center" valign="middle">
                <div class="doc-title"><?= htmlspecialchars($company['company_name']) ?></div>
                <?php if (!empty($company['company_subtitle'])): ?>
                    <div class="sub"><?= htmlspecialchars($company['company_subtitle']) ?></div>
                <?php endif; ?>
                <?php if (!empty($company['company_address'])): ?>
                    <div class="sub"><?= htmlspecialchars($company['company_address']) ?></div>
                <?php endif; ?>
                <?php if (!empty($company['company_phone']) || !empty($company['company_email'])): ?>
                    <div class="sub">
                        <?= htmlspecialchars($company['company_phone']) ?>
                        <?= (!empty($company['company_phone']) && !empty($company['company_email'])) ? ' &middot; ' : '' ?>
                        <?= htmlspecialchars($company['company_email']) ?>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <h2 style="text-align:center; color:#333; margin:14px 0 10px;">EMPLOYEE PAYSLIP</h2>

    <table width="100%" cellpadding="5" cellspacing="0" border="1" style="border-color:#dddddd;">
        <tr>
            <td width="25%" class="lbl">Employee Name</td>
            <td width="25%"><?= htmlspecialchars($payroll['full_name']) ?></td>
            <td width="25%" class="lbl">Employee ID</td>
            <td width="25%"><?= htmlspecialchars($payroll['employee_code'] ?? '—') ?></td>
        </tr>
        <tr>
            <td class="lbl">Daily Rate</td>
            <td>P<?= number_format((float)$payroll['daily_rate'], 2) ?></td>
            <td class="lbl">Payroll Status</td>
            <td><?= htmlspecialchars($payroll['status']) ?></td>
        </tr>
        <tr>
            <td class="lbl">Pay Period</td>
            <td colspan="3">
                <?= htmlspecialchars(date('F d, Y', strtotime($weekStart))) ?>
                to
                <?= htmlspecialchars(date('F d, Y', strtotime($weekEnd))) ?>
            </td>
        </tr>
    </table>


    <h3 class="section-title">DEDUCTIONS</h3>

    <table width="100%" cellpadding="5" cellspacing="0" border="1" style="border-color:#dddddd;">
        <thead>
            <tr style="background-color:#f5f5f5;">
                <th align="left" width="70%">Description</th>
                <th align="right" width="30%">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($deductionTypes)): ?>
                <tr><td colspan="2" align="center" style="color:#888;">No deductions applied.</td></tr>
            <?php else: ?>
                <?php foreach ($deductionTypes as $d):
                    $amt = (float)($deductionValues[(int)$d['id']] ?? 0);
                ?>
                    <tr>
                        <td><?= htmlspecialchars($d['label']) ?></td>
                        <td class="money">P<?= number_format($amt, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr style="background-color:#fafafa;">
                <th align="left">Total Deductions</th>
                <th align="right">P<?= number_format((float)$payroll['total_deductions'], 2) ?></th>
            </tr>
        </tfoot>
    </table>

    <h3 class="section-title">NET PAY</h3>

    <table width="100%" cellpadding="8" cellspacing="0" border="1" style="border-color:#c00000;">
        <tr style="background-color:#fff5f5;">
            <td><strong style="color:#c00000; font-size:14px;">NET PAY</strong></td>
            <td align="right">
                <strong style="color:#c00000; font-size:18px;">
                    P<?= number_format((float)$payroll['net_pay'], 2) ?>
                </strong>
            </td>
        </tr>
    </table>

    <br><br>

    <table width="100%">
        <tr>
            <td width="50%">
                <div class="sub">Generated: <?= date('F d, Y h:i A') ?></div>
                <div class="sub">This payslip is system-generated and does not require a signature.</div>
            </td>
            <td width="50%" align="right">
                <div class="sub">___________________________</div>
                <div class="sub">Authorized Signature</div>
            </td>
        </tr>
    </table>

    <?php
    $html = ob_get_clean();

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output(
        'Payslip_' .
        date('Ymd', strtotime($weekStart)) . '_' .
        date('Ymd', strtotime($weekEnd)) .
        '.pdf',
        'I'
    );
    exit;
}
?>
<div class="top-bar">
    <div>
        <h2>Weekly Payroll</h2>
        <p>Monday to Friday payroll information.</p>
    </div>

    <div class="d-flex gap-2 align-items-center flex-wrap">
        <form method="GET" action="employee.php" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="tab" value="payroll">
            <label for="week_start" class="mb-0"><strong>Week</strong></label>
            <input type="date" id="week_start" name="week_start"
                   value="<?= htmlspecialchars($weekStart) ?>"
                   onchange="this.form.submit()">
        </form>

        <?php if ($payroll): ?>
            <a href="employee.php?tab=payroll&week_start=<?= urlencode($weekStart) ?>&generate_payslip=1"
               class="btn btn-primary"
               target="_blank" rel="noopener">
                <i class="bi bi-download me-1"></i>Generate Payslip
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Employee ID</th>
                <th>Daily Rate</th>
                <th>Hours Worked</th>
                <th>Gross Pay</th>
                <?php foreach ($deductionTypes as $d): ?>
                    <th><?= htmlspecialchars($d['label']) ?></th>
                <?php endforeach; ?>
                <th>Total Deductions</th>
                <th>Net Pay</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$payroll): ?>
            <tr>
                <td colspan="<?= 9 + count($deductionTypes) ?>" class="text-center py-5" style="color: var(--fr-muted);">
                    <i class="bi bi-cash-coin" style="font-size: 28px; opacity: .4; display: block; margin-bottom: 8px;"></i>
                    No payroll record is available for this week yet.
                </td>
            </tr>
        <?php else:
            $statusClass = strtolower(str_replace(' ', '-', $payroll['status']));
        ?>
            <tr>
                <td><strong><?= htmlspecialchars($payroll['full_name']) ?></strong></td>
                <td><?= htmlspecialchars($payroll['employee_code'] ?? '—') ?></td>
                <td>P<?= number_format((float)$payroll['daily_rate'], 2) ?></td>
                <td><?= number_format((float)$payroll['hours_worked'], 2) ?></td>
                <td>P<?= number_format((float)$payroll['gross_pay'], 2) ?></td>
                <?php foreach ($deductionTypes as $d): ?>
                    <td>P<?= number_format((float)($deductionValues[(int)$d['id']] ?? 0), 2) ?></td>
                <?php endforeach; ?>
                <td>P<?= number_format((float)$payroll['total_deductions'], 2) ?></td>
                <td><strong style="color: var(--fr-red); font-size: 15px;">P<?= number_format((float)$payroll['net_pay'], 2) ?></strong></td>
                <td>
                    <span class="status-badge status-<?= htmlspecialchars($statusClass) ?>">
                        <?= htmlspecialchars($payroll['status']) ?>
                    </span>
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
        <?php if ($payroll): ?>
            <tfoot>
                <tr>
                    <th colspan="<?= 8 + count($deductionTypes) ?>" style="text-align: right;">Weekly Net Pay</th>
                    <th style="color: var(--fr-red); font-size: 15px;">P<?= number_format((float)$payroll['net_pay'], 2) ?></th>
                    <th></th>
                </tr>
            </tfoot>
        <?php endif; ?>
    </table>
</div>