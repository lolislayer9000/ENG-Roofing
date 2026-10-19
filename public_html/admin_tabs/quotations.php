<?php
// This file is included inside admin.php.

// REVISION: Display the linked inspection's saved area; preserve quotation snapshots.
function adminQuotationRoofArea(PDO $pdo, array $quotation): ?float
{
    if (!empty($quotation['inspection_id'])) {
        $stmt = $pdo->prepare('SELECT roof_area FROM site_inspections WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$quotation['inspection_id']]);
        $area = $stmt->fetchColumn();
        if ($area !== false && $area !== null && (float)$area > 0) return (float)$area;
    }
    return isset($quotation['roof_area']) && (float)$quotation['roof_area'] > 0
        ? (float)$quotation['roof_area'] : null;
}

// ---------------------------------------------------------
// Load system settings
// ---------------------------------------------------------
$stmt = $pdo->query("SELECT * FROM system_settings ORDER BY id ASC LIMIT 1");
$system_settings = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$system_settings) {
    $system_settings = ['down_payment_rate' => 30.00];
}
$down_payment_rate = (float)$system_settings['down_payment_rate'];


// ---------------------------------------------------------
// Load material catalog (with type + product + category)
// ---------------------------------------------------------
$material_catalog = $pdo->query("
    SELECT id, item_product, specification, unit, estimated_price,
       material_type, product_category, roofing_product, general_category
    FROM materials
    ORDER BY material_type ASC, general_category ASC, roofing_product ASC, item_product ASC
")->fetchAll(PDO::FETCH_ASSOC);

$allMainMaterials = array_values(array_filter(
    $material_catalog, fn($m) => $m['material_type'] === 'main'
));
$allGeneralMaterials = array_values(array_filter(
    $material_catalog, fn($m) => $m['material_type'] === 'general'
));


// ---------------------------------------------------------
// Edit quotation
// ---------------------------------------------------------
if (isset($_POST['edit_quotation'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $id = (int)($_POST['edit_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM quotations WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quotation) {
        header('Location: admin.php?tab=quotations&msg=error');
        exit;
    }
    // REVISION: Preserve quotations already sent to the client.
    if (in_array($quotation['status'], ['Sent', 'Approved', 'Not Selected'], true)) {
        header('Location: admin.php?tab=quotations&msg=cannot_edit_approved');
        exit;
    }

    // -----------------------------------------------------
    // Materials
    // -----------------------------------------------------
    $material_ids    = $_POST['material_id'] ?? [];
    $material_types  = $_POST['material_type'] ?? [];
    $quantities      = $_POST['material_qty'] ?? [];
    $unit_prices     = $_POST['material_unit_price'] ?? [];

    if (!is_array($material_ids))   $material_ids = [];
    if (!is_array($material_types)) $material_types = [];
    if (!is_array($quantities))     $quantities = [];
    if (!is_array($unit_prices))    $unit_prices = [];

    // REVISION: Preserve saved material prices and quantities.
    // Standard price changes belong in Settings.
    // Ignore browser-submitted price overrides.
    $material_rows = getQuotationMaterials($pdo, $id);
    $material_subtotal = 0;
    
    foreach ($material_rows as &$row) {
        $price = (float)$row['unit_price'];
        $qty = (float)$row['quantity'];
    
        // REVISION: Do not finalize materials with missing/invalid pricing.
        if (
            !is_finite($price) || round($price, 2) <= 0 ||
            !is_finite($qty) || $qty < 0.01
        ) {
            header('Location: admin.php?tab=quotations&msg=invalid_unit_price');
            exit;
        }
    
        $row['amount'] = round($qty * $price, 2);
        $material_subtotal += $row['amount'];
    }
    unset($row);
    
    $material_subtotal = round($material_subtotal, 2);

    // -----------------------------------------------------
    // Additional charges
    // -----------------------------------------------------
    $hardware_attachments = 0;
    // REVISION: Installation and delivery charges must both be greater than zero.
    $instInput = $_POST['installation_charge'] ?? '';
    $delivInput = $_POST['delivery_charges'] ?? '';
    
    $instInput = is_scalar($instInput)
        ? trim(str_replace(',', '', (string)$instInput)) : '';
    $delivInput = is_scalar($delivInput)
        ? trim(str_replace(',', '', (string)$delivInput)) : '';
    
    if (
        !is_numeric($instInput) ||
        !is_numeric($delivInput) ||
        !is_finite((float)$instInput) ||
        !is_finite((float)$delivInput) ||
        round((float)$instInput, 2) <= 0 ||
        round((float)$delivInput, 2) <= 0
    ) {
        header('Location: admin.php?tab=quotations&msg=invalid_charges');
        exit;
    }
    
    $installation_charge = round((float)$instInput, 2);
    $delivery_charges = round((float)$delivInput, 2);
    $insulation_charge    = 0;
    $other_charges        = 0;

    // Discount is now a PERCENTAGE (0–100).
    // REVISION: Reject invalid discounts without changing the saved quotation.
        $discountInput = $_POST['discount_percent'] ?? null;
        
        if (
            !is_scalar($discountInput) ||
            !is_numeric($discountInput) ||
            !is_finite((float)$discountInput) ||
            (float)$discountInput < 0 ||
            (float)$discountInput > 100
        ) {
            header('Location: admin.php?tab=quotations&msg=invalid_discount');
            exit;
        }
        
        $discount_percent = (float)$discountInput;

    $charges_before_discount = $installation_charge + $delivery_charges;
    $pre_discount_total      = $material_subtotal + $charges_before_discount;
    $discount_amount         = round($pre_discount_total * ($discount_percent / 100), 2);

    $grand_total                 = round($pre_discount_total - $discount_amount, 2);
    $additional_charges_subtotal = round($grand_total - $material_subtotal, 2);

    // The `discount` DB column now stores the PERCENTAGE.
    $discount = $discount_percent;

    // -----------------------------------------------------
    // Down payment rate
    // -----------------------------------------------------
    if (
        in_array($quotation['status'], ['Approved'], true)
        || !empty($quotation['down_payment'])
    ) {
        $existing_grand_total  = (float)$quotation['grand_total'];
        $existing_down_payment = (float)$quotation['down_payment'];

        if ($existing_grand_total > 0) {
            $quotation_rate = ($existing_down_payment / $existing_grand_total) * 100;
        } else {
            $quotation_rate = $down_payment_rate;
        }
    } else {
        $quotation_rate = $down_payment_rate;
    }

    $down_payment = round($grand_total * ($quotation_rate / 100), 2);
    $balance = round($grand_total - $down_payment, 2);
    if ($balance < 0) $balance = 0;

    // Don't allow total below paid
    $stmt = $pdo->prepare("SELECT paid FROM payments WHERE quotation_id = ? LIMIT 1");
    $stmt->execute([$id]);
    $existing_payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing_payment) {
        $paid = (float)$existing_payment['paid'];
        if ($grand_total < $paid) {
            header('Location: admin.php?tab=quotations&msg=total_below_paid');
            exit;
        }
    }

    $new_status = $quotation['status'];

    // -----------------------------------------------------
    // Save transactionally
    // -----------------------------------------------------
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE quotations
            SET
                material_subtotal = :material_subtotal,
                hardware_attachments = :hardware_attachments,
                installation_charge = :installation_charge,
                delivery_charges = :delivery_charges,
                insulation_charge = :insulation_charge,
                other_charges = :other_charges,
                discount = :discount,
                additional_charges_subtotal = :additional_charges_subtotal,
                grand_total = :grand_total,
                down_payment = :down_payment,
                balance = :balance,
                status = :status
            WHERE id = :id
        ");
        $stmt->execute([
            ':material_subtotal'           => $material_subtotal,
            ':hardware_attachments'        => $hardware_attachments,
            ':installation_charge'         => $installation_charge,
            ':delivery_charges'            => $delivery_charges,
            ':insulation_charge'           => $insulation_charge,
            ':other_charges'               => $other_charges,
            ':discount'                    => $discount,
            ':additional_charges_subtotal' => $additional_charges_subtotal,
            ':grand_total'                 => $grand_total,
            ':down_payment'                => $down_payment,
            ':balance'                     => $balance,
            ':status'                      => $new_status,
            ':id'                          => $id,
        ]);

        $stmt = $pdo->prepare("DELETE FROM quotation_materials WHERE quotation_id = ?");
        $stmt->execute([$id]);

        if (!empty($material_rows)) {
            $stmt = $pdo->prepare("
                INSERT INTO quotation_materials (
                    quotation_id, material_id, material_type, item_product, specification, unit,
                    quantity, unit_price, amount
                )
                VALUES (
                    :quotation_id, :material_id, :material_type, :item_product, :specification, :unit,
                    :quantity, :unit_price, :amount
                )
            ");
            foreach ($material_rows as $row) {
                $stmt->execute([
                    ':quotation_id' => $id,
                    ':material_id'  => $row['material_id'],
                    ':material_type'=> $row['material_type'],
                    ':item_product' => $row['item_product'],
                    ':specification'=> $row['specification'],
                    ':unit'         => $row['unit'],
                    ':quantity'     => $row['quantity'],
                    ':unit_price'   => $row['unit_price'],
                    ':amount'       => $row['amount'],
                ]);
            }
        }

        // Sync payment
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE quotation_id = ? LIMIT 1");
        $stmt->execute([$id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($payment) {
            $paid = (float)$payment['paid'];
            $remaining = max(0, round($grand_total - $paid, 2));

            if ($paid >= $grand_total && $grand_total > 0) {
                $payment_status = 'Fully Paid';
            } elseif ($paid >= $down_payment && $down_payment > 0) {
                $payment_status = 'Down Payment Paid';
            } else {
                $payment_status = 'Unpaid';
            }

            $stmt = $pdo->prepare("
                UPDATE payments
                SET total = :total, down_payment = :down_payment, remaining = :remaining, status = :status
                WHERE id = :id
            ");
            $stmt->execute([
                ':total' => $grand_total,
                ':down_payment' => $down_payment,
                ':remaining' => $remaining,
                ':status' => $payment_status,
                ':id' => $payment['id'],
            ]);
        }

        $pdo->commit();
        header('Location: admin.php?tab=quotations&msg=updated');
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header('Location: admin.php?tab=quotations&msg=error');
        exit;
    }
}


// ---------------------------------------------------------
// Send quotation
// ---------------------------------------------------------
if (isset($_POST['send'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $id = (int)$_POST['send'];

    $stmt = $pdo->prepare("SELECT client_id, quotation_id FROM quotations WHERE id = ?");
    $stmt->execute([$id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quotation) {
        header('Location: admin.php?tab=quotations&msg=error');
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE quotations
        SET status = 'Sent'
        WHERE id = ?
          AND status IN ('Waiting for Action', 'Waiting for Admin Confirmation')
    ");
    $stmt->execute([$id]);

    try {
        $client_id = $quotation['client_id'];
        $message = "Your quotation {$quotation['quotation_id']} has been sent. Please review and approve.";
        $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, message) VALUES (?, NULL, ?)");
        $notif_stmt->execute([$client_id, $message]);
    } catch (Throwable $e) {
        error_log("Failed to send notification for quotation {$quotation['quotation_id']}: " . $e->getMessage());
    }

    header('Location: admin.php?tab=quotations&msg=sent');
    exit;
}

// ---------------------------------------------------------
// Send all in-flight quotations in a group
// ---------------------------------------------------------
if (isset($_POST['send_group'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $anchor_id = (int)$_POST['send_group'];

    $stmt = $pdo->prepare("SELECT inspection_id FROM quotations WHERE id = ? LIMIT 1");
    $stmt->execute([$anchor_id]);
    $inspection_id = (int)$stmt->fetchColumn();

    if ($inspection_id <= 0) {
        header('Location: admin.php?tab=quotations&msg=error');
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE quotations
        SET status = 'Sent'
        WHERE inspection_id = ?
          AND status IN ('Waiting for Action', 'Waiting for Admin Confirmation')
    ");
    $stmt->execute([$inspection_id]);

    try {
        $stmt = $pdo->prepare("SELECT client_id FROM quotations WHERE inspection_id = ? LIMIT 1");
        $stmt->execute([$inspection_id]);
        $client_id = (int)$stmt->fetchColumn();
        if ($client_id) {
            $msg = "Your quotation(s) for inspection #{$inspection_id} have been sent. Please review and approve one.";
            $notif = $pdo->prepare("INSERT INTO notifications (user_id, project_id, message) VALUES (?, NULL, ?)");
            $notif->execute([$client_id, $msg]);
        }
    } catch (Throwable $e) {
        error_log("Send-group notification failed: " . $e->getMessage());
    }

    header('Location: admin.php?tab=quotations&msg=sent');
    exit;
}

// ---------------------------------------------------------
// Resend rejected quotation as a NEW quotation (Superseded keeps history)
// ---------------------------------------------------------
if (isset($_POST['resend_group'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $anchor_id = (int)$_POST['resend_group'];

    $stmt = $pdo->prepare("SELECT inspection_id FROM quotations WHERE id = ? LIMIT 1");
    $stmt->execute([$anchor_id]);
    $inspection_id = (int)$stmt->fetchColumn();

    if ($inspection_id <= 0) {
        header('Location: admin.php?tab=quotations&msg=cannot_resend');
        exit;
    }

    // Find every Rejected quotation in this group.
    $stmt = $pdo->prepare("
        SELECT * FROM quotations
        WHERE inspection_id = ? AND status = 'Rejected'
        ORDER BY sequence_no ASC, id ASC
    ");
    $stmt->execute([$inspection_id]);
    $rejected = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rejected)) {
        header('Location: admin.php?tab=quotations&msg=cannot_resend');
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Current highest sequence for this inspection.
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(sequence_no), 0) FROM quotations WHERE inspection_id = ?");
        $stmt->execute([$inspection_id]);
        $nextSeq = (int)$stmt->fetchColumn();
        if ($nextSeq < 1) $nextSeq = 1;

        $number = getNumberFromID($rejected[0]['quotation_id']);
        $newIds = [];

        foreach ($rejected as $src) {
            $nextSeq++;
            $new_quotation_id = generateQuotationID($pdo, $number, $nextSeq);

            $stmt = $pdo->prepare("
                INSERT INTO quotations (
                    quotation_id, sequence_no, client_id, project_id, inspection_id,
                    service_type, roof_area,
                    material_subtotal, hardware_attachments, installation_charge,
                    delivery_charges, insulation_charge, other_charges, discount,
                    additional_charges_subtotal, grand_total,
                    down_payment, balance, status, is_selected
                ) VALUES (
                    :quotation_id, :sequence_no, :client_id, :project_id, :inspection_id,
                    :service_type, :roof_area,
                    :material_subtotal, :hardware_attachments, :installation_charge,
                    :delivery_charges, :insulation_charge, :other_charges, :discount,
                    :additional_charges_subtotal, :grand_total,
                    :down_payment, :balance, 'Sent', 0
                )
            ");
            $stmt->execute([
                ':quotation_id'                => $new_quotation_id,
                ':sequence_no'                 => $nextSeq,
                ':client_id'                   => $src['client_id'],
                ':project_id'                  => $src['project_id'],
                ':inspection_id'               => $inspection_id,
                ':service_type'                => $src['service_type'],
                ':roof_area'                   => $src['roof_area'],
                ':material_subtotal'           => $src['material_subtotal'],
                ':hardware_attachments'        => $src['hardware_attachments'],
                ':installation_charge'         => $src['installation_charge'],
                ':delivery_charges'            => $src['delivery_charges'],
                ':insulation_charge'           => $src['insulation_charge'],
                ':other_charges'               => $src['other_charges'],
                ':discount'                    => $src['discount'],
                ':additional_charges_subtotal' => $src['additional_charges_subtotal'],
                ':grand_total'                 => $src['grand_total'],
                ':down_payment'                => $src['down_payment'],
                ':balance'                     => $src['balance'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            $newIds[] = ['id' => $newId, 'quotation_id' => $new_quotation_id];

            // Copy the materials across verbatim.
            $stmt = $pdo->prepare("
                INSERT INTO quotation_materials
                    (quotation_id, material_id, material_type, item_product, specification, unit,
                     quantity, unit_price, amount)
                SELECT ?, material_id, material_type, item_product, specification, unit,
                       quantity, unit_price, amount
                FROM quotation_materials
                WHERE quotation_id = ?
            ");
            $stmt->execute([$newId, (int)$src['id']]);

            // Archive the original.
            $stmt = $pdo->prepare("UPDATE quotations SET status = 'Superseded' WHERE id = ?");
            $stmt->execute([(int)$src['id']]);
        }

        $pdo->commit();

        // Notify the client.
        try {
            $client_id = (int)$rejected[0]['client_id'];
            if ($client_id) {
                $list = implode(', ', array_column($newIds, 'quotation_id'));
                $msg = "A revised quotation has been sent: {$list}. Please review and approve.";
                $notif = $pdo->prepare("INSERT INTO notifications (user_id, project_id, message) VALUES (?, NULL, ?)");
                $notif->execute([$client_id, $msg]);
            }
        } catch (Throwable $e) {
            error_log("Resend notification failed: " . $e->getMessage());
        }

        header('Location: admin.php?tab=quotations&msg=resent');
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Resend quotation failed: " . $e->getMessage());
        header('Location: admin.php?tab=quotations&msg=error');
        exit;
    }
}

// ---------------------------------------------------------
// Delete all quotations in a group
// ---------------------------------------------------------
if (isset($_POST['delete_group'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $anchor_id = (int)$_POST['delete_group'];

    $stmt = $pdo->prepare("SELECT inspection_id FROM quotations WHERE id = ? LIMIT 1");
    $stmt->execute([$anchor_id]);
    $inspection_id = (int)$stmt->fetchColumn();

    if ($inspection_id <= 0) {
        // Standalone
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE quotation_id = ?");
        $stmt->execute([$anchor_id]);
        if ((int)$stmt->fetchColumn() > 0) {
            header('Location: admin.php?tab=quotations&msg=has_payment');
            exit;
        }
        $pdo->prepare("DELETE FROM quotations WHERE id = ?")->execute([$anchor_id]);
        header('Location: admin.php?tab=quotations&msg=deleted');
        exit;
    }

    // Any payments attached to any quotation in this group?
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM payments py
        JOIN quotations q ON q.id = py.quotation_id
        WHERE q.inspection_id = ?
    ");
    $stmt->execute([$inspection_id]);
    if ((int)$stmt->fetchColumn() > 0) {
        header('Location: admin.php?tab=quotations&msg=has_payment');
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM quotations WHERE inspection_id = ?");
    $stmt->execute([$inspection_id]);

    header('Location: admin.php?tab=quotations&msg=deleted');
    exit;
}


// ---------------------------------------------------------
// Delete quotation
// ---------------------------------------------------------
if (isset($_POST['delete'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $id = (int)$_POST['delete'];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE quotation_id = ?");
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() > 0) {
        header('Location: admin.php?tab=quotations&msg=has_payment');
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM quotations WHERE id = ?");
    $stmt->execute([$id]);

    header('Location: admin.php?tab=quotations&msg=deleted');
    exit;
}


// ---------------------------------------------------------
// Status filter + quotation list
// ---------------------------------------------------------
$status_filter = $_GET['status_filter'] ?? 'all';
$allowed_status = [
    'Waiting for Action',
    'Waiting for Admin Confirmation',
    'Sent',
    'Approved',
    'Rejected',
    'all',
];
if (!in_array($status_filter, $allowed_status, true)) {
    $status_filter = 'all';
}

$sql = "
    SELECT q.*, u.full_name AS client_name, p.project_name
    FROM quotations q
    JOIN users u ON q.client_id = u.id
    LEFT JOIN projects p ON q.project_id = p.id
    WHERE 1=1
";
$params = [];
if ($status_filter !== 'all') {
    $sql .= " AND q.status = :status";
    $params[':status'] = $status_filter;
}
$sql .= " ORDER BY q.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);



// ---------------------------------------------------------
// Counts
// ---------------------------------------------------------
$counts = [
    'Waiting for Action' => 0,
    'Waiting for Admin Confirmation' => 0,
    'Sent' => 0,
    'Approved' => 0,
    'Rejected' => 0,
];
$stmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM quotations GROUP BY status");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int)$row['cnt'];
    }
}


// ---------------------------------------------------------
// Messages
// ---------------------------------------------------------
$msg = '';
$msg_type = '';
if (isset($_GET['msg'])) {
    $messages = [
    // REVISION: Explain rejected material prices.
        'invalid_unit_price' => [
            'type' => 'error',
            'text' => 'Each material must have a valid unit price greater than ₱0.',
        ],
        'updated' => ['text' => 'Quotation updated successfully.', 'type' => ''],
        // REVISION: Display separate discount and charges validation errors.
    'invalid_discount' => [
        'text' => 'Quotation not saved. Discount must be between 0 and 100%.',
        'type' => 'error'
    ],
    'invalid_charges' => [
        'text' => 'Quotation not saved. Installation Fee and Delivery Charges must both be greater than zero.',
        'type' => 'error'
    ],
        'sent' => ['text' => 'Quotation sent to the client.', 'type' => ''],
        'deleted' => ['text' => 'Quotation deleted.', 'type' => ''],
        'has_payment' => ['text' => 'This quotation cannot be deleted because it already has a payment record.', 'type' => 'error'],
        'resent'         => ['text' => 'A revised quotation has been sent to the client.', 'type' => ''],
        'cannot_resend'  => ['text' => 'Only Rejected quotations can be resent.', 'type' => 'error'],
        'total_below_paid' => ['text' => 'Grand Total cannot be lower than the amount already paid.', 'type' => 'error'],
        'error' => ['text' => 'An error occurred.', 'type' => 'error'],
        'cannot_edit_approved' => ['text' => 'Sent, approved, or not selected quotations cannot be edited.', 'type' => 'error'],
    ];
    if (isset($messages[$_GET['msg']])) {
        $msg = $messages[$_GET['msg']]['text'];
        $msg_type = $messages[$_GET['msg']]['type'];
    }
}


// ---------------------------------------------------------
// Fetch all quotations, then group by inspection_id
// ---------------------------------------------------------
$status_filter = $_GET['status_filter'] ?? 'all';
$allowed_status = [
    'Waiting for Action',
    'Waiting for Admin Confirmation',
    'Sent',
    'Approved',
    'Rejected',
    'all',
];
if (!in_array($status_filter, $allowed_status, true)) {
    $status_filter = 'all';
}

$stmt = $pdo->query("
    SELECT q.*, u.full_name AS client_name, p.project_name
    FROM quotations q
    JOIN users u ON q.client_id = u.id
    LEFT JOIN projects p ON q.project_id = p.id
    ORDER BY q.created_at DESC, q.id ASC
");
$allQuotations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by inspection_id (standalone quotations become their own group)
$quotationGroups = [];
foreach ($allQuotations as $q) {
    $key = !empty($q['inspection_id']) ? 'i-' . (int)$q['inspection_id'] : 'q-' . (int)$q['id'];
    if (!isset($quotationGroups[$key])) {
        $quotationGroups[$key] = ['quotations' => []];
    }
    $quotationGroups[$key]['quotations'][] = $q;
}

foreach ($quotationGroups as &$g) {
    usort($g['quotations'], fn($a, $b) =>
        ((int)($a['sequence_no'] ?? 0) <=> (int)($b['sequence_no'] ?? 0))
        ?: ((int)$a['id'] <=> (int)$b['id'])
    );
    $g['anchor']          = $g['quotations'][0];
    $g['representative']  = pickRepresentativeQuotation($g['quotations']);
    $g['group_code']      = getQuotationGroupCode($g['anchor']);
    $g['dominant_status'] = dominantQuotationStatus($g['quotations']);
    $g['count']           = count($g['quotations']);
}
unset($g);

// Filter: keep groups where ANY quotation matches
if ($status_filter !== 'all') {
    $quotationGroups = array_values(array_filter($quotationGroups, function ($g) use ($status_filter) {
        foreach ($g['quotations'] as $q) {
            if ($q['status'] === $status_filter) return true;
        }
        return false;
    }));
}

// Sort groups by anchor's created_at DESC
usort($quotationGroups, fn($a, $b) =>
    strtotime($b['anchor']['created_at']) - strtotime($a['anchor']['created_at'])
);


// ---------------------------------------------------------
// Counts (by dominant status)
// ---------------------------------------------------------
$counts = [
    'Waiting for Action'             => 0,
    'Waiting for Admin Confirmation' => 0,
    'Sent'                           => 0,
    'Approved'                       => 0,
    'Rejected'                       => 0,
];
foreach ($quotationGroups as $g) {
    if (isset($counts[$g['dominant_status']])) {
        $counts[$g['dominant_status']]++;
    }
}


// ---------------------------------------------------------
// Selected group for VIEW
// ---------------------------------------------------------
$viewGroup = null;
if (isset($_GET['view'])) {
    $view_id = (int)$_GET['view'];
    foreach ($quotationGroups as $g) {
        if ((int)$g['anchor']['id'] === $view_id) { $viewGroup = $g; break; }
    }
    if (!$viewGroup) {
        // Fallback: rebuild from DB by anchor row (in case filter excluded it)
        $stmt = $pdo->prepare("SELECT * FROM quotations WHERE id = ? LIMIT 1");
        $stmt->execute([$view_id]);
        $anchor = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($anchor) {
            if (!empty($anchor['inspection_id'])) {
                $stmt = $pdo->prepare("
                    SELECT q.*, u.full_name AS client_name, p.project_name
                    FROM quotations q
                    JOIN users u ON q.client_id = u.id
                    LEFT JOIN projects p ON q.project_id = p.id
                    WHERE q.inspection_id = ?
                    ORDER BY q.sequence_no ASC, q.id ASC
                ");
                $stmt->execute([(int)$anchor['inspection_id']]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT q.*, u.full_name AS client_name, p.project_name
                    FROM quotations q
                    JOIN users u ON q.client_id = u.id
                    LEFT JOIN projects p ON q.project_id = p.id
                    WHERE q.id = ?
                    LIMIT 1
                ");
                $stmt->execute([$view_id]);
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $viewGroup = [
                    'quotations'      => $rows,
                    'anchor'          => $rows[0],
                    'representative'  => pickRepresentativeQuotation($rows),
                    'group_code'      => getQuotationGroupCode($rows[0]),
                    'dominant_status' => dominantQuotationStatus($rows),
                    'count'           => count($rows),
                ];
            }
        }
    }
    if ($viewGroup) {
        foreach ($viewGroup['quotations'] as &$vq) {
            $vq['materials'] = getQuotationMaterials($pdo, (int)$vq['id']);
        }
        unset($vq);
    }
}


// ---------------------------------------------------------
// Selected group for EDIT
// ---------------------------------------------------------
$editGroup = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    foreach ($quotationGroups as $g) {
        if ((int)$g['anchor']['id'] === $edit_id) { $editGroup = $g; break; }
    }
    if (!$editGroup) {
        $stmt = $pdo->prepare("SELECT * FROM quotations WHERE id = ? LIMIT 1");
        $stmt->execute([$edit_id]);
        $anchor = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($anchor) {
            if (!empty($anchor['inspection_id'])) {
                $stmt = $pdo->prepare("
                    SELECT q.*, u.full_name AS client_name, p.project_name
                    FROM quotations q
                    JOIN users u ON q.client_id = u.id
                    LEFT JOIN projects p ON q.project_id = p.id
                    WHERE q.inspection_id = ?
                    ORDER BY q.sequence_no ASC, q.id ASC
                ");
                $stmt->execute([(int)$anchor['inspection_id']]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT q.*, u.full_name AS client_name, p.project_name
                    FROM quotations q
                    JOIN users u ON q.client_id = u.id
                    LEFT JOIN projects p ON q.project_id = p.id
                    WHERE q.id = ?
                    LIMIT 1
                ");
                $stmt->execute([$edit_id]);
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $editGroup = [
                    'quotations'      => $rows,
                    'anchor'          => $rows[0],
                    'representative'  => pickRepresentativeQuotation($rows),
                    'group_code'      => getQuotationGroupCode($rows[0]),
                    'dominant_status' => dominantQuotationStatus($rows),
                    'count'           => count($rows),
                ];
            }
        }
    }
    if ($editGroup) {
        foreach ($editGroup['quotations'] as &$eq) {
            $eq['materials'] = getQuotationMaterials($pdo, (int)$eq['id']);
            $eq['inspection'] = !empty($eq['inspection_id'])
                ? getInspectionDetailsForQuotation($pdo, (int)$eq['inspection_id'])
                : null;
        }
        unset($eq);
    }
}

// Set JS-global inspection for the viewer modal
$firstInspection = null;
if (!empty($editGroup['quotations'][0]['inspection'])) {
    $firstInspection = $editGroup['quotations'][0]['inspection'];
}
$editRoofingProduct = $firstInspection['roofing_product'] ?? '';
?>

<?php if ($msg): ?><div class="msg <?= htmlspecialchars($msg_type) ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="stats">
    <div class="stat-card"><div class="number"><?= $counts['Waiting for Action'] ?></div><div class="label">Waiting</div></div>
    <div class="stat-card"><div class="number"><?= $counts['Waiting for Admin Confirmation'] ?></div><div class="label">For Admin Review</div></div>
    <div class="stat-card"><div class="number"><?= $counts['Sent'] ?></div><div class="label">Sent</div></div>
    <div class="stat-card"><div class="number"><?= $counts['Approved'] ?></div><div class="label">Approved</div></div>
    <div class="stat-card"><div class="number"><?= $counts['Rejected'] ?></div><div class="label">Rejected</div></div>
</div>

<div class="top-bar">
    <form method="GET" class="d-flex align-items-center gap-2">
        <input type="hidden" name="tab" value="quotations">
        <label for="status_filter">Status:</label>
        <select name="status_filter" id="status_filter" onchange="this.form.submit()">
            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All</option>
            <option value="Waiting for Action" <?= $status_filter === 'Waiting for Action' ? 'selected' : '' ?>>Waiting</option>
            <option value="Waiting for Admin Confirmation" <?= $status_filter === 'Waiting for Admin Confirmation' ? 'selected' : '' ?>>For Admin Review</option>
            <option value="Sent" <?= $status_filter === 'Sent' ? 'selected' : '' ?>>Sent</option>
            <option value="Approved" <?= $status_filter === 'Approved' ? 'selected' : '' ?>>Approved</option>
            <option value="Rejected" <?= $status_filter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
    </form>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Quotation ID</th><th>Client</th><th>Project</th><th>Service</th>
                <th>Roof Area</th><th>Grand Total</th><th>Down Payment</th><th>Balance</th><th>Status</th><th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($quotationGroups)): ?>
            <tr><td colspan="10" class="text-center py-5" style="color: var(--fr-muted);">No quotations found.</td></tr>
        <?php else: ?>
            <?php foreach ($quotationGroups as $g):
                $rep = $g['representative'];
                $statusClass = strtolower(str_replace(' ', '-', $g['dominant_status']));
            ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($g['group_code']) ?></strong>
                        <?php if ($g['count'] > 1): ?>
                            <span class="fr-badge followup" style="margin-left: 6px; font-size: 10.5px;">
                                <?= $g['count'] ?> quotes
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($rep['client_name']) ?></td>
                    <td>
                        <?php if (!empty($rep['project_name'])): ?>
                            <?= htmlspecialchars($rep['project_name']) ?>
                        <?php else: ?>
                            <span style="color: var(--fr-muted); font-style: italic;">Pending project</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(ucfirst($rep['service_type'])) ?></td>
                    <td><?= ($displayArea = adminQuotationRoofArea($pdo, $rep)) !== null ? number_format($displayArea, 2) . ' SQM' : '—' ?></td>
                    <td>₱<?= number_format((float)$rep['grand_total'], 2) ?></td>
                    <td>
                        ₱<?= number_format((float)$rep['down_payment'], 2) ?>
                        <?php if ((float)$rep['grand_total'] > 0): ?>
                            <span style="color: var(--fr-muted); font-size: 11px;">(<?= number_format(((float)$rep['down_payment'] / (float)$rep['grand_total']) * 100, 0) ?>%)</span>
                        <?php endif; ?>
                    </td>
                    <td>₱<?= number_format((float)$rep['balance'], 2) ?></td>
                    <td>
                        <span class="fr-badge <?= $statusClass ?>">
                            <?= htmlspecialchars($g['dominant_status']) ?>
                        </span>
                    </td>
                    <td class="actions">
                        <div class="dropdown">
                            <button type="button" class="action-btn dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Actions">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a class="dropdown-item" href="admin.php?tab=quotations&view=<?= (int)$g['anchor']['id'] ?>">
                                        <i class="bi bi-eye me-2"></i>View Details
                                    </a>
                                </li>
                                <li>
                                    <?php if ($g['dominant_status'] !== 'Approved'): ?>
                                        <a class="dropdown-item" href="admin.php?tab=quotations&edit=<?= (int)$g['anchor']['id'] ?>">
                                            <i class="bi bi-pencil me-2"></i>Edit
                                        </a>
                                    <?php else: ?>
                                        <span class="dropdown-item disabled">
                                            <i class="bi bi-lock me-2"></i>Edit (Locked)
                                        </span>
                                    <?php endif; ?>
                                </li>
                                <?php
                                $sendable = 0;
                                foreach ($g['quotations'] as $qq) {
                                    if (in_array($qq['status'], ['Waiting for Action', 'Waiting for Admin Confirmation'], true)) {
                                        $sendable++;
                                    }
                                }
                                if ($sendable > 0):
                                ?>
                                    <li>
                                        <form method="POST" action="admin.php?tab=quotations" class="m-0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="send_group" value="<?= (int)$g['anchor']['id'] ?>">
                                            <button type="submit" class="dropdown-item" onclick="return confirm('Send all <?= $sendable ?> quotation(s) in this group to the client?')">
                                                <i class="bi bi-send me-2"></i>Send <?= $sendable > 1 ? 'All ' . $sendable . ' ' : '' ?>to Client
                                            </button>
                                        </form>
                                    </li>
                                <?php endif; ?>
                                <?php
                                $hasRejected = false;
                                foreach ($g['quotations'] as $qq) {
                                    if ($qq['status'] === 'Rejected') { $hasRejected = true; break; }
                                }
                                if ($hasRejected):
                                ?>
                                    <li>
                                        <form method="POST" action="admin.php?tab=quotations" class="m-0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="resend_group" value="<?= (int)$g['anchor']['id'] ?>">
                                            <button type="submit" class="dropdown-item"
                                                    onclick="return confirm('Create a revised quotation and send it to the client? The rejected one will be archived as Superseded.');">
                                                <i class="bi bi-arrow-clockwise me-2"></i>Resend as New Quotation
                                            </button>
                                        </form>
                                    </li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="POST" action="admin.php?tab=quotations" class="m-0">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_group" value="<?= (int)$g['anchor']['id'] ?>">
                                        <button type="submit" class="dropdown-item text-danger" onclick="return confirm('Delete ALL quotations in this group?')">
                                            <i class="bi bi-trash me-2"></i>Delete<?= $g['count'] > 1 ? ' All (' . $g['count'] . ')' : ' Quotation' ?>
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- View Modal -->
<?php if ($viewGroup): ?>
<div id="viewQuotationModal" class="ad-modal show">
    <div class="ad-modal-content" style="max-width: 1000px;">
        <a href="admin.php?tab=quotations" class="close">&times;</a>
        <h2>Quotation Details – <?= htmlspecialchars($viewGroup['group_code']) ?></h2>

        <?php if ($viewGroup['count'] > 1): ?>
            <div class="quotation-tabs" style="margin-bottom: 18px;">
                <?php foreach ($viewGroup['quotations'] as $i => $q): ?>
                    <button type="button"
                            class="quotation-tab <?= $i === 0 ? 'is-active' : '' ?>"
                            data-qid="<?= (int)$q['id'] ?>">
                        <?= htmlspecialchars($q['quotation_id']) ?>
                        <small class="tab-status"><?= htmlspecialchars($q['status']) ?></small>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php foreach ($viewGroup['quotations'] as $i => $vq):
            $vMaterials = $vq['materials'] ?? [];
            $vMain = []; $vGen = [];
            foreach ($vMaterials as $m) {
                if (($m['material_type'] ?? 'main') === 'general') $vGen[] = $m;
                else $vMain[] = $m;
            }
        ?>
            <div class="quotation-panel" data-qid="<?= (int)$vq['id'] ?>"
                 style="display: <?= $i === 0 ? 'block' : 'none' ?>;">

                <div class="row g-3 mb-4">
                    <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 12px; color: var(--fr-muted); font-weight: 600; margin-bottom: 4px;">Client</div><div style="font-weight: 700;"><?= htmlspecialchars($vq['client_name']) ?></div></div></div>
                    <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 12px; color: var(--fr-muted); font-weight: 600; margin-bottom: 4px;">Project</div><div style="font-weight: 700;"><?= !empty($vq['project_name']) ? htmlspecialchars($vq['project_name']) : '<em style="color: var(--fr-muted);">Pending project</em>' ?></div></div></div>
                    <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 12px; color: var(--fr-muted); font-weight: 600; margin-bottom: 4px;">Service Type</div><div style="font-weight: 700;"><?= htmlspecialchars(ucfirst($vq['service_type'])) ?></div></div></div>
                    <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 12px; color: var(--fr-muted); font-weight: 600; margin-bottom: 4px;">Roof Area</div><div style="font-weight: 700;"><?= ($displayArea = adminQuotationRoofArea($pdo, $vq)) !== null ? number_format($displayArea, 2) . ' SQM' : '—' ?></div></div></div>
                    <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 12px; color: var(--fr-muted); font-weight: 600; margin-bottom: 4px;">Status</div><div style="font-weight: 700;"><?= htmlspecialchars($vq['status']) ?></div></div></div>
                    <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 12px; color: var(--fr-muted); font-weight: 600; margin-bottom: 4px;">Down Payment Rate</div><div style="font-weight: 700;"><?= number_format(((float)$vq['grand_total'] > 0) ? ((float)$vq['down_payment'] / (float)$vq['grand_total']) * 100 : 0, 2) ?>%</div></div></div>
                </div>

                <h3 class="fr-section-title">Main Component Materials</h3>
                <div class="table-wrap" style="box-shadow: none; margin-bottom: 20px;">
                    <table>
                        <thead><tr><th>Item / Product</th><th>Specification</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Amount</th></tr></thead>
                        <tbody>
                        <?php if (empty($vMain)): ?>
                            <tr><td colspan="6" class="text-center py-3" style="color: var(--fr-muted);">No main materials.</td></tr>
                        <?php else: foreach ($vMain as $m): ?>
                            <tr>
                                <td><?= htmlspecialchars($m['item_product']) ?></td>
                                <td><?= htmlspecialchars($m['specification'] ?: '—') ?></td>
                                <td><?= number_format((float)$m['quantity'], 2) ?></td>
                                <td><?= htmlspecialchars($m['unit']) ?></td>
                                <td>₱<?= number_format((float)$m['unit_price'], 2) ?></td>
                                <td><strong>₱<?= number_format((float)$m['amount'], 2) ?></strong></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <h3 class="fr-section-title">General Roof Construction Materials</h3>
                <div class="table-wrap" style="box-shadow: none; margin-bottom: 20px;">
                    <table>
                        <thead><tr><th>Item / Product</th><th>Specification</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Amount</th></tr></thead>
                        <tbody>
                        <?php if (empty($vGen)): ?>
                            <tr><td colspan="6" class="text-center py-3" style="color: var(--fr-muted);">No general materials.</td></tr>
                        <?php else: foreach ($vGen as $m): ?>
                            <tr>
                                <td><?= htmlspecialchars($m['item_product']) ?></td>
                                <td><?= htmlspecialchars($m['specification'] ?: '—') ?></td>
                                <td><?= number_format((float)$m['quantity'], 2) ?></td>
                                <td><?= htmlspecialchars($m['unit']) ?></td>
                                <td>₱<?= number_format((float)$m['unit_price'], 2) ?></td>
                                <td><strong>₱<?= number_format((float)$m['amount'], 2) ?></strong></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <h3 class="fr-section-title">Additional Charges</h3>
                <div class="row g-2 mb-3">
                    <div class="col-md-6"><div class="d-flex justify-content-between p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><span style="color: var(--fr-muted);">Delivery Charges</span><strong>₱<?= number_format((float)$vq['delivery_charges'], 2) ?></strong></div></div>
                    <div class="col-md-6"><div class="d-flex justify-content-between p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><span style="color: var(--fr-muted);">Installation Fee</span><strong>₱<?= number_format((float)$vq['installation_charge'], 2) ?></strong></div></div>
                    <div class="col-md-6"><div class="d-flex justify-content-between p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><span style="color: var(--fr-muted);">Discount</span><strong><?= number_format((float)$vq['discount'], 2) ?>%</strong></div></div>
                </div>

                <div style="border-top: 1px solid var(--fr-border); padding-top: 15px;">
                    <div class="d-flex justify-content-between py-2"><span style="color: var(--fr-muted);">Materials Subtotal</span><strong>₱<?= number_format((float)$vq['material_subtotal'], 2) ?></strong></div>
                    <div class="d-flex justify-content-between py-2"><span style="color: var(--fr-muted);">Additional Charges Subtotal</span><strong>₱<?= number_format((float)$vq['additional_charges_subtotal'], 2) ?></strong></div>
                    <div class="d-flex justify-content-between py-3" style="border-top: 2px solid var(--fr-border); border-bottom: 1px solid var(--fr-border); font-size: 17px;"><span class="fw-bold">Grand Total</span><strong style="color: var(--fr-red);">₱<?= number_format((float)$vq['grand_total'], 2) ?></strong></div>
                    <div class="d-flex justify-content-between py-2"><span style="color: var(--fr-muted);">Down Payment</span><strong>₱<?= number_format((float)$vq['down_payment'], 2) ?></strong></div>
                    <div class="d-flex justify-content-between py-2"><span style="color: var(--fr-muted);">Balance</span><strong>₱<?= number_format((float)$vq['balance'], 2) ?></strong></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Edit Modal -->
<?php if ($editGroup): ?>
<div id="editQuotationModal" class="ad-modal show">
    <div class="ad-modal-content" style="max-width: 1000px;">
        <a href="admin.php?tab=quotations" class="close">&times;</a>
        <h2>Edit Quotation<?= $editGroup['count'] > 1 ? ' Group' : '' ?> – <?= htmlspecialchars($editGroup['group_code']) ?></h2>

        <?php if ($firstInspection): ?>
        <div class="d-flex gap-2 flex-wrap mb-3">
            <button type="button" class="btn-action btn-action-info" onclick="openInspectionViewer()">
                <i class="bi bi-clipboard-check"></i><span>View Site Inspection</span>
            </button>
        </div>
        <?php endif; ?>

        <?php if ($editGroup['count'] > 1): ?>
            <div class="quotation-tabs" style="margin-bottom: 18px;">
                <?php foreach ($editGroup['quotations'] as $i => $q): ?>
                    <button type="button"
                            class="quotation-tab <?= $i === 0 ? 'is-active' : '' ?>"
                            data-qid="<?= (int)$q['id'] ?>">
                        <?= htmlspecialchars($q['quotation_id']) ?>
                        <small class="tab-status"><?= htmlspecialchars($q['status']) ?></small>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php foreach ($editGroup['quotations'] as $i => $eq):
            $eqMaterials = $eq['materials'] ?? [];
            $eqMain = []; $eqGen = [];
            foreach ($eqMaterials as $m) {
                if (($m['material_type'] ?? 'main') === 'general') $eqGen[] = $m;
                else $eqMain[] = $m;
            }
            $mainEditById = [];
            foreach ($eqMain as $m) $mainEditById[(int)$m['material_id']] = $m;
            $generalEditById = [];
            foreach ($eqGen as $m) $generalEditById[(int)$m['material_id']] = $m;
            $eqRoofingProduct = $eq['inspection']['roofing_product'] ?? '';
        ?>
        <form method="POST"
              action="admin.php?tab=quotations"
              class="quotation-panel quotation-panel-form"
              data-qid="<?= (int)$eq['id'] ?>"
              style="display: <?= $i === 0 ? 'block' : 'none' ?>;">
            <?= csrf_field() ?>
            <input type="hidden" name="edit_id" value="<?= (int)$eq['id'] ?>">
            <!-- REVISION: Keep client-facing quotations read-only, including direct edit URLs. -->
            <fieldset <?= in_array($eq['status'], ['Sent', 'Approved', 'Not Selected'], true) ? 'disabled' : '' ?> style="border:0;padding:0;margin:0;min-width:0;">

            <div class="row g-3 mb-4">
                <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 12px; color: var(--fr-muted); font-weight: 600; margin-bottom: 4px;">Client</div><div style="font-weight: 700;"><?= htmlspecialchars($eq['client_name']) ?></div></div></div>
                <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 12px; color: var(--fr-muted); font-weight: 600; margin-bottom: 4px;">Service Type</div><div style="font-weight: 700;"><?= htmlspecialchars(ucfirst($eq['service_type'])) ?></div></div></div>
                <div class="col-md-6"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 12px; color: var(--fr-muted); font-weight: 600; margin-bottom: 4px;">Roof Area</div><div style="font-weight: 700;"><?= ($displayArea = adminQuotationRoofArea($pdo, $eq)) !== null ? number_format($displayArea, 2) . ' SQM' : '—' ?></div></div></div>
            </div>

            <h3 class="fr-section-title">Main Component Materials</h3>
            <!-- REVISION: Review the saved selection only; catalog selection stays with Foreman. -->
            <p style="color: var(--fr-muted); font-size: 12.5px; margin-bottom: 8px;">
                Materials, quantities and saved unit prices prepared by the Foreman.
            </p>

            <div class="table-wrap" style="box-shadow: none; margin-bottom: 10px;">
                <table>
                    <thead><tr><th style="width: 35%;">Item / Product</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Amount</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($eqMain as $savedMaterial):
                        $mat = $savedMaterial;
                        $mat['id'] = (int)$savedMaterial['material_id'];
                        $sel      = $mainEditById[(int)$mat['id']] ?? null;
                        $checked  = $sel ? 'checked' : '';
                        $disabled = $sel ? '' : 'disabled';
                        $qty      = $sel ? $sel['quantity']   : '';
                        $price    = $sel ? $sel['unit_price'] : ($mat['estimated_price'] ?? 0);
                        $amount   = $sel ? $sel['amount']     : 0;
                        $hidden   = ''; // REVISION: All rows are saved selections, including legacy profiles.
                    ?>
                        <tr class="material-row main-material-row <?= $sel ? 'is-selected' : '' ?>"
                            data-material-type="main"
                            data-material-id="<?= (int)$mat['id'] ?>"
                            data-roofing-product="<?= htmlspecialchars($mat['roofing_product'] ?? '') ?>"
                            <?= $hidden ?>>
                            <input type="hidden" name="material_type[]" value="main" <?= $disabled ?>>
                            <input type="hidden" name="material_id[]" value="<?= (int)$mat['id'] ?>" <?= $disabled ?>>
                            <td>
                                <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;margin:0;">
                                    <input type="checkbox" class="material-checkbox" <?= $checked ?> disabled
                                           onchange="onMaterialCheckboxChange(this)">
                                    <span>
                                        <strong><?= htmlspecialchars($mat['item_product']) ?></strong>
                                        <?php if (!empty($mat['specification'])): ?>
                                            <span style="color: var(--fr-muted); font-size: 11.5px;">
                                                — <?= htmlspecialchars($mat['specification']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            </td>
                            <td><input type="number" name="material_qty[]" class="form-control form-control-sm" data-numeric="1"
                                       min="0.01" step="0.01" readonly value="<?= htmlspecialchars($qty) ?>"
                                       oninput="calculateQuotation()" <?= $disabled ?>></td>
                            <td class="material-unit" style="color: var(--fr-muted);"><?= htmlspecialchars($mat['unit']) ?></td>
                            <td><input type="text" inputmode="decimal" class="form-control form-control-sm money-input"
                                       name="material_unit_price[]" readonly value="<?= htmlspecialchars(number_format((float)$price, 2, '.', ',')) ?>"
                                       oninput="calculateQuotation()" <?= $disabled ?>></td>
                            <td class="material-line-amount" style="font-weight:600;">₱<?= number_format((float)$amount, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-end align-items-center gap-4 py-3">
                <span class="fw-bold">Main Component Subtotal</span>
                <strong class="main-material-subtotal">₱0.00</strong>
            </div>

            <h3 class="fr-section-title">General Roof Construction Materials</h3>
            <p style="color: var(--fr-muted); font-size: 12.5px; margin-bottom: 8px;">
                General materials and quantities saved by the Foreman.
            </p>

            <div class="table-wrap" style="box-shadow: none; margin-bottom: 10px;">
                <table>
                    <thead><tr><th style="width: 35%;">Item / Product</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Amount</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($eqGen as $savedMaterial):
                        $mat = $savedMaterial;
                        $mat['id'] = (int)$savedMaterial['material_id'];
                        $sel      = $generalEditById[(int)$mat['id']] ?? null;
                        $checked  = $sel ? 'checked' : '';
                        $disabled = $sel ? '' : 'disabled';
                        $qty      = $sel ? $sel['quantity']   : '';
                        $price    = $sel ? $sel['unit_price'] : ($mat['estimated_price'] ?? 0);
                        $amount   = $sel ? $sel['amount']     : 0;
                    ?>
                        <tr class="material-row general-material-row <?= $sel ? 'is-selected' : '' ?>"
                            data-material-type="general"
                            data-material-id="<?= (int)$mat['id'] ?>">
                            <input type="hidden" name="material_type[]" value="general" <?= $disabled ?>>
                            <input type="hidden" name="material_id[]" value="<?= (int)$mat['id'] ?>" <?= $disabled ?>>
                            <td>
                                <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;margin:0;">
                                    <input type="checkbox" class="material-checkbox" <?= $checked ?> disabled
                                           onchange="onMaterialCheckboxChange(this)">
                                    <span>
                                        <strong><?= htmlspecialchars($mat['item_product']) ?></strong>
                                        <?php if (!empty($mat['specification'])): ?>
                                            <span style="color: var(--fr-muted); font-size: 11.5px;">
                                                — <?= htmlspecialchars($mat['specification']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            </td>
                            <td><input type="number" name="material_qty[]" class="form-control form-control-sm" data-numeric="1"
                                       min="0.01" step="0.01" readonly value="<?= htmlspecialchars($qty) ?>"
                                       oninput="calculateQuotation()" <?= $disabled ?>></td>
                            <td class="material-unit" style="color: var(--fr-muted);"><?= htmlspecialchars($mat['unit']) ?></td>
                            <td><input type="text" inputmode="decimal" class="form-control form-control-sm money-input"
                                       name="material_unit_price[]" readonly value="<?= htmlspecialchars(number_format((float)$price, 2, '.', ',')) ?>"
                                       oninput="calculateQuotation()" <?= $disabled ?>></td>
                            <td class="material-line-amount" style="font-weight:600;">₱<?= number_format((float)$amount, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-end align-items-center gap-4 py-3">
                <span class="fw-bold">General Materials Subtotal</span>
                <strong class="general-material-subtotal">₱0.00</strong>
            </div>

            <h3 class="fr-section-title">Additional Charges</h3>
            <div class="row g-3">
                <div class="col-md-4"><div class="form-group"><label>Delivery Charges (₱)</label>
                    <input type="text" inputmode="decimal" class="form-control money-input" name="delivery_charges"
                           value="<?= ((float)$eq['delivery_charges'] > 0) ? htmlspecialchars(number_format((float)$eq['delivery_charges'], 2, '.', ',')) : '' ?>"
                           placeholder="0.00" oninput="calculateQuotation()" onchange="calculateQuotation()"></div></div>
                <div class="col-md-4"><div class="form-group"><label>Installation Fee (₱)</label>
                    <input type="text" inputmode="decimal" class="form-control money-input" name="installation_charge"
                           value="<?= ((float)$eq['installation_charge'] > 0) ? htmlspecialchars(number_format((float)$eq['installation_charge'], 2, '.', ',')) : '' ?>"
                           placeholder="0.00" oninput="calculateQuotation()" onchange="calculateQuotation()"></div></div>
                <div class="col-md-4"><div class="form-group"><label>Discount (%)</label>
                    <input type="number" name="discount_percent" min="0" max="100" step="0.01" data-numeric="1"
                           value="<?= htmlspecialchars($eq['discount']) ?>" oninput="calculateQuotation()"></div></div>
            </div>
            <div class="d-flex justify-content-end align-items-center gap-4 py-3">
                <span class="fw-bold">Total Additional Charges</span>
                <strong class="additional-subtotal">₱0.00</strong>
            </div>

            <h3 class="fr-section-title">Summary</h3>
            <div style="border-top: 1px solid var(--fr-border); padding-top: 15px;">
                <div class="d-flex justify-content-between py-2"><span style="color: var(--fr-muted);">Materials Subtotal</span><strong class="summary-materials">₱0.00</strong></div>
                <div class="d-flex justify-content-between py-2"><span style="color: var(--fr-muted);">Additional Charges Subtotal</span><strong class="summary-additional">₱0.00</strong></div>
                <div class="d-flex justify-content-between py-3" style="border-top: 2px solid var(--fr-border); border-bottom: 1px solid var(--fr-border); font-size: 17px;"><span class="fw-bold">Grand Total</span><strong class="summary-grand" style="color: var(--fr-red);">₱0.00</strong></div>
                <div class="d-flex justify-content-between py-2"><span style="color: var(--fr-muted);">Down Payment</span><strong class="summary-down">₱0.00</strong></div>
                <div class="d-flex justify-content-between py-2"><span style="color: var(--fr-muted);">Balance</span><strong class="summary-balance">₱0.00</strong></div>
            </div>

            <div class="form-actions">
            <!-- REVISION: Allow closing even when the quotation is locked. -->
            <a href="admin.php?tab=quotations"
               class="btn btn-outline-secondary"
               style="pointer-events:auto;">Close</a>
                <button type="submit" name="edit_quotation" class="btn btn-primary">Save This Quotation</button>
            </div>
            </fieldset>
        </form>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Inspection Viewer Modal (populated by JS) -->
<div class="modal fade" id="inspectionViewerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Source Site Inspection</h5>
                    <div class="inspection-viewer-sub text-muted small"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="inspectionViewerBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
window.__inspectionForEdit = <?= json_encode($firstInspection ?? null, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
window.quotationMaterials = <?= json_encode($material_catalog) ?>;
window.quotationRoofingProduct = <?= json_encode($editRoofingProduct) ?>;
window.quotationDownPaymentRate = <?= json_encode($down_payment_rate) ?>;

function money(value) {
    return '₱' + Number(value || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


// =========================================================
// Recalculate all amounts
// =========================================================
function rowAmount(row) {
    if (!row.classList.contains('is-selected')) return 0;
    const qty   = parseFloat(row.querySelector('input[name="material_qty[]"]')?.value || 0) || 0;
    const price = window.frParseMoney(row.querySelector('input[name="material_unit_price[]"]')?.value);
    const amount = qty * price;
    const cell = row.querySelector('.material-line-amount');
    if (cell) cell.textContent = money(amount);
    return amount;
}

function money(value) {
    return '₱' + Number(value || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function recalculatePanel(form) {
    if (!form) return;

    let mainTotal = 0, generalTotal = 0;

    form.querySelectorAll('tr.main-material-row').forEach(function (row) {
        if (!row.classList.contains('is-selected')) return;
        const qty   = parseFloat(row.querySelector('input[name="material_qty[]"]')?.value || 0) || 0;
        const price = window.frParseMoney(row.querySelector('input[name="material_unit_price[]"]')?.value);
        const amount = qty * price;
        const cell = row.querySelector('.material-line-amount');
        if (cell) cell.textContent = money(amount);
        mainTotal += amount;
    });

    form.querySelectorAll('tr.general-material-row').forEach(function (row) {
        if (!row.classList.contains('is-selected')) return;
        const qty   = parseFloat(row.querySelector('input[name="material_qty[]"]')?.value || 0) || 0;
        const price = window.frParseMoney(row.querySelector('input[name="material_unit_price[]"]')?.value);
        const amount = qty * price;
        const cell = row.querySelector('.material-line-amount');
        if (cell) cell.textContent = money(amount);
        generalTotal += amount;
    });

    const materialsTotal = mainTotal + generalTotal;

    const mainSubEl = form.querySelector('.main-material-subtotal');
    if (mainSubEl) mainSubEl.textContent = money(mainTotal);

    const genSubEl = form.querySelector('.general-material-subtotal');
    if (genSubEl) genSubEl.textContent = money(generalTotal);

    const delivery    = window.frParseMoney(form.querySelector('[name="delivery_charges"]')?.value);
    const install     = window.frParseMoney(form.querySelector('[name="installation_charge"]')?.value);
    // REVISION: Block invalid discounts and clear misleading totals.
    const discountEl = form.querySelector('[name="discount_percent"]');
    const discountText = discountEl ? discountEl.value.trim() : '';
    const discountPct = Number(discountText);
    
    const invalidDiscount =
        discountText === '' ||
        !Number.isFinite(discountPct) ||
        discountPct < 0 ||
        discountPct > 100;
    
    if (discountEl) {
        discountEl.setCustomValidity(
            invalidDiscount ? 'Enter a discount between 0 and 100%.' : ''
        );
    }
    
    if (invalidDiscount) {
        [
            '.additional-subtotal',
            '.summary-additional',
            '.summary-grand',
            '.summary-down',
            '.summary-balance'
        ].forEach(function (selector) {
            const el = form.querySelector(selector);
            if (el) el.textContent = 'Invalid discount';
        });
        return;
    }

    const chargesBeforeDiscount = delivery + install;
    const preDiscountTotal = materialsTotal + chargesBeforeDiscount;
    const discountAmount   = preDiscountTotal * (discountPct / 100);
    const additionalTotal  = chargesBeforeDiscount - discountAmount;
    const grandTotal       = preDiscountTotal - discountAmount;
    const downPayment      = grandTotal * (window.quotationDownPaymentRate / 100);
    const balance          = Math.max(0, grandTotal - downPayment);

    const set = function (sel, val) { const el = form.querySelector(sel); if (el) el.textContent = val; };
    set('.additional-subtotal',  money(additionalTotal));
    set('.summary-materials',    money(materialsTotal));
    set('.summary-additional',   money(additionalTotal));
    set('.summary-grand',        money(grandTotal));
    set('.summary-down',         money(downPayment));
    set('.summary-balance',      money(balance));
}

function calculateQuotation() {
    document.querySelectorAll('.quotation-panel-form').forEach(recalculatePanel);
}

function onMaterialCheckboxChange(checkbox) {
    const row = checkbox.closest('.material-row');
    if (!row) return;
    const enabled = checkbox.checked;
    row.classList.toggle('is-selected', enabled);
    row.querySelectorAll('input[type="hidden"], input[type="number"]').forEach(function (inp) {
        inp.disabled = !enabled;
    });
    if (enabled) {
        const qty = row.querySelector('input[name="material_qty[]"]');
        if (qty && (!qty.value || parseFloat(qty.value) <= 0)) qty.value = 1;
    }
    const form = row.closest('.quotation-panel-form');
    if (form) recalculatePanel(form);
}

function onMainShowAllToggle() {
    document.querySelectorAll('.quotation-panel-form').forEach(function (form) {
        const toggle = form.querySelector('.main-show-all-toggle');
        if (!toggle) return;
        const showAll = toggle.checked;
        form.querySelectorAll('tr.main-material-row').forEach(function (row) {
            const isChecked = row.classList.contains('is-selected');
            const matches   = (window.quotationRoofingProduct === '' || row.dataset.roofingProduct === window.quotationRoofingProduct);
            row.style.display = (showAll || isChecked || matches) ? '' : 'none';
        });
    });
}

// Quotation tab switching
document.addEventListener('click', function (e) {
    const tab = e.target.closest('.quotation-tab');
    if (!tab || !tab.dataset.qid) return;
    const modal = tab.closest('.ad-modal');
    if (!modal) return;
    const qid = tab.dataset.qid;

    modal.querySelectorAll('.quotation-tab').forEach(function (t) {
        t.classList.toggle('is-active', t === tab);
    });
    modal.querySelectorAll('.quotation-panel').forEach(function (p) {
        p.style.display = (p.dataset.qid === qid) ? 'block' : 'none';
    });

    const active = modal.querySelector('.quotation-panel[data-qid="' + qid + '"]');
    if (active && active.classList.contains('quotation-panel-form')) {
        recalculatePanel(active);
    }
});

// Init
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.quotation-panel-form').forEach(function (form) {
        // Sync disabled state on hidden inputs
        form.querySelectorAll('.material-row').forEach(function (row) {
            const cb = row.querySelector('.material-checkbox');
            if (!cb) return;
            row.querySelectorAll('input[type="hidden"], input[type="number"]').forEach(function (inp) {
                inp.disabled = !cb.checked;
            });
        });
        recalculatePanel(form);
    });
    onMainShowAllToggle();
});

// =========================================================
// Inspection viewer (used by the "View Site Inspection" button)
// =========================================================
window.openInspectionViewer = (function () {

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function buildHtml(ins) {
        if (!ins) return '<div class="text-muted">No inspection data available.</div>';

        const grid = (label, value) => `
            <div class="col-md-6 mb-3">
                <div class="p-3 rounded-3" style="background: var(--fr-bg); border:1px solid var(--fr-border);">
                    <div style="font-size:10px; font-weight:700; text-transform:uppercase; color:var(--fr-muted); margin-bottom:4px;">${esc(label)}</div>
                    <div style="font-weight:600;">${value || '—'}</div>
                </div>
            </div>`;

        // ---- Extract the foreman's inputs from the measurements table ----
        const measurements = ins.measurements || [];
        let rmLength = 0, rmWidth = 0, rmRise = 0;
        measurements.forEach(m => {
            const c = String(m.component || '').trim().toLowerCase();
            if (c === 'roof length')      rmLength = parseFloat(m.length) || 0;
            else if (c === 'roof width')  rmWidth  = parseFloat(m.length) || 0;
            else if (c === 'rise')        rmRise   = parseFloat(m.length) || 0;
        });

        const roofCat = ins.roofing_category || '';
        const isFlat  = roofCat === 'Flat Roof';
        const isHip   = roofCat === 'Hip Roof';
        const isGable = roofCat === 'Gable Roof';

        // ---- Compute derived values (mirrors foreman) ----
        let ridge = 0, eave = 0, hipRafter = 0, area = 0;
        if (roofCat === 'Gable Roof') {
            const sloped = Math.sqrt(Math.pow(rmWidth / 2, 2) + Math.pow(rmRise, 2));
            area   = 2 * rmLength * sloped;
            ridge  = rmLength;
            eave   = 2 * rmLength;
        } else if (roofCat === 'Hip Roof') {
            const sloped = Math.sqrt(Math.pow(rmWidth / 2, 2) + Math.pow(rmRise, 2));
            hipRafter   = Math.sqrt(Math.pow(rmRise, 2) + (Math.pow(rmWidth, 2) / 2));
            area        = Math.max(0, (2 * rmLength - rmWidth)) * sloped + rmWidth * hipRafter;
            ridge       = Math.max(0, rmLength - rmWidth);
            eave        = 2 * (rmLength + rmWidth);
        } else if (roofCat === 'Flat Roof') {
            area = rmLength * rmWidth;
            eave = 2 * (rmLength + rmWidth);
        } else if (roofCat === 'Shed Roof') {
            const sloped = Math.sqrt(Math.pow(rmWidth, 2) + Math.pow(rmRise, 2));
            area   = rmLength * sloped;
            eave   = rmLength;
        }

        const fmtM = (v) => (v > 0 ? Number(v).toFixed(2) + ' m' : null);

        let html = '<h4 class="fr-section-title">Overview</h4><div class="row">';
        html += grid('Inspection ID',   esc(ins.inspection_id));
        html += grid('Booking ID',      esc(ins.booking_id));
        html += grid('Inspection Date', esc(ins.inspection_date));
        html += grid('Inspector',       esc(ins.inspector_name || '—'));
        html += '</div>';

        html += '<h4 class="fr-section-title">Site Details</h4><div class="row">';
        html += grid('Condition',         esc(ins.existing_roof_condition));
        html += grid('Service Type',      esc(ins.roof_type));
        html += grid('Product Cat.',      esc(ins.product_category));
        html += grid('Roof Shape',        esc(ins.roofing_category));
        html += grid('Product / Profile', esc(ins.roofing_product));
        html += grid('Labor Req.',        (ins.labor_requirement || 0) + ' workers');
        html += grid('Site Access.',      esc(ins.site_accessibility));
        html += grid('Site Prep.',        esc(ins.site_preparation));
        html += '</div>';

        html += '<h4 class="fr-section-title">Required Measurements</h4>';
        if (rmLength <= 0 && rmWidth <= 0) {
            html += '<div class="text-muted small">No measurements recorded.</div>';
        } else {
            html += '<div class="row">';
            html += grid('Roof Length', fmtM(rmLength));
            html += grid('Roof Width',  fmtM(rmWidth));
            if (!isFlat) html += grid('Rise', fmtM(rmRise));
            if (isGable || isHip) html += grid('Ridge', fmtM(ridge));
            html += grid(isFlat ? 'Eave / Perimeter' : 'Eave', fmtM(eave));
            if (isHip) html += grid('Hip Rafter Length', fmtM(hipRafter));
            html += grid('Roof Area', area > 0 ? Number(area).toFixed(2) + ' m²' : null);
            html += '</div>';
        }

        if (ins.remarks) {
            html += '<h4 class="fr-section-title mt-3">Remarks</h4>';
            html += '<div class="p-3 rounded-3" style="background: var(--fr-bg); border:1px solid var(--fr-border); white-space:pre-line;">' + esc(ins.remarks) + '</div>';
        }

        return html;
    }

    return function () {
        const modalEl = document.getElementById('inspectionViewerModal');
        if (!modalEl) return;
        if (typeof bootstrap === 'undefined') {
            console.error('Bootstrap is not loaded yet; cannot open the inspection viewer.');
            return;
        }

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        const ins = window.__inspectionForEdit || null;

        document.getElementById('inspectionViewerBody').innerHTML = buildHtml(ins);

        const sub = modalEl.querySelector('.inspection-viewer-sub');
        if (sub) {
            sub.textContent = ins
                ? (ins.inspection_id + (ins.inspection_date ? ' · ' + ins.inspection_date : ''))
                : '';
        }

        modal.show();
    };
})();
// REVISION: Validate Admin quotation fields before submission.
(function () {
    const rules = {
        'material_unit_price[]': {
            min: 0.01,
            message: 'Enter a unit price greater than ₱0.'
        },
        'installation_charge': {
            min: 0.01,
            message: 'Enter an installation fee greater than ₱0.'
        },
        'delivery_charges': {
            min: 0.01,
            message: 'Enter delivery charges greater than ₱0.'
        },
        'discount_percent': {
            min: 0,
            max: 100,
            message: 'Enter a discount between 0 and 100%.'
        }
    };

    function validate(input) {
        const rule = rules[input.name];
        if (!rule || input.matches(':disabled')) return true;

        const raw = input.value.trim().replace(/,/g, '');
        const value = Number(raw);
        const valid = raw !== '' &&
            Number.isFinite(value) &&
            value >= rule.min &&
            (rule.max === undefined || value <= rule.max);

        input.setCustomValidity(valid ? '' : rule.message);
        return valid;
    }

    function validateForm(form) {
        let valid = true;
        form.querySelectorAll('input').forEach(function (input) {
            if (!validate(input)) valid = false;
        });
        if (!valid) form.reportValidity();
        return valid;
    }

    document.addEventListener('input', function (event) {
        if (event.target.closest('.quotation-panel-form')) {
            validate(event.target);
        }
    });

    document.addEventListener('click', function (event) {
        const button = event.target.closest('button[type="submit"]');
        const form = button ? button.form : null;
        if (!form || !form.matches('.quotation-panel-form')) return;

        if (!validateForm(form)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    document.addEventListener('submit', function (event) {
        if (!event.target.matches('.quotation-panel-form')) return;

        if (!validateForm(event.target)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
})();
</script>