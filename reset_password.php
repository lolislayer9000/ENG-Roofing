<?php
// reset_password.php
require_once 'config.php';

$error = '';
$success = '';
$validToken = false;
$userId = null;

// Check for token in URL
if (isset($_GET['token'])) {
    $token = trim($_GET['token']);

    // Look up the token and ensure it's not expired
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expiry > ? AND status = 'active' LIMIT 1");
    $stmt->execute([$token, $now]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $validToken = true;
        $userId = $user['id'];
    } else {
        $error = 'The reset link is invalid or has expired.';
    }
} else {
    // If no token in URL, show error
    $error = 'No reset token provided.';
}

// Handle password update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && isset($_POST['confirm_password']) && isset($_POST['user_id'])) {
    $userId = (int)$_POST['user_id'];
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Update password
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expiry = NULL WHERE id = ?");
        $stmt->execute([$hashed, $userId]);

        $success = 'Your password has been reset successfully. You can now sign in.';
        // Clear the token so it cannot be reused
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
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
    <div class="heading">Set New Password</div>

    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?>
        <div class="success"><?= htmlspecialchars($success) ?></div>
        <div class="switch"><a href="login.php">Go to Sign In</a></div>
    <?php else: ?>
        <?php if ($validToken): ?>
            <form method="POST">
                <input type="hidden" name="user_id" value="<?= $userId ?>">
                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                <button class="btn" type="submit">Reset Password</button>
            </form>
        <?php else: ?>
            <div class="switch"><a href="forgot_password.php">Request a new reset link</a></div>
            <div class="switch"><a href="login.php">Back to Sign In</a></div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>