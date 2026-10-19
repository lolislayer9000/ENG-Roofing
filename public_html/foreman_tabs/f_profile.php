<?php
// This file is included inside foreman.php.
// Foreman's profile tab: photo, contact info, QR code.
// All <style> rules live in foreman.php.

$foreman_id = (int)($_SESSION['user_id'] ?? 0);

if ($foreman_id <= 0) {
    die('Invalid foreman account.');
}


// -----------------------------------------------------------------------------
// Load the current foreman record
// -----------------------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT id, full_name, email, phone, employee_id, join_date, profile_photo,
           public_profile_token
    FROM users
    WHERE id = ? AND role = 'foreman'
    LIMIT 1
");
$stmt->execute([$foreman_id]);
$foreman = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$foreman) {
    die('Foreman account not found.');
}


// -----------------------------------------------------------------------------
// Handle profile update
// -----------------------------------------------------------------------------
$errors = [];

if (isset($_POST['update_foreman_profile'])) {

    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');

    if ($full_name === '') {
        $errors[] = 'Full name is required.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (!preg_match('/^09\d{9}$/', $phone)) {
        $errors[] = 'Phone number must be 11 digits, starting with 09.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            SELECT id FROM users
            WHERE email = ? AND id != ?
            LIMIT 1
        ");
        $stmt->execute([$email, $foreman_id]);
        if ($stmt->fetch()) {
            $errors[] = 'That email address is already used by another account.';
        }
    }

    $newPhotoPath = $foreman['profile_photo'];

    if (
        empty($errors) &&
        isset($_FILES['profile_photo']) &&
        $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE
    ) {

        $file = $_FILES['profile_photo'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Photo upload failed (error code ' . (int)$file['error'] . ').';
        } elseif ($file['size'] > 3 * 1024 * 1024) {
            $errors[] = 'Photo must be 3 MB or smaller.';
        } else {
            $info = @getimagesize($file['tmp_name']);
            $allowed_types = [
                IMAGETYPE_JPEG => 'jpg',
                IMAGETYPE_PNG  => 'png',
                IMAGETYPE_WEBP => 'webp',
            ];

            if ($info === false || !isset($allowed_types[$info[2]])) {
                $errors[] = 'Please upload a JPG, PNG, or WebP image.';
            } else {
                $ext = $allowed_types[$info[2]];

                // Uploads live at <project>/uploads/profiles/
                // f_profile.php is at <project>/foreman_tabs/, so go up one level.
                $dir = dirname(__DIR__) . '/uploads/profiles/';

                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }

                if (!is_dir($dir)) {
                    $errors[] = 'Unable to create the upload directory.';
                } else {
                    $filename = 'foreman_' . $foreman_id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    $dest = $dir . $filename;

                    if (!move_uploaded_file($file['tmp_name'], $dest)) {
                        $errors[] = 'Unable to save the uploaded photo.';
                    } else {
                        @chmod($dest, 0644);

                        // Delete the previous file if one existed.
                        if (!empty($foreman['profile_photo'])) {
                            $old = dirname(__DIR__) . '/' . ltrim($foreman['profile_photo'], '/');
                            if (is_file($old)) @unlink($old);
                        }

                        $newPhotoPath = 'uploads/profiles/' . $filename;
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            UPDATE users
            SET full_name = ?, email = ?, phone = ?, profile_photo = ?
            WHERE id = ? AND role = 'foreman'
        ");
        $stmt->execute([$full_name, $email, $phone, $newPhotoPath, $foreman_id]);

        // Keep the session in sync so the sidebar updates immediately.
        $_SESSION['full_name'] = $full_name;

        header('Location: foreman.php?tab=profile&msg=updated');
        exit;
    }
}


// -----------------------------------------------------------------------------
// Messages
// -----------------------------------------------------------------------------
$msg = '';
$msg_type = '';

if (!empty($errors)) {
    $msg = implode(' ', $errors);
    $msg_type = 'error';
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'updated') {
    $msg = 'Profile updated successfully.';
}


// -----------------------------------------------------------------------------
// Whether the photo currently on file is readable
// -----------------------------------------------------------------------------
$hasPhoto = !empty($foreman['profile_photo'])
         && is_file(dirname(__DIR__) . '/' . ltrim($foreman['profile_photo'], '/'));


// -----------------------------------------------------------------------------
// Public verification profile
//
// Every foreman gets an opaque 32-hex-char token. Their QR code encodes
// a URL to the public verification page (foreman_profile.php), which
// reads the token and shows only name / photo / employee ID / phone /
// role / join date / active status. No daily rate, email, address,
// biometrics, or attendance data are ever exposed.
//
// If the token is missing (foreman was created before the migration),
// generate one now and persist it. New foremen get one on their first
// visit to this tab.
// -----------------------------------------------------------------------------
$profileToken = $foreman['public_profile_token'] ?? '';

if (empty($profileToken)) {
    $profileToken = bin2hex(random_bytes(16));
    $pdo->prepare("UPDATE users SET public_profile_token = ? WHERE id = ?")
        ->execute([$profileToken, $foreman_id]);
}

$profileVerifyUrl = '';

if (!empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    // SCRIPT_NAME for this tab is /foreman.php (the tab file is included).
    // Walk up to the project root the same way the photo URL used to.
    $baseDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    if ($baseDir === '/' || $baseDir === '\\') {
        $baseDir = '';
    }

    $profileVerifyUrl = $scheme . '://' . $_SERVER['HTTP_HOST']
                      . $baseDir . '/foreman_profile.php?t=' . $profileToken;
}
?>

<?php if ($msg): ?>
    <div class="alert <?= $msg_type === 'error' ? 'alert-danger' : 'alert-success' ?> d-flex align-items-center" role="alert">
        <i class="bi <?= $msg_type === 'error' ? 'bi-exclamation-triangle' : 'bi-check-circle' ?> me-2"></i>
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
    <div>
        <h2 class="fw-bold mb-1" style="font-size: 20px;">My Profile</h2>
        <p class="mb-0" style="color: var(--fr-muted); font-size: 13.5px;">
            Your contact details and a scannable identity card for site use.
        </p>
    </div>
</div>

<div class="row g-4">

    <!-- =====================================================
         Left column: photo + info form
         ===================================================== -->
    <div class="col-lg-7">
        <div class="fr-card">
            <div class="fr-card-pad">
                <h3 class="fr-section-title">Profile Details</h3>

                <form method="POST"
                      action="foreman.php?tab=profile"
                      enctype="multipart/form-data">
                    <?= csrf_field() ?>

                    <div class="d-flex flex-column flex-sm-row gap-4 align-items-start mb-4">

                        <!-- Photo frame -->
                        <div class="d-flex flex-column align-items-center gap-2">
                            <div class="profile-photo-frame" id="profilePhotoFrame">
                                <?php if ($hasPhoto): ?>
                                    <img src="<?= htmlspecialchars($foreman['profile_photo']) ?>" alt="">
                                <?php else: ?>
                                    <span class="profile-photo-fallback">
                                        <?= htmlspecialchars(strtoupper(mb_substr($foreman['full_name'], 0, 1))) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <label for="profile_photo" class="btn btn-outline-secondary btn-sm mb-0">
                                <i class="bi bi-camera me-1"></i>Change Photo
                            </label>
                            <input type="file"
                                   id="profile_photo"
                                   name="profile_photo"
                                   accept="image/jpeg,image/png,image/webp"
                                   class="d-none">
                            <div style="font-size: 11px; color: var(--fr-muted); text-align: center;">
                                JPG, PNG, WebP · max 3 MB
                            </div>
                        </div>

                        <!-- Info fields -->
                        <div class="flex-grow-1 w-100" style="min-width: 0;">
                            <div class="form-group">
                                <label for="full_name">Full Name</label>
                                <input type="text"
                                       id="full_name"
                                       name="full_name"
                                       class="form-control"
                                       value="<?= htmlspecialchars($foreman['full_name'] ?? '') ?>"
                                       required>
                            </div>

                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email"
                                       id="email"
                                       name="email"
                                       class="form-control"
                                       value="<?= htmlspecialchars($foreman['email'] ?? '') ?>"
                                       required>
                            </div>

                            <div class="form-group mb-0">
                                <label for="phone">Contact Number</label>
                                <input type="tel"
                                       id="phone"
                                       name="phone"
                                       class="form-control"
                                       value="<?= htmlspecialchars($foreman['phone'] ?? '') ?>"
                                       maxlength="11"
                                       minlength="11"
                                       pattern="09[0-9]{9}"
                                       placeholder="09xxxxxxxxx"
                                       inputmode="numeric"
                                       oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11);"
                                       required>
                                <small class="form-text">11 digits, starting with 09.</small>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit"
                                name="update_foreman_profile"
                                class="btn btn-primary">
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- =====================================================
         Right column: QR code
         ===================================================== -->
    <div class="col-lg-5">
        <div class="fr-card h-100">
            <div class="fr-card-pad d-flex flex-column h-100">
                <h3 class="fr-section-title">Verification QR</h3>

                <div class="profile-qr-card flex-grow-1">
                    <canvas id="profileQR" width="220" height="220"></canvas>
                    <p class="profile-qr-caption">
                        Scan with any phone camera to open a public verification page
                        showing your name, photo, employee ID, and contact number.
                        Share this with clients at the job site so they can confirm
                        you're a company foreman and reach you directly if needed.
                    </p>
                    <button type="button"
                            id="profileQRDownload"
                            class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-download me-1"></i>Download QR
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>


<script>
(function () {

    // ---------------------------------------------------------------
    // Photo preview
    // ---------------------------------------------------------------
    const photoInput = document.getElementById('profile_photo');
    const photoFrame = document.getElementById('profilePhotoFrame');

    if (photoInput && photoFrame) {
        photoInput.addEventListener('change', function () {
            const file = this.files && this.files[0];
            if (!file) return;

            const reader = new FileReader();

            reader.onload = function (e) {
                photoFrame.innerHTML =
                    '<img src="' + e.target.result + '" alt="">';
            };

            reader.readAsDataURL(file);
        });
    }

    // ---------------------------------------------------------------
    // QR code
    // ---------------------------------------------------------------
    const canvas = document.getElementById('profileQR');
    const dlBtn  = document.getElementById('profileQRDownload');

    function renderQR() {

        if (!canvas) return;
    
        const qrPayload = <?= json_encode($profileVerifyUrl) ?>;
    
        // The verification URL should always exist once the migration has run.
        // If it doesn't (rare — token generation failed), show a graceful error.
        if (!qrPayload) {
            canvas.replaceWith(
                Object.assign(document.createElement('div'), {
                    innerHTML:
                        '<i class="bi bi-exclamation-triangle" ' +
                        'style="font-size:28px;color:#b91c1c;display:block;' +
                        'margin-bottom:8px;"></i>' +
                        '<div style="color:#6b7280;font-size:12.5px;' +
                        'text-align:center;">' +
                        'Verification link is unavailable. Please reload the page.' +
                        '</div>',
                    style: 'padding:20px;'
                })
            );
    
            if (dlBtn) dlBtn.style.display = 'none';
    
            return;
        }
    
        if (typeof QRCode === 'undefined') {
            canvas.replaceWith(
                Object.assign(document.createElement('div'), {
                    textContent: 'QR library failed to load.',
                    style: 'color:#b91c1c;font-size:12px;'
                })
            );
            return;
        }
    
        QRCode.toCanvas(canvas, qrPayload, {
            width: 220,
            margin: 1,
            color: {
                dark: '#111827',
                light: '#ffffff'
            },
            errorCorrectionLevel: 'M'
        }).catch(function (err) {
            console.error('QR generation failed:', err);
    
            canvas.replaceWith(
                Object.assign(document.createElement('div'), {
                    textContent: 'Unable to render QR code.',
                    style: 'color:#b91c1c;font-size:12px;'
                })
            );
        });
    }

    // ---------------------------------------------------------------
    // Download QR
    // ---------------------------------------------------------------
    if (dlBtn && canvas) {
        dlBtn.addEventListener('click', function () {

            const link = document.createElement('a');

            link.download = 'foreman-profile-qr.png';
            link.href = canvas.toDataURL('image/png');

            link.click();
        });
    }

    // ---------------------------------------------------------------
    // Run QR rendering
    // ---------------------------------------------------------------
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderQR);
    } else {
        renderQR();
    }

})();
</script>