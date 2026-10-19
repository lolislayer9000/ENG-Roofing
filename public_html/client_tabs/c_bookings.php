<?php
// This file is included inside client.php.

// Make sure the user is logged in.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$client_id = (int)$_SESSION['user_id'];


// ---------------------------------------------------------
// Fetch the client's current profile
// ---------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT id, full_name, email, phone, address, join_date, status
    FROM users
    WHERE id = ?
      AND role = 'client'
    LIMIT 1
");
$stmt->execute([$client_id]);

$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    die('Client account not found.');
}

// Fetch dates that already have an inspection or pending booking.
$blockedDates        = getScheduledInspectionDates($pdo);
$preferredDateCounts = getPreferredDateCounts($pdo);

$activeServices   = getActiveConfigurationNames($pdo, 'service');
$activeProperties = getActiveConfigurationNames($pdo, 'property');

if (empty($activeServices))   $activeServices   = ['roof installation','roof repair','roof replacement'];
if (empty($activeProperties)) $activeProperties = ['residential','industrial','commercial','mixed-use'];


// ---------------------------------------------------------
// Submit new booking request
// ---------------------------------------------------------
if (isset($_POST['submit_booking'])) {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $service_type = trim($_POST['service_type'] ?? '');
    $property_type = trim($_POST['property_type'] ?? '');
    $project_location = trim($_POST['project_location'] ?? '');
    $preferred_inspection_date = $_POST['preferred_inspection_date'] ?? '';
    $preferred_schedule = trim($_POST['preferred_schedule'] ?? '');
    $existing_roof_condition = trim($_POST['existing_roof_condition'] ?? '');
    $product_category = trim($_POST['product_category'] ?? '');
    $roofing_product = trim($_POST['roofing_product'] ?? '');
    $project_description = trim($_POST['project_description'] ?? '');

    // -----------------------------------------------------
    // Check that the client's profile is complete
    // -----------------------------------------------------
    if (
        empty($client['full_name']) ||
        empty($client['email']) ||
        empty($client['phone'])
    ) {
        header('Location: client.php?tab=profile&msg=complete_profile');
        exit;
    }

    // Verify the phone is exactly 11 digits.
    if (!preg_match('/^\d{11}$/', $client['phone'])) {
        header('Location: client.php?tab=profile&msg=invalid_phone');
        exit;
    }

        // -----------------------------------------------------
    // Validate booking fields (except optional ones)
    // -----------------------------------------------------
    if (
        $service_type === '' ||
        $property_type === '' ||
        $project_location === '' ||
        $preferred_inspection_date === '' ||
        $preferred_schedule === '' ||
        $existing_roof_condition === '' ||
        $product_category === '' ||
        $roofing_product === ''
    ) {
        header('Location: client.php?tab=bookings&msg=required');
        exit;
    }

    // Reject Service / Property values that were deactivated by admin.
    if (
        !in_array($service_type, $activeServices, true) ||
        !in_array($property_type, $activeProperties, true)
    ) {
        header('Location: client.php?tab=bookings&msg=required');
        exit;
    }

    if (in_array($preferred_inspection_date, $blockedDates, true)) {
        header('Location: client.php?tab=bookings&msg=date_taken');
        exit;
    }


    // -----------------------------------------------------
    // Generate booking ID
    // -----------------------------------------------------
    $booking_id = generateBookingID($pdo);

    /*
     * IMPORTANT:
     *
     * We copy the client's current profile information into
     * the booking record.
     *
     * This means the booking preserves the client's information
     * as it was when the request was submitted.
     */
    $data = [
        'booking_id' => $booking_id,
        'client_id' => $client_id,
        'client_full_name' => $client['full_name'],
        'client_contact' => $client['phone'],
        'client_email' => $client['email'],
        'client_address' => trim((string)($client['address'] ?? '')),
        'service_type' => $service_type,
        'property_type' => $property_type,
        'project_location' => $project_location,
        'preferred_inspection_date' => $preferred_inspection_date,
        'preferred_schedule' => $preferred_schedule,
        'existing_roof_condition' => $existing_roof_condition,
        'product_category' => $product_category,
        'roofing_product' => $roofing_product,
        'project_description' => $project_description,
        'status' => 'pending'
    ];


    // -----------------------------------------------------
    // Create booking
    // -----------------------------------------------------
    $sql = "
        INSERT INTO bookings (
            booking_id,
            client_id,
            client_full_name,
            client_contact,
            client_email,
            client_address,
            service_type,
            property_type,
            project_location,
            preferred_inspection_date,
            preferred_schedule,
            existing_roof_condition,
            product_category,
            roofing_product,
            project_description,
            status
        )
        VALUES (
            :booking_id,
            :client_id,
            :client_full_name,
            :client_contact,
            :client_email,
            :client_address,
            :service_type,
            :property_type,
            :project_location,
            :preferred_inspection_date,
            :preferred_schedule,
            :existing_roof_condition,
            :product_category,
            :roofing_product,
            :project_description,
            :status
        )
    ";

    try {

        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);

        // Send notifications to all active admins
        try {
            $admin_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
            $admin_stmt->execute();
            $admins = $admin_stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($admins)) {
                $message = "New booking request from {$client['full_name']} (ID: $booking_id).";
                $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, message) VALUES (?, NULL, ?)");
                foreach ($admins as $admin_id) {
                    $notif_stmt->execute([$admin_id, $message]);
                }
            }
        } catch (Throwable $e) {
            error_log("Failed to send admin notifications for booking $booking_id: " . $e->getMessage());
        }

        /*
         * DO NOT create a project here.
         *
         * The request is still pending and must first be
         * approved by the admin.
         */

        header('Location: client.php?tab=bookings&msg=submitted');
        exit;

    } catch (PDOException $e) {

        error_log("Client booking insert failed: " . $e->getMessage());
        header('Location: client.php?tab=bookings&msg=error');
        exit;
    }
}


// ---------------------------------------------------------
// Fetch all bookings belonging to this client, including inspection date
// ---------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT
        b.*,
        si.inspection_date,
        si.inspector_id,
        u.full_name      AS inspector_name,
        u.phone          AS inspector_phone,
        u.profile_photo  AS inspector_photo
    FROM bookings b
    LEFT JOIN site_inspections si ON b.booking_id = si.booking_id
    LEFT JOIN users u             ON u.id = si.inspector_id
    WHERE b.client_id = ?
    ORDER BY b.created_at DESC
");
$stmt->execute([$client_id]);

$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ---------------------------------------------------------
// Messages
// ---------------------------------------------------------
$msg = '';
$msg_type = '';

if (isset($_GET['msg'])) {

    $messages = [
        'submitted' => [
            'text' => 'Booking request submitted successfully.',
            'type' => ''
        ],

        'complete_profile' => [
            'text' => 'Please complete your profile before submitting a booking request.',
            'type' => 'warning'
        ],

        'invalid_phone' => [
            'text' => 'Your profile phone number must contain exactly 11 digits.',
            'type' => 'error'
        ],

        'required' => [
            'text' => 'Please complete all required booking fields.',
            'type' => 'error'
        ],

        'invalid_area' => [
            'text' => 'Please enter a valid roof area if provided.',
            'type' => 'error'
        ],

        'invalid_quantity' => [
            'text' => 'Please enter a valid estimated quantity if provided.',
            'type' => 'error'
        ],
        
        'date_taken' => [
            'text' => 'That inspection date is already fully booked. Please choose another.',
            'type' => 'error'
        ],

        'error' => [
            'text' => 'The booking request could not be submitted.',
            'type' => 'error'
        ]
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

<div class="top-bar">
    <h2>Booking Requests</h2>
    <button type="button" class="btn btn-primary" onclick="document.getElementById('newBookingModal').classList.add('show')">
        <i class="bi bi-plus-lg me-1"></i>New Booking
    </button>
</div>

<?php if (count($bookings) === 0): ?>
    <div class="fr-card">
        <div class="fr-card-pad text-center py-5">
            <i class="bi bi-calendar-x" style="font-size: 36px; color: var(--fr-muted); opacity: .5;"></i>
            <h3 class="mt-3 mb-1" style="font-size: 17px; color: var(--fr-text);">No Booking Requests Yet</h3>
            <p class="mb-0" style="color: var(--fr-muted); font-size: 13px;">Click <strong>+ New Booking</strong> to submit your first request.</p>
        </div>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Booking ID</th>
                    <th>Service Type</th>
                    <th>Property Type</th>
                    <th>Preferred Inspection Date<span class="date-column-note">Requested by you</span></th>
                    <th>Confirmed Inspection Date<span class="date-column-note">Scheduled by Eng Roofing</span></th>
                    <th>Foreman in Charge</th>
                    <th>Roofing Product</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($bookings as $b): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($b['booking_id']) ?></strong></td>
                    <td><?= htmlspecialchars(ucfirst($b['service_type'])) ?></td>
                    <td><?= htmlspecialchars(ucfirst($b['property_type'])) ?></td>
                    <td>
                        <strong><?= date('M d, Y', strtotime($b['preferred_inspection_date'])) ?></strong><br><small class="date-subtext">Requested: <?= htmlspecialchars($b['preferred_schedule']) ?></small>
                    </td>
                    <td>
                        <?php if (!empty($b['inspection_date'])): ?>
                            <strong><?= date('M d, Y', strtotime($b['inspection_date'])) ?></strong>
                        <?php else: ?>
                            <span class="muted">Not yet confirmed</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($b['inspector_id']) && !empty($b['inspector_name'])): ?>
                            <div class="d-flex align-items-center gap-2">
                                <?php
                                $photoRel = trim((string)($b['inspector_photo'] ?? ''));
                                $photoAbs = $photoRel !== '' ? dirname(__DIR__) . '/' . ltrim($photoRel, '/') : '';
                                $hasPhoto = $photoRel !== '' && is_file($photoAbs);
                                ?>
                                <?php if ($hasPhoto): ?>
                                    <img src="<?= htmlspecialchars($photoRel) ?>"
                                         alt=""
                                         style="width: 36px; height: 36px; border-radius: 50%;
                                                object-fit: cover; flex-shrink: 0;
                                                border: 1px solid var(--fr-border);">
                                <?php else: ?>
                                    <div style="width: 36px; height: 36px; border-radius: 50%;
                                                background: var(--fr-red); color: #fff;
                                                display: flex; align-items: center; justify-content: center;
                                                font-weight: 700; flex-shrink: 0; font-size: 14px;">
                                        <?= htmlspecialchars(strtoupper(mb_substr($b['inspector_name'], 0, 1))) ?>
                                    </div>
                                <?php endif; ?>
                                <div style="min-width: 0;">
                                    <div style="font-weight: 600; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?= htmlspecialchars($b['inspector_name']) ?>
                                    </div>
                                    <div style="font-size: 11.5px; color: var(--fr-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?php if (!empty($b['inspector_phone'])): ?>
                                            <i class="bi bi-telephone" style="font-size: 10px;"></i>
                                            <?= htmlspecialchars($b['inspector_phone']) ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <span class="muted" style="font-style: italic; font-size: 12.5px;">Not yet assigned</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= htmlspecialchars($b['roofing_product']) ?>
                        <br><small class="muted"><?= htmlspecialchars($b['product_category']) ?></small>
                    </td>
                    <td>
                        <span class="fr-badge <?= htmlspecialchars($b['status']) ?>"><?= htmlspecialchars(ucfirst($b['status'])) ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- New Booking Modal -->
<div id="newBookingModal" class="ad-modal">
    <div class="ad-modal-content">
        <button type="button" class="close" onclick="document.getElementById('newBookingModal').classList.remove('show')">&times;</button>
        <h2>New Booking Request</h2>
        <p style="color: var(--fr-muted); font-size: 13px;">Submit the details of your roofing project and preferred inspection schedule.</p>

        <form method="POST" action="client.php?tab=bookings">
            <?= csrf_field() ?>

            <div class="form-row">
                <div class="form-group">
                    <label>Service Type</label>
                    <select name="service_type" class="form-select" required>
                        <?php foreach ($activeServices as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>">
                                <?= htmlspecialchars(ucwords($s)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Property Type</label>
                    <select name="property_type" class="form-select" required>
                        <?php foreach ($activeProperties as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>">
                                <?= htmlspecialchars(ucwords($p)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Project Location</label>
                <input type="text" name="project_location" id="projectLocationInput" class="form-control" required>
                <?php if (!empty($client['address'])): ?>
                    <small class="form-text">
                        <a href="#" id="useHomeAddressLink" style="color: var(--fr-red); text-decoration: none;">
                            <i class="bi bi-geo-alt"></i> Use my home address
                        </a>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Preferred Inspection Date<small class="date-field-note">(Your requested date)</small></label>
                    <input type="date" name="preferred_inspection_date" id="cbPreferredDate"
                           class="form-control" min="<?= date('Y-m-d') ?>" required>
                    <div id="cbPreferredDateError" class="form-text" style="color:#b91c1c;display:none;font-weight:600;">
                        That date is already booked. Please choose another.
                    </div>
                </div>
                <div class="form-group">
                    <label>Preferred Schedule<small class="date-field-note">(Your requested time)</small></label>
                    <select name="preferred_schedule" class="form-select" required>
                        <option value="Morning (8:00AM-12:00PM)">Morning (8:00AM-12:00PM)</option>
                        <option value="Afternoon (1:00PM-5:00PM)">Afternoon (1:00PM-5:00PM)</option>
                        <option value="Full Day (8:00AM-5:00PM)">Full Day (8:00AM-5:00PM)</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Existing Roof Condition</label>
                <select name="existing_roof_condition" class="form-select" required>
                    <option value="new construction">New Construction</option>
                    <option value="good condition">Good Condition</option>
                    <option value="fair condition">Fair Condition</option>
                    <option value="partially damage">Partially Damage</option>
                    <option value="severely damage">Severely Damage</option>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Product Category</label>
                    <select name="product_category" class="form-select" required>
                        <option value="--">--</option>
                        <option value="ribbed roofing">Ribbed / Corrugated Profiles</option>
                        <option value="tile-profile roofing">Tile Profile / Elegant Series</option>
                        <option value="standing seam roofing">Long Span &amp; Standing Seam Systems</option>
                        <option value="specialty roofing">Specialized &amp; Structural Roofing</option>
                        <option value="ceilings">Ceilings / Architectural Panels</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Roofing Product / Profile</label>
                    <select name="roofing_product" class="form-select" required></select>
                </div>
            </div>

            <div class="form-group">
                <label>Project Description</label>
                <textarea name="project_description" class="form-control" rows="3" placeholder="Describe the roofing project, requirements, existing issues, or other relevant details."></textarea>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('newBookingModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="submit_booking" class="btn btn-primary">Submit Booking Request</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     Flatpickr — used by the Preferred Inspection Date field
     ========================================================= -->
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">

<style>
    .flatpickr-calendar.arrowTop::before,
    .flatpickr-calendar.arrowTop::after {
        border-bottom-color: #fff;
    }

    .flatpickr-day.selected,
    .flatpickr-day.selected:hover,
    .flatpickr-day.selected:focus {
        background: var(--fr-red);
        border-color: var(--fr-red);
        color: #fff;
    }

    .flatpickr-day.today {
        border-color: var(--fr-red);
    }

    .flatpickr-day.today:hover,
    .flatpickr-day.today:focus {
        background: var(--fr-red);
        border-color: var(--fr-red);
        color: #fff;
    }

    .flatpickr-day.flatpickr-disabled,
    .flatpickr-day.flatpickr-disabled:hover {
        color: #cbd5e1;
        background: #f9fafb;
        text-decoration: line-through;
        cursor: not-allowed;
    }

    .flatpickr-day.prevMonthDay,
    .flatpickr-day.nextMonthDay {
        color: #cbd5e1;
    }

    .flatpickr-day {
        position: relative;
    }

    .fp-pref-count {
        position: absolute;
        top: 1px;
        right: 1px;
        background: #f59e0b;
        color: #fff;
        font-size: 9px;
        font-weight: 700;
        line-height: 1;
        padding: 2px 4px;
        border-radius: 8px;
        min-width: 12px;
        text-align: center;
        pointer-events: none;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>

<!-- =========================================================
     JAVASCRIPT for dynamic product dropdowns
     ========================================================= -->
<script>
// Map roofing categories to product lists
const productByCategory = {
    'tile-profile roofing': ['Banawe', 'Hacienda (Royal Mediterranean Series)', 'Valencia (Royal Mediterranean Series)', 'Tilespan Elite', 'Tilespan Maxi', 'Satori Tile'],
    'ribbed roofing': ['Twin Rib', 'EPR', 'R-Span', 'Multi R-Span', 'Corspan', 'Mega 2A Rib', 'Big V'],
    'specialty roofing': ['Retrofitter', 'Curve Roof', 'Steel Decking'],
    'ceilings': ['Spandrel'],
    'standing seam roofing': ['Super R-Span', 'Standing S-Span', 'S-Rib Standing Seam']
};

function populateProductDropdown(selectElement, categoryValue, selectedProduct) {
    selectElement.innerHTML = '';
    const products = productByCategory[categoryValue] || [];
    products.forEach(function(product) {
        const option = document.createElement('option');
        option.value = product;
        option.textContent = product;
        if (product === selectedProduct) {
            option.selected = true;
        }
        selectElement.appendChild(option);
    });
    if (products.length === 0) {
        const option = document.createElement('option');
        option.value = 'No product available';
        option.textContent = 'No Products Available for this Category';
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

function initializeBookingPage() {
    const modal = document.getElementById('newBookingModal');

    if (modal) {
        const category = modal.querySelector('select[name="product_category"]');
        const product = modal.querySelector('select[name="roofing_product"]');

        if (category && product) {
            setupCategoryProductPair(category, product, null);
        }
    }

    const homeAddress = <?= json_encode($client['address'] ?? '') ?>;
    const projectInput = document.getElementById('projectLocationInput');
    const useAddrLink = document.getElementById('useHomeAddressLink');

    if (useAddrLink && projectInput && homeAddress) {
        useAddrLink.addEventListener('click', function (e) {
            e.preventDefault();
            projectInput.value = homeAddress;
            projectInput.focus();
        });
    }

    const blockedDates = <?= json_encode(
        $blockedDates,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ) ?>;

    const preferredCounts = <?= json_encode(
        $preferredDateCounts,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ) ?>;

    const preferredDate = document.getElementById('cbPreferredDate');
    const dateErrorEl = document.getElementById('cbPreferredDateError');
    const bookingForm = preferredDate
        ? preferredDate.closest('form')
        : null;

    function keyFromDateObj(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');

        return `${y}-${m}-${day}`;
    }

    if (preferredDate && typeof flatpickr !== 'undefined') {

        // Prevent initializing Flatpickr more than once
        if (!preferredDate._flatpickr) {

            flatpickr(preferredDate, {
                minDate: "today",
                dateFormat: "Y-m-d",
                altInput: true,
                altFormat: "F j, Y",
                allowInput: false,
                disable: blockedDates,
                disableMobile: true,

                onDayCreate: function (dObj, dStr, fp, dayElem) {

                    if (dayElem.classList.contains('flatpickr-disabled')) {
                        return;
                    }
                
                    if (dayElem.classList.contains('prevMonthDay')) {
                        return;
                    }
                
                    if (dayElem.classList.contains('nextMonthDay')) {
                        return;
                    }
                
                    const dateObj = dayElem.dateObj;
                
                    const count =
                        preferredCounts[keyFromDateObj(dateObj)];
                
                    if (!count) {
                        return;
                    }
                
                    const badge = document.createElement('span');
                    badge.className = 'fp-pref-count';
                    badge.textContent = count;
                
                    dayElem.appendChild(badge);
                
                    dayElem.title =
                        count +
                        (count === 1
                            ? ' person has '
                            : ' people have ') +
                        'this date as their preferred inspection date.';
                }
            });
        }

        if (bookingForm && dateErrorEl) {
            bookingForm.addEventListener('submit', function (e) {

                if (blockedDates.includes(preferredDate.value)) {
                    e.preventDefault();

                    dateErrorEl.style.display = 'block';
                    preferredDate.focus();
                }
            });
        }

    } else {
        console.error(
            'Flatpickr failed to load. typeof flatpickr =',
            typeof flatpickr
        );
    }
}

// Works whether DOMContentLoaded has already happened or not.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeBookingPage);
} else {
    initializeBookingPage();
}
</script>