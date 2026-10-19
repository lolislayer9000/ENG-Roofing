<?php
require_once 'config.php';

// -----------------------------------------------------------------------------
// Load company settings (for branding on the verification page)
// -----------------------------------------------------------------------------
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


// -----------------------------------------------------------------------------
// Determine verification state
// -----------------------------------------------------------------------------
$state = 'error';      // success | error | warning
$title = 'Verification Failed';
$message = 'Something went wrong.';
$show_login = false;

$token = $_GET['token'] ?? '';

if ($token === '') {

    $title = 'Invalid Verification Link';
    $message = 'No verification token was provided. Please use the link from your email.';

} else {

    $stmt = $pdo->prepare("
        SELECT *
        FROM pending_registrations
        WHERE token = ?
          AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        LIMIT 1
    ");

    $stmt->execute([$token]);
    $pending = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pending) {

        $state = 'warning';
        $title = 'Link Expired or Invalid';
        $message = 'This verification link is invalid or has expired. Please register again.';

    } else {

        // Check if the email already exists in users.
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$pending['email']]);

        if ($stmt->fetch()) {

            // Already registered — clean up the pending row.
            $stmt = $pdo->prepare("DELETE FROM pending_registrations WHERE id = ?");
            $stmt->execute([$pending['id']]);

            $state = 'warning';
            $title = 'Account Already Exists';
            $message = 'Your account is already registered. Please log in to continue.';
            $show_login = true;

        } else {

            try {

                $stmt = $pdo->prepare("
                    INSERT INTO users
                    (
                        username, full_name, email, password, role,
                        phone, address, join_date, daily_rate,
                        employee_id, status
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, 'client',
                        ?, ?, ?, NULL,
                        NULL, 'active'
                    )
                ");

                $stmt->execute([
                    $pending['email'],          // username = email
                    $pending['full_name'],
                    $pending['email'],
                    $pending['password_hash'],
                    $pending['phone'],
                    $pending['address'],
                    date('Y-m-d')               // join_date
                ]);

                // --- Backfill any guest bookings that used this email ---
                $newUserId = (int)$pdo->lastInsertId();
                
                $stmt = $pdo->prepare("
                    UPDATE bookings
                    SET client_id = ?,
                        is_guest = 0,
                        guest_email_verified = 1
                    WHERE LOWER(TRIM(client_email)) = LOWER(TRIM(?))
                      AND client_id IS NULL
                ");
                $stmt->execute([$newUserId, $pending['email']]);
                
                // Match inspections via the booking
                $stmt = $pdo->prepare("
                    UPDATE site_inspections si
                    INNER JOIN bookings b ON si.booking_id = b.booking_id
                    SET si.client_id = ?
                    WHERE LOWER(TRIM(b.client_email)) = LOWER(TRIM(?))
                      AND si.client_id IS NULL
                ");
                $stmt->execute([$newUserId, $pending['email']]);
                
                // Match quotations via the inspection
                $stmt = $pdo->prepare("
                    UPDATE quotations q
                    INNER JOIN site_inspections si ON q.inspection_id = si.id
                    INNER JOIN bookings b ON si.booking_id = b.booking_id
                    SET q.client_id = ?
                    WHERE LOWER(TRIM(b.client_email)) = LOWER(TRIM(?))
                      AND q.client_id IS NULL
                ");
                $stmt->execute([$newUserId, $pending['email']]);
                
                // Match payments via the quotation
                $stmt = $pdo->prepare("
                    UPDATE payments p
                    INNER JOIN quotations q ON p.quotation_id = q.id
                    INNER JOIN site_inspections si ON q.inspection_id = si.id
                    INNER JOIN bookings b ON si.booking_id = b.booking_id
                    SET p.client_id = ?
                    WHERE LOWER(TRIM(b.client_email)) = LOWER(TRIM(?))
                      AND p.client_id IS NULL
                ");
                $stmt->execute([$newUserId, $pending['email']]);
                // --- End backfill ---
                
                $stmt = $pdo->prepare("DELETE FROM pending_registrations WHERE id = ?");
                $stmt->execute([$pending['id']]);
                
                $state = 'success';
                $title = 'Email Verified';
                $message = 'Your account is now active. You can log in and start using the system.';
                $show_login = true;

            } catch (PDOException $e) {

                $state = 'error';
                $title = 'Activation Failed';
                $message = 'Unable to activate your account. Please contact support.';
            }
        }
    }
}


// -----------------------------------------------------------------------------
// Pick the state icon glyph
// -----------------------------------------------------------------------------
$state_icon = [
    'success' => '&#10003;',   // ✓
    'warning' => '&#33;',      // !
    'error'   => '&#10005;'    // ✕
][$state] ?? '&#10005;';

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= htmlspecialchars($title) ?>
        &middot;
        <?= htmlspecialchars($company['company_name']) ?>
    </title>

    <style>

        /* ---------------------------------------------------------
           Base
           --------------------------------------------------------- */

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }

        html, body {
            height: 100%;
        }

        body {
            background: #17191c;
            color: #eeeeee;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 24px;
            min-height: 100vh;

            /* Subtle red radial accent to echo the brand colour. */
            background-image:
                radial-gradient(
                    circle at 50% -10%,
                    rgba(204, 0, 0, .18),
                    transparent 55%
                );
        }

        /* ---------------------------------------------------------
           Card
           --------------------------------------------------------- */

        .verify-card {
            width: 100%;
            max-width: 460px;
            background: #22252a;
            border: 1px solid #353a40;
            border-radius: 14px;
            padding: 40px 34px 34px;
            text-align: center;
            position: relative;
            overflow: hidden;
            box-shadow:
                0 18px 50px rgba(0, 0, 0, .55),
                0 1px 0 rgba(255, 255, 255, .03) inset;
        }

        /* Thin red top border accent. */
        .verify-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(
                90deg,
                #c00 0%,
                #a00 50%,
                #c00 100%
            );
        }

        /* ---------------------------------------------------------
           Brand block
           --------------------------------------------------------- */

        .verify-brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            margin-bottom: 28px;
        }

        .verify-brand-logo {
            width: 72px;
            height: 72px;
            border-radius: 14px;
            background: #1c1f23;
            border: 1px solid #383d44;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .verify-brand-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .verify-brand-logo-fallback {
            font-size: 12px;
            font-weight: bold;
            color: #7d838b;
            letter-spacing: .8px;
        }

        .verify-brand-name {
            font-size: 17px;
            font-weight: bold;
            color: #eeeeee;
            letter-spacing: .3px;
        }

        .verify-brand-subtitle {
            font-size: 12px;
            color: #9da2a8;
            margin-top: -4px;
        }

        /* ---------------------------------------------------------
           Status icon
           --------------------------------------------------------- */

        .verify-status-icon {
            width: 76px;
            height: 76px;
            margin: 0 auto 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
            font-weight: bold;
            line-height: 1;
            user-select: none;
        }

        .verify-status-icon.success {
            background: rgba(76, 175, 120, .13);
            border: 1px solid rgba(76, 175, 120, .32);
            color: #75d49b;
            box-shadow: 0 0 0 6px rgba(76, 175, 120, .06);
        }

        .verify-status-icon.warning {
            background: rgba(213, 168, 60, .13);
            border: 1px solid rgba(213, 168, 60, .32);
            color: #e5c46b;
            box-shadow: 0 0 0 6px rgba(213, 168, 60, .06);
        }

        .verify-status-icon.error {
            background: rgba(214, 58, 58, .13);
            border: 1px solid rgba(214, 58, 58, .32);
            color: #ef8585;
            box-shadow: 0 0 0 6px rgba(214, 58, 58, .06);
        }

        /* ---------------------------------------------------------
           Text
           --------------------------------------------------------- */

        .verify-title {
            font-size: 22px;
            color: #f4f4f4;
            margin-bottom: 10px;
            line-height: 1.3;
        }

        .verify-message {
            font-size: 14px;
            color: #9da2a8;
            line-height: 1.6;
            margin-bottom: 28px;
            max-width: 340px;
            margin-left: auto;
            margin-right: auto;
        }

        /* ---------------------------------------------------------
           Button
           --------------------------------------------------------- */

        .verify-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .verify-btn {
            display: inline-block;
            width: 100%;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition:
                background .15s,
                transform .1s,
                box-shadow .15s;
            line-height: 1.2;
        }

        .verify-btn-primary {
            background: #c00;
            color: #fff;
            box-shadow: 0 4px 14px rgba(204, 0, 0, .28);
        }

        .verify-btn-primary:hover {
            background: #a00;
        }

        .verify-btn-primary:active {
            transform: translateY(1px);
            box-shadow: 0 2px 8px rgba(204, 0, 0, .28);
        }

        .verify-btn-secondary {
            background: transparent;
            color: #9da2a8;
            border: 1px solid #383d44;
            font-weight: 500;
        }

        .verify-btn-secondary:hover {
            background: #2b2f35;
            color: #d0d3d7;
        }

        /* ---------------------------------------------------------
           Footer
           --------------------------------------------------------- */

        .verify-footer {
            margin-top: 26px;
            padding-top: 18px;
            border-top: 1px solid #2d3137;
            font-size: 11px;
            color: #6f757c;
            letter-spacing: .3px;
        }

        /* ---------------------------------------------------------
           Responsive
           --------------------------------------------------------- */

        @media (max-width: 480px) {
            .verify-card {
                padding: 32px 22px 26px;
                border-radius: 12px;
            }
            .verify-title {
                font-size: 20px;
            }
            .verify-status-icon {
                width: 66px;
                height: 66px;
                font-size: 30px;
            }
        }

    </style>

</head>

<body>

<div class="verify-card">

    <!-- Brand -->
    <div class="verify-brand">

        <div class="verify-brand-logo">

            <?php if (!empty($company['company_logo']) && is_file(__DIR__ . '/' . ltrim($company['company_logo'], '/'))): ?>

                <img
                    src="<?= htmlspecialchars($company['company_logo']) ?>"
                    alt="<?= htmlspecialchars($company['company_name']) ?>"
                >

            <?php else: ?>

                <span class="verify-brand-logo-fallback">LOGO</span>

            <?php endif; ?>

        </div>

        <div class="verify-brand-name">
            <?= htmlspecialchars($company['company_name']) ?>
        </div>

        <?php if (!empty($company['company_subtitle'])): ?>
            <div class="verify-brand-subtitle">
                <?= htmlspecialchars($company['company_subtitle']) ?>
            </div>
        <?php endif; ?>

    </div>


    <!-- Status icon -->
    <div class="verify-status-icon <?= htmlspecialchars($state) ?>">
        <?= $state_icon ?>
    </div>


    <!-- Title + message -->
    <h1 class="verify-title">
        <?= htmlspecialchars($title) ?>
    </h1>

    <p class="verify-message">
        <?= htmlspecialchars($message) ?>
    </p>


    <!-- Actions -->
    <div class="verify-actions">

        <?php if ($show_login): ?>

            <a
                href="login.php"
                class="verify-btn verify-btn-primary"
            >Log In to Your Account</a>

        <?php else: ?>

            <a
                href="register.php"
                class="verify-btn verify-btn-primary"
            >Back to Registration</a>

        <?php endif; ?>

        <?php if ($show_login): ?>
            <a
                href="register.php"
                class="verify-btn verify-btn-secondary"
            >Register a Different Account</a>
        <?php endif; ?>

    </div>


    <!-- Footer -->
    <div class="verify-footer">
        &copy; <?= date('Y') ?>
        <?= htmlspecialchars($company['company_name']) ?>
        &middot; All rights reserved
    </div>

</div>

</body>

</html>