<?php
require_once 'config.php';

// Load company settings FIRST (used by registration)
$stmt = $pdo->query("SELECT * FROM system_settings ORDER BY id ASC LIMIT 1");
$company_settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company_settings) {
    $company_settings = [
        'company_name' => 'Eng Roofing',
        'company_subtitle' => 'Supply and Installation',
        'company_logo' => ''
    ];
}

// Already logged in? Send the user to the correct dashboard.
if (isLoggedIn()) {
    switch ($_SESSION['role'] ?? '') {
        case 'admin': header('Location: admin.php'); break;
        case 'client': header('Location: client.php'); break;
        case 'foreman': header('Location: foreman.php'); break;
        case 'employee': header('Location: employee.php'); break;
        default: session_destroy(); header('Location: login.php'); break;
    }
    exit;
}

$mode = ($_GET['mode'] ?? 'login') === 'register' ? 'register' : 'login';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';

    if ($action === 'register') {
        $mode = 'register';

        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($full_name === '' || $email === '' || $phone === '' || $address === '' || $password === '' || $confirm_password === '') {
            $error = 'All fields are required.';
        } elseif (!preg_match("/^[a-zA-Z\s\-\.']+$/", $full_name)) {
            $error = 'Full Name can only contain letters, spaces, hyphens, apostrophes, and periods.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!preg_match('/^09\d{9}$/', $phone)) {
            $error = 'Phone Number must be 11 digits and start with 09.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters long.';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } else {
            // Check if email already exists in users table (active account)
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                $error = 'An account with that email address already exists.';
            } else {
                // Also check pending registrations
                $stmt = $pdo->prepare("SELECT id FROM pending_registrations WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);

                if ($stmt->fetch()) {
                    $error = 'A confirmation email has already been sent to this email. Please check your inbox.';
                } else {
                    // Generate a unique token
                    $token = bin2hex(random_bytes(50));
                    $hashed = password_hash($password, PASSWORD_DEFAULT);

                    // Store pending registration
                    try {
                        $stmt = $pdo->prepare("INSERT INTO pending_registrations
                            (full_name, email, phone, address, password_hash, token, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())");

                        $stmt->execute([
                            $full_name,
                            $email,
                            $phone,
                            $address,
                            $hashed,
                            $token
                        ]);

                        // Send confirmation email using unified sendMail()
                        $subject = 'Confirm your registration – ' . $company_settings['company_name'];
                        $base_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                        $verifyLink = $base_url . '/verify_email.php?token=' . urlencode($token);
                        $message = "Thank you for registering.\n\nPlease click the following link to confirm your account:\n" . $verifyLink . "\n\nIf you did not request this, ignore this email.";

                        $fromEmail = !empty($company_settings['company_email']) ? $company_settings['company_email'] : 'no-reply@engroofing.com';
                        $fromName = $company_settings['company_name'];

                        // Use the unified sendMail – plain text ($isHtml = false)
                        $mailSent = sendMail($email, $full_name, $subject, $message, false, $fromEmail, $fromName);

                        if ($mailSent) {
                            $success = 'A confirmation email has been sent to your email address. Please check your inbox and click the link to complete registration.';
                            $mode = 'login';
                        } else {
                            $error = 'Unable to send confirmation email. Please contact support.';
                        }
                    } catch (PDOException $e) {
                        $error = 'Unable to create your pending registration. Please try again.';
                    }
                }
            }
        }
    } else {
        // Login logic
        $mode = 'login';

        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = 'Please enter your email and password.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];

                switch ($user['role']) {
                    case 'admin': header('Location: admin.php'); break;
                    case 'client': header('Location: client.php'); break;
                    case 'foreman': header('Location: foreman.php'); break;
                    case 'employee': header('Location: employee.php'); break;
                    default:
                        session_destroy();
                        die('Unknown user role.');
                }
                exit;
            }

            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($company_settings['company_name']) ?> – <?= $mode === 'register' ? 'Register' : 'Login' ?></title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif}
        body{background:#1a1a1a;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:20px}
        .auth-container{background:#fff;padding:36px 40px;border-radius:10px;width:100%;max-width:430px;box-shadow:0 0 20px rgba(0,0,0,.5)}
        .logo{display:block;width:85px;height:85px;object-fit:contain;margin:0 auto 14px}.brand{text-align:center;color:#c00;font-size:25px;font-weight:bold}
        .brand-link{display:block;text-decoration:none;color:inherit;margin-bottom:0}
        .brand-link:hover .brand{color:#a00}
        .brand-link:hover .logo{opacity:.85}
        .subtitle{text-align:center;color:#666;font-size:14px;margin-top:4px;margin-bottom:22px}.heading{text-align:center;font-size:20px;margin-bottom:18px;color:#333}
        .form-group{margin-bottom:14px}.form-group label{display:block;margin-bottom:5px;font-weight:bold;color:#555}.form-group input,.form-group textarea{width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:5px;font-size:14px}.form-group textarea{min-height:85px;resize:vertical}.form-group input:focus,.form-group textarea:focus{outline:none;border-color:#c00}.btn{width:100%;padding:12px;background:#c00;color:#fff;border:none;border-radius:5px;font-size:15px;cursor:pointer}.btn:hover{background:#a00}
        .error{color:#721c24;background:#f8d7da;border:1px solid #f5c6cb;padding:10px;border-radius:5px;margin-bottom:14px;font-size:13px}.success{color:#155724;background:#d4edda;border:1px solid #c3e6cb;padding:10px;border-radius:5px;margin-bottom:14px;font-size:13px}.switch{text-align:center;margin-top:16px;font-size:13px;color:#777}.switch a{color:#c00;text-decoration:none;font-weight:bold}.switch a:hover{text-decoration:underline}.footer{text-align:center;margin-top:18px;color:#888;font-size:12px}
        .note{background:#f5f5f5;border:1px solid #eee;border-radius:6px;padding:10px 12px;font-size:12px;color:#777;margin-bottom:15px}
        /* Password show/hide toggle */
        .password-field{position:relative}
        .password-field input{padding-right:44px}
        .password-toggle{position:absolute;top:50%;right:12px;transform:translateY(-50%);background:transparent;border:none;cursor:pointer;color:#888;padding:4px;line-height:0;display:inline-flex;align-items:center;justify-content:center;border-radius:4px;transition:color .15s}
        .password-toggle:hover{color:#c00}
        .password-toggle:focus{outline:none;color:#c00}
        .password-toggle svg{width:18px;height:18px;display:block;pointer-events:none}
        .password-toggle .icon-eye-off{display:none}
        .password-toggle.is-visible .icon-eye{display:none}
        .password-toggle.is-visible .icon-eye-off{display:block}
    </style>
</head>
<body>
<div class="auth-container">
    <a href="index.php" class="brand-link" title="Back to home">
        <?php if (!empty($company_settings['company_logo'])): ?>
            <img class="logo" src="<?= htmlspecialchars($company_settings['company_logo']) ?>" alt="Company Logo">
        <?php endif; ?>
        <div class="brand"><?= htmlspecialchars($company_settings['company_name']) ?></div>
    </a>
    <?php if (!empty($company_settings['company_subtitle'])): ?><div class="subtitle"><?= htmlspecialchars($company_settings['company_subtitle']) ?></div><?php endif; ?>

    <div class="heading"><?= $mode === 'register' ? 'Create Client Account' : 'Sign In' ?></div>

    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <?php if ($mode === 'register' && !$success): ?>
        <?php if (($_GET['from'] ?? '') === 'booking'): ?>
            <div class="note" style="background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;">
                Register to view your approved booking. Use the same email address you used when you submitted the booking.
            </div>
        <?php endif; ?>
        <div class="note">Public registration creates a Client account only. A confirmation email will be sent to verify your address before your account is activated.</div>
        <form method="POST" action="login.php?mode=register">
            <input type="hidden" name="action" value="register">
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input 
                    id="full_name" 
                    type="text" 
                    name="full_name" 
                    required 
                    pattern="[a-zA-Z\s\-'\.]+" 
                    title="Name can only contain letters, spaces, hyphens, apostrophes, and periods."
                    oninput="this.value = this.value.replace(/[^a-zA-Z\s\-'\.]/g, '');"
                    value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                >
            </div>
            <div class="form-group"><label for="email">Email Address</label><input id="email" type="email" name="email" required autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? $_GET['email'] ?? '') ?>"></div>
            <div class="form-group"><label for="phone">Phone Number</label><input id="phone" type="tel" name="phone" maxlength="11" minlength="11" pattern="09[0-9]{9}" placeholder="09xxxxxxxxx" inputmode="numeric" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11);" required value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"></div>
            <div class="form-group"><label for="address">Address</label><textarea id="address" name="address" required><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea></div>
            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-field">
                    <input id="password" type="password" name="password" required autocomplete="new-password">
                    <button type="button" class="password-toggle" onclick="togglePasswordVisibility(this)" aria-label="Show password">
                        <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <div class="password-field">
                    <input id="confirm_password" type="password" name="confirm_password" required autocomplete="new-password">
                    <button type="button" class="password-toggle" onclick="togglePasswordVisibility(this)" aria-label="Show password">
                        <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
            </div>
            <button class="btn" type="submit">Create Account</button>
        </form>
        <div class="switch">Already have an account? <a href="login.php">Sign In</a></div>
    <?php elseif ($mode === 'login' || $success): ?>
        <?php if ($success): ?>
            <div style="text-align:center; margin-top:20px;">
                <p><a href="login.php" class="btn" style="text-decoration:none; display:inline-block; padding:12px 30px;">Go to Login</a></p>
            </div>
    <?php else: ?>
            <form method="POST" action="login.php">
                <input type="hidden" name="action" value="login">
                <div class="form-group"><label for="login_email">Email Address</label><input id="login_email" type="email" name="email" required autofocus autocomplete="email"></div>
                <div class="form-group">
                    <label for="login_password">Password</label>
                    <div class="password-field">
                        <input id="login_password" type="password" name="password" required autocomplete="current-password">
                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility(this)" aria-label="Show password">
                            <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>
                <div style="text-align:right; margin-top:8px; font-size:13px;">
                    <a href="forgot_password.php" style="color:#c00;">Forgot Password?</a>
                </div>
                <button class="btn" type="submit">Sign In</button>
            </form>
            <div class="switch">Don't have a client account? <a href="login.php?mode=register">Register</a></div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="footer">© <?= date('Y') ?> <?= htmlspecialchars($company_settings['company_name']) ?>. All rights reserved.</div>
</div>

<script>
function togglePasswordVisibility(btn) {
    var wrapper = btn.closest('.password-field');
    if (!wrapper) return;

    var input = wrapper.querySelector('input');
    if (!input) return;

    var isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';

    btn.classList.toggle('is-visible', isPassword);
    btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
}
</script>

</body>
</html>