<?php
// guest_set_password.php — one-time password setup for accounts
// created by the guest booking verification flow.
require_once 'config.php';
// Intentionally public — auth is the token in the URL.

$stmt = $pdo->query("SELECT company_name, company_subtitle, company_logo FROM system_settings ORDER BY id ASC LIMIT 1");
$company = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    $company = ['company_name' => 'Eng Roofing', 'company_subtitle' => '', 'company_logo' => ''];
}

$errors = [];
$token  = trim($_GET['token'] ?? $_POST['token'] ?? '');

$user = null;
if ($token !== '') {
    $stmt = $pdo->prepare("
        SELECT id, full_name, email
        FROM users
        WHERE password_setup_token = ?
          AND (password_setup_expires_at IS NULL OR password_setup_expires_at > NOW())
          AND role = 'client'
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ---- POST: save the new password ----
if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (strlen($password) < 6)  $errors[] = 'Password must be at least 6 characters.';
        if ($password !== $confirm) $errors[] = 'Passwords do not match.';

        if (empty($errors)) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                UPDATE users
                SET password = ?,
                    password_setup_token = NULL,
                    password_setup_expires_at = NULL
                WHERE id = ?
            ");
            $stmt->execute([$hashed, $user['id']]);

            // Auto-login.
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['email'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = 'client';

            header('Location: client.php?tab=bookings&msg=welcome');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Password – <?= htmlspecialchars($company['company_name']) ?></title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif}
        body{background:#1a1a1a;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:20px}
        .auth-container{background:#fff;padding:36px 40px;border-radius:10px;width:100%;max-width:430px;box-shadow:0 0 20px rgba(0,0,0,.5)}
        .logo{display:block;width:85px;height:85px;object-fit:contain;margin:0 auto 14px}
        .brand{text-align:center;color:#c00;font-size:25px;font-weight:bold}
        .subtitle{text-align:center;color:#666;font-size:14px;margin-top:4px;margin-bottom:22px}
        .heading{text-align:center;font-size:20px;margin-bottom:12px;color:#333}
        .note{background:#f5f5f5;border:1px solid #eee;border-radius:6px;padding:12px 14px;font-size:12.5px;color:#555;margin-bottom:18px;line-height:1.5}
        .form-group{margin-bottom:14px}
        .form-group label{display:block;margin-bottom:5px;font-weight:bold;color:#555}
        .form-group input{width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:5px;font-size:14px}
        .form-group input:focus{outline:none;border-color:#c00}
        .btn{width:100%;padding:12px;background:#c00;color:#fff;border:none;border-radius:5px;font-size:15px;cursor:pointer;font-weight:bold}
        .btn:hover{background:#a00}
        .error{color:#721c24;background:#f8d7da;border:1px solid #f5c6cb;padding:10px;border-radius:5px;margin-bottom:14px;font-size:13px}
        .error ul{margin:0;padding-left:18px}
        .footer{text-align:center;margin-top:18px;color:#888;font-size:12px}
        .footer a{color:#c00;text-decoration:none;font-weight:bold}
    </style>
</head>
<body>
<div class="auth-container">

    <?php if (!empty($company['company_logo'])): ?>
        <img class="logo" src="<?= htmlspecialchars($company['company_logo']) ?>" alt="">
    <?php endif; ?>
    <div class="brand"><?= htmlspecialchars($company['company_name']) ?></div>
    <?php if (!empty($company['company_subtitle'])): ?>
        <div class="subtitle"><?= htmlspecialchars($company['company_subtitle']) ?></div>
    <?php endif; ?>

    <?php if (!$user): ?>
        <div class="heading">Link Expired or Invalid</div>
        <p style="color:#666;font-size:13.5px;line-height:1.6;text-align:center;">
            This password setup link is no longer valid. If you need to reset your password,
            use the "Forgot Password" option on the login page.
        </p>
        <div style="text-align:center;margin-top:20px;">
            <a href="login.php" class="btn" style="display:inline-block;text-decoration:none;width:auto;padding:11px 30px;">
                Go to Log In
            </a>
        </div>

    <?php else: ?>
        <div class="heading">Set Your Password</div>
        <div class="note">
            Welcome, <strong><?= htmlspecialchars($user['full_name']) ?></strong>.<br>
            Your email <strong><?= htmlspecialchars($user['email']) ?></strong> is confirmed.
            Just set a password and you're in.
        </div>

        <?php if (!empty($errors)): ?>
            <div class="error">
                <ul>
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="guest_set_password.php">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <div class="form-group">
                <label for="password">New Password</label>
                <input id="password" type="password" name="password" required minlength="6" autocomplete="new-password" autofocus>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input id="confirm_password" type="password" name="confirm_password" required minlength="6" autocomplete="new-password">
            </div>
            <button class="btn" type="submit">Save Password &amp; Log In</button>
        </form>
    <?php endif; ?>

    <div class="footer">
        &copy; <?= date('Y') ?> <?= htmlspecialchars($company['company_name']) ?>
    </div>
</div>
</body>
</html>