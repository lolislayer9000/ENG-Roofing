<?php
// REVISION: Confirmed AM/PM slots, submission timestamps and atomic approval.
require_once __DIR__ . "/../booking_schedule_helpers.php";
require_once __DIR__ . "/../guest_tracking_helpers.php"; // REVISION: Approval email tracking.
// This file is included inside admin.php, so $pdo, session, and $tab are available.

// --- Handle POST/GET actions for booking ---



// Approve booking
if (isset($_POST['approve'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $id = (int)$_POST['approve'];

    $result = saveBookingSchedule($pdo, $id, true);
    if (!$result['ok']) {
        header('Location: admin.php?tab=booking&msg=' . $result['message']);
        exit;
    }
    $booking = $result['booking'];

    // --- Notify the client ---
    try {
        $client_id = $booking['client_id'];
        $base = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://')
              . $_SERVER['HTTP_HOST']
              . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    
        if ($client_id) {
            // User exists — in-app notification + email.
            $message = "Your booking {$booking['booking_id']} has been approved.";
            $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, message) VALUES (?, NULL, ?)");
            $notif_stmt->execute([$client_id, $message]);
    
            $subject = "Your booking {$booking['booking_id']} has been approved";
            $body = "Hi {$booking['client_full_name']},\n\n"
                  . "Good news — your booking request ({$booking['booking_id']}) has been approved.\n\n"
                  . "Log in to view it and track the project:\n"
                  . "$base/login.php\n\n"
                  . "— " . ($company_settings['company_name'] ?? 'Eng Roofing');
        } else {
            // Fallback: an unverified guest was approved via "show unverified".
            // We can't guarantee they ever verified, so send them the register link.
            $registerUrl = $base . '/login.php?mode=register&email=' . urlencode($booking['client_email']) . '&from=booking';
    
            $subject = "Your booking {$booking['booking_id']} has been approved";
            $body = "Hi {$booking['client_full_name']},\n\n"
                  . "Good news — your booking request ({$booking['booking_id']}) has been approved.\n\n"
                  . "Register to view it:\n"
                  . "$registerUrl\n\n"
                  . "— " . ($company_settings['company_name'] ?? 'Eng Roofing');
        }
    
        $fromEmail = !empty($company_settings['company_email']) ? $company_settings['company_email'] : 'no-reply@engroofing.com';
        $fromName  = $company_settings['company_name'] ?? 'Eng Roofing';
    
        // REVISION: Keep account access optional; add booking-scoped guest tracking.
        $body .= "\n\nTrack without logging in:\n" . guestTrackingUrl($pdo, (int)$booking['id'])
               . "\nPlease keep this link private.";
        sendMail(
            $booking['client_email'],
            $booking['client_full_name'],
            $subject,
            $body,
            false,
            $fromEmail,
            $fromName
        );
    } catch (Throwable $e) {
        error_log("Failed to send approval notification for booking {$booking['booking_id']}: " . $e->getMessage());
    }
    
    header('Location: admin.php?tab=booking&msg=approved');
    exit;
}

// Reject booking
if (isset($_POST['reject'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $id = (int)$_POST['reject'];

    // Fetch the booking to get client_id and booking_id
    $stmt = $pdo->prepare("SELECT client_id, booking_id FROM bookings WHERE id = ?");
    $stmt->execute([$id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        header('Location: admin.php?tab=booking&msg=error');
        exit;
    }

    $stmt = $pdo->prepare("UPDATE bookings SET status='rejected' WHERE id=? AND status='pending'");
    $stmt->execute([$id]);

    // REVISION: Do not reject an already-approved booking through an old form.
    if ($stmt->rowCount() === 0) {
        header('Location: admin.php?tab=booking&msg=error');
        exit;
    }

    // --- Send notification to the client ---
    try {
        $client_id = $booking['client_id'];
    
        if ($client_id) {
            $message = "Your booking {$booking['booking_id']} has been rejected.";
            $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, message) VALUES (?, NULL, ?)");
            $notif_stmt->execute([$client_id, $message]);
        }
    
        // Always email.
        $subject = "Update on your booking {$booking['booking_id']}";
        $body = "Hi {$booking['client_full_name']},\n\n"
              . "Unfortunately, we're unable to proceed with your booking request ({$booking['booking_id']}).\n"
              . "You're welcome to contact us for more details.\n\n"
              . "— " . ($company_settings['company_name'] ?? 'Eng Roofing');
    
        $fromEmail = !empty($company_settings['company_email']) ? $company_settings['company_email'] : 'no-reply@engroofing.com';
        $fromName  = $company_settings['company_name'] ?? 'Eng Roofing';
    
        sendMail($booking['client_email'], $booking['client_full_name'], $subject, $body, false, $fromEmail, $fromName);
    } catch (Throwable $e) {
        error_log("Failed to send rejection notification for booking {$booking['booking_id']}: " . $e->getMessage());
    }

    header('Location: admin.php?tab=booking&msg=rejected');
    exit;
}

// Edit booking (update)
// Edit booking — only the Confirmed Inspection Date is editable.
if (isset($_POST['edit_booking'])) {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    // REVISION: Validate on the server; calendar restrictions alone are insufficient.
    $result = saveBookingSchedule(
        $pdo,
        (int)$_POST['edit_id'],
        false,
        trim((string)($_POST['inspection_date'] ?? '')),
        trim((string)($_POST['confirmed_slot'] ?? ''))
    );
    header('Location: admin.php?tab=booking&msg='
        . ($result['ok'] ? 'updated' : $result['message']));
    exit;
}

// --- Fetch data for display ---

// Status counts
$counts = ['pending'=>0, 'approved'=>0, 'rejected'=>0, 'rescheduled'=>0];
$stmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM bookings GROUP BY status");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = $row['cnt'];
    }
}

// All bookings — Flow A: only show bookings whose email is verified (or that are already tied to a client)
$show_unverified = isset($_GET['show_unverified']) && $_GET['show_unverified'] === '1';
$sql = "SELECT b.*, si.status AS inspection_status,
        sd.confirmed_slot, sd.approved_at, si.inspection_date AS linked_inspection_date
        FROM bookings b
        LEFT JOIN site_inspections si ON si.id = (
            SELECT latest.id FROM site_inspections latest
            WHERE latest.booking_id = b.booking_id ORDER BY latest.id DESC LIMIT 1
        )
        LEFT JOIN booking_schedule_details sd ON sd.booking_id = b.id";
if (!$show_unverified) {
    $sql .= " WHERE b.is_guest = 0 OR b.guest_email_verified = 1";
}
$sql .= " ORDER BY b.created_at DESC, b.id DESC";
$bookings = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------------------
// Flatpickr: only dates with a *confirmed* inspection are blocked.
// Pending bookings no longer block a date — they surface as a badge so the
// admin can see how many clients want the same day.
// Each booking's own dates are removed from its own list so its existing
// inspection_date stays selectable.
// -------------------------------------------------------------------------
// REVISION: Only dates with both slots occupied are fully blocked.
// Server validation checks the chosen slot and excludes the current booking.
$preferredDateCounts = getPreferredDateCounts($pdo);
$disabledByBooking = [];
$occupiedRows = bookingScheduleRows($pdo);
foreach ($bookings as $b) {
    $slotsByDate = [];
    foreach ($occupiedRows as $occupied) {
        if ((int)$occupied['id'] === (int)$b['id']) continue;
        $date = $occupied['effective_date'];
        $slot = $occupied['confirmed_slot'] ?: bookingSlotName($occupied['preferred_schedule']);
        if (!$slot) $slotsByDate[$date] = ['AM' => true, 'PM' => true];
        else $slotsByDate[$date][$slot] = true;
    }
    $disabledByBooking[(int)$b['id']] = array_values(array_keys(array_filter(
        $slotsByDate,
        function ($slots) { return isset($slots['AM'], $slots['PM']); }
    )));
}

// For editing, if we have an edit ID, fetch that booking (we'll use it to open modal)
$editBooking = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id=?");
    $stmt->execute([$id]);
    $editBooking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editBooking) {
        unset($_GET['edit']);
    }
}

// Message handling
$msg = '';
$msg_type = '';
if (isset($_GET['msg'])) {
    $msgs = [
        'added' => ['text' => 'Booking added successfully.', 'type' => ''],
        'approved' => ['text' => 'Booking approved.', 'type' => ''],
        'rejected' => ['text' => 'Booking rejected.', 'type' => ''],
        'updated' => ['text' => 'Booking updated.', 'type' => ''],
        'slot_conflict' => ['text' => 'That confirmed date and AM/PM slot is already occupied. Manage this booking and choose another date or slot before approving.', 'type' => 'error'],
        'invalid_schedule' => ['text' => 'Choose a valid inspection date and an AM/PM slot. New appointments cannot be in the past.', 'type' => 'error'],
        'error' => ['text' => 'An error occurred.', 'type' => 'error'],
        'invalid_contact' => ['text' => 'Contact number must be exactly 11 digits and start with 09.', 'type' => 'error'],
        'invalid_area' => ['text' => 'Invalid roof area.', 'type' => 'error'],
        'inspection_locked' => ['text' => 'This booking cannot be modified — its linked site inspection has already been completed.', 'type' => 'error'],
        'invalid_quantity' => ['text' => 'Invalid estimated quantity.', 'type' => 'error']
    ];
    if (isset($msgs[$_GET['msg']])) {
        $msg = $msgs[$_GET['msg']]['text'];
        $msg_type = $msgs[$_GET['msg']]['type'];
    }
}
?>
<?php if ($msg): ?>
    <div class="msg <?= htmlspecialchars($msg_type) ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats">
    <div class="stat-card"><div class="number"><?= $counts['pending'] ?></div><div class="label">Pending</div></div>
    <div class="stat-card"><div class="number"><?= $counts['approved'] ?></div><div class="label">Approved</div></div>
    <div class="stat-card"><div class="number"><?= $counts['rejected'] ?></div><div class="label">Rejected</div></div>
    <div class="stat-card"><div class="number"><?= $counts['rescheduled'] ?></div><div class="label">Rescheduled</div></div>
</div>

<!-- Table -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Booking ID</th><th>Client</th>
                <th>Preferred Inspection Date<span class="date-column-note">Requested by client</span></th>
                <th>Confirmed Inspection Date<span class="date-column-note">Scheduled by admin</span></th>
                <th>Status</th><th style="text-align: center">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($bookings) === 0): ?>
                <tr><td colspan="6" class="text-center py-5" style="color: var(--fr-muted);">No bookings yet.</td></tr>
            <?php else: ?>
                <?php foreach ($bookings as $b): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($b['booking_id']) ?></strong>
                            <!-- REVISION: Submission timestamp, not the preferred appointment date. -->
                            <br><small class="date-subtext">Submitted:
                                <?= !empty($b['created_at']) ? htmlspecialchars(date('M d, Y g:i A', strtotime($b['created_at']))) : 'Not recorded' ?>
                            </small>
                        </td>
                        <td>
                            <span class="client-name"><?= htmlspecialchars($b['client_full_name']) ?></span>
                            <span class="client-email"><?= htmlspecialchars($b['client_email']) ?></span>
                        </td>
                        <td>
                            <strong><?= date('M d, Y', strtotime($b['preferred_inspection_date'])) ?></strong>
                            <br><small class="date-subtext">Requested: <?= htmlspecialchars($b['preferred_schedule']) ?></small>
                        </td>
                        <td>
                            <?php $displayDate = $b['inspection_date'] ?: ($b['linked_inspection_date'] ?? null); ?>
                            <?php if (!empty($displayDate)): ?>
                                <strong><?= date('M d, Y', strtotime($displayDate)) ?></strong>
                                <br><small class="date-subtext">
                                    <?= htmlspecialchars($b['confirmed_slot'] ?: ((bookingSlotName($b['preferred_schedule']) ?? 'Unknown slot') . ' (legacy preference; verify)')) ?>
                                </small>
                            <?php else: ?>
                                <span class="muted">Not yet confirmed</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="fr-badge <?= htmlspecialchars($b['status']) ?>"><?= ucfirst($b['status']) ?></span>
                            <?php if ($b['status'] === 'approved'): ?>
                                <br><small class="date-subtext">Approved:
                                    <?= !empty($b['approved_at']) ? htmlspecialchars(date('M d, Y g:i A', strtotime($b['approved_at']))) : 'Time not recorded (legacy)' ?>
                                </small>
                            <?php endif; ?>
                            <?php if (!empty($b['is_guest']) && !$b['client_id']): ?>
                                <span class="fr-badge" style="background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;margin-left:4px;">
                                    Guest
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="actions">
                            <?php if ($b['status'] === 'pending'): ?>
                                <form method="POST" action="admin.php?tab=booking" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="approve" value="<?= $b['id'] ?>">
                                    <button type="submit" class="action-btn approve" onclick="return confirm('Approve this booking?')">Approve</button>
                                </form>
                                <form method="POST" action="admin.php?tab=booking" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="reject" value="<?= $b['id'] ?>">
                                    <button type="submit" class="action-btn reject" onclick="return confirm('Reject this booking?')">Reject</button>
                                </form>
                            <?php endif; ?>
                            <a href="admin.php?tab=booking&edit=<?= $b['id'] ?>" class="action-btn edit" onclick="document.getElementById('editModal<?= $b['id'] ?>').classList.add('show'); return false;">Manage</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
window.__disabledDatesByBooking = <?= json_encode(
    $disabledByBooking,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

window.__preferredDateCounts = <?= json_encode(
    $preferredDateCounts,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;
</script>

<!-- Edit modals — all class="modal" changed to "ad-modal" so Bootstrap doesn't clash -->
<!-- Manage modals — read-only detail view + editable Confirmed Inspection Date -->
<?php foreach ($bookings as $b): ?>
    <div id="editModal<?= (int)$b['id'] ?>" class="ad-modal">
        <div class="ad-modal-content booking-modal-content">

            <button type="button" class="close" aria-label="Close"
                    onclick="document.getElementById('editModal<?= (int)$b['id'] ?>').classList.remove('show')">&times;</button>

            <form method="POST" action="admin.php?tab=booking">
                <?= csrf_field() ?>
                <input type="hidden" name="edit_id" value="<?= (int)$b['id'] ?>">

                <div class="ad-modal-header">
                    <div class="ad-modal-header-main">
                        <h2>Booking Details</h2>
                        <p class="ad-modal-subtitle">
                            <strong><?= htmlspecialchars($b['booking_id']) ?></strong>
                            &nbsp;·&nbsp; <?= htmlspecialchars($b['client_full_name']) ?>
                            <?php if (!empty($b['created_at'])): ?>
                                &nbsp;·&nbsp; Submitted <?= htmlspecialchars(date('M d, Y g:i A', strtotime($b['created_at']))) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="ad-modal-header-meta">
                        <span class="fr-badge <?= htmlspecialchars($b['status']) ?>">
                            <?= htmlspecialchars(ucfirst($b['status'])) ?>
                        </span>
                        <?php if (!empty($b['is_guest']) && !$b['client_id']): ?>
                            <span class="fr-badge ad-guest-badge">Guest</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ad-modal-body">

                    <div class="ad-modal-section">
                        <h3 class="ad-modal-section-title">Client</h3>
                        <div class="spec-grid">
                            <div class="spec-item">
                                <span class="spec-label">Full Name</span>
                                <div class="spec-value"><?= htmlspecialchars($b['client_full_name']) ?></div>
                            </div>
                            <div class="spec-item">
                                <span class="spec-label">Contact Number</span>
                                <div class="spec-value"><?= htmlspecialchars($b['client_contact']) ?></div>
                            </div>
                            <div class="spec-item full">
                                <span class="spec-label">Email Address</span>
                                <div class="spec-value"><?= htmlspecialchars($b['client_email']) ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="ad-modal-section">
                        <h3 class="ad-modal-section-title">Project Information</h3>
                        <div class="spec-grid">
                            <div class="spec-item">
                                <span class="spec-label">Service Type</span>
                                <div class="spec-value"><?= htmlspecialchars(ucfirst($b['service_type'])) ?></div>
                            </div>
                            <div class="spec-item">
                                <span class="spec-label">Property Type</span>
                                <div class="spec-value"><?= htmlspecialchars(ucfirst($b['property_type'])) ?></div>
                            </div>
                            <div class="spec-item full">
                                <span class="spec-label">Project Location</span>
                                <div class="spec-value"><?= htmlspecialchars($b['project_location']) ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="ad-modal-section">
                        <h3 class="ad-modal-section-title">Client's Preferred Schedule</h3>
                        <div class="spec-grid">
                            <div class="spec-item">
                                <span class="spec-label">Preferred Date</span>
                                <div class="spec-value">
                                    <?= !empty($b['preferred_inspection_date'])
                                        ? htmlspecialchars(date('M d, Y', strtotime($b['preferred_inspection_date'])))
                                        : '<span class="is-muted">Not specified</span>' ?>
                                </div>
                            </div>
                            <div class="spec-item">
                                <span class="spec-label">Preferred Time</span>
                                <div class="spec-value">
                                    <?= !empty($b['preferred_schedule'])
                                        ? htmlspecialchars($b['preferred_schedule'])
                                        : '<span class="is-muted">Not specified</span>' ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ad-modal-section">
                        <h3 class="ad-modal-section-title">Admin Scheduling</h3>
                        <?php $bookingLocked = (strtolower(trim($b['inspection_status'] ?? '')) === 'completed'); ?>
                        <div class="ad-form-block">
                            <div class="form-group">
                                <label>
                                    Confirmed Inspection Date
                                    <small class="date-field-note">(Scheduled by admin)</small>
                                </label>
                                <input type="date" name="inspection_date"
                                       class="form-control <?= $bookingLocked ? 'locked-field' : '' ?>"
                                       value="<?= htmlspecialchars($b['inspection_date'] ?? '') ?>"
                                       <?= $bookingLocked ? 'readonly tabindex="-1"' : '' ?>>
                                <!-- REVISION: Confirmed slot is separate from the client's preference. -->
                                <label class="mt-3">Confirmed Inspection Slot</label>
                                <?php $selectedSlot = $b['confirmed_slot'] ?: bookingSlotName($b['preferred_schedule']); ?>
                                <select name="confirmed_slot" class="form-control" required <?= $bookingLocked ? 'disabled' : '' ?>>
                                    <option value="">Select AM or PM</option>
                                    <option value="AM" <?= $selectedSlot === 'AM' ? 'selected' : '' ?>>Morning (AM)</option>
                                    <option value="PM" <?= $selectedSlot === 'PM' ? 'selected' : '' ?>>Afternoon (PM)</option>
                                </select>
                                <?php if ($bookingLocked): ?>
                                    <small class="form-text" style="color:#b45309; font-weight:600;">
                                        <i class="bi bi-lock-fill"></i>
                                        Locked — the linked site inspection has been completed.
                                    </small>
                                <?php else: ?>
                                    <small class="form-text">
                                        This is the actual inspection date shown to the foreman and client.
                                        Select both a date and AM/PM slot to save. Approval reserves this schedule.
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ad-modal-footer">
                    <button type="button" class="btn btn-outline-secondary"
                            onclick="document.getElementById('editModal<?= (int)$b['id'] ?>').classList.remove('show')">
                        <?= $bookingLocked ? 'Close' : 'Cancel' ?>
                    </button>
                    <?php if (!$bookingLocked): ?>
                        <button type="submit" name="edit_booking" class="btn btn-primary">
                            Update Booking
                        </button>
                    <?php endif; ?>
                </div>
            </form>

        </div>
    </div>
<?php endforeach; ?>

<?php if (isset($_GET['edit']) && $editBooking): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('editModal<?= (int)$_GET['edit'] ?>').classList.add('show');
});
</script>
<?php endif; ?>

<script>
const productByCategory = {
    '--': ['--'],
    'tile-profile roofing': ['Banawe', 'Hacienda (Royal Mediterranean Series)', 'Valencia (Royal Mediterranean Series)', 'Tilespan Elite', 'Tilespan Maxi', 'Satori Tile'],
    'ribbed roofing': ['Twin Rib', 'EPR', 'R-Span', 'Multi R-Span', 'Corspan', 'Mega 2A Rib', 'Big V'],
    'specialty roofing': ['Retrofitter', 'Curve Roof', 'Steel Decking'],
    'ceilings': ['Spandrel'],
    'standing seam roofing': ['Super R-Span', 'Standing S-Span', 'S-Rib Standing Seam']
};

function populateProductDropdown(selectElement, categoryValue, selectedProduct) {
    selectElement.innerHTML = '';
    const products = productByCategory[categoryValue] || [];
    let matched = false;
    products.forEach(function(product) {
        const option = document.createElement('option');
        option.value = product;
        option.textContent = product;
        if (selectedProduct && product.toLowerCase() === String(selectedProduct).toLowerCase()) {
            option.selected = true;
            matched = true;
        }
        selectElement.appendChild(option);
    });
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
    categorySelect.addEventListener('change', function() {
        populateProductDropdown(productSelect, this.value, null);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[id^="editModal"]').forEach(function(modal) {
        const category = modal.querySelector('select[name="product_category"]');
        const product = modal.querySelector('select[name="roofing_product"]');
        if (category && product) {
            const currentProduct = modal.dataset.currentProduct || '';
            setupCategoryProductPair(category, product, currentProduct);
        }
    });
});
</script>

<!-- Flatpickr runtime — must load before the init block below -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>

<script>
function initializeAdminBookingCalendars() {

    const disabledByBooking = window.__disabledDatesByBooking || {};
    const preferredCounts   = window.__preferredDateCounts   || {};
    const hasFlatpickr      = (typeof flatpickr !== 'undefined');

    function keyFromDateObj(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    document.querySelectorAll('[id^="editModal"]').forEach(function (modal) {

        const input = modal.querySelector('input[name="inspection_date"]');
        const form  = modal.querySelector('form');
        if (!input || !form) return;
        
        // Locked bookings use a plain readonly date input, not Flatpickr.
        if (input.readOnly || input.classList.contains('locked-field')) return;

        // Prevent double init.
        if (input._flatpickr) return;

        const bookingId = parseInt(modal.id.replace('editModal', ''), 10);
        const disabled  = Array.isArray(disabledByBooking[bookingId])
                            ? disabledByBooking[bookingId]
                            : [];

        if (hasFlatpickr) {
            flatpickr(input, {
                dateFormat:    'Y-m-d',
                altInput:      true,
                altFormat:     'F j, Y',
                allowInput:    false,
                disable:       disabled,
                disableMobile: true,

                onDayCreate: function (dObj, dStr, fp, dayElem) {

                    if (dayElem.classList.contains('flatpickr-disabled')) return;
                    if (dayElem.classList.contains('prevMonthDay'))      return;
                    if (dayElem.classList.contains('nextMonthDay'))      return;

                    const dateObj = dayElem.dateObj;
                    const count = preferredCounts[keyFromDateObj(dateObj)];
                    if (!count) return;

                    const badge = document.createElement('span');
                    badge.className = 'fp-pref-count';
                    badge.textContent = count;
                    dayElem.appendChild(badge);

                    dayElem.title =
                        count +
                        (count === 1 ? ' person has ' : ' people have ') +
                        'this date as their preferred inspection date.';
                }
            });
        }

        form.addEventListener('submit', function (e) {
            if (disabled.includes(input.value)) {
                e.preventDefault();
                alert('Both inspection slots on that date are occupied. Please pick a different date.');
                if (hasFlatpickr && input._flatpickr) {
                    input._flatpickr.open();
                } else {
                    input.focus();
                }
            }
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeAdminBookingCalendars);
} else {
    initializeAdminBookingCalendars();
}
</script>