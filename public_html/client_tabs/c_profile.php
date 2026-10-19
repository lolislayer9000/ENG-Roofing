<?php
// This file is included inside client.php.
// Only the currently logged-in client can access this profile.

// Make sure we have a logged-in user.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Make sure this user is actually a client.
$stmt = $pdo->prepare("
    SELECT *
    FROM users
    WHERE id = ?
      AND role = 'client'
    LIMIT 1
");
$stmt->execute([$user_id]);

$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    die('Client account not found.');
}

$msg = '';
$msg_type = '';

if (isset($_GET['msg'])) {

    $messages = [
        'complete_profile' => [
            'text' => 'Please complete the required profile fields (name, email, phone).',
            'type' => 'warning'
        ],

        'invalid_phone' => [
            'text' => 'Your phone number must contain exactly 11 digits.',
            'type' => 'error'
        ]
    ];

    if (isset($messages[$_GET['msg']])) {
        $msg = $messages[$_GET['msg']]['text'];
        $msg_type = $messages[$_GET['msg']]['type'];
    }
}
// ---------------------------------------------------------
// UPDATE PROFILE
// ---------------------------------------------------------
if (isset($_POST['update_profile'])) {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    // Validate required fields
    if (
        $full_name === '' ||
        $email === '' ||
        $phone === ''
    ) {
        $msg = 'Full name, email, and phone number are required.';
        $msg_type = 'error';
    }

    // Validate email
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = 'Please enter a valid email address.';
        $msg_type = 'error';
    }

    // Validate phone: exactly 11 digits
    elseif (!preg_match('/^09\d{9}$/', $phone)) {
        $msg = 'Phone Number must be 11 digits and start with 09.';
        $msg_type = 'error';
    }

    else {

        // Check whether another user already uses this email.
        $stmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = ?
              AND id != ?
            LIMIT 1
        ");
        $stmt->execute([$email, $user_id]);

        $existing_email = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing_email) {
            $msg = 'That email address is already being used by another account.';
            $msg_type = 'error';
        }

        else {

            try {

                /*
                 * Current client accounts are created with:
                 *
                 * username = email
                 *
                 * Therefore, when the client changes their email,
                 * update username too so they can continue logging in
                 * with their new email address.
                 */
                $sql = "
                    UPDATE users
                    SET
                        username = :username,
                        full_name = :full_name,
                        email = :email,
                        phone = :phone,
                        address = :address
                    WHERE id = :id
                      AND role = 'client'
                ";

                $stmt = $pdo->prepare($sql);

                $stmt->execute([
                    ':username' => $email,
                    ':full_name' => $full_name,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':address' => $address,
                    ':id' => $user_id
                ]);

                // Update session name as well.
                $_SESSION['full_name'] = $full_name;
                $_SESSION['username'] = $email;

                // Reload client data.
                $stmt = $pdo->prepare("
                    SELECT *
                    FROM users
                    WHERE id = ?
                      AND role = 'client'
                    LIMIT 1
                ");

                $stmt->execute([$user_id]);

                $client = $stmt->fetch(PDO::FETCH_ASSOC);

                $msg = 'Profile updated successfully.';
                $msg_type = '';

            } catch (PDOException $e) {

                $msg = 'Unable to update your profile. Please try again.';
                $msg_type = 'error';
            }
        }
    }
}
?>

<?php if ($msg): ?>
    <div class="msg <?= $msg_type === 'error' ? 'error' : ($msg_type === 'warning' ? 'warning' : '') ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="mb-4">
    <h2 class="fw-bold mb-1" style="font-size: 20px;">My Profile</h2>
    <p class="mb-0" style="color: var(--fr-muted); font-size: 13.5px;">View and update your personal information. All fields are required before you can submit a booking request.</p>
</div>

<div class="fr-card">
    <div class="fr-card-pad">
        <form method="POST" action="client.php?tab=profile">
            <?= csrf_field() ?>

            <div class="form-row">
                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" class="form-control" value="<?= htmlspecialchars($client['full_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($client['email'] ?? '') ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-control" value="<?= htmlspecialchars($client['phone'] ?? '') ?>"
                           maxlength="11" minlength="11" pattern="09[0-9]{9}" placeholder="09xxxxxxxxx" inputmode="numeric"
                           oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11);" required>
                    <small class="form-text">Must be 11 digits starting with 09.</small>
                </div>
                <div class="form-group">
                    <label for="join_date">Join Date</label>
                    <input type="date" id="join_date" class="form-control" value="<?= htmlspecialchars($client['join_date'] ?? '') ?>" readonly style="background: #f9fafb;">
                </div>
            </div>

            <div class="form-group">
                <label for="address">
                    Address <span style="color: var(--fr-muted); font-weight: 400;">(optional)</span>
                </label>
                <textarea id="address" name="address" class="form-control" rows="4"><?= htmlspecialchars($client['address'] ?? '') ?></textarea>
                <small class="form-text">Used as a shortcut when creating bookings. You can also type a different project location each time.</small>
            </div>

            <div class="form-actions">
                <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>