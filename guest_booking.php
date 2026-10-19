<?php
// guest_booking.php — public booking form for visitors without an account.
require_once 'config.php';
require_once __DIR__ . '/guest_tracking_helpers.php'; // REVISION: Private tracking email link.
// NOTE: no requireLogin() here — this page is intentionally public.
// ---- Fetch active configuration options ----
$activeServices   = getActiveConfigurationNames($pdo, 'service');
$activeProperties = getActiveConfigurationNames($pdo, 'property');

if (empty($activeServices)) {
    $activeServices = ['roof installation', 'roof repair', 'roof replacement'];
}

if (empty($activeProperties)) {
    $activeProperties = ['residential', 'industrial', 'commercial', 'mixed-use'];
}

// ---- Company branding ----
$stmt = $pdo->query("SELECT * FROM system_settings ORDER BY id ASC LIMIT 1");
$company = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    $company = [
        'company_name'     => 'Eng Roofing',
        'company_subtitle' => 'Supply and Installation',
        'company_logo'     => '',
        'company_email'    => '',
    ];
}

$errors       = [];
$showSuccess  = false;

// Hard-blocked: dates with a confirmed inspection already scheduled.
$blockedDates = getScheduledInspectionDates($pdo);

// Informational: how many pending bookings want each date.
$preferredDateCounts = getPreferredDateCounts($pdo);

if (!isset($_SESSION['guest_booking_last_submit'])) {
    $_SESSION['guest_booking_last_submit'] = 0;
}

// Stamp when the form was rendered, so we can enforce a
// minimum fill time on POST. Bots submit in < 2 seconds.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION['guest_booking_form_loaded_at'] = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $loaded_at = (int)($_SESSION['guest_booking_form_loaded_at'] ?? 0);
    $fill_time = time() - $loaded_at;

    // Honeypot — renamed to something no password manager targets.
    // (See the input in the HTML below.)
    $honeypot_filled = !empty($_POST['gb_note_field_2']);

    // Anything submitted faster than 3 seconds after the form loaded
    // is almost certainly a bot.
    $submitted_too_fast = ($fill_time < 1);

    if ($honeypot_filled || $submitted_too_fast) {
        // TEMPORARY DEBUG — remove this whole block once diagnosed.
        $errors[] = sprintf(
            'DEBUG → honeypot=%s | fill_time=%ds | loaded_at=%d | now=%d',
            $honeypot_filled ? 'FILLED' : 'empty',
            $fill_time,
            $loaded_at,
            time()
        );
        error_log('guest_booking POST keys: ' . implode(', ', array_keys($_POST)));
        error_log('gb_note_field_2 value: [' . ($_POST['gb_note_field_2'] ?? 'MISSING') . ']');
    }
    elseif (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    }
    elseif (time() - (int)$_SESSION['guest_booking_last_submit'] < 30) {
        $errors[] = 'Please wait a moment before submitting another booking.';
    }
    else {

        $full_name    = trim($_POST['full_name'] ?? '');
        $email        = strtolower(trim($_POST['email'] ?? ''));
        $phone        = trim($_POST['phone'] ?? '');

        $service_type             = $_POST['service_type'] ?? '';
        $property_type            = $_POST['property_type'] ?? '';
        $project_location         = trim($_POST['project_location'] ?? '');
        $preferred_inspection_date= $_POST['preferred_inspection_date'] ?? '';
        $preferred_schedule       = $_POST['preferred_schedule'] ?? '';
        $existing_roof_condition = trim($_POST['existing_roof_condition'] ?? '');
        $project_description      = trim($_POST['project_description'] ?? '');

        // ---------- Validation ----------
        if ($full_name === '')                            $errors[] = 'Please enter your full name.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))   $errors[] = 'Please enter a valid email address.';
        if (!preg_match('/^09\d{9}$/', $phone))           $errors[] = 'Phone must be exactly 11 digits, starting with 09.';
        if ($project_location === '')                     $errors[] = 'Please enter the project location.';

        // ---- Fetch active configuration options ----
        $activeServices    = getActiveConfigurationNames($pdo, 'service');
        $activeProperties  = getActiveConfigurationNames($pdo, 'property');

        if (empty($activeServices))   $activeServices   = ['roof installation','roof repair','roof replacement'];
        if (empty($activeProperties)) $activeProperties = ['residential','industrial','commercial','mixed-use'];

        if (!in_array($existing_roof_condition, [
            'new construction',
            'good condition',
            'fair condition',
            'partially damage',
            'severely damage',
        ], true))
            $errors[] = 'Please select a valid existing roof condition.';

        $today = date('Y-m-d');
        if ($preferred_inspection_date === '' || $preferred_inspection_date < $today)
            $errors[] = 'Preferred inspection date must be today or later.';
            
        if (in_array($preferred_inspection_date, $blockedDates, true)) {
            $errors[] = 'That inspection date is already fully booked. Please choose another.';
        }

        if (empty($errors)) {

            // Does a user already exist with this email?
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $existingUserId = $stmt->fetchColumn();

            $booking_id = generateBookingID($pdo);
            $token      = bin2hex(random_bytes(50));

            try {
                $pdo->beginTransaction();
            
                $isGuest  = $existingUserId ? 0 : 1;
                $verified = $existingUserId ? 1 : 0;
            
                $stmt = $pdo->prepare("
                    INSERT INTO bookings (
                        booking_id, client_id, is_guest, guest_email_verified,
                        email_verification_token, email_verification_sent_at,
                        client_full_name, client_contact, client_email, client_address,
                        service_type, property_type, project_location,
                        preferred_inspection_date, preferred_schedule,
                        existing_roof_condition, product_category, roofing_product,
                        project_description, status
                    ) VALUES (
                        :booking_id, :client_id, :is_guest, :verified,
                        :token, NOW(),
                        :full_name, :phone, :email, :address,
                        :service_type, :property_type, :project_location,
                        :pref_date, :pref_schedule,
                        :roof_cond, :prod_cat, :prod,
                        :desc, 'pending'
                    )
                ");
            
                $stmt->execute([
                    ':booking_id'    => $booking_id,
                    ':client_id'     => $existingUserId ?: null,
                    ':is_guest'      => $isGuest,
                    ':verified'      => $verified,
                    ':token'         => $existingUserId ? null : $token,
                    ':full_name'     => $full_name,
                    ':phone'         => $phone,
                    ':email'         => $email,
                    ':address'       => '',
                    ':service_type'  => $service_type,
                    ':property_type' => $property_type,
                    ':project_location' => $project_location,
                    ':pref_date'     => $preferred_inspection_date,
                    ':pref_schedule' => $preferred_schedule,
                    ':roof_cond'     => $existing_roof_condition,
                    ':prod_cat'      => '',
                    ':prod'          => '',
                    ':desc'          => $project_description,
                ]);
            
                $savedBookingId = (int)$pdo->lastInsertId();
                guestTrackingToken($pdo, $savedBookingId); // REVISION: Persist link with booking.
                $pdo->commit();
            
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("Guest booking insert failed: " . $e->getMessage());
                $errors[] = 'We could not save your booking. Please try again.';
            }
            
            if (empty($errors)) {
                try {
                    $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
            
                    if (!empty($admins)) {
                        $notif = $pdo->prepare("INSERT INTO notifications (user_id, project_id, message) VALUES (?, NULL, ?)");
                        $msg = "New " . ($isGuest ? "guest " : "") . "booking request $booking_id from $full_name.";
            
                        foreach ($admins as $a) {
                            $notif->execute([$a, $msg]);
                        }
                    }
                } catch (Throwable $e) {
                    error_log("Guest booking notification failed for $booking_id: " . $e->getMessage());
                }
            }

            if (empty($errors)) {
                $base_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://')
                          . $_SERVER['HTTP_HOST']
                          . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                $fromEmail = !empty($company['company_email']) ? $company['company_email'] : 'no-reply@engroofing.com';
                $fromName  = $company['company_name'];

                if ($existingUserId) {
                    // Existing client — skip verification, tell them to log in.
                    $subject = 'Booking received – ' . $booking_id;
                    $body = "Hi $full_name,\n\n"
                          . "We received your booking request ($booking_id).\n"
                          . "Log in to view it: $base_url/login.php\n\n"
                          . "— $fromName";
                } else {
                    // Guest — send the verification link.
                    $verifyUrl = $base_url . '/verify_booking.php?token=' . urlencode($token);
                    $subject = 'Confirm your booking request – ' . $booking_id;
                    $body = "Hi $full_name,\n\n"
                          . "Thanks for your booking request.\n\n"
                          . "Click this link to confirm your email (account setup is optional):\n"
                          . "$verifyUrl\n\n"
                          . "Booking ID: $booking_id\n\n"
                          . "The link expires in 7 days. If you didn't submit this request, "
                          . "you can safely ignore this email.\n\n"
                          . "— $fromName";
                }

                // REVISION: Tracking does not require login or password setup.
                $body .= "\n\nTrack your booking/project without logging in:\n"
                       . guestTrackingUrl($pdo, $savedBookingId)
                       . "\nKeep this private link; anyone with it can view this booking's progress.";
                sendMail($email, $full_name, $subject, $body, false, $fromEmail, $fromName);

                $_SESSION['guest_booking_last_submit'] = time();
                $showSuccess = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($company['company_name']) ?> – Book a Service</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, sans-serif; }
        body { background: #1a1a1a; padding: 30px 20px; min-height: 100vh; color: #333; }
        .wrap { max-width: 720px; margin: 0 auto; background: #fff; border-radius: 10px; padding: 32px 36px; box-shadow: 0 0 20px rgba(0,0,0,.5); }
        .brand { text-align: center; margin-bottom: 22px; }
        .brand img { max-height: 70px; margin-bottom: 8px; }
        .brand .name { color: #c00; font-size: 22px; font-weight: bold; }
        .brand .sub { color: #666; font-size: 13px; margin-top: 4px; }
        h1 { font-size: 20px; text-align: center; margin-bottom: 8px; }
        p.lead { text-align: center; color: #666; font-size: 13.5px; margin-bottom: 22px; line-height: 1.5; }
        .msg-error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; }
        .msg-error ul { margin: 0; padding-left: 18px; }
        .msg-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 16px 20px; border-radius: 6px; font-size: 14px; line-height: 1.6; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .row.one { grid-template-columns: 1fr; }
        .field { margin-bottom: 14px; }
        label { display: block; font-weight: bold; color: #555; font-size: 13px; margin-bottom: 5px; }
        input[type=text], input[type=email], input[type=tel], input[type=date], select, textarea {
            width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 5px; font-size: 14px;
        }
        textarea { min-height: 90px; resize: vertical; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #c00; }
        .hint { font-size: 11.5px; color: #888; margin-top: 4px; }
        .hp {
            position: absolute !important;
            left: -9999px !important;
            width: 1px !important;
            height: 1px !important;
            opacity: 0 !important;
            pointer-events: none !important;
        }
        .actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 12px; }
        .btn { padding: 11px 22px; border: none; border-radius: 5px; font-size: 14px; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #c00; color: #fff; }
        .btn-primary:hover { background: #a00; }
        .btn-secondary { background: #eee; color: #333; }
        .btn-secondary:hover { background: #ddd; }
        .foot { text-align: center; color: #888; font-size: 12px; margin-top: 20px; }
        .foot a { color: #c00; text-decoration: none; }
        @media (max-width: 600px) { .row { grid-template-columns: 1fr; } .wrap { padding: 24px 20px; } }

        /* Flatpickr theme overrides — guest page has its own red (#c00). */
        .flatpickr-day.selected,
        .flatpickr-day.selected:hover,
        .flatpickr-day.selected:focus {
            background: #c00;
            border-color: #c00;
            color: #fff;
        }
        .flatpickr-day.today { border-color: #c00; }
        .flatpickr-day.today:hover,
        .flatpickr-day.today:focus {
            background: #c00;
            border-color: #c00;
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
        .flatpickr-day.nextMonthDay { color: #cbd5e1; }
        /* Number badge on days with pending booking preferences. */
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

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
</head>
<body>
<div class="wrap">

    <div class="brand">
        <?php if (!empty($company['company_logo'])): ?>
            <img src="<?= htmlspecialchars($company['company_logo']) ?>" alt="">
        <?php endif; ?>
        <div class="name"><?= htmlspecialchars($company['company_name']) ?></div>
        <?php if (!empty($company['company_subtitle'])): ?>
            <div class="sub"><?= htmlspecialchars($company['company_subtitle']) ?></div>
        <?php endif; ?>
    </div>

    <?php if ($showSuccess): ?>
        <div class="msg-success">
            <strong>Almost done!</strong><br>
            We've sent a confirmation link to your email. Click it to verify your email and set up
            your account — that's all you need to do. If you don't see the email within a few
            minutes, check your spam folder.
        </div>
        <div class="actions" style="justify-content:center;margin-top:22px;">
            <a href="index.php" class="btn btn-secondary">Back to Home</a>
        </div>

    <?php else: ?>

        <h1>Book a Service</h1>
        <p class="lead">
            No account needed. Fill this out and we'll email you a confirmation link.<br>
            After we approve your request, you'll be invited to register so you can track it.
        </p>

        <?php if (!empty($errors)): ?>
            <div class="msg-error">
                <ul>
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="guest_booking.php" novalidate>
            <?= csrf_field() ?>

            <div class="row">
                <div class="field">
                    <label>Full Name</label>
                    <input type="text" name="full_name" required value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Email Address</label>
                    <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
            </div>

            <div class="row one">
                <div class="field">
                    <label>Phone Number</label>
                    <input type="tel" name="phone" required maxlength="11" pattern="09[0-9]{9}"
                           placeholder="09xxxxxxxxx" inputmode="numeric"
                           oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11);"
                           value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                    <div class="hint">11 digits, starts with 09.</div>
                </div>
            </div>

            <div class="row">
                <div class="field">
                    <label>Service Type</label>
                    <select name="service_type" required>
                        <?php foreach ($activeServices as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>" <?= ($_POST['service_type'] ?? '') === $s ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucwords($s)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Property Type -->
                <div class="field">
                    <label>Property Type</label>
                    <select name="property_type" required>
                        <?php foreach ($activeProperties as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>" <?= ($_POST['property_type'] ?? '') === $p ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucwords($p)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label>Project Location</label>
                <input type="text" name="project_location" required value="<?= htmlspecialchars($_POST['project_location'] ?? '') ?>">
            </div>

            <div class="row">
                <div class="field">
                    <label>Preferred Inspection Date</label>
                    <input type="date" name="preferred_inspection_date" id="gbPreferredDate" required
                           min="<?= date('Y-m-d') ?>"
                           value="<?= htmlspecialchars($_POST['preferred_inspection_date'] ?? '') ?>">
                    <div id="gbPreferredDateError" class="hint" style="color:#b91c1c;display:none;font-weight:600;">
                        That date is already booked. Please choose another.
                    </div>
                </div>
                <div class="field">
                    <label>Preferred Schedule</label>
                    <select name="preferred_schedule" required>
                        <?php foreach (['Morning (8:00AM-12:00PM)','Afternoon (1:00PM-5:00PM)','Full Day (8:00AM-5:00PM)'] as $s): ?>
                            <option value="<?= $s ?>" <?= ($_POST['preferred_schedule'] ?? '') === $s ? 'selected' : '' ?>>
                                <?= $s ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label>Existing Roof Condition</label>
                <select name="existing_roof_condition" required>
                    <?php foreach ([
                        'new construction' => 'New Construction',
                        'good condition' => 'Good Condition',
                        'fair condition' => 'Fair Condition',
                        'partially damage' => 'Partially Damage',
                        'severely damage' => 'Severely Damage',
                    ] as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value) ?>"
                            <?= ($_POST['existing_roof_condition'] ?? '') === $value ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Project Description</label>
                <textarea name="project_description" placeholder="Optional — describe the project, existing issues, or anything else we should know."><?= htmlspecialchars($_POST['project_description'] ?? '') ?></textarea>
            </div>
            
            <!--
            Honeypot. Password managers key off common names like
            "website", "url", "address", so we use something they
            won't recognize. Do not rename this without updating
            the POST handler above.
            -->
            <input type="text"
               name="gb_note_field_2"
               class="hp"
               tabindex="-1"
               autocomplete="new-password"
               readonly
               aria-hidden="true">

            <div class="actions">
                <a href="index.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Submit Booking Request</button>
            </div>
        </form>

    <?php endif; ?>

    <div class="foot">
        Already have an account? <a href="login.php">Sign in</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script>

function initializeGuestBookingPage() {

    const blockedDates = <?= json_encode(
        $blockedDates,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ) ?>;

    const preferredCounts = <?= json_encode(
        $preferredDateCounts,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ) ?>;

    const preferredDate = document.getElementById('gbPreferredDate');
    const dateErrorEl   = document.getElementById('gbPreferredDateError');
    const form          = preferredDate ? preferredDate.closest('form') : null;

    function keyFromDateObj(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    if (preferredDate && typeof flatpickr !== 'undefined') {

        // Prevent initializing Flatpickr more than once.
        if (!preferredDate._flatpickr) {
            flatpickr(preferredDate, {
                minDate:    "today",
                dateFormat: "Y-m-d",
                altInput:   true,
                altFormat:  "F j, Y",
                allowInput: false,
                disable:    blockedDates,
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

        if (form && dateErrorEl) {
            form.addEventListener('submit', function (e) {
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

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeGuestBookingPage);
} else {
    initializeGuestBookingPage();
}
</script>
</body>
</html>