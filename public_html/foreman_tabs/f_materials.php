<?php
// f_materials.php – Foreman Material Delivery Tracking
// Included inside foreman.php

$foreman_id = (int)($_SESSION['user_id'] ?? 0);

if ($foreman_id <= 0) {
    die('Invalid foreman account.');
}

// -------------------------------------------------------------------------
// Handle POST actions
// -------------------------------------------------------------------------

// Update delivered quantity (inline edit)
if (isset($_POST['update_delivery'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $project_id  = (int)($_POST['project_id'] ?? 0);
    $material_id = (int)($_POST['quotation_material_id'] ?? 0);
    $delivered   = (float)($_POST['delivered_quantity'] ?? 0);

    if ($project_id <= 0 || $material_id <= 0) {
        header('Location: foreman.php?tab=materials&msg=error');
        exit;
    }

    // Ownership check
    $stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND foreman_id = ? LIMIT 1");
    $stmt->execute([$project_id, $foreman_id]);
    if (!$stmt->fetch()) {
        header('Location: foreman.php?tab=materials&msg=not_allowed');
        exit;
    }

    // Total quote quantity (hard ceiling for delivered)
    $stmt = $pdo->prepare("SELECT quantity FROM quotation_materials WHERE id = ?");
    $stmt->execute([$material_id]);
    $total = (float)$stmt->fetchColumn();

    if ($delivered < 0) $delivered = 0;
    if ($delivered > $total) $delivered = $total;

    // Get existing state
    $stmt = $pdo->prepare("
        SELECT delivered_quantity, used_quantity
        FROM project_material_usage
        WHERE project_id = ? AND quotation_material_id = ?
    ");
    $stmt->execute([$project_id, $material_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $old_delivered = (float)($row['delivered_quantity'] ?? 0);
    $old_used      = (float)($row['used_quantity'] ?? 0);

    // Can't have used > delivered
    $new_used = min($old_used, $delivered);

    $action = ($old_delivered == 0) ? 'add' : 'edit';

    $stmt = $pdo->prepare("
        INSERT INTO project_material_usage
            (project_id, quotation_material_id, delivered_quantity, used_quantity)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            delivered_quantity = VALUES(delivered_quantity),
            used_quantity      = VALUES(used_quantity)
    ");
    $stmt->execute([$project_id, $material_id, $delivered, $new_used]);

    $stmt = $pdo->prepare("
        INSERT INTO material_usage_log
            (project_id, quotation_material_id, old_delivered, new_delivered, action, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$project_id, $material_id, $old_delivered, $delivered, $action, $foreman_id]);

    header('Location: foreman.php?tab=materials&project_id=' . $project_id . '&msg=updated');
    exit;
}

// Add to delivered via modal
if (isset($_POST['add_quantity'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $project_id  = (int)($_POST['project_id'] ?? 0);
    $material_id = (int)($_POST['quotation_material_id'] ?? 0);
    $add_amount  = (float)($_POST['add_amount'] ?? 0);

    if ($project_id <= 0 || $material_id <= 0 || $add_amount <= 0) {
        header('Location: foreman.php?tab=materials&msg=error');
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND foreman_id = ? LIMIT 1");
    $stmt->execute([$project_id, $foreman_id]);
    if (!$stmt->fetch()) {
        header('Location: foreman.php?tab=materials&msg=not_allowed');
        exit;
    }

    $stmt = $pdo->prepare("SELECT quantity FROM quotation_materials WHERE id = ?");
    $stmt->execute([$material_id]);
    $total = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT delivered_quantity, used_quantity
        FROM project_material_usage
        WHERE project_id = ? AND quotation_material_id = ?
    ");
    $stmt->execute([$project_id, $material_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $old_delivered = (float)($row['delivered_quantity'] ?? 0);
    $old_used      = (float)($row['used_quantity'] ?? 0);

    $new_delivered = min($total, $old_delivered + $add_amount);
    if ($new_delivered < 0) $new_delivered = 0;

    $stmt = $pdo->prepare("
        INSERT INTO project_material_usage
            (project_id, quotation_material_id, delivered_quantity, used_quantity)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            delivered_quantity = VALUES(delivered_quantity),
            used_quantity      = VALUES(used_quantity)
    ");
    $stmt->execute([$project_id, $material_id, $new_delivered, $old_used]);

    $action = ($old_delivered == 0) ? 'add' : 'edit';
    $stmt = $pdo->prepare("
        INSERT INTO material_usage_log
            (project_id, quotation_material_id, old_delivered, new_delivered, action, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$project_id, $material_id, $old_delivered, $new_delivered, $action, $foreman_id]);

    header('Location: foreman.php?tab=materials&project_id=' . $project_id . '&msg=updated');
    exit;
}

// Mark delivered as full
if (isset($_POST['complete_material'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $project_id  = (int)($_POST['project_id'] ?? 0);
    $material_id = (int)($_POST['quotation_material_id'] ?? 0);

    if ($project_id <= 0 || $material_id <= 0) {
        header('Location: foreman.php?tab=materials&msg=error');
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND foreman_id = ? LIMIT 1");
    $stmt->execute([$project_id, $foreman_id]);
    if (!$stmt->fetch()) {
        header('Location: foreman.php?tab=materials&msg=not_allowed');
        exit;
    }

    $stmt = $pdo->prepare("SELECT quantity FROM quotation_materials WHERE id = ?");
    $stmt->execute([$material_id]);
    $total = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT delivered_quantity, used_quantity
        FROM project_material_usage
        WHERE project_id = ? AND quotation_material_id = ?
    ");
    $stmt->execute([$project_id, $material_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $old_delivered = (float)($row['delivered_quantity'] ?? 0);
    $old_used      = (float)($row['used_quantity'] ?? 0);

    $stmt = $pdo->prepare("
        INSERT INTO project_material_usage
            (project_id, quotation_material_id, delivered_quantity, used_quantity)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            delivered_quantity = VALUES(delivered_quantity),
            used_quantity      = VALUES(used_quantity)
    ");
    $stmt->execute([$project_id, $material_id, $total, $old_used]);

    $stmt = $pdo->prepare("
        INSERT INTO material_usage_log
            (project_id, quotation_material_id, old_delivered, new_delivered, action, created_by)
        VALUES (?, ?, ?, ?, 'complete', ?)
    ");
    $stmt->execute([$project_id, $material_id, $old_delivered, $total, $foreman_id]);

    header('Location: foreman.php?tab=materials&project_id=' . $project_id . '&msg=completed');
    exit;
}

// Record used quantity via modal
if (isset($_POST['add_used_quantity'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $project_id  = (int)($_POST['project_id'] ?? 0);
    $material_id = (int)($_POST['quotation_material_id'] ?? 0);
    $use_amount  = (float)($_POST['use_amount'] ?? 0);

    if ($project_id <= 0 || $material_id <= 0 || $use_amount <= 0) {
        header('Location: foreman.php?tab=materials&msg=error');
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND foreman_id = ? LIMIT 1");
    $stmt->execute([$project_id, $foreman_id]);
    if (!$stmt->fetch()) {
        header('Location: foreman.php?tab=materials&msg=not_allowed');
        exit;
    }

    // Need current delivered + used
    $stmt = $pdo->prepare("
        SELECT delivered_quantity, used_quantity
        FROM project_material_usage
        WHERE project_id = ? AND quotation_material_id = ?
    ");
    $stmt->execute([$project_id, $material_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $old_delivered = (float)($row['delivered_quantity'] ?? 0);
    $old_used      = (float)($row['used_quantity'] ?? 0);

    // Can't use more than has been delivered
    $new_used = min($old_delivered, $old_used + $use_amount);
    if ($new_used < 0) $new_used = 0;

    $stmt = $pdo->prepare("
        INSERT INTO project_material_usage
            (project_id, quotation_material_id, delivered_quantity, used_quantity)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            delivered_quantity = VALUES(delivered_quantity),
            used_quantity      = VALUES(used_quantity)
    ");
    $stmt->execute([$project_id, $material_id, $old_delivered, $new_used]);

    // Log it — action='used' distinguishes it from delivered changes
    $stmt = $pdo->prepare("
        INSERT INTO material_usage_log
            (project_id, quotation_material_id, old_delivered, new_delivered, action, created_by)
        VALUES (?, ?, ?, ?, 'used', ?)
    ");
    $stmt->execute([$project_id, $material_id, $old_used, $new_used, $foreman_id]);

    header('Location: foreman.php?tab=materials&project_id=' . $project_id . '&msg=used');
    exit;
}

// Mark all as fully delivered
if (isset($_POST['complete_all'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $project_id = (int)($_POST['project_id'] ?? 0);

    if ($project_id <= 0) {
        header('Location: foreman.php?tab=materials&msg=error');
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND foreman_id = ? LIMIT 1");
    $stmt->execute([$project_id, $foreman_id]);
    if (!$stmt->fetch()) {
        header('Location: foreman.php?tab=materials&msg=not_allowed');
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT qm.id, qm.quantity
        FROM quotations q
        JOIN quotation_materials qm ON qm.quotation_id = q.id
        WHERE q.project_id = ?
    ");
    $stmt->execute([$project_id]);
    $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($materials as $mat) {
        $material_id = (int)$mat['id'];
        $total = (float)$mat['quantity'];

        $stmt2 = $pdo->prepare("
            SELECT delivered_quantity, used_quantity
            FROM project_material_usage
            WHERE project_id = ? AND quotation_material_id = ?
        ");
        $stmt2->execute([$project_id, $material_id]);
        $row = $stmt2->fetch(PDO::FETCH_ASSOC);

        $old_delivered = (float)($row['delivered_quantity'] ?? 0);
        $old_used      = (float)($row['used_quantity'] ?? 0);

        $stmt2 = $pdo->prepare("
            INSERT INTO project_material_usage
                (project_id, quotation_material_id, delivered_quantity, used_quantity)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                delivered_quantity = VALUES(delivered_quantity),
                used_quantity      = VALUES(used_quantity)
        ");
        $stmt2->execute([$project_id, $material_id, $total, $old_used]);

        $stmt2 = $pdo->prepare("
            INSERT INTO material_usage_log
                (project_id, quotation_material_id, old_delivered, new_delivered, action, created_by)
            VALUES (?, ?, ?, ?, 'complete_all', ?)
        ");
        $stmt2->execute([$project_id, $material_id, $old_delivered, $total, $foreman_id]);
    }

    header('Location: foreman.php?tab=materials&project_id=' . $project_id . '&msg=all_completed');
    exit;
}

// -------------------------------------------------------------------------
// Fetch projects
// -------------------------------------------------------------------------
$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

$stmt = $pdo->prepare("
    SELECT
        p.id, p.project_id, p.project_name, p.service_type,
        p.status, p.start_date, p.expected_end_date, p.progress
    FROM projects p
    WHERE p.foreman_id = ?
    ORDER BY
        CASE p.status
            WHEN 'active' THEN 1
            WHEN 'pending' THEN 2
            WHEN 'completed' THEN 3
            ELSE 4
        END,
        p.start_date ASC,
        p.created_at DESC
");
$stmt->execute([$foreman_id]);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$selectedProject = null;
$materials = [];
$deliveryStatus = [];   // quotation_material_id => ['delivered' => x, 'used' => y]
$history = [];

if ($project_id > 0) {
    foreach ($projects as $p) {
        if ((int)$p['id'] === $project_id) {
            $selectedProject = $p;
            break;
        }
    }

    if ($selectedProject) {
        $stmt = $pdo->prepare("
            SELECT
                qm.id AS quotation_material_id,
                qm.item_product,
                qm.specification,
                qm.unit,
                qm.quantity AS total_quantity
            FROM quotations q
            JOIN quotation_materials qm ON qm.quotation_id = q.id
            WHERE q.project_id = ?
            ORDER BY qm.id ASC
        ");
        $stmt->execute([$project_id]);
        $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($materials)) {
            $ids = array_column($materials, 'quotation_material_id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $stmt = $pdo->prepare("
                SELECT quotation_material_id, delivered_quantity, used_quantity
                FROM project_material_usage
                WHERE project_id = ? AND quotation_material_id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$project_id], $ids));

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $deliveryStatus[$row['quotation_material_id']] = [
                    'delivered' => (float)$row['delivered_quantity'],
                    'used'      => (float)$row['used_quantity'],
                ];
            }
        }

        $stmt = $pdo->prepare("
            SELECT
                mul.old_delivered,
                mul.new_delivered,
                mul.action,
                mul.created_at,
                u.full_name AS created_by_name,
                qm.item_product
            FROM material_usage_log mul
            JOIN users u ON u.id = mul.created_by
            JOIN quotation_materials qm ON qm.id = mul.quotation_material_id
            WHERE mul.project_id = ?
            ORDER BY mul.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$project_id]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// -------------------------------------------------------------------------
// Messages
// -------------------------------------------------------------------------
$msg = '';
$msg_type = '';

if (isset($_GET['msg'])) {
    $messages = [
        'updated'       => ['text' => 'Delivery quantity updated successfully.', 'type' => ''],
        'completed'     => ['text' => 'Material marked as fully delivered.', 'type' => ''],
        'all_completed' => ['text' => 'All materials marked as fully delivered.', 'type' => ''],
        'used'          => ['text' => 'Usage recorded successfully.', 'type' => ''],
        'error'         => ['text' => 'An error occurred.', 'type' => 'error'],
        'not_allowed'   => ['text' => 'You are not allowed to modify this project.', 'type' => 'error'],
    ];
    if (isset($messages[$_GET['msg']])) {
        $msg = $messages[$_GET['msg']]['text'];
        $msg_type = $messages[$_GET['msg']]['type'];
    }
}
?>

<?php if ($msg): ?>
    <div class="alert <?= $msg_type === 'error' ? 'alert-danger' : 'alert-success' ?> d-flex align-items-center">
        <i class="bi <?= $msg_type === 'error' ? 'bi-exclamation-triangle' : 'bi-check-circle' ?> me-2"></i>
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<?php if (!$selectedProject): ?>

    <!-- PROJECT LIST -->
    <div class="mb-4">
        <h2 class="fw-bold mb-1" style="font-size: 20px;">Material Delivery Tracking</h2>
        <p class="mb-0" style="color: var(--fr-muted); font-size: 13.5px;">Select a project to track material deliveries.</p>
    </div>

    <div class="fr-table-wrap">
        <table class="fr-table">
            <thead>
                <tr><th>Project ID</th><th>Project Name</th><th>Service</th><th>Status</th><th>Progress</th><th class="text-end">Action</th></tr>
            </thead>
            <tbody>
                <?php if (empty($projects)): ?>
                    <tr><td colspan="6" class="text-center py-5" style="color: var(--fr-muted);">No projects assigned to you.</td></tr>
                <?php else: ?>
                    <?php foreach ($projects as $p):
                        $st = $p['status'] === 'completed' ? 'completed' : ($p['status'] === 'pending' ? 'pending' : 'active');
                    ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($p['project_id']) ?></td>
                            <td><?= htmlspecialchars($p['project_name']) ?></td>
                            <td><?= htmlspecialchars(ucfirst($p['service_type'])) ?></td>
                            <td><span class="fr-badge <?= $st ?>"><?= htmlspecialchars(ucfirst($p['status'])) ?></span></td>
                            <td class="fw-num"><?= (int)$p['progress'] ?>%</td>
                            <td class="text-end">
                                <a href="foreman.php?tab=materials&project_id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-primary">
                                    View Materials
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php else: ?>

    <!-- DETAIL VIEW -->
    <a href="foreman.php?tab=materials" class="btn btn-outline-secondary btn-sm mb-3">
        <i class="bi bi-arrow-left me-1"></i>Back to Projects
    </a>

    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
        <div>
            <h2 class="fw-bold mb-1" style="font-size: 20px;"><?= htmlspecialchars($selectedProject['project_name']) ?></h2>
            <p class="mb-0" style="color: var(--fr-muted); font-size: 13px;">
                <?= htmlspecialchars($selectedProject['project_id']) ?>
                · <?= htmlspecialchars(ucfirst($selectedProject['service_type'])) ?>
                · <span class="fr-badge <?= $selectedProject['status'] === 'completed' ? 'completed' : 'active' ?>"><?= htmlspecialchars(ucfirst($selectedProject['status'])) ?></span>
            </p>
        </div>
        <?php if (!empty($materials)): ?>
            <form method="POST" class="d-inline"
                  onsubmit="return confirm('Mark all materials as fully delivered?')">
                <?= csrf_field() ?>
                <input type="hidden" name="project_id" value="<?= (int)$selectedProject['id'] ?>">
                <button type="submit" name="complete_all" class="btn btn-primary">
                    <i class="bi bi-check2-all me-1"></i>Mark All Full
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (empty($materials)): ?>
        <div class="text-center py-5 fr-card">
            <i class="bi bi-box" style="font-size: 32px; opacity: .4; color: var(--fr-muted);"></i>
            <p class="mt-2 fw-semibold" style="color: var(--fr-text-soft);">No Materials Found</p>
            <p style="color: var(--fr-muted); font-size: 13px;">This project has no materials in its quotation.</p>
        </div>
    <?php else: ?>
        <div class="fr-table-wrap mb-4">
            <table class="fr-table">
                <thead>
                    <tr>
                        <th>Material</th>
                        <th>Specification</th>
                        <th>Unit</th>
                        <th>Delivered</th>
                        <th>Used</th>
                        <th>Inventory</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($materials as $mat):
                        $materialId = (int)$mat['quotation_material_id'];
                        $total = (float)$mat['total_quantity'];
                        $status = $deliveryStatus[$materialId] ?? ['delivered' => 0.0, 'used' => 0.0];
                        $delivered = (float)$status['delivered'];
                        $used = (float)$status['used'];
                        $inventory = max(0, $delivered - $used);
                        $is_complete = ($total > 0 && $delivered >= $total);
                        $remainingStock = max(0, $total - $delivered);
                        $inventoryBase = $total > 0 ? $total : max(1, $delivered);
                        $inventoryPct = $inventoryBase > 0 ? min(100, round(($inventory / $inventoryBase) * 100)) : 0;

                        if ($delivered <= 0) { $invColor = 'var(--fr-muted)'; $barColor = '#d1d5db'; }
                        elseif ($inventoryPct >= 50) { $invColor = '#047857'; $barColor = '#10b981'; }
                        elseif ($inventoryPct >= 20) { $invColor = '#b45309'; $barColor = '#f59e0b'; }
                        else { $invColor = '#b91c1c'; $barColor = '#ef4444'; }
                    ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($mat['item_product']) ?></td>
                            <td style="color: var(--fr-muted);"><?= htmlspecialchars($mat['specification'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($mat['unit']) ?></td>

                            <td>
                                <span class="delivered-display fw-semibold" style="color: #047857; cursor: pointer;"
                                      data-project-id="<?= (int)$selectedProject['id'] ?>"
                                      data-material-id="<?= $materialId ?>"
                                      data-delivered="<?= number_format($delivered, 2, '.', '') ?>"
                                      data-total="<?= number_format($total, 2, '.', '') ?>"
                                      onclick="makeEditable(this)" title="Click to edit">
                                    <?= number_format($delivered, 2) ?> / <?= number_format($total, 2) ?>
                                    <?php if ($is_complete): ?><i class="bi bi-check-circle-fill ms-1" style="color: #10b981;"></i><?php endif; ?>
                                </span>
                                <span class="delivered-edit" style="display:none;">
                                    <form method="POST" class="d-inline-flex gap-1 align-items-center">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="project_id" value="<?= (int)$selectedProject['id'] ?>">
                                        <input type="hidden" name="quotation_material_id" value="<?= $materialId ?>">
                                        <input type="number" name="delivered_quantity" class="form-control form-control-sm" style="width: 80px;"
                                               value="<?= number_format($delivered, 2, '.', '') ?>" step="1" min="1" max="<?= $total ?>">
                                        <button type="submit" name="update_delivery" class="btn btn-sm btn-primary" title="Save"><i class="bi bi-check2"></i></button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cancelEdit(this)" title="Cancel"><i class="bi bi-x"></i></button>
                                    </form>
                                </span>
                            </td>

                            <td class="fw-semibold" style="color: #b45309;"><?= number_format($used, 2) ?></td>

                            <td>
                                <div style="min-width: 120px;">
                                    <div class="fw-bold" style="color: <?= $invColor ?>; font-size: 14px;">
                                        <?= number_format($inventory, 2) ?>
                                        <small style="font-weight: 400; font-size: 11px; color: var(--fr-muted);"><?= htmlspecialchars($mat['unit']) ?></small>
                                    </div>
                                    <div style="height: 6px; background: #eef0f3; border-radius: 99px; overflow: hidden; margin: 4px 0;">
                                        <div style="width: <?= $inventoryPct ?>%; height: 100%; background: <?= $barColor ?>;"></div>
                                    </div>
                                    <div style="font-size: 10.5px; color: var(--fr-muted); text-transform: uppercase; letter-spacing: .3px;">
                                        <?= $delivered <= 0 ? 'Awaiting delivery' : $inventoryPct . '% remaining' ?>
                                    </div>
                                </div>
                            </td>

                            <td class="text-end">
                                <div class="d-flex gap-1 justify-content-end flex-wrap">
                                    <?php if (!$is_complete): ?>
                                        <button type="button" class="btn btn-sm btn-primary"
                                                onclick="openAddModal(<?= (int)$selectedProject['id'] ?>, <?= $materialId ?>, <?= number_format($remainingStock, 2, '.', '') ?>)">
                                            <i class="bi bi-plus-lg"></i> Stock
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                onclick="if(confirm('Mark as fully delivered?')) { document.getElementById('complete_form_<?= $materialId ?>').submit(); }">
                                            <i class="bi bi-check2-all"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($delivered > 0 && $inventory > 0): ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                onclick="openUseModal(<?= (int)$selectedProject['id'] ?>, <?= $materialId ?>, <?= number_format($inventory, 2, '.', '') ?>)">
                                            <i class="bi bi-dash-lg"></i> Use
                                        </button>
                                    <?php elseif ($is_complete && $inventory <= 0 && $used > 0): ?>
                                        <span class="fr-badge completed">Fully Used</span>
                                    <?php endif; ?>
                                    <form id="complete_form_<?= $materialId ?>" method="POST" style="display:none;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="project_id" value="<?= (int)$selectedProject['id'] ?>">
                                        <input type="hidden" name="quotation_material_id" value="<?= $materialId ?>">
                                        <input type="hidden" name="complete_material" value="1">
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- History -->
    <div class="fr-card">
        <div class="fr-card-pad">
            <h3 class="fr-section-title">Activity History</h3>
            <?php if (empty($history)): ?>
                <div class="text-center py-4" style="color: var(--fr-muted); font-size: 13px;">No activity recorded yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="fr-table">
                        <thead><tr><th>Date</th><th>Material</th><th>Type</th><th>Before</th><th>After</th></tr></thead>
                        <tbody>
                            <?php foreach ($history as $log):
                                $action = $log['action'] ?? 'edit';
                                if ($action === 'used') { $chip = 'scheduled'; $label = 'Used'; }
                                elseif ($action === 'complete' || $action === 'complete_all') { $chip = 'followup'; $label = ($action === 'complete_all' ? 'Full (All)' : 'Full'); }
                                else { $chip = 'completed'; $label = 'Delivered'; }
                            ?>
                                <tr>
                                    <td class="fw-num"><?= date('M d, Y H:i', strtotime($log['created_at'])) ?></td>
                                    <td><?= htmlspecialchars($log['item_product']) ?></td>
                                    <td><span class="fr-badge <?= $chip ?>"><?= htmlspecialchars($label) ?></span></td>
                                    <td class="fw-num" style="color: var(--fr-muted);"><?= $log['old_delivered'] !== null ? number_format((float)$log['old_delivered'], 2) : '—' ?></td>
                                    <td class="fw-num fw-semibold"><?= number_format((float)$log['new_delivered'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

<!-- Add Stock Modal (Bootstrap) -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Delivered Stock</h5>
                <button type="button" class="btn-close" onclick="closeAddModal()"></button>
            </div>
            <form method="POST" action="foreman.php?tab=materials" id="addForm">
                <?= csrf_field() ?>
                <input type="hidden" name="project_id" id="addProjectId">
                <input type="hidden" name="quotation_material_id" id="addMaterialId">
                <div class="modal-body">
                    <p style="color: var(--fr-muted); font-size: 13px;">
                        Enter the amount to add to the <strong style="color: #047857;">Delivered</strong> quantity.
                    </p>
                    <label class="form-label">Amount to Add</label>
                    <input type="number" name="add_amount" id="addAmount" class="form-control" step="1" min="1" required>
                    <div class="form-text">Maximum remaining: <span id="addMaxRemaining">0</span></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="closeAddModal()">Cancel</button>
                    <button type="submit" name="add_quantity" class="btn btn-primary">Add Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Record Usage Modal (Bootstrap) -->
<div class="modal fade" id="useModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Record Material Usage</h5>
                <button type="button" class="btn-close" onclick="closeUseModal()"></button>
            </div>
            <form method="POST" action="foreman.php?tab=materials" id="useForm">
                <?= csrf_field() ?>
                <input type="hidden" name="project_id" id="useProjectId">
                <input type="hidden" name="quotation_material_id" id="useMaterialId">
                <div class="modal-body">
                    <p style="color: var(--fr-muted); font-size: 13px;">
                        Enter the amount to add to the <strong style="color: #b45309;">Used</strong> quantity.
                        Inventory will decrease by the same amount.
                    </p>
                    <label class="form-label">Amount to Record as Used</label>
                    <input type="number" name="use_amount" id="useAmount" class="form-control" step="1" min="1" required>
                    <div class="form-text">Maximum available inventory: <span id="useMaxRemaining">0</span></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="closeUseModal()">Cancel</button>
                    <button type="submit" name="add_used_quantity" class="btn btn-primary">Record Usage</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Keep the existing inline-edit / modal JS but switch to Bootstrap modal API -->
<script>
function makeEditable(el) {
    var edit = el.nextElementSibling;
    if (edit && edit.classList.contains('delivered-edit')) {
        el.style.display = 'none';
        edit.style.display = 'inline-flex';
        var input = edit.querySelector('input[type="number"]');
        if (input) input.focus();
    }
}

function cancelEdit(btn) {
    var edit = btn.closest('.delivered-edit');
    if (!edit) return;
    var display = edit.previousElementSibling;
    if (display && display.classList.contains('delivered-display')) {
        edit.style.display = 'none';
        display.style.display = 'inline';
    }
}

function openAddModal(projectId, materialId, maxRemaining) {
    document.getElementById('addProjectId').value = projectId;
    document.getElementById('addMaterialId').value = materialId;
    var input = document.getElementById('addAmount');
    input.value = ''; input.max = maxRemaining;
    document.getElementById('addMaxRemaining').textContent = Number(maxRemaining).toFixed(2);
    new bootstrap.Modal(document.getElementById('addModal')).show();
}
function closeAddModal() {
    bootstrap.Modal.getInstance(document.getElementById('addModal'))?.hide();
}
function openUseModal(projectId, materialId, maxAvailable) {
    document.getElementById('useProjectId').value = projectId;
    document.getElementById('useMaterialId').value = materialId;
    var input = document.getElementById('useAmount');
    input.value = ''; input.max = maxAvailable;
    document.getElementById('useMaxRemaining').textContent = Number(maxAvailable).toFixed(2);
    new bootstrap.Modal(document.getElementById('useModal')).show();
}
function closeUseModal() {
    bootstrap.Modal.getInstance(document.getElementById('useModal'))?.hide();
}
</script>