<?php
// verify_booking.php — confirms a guest booking's email,
// creates the client account, and hands off to set-password.
require_once 'config.php';
require_once __DIR__ . '/guest_tracking_helpers.php'; // REVISION: Optional account setup.

// ---- Company branding ----
$stmt = $pdo->query("SELECT company_name, company_subtitle, company_logo FROM system_settings ORDER BY id ASC LIMIT 1");
$company = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    $company = ['company_name' => 'Eng Roofing', 'company_subtitle' => '', 'company_logo' => ''];
}

$state   = 'error';
$title   = 'Invalid Verification Link';
$message = 'No verification token was provided.';
$actionUrl   = 'index.php';
$actionLabel = 'Back to Home';
$secondaryUrl   = '';
$secondaryLabel = '';

$token = trim($_GET['token'] ?? '');

if ($token !== '') {
    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE email_verification_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        // The booking token is gone, but the user might still have a pending
        // password_setup_token. If so, send them to finish setup.
        $stmt = $pdo->prepare("
            SELECT password_setup_token
            FROM users
            WHERE email = (
                SELECT client_email FROM bookings WHERE email_verification_token IS NULL
                ORDER BY id DESC LIMIT 1
            )
            AND password_setup_token IS NOT NULL
            AND (password_setup_expires_at IS NULL OR password_setup_expires_at > NOW())
            LIMIT 1
        ");
        // ^ That won't work — the token column is NULL on the booking at this point.
        // Simpler approach: look up the user by email is not possible either,
        // because we don't know which email they came from. So just fall through
        // to the generic error and rely on Forgot Password.
        $state = 'already';
        $title = 'Link Already Used or Expired';
        $message = 'This confirmation link has already been used. '
                 . 'If you haven\'t finished setting your password, use the "Forgot Password" '
                 . 'option on the login page to get a new link.';
        $actionUrl   = 'login.php';
        $actionLabel = 'Log In';
        $secondaryUrl = 'index.php';
        $secondaryLabel = 'Back to Home';
    } else {
        try {
            $trackingUrl = guestTrackingUrl($pdo, (int)$booking['id']);
            $pdo->beginTransaction();

            // 1. Mark the booking as verified and burn the token.
            $stmt = $pdo->prepare("
                UPDATE bookings
                SET guest_email_verified = 1,
                    email_verification_token = NULL
                WHERE id = ?
            ");
            $stmt->execute([$booking['id']]);

            // 2. Does a user already exist with this email?
            $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$booking['client_email']]);
            $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingUser) {
                // Already a user — just attach the booking.
                $stmt = $pdo->prepare("
                    UPDATE bookings
                    SET client_id = ?, is_guest = 0
                    WHERE id = ?
                ");
                $stmt->execute([$existingUser['id'], $booking['id']]);

                $pdo->commit();

                $state   = 'success';
                $title   = 'Email Confirmed';
                $message = "Thanks! Your booking request ({$booking['booking_id']}) is confirmed. "
                         . "You can track progress without logging in.";
                $actionUrl = $trackingUrl;
                $actionLabel = 'Track Booking / Project';
                $secondaryUrl = 'login.php';
                $secondaryLabel = 'Sign In (Optional)';
            } else {
                // 3. Create the user with a placeholder password + setup token.
                $setupToken = bin2hex(random_bytes(50));
                $placeholderPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("
                    INSERT INTO users
                        (username, full_name, email, password, role, phone,
                         status, join_date,
                         password_setup_token, password_setup_expires_at)
                    VALUES
                        (:username, :full_name, :email, :password, 'client', :phone,
                         'active', CURDATE(),
                         :setup_token, DATE_ADD(NOW(), INTERVAL 7 DAY))
                ");
                $stmt->execute([
                    ':username'    => $booking['client_email'],
                    ':full_name'   => $booking['client_full_name'],
                    ':email'       => $booking['client_email'],
                    ':password'    => $placeholderPassword,
                    ':phone'       => $booking['client_contact'],
                    ':setup_token' => $setupToken,
                ]);
                $newUserId = (int)$pdo->lastInsertId();

                // 4. Attach the booking to the new user.
                $stmt = $pdo->prepare("
                    UPDATE bookings
                    SET client_id = ?, is_guest = 0
                    WHERE id = ?
                ");
                $stmt->execute([$newUserId, $booking['id']]);

                $pdo->commit();

                // REVISION: Confirm email without forcing password setup.
                $state = 'success';
                $title = 'Email Confirmed';
                $message = 'Your booking is confirmed. Track progress without logging in, or optionally set up your account.';
                $actionUrl = $trackingUrl;
                $actionLabel = 'Track Booking / Project';
                $secondaryUrl = 'guest_set_password.php?token=' . urlencode($setupToken);
                $secondaryLabel = 'Set Up Account (Optional)';
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("verify_booking.php failed: " . $e->getMessage());
            $state   = 'error';
            $title   = 'Verification Failed';
            $message = 'Something went wrong while confirming your booking. Please contact us.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> – <?= htmlspecialchars($company['company_name']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, sans-serif; }
        body {
            background: #17191c; color: #eee; padding: 30px;
            display: flex; align-items: center; justify-content: center; min-height: 100vh;
            background-image: radial-gradient(circle at 50% -10%, rgba(204,0,0,.18), transparent 55%);
        }
        .card {
            max-width: 460px; width: 100%; background: #22252a;
            border: 1px solid #353a40; border-radius: 14px; padding: 40px 34px;
            text-align: center; box-shadow: 0 18px 50px rgba(0,0,0,.55);
            position: relative; overflow: hidden;
        }
        .card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, #c00, #a00, #c00);
        }
        .brand { margin-bottom: 24px; }
        .brand img { max-height: 60px; margin-bottom: 8px; }
        .brand .name { color: #eee; font-size: 16px; font-weight: bold; }
        .brand .sub { color: #9da2a8; font-size: 12px; margin-top: 2px; }
        .icon {
            width: 76px; height: 76px; margin: 0 auto 20px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 34px; font-weight: bold; line-height: 1;
        }
        .icon.success { background: rgba(76,175,120,.13); border: 1px solid rgba(76,175,120,.32); color: #75d49b; }
        .icon.already { background: rgba(213,168,60,.13); border: 1px solid rgba(213,168,60,.32); color: #e5c46b; }
        .icon.error   { background: rgba(214,58,58,.13);  border: 1px solid rgba(214,58,58,.32);  color: #ef8585; }
        h1 { font-size: 22px; margin-bottom: 10px; }
        p  { font-size: 14px; color: #9da2a8; line-height: 1.6; margin-bottom: 22px; }
        .btn {
            display: inline-block; padding: 12px 20px; border-radius: 8px;
            font-size: 14px; font-weight: bold; text-decoration: none;
            margin: 4px 0; width: 100%; text-align: center;
        }
        .btn-primary { background: #c00; color: #fff; }
        .btn-primary:hover { background: #a00; }
        .btn-ghost { background: transparent; color: #9da2a8; border: 1px solid #383d44; font-weight: 500; }
        .btn-ghost:hover { background: #2b2f35; color: #d0d3d7; }
        .foot { margin-top: 22px; padding-top: 16px; border-top: 1px solid #2d3137; font-size: 11px; color: #6f757c; }
    </style>
</head>
<body>
<div class="card">
    <div class="brand">
        <?php if (!empty($company['company_logo'])): ?>
            <img src="<?= htmlspecialchars($company['company_logo']) ?>" alt="">
        <?php endif; ?>
        <div class="name"><?= htmlspecialchars($company['company_name']) ?></div>
        <?php if (!empty($company['company_subtitle'])): ?>
            <div class="sub"><?= htmlspecialchars($company['company_subtitle']) ?></div>
        <?php endif; ?>
    </div>

    <div class="icon <?= htmlspecialchars($state) ?>">
        <?= $state === 'success' ? '&#10003;' : ($state === 'already' ? '&#33;' : '&#10005;') ?>
    </div>

    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($message) ?></p>

    <a href="<?= htmlspecialchars($actionUrl) ?>" class="btn btn-primary">
        <?= htmlspecialchars($actionLabel) ?>
    </a>
    <?php if ($secondaryUrl): ?>
        <a href="<?= htmlspecialchars($secondaryUrl) ?>" class="btn btn-ghost">
            <?= htmlspecialchars($secondaryLabel) ?>
        </a>
    <?php endif; ?>

    <div class="foot">&copy; <?= date('Y') ?> <?= htmlspecialchars($company['company_name']) ?></div>
</div>
</body>
</html>