<?php
// This file is included inside admin.php.


// ---------------------------------------------------------
// Record payment
// ---------------------------------------------------------
if (isset($_POST['record_payment'])) {

    // CSRF validation
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $payment_db_id = (int)$_POST['payment_db_id'];

    $payment_type = trim($_POST['payment_type'] ?? '');
    $amount = (float) str_replace(',', '', $_POST['amount'] ?? '0');
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');

    $allowed_payment_types = [
        'Down Payment',
        'Progress Payment',
        'Final Payment'
    ];

    if (!in_array($payment_type, $allowed_payment_types, true)) {
        header('Location: admin.php?tab=payments&msg=invalid_type');
        exit;
    }

    if ($amount <= 0) {
        header('Location: admin.php?tab=payments&msg=invalid_amount');
        exit;
    }


    try {

        $pdo->beginTransaction();


        // Lock payment record.
        $stmt = $pdo->prepare("
            SELECT *
            FROM payments
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([$payment_db_id]);

        $payment = $stmt->fetch(PDO::FETCH_ASSOC);


        if (!$payment) {

            $pdo->rollBack();

            header('Location: admin.php?tab=payments&msg=not_found');
            exit;
        }


        $remaining = round((float)$payment['remaining'], 2);
        $down_payment = round((float)$payment['down_payment'], 2);
        
        
        // Prevent overpayment of the total balance.
        if ($amount > $remaining) {
        
            $pdo->rollBack();
        
            header('Location: admin.php?tab=payments&msg=overpayment');
            exit;
        }
        
        
        // Sum previous Down Payment entries so we cap against the *remaining*
        // required down payment, not the total.
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM payment_history
            WHERE payment_id = ?
              AND payment_type = 'Down Payment'
        ");
        $stmt->execute([$payment_db_id]);

        $down_payment_paid_so_far = (float)$stmt->fetchColumn();
        $down_payment_remaining   = max(0.0, round($down_payment - $down_payment_paid_so_far, 2));

        if ($payment_type === 'Down Payment' && $down_payment > 0 && $amount > $down_payment_remaining) {
            $pdo->rollBack();
            header('Location: admin.php?tab=payments&msg=down_payment_exceeded');
            exit;
        }


        $new_paid = round(
            (float)$payment['paid'] + $amount,
            2
        );

        $total = (float)$payment['total'];
        $down_payment = (float)$payment['down_payment'];

        $new_remaining = max(
            0,
            round($total - $new_paid, 2)
        );


        // Status:
        // Unpaid = paid below required down payment
        // Down Payment Paid = down payment reached
        // Fully Paid = total reached
        if ($new_paid >= $total && $total > 0) {
            $new_status = 'Fully Paid';
        } elseif ($new_paid >= $down_payment && $down_payment > 0) {
            $new_status = 'Down Payment Paid';
        } else {
            $new_status = 'Unpaid';
        }


        // Update payment summary.
        $stmt = $pdo->prepare("
            UPDATE payments
            SET
                paid = :paid,
                remaining = :remaining,
                status = :status
            WHERE id = :id
        ");

        $stmt->execute([
            ':paid' => $new_paid,
            ':remaining' => $new_remaining,
            ':status' => $new_status,
            ':id' => $payment_db_id
        ]);


        // Add history.
        $stmt = $pdo->prepare("
            INSERT INTO payment_history (
                payment_id,
                payment_type,
                amount,
                payment_date
            )
            VALUES (
                :payment_id,
                :payment_type,
                :amount,
                :payment_date
            )
        ");

        $stmt->execute([
            ':payment_id' => $payment_db_id,
            ':payment_type' => $payment_type,
            ':amount' => $amount,
            ':payment_date' => $payment_date
        ]);
        
        // Payment clearance does not confirm a date or start actual work.
        // Admin confirms scheduling in Projects, then uses Start Project.
        if (in_array($new_status, ['Down Payment Paid', 'Fully Paid'], true)) {
            $stmt = $pdo->prepare("
                SELECT id, status FROM projects WHERE id = ? LIMIT 1 FOR UPDATE
            ");
            $stmt->execute([$payment['project_id']]);
            $linkedProject = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($linkedProject && in_array(
                strtolower(trim((string)$linkedProject['status'])),
                ['', 'awaiting down payment', 'waiting for down payment', 'pending'],
                true
            )) {
                $stmt = $pdo->prepare("UPDATE projects SET status = 'pending' WHERE id = ?");
                $stmt->execute([$linkedProject['id']]);
            }
        }

        $pdo->commit();

        header('Location: admin.php?tab=payments&msg=payment_recorded');
        exit;

    } catch (Exception $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        header('Location: admin.php?tab=payments&msg=error');
        exit;
    }
}


// ---------------------------------------------------------
// Fetch payments
// ---------------------------------------------------------
$stmt = $pdo->query("
    SELECT
        py.*,
        p.project_name,
        u.full_name AS client_name,
        q.quotation_id
    FROM payments py
    JOIN projects p ON py.project_id = p.id
    JOIN users u ON py.client_id = u.id
    JOIN quotations q ON py.quotation_id = q.id
    ORDER BY py.created_at DESC
");

$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ---------------------------------------------------------
// Fetch history for every payment
// ---------------------------------------------------------
$paymentHistory = [];

if (!empty($payments)) {

    $paymentIds = array_column($payments, 'id');

    $placeholders = implode(
        ',',
        array_fill(0, count($paymentIds), '?')
    );

    $stmt = $pdo->prepare("
        SELECT *
        FROM payment_history
        WHERE payment_id IN ($placeholders)
        ORDER BY payment_date DESC, created_at DESC
    ");

    $stmt->execute($paymentIds);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

        $paymentHistory[$row['payment_id']][] = $row;
    }
}

// ---------------------------------------------------------
// Sum DP payments per payment_id. Passed to the Record modal
// so the client-side cap matches the server-side cap.
// ---------------------------------------------------------
$dpPaidPerPayment = [];

foreach ($paymentHistory as $payment_id => $rows) {
    $sum = 0.0;
    foreach ($rows as $h) {
        if ($h['payment_type'] === 'Down Payment') {
            $sum += (float)$h['amount'];
        }
    }
    $dpPaidPerPayment[$payment_id] = $sum;
}


// ---------------------------------------------------------
// Messages
// ---------------------------------------------------------
$msg = '';
$msg_type = '';

if (isset($_GET['msg'])) {

    $messages = [

        'payment_recorded' => [
            'text' => 'Payment recorded successfully.',
            'type' => ''
        ],

        'invalid_type' => [
            'text' => 'Invalid payment type.',
            'type' => 'error'
        ],

        'invalid_amount' => [
            'text' => 'Payment amount must be greater than zero.',
            'type' => 'error'
        ],

        'overpayment' => [
            'text' => 'Payment cannot be greater than the remaining balance.',
            'type' => 'error'
        ],
        
        'down_payment_exceeded' => [
            'text' => 'Down Payment cannot exceed the required down payment amount.',
            'type' => 'error'
        ],

        'not_found' => [
            'text' => 'Payment record not found.',
            'type' => 'error'
        ],

        'error' => [
            'text' => 'An error occurred while recording the payment.',
            'type' => 'error'
        ]
    ];

    if (isset($messages[$_GET['msg']])) {

        $msg = $messages[$_GET['msg']]['text'];
        $msg_type = $messages[$_GET['msg']]['type'];
    }
}
// =========================================================
// GENERATE BILLING STATEMENT PDF
// =========================================================
if (isset($_GET['generate_billing']) && (int)$_GET['generate_billing'] > 0) {

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

    $billing_id = (int)$_GET['generate_billing'];

    // ---------------------------------------------------------
    // Fetch the payment record
    // ---------------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT
            py.*,
            p.project_name,
            p.project_id AS project_code,
            u.full_name AS client_name,
            u.email     AS client_email,
            u.phone     AS client_phone,
            u.address   AS client_address,
            q.quotation_id,
            q.status    AS quotation_status
        FROM payments py
        JOIN projects p    ON py.project_id = p.id
        JOIN users u       ON py.client_id = u.id
        JOIN quotations q  ON py.quotation_id = q.id
        WHERE py.id = ?
        LIMIT 1
    ");

    $stmt->execute([$billing_id]);
    $billingPayment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$billingPayment) {
        die('Payment record not found.');
    }

    // ---------------------------------------------------------
    // Fetch payment history
    // ---------------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT *
        FROM payment_history
        WHERE payment_id = ?
        ORDER BY payment_date ASC, created_at ASC
    ");

    $stmt->execute([$billing_id]);
    $billingHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ---------------------------------------------------------
    // Company settings
    // ---------------------------------------------------------
    $stmt = $pdo->query("
        SELECT company_name, company_subtitle, company_logo,
               company_address, company_phone, company_email, company_tin
        FROM system_settings
        ORDER BY id ASC
        LIMIT 1
    ");
    $billingCompany = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$billingCompany) {
        $billingCompany = [
            'company_name'     => 'Eng Roofing',
            'company_subtitle' => 'Supply and Installation',
            'company_logo'     => '',
            'company_address'  => '',
            'company_phone'    => '',
            'company_email'    => '',
            'company_tin'      => ''
        ];
    }

    $billingLogoSrc = '';
    if (!empty($billingCompany['company_logo'])) {
        $candidate = __DIR__ . '/../' . ltrim($billingCompany['company_logo'], '/');
        if (is_file($candidate)) {
            $logoData = @file_get_contents($candidate);
            if ($logoData !== false) {
                $logoExt = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
                if ($logoExt === 'jpg') $logoExt = 'jpeg';
                $billingLogoSrc = 'data:image/' . $logoExt . ';base64,' . base64_encode($logoData);
            }
        }
    }

    // ---------------------------------------------------------
    // Build PDF
    // ---------------------------------------------------------
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    $pdf->SetCreator($billingCompany['company_name']);
    $pdf->SetAuthor($billingCompany['company_name']);
    $pdf->SetTitle('Billing Statement - ' . $billingPayment['payment_id']);
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
        .status-pill  { display:inline-block; padding:3px 10px; border-radius:10px; font-size:10px; font-weight:bold; }
        .status-paid  { background:#e0f5e7; color:#1f7a44; border:1px solid #b6e3c4; }
        .status-dp    { background:#fff6e0; color:#8a6d1c; border:1px solid #f0dfa6; }
        .status-unpaid{ background:#fde6e6; color:#8a1c1c; border:1px solid #f0b6b6; }
    </style>

    <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td width="20%" align="left" valign="middle">
                <?php if ($billingLogoSrc): ?>
                    <img src="<?= $billingLogoSrc ?>" style="height:60px;">
                <?php endif; ?>
            </td>
            <td width="60%" align="center" valign="middle">
                <div class="doc-title"><?= htmlspecialchars($billingCompany['company_name']) ?></div>
                <?php if (!empty($billingCompany['company_subtitle'])): ?>
                    <div class="sub"><?= htmlspecialchars($billingCompany['company_subtitle']) ?></div>
                <?php endif; ?>
                <?php if (!empty($billingCompany['company_address'])): ?>
                    <div class="sub"><?= htmlspecialchars($billingCompany['company_address']) ?></div>
                <?php endif; ?>
                <?php
                $billingContactParts = [];
                if (!empty($billingCompany['company_phone'])) $billingContactParts[] = $billingCompany['company_phone'];
                if (!empty($billingCompany['company_email'])) $billingContactParts[] = $billingCompany['company_email'];
                if (!empty($billingCompany['company_tin']))   $billingContactParts[] = 'TIN: ' . $billingCompany['company_tin'];
                ?>
                <?php if (!empty($billingContactParts)): ?>
                    <div class="sub"><?= htmlspecialchars(implode(' · ', $billingContactParts)) ?></div>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <h2 style="text-align:center; color:#333; margin:14px 0 10px;">BILLING STATEMENT</h2>

    <table width="100%" cellpadding="5" cellspacing="0" border="1" style="border-color:#dddddd;">
        <tr>
            <td width="25%" class="lbl">Billing No.</td>
            <td width="25%"><?= htmlspecialchars($billingPayment['payment_id']) ?></td>
            <td width="25%" class="lbl">Date Issued</td>
            <td width="25%"><?= date('F d, Y') ?></td>
        </tr>
        <tr>
            <td class="lbl">Client</td>
            <td colspan="3">
                <strong><?= htmlspecialchars($billingPayment['client_name']) ?></strong>
                <?php if (!empty($billingPayment['client_email'])): ?>
                    <div class="sub"><?= htmlspecialchars($billingPayment['client_email']) ?></div>
                <?php endif; ?>
                <?php if (!empty($billingPayment['client_phone'])): ?>
                    <div class="sub"><?= htmlspecialchars($billingPayment['client_phone']) ?></div>
                <?php endif; ?>
                <?php if (!empty($billingPayment['client_address'])): ?>
                    <div class="sub"><?= htmlspecialchars($billingPayment['client_address']) ?></div>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td class="lbl">Project</td>
            <td><?= htmlspecialchars($billingPayment['project_name']) ?></td>
            <td class="lbl">Project ID</td>
            <td><?= htmlspecialchars($billingPayment['project_code']) ?></td>
        </tr>
        <tr>
            <td class="lbl">Quotation ID</td>
            <td><?= htmlspecialchars($billingPayment['quotation_id']) ?></td>
            <td class="lbl">Quotation Status</td>
            <td><?= htmlspecialchars($billingPayment['quotation_status']) ?></td>
        </tr>
    </table>

    <h3 class="section-title">BILLING SUMMARY</h3>
    <table width="100%" cellpadding="6" cellspacing="0" border="1" style="border-color:#dddddd;">
        <tbody>
            <tr>
                <td width="60%">Total Contract Amount</td>
                <td width="40%" align="right"><strong>P<?= number_format((float)$billingPayment['total'], 2) ?></strong></td>
            </tr>
            <tr>
                <td width="60%">Required Down Payment</td>
                <td width="40%" align="right">P<?= number_format((float)$billingPayment['down_payment'], 2) ?></td>
            </tr>
            <tr>
                <td width="60%">Amount Paid</td>
                <td width="40%" align="right">P<?= number_format((float)$billingPayment['paid'], 2) ?></td>
            </tr>
            <tr style="background-color:#fff5f5;">
                <td width="60%"><strong style="color:#c00000;">Remaining Balance</strong></td>
                <td width="40%" align="right">
                    <strong style="color:#c00000; font-size:15px;">
                        P<?= number_format((float)$billingPayment['remaining'], 2) ?>
                    </strong>
                </td>
            </tr>
            <tr>
                <td width="60%">Payment Status</td>
                <td width="40%" align="right">
                    <?php
                    $billingStatus = $billingPayment['status'];
                    $billingStatusClass = 'status-unpaid';
                    if ($billingStatus === 'Fully Paid') {
                        $billingStatusClass = 'status-paid';
                    } elseif ($billingStatus === 'Down Payment Paid') {
                        $billingStatusClass = 'status-dp';
                    }
                    ?>
                    <span class="status-pill <?= $billingStatusClass ?>">
                        <?= htmlspecialchars($billingStatus) ?>
                    </span>
                </td>
            </tr>
        </tbody>
    </table>

    <h3 class="section-title">PAYMENT HISTORY</h3>

    <table width="100%" cellpadding="5" cellspacing="0" border="1" style="border-color:#dddddd;">
        <thead>
            <tr style="background-color:#f5f5f5;">
                <th align="left" width="10%">#</th>
                <th align="left" width="40%">Payment Type</th>
                <th align="left" width="25%">Date</th>
                <th align="right" width="25%">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($billingHistory)): ?>
                <tr>
                    <td colspan="4" align="center" style="color:#888;">
                        No payments recorded yet.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($billingHistory as $i => $h): ?>
                    <tr>
                        <td width="10%"><?= (int)($i + 1) ?></td>
                        <td width="40%"><?= htmlspecialchars($h['payment_type']) ?></td>
                        <td width="25%"><?= htmlspecialchars(date('M d, Y', strtotime($h['payment_date']))) ?></td>
                        <td width="25%" align="right">PHP <?= number_format((float)$h['amount'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr style="background-color:#fafafa;">
                <th colspan="3" align="right" width="75%">Total Paid</th>
                <th align="right" width="25%">PHP <?= number_format((float)$billingPayment['paid'], 2) ?></th>
            </tr>
        </tfoot>
    </table>

    <br><br>

    <table width="100%">
        <tr>
            <td width="50%">
                <div class="sub">Generated: <?= date('F d, Y h:i A') ?></div>
                <div class="sub">This billing statement is system-generated. Please retain for your records.</div>
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
        'BillingStatement_' .
        $billingPayment['payment_id'] . '_' .
        date('Ymd') .
        '.pdf',
        'I'
    );
    exit;
}
?>


<?php if ($msg): ?><div class="msg <?= htmlspecialchars($msg_type) ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="mb-4">
    <h2 class="fw-bold mb-1" style="font-size: 20px;">Payments</h2>
    <p class="mb-0" style="color: var(--fr-muted); font-size: 13.5px;">Track approved quotation payments and record payment transactions.</p>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr><th>Payment ID</th><th>Project</th><th>Client</th><th>Total</th><th>Down Payment</th><th>Paid</th><th>Remaining</th><th>Status</th><th style="text-align: center">Actions</th></tr>
        </thead>
        <tbody>
        <?php if (empty($payments)): ?>
            <tr><td colspan="9" class="text-center py-5" style="color: var(--fr-muted);">No payment records found.</td></tr>
        <?php else: ?>
            <?php foreach ($payments as $payment): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($payment['payment_id']) ?></strong></td>
                    <td><?= htmlspecialchars($payment['project_name']) ?></td>
                    <td><?= htmlspecialchars($payment['client_name']) ?></td>
                    <td>P<?= number_format((float)$payment['total'], 2) ?></td>
                    <td>P<?= number_format((float)$payment['down_payment'], 2) ?></td>
                    <td>P<?= number_format((float)$payment['paid'], 2) ?></td>
                    <td>P<?= number_format((float)$payment['remaining'], 2) ?></td>
                    <td>
                        <span class="fr-badge <?= $payment['status'] === 'Fully Paid' ? 'completed' : ($payment['status'] === 'Down Payment Paid' ? 'scheduled' : 'rejected') ?>">
                            <?= htmlspecialchars($payment['status']) ?>
                        </span>
                    </td>
                    <td class="actions">
                        <button type="button" class="action-btn view-btn" onclick="document.getElementById('historyModal<?= (int)$payment['id'] ?>').classList.add('show')">History</button>
                        <?php if ((float)$payment['remaining'] > 0): ?>
                            <button type="button" class="action-btn edit-btn" onclick="openPaymentModal(<?= (int)$payment['id'] ?>, <?= htmlspecialchars(json_encode($payment['remaining']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($payment['down_payment']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($payment['status']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($dpPaidPerPayment[$payment['id']] ?? 0), ENT_QUOTES) ?>)">Record</button>
                        <?php endif; ?>
                        <a href="admin.php?tab=payments&generate_billing=<?= (int)$payment['id'] ?>" class="action-btn pdf-btn" target="_blank" rel="noopener">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        </a>
                    </td>
                </tr>

                <!-- History Modal -->
                <div id="historyModal<?= (int)$payment['id'] ?>" class="ad-modal">
                    <div class="ad-modal-content" style="max-width: 750px;">
                        <button type="button" class="close" onclick="document.getElementById('historyModal<?= (int)$payment['id'] ?>').classList.remove('show')">&times;</button>
                        <h2>Payment History – <?= htmlspecialchars($payment['payment_id']) ?></h2>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 11px; color: var(--fr-muted); font-weight: 600;">Project</div><div style="font-weight: 700; font-size: 13px;"><?= htmlspecialchars($payment['project_name']) ?></div></div></div>
                            <div class="col-md-4"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 11px; color: var(--fr-muted); font-weight: 600;">Client</div><div style="font-weight: 700; font-size: 13px;"><?= htmlspecialchars($payment['client_name']) ?></div></div></div>
                            <div class="col-md-4"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 11px; color: var(--fr-muted); font-weight: 600;">Total</div><div style="font-weight: 700; font-size: 13px;">P<?= number_format((float)$payment['total'], 2) ?></div></div></div>
                        </div>

                        <?php if (empty($paymentHistory[$payment['id']])): ?>
                            <div class="text-center py-4" style="color: var(--fr-muted); font-size: 13px;">No payments recorded yet.</div>
                        <?php else: ?>
                            <?php foreach ($paymentHistory[$payment['id']] as $history): ?>
                                <div class="d-flex justify-content-between align-items-center py-3" style="border-bottom: 1px solid var(--fr-border);">
                                    <div>
                                        <strong style="display: block;"><?= htmlspecialchars($history['payment_type']) ?></strong>
                                        <small style="color: var(--fr-muted);"><?= htmlspecialchars(date('M d, Y', strtotime($history['payment_date']))) ?></small>
                                    </div>
                                    <strong style="color: var(--fr-red);">P<?= number_format((float)$history['amount'], 2) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Record Payment Modal -->
<div id="recordPaymentModal" class="ad-modal">
    <div class="ad-modal-content" style="max-width: 550px;">
        <button type="button" class="close" onclick="document.getElementById('recordPaymentModal').classList.remove('show')">&times;</button>
        <h2>Record Payment</h2>
        <form method="POST" action="admin.php?tab=payments">
            <?= csrf_field() ?>
            <input type="hidden" name="payment_db_id" id="payment_db_id">
            <div class="form-group"><label for="payment_type">Payment Type</label>
                <select id="payment_type" name="payment_type" required onchange="applyPaymentTypeMax()">
                    <option value="Down Payment">Down Payment</option>
                    <option value="Progress Payment">Progress Payment</option>
                    <option value="Final Payment">Final Payment</option>
                </select>
            </div>
            <div class="form-group">
                <label for="payment_amount">Amount (P)</label>
                <input
                    type="text"
                    inputmode="decimal"
                    id="payment_amount"
                    name="amount"
                    autocomplete="off"
                    oninput="formatAmountInput(this)"
                    required
                >
                <small id="paymentRemainingHint" style="display: block; margin-top: 5px; color: var(--fr-muted);"></small>
            </div>
            <div class="form-group"><label for="payment_date">Payment Date</label><input type="date" id="payment_date" name="payment_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('recordPaymentModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="record_payment" class="btn btn-primary">Record Payment</button>
            </div>
        </form>
    </div>
</div>


<script>

// ---------------------------------------------------------------
// Constants for the current modal session.
// ---------------------------------------------------------------
var paymentAmountMaxRemaining = 0;   // always: remaining balance
var paymentAmountMaxDownPayment = 0; // the required down payment
var paymentDownPaymentPaid = 0;      // how much DP has been paid already
var paymentCurrentMax = 0;           // effective max for the selected type


// ---------------------------------------------------------------
// Compute the effective maximum for the current payment type.
// ---------------------------------------------------------------
function computeTypeMax(type) {

    // Down Payment is capped at min(remaining, remaining DP required).
    if (type === 'Down Payment' && paymentAmountMaxDownPayment > 0) {
        const dpRemainingCents = Math.max(
            0,
            Math.round(paymentAmountMaxDownPayment * 100) -
            Math.round(paymentDownPaymentPaid * 100)
        );
        return Math.min(Math.round(paymentAmountMaxRemaining * 100), dpRemainingCents) / 100;
    }

    // Progress Payment / Final Payment are capped at remaining.
    return Math.round(paymentAmountMaxRemaining * 100) / 100;
}



// ---------------------------------------------------------------
// Clamp the input to paymentCurrentMax, preserving formatting.
// ---------------------------------------------------------------
function clampInputToMax(input) {

    const raw = input.value.replace(/,/g, '');
    const value = parseFloat(raw);

    if (isNaN(value) || value <= 0) return;      // nothing to clamp
    if (value <= paymentCurrentMax) return;      // within bounds

    // Rewrite with the max value, then reformat.
    input.value = paymentCurrentMax.toFixed(2);
    formatAmountInput(input);
}


// ---------------------------------------------------------------
// Recompute the effective max, auto-fill the amount, refresh
// the hint, and clamp any excess.
// ---------------------------------------------------------------
function applyPaymentTypeMax() {

    const typeSel = document.getElementById('payment_type');
    const input   = document.getElementById('payment_amount');
    const hint    = document.getElementById('paymentRemainingHint');

    if (!typeSel || !input || !hint) return;

    const type = typeSel.value;
    paymentCurrentMax = computeTypeMax(type);

    // Auto-fill the amount with the max allowed for this type.
    if (paymentCurrentMax > 0) {
        input.value = paymentCurrentMax.toFixed(2);
    } else {
        input.value = '';
    }

    // Reformat (adds thousands separators, trims extra decimals).
    formatAmountInput(input);

    // Build the hint line.
    let hintText = 'Maximum allowed for ' + type + ': P' +
        paymentCurrentMax.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

    if (type === 'Down Payment') {
        hintText += ' (down payment required: P' +
            paymentAmountMaxDownPayment.toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + ')';
    }

    hint.textContent = hintText;
}


// ---------------------------------------------------------------
// Open the Record Payment modal.
//
// Hides the "Down Payment" option when the required down payment
// has already been reached (or when no down payment is set).
// ---------------------------------------------------------------
function openPaymentModal(paymentId, remaining, downPayment, status, downPaymentPaid) {

    paymentAmountMaxRemaining   = Math.round((parseFloat(remaining) || 0) * 100) / 100;
    paymentAmountMaxDownPayment = Math.round((parseFloat(downPayment) || 0) * 100) / 100;
    paymentDownPaymentPaid      = Math.round((parseFloat(downPaymentPaid) || 0) * 100) / 100;

    document.getElementById('payment_db_id').value = paymentId;

    // Clear the amount; applyPaymentTypeMax() will refill it.
    document.getElementById('payment_amount').value = '';

    // Down Payment is not selectable when:
    //   - its requirement has been satisfied (by any payment type), or
    //   - the quotation has no down payment at all.
    const dpRequirementMet =
        paymentAmountMaxDownPayment <= 0 ||
        paymentDownPaymentPaid >= paymentAmountMaxDownPayment;

    const hideDownPayment =
        status === 'Down Payment Paid' ||
        status === 'Fully Paid' ||
        dpRequirementMet;

    // Rebuild the dropdown so state is clean for each row.
    const typeSel = document.getElementById('payment_type');

    let optionsHtml = '';
    if (!hideDownPayment) {
        optionsHtml += '<option value="Down Payment">Down Payment</option>';
    }
    optionsHtml += '<option value="Progress Payment">Progress Payment</option>';
    optionsHtml += '<option value="Final Payment">Final Payment</option>';

    typeSel.innerHTML = optionsHtml;
    typeSel.selectedIndex = 0;   // default to the first available type

    // Auto-fill the amount based on the selected type.
    applyPaymentTypeMax();

    document.getElementById('recordPaymentModal').classList.add('show');

    // Focus the amount field so the user can type immediately.
    setTimeout(function () {
        document.getElementById('payment_amount').focus();
    }, 50);
}


// ---------------------------------------------------------------
// Formatting (unchanged from before) + auto-clamp on input.
// ---------------------------------------------------------------
function formatAmountInput(input) {

    // 1. Strip anything that isn't a digit or a period.
    let raw = input.value.replace(/[^\d.]/g, '');

    // 2. Allow only one decimal point.
    const firstDot = raw.indexOf('.');
    if (firstDot !== -1) {
        raw =
            raw.slice(0, firstDot + 1) +
            raw.slice(firstDot + 1).replace(/\./g, '');
    }

    // 3. Split integer / decimal parts.
    const parts = raw.split('.');
    let intPart = parts[0];
    let decPart = parts.length > 1 ? parts[1].slice(0, 2) : null;

    // 4. Thousands separators.
    intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    // 5. Reassemble.
    input.value = decPart !== null ? intPart + '.' + decPart : intPart;

    // 6. Auto-clamp if the parsed value exceeds the current type max.
    const cleaned = input.value.replace(/,/g, '');
    const value = parseFloat(cleaned);

    if (!isNaN(value) && value > paymentCurrentMax && paymentCurrentMax > 0) {
        input.value = paymentCurrentMax.toFixed(2);
        const cappedParts = input.value.split('.');
        cappedParts[0] = cappedParts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        input.value = cappedParts.join('.');
    }
}


// ---------------------------------------------------------------
// Submit-time validation (kept as a safety net).
// ---------------------------------------------------------------
function validatePaymentAmount() {

    const input = document.getElementById('payment_amount');
    const cleaned = input.value.replace(/,/g, '');
    const amount = parseFloat(cleaned);

    if (isNaN(amount) || amount <= 0) {
        alert('Please enter a valid amount greater than zero.');
        input.focus();
        return false;
    }

    if (amount > paymentCurrentMax) {
        alert(
            'Amount cannot exceed the current maximum of P' +
            paymentCurrentMax.toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + '.'
        );
        input.focus();
        return false;
    }

    return true;
}


// ---------------------------------------------------------------
// Wire up on page load.
// ---------------------------------------------------------------
document.addEventListener('DOMContentLoaded', function () {

    const form = document.querySelector('#recordPaymentModal form');

    if (form) {
        form.addEventListener('submit', function (e) {
            if (!validatePaymentAmount()) {
                e.preventDefault();
            }
        });
    }
});

</script>