<?php
// This file is included inside client.php.

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$client_id = (int)$_SESSION['user_id'];
$client_name = $_SESSION['full_name'] ?? 'Client';


// ---------------------------------------------------------
// Client approves quotation (also allow 'Changed')
// ---------------------------------------------------------
if (isset($_POST['approve_quotation'])) {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $quotation_id = (int)$_POST['quotation_id'];

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT *
            FROM quotations
            WHERE id = ?
              AND client_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$quotation_id, $client_id]);
        $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$quotation || $quotation['status'] !== 'Sent') {
            $pdo->rollBack();
            header('Location: client.php?tab=payments&msg=quotation_error');
            exit;
        }


        // Approve quotation
        $stmt = $pdo->prepare("UPDATE quotations SET status = 'Approved', is_selected = 1 WHERE id = ?");
        $stmt->execute([$quotation_id]);

        // Mark all siblings from the same inspection as Not Selected
        if (!empty($quotation['inspection_id'])) {
            $stmt = $pdo->prepare("
                UPDATE quotations
                SET status = 'Not Selected', is_selected = 0
                WHERE inspection_id = ?
                  AND id <> ?
                  AND status IN ('Sent', 'Waiting for Action', 'Waiting for Admin Confirmation')
            ");
            $stmt->execute([(int)$quotation['inspection_id'], $quotation_id]);
        }
        
        // NOW create the project — this is the moment the client accepted.
        //
        // createProjectFromQuotation() returns the row ID of the newly
        // created project. We must use that value for the payment insert
        // below, because $quotation was fetched BEFORE this call and its
        // project_id is still NULL.
        $project_row_id = !empty($quotation['project_id'])
            ? (int)$quotation['project_id']
            : null;

        try {
            $project_row_id = createProjectFromQuotation(
                $pdo,
                $quotation_id
            );
        } catch (Throwable $e) {
            error_log(
                "Failed to create project for quotation {$quotation_id}: "
                . $e->getMessage()
            );
        
            // Roll back the entire approval if project creation fails.
            throw $e;
        }

        // Fallback: re-read from the DB in case the function returned null.
        if (!$project_row_id) {
            $stmt = $pdo->prepare(
                "SELECT project_id FROM quotations WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$quotation_id]);
            $project_row_id = $stmt->fetchColumn() ?: null;
        }
        
        if (!$project_row_id) {
            throw new RuntimeException(
                "Project creation returned no project ID "
                . "for quotation {$quotation_id}."
            );
        }

        
        
        // Check if payment record exists
        $stmt = $pdo->prepare("SELECT id FROM payments WHERE quotation_id = ? LIMIT 1");
        $stmt->execute([$quotation_id]);
        $existingPayment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingPayment) {
            $number = getNumberFromID($quotation['quotation_id']);
            $payment_id = generatePaymentID($pdo, $number);

            $total = (float)$quotation['grand_total'];
            $down_payment = (float)$quotation['down_payment'];
            $remaining = $total;

            $stmt = $pdo->prepare("
                INSERT INTO payments (
                    payment_id, quotation_id, project_id, client_id,
                    total, down_payment, paid, remaining, status
                ) VALUES (
                    :payment_id, :quotation_id, :project_id, :client_id,
                    :total, :down_payment, 0.00, :remaining, 'Unpaid'
                )
            ");
            $stmt->execute([
                ':payment_id' => $payment_id,
                ':quotation_id' => $quotation_id,
                ':project_id' => $project_row_id,
                ':client_id' => $quotation['client_id'],
                ':total' => $total,
                ':down_payment' => $down_payment,
                ':remaining' => $remaining
            ]);
        }

        $pdo->commit();

        // Generate tasks after the approval transaction ends.
        if ($project_row_id) {
            try {
                require_once __DIR__ . '/../task_filter.php';
                $taskResult = generateProjectTasksForApprovedQuotation($pdo, (int)$project_row_id, (int)$quotation_id);
                if (!$taskResult['success']) {
                    error_log("Task filter: {$taskResult['message']} (project {$project_row_id})");
                }
            } catch (Throwable $e) {
                error_log("Task filter threw for project {$project_row_id}: " . $e->getMessage());
            }
        }        

        // --- Send notification to all active admins ---
        try {
            $admin_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
            $admin_stmt->execute();
            $admins = $admin_stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($admins)) {
                $message = "Client $client_name approved quotation {$quotation['quotation_id']}.";
                $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, message) VALUES (?, NULL, ?)");
                foreach ($admins as $admin_id) {
                    $notif_stmt->execute([$admin_id, $message]);
                }
            }
        } catch (Throwable $e) {
            error_log("Failed to send admin notifications for approved quotation {$quotation['quotation_id']}: " . $e->getMessage());
        }

        header('Location: client.php?tab=payments&msg=quotation_approved');
        exit;

    } catch (Throwable $e) {
        error_log(
            "Quotation approval failed for #{$quotation_id}: "
            . $e->getMessage()
        );
    
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    
        header('Location: client.php?tab=payments&msg=error');
        exit;
    }
}


// ---------------------------------------------------------
// Client rejects quotation (allow 'Sent' or 'Changed')
// ---------------------------------------------------------
if (isset($_POST['reject_quotation'])) {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $quotation_id = (int)$_POST['quotation_id'];

    // Fetch quotation details before updating
    $stmt = $pdo->prepare("SELECT quotation_id FROM quotations WHERE id = ? AND client_id = ?");
    $stmt->execute([$quotation_id, $client_id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quotation) {
        header('Location: client.php?tab=payments&msg=quotation_error');
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE quotations
        SET status = 'Rejected'
        WHERE id = ?
          AND client_id = ?
          AND status IN ('Sent', 'Changed')
    ");
    $stmt->execute([$quotation_id, $client_id]);

    // --- Send notification to all active admins ---
    try {
        $admin_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
        $admin_stmt->execute();
        $admins = $admin_stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($admins)) {
            $message = "Client $client_name rejected quotation {$quotation['quotation_id']}.";
            $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, message) VALUES (?, NULL, ?)");
            foreach ($admins as $admin_id) {
                $notif_stmt->execute([$admin_id, $message]);
            }
        }
    } catch (Throwable $e) {
        error_log("Failed to send admin notifications for rejected quotation {$quotation['quotation_id']}: " . $e->getMessage());
    }

    header('Location: client.php?tab=payments&msg=quotation_rejected');
    exit;
}


// ---------------------------------------------------------
// Fetch quotation needing client action (Sent or Changed)
// ---------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT q.*,
           p.project_name,
           si.inspection_id AS inspection_code,
           si.inspection_date,
           si.location AS inspection_location
    FROM quotations q
    LEFT JOIN projects p ON q.project_id = p.id
    LEFT JOIN site_inspections si ON si.id = q.inspection_id
    WHERE q.client_id = ?
      AND q.status = 'Sent'
      AND q.is_selected = 0
    ORDER BY q.inspection_id ASC, q.id ASC
");
$stmt->execute([$client_id]);
$allPending = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group them by inspection so a batch shows as one section
$pendingByInspection = [];
foreach ($allPending as $q) {
    $key = (int)($q['inspection_id'] ?? 0);
    $pendingByInspection[$key][] = $q;
}


// ---------------------------------------------------------
// Fetch all client payments
// ---------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT
        py.*,
        p.project_name,
        q.quotation_id AS quotation_code,
        q.status AS quotation_status
    FROM payments py
    LEFT JOIN projects p ON py.project_id = p.id
    JOIN quotations q ON py.quotation_id = q.id
    WHERE py.client_id = ?
    ORDER BY py.created_at DESC
");
$stmt->execute([$client_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ---------------------------------------------------------
// Fetch payment history
// ---------------------------------------------------------
$paymentHistory = [];
if (!empty($payments)) {
    $paymentIds = array_column($payments, 'id');
    $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
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
// Helper: fetch quotation details and materials for modals
// ---------------------------------------------------------
function getClientQuotationDetails($pdo, $quotation_id)
{
    $stmt = $pdo->prepare("
        SELECT q.*, u.full_name AS client_name, p.project_name
        FROM quotations q
        JOIN users u ON q.client_id = u.id
        LEFT JOIN projects p ON q.project_id = p.id
        WHERE q.id = ?
        LIMIT 1
    ");
    $stmt->execute([$quotation_id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quotation) return null;

    $stmt = $pdo->prepare("
        SELECT *
        FROM quotation_materials
        WHERE quotation_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$quotation_id]);
    $quotation['materials'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $quotation;
}


// ---------------------------------------------------------
// Messages
// ---------------------------------------------------------
$msg = '';
$msg_type = '';

if (isset($_GET['msg'])) {
    $messages = [
        'quotation_approved' => ['text' => 'Quotation approved. Your payment record has been created.', 'type' => ''],
        'quotation_rejected' => ['text' => 'Quotation rejected.', 'type' => 'warning'],
        'quotation_error' => ['text' => 'That quotation is no longer available for approval.', 'type' => 'error'],
        'error' => ['text' => 'An error occurred. Please try again.', 'type' => 'error']
    ];

    if (isset($messages[$_GET['msg']])) {
        $msg = $messages[$_GET['msg']]['text'];
        $msg_type = $messages[$_GET['msg']]['type'];
    }
}

?>
<?php if ($msg): ?>
    <div class="msg <?= $msg_type === 'error' ? 'error' : ($msg_type === 'warning' ? 'warning' : '') ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php foreach ($pendingByInspection as $insId => $quotes): ?>
    <?php
        $first = $quotes[0];
        $hasSiblings = count($quotes) > 1;
        $groupId = 'client-' . $insId;
    ?>
    <div class="fr-card mb-4" style="border-left: 4px solid var(--fr-red);">
        <div class="fr-card-pad">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <h2 class="fw-bold mb-1" style="font-size: 20px;">
                        <?= $hasSiblings ? 'Quotation Options' : 'Quotation Available' ?>
                    </h2>
                    <p class="mb-0" style="color: var(--fr-muted); font-size: 13px;">
                        Inspection <?= htmlspecialchars($first['inspection_code'] ?? 'N/A') ?>
                        <?php if (!empty($first['inspection_date'])): ?>
                            · <?= htmlspecialchars(date('M d, Y', strtotime($first['inspection_date']))) ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php if ($hasSiblings): ?>
                <p style="color: var(--fr-muted); font-size: 13px;">
                    Multiple quotations are available. Compare them and choose the one you prefer.
                    The others will remain visible in your records but can no longer be acted on.
                </p>
                <div class="quotation-tabs" data-group="<?= $groupId ?>">
                    <?php foreach ($quotes as $i => $q): ?>
                        <button type="button"
                                class="quotation-tab <?= $i === 0 ? 'is-active' : '' ?>"
                                data-group="<?= $groupId ?>"
                                data-qid="<?= (int)$q['id'] ?>">
                            <?= htmlspecialchars($q['quotation_id']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php foreach ($quotes as $i => $q):
                $details = getClientQuotationDetails($pdo, (int)$q['id']);
            ?>
                <div class="quotation-panel"
                     data-group="<?= $groupId ?>"
                     data-qid="<?= (int)$q['id'] ?>"
                     style="display: <?= ($hasSiblings && $i > 0) ? 'none' : 'block' ?>;">

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <strong style="font-size: 15px;">
                                <?= htmlspecialchars($q['quotation_id']) ?>
                            </strong>
                        </div>
                        <div style="font-size: 20px; font-weight: 700; color: var(--fr-red);">
                            ₱<?= number_format((float)$details['grand_total'], 2) ?>
                        </div>
                    </div>

                    <h3 class="fr-section-title mt-3">Materials</h3>
                    <div class="table-wrap" style="box-shadow: none; margin-bottom: 15px;">
                        <table>
                            <thead><tr><th>Item / Product</th><th>Specification</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Amount</th></tr></thead>
                            <tbody>
                            <?php if (empty($details['materials'])): ?>
                                <tr><td colspan="6" class="text-center py-3 muted">No materials listed.</td></tr>
                            <?php else: foreach ($details['materials'] as $m): ?>
                                <tr>
                                    <td><?= htmlspecialchars($m['item_product']) ?></td>
                                    <td><?= htmlspecialchars($m['specification'] ?: '—') ?></td>
                                    <td><?= number_format((float)$m['quantity'], 2) ?></td>
                                    <td><?= htmlspecialchars($m['unit']) ?></td>
                                    <td>₱<?= number_format((float)$m['unit_price'], 2) ?></td>
                                    <td class="fw-semibold">₱<?= number_format((float)$m['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <h3 class="fr-section-title">Additional Charges</h3>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6"><div class="d-flex justify-content-between p-2 rounded-2" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><span class="muted">Delivery Charges</span><strong>₱<?= number_format((float)$details['delivery_charges'], 2) ?></strong></div></div>
                        <div class="col-md-6"><div class="d-flex justify-content-between p-2 rounded-2" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><span class="muted">Installation Fee</span><strong>₱<?= number_format((float)$details['installation_charge'], 2) ?></strong></div></div>
                        <div class="col-md-6"><div class="d-flex justify-content-between p-2 rounded-2" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><span class="muted">Discount</span><strong><?= number_format((float)$details['discount'], 2) ?>%</strong></div></div>
                    </div>

                    <div style="border-top: 1px solid var(--fr-border); padding-top: 15px;">
                        <div class="d-flex justify-content-between py-2"><span class="muted">Additional Charges Subtotal</span><strong>₱<?= number_format((float)$details['additional_charges_subtotal'], 2) ?></strong></div>
                        <div class="d-flex justify-content-between py-3" style="border-top: 2px solid var(--fr-border); border-bottom: 1px solid var(--fr-border); font-size: 17px;"><span class="fw-bold">Grand Total</span><strong style="color: var(--fr-red);">₱<?= number_format((float)$details['grand_total'], 2) ?></strong></div>
                        <div class="d-flex justify-content-between py-2"><span class="muted">Down Payment</span><strong>₱<?= number_format((float)$details['down_payment'], 2) ?></strong></div>
                        <div class="d-flex justify-content-between py-2"><span class="muted">Balance</span><strong>₱<?= number_format((float)$details['balance'], 2) ?></strong></div>
                    </div>

                    <div class="form-actions mt-3">
                        <form method="POST" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="quotation_id" value="<?= (int)$q['id'] ?>">
                            <button type="submit" name="reject_quotation" class="btn btn-outline-secondary"
                                    onclick="return confirm('Reject this quotation?');">
                                Reject
                            </button>
                        </form>
                        <form method="POST" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="quotation_id" value="<?= (int)$q['id'] ?>">
                            <button type="submit" name="approve_quotation" class="btn btn-primary"
                                    onclick="return confirm('Approve this quotation? The others in this set will be marked Not Selected.');">
                                Approve This Quotation
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h2 class="fw-bold mb-1" style="font-size: 20px;">My Payments</h2>
        <p class="mb-0" style="color: var(--fr-muted); font-size: 13.5px;">View your quotation payment status and payment history.</p>
    </div>
</div>

<?php if (empty($payments)): ?>
    <div class="fr-card">
        <div class="fr-card-pad text-center py-5">
            <i class="bi bi-cash-coin" style="font-size: 36px; color: var(--fr-muted); opacity: .5;"></i>
            <h3 class="mt-3 mb-1" style="font-size: 17px; color: var(--fr-text);">No Payment Records Yet</h3>
            <p class="mb-0" style="color: var(--fr-muted); font-size: 13px;">A payment record will appear once you approve a quotation.</p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($payments as $payment):
        $quoteDetails = getClientQuotationDetails($pdo, $payment['quotation_id']);
        $status_class = $payment['status'] === 'Fully Paid' ? 'completed' : ($payment['status'] === 'Down Payment Paid' ? 'scheduled' : 'rejected');
    ?>
        <div class="fr-card mb-3">
            <div class="fr-card-pad">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div>
                        <h3 class="fw-bold mb-1" style="font-size: 16px;">
                            <?= !empty($payment['project_name']) ? htmlspecialchars($payment['project_name']) : '<span class="muted" style="font-style: italic;">Pending project</span>' ?>
                        </h3>
                        <p class="mb-0" style="color: var(--fr-muted); font-size: 12px;"><?= htmlspecialchars($payment['payment_id']) ?> · <?= htmlspecialchars($payment['quotation_code']) ?></p>
                    </div>
                    <span class="fr-badge <?= $status_class ?>"><?= htmlspecialchars($payment['status']) ?></span>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-3 col-6"><div class="p-3 rounded-3 text-center" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div class="muted" style="font-size: 11px; font-weight: 600;">Grand Total</div><strong style="font-size: 15px;">₱<?= number_format((float)$payment['total'], 2) ?></strong></div></div>
                    <div class="col-md-3 col-6"><div class="p-3 rounded-3 text-center" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div class="muted" style="font-size: 11px; font-weight: 600;">Down Payment (<?= ((float)$payment['total'] > 0 ? number_format(((float)$payment['down_payment'] / (float)$payment['total']) * 100, 0) : 0) ?>%)</div><strong style="font-size: 15px;">₱<?= number_format((float)$payment['down_payment'], 2) ?></strong></div></div>
                    <div class="col-md-3 col-6"><div class="p-3 rounded-3 text-center" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div class="muted" style="font-size: 11px; font-weight: 600;">Total Paid</div><strong style="font-size: 15px; color: #047857;">₱<?= number_format((float)$payment['paid'], 2) ?></strong></div></div>
                    <div class="col-md-3 col-6"><div class="p-3 rounded-3 text-center" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div class="muted" style="font-size: 11px; font-weight: 600;">Remaining</div><strong style="font-size: 15px; color: var(--fr-red);">₱<?= number_format((float)$payment['remaining'], 2) ?></strong></div></div>
                </div>

                <button type="button" class="btn btn-outline-secondary btn-sm mb-3" onclick="openQuotationModal(<?= (int)$payment['quotation_id'] ?>)">
                    <i class="bi bi-eye me-1"></i>View Quotation Details
                </button>

                <h4 class="fr-section-title" style="margin-top: 20px;">Payment History</h4>
                <?php if (empty($paymentHistory[$payment['id']])): ?>
                    <div class="text-center py-3 muted" style="font-size: 13px;">No payments recorded yet.</div>
                <?php else: ?>
                    <?php foreach ($paymentHistory[$payment['id']] as $history): ?>
                        <div class="d-flex justify-content-between align-items-center py-2" style="border-bottom: 1px solid var(--fr-border-soft);">
                            <div>
                                <strong style="display: block; font-size: 13.5px;"><?= htmlspecialchars($history['payment_type']) ?></strong>
                                <small class="muted"><?= htmlspecialchars(date('M d, Y', strtotime($history['payment_date']))) ?></small>
                            </div>
                            <strong style="color: var(--fr-red);">₱<?= number_format((float)$history['amount'], 2) ?></strong>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quotation Details Modal -->
        <div id="quotationModal<?= (int)$payment['quotation_id'] ?>" class="ad-modal">
            <div class="ad-modal-content" style="max-width: 900px;">
                <button type="button" class="close" onclick="closeQuotationModal(<?= (int)$payment['quotation_id'] ?>)">&times;</button>
                <h2>Quotation Details – <?= htmlspecialchars($payment['quotation_code']) ?></h2>
                <?php if ($quoteDetails): ?>
                    <?php if ($quoteDetails['status'] === 'Changed'): ?>
                        <div class="msg warning">This quotation has been changed. Please re-approve.</div>
                    <?php endif; ?>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6"><div class="p-2 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div class="muted" style="font-size: 11px; font-weight: 600;">Client</div><div class="fw-semibold"><?= htmlspecialchars($quoteDetails['client_name']) ?></div></div></div>
                        <div class="col-md-6"><div class="p-2 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div class="muted" style="font-size: 11px; font-weight: 600;">Project</div><div class="fw-semibold"><?= !empty($quoteDetails['project_name']) ? htmlspecialchars($quoteDetails['project_name']) : '<em>Pending project</em>' ?></div></div></div>
                        <div class="col-md-6"><div class="p-2 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div class="muted" style="font-size: 11px; font-weight: 600;">Service Type</div><div class="fw-semibold"><?= htmlspecialchars(ucfirst($quoteDetails['service_type'])) ?></div></div></div>
                        <div class="col-md-6"><div class="p-2 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div class="muted" style="font-size: 11px; font-weight: 600;">Roof Area</div><div class="fw-semibold"><?= number_format((float)$quoteDetails['roof_area'], 2) ?> SQM</div></div></div>
                    </div>

                    <h3 class="fr-section-title">Materials</h3>
                    <div class="table-wrap" style="box-shadow: none; margin-bottom: 15px;">
                        <table>
                            <thead><tr><th>Item / Product</th><th>Specification</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Amount</th></tr></thead>
                            <tbody>
                            <?php if (empty($quoteDetails['materials'])): ?>
                                <tr><td colspan="6" class="text-center py-3 muted">No materials added.</td></tr>
                            <?php else: foreach ($quoteDetails['materials'] as $m): ?>
                                <tr>
                                    <td><?= htmlspecialchars($m['item_product']) ?></td>
                                    <td><?= htmlspecialchars($m['specification'] ?: '—') ?></td>
                                    <td><?= number_format((float)$m['quantity'], 2) ?></td>
                                    <td><?= htmlspecialchars($m['unit']) ?></td>
                                    <td>₱<?= number_format((float)$m['unit_price'], 2) ?></td>
                                    <td class="fw-semibold">₱<?= number_format((float)$m['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <h3 class="fr-section-title">Additional Charges</h3>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6"><div class="d-flex justify-content-between p-2 rounded-2" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><span class="muted">Delivery</span><strong>₱<?= number_format((float)$quoteDetails['delivery_charges'], 2) ?></strong></div></div>
                        <div class="col-md-6"><div class="d-flex justify-content-between p-2 rounded-2" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><span class="muted">Installation Fee</span><strong>₱<?= number_format((float)$quoteDetails['installation_charge'], 2) ?></strong></div></div>
                        <div class="col-md-6"><div class="d-flex justify-content-between p-2 rounded-2" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><span class="muted">Discount</span><strong><?= number_format((float)$quoteDetails['discount'], 2) ?>%</strong></div></div>
                    </div>

                    <div style="border-top: 1px solid var(--fr-border); padding-top: 15px;">
                        <div class="d-flex justify-content-between py-2"><span class="muted">Materials Subtotal</span><strong>₱<?= number_format((float)$quoteDetails['material_subtotal'], 2) ?></strong></div>
                        <div class="d-flex justify-content-between py-2"><span class="muted">Additional Charges Subtotal</span><strong>₱<?= number_format((float)$quoteDetails['additional_charges_subtotal'], 2) ?></strong></div>
                        <div class="d-flex justify-content-between py-3" style="border-top: 2px solid var(--fr-border); border-bottom: 1px solid var(--fr-border); font-size: 17px;"><span class="fw-bold">Grand Total</span><strong style="color: var(--fr-red);">₱<?= number_format((float)$quoteDetails['grand_total'], 2) ?></strong></div>
                        <div class="d-flex justify-content-between py-2"><span class="muted">Down Payment</span><strong>₱<?= number_format((float)$quoteDetails['down_payment'], 2) ?></strong></div>
                        <div class="d-flex justify-content-between py-2"><span class="muted">Balance</span><strong>₱<?= number_format((float)$quoteDetails['balance'], 2) ?></strong></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- JavaScript to open/close modals -->
<script>
function openQuotationModal(id) {
    var modal = document.getElementById('quotationModal' + id);
    if (modal) modal.classList.add('show');
}
function closeQuotationModal(id) {
    var modal = document.getElementById('quotationModal' + id);
    if (modal) modal.classList.remove('show');
}

// =========================================================================
// Client quotation tabs — one panel per quotation, tab-switched
// =========================================================================
document.addEventListener('click', function (e) {
    const tab = e.target.closest('.quotation-tab');
    if (!tab || !tab.dataset.qid) return;

    const group = tab.dataset.group;
    const qid   = tab.dataset.qid;
    const bar   = tab.closest('.quotation-tabs');
    if (!bar) return;

    bar.querySelectorAll('.quotation-tab').forEach(t => t.classList.remove('is-active'));
    tab.classList.add('is-active');

    document.querySelectorAll('.quotation-panel[data-group="' + group + '"]').forEach(p => {
        p.style.display = (p.dataset.qid === qid) ? 'block' : 'none';
    });
});
</script>