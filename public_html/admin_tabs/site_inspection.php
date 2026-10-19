<?php
// This file is included inside admin.php

// --- Handle actions ---
// =====================================================================
// Roof shapes (Roofing Category) — add / remove options here anytime.
// =====================================================================
$roof_shapes = [
    'Gable Roof',
    'Hip Roof',
    'Flat Roof',
    'Shed Roof'
];

// =====================================================================
// Helper: derive the roof measurements from the foreman's Length /
// Width / Rise inputs. Mirrors foreman_tabs/f_site_inspection.php.
// =====================================================================
function calcInspectionRoofMeasurements(string $category, float $length, float $width, float $rise): array
{
    $ridge = 0.0; $eave = 0.0; $hip_rafter = 0.0; $area = 0.0;

    switch ($category) {
        case 'Gable Roof':
            $sloped = sqrt(pow($width / 2, 2) + pow($rise, 2));
            $area   = 2 * $length * $sloped;
            $ridge  = $length;
            $eave   = 2 * $length;
            break;
        case 'Hip Roof':
            $sloped     = sqrt(pow($width / 2, 2) + pow($rise, 2));
            $hip_rafter = sqrt(pow($rise, 2) + (pow($width, 2) / 2));
            $area       = max(0, (2 * $length - $width)) * $sloped + $width * $hip_rafter;
            $ridge      = max(0, $length - $width);
            $eave       = 2 * ($length + $width);
            break;
        case 'Flat Roof':
            $area = $length * $width;
            $eave = 2 * ($length + $width);
            break;
        case 'Shed Roof':
            $sloped = sqrt(pow($width, 2) + pow($rise, 2));
            $area   = $length * $sloped;
            $eave   = $length;
            break;
    }

    return [
        'ridge'      => round($ridge, 2),
        'eave'       => round($eave, 2),
        'hip_rafter' => round($hip_rafter, 2),
        'area'       => round($area, 2),
    ];
}

// Update inspection (edit)
if (isset($_POST['edit_inspection'])) {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $id = (int)$_POST['edit_id'];

    // Get current inspection first
    $stmt = $pdo->prepare("SELECT * FROM site_inspections WHERE id = ?");
    $stmt->execute([$id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current) {
        header('Location: admin.php?tab=site_inspection&msg=error');
        exit;
    }
    if ($current['status'] === 'Completed') {
        header('Location: admin.php?tab=site_inspection&msg=inspection_locked');
        exit;
    }

    // Admin now only assigns / reassigns the foreman here.
    // Everything else (client, location, remarks, field data) is owned by
    // other tabs or by the foreman's Site Inspection tab.
    $inspector_id = !empty($_POST['inspector_id']) ? (int)$_POST['inspector_id'] : null;

    $stmt = $pdo->prepare("UPDATE site_inspections SET inspector_id = :inspector_id WHERE id = :id");
    $stmt->execute([
        ':inspector_id' => $inspector_id,
        ':id'           => $id
    ]);

    // =====================================================
    // Notification when inspector assignment changes
    // =====================================================
    if ($inspector_id != $current['inspector_id']) {
        // Fetch inspector name (if assigned)
        $inspector_name = null;
        if ($inspector_id) {
            $inspector_stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $inspector_stmt->execute([$inspector_id]);
            $inspector_name = $inspector_stmt->fetchColumn();
        }

        // 1) Notify the inspector (foreman)
        if ($inspector_id && $inspector_name) {
            $message = "You have been assigned as inspector for site inspection {$current['inspection_id']} on {$current['inspection_date']} at {$current['location']} for client {$current['client_name']}.";
            $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, message) VALUES (?, NULL, ?)");
            $notif_stmt->execute([$inspector_id, $message]);
        }

        // 2) Notify the client
        $client_id = $current['client_id'] ?? null;
        if (!$client_id && !empty($current['booking_id'])) {
            $booking_stmt = $pdo->prepare("SELECT client_id FROM bookings WHERE id = ?");
            $booking_stmt->execute([$current['booking_id']]);
            $client_id = $booking_stmt->fetchColumn();
        }

        if ($client_id) {
            $inspector_display = $inspector_name ? $inspector_name : 'Unassigned';
            $message = "Your site inspection on {$current['inspection_date']} has been assigned to {$inspector_display}.";
            $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, message) VALUES (?, NULL, ?)");
            $notif_stmt->execute([$client_id, $message]);
        }
    }
    // =====================================================

    header('Location: admin.php?tab=site_inspection&msg=assigned');
    exit;
}

// REVISION: Only the assigned Foreman completes an inspection after validation.
// Reject old forms or manually submitted Admin completion requests as well.
if (isset($_POST['complete'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    header('Location: admin.php?tab=site_inspection&msg=foreman_completion_only');
    exit;
}


// --- Fetch data ---

// Status filter
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : 'all';
$allowed_status = ['Scheduled', 'Completed', 'all'];
if (!in_array($status_filter, $allowed_status)) {
    $status_filter = 'all';
}

// Build query with join to get inspector name
$sql = "SELECT si.*, u.full_name AS inspector_name
        FROM site_inspections si
        LEFT JOIN users u ON si.inspector_id = u.id
        WHERE 1=1";
$params = [];
if ($status_filter !== 'all') {
    $sql .= " AND si.status = :status";
    $params[':status'] = $status_filter;
}
$sql .= " ORDER BY si.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts per status
$counts = ['Scheduled' => 0, 'Completed' => 0];
$stmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM site_inspections GROUP BY status");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = $row['cnt'];
    }
}

// Load linear measurements for all displayed inspections in one query.
$measurementsByInspection = [];

if (!empty($inspections)) {

    $ids = array_column($inspections, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare("
        SELECT *
        FROM site_inspection_measurements
        WHERE inspection_id IN ($placeholders)
        ORDER BY id ASC
    ");
    $stmt->execute($ids);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $measurementsByInspection[(int)$row['inspection_id']][] = $row;
    }
}

// Attachments per inspection, for the read-only view inside Details.
$inspectionAttachmentsByInspection = [];

if (!empty($inspections)) {

    $ids = array_column($inspections, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name AS uploaded_by_name
        FROM site_inspection_attachments a
        LEFT JOIN users u ON u.id = a.uploaded_by
        WHERE a.inspection_id IN ($placeholders)
        ORDER BY a.id ASC
    ");
    $stmt->execute($ids);

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $inspectionAttachmentsByInspection[(int)$r['inspection_id']][] = [
            'id'               => (int)$r['id'],
            'url'              => 'inspection_attachment.php?id=' . (int)$r['id'],
            'original_name'    => $r['original_name'],
            'uploaded_by_name' => $r['uploaded_by_name'] ?? '',
            'file_size'        => (int)$r['file_size'],
            'uploaded_at'      => $r['uploaded_at'],
        ];
    }
}

// Lists for dropdowns (inspectors: only Foremen)
$inspectors = $pdo->query("SELECT id, full_name FROM users WHERE role = 'foreman' AND status = 'active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

// Message handling
$msg = '';
if (isset($_GET['msg'])) {
    $msgs = [
        'updated'        => 'Inspection updated successfully.',
        'assigned'       => 'Inspector assignment updated.',
        'status_updated' => 'Status updated successfully.',
        'error'          => 'An error occurred.',
        'foreman_completion_only' => 'The assigned Foreman must complete the inspection with all required details.',
        'inspection_locked' => 'Cannot reassign the foreman — this inspection has already been completed.',
        'invalid_labor'  => 'Labor Requirement must be a positive integer of at least 1.'
    ];
    $msg = $msgs[$_GET['msg']] ?? '';
}

// For editing: fetch inspection data
$editInspection = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM site_inspections WHERE id = ?");
    $stmt->execute([$id]);
    $editInspection = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editInspection) {
        unset($_GET['edit']);
    }
}
?>
<?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- Stats -->
<div class="stats">
    <div class="stat-card"><div class="number"><?= $counts['Scheduled'] ?></div><div class="label">Scheduled</div></div>
    <div class="stat-card"><div class="number"><?= $counts['Completed'] ?></div><div class="label">Completed</div></div>
</div>

<!-- Filter -->
<div class="top-bar">
    <form method="GET" class="d-flex align-items-center gap-2">
        <input type="hidden" name="tab" value="site_inspection">
        <label for="status_filter">Status:</label>
        <select name="status_filter" id="status_filter" onchange="this.form.submit()">
            <option value="all" <?= $status_filter=='all'?'selected':'' ?>>All</option>
            <option value="Scheduled" <?= $status_filter=='Scheduled'?'selected':'' ?>>Scheduled</option>
            <option value="Completed" <?= $status_filter=='Completed'?'selected':'' ?>>Completed</option>
        </select>
    </form>
</div>

<!-- Table -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Inspection ID</th><th>Client</th><th>Inspection Date</th><th>Inspector</th>
                <th>Location</th><th>Roof Area (sqm)</th><th>Status</th><th style="text-align: center">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (count($inspections) === 0): ?>
            <tr><td colspan="8" class="text-center py-5" style="color: var(--fr-muted);">No inspections found.</td></tr>
        <?php else: ?>
            <?php foreach ($inspections as $ins): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($ins['inspection_id']) ?></strong></td>
                    <td><?= htmlspecialchars($ins['client_name']) ?></td>
                    <td><?= date('M d, Y', strtotime($ins['inspection_date'])) ?></td>
                    <td><?= htmlspecialchars($ins['inspector_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($ins['location']) ?></td>
                    <td>
                        <?= $ins['roof_area'] !== null
                            ? number_format((float)$ins['roof_area'], 2)
                            : '<span style="color: var(--fr-muted);">—</span>' ?>
                    </td>
                    <td>
                        <span class="fr-badge <?= strtolower(str_replace(' ', '-', $ins['status'])) ?>">
                            <?= $ins['status'] ?>
                        </span>
                    </td>
                    <td class="actions">
                        <button type="button" class="action-btn view-btn"
                                onclick="document.getElementById('detailsModal<?= (int)$ins['id'] ?>').classList.add('show');">Details</button>
                        <?php if ($ins['status'] !== 'Completed'): ?>
                            <a href="admin.php?tab=site_inspection&edit=<?= $ins['id'] ?>" class="action-btn edit"
                               onclick="document.getElementById('editModal<?= $ins['id'] ?>').classList.add('show'); return false;">Assign</a>
                        <?php else: ?>
                            <span class="action-btn" style="cursor:not-allowed; opacity:.55; background:#f3f4f6; border-color:#e5e7eb; color:#9ca3af;">
                                <i class="bi bi-lock-fill"></i> Locked
                            </span>
                        <?php endif; ?>
                        <!-- REVISION: Completion is handled in the assigned Foreman portal. -->
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modals (all class changed to ad-modal) -->
<?php if (count($inspections) > 0): ?>
    <?php foreach ($inspections as $ins):
        $rowMeasurements = $measurementsByInspection[(int)$ins['id']] ?? [];
    ?>
        <!-- Edit Modal -->
        <div id="editModal<?= $ins['id'] ?>" class="ad-modal">
            <div class="ad-modal-content" style="max-width: 520px;">
                <button type="button" class="close" onclick="document.getElementById('editModal<?= $ins['id'] ?>').classList.remove('show')">&times;</button>
        
                <h2>Assign Inspector</h2>
                <p class="ad-modal-subtitle">
                    <strong><?= htmlspecialchars($ins['inspection_id']) ?></strong>
                    &nbsp;·&nbsp; <?= htmlspecialchars($ins['client_name']) ?>
                    &nbsp;·&nbsp; <?= htmlspecialchars(date('M d, Y', strtotime($ins['inspection_date']))) ?>
                </p>
        
                <form method="POST" action="admin.php?tab=site_inspection">
                    <?= csrf_field() ?>
                    <input type="hidden" name="edit_id" value="<?= (int)$ins['id'] ?>">
        
                    <div class="form-group">
                        <label>Foreman</label>
                        <select name="inspector_id">
                            <option value="">— Unassigned —</option>
                            <?php foreach ($inspectors as $insp): ?>
                                <option value="<?= $insp['id'] ?>" <?= $insp['id']==$ins['inspector_id']?'selected':'' ?>>
                                    <?= htmlspecialchars($insp['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text">
                            The assigned foreman will be notified and can complete the site assessment from their dashboard.
                        </small>
                    </div>
        
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="document.getElementById('editModal<?= $ins['id'] ?>').classList.remove('show')">Cancel</button>
                        <button type="submit" name="edit_inspection" class="btn btn-primary">Save Assignment</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Details Modal -->
        <div id="detailsModal<?= (int)$ins['id'] ?>" class="ad-modal">
            <div class="ad-modal-content" style="max-width: 780px;">
                <button type="button" class="close" onclick="document.getElementById('detailsModal<?= (int)$ins['id'] ?>').classList.remove('show')">&times;</button>
        
                <h2>Inspection Details — <?= htmlspecialchars($ins['inspection_id']) ?></h2>
                <p class="ad-modal-subtitle">
                    <?= htmlspecialchars($ins['client_name']) ?>
                    &nbsp;·&nbsp; <?= htmlspecialchars(date('M d, Y', strtotime($ins['inspection_date']))) ?>
                    <?php if (!empty($ins['inspector_name'])): ?>
                        &nbsp;·&nbsp; <?= htmlspecialchars($ins['inspector_name']) ?>
                    <?php endif; ?>
                </p>
        
                <h3 class="fr-section-title">Overview</h3>
                <div class="ad-detail-grid">
                    <div class="ad-detail-card">
                        <div class="ad-detail-label">Booking ID</div>
                        <div class="ad-detail-value"><?= htmlspecialchars($ins['booking_id'] ?? '—') ?></div>
                    </div>
                    <div class="ad-detail-card">
                        <div class="ad-detail-label">Status</div>
                        <div class="ad-detail-value">
                            <span class="fr-badge <?= strtolower(str_replace(' ', '-', $ins['status'])) ?>">
                                <?= htmlspecialchars($ins['status']) ?>
                            </span>
                        </div>
                    </div>
                    <div class="ad-detail-card">
                        <div class="ad-detail-label">Inspection Date</div>
                        <div class="ad-detail-value"><?= htmlspecialchars(date('M d, Y', strtotime($ins['inspection_date']))) ?></div>
                    </div>
                    <div class="ad-detail-card">
                        <div class="ad-detail-label">Inspector</div>
                        <div class="ad-detail-value"><?= htmlspecialchars($ins['inspector_name'] ?? '—') ?></div>
                    </div>
                    <div class="ad-detail-card full">
                        <div class="ad-detail-label">Location</div>
                        <div class="ad-detail-value"><?= nl2br(htmlspecialchars($ins['location'] ?? '—')) ?></div>
                    </div>
                </div>
        
                <h3 class="fr-section-title">Inspection Details</h3>
                <div class="ad-detail-grid">
                    <div class="ad-detail-card">
                        <div class="ad-detail-label">Roof Condition</div>
                        <div class="ad-detail-value"><?= htmlspecialchars(ucfirst($ins['existing_roof_condition'] ?? '—')) ?></div>
                    </div>
                    <div class="ad-detail-card">
                        <div class="ad-detail-label">Service Type</div>
                        <div class="ad-detail-value"><?= htmlspecialchars(ucfirst($ins['roof_type'] ?? '—')) ?></div>
                    </div>
                </div>
        
                <h3 class="fr-section-title">Roofing Specification</h3>
                <div class="ad-detail-grid">
                    <div class="ad-detail-card">
                        <div class="ad-detail-label">Product Category</div>
                        <div class="ad-detail-value"><?= htmlspecialchars(ucfirst($ins['product_category'] ?? '—')) ?></div>
                    </div>
                    <div class="ad-detail-card">
                        <div class="ad-detail-label">Roofing Type</div>
                        <div class="ad-detail-value"><?= htmlspecialchars($ins['roofing_category'] ?? '—') ?></div>
                    </div>
                    <div class="ad-detail-card full">
                        <div class="ad-detail-label">Product / Profile</div>
                        <div class="ad-detail-value"><?= htmlspecialchars($ins['roofing_product'] ?? '—') ?></div>
                    </div>
                    <div class="ad-detail-card">
                        <div class="ad-detail-label">Labor Requirement</div>
                        <div class="ad-detail-value"><?= (int)($ins['labor_requirement'] ?? 0) ?> workers</div>
                    </div>
                </div>
        
                <h3 class="fr-section-title">Site Assessment</h3>
                <div class="ad-detail-grid">
                    <div class="ad-detail-card">
                        <div class="ad-detail-label">Site Accessibility</div>
                        <div class="ad-detail-value"><?= htmlspecialchars($ins['site_accessibility'] ?? '—') ?></div>
                    </div>
                    <div class="ad-detail-card">
                        <div class="ad-detail-label">Site Preparation</div>
                        <div class="ad-detail-value"><?= htmlspecialchars($ins['site_preparation'] ?? '—') ?></div>
                    </div>
                </div>
        
                <?php
                // Extract the foreman's inputs from the measurements table.
                $rm_data = ['length' => 0.0, 'width' => 0.0, 'rise' => 0.0];
                foreach ($rowMeasurements as $m) {
                    $comp = strtolower(trim((string)$m['component']));
                    if ($comp === 'roof length')      $rm_data['length'] = (float)$m['length'];
                    elseif ($comp === 'roof width')   $rm_data['width']  = (float)$m['length'];
                    elseif ($comp === 'rise')         $rm_data['rise']   = (float)$m['length'];
                }

                $roof_cat = $ins['roofing_category'] ?? '';
                $is_flat  = ($roof_cat === 'Flat Roof');
                $is_hip   = ($roof_cat === 'Hip Roof');
                $is_gable = ($roof_cat === 'Gable Roof');

                $calc_rm = calcInspectionRoofMeasurements(
                    $roof_cat,
                    $rm_data['length'],
                    $rm_data['width'],
                    $rm_data['rise']
                );
                ?>

                <h3 class="fr-section-title">Required Measurements</h3>

                <?php if ($rm_data['length'] <= 0 && $rm_data['width'] <= 0): ?>
                    <div class="text-center py-3" style="color: var(--fr-muted); font-size: 13px; border: 1px dashed var(--fr-border); border-radius: 8px;">
                        No measurements recorded yet.
                    </div>
                <?php else: ?>
                    <div class="ad-detail-grid">
                        <div class="ad-detail-card">
                            <div class="ad-detail-label">Roof Length</div>
                            <div class="ad-detail-value">
                                <?= $rm_data['length'] > 0 ? number_format($rm_data['length'], 2) . ' m' : '<span class="is-muted">—</span>' ?>
                            </div>
                        </div>
                        <div class="ad-detail-card">
                            <div class="ad-detail-label">Roof Width</div>
                            <div class="ad-detail-value">
                                <?= $rm_data['width'] > 0 ? number_format($rm_data['width'], 2) . ' m' : '<span class="is-muted">—</span>' ?>
                            </div>
                        </div>
                        <?php if (!$is_flat): ?>
                            <div class="ad-detail-card">
                                <div class="ad-detail-label">Rise</div>
                                <div class="ad-detail-value">
                                    <?= $rm_data['rise'] > 0 ? number_format($rm_data['rise'], 2) . ' m' : '<span class="is-muted">—</span>' ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($is_gable || $is_hip): ?>
                            <div class="ad-detail-card">
                                <div class="ad-detail-label">Ridge</div>
                                <div class="ad-detail-value">
                                    <?= $calc_rm['ridge'] > 0 ? number_format($calc_rm['ridge'], 2) . ' m' : '<span class="is-muted">—</span>' ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="ad-detail-card">
                            <div class="ad-detail-label"><?= $is_flat ? 'Eave / Perimeter' : 'Eave' ?></div>
                            <div class="ad-detail-value">
                                <?= $calc_rm['eave'] > 0 ? number_format($calc_rm['eave'], 2) . ' m' : '<span class="is-muted">—</span>' ?>
                            </div>
                        </div>
                        <?php if ($is_hip): ?>
                            <div class="ad-detail-card">
                                <div class="ad-detail-label">Hip Rafter Length</div>
                                <div class="ad-detail-value">
                                    <?= $calc_rm['hip_rafter'] > 0 ? number_format($calc_rm['hip_rafter'], 2) . ' m' : '<span class="is-muted">—</span>' ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="ad-detail-card full">
                            <div class="ad-detail-label">Roof Area</div>
                            <div class="ad-detail-value" style="color: var(--fr-red);">
                                <?= $calc_rm['area'] > 0 ? number_format($calc_rm['area'], 2) . ' m²' : '<span class="is-muted">—</span>' ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
        
                <h3 class="fr-section-title mt-3">
                    Attached Photos
                    <?php $rowPhotos = $inspectionAttachmentsByInspection[(int)$ins['id']] ?? []; ?>
                    <?php if (!empty($rowPhotos)): ?>
                        <span class="fr-badge followup"><?= count($rowPhotos) ?></span>
                    <?php endif; ?>
                </h3>
        
                <?php if (empty($rowPhotos)): ?>
                    <div class="text-center py-3" style="color: var(--fr-muted); font-size: 13px; border: 1px dashed var(--fr-border); border-radius: 8px;">
                        No photos attached to this inspection.
                    </div>
                <?php else: ?>
                    <div class="row g-2">
                        <?php foreach ($rowPhotos as $idx => $photo): ?>
                            <div class="col-6 col-md-4 col-lg-3">
                                <button type="button"
                                        class="admin-inspection-photo-btn d-block rounded-3 overflow-hidden w-100 p-0"
                                        style="border: 1px solid var(--fr-border); aspect-ratio: 1/1; background: #111; cursor: pointer;"
                                        data-inspection-id="<?= (int)$ins['id'] ?>"
                                        data-photo-index="<?= (int)$idx ?>"
                                        title="Click to view">
                                    <img src="<?= htmlspecialchars($photo['url']) ?>"
                                         alt="<?= htmlspecialchars($photo['original_name']) ?>"
                                         style="width: 100%; height: 100%; object-fit: cover; display: block;">
                                </button>
                                <div class="small text-muted mt-1" style="font-size: 11px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?= htmlspecialchars($photo['original_name']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
        
                <div class="form-actions mt-4">
                    <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('detailsModal<?= (int)$ins['id'] ?>').classList.remove('show')">Close</button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Auto-open edit modal -->
<?php if (isset($_GET['edit']) && $editInspection): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('editModal<?= (int)$_GET['edit'] ?>').classList.add('show');
});
</script>
<?php endif; ?>

<!-- Inspection Photo Lightbox (read-only) -->
<div class="modal fade" id="adminInspectionPhotoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content" style="max-width: 1140px;">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Inspection Photos</h5>
                    <div class="admin-inspection-photo-desc text-muted small"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="admin-inspection-photo-stage position-relative rounded-3 mb-3" style="background:#111; overflow:hidden;">
                    <img class="admin-inspection-photo-main" src="" alt=""
                         style="width:100%; display:block; max-height:65vh; object-fit:contain; background:#111;">
                    <button type="button"
                            class="admin-inspection-photo-prev btn btn-dark position-absolute top-50 start-0 translate-middle-y ms-2"
                            aria-label="Previous">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <button type="button"
                            class="admin-inspection-photo-next btn btn-dark position-absolute top-50 end-0 translate-middle-y me-2"
                            aria-label="Next">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                    <div class="admin-inspection-photo-meta position-absolute bottom-0 start-0 end-0 p-2 small text-white-50"
                         style="background: linear-gradient(to top, rgba(0,0,0,.7), transparent);"></div>
                </div>
                <div class="admin-inspection-photo-strip d-flex gap-2 overflow-auto" style="padding-bottom: 4px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
window.__adminInspectionPhotos = <?= json_encode(
    $inspectionAttachmentsByInspection,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('adminInspectionPhotoModal');
    if (!modalEl) return;

    const modal   = new bootstrap.Modal(modalEl);
    const descEl  = modalEl.querySelector('.admin-inspection-photo-desc');
    const mainImg = modalEl.querySelector('.admin-inspection-photo-main');
    const metaEl  = modalEl.querySelector('.admin-inspection-photo-meta');
    const prevBtn = modalEl.querySelector('.admin-inspection-photo-prev');
    const nextBtn = modalEl.querySelector('.admin-inspection-photo-next');
    const stripEl = modalEl.querySelector('.admin-inspection-photo-strip');

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
        if (!items.length) return;

        // Wrap-around navigation
        if (index < 0) index = items.length - 1;
        if (index >= items.length) index = 0;

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
        items.forEach(function (a, i) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn p-0 border rounded-2 flex-shrink-0';
            btn.style.width = '72px';
            btn.style.height = '72px';
            btn.style.overflow = 'hidden';
            if (i === index) {
                btn.classList.add('border-primary');
                btn.style.borderWidth = '2px';
            }
            btn.innerHTML = '<img src="' + a.url + '" alt="" style="width:100%;height:100%;object-fit:cover;display:block;">';
            btn.addEventListener('click', function () { index = i; render(); });
            stripEl.appendChild(btn);
        });
    }

    // Delegate: open the lightbox on any thumbnail click.
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.admin-inspection-photo-btn');
        if (!btn) return;

        const inspectionId = parseInt(btn.dataset.inspectionId, 10);
        const photoIndex   = parseInt(btn.dataset.photoIndex, 10) || 0;

        items = (window.__adminInspectionPhotos &&
                 window.__adminInspectionPhotos[inspectionId]) || [];

        if (!items.length) return;

        index = photoIndex;
        descEl.textContent = '';
        render();
        modal.show();
    });

    prevBtn.addEventListener('click', function () { index--; render(); });
    nextBtn.addEventListener('click', function () { index++; render(); });

    // Keyboard: Left / Right arrows while the lightbox is open.
    document.addEventListener('keydown', function (e) {
        if (!modalEl.classList.contains('show')) return;
        if (e.key === 'ArrowLeft')  { e.preventDefault(); index--; render(); }
        if (e.key === 'ArrowRight') { e.preventDefault(); index++; render(); }
    });
});
</script>

<!-- KEEP the product map JavaScript entirely unchanged -->
<script>
// Same map used in booking.php
const productByCategory = {
    'tile-profile roofing': ['Banawe', 'Hacienda (Royal Mediterranean Series)', 'Valencia (Royal Mediterranean Series)', 'Tilespan Elite', 'Tilespan Maxi', 'Satori Tile'],
    'ribbed roofing': ['Twin Rib', 'EPR', 'R-Span', 'Multi R-Span', 'Corspan', 'Mega 2A Rib', 'Big V'],
    'specialty roofing': ['Retrofitter', 'Curve Roof', 'Steel Decking'],
    'ceilings': ['Spandrel'],
    'standing seam roofing': ['Super R-Span', 'Standing S-Span', 'S-Rib Standing Seam']
};
const legacyCategoryMap = {
    'curved roofing': ['Curve Roof'],
    'steel decking': ['Steel Decking']
};

function populateProductDropdown(selectElement, categoryValue, selectedProduct) {
    selectElement.innerHTML = '';

    const products =
        productByCategory[categoryValue] ||
        legacyCategoryMap[categoryValue] ||
        [];

    let matched = false;

    products.forEach(function (product) {
        const option = document.createElement('option');
        option.value = product;
        option.textContent = product;

        if (
            selectedProduct &&
            product.toLowerCase() === String(selectedProduct).toLowerCase()
        ) {
            option.selected = true;
            matched = true;
        }

        selectElement.appendChild(option);
    });

    // Preserve a product value that isn't in the current list
    // (older casing, legacy product, etc.) so saving doesn't wipe it.
    if (selectedProduct && !matched) {
        const option = document.createElement('option');
        option.value = selectedProduct;
        option.textContent = selectedProduct + ' (legacy)';
        option.selected = true;
        selectElement.insertBefore(option, selectElement.firstChild);
    }

    if (products.length === 0 && !selectedProduct) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'No products available for this category';
        option.disabled = true;
        option.selected = true;
        selectElement.appendChild(option);
    }
}

function setupCategoryProductPair(categorySelect, productSelect, initialProduct) {
    populateProductDropdown(productSelect, categorySelect.value, initialProduct);

    categorySelect.addEventListener('change', function () {
        populateProductDropdown(productSelect, this.value, null);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[id^="editModal"]').forEach(function (modal) {
        const category = modal.querySelector('select[name="roofing_category"]');
        const product = modal.querySelector('select[name="roofing_product"]');
        if (!category || !product) return;

        const currentProduct = product.dataset.currentProduct || '';
        setupCategoryProductPair(category, product, currentProduct);
    });
});
</script>