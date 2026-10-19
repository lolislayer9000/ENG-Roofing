<?php
// forgot_password.php
require_once 'config.php';

// Load company settings
$stmt = $pdo->query("SELECT * FROM system_settings ORDER BY id ASC LIMIT 1");
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    $company = [
        'company_name' => 'Eng Roofing',
        'company_subtitle' => 'Supply and Installation',
        'company_logo' => ''
    ];
}

$step = 'form'; // 'form' or 'sent'
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = trim($_POST['email']);

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expiry = ? WHERE id = ?");
            $stmt->execute([$token, $expiry, $user['id']]);

            // ---- Dynamic base URL ----
            $base_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            $resetLink = $base_url . '/reset_password.php?token=' . urlencode($token);

            $subject = "Password Reset Request – " . $company['company_name'];
            $message = "
                <p>Hello " . htmlspecialchars($user['full_name']) . ",</p>
                <p>You requested to reset your password. Click the link below to set a new password:</p>
                <p><a href='$resetLink'>$resetLink</a></p>
                <p>This link will expire in 1 hour.</p>
                <p>If you did not request this, ignore this email.</p>
            ";

            // Use unified sendMail – HTML email
            $fromEmail = $company['company_email'] ?? 'no-reply@engroofing.com';
            $fromName = $company['company_name'] ?? 'ENG Roofing';
            $mailSent = sendMail($email, $user['full_name'], $subject, $message, true, $fromEmail, $fromName);

            if ($mailSent) {
                $step = 'sent';
            } else {
                $error = 'We could not send the reset email. Please try again later.';
                // PHPMailer errors are logged in config.php
            }
        } else {
            $step = 'sent'; // pretend sent for security
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif}
        body{background:#1a1a1a;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:20px}
        .auth-container{background:#fff;padding:36px 40px;border-radius:10px;width:100%;max-width:430px;box-shadow:0 0 20px rgba(0,0,0,.5)}
        .heading{text-align:center;font-size:20px;margin-bottom:18px;color:#333}
        .form-group{margin-bottom:14px}
        .form-group label{display:block;margin-bottom:5px;font-weight:bold;color:#555}
        .form-group input{width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:5px;font-size:14px}
        .btn{width:100%;padding:12px;background:#c00;color:#fff;border:none;border-radius:5px;font-size:15px;cursor:pointer}
        .btn:hover{background:#a00}
        .error{color:#721c24;background:#f8d7da;border:1px solid #f5c6cb;padding:10px;border-radius:5px;margin-bottom:14px;font-size:13px}
        .success{color:#155724;background:#d4edda;border:1px solid #c3e6cb;padding:10px;border-radius:5px;margin-bottom:14px;font-size:13px}
        .switch{text-align:center;margin-top:16px;font-size:13px}
        .switch a{color:#c00;text-decoration:none;font-weight:bold}
        .switch a:hover{text-decoration:underline}
    </style>
</head>
<body>
<div class="auth-container">
    <div class="heading">Reset Your Password</div>

    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($step === 'sent'): ?>
        <div class="success">
            If an account with that email exists, we've sent a password reset link. Please check your inbox (and spam folder).
        </div>
        <div class="switch"><a href="login.php">Back to Sign In</a></div>
    <?php else: ?>
        <form method="POST">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required autofocus>
            </div>
            <button class="btn" type="submit">Send Reset Link</button>
        </form>
        <div class="switch"><a href="login.php">Back to Sign In</a></div>
    <?php endif; ?>
</div>
</body>
</html>