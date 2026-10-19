<?php
// This file is included inside admin.php, so $pdo and session are available.
// Only admin can access this page (already enforced in admin.php).

// -----------------------------------------------------------------------------
// Handle POST/GET actions
// -----------------------------------------------------------------------------

// Add new user
if (isset($_POST['add_user'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['system_role'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $join_date = !empty($_POST['join_date']) ? $_POST['join_date'] : date('Y-m-d');
    $daily_rate = null;
    $employee_id = null;

    if (empty($full_name) || empty($email) || empty($password) || empty($role)) {
        header('Location: admin.php?tab=user_management&msg=error');
        exit;
    }

    // Validate phone number: must be 11 digits starting with 09.
    if (!preg_match('/^09\d{9}$/', $phone)) {
        header('Location: admin.php?tab=user_management&msg=invalid_phone');
        exit;
    }

    // Check password confirmation
    if ($password !== ($_POST['confirm_password'] ?? '')) {
        header('Location: admin.php?tab=user_management&msg=password_mismatch');
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        header('Location: admin.php?tab=user_management&msg=duplicate_email');
        exit;
    }

    if ($role === 'employee') {
        $employee_id = generateEmployeeID($pdo);
        $daily_rate = !empty($_POST['daily_rate']) ? (float)$_POST['daily_rate'] : null;
        if ($daily_rate === null) {
            header('Location: admin.php?tab=user_management&msg=missing_rate');
            exit;
        }
    } elseif ($role === 'foreman') {
        $employee_id = generateEmployeeID($pdo);
        $daily_rate = null; // No daily rate for foreman
    }

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $username = $email;

    $sql = "INSERT INTO users
            (username, full_name, email, password, role, phone, join_date, daily_rate, employee_id, status)
            VALUES
            (:username, :full_name, :email, :password, :role, :phone, :join_date, :daily_rate, :employee_id, 'active')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':username' => $username,
        ':full_name' => $full_name,
        ':email' => $email,
        ':password' => $hashed,
        ':role' => $role,
        ':phone' => $phone,
        ':join_date' => $join_date,
        ':daily_rate' => $daily_rate,
        ':employee_id' => $employee_id
    ]);

    header('Location: admin.php?tab=user_management&msg=added');
    exit;
}

// Edit user
if (isset($_POST['edit_user'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['edit_id'] ?? 0);
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['system_role'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $join_date = !empty($_POST['join_date']) ? $_POST['join_date'] : date('Y-m-d');
    $daily_rate = null;
    $employee_id = null;

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        header('Location: admin.php?tab=user_management&msg=error');
        exit;
    }

    if ($email !== $user['email']) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            header('Location: admin.php?tab=user_management&msg=duplicate_email');
            exit;
        }
    }

    // Validate phone number
    if (!preg_match('/^09\d{9}$/', $phone)) {
        header('Location: admin.php?tab=user_management&msg=invalid_phone');
        exit;
    }

    if ($role === 'employee') {
        $daily_rate = !empty($_POST['daily_rate']) ? (float)$_POST['daily_rate'] : null;
        if ($daily_rate === null) {
            header('Location: admin.php?tab=user_management&msg=missing_rate');
            exit;
        }

        $employee_id = !empty($user['employee_id'])
            ? $user['employee_id']
            : generateEmployeeID($pdo);
    } elseif ($role === 'foreman') {
        $daily_rate = null; // No daily rate for foreman
        $employee_id = !empty($user['employee_id'])
            ? $user['employee_id']
            : generateEmployeeID($pdo);
    } else {
        $employee_id = null;
        $daily_rate = null;
    }

    $password_update = '';
    $params = [
        ':username' => $email,
        ':full_name' => $full_name,
        ':email' => $email,
        ':role' => $role,
        ':phone' => $phone,
        ':join_date' => $join_date,
        ':daily_rate' => $daily_rate,
        ':employee_id' => $employee_id,
        ':id' => $id
    ];

    // Only handle password change if a new password is provided
    if (!empty($_POST['password'])) {
        // Check confirmation
        if ($_POST['password'] !== ($_POST['confirm_password'] ?? '')) {
            header('Location: admin.php?tab=user_management&msg=password_mismatch');
            exit;
        }
        $hashed = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $password_update = ", password = :password";
        $params[':password'] = $hashed;
    }

    $sql = "UPDATE users SET
                username = :username,
                full_name = :full_name,
                email = :email,
                role = :role,
                phone = :phone,
                join_date = :join_date,
                daily_rate = :daily_rate,
                employee_id = :employee_id
                $password_update
            WHERE id = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    header('Location: admin.php?tab=user_management&msg=updated');
    exit;
}

// -----------------------------------------------------------------------------
// Face enrollment actions
// face_descriptor stores a JSON array of 128-number face-api.js descriptors.
// Images themselves are NOT stored. Camera/upload images are processed in the
// browser, and only the descriptor is submitted to the database.
// -----------------------------------------------------------------------------

if (isset($_POST['add_face_descriptor'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['face_user_id'] ?? 0);
    $descriptor_json = trim($_POST['face_descriptor'] ?? '');

    if ($id <= 0 || $descriptor_json === '') {
        header('Location: admin.php?tab=user_management&msg=face_invalid');
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, face_descriptor FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $face_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$face_user) {
        header('Location: admin.php?tab=user_management&msg=face_invalid');
        exit;
    }

    $new_descriptor = json_decode($descriptor_json, true);

    // face-api.js descriptors contain exactly 128 numeric values.
    if (!is_array($new_descriptor) || count($new_descriptor) !== 128) {
        header('Location: admin.php?tab=user_management&msg=face_invalid');
        exit;
    }

    foreach ($new_descriptor as $value) {
        if (!is_numeric($value) || !is_finite((float)$value)) {
            header('Location: admin.php?tab=user_management&msg=face_invalid');
            exit;
        }
    }

    $existing_descriptors = [];
    if (!empty($face_user['face_descriptor'])) {
        $decoded = json_decode($face_user['face_descriptor'], true);
        if (is_array($decoded)) {
            $existing_descriptors = array_values($decoded);
        }
    }

    $new_descriptor = array_map('floatval', array_values($new_descriptor));
    $existing_descriptors[] = $new_descriptor;

    $stmt = $pdo->prepare("UPDATE users SET face_descriptor = ? WHERE id = ?");
    $stmt->execute([
        json_encode($existing_descriptors, JSON_PRESERVE_ZERO_FRACTION),
        $id
    ]);

    header('Location: admin.php?tab=user_management&edit=' . $id . '&msg=face_enrolled');
    exit;
}

// Delete one enrolled face descriptor.
if (isset($_POST['delete_face_descriptor'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['face_user_id'] ?? 0);
    $face_index = (int)($_POST['face_index'] ?? -1);

    $stmt = $pdo->prepare("SELECT face_descriptor FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $face_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$face_user || $face_index < 0) {
        header('Location: admin.php?tab=user_management&msg=face_invalid');
        exit;
    }

    $descriptors = [];
    if (!empty($face_user['face_descriptor'])) {
        $decoded = json_decode($face_user['face_descriptor'], true);
        if (is_array($decoded)) {
            $descriptors = array_values($decoded);
        }
    }

    if (!isset($descriptors[$face_index])) {
        header('Location: admin.php?tab=user_management&msg=face_invalid');
        exit;
    }

    unset($descriptors[$face_index]);
    $descriptors = array_values($descriptors);

    $new_json = empty($descriptors)
        ? null
        : json_encode($descriptors, JSON_PRESERVE_ZERO_FRACTION);

    $stmt = $pdo->prepare("UPDATE users SET face_descriptor = ? WHERE id = ?");
    $stmt->execute([$new_json, $id]);

    header('Location: admin.php?tab=user_management&edit=' . $id . '&msg=face_deleted');
    exit;
}

// Delete every enrolled face descriptor for a user.
if (isset($_POST['clear_face_descriptors'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['face_user_id'] ?? 0);

    $stmt = $pdo->prepare("UPDATE users SET face_descriptor = NULL WHERE id = ?");
    $stmt->execute([$id]);

    header('Location: admin.php?tab=user_management&edit=' . $id . '&msg=faces_cleared');
    exit;
}

// Deactivate / Activate user
if (isset($_POST['toggle_status'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $id = (int)$_POST['toggle_status'];
    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $new_status = ($user['status'] === 'active') ? 'inactive' : 'active';
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $id]);
    }
    header('Location: admin.php?tab=user_management&msg=status_toggled');
    exit;
}

// -----------------------------------------------------------------------------
// Fetch data
// -----------------------------------------------------------------------------

$role_filter = $_GET['role_filter'] ?? 'all';
$allowed_roles = ['admin', 'foreman', 'employee', 'client'];
if (!in_array($role_filter, $allowed_roles, true) && $role_filter !== 'all') {
    $role_filter = 'all';
}

$sql = "SELECT * FROM users";
$params = [];
if ($role_filter !== 'all') {
    $sql .= " WHERE role = :role";
    $params[':role'] = $role_filter;
}
$sql .= " ORDER BY created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$role_counts = ['admin' => 0, 'foreman' => 0, 'employee' => 0, 'client' => 0];
$stmt = $pdo->query("SELECT role, COUNT(*) as cnt FROM users WHERE status = 'active' GROUP BY role");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (isset($role_counts[$row['role']])) {
        $role_counts[$row['role']] = $row['cnt'];
    }
}

$msg = '';
$msg_type = '';
if (isset($_GET['msg'])) {
    $msgs = [
        'added' => ['text' => 'User added successfully.', 'type' => ''],
        'updated' => ['text' => 'User updated successfully.', 'type' => ''],
        'status_toggled' => ['text' => 'User status updated.', 'type' => ''],
        'error' => ['text' => 'An error occurred. Please try again.', 'type' => 'error'],
        'duplicate_email' => ['text' => 'Email address already exists.', 'type' => 'error'],
        'missing_rate' => ['text' => 'Daily rate is required for Employee role.', 'type' => 'error'],
        'invalid_phone' => ['text' => 'Phone Number must be 11 digits and start with 09.', 'type' => 'error'],
        'password_mismatch' => ['text' => 'Password and Confirm Password do not match.', 'type' => 'error'],
        'face_enrolled' => ['text' => 'Face enrolled successfully.', 'type' => ''],
        'face_deleted' => ['text' => 'Enrolled face deleted.', 'type' => ''],
        'faces_cleared' => ['text' => 'All enrolled faces have been deleted.', 'type' => ''],
        'face_invalid' => ['text' => 'The face descriptor was invalid or the selected user could not be found.', 'type' => 'error']
    ];

    if (isset($msgs[$_GET['msg']])) {
        $msg = $msgs[$_GET['msg']]['text'];
        $msg_type = $msgs[$_GET['msg']]['type'];
    }
}

$editUser = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editUser) {
        unset($_GET['edit']);
    }
}
?>

<?php if ($msg): ?>
    <div class="msg <?= htmlspecialchars($msg_type) ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="stats">
    <div class="stat-card"><div class="number"><?= $role_counts['admin'] ?></div><div class="label">Admins</div></div>
    <div class="stat-card"><div class="number"><?= $role_counts['foreman'] ?></div><div class="label">Foremen</div></div>
    <div class="stat-card"><div class="number"><?= $role_counts['employee'] ?></div><div class="label">Employees</div></div>
    <div class="stat-card"><div class="number"><?= $role_counts['client'] ?></div><div class="label">Clients</div></div>
</div>

<div class="top-bar">
    <form method="GET" class="d-flex align-items-center gap-2">
        <input type="hidden" name="tab" value="user_management">
        <label for="role_filter">Filter by Role:</label>
        <select name="role_filter" id="role_filter" onchange="this.form.submit()">
            <option value="all" <?= $role_filter === 'all' ? 'selected' : '' ?>>All</option>
            <option value="admin" <?= $role_filter === 'admin' ? 'selected' : '' ?>>Admin</option>
            <option value="foreman" <?= $role_filter === 'foreman' ? 'selected' : '' ?>>Foreman</option>
            <option value="employee" <?= $role_filter === 'employee' ? 'selected' : '' ?>>Employee</option>
            <option value="client" <?= $role_filter === 'client' ? 'selected' : '' ?>>Client</option>
        </select>
    </form>
    <button class="btn btn-primary" onclick="document.getElementById('addUserModal').classList.add('show')">
        <i class="bi bi-plus-lg me-1"></i>Add New User
    </button>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Employee ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Role</th>
                <th>Daily Rate</th><th>Join Date</th><th>Status</th><th>Faces</th><th style="text-align: center">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (count($users) === 0): ?>
            <tr><td colspan="10" class="text-center py-5" style="color: var(--fr-muted);">No users found.</td></tr>
        <?php else: ?>
            <?php foreach ($users as $u):
                $userFaces = [];
                if (!empty($u['face_descriptor'])) {
                    $decodedFaces = json_decode($u['face_descriptor'], true);
                    if (is_array($decodedFaces)) $userFaces = array_values($decodedFaces);
                }
                $faceCount = count($userFaces);
            ?>
                <tr>
                    <td><?= htmlspecialchars($u['employee_id'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($u['full_name']) ?></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= htmlspecialchars($u['phone'] ?? '—') ?></td>
                    <td><?= htmlspecialchars(ucfirst($u['role'])) ?></td>
                    <td><?= $u['daily_rate'] !== null && $u['daily_rate'] !== '' ? '₱' . number_format((float)$u['daily_rate'], 2) : '—' ?></td>
                    <td><?= !empty($u['join_date']) ? date('M d, Y', strtotime($u['join_date'])) : '—' ?></td>
                    <td>
                        <span class="fr-badge <?= $u['status'] === 'active' ? 'active' : 'rejected' ?>">
                            <?= htmlspecialchars(ucfirst($u['status'])) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($faceCount > 0): ?>
                            <span class="fr-badge followup"><?= $faceCount ?> enrolled</span>
                        <?php else: ?>
                            <span class="muted" style="font-size: 12px;">None</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <a href="admin.php?tab=user_management&edit=<?= (int)$u['id'] ?>"
                           class="action-btn edit"
                           onclick="document.getElementById('editModal<?= (int)$u['id'] ?>').classList.add('show'); return false;">Edit</a>
                        <form method="POST" action="admin.php?tab=user_management" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="toggle_status" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="action-btn <?= $u['status'] === 'active' ? 'reject' : 'approve' ?>"
                                    onclick="return confirm('Toggle status for this user?')">
                                <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </form>
                    </td>
                </tr>

                <div id="editModal<?= (int)$u['id'] ?>" class="ad-modal">
                    <div class="ad-modal-content" style="max-width: 900px;">
                        <button type="button" class="close" onclick="closeEditModal(<?= (int)$u['id'] ?>)">&times;</button>
                        <h2>Edit User – <?= htmlspecialchars($u['full_name']) ?></h2>

                        <form method="POST" action="admin.php?tab=user_management" id="editUserForm<?= (int)$u['id'] ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="edit_id" value="<?= (int)$u['id'] ?>">

                            <div class="form-row">
                                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" value="<?= htmlspecialchars($u['full_name']) ?>" required></div>
                                <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($u['email']) ?>" required></div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Password <small>(leave blank to keep current)</small></label>
                                    <div class="password-field">
                                        <input type="password" name="password">
                                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility(this)">
                                            <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                        </button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Confirm Password</label>
                                    <div class="password-field">
                                        <input type="password" name="confirm_password">
                                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility(this)">
                                            <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group"><label>Phone Number</label><input type="text" name="phone" value="<?= htmlspecialchars($u['phone'] ?? '') ?>" required maxlength="11" pattern="09[0-9]{9}" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11);"></div>
                                <div class="form-group"><label>System Role</label>
                                    <select name="system_role" id="edit_role_<?= (int)$u['id'] ?>" required onchange="toggleEditFields(<?= (int)$u['id'] ?>)">
                                        <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin/Project Manager</option>
                                        <option value="foreman" <?= $u['role'] === 'foreman' ? 'selected' : '' ?>>Foreman</option>
                                        <option value="employee" <?= $u['role'] === 'employee' ? 'selected' : '' ?>>Employee/Roof Installer</option>
                                        <option value="client" <?= $u['role'] === 'client' ? 'selected' : '' ?>>Client</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group"><label>Join Date</label><input type="date" name="join_date" value="<?= htmlspecialchars($u['join_date'] ?: date('Y-m-d')) ?>" required></div>
                                <div class="form-group" id="edit_daily_rate_wrap_<?= (int)$u['id'] ?>" style="<?= $u['role'] === 'employee' ? '' : 'display:none;' ?>">
                                    <label>Daily Rate (₱)</label>
                                    <input type="number" step="0.01" name="daily_rate" value="<?= htmlspecialchars($u['daily_rate'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="form-group" id="edit_employee_id_wrap_<?= (int)$u['id'] ?>" style="<?= in_array($u['role'], ['foreman', 'employee'], true) ? '' : 'display:none;' ?>">
                                <label>Employee ID (auto-generated)</label>
                                <input type="text" value="<?= htmlspecialchars($u['employee_id'] ?? 'Will be generated') ?>" readonly style="background: #f9fafb;">
                            </div>

                            <div class="form-actions">
                                <button type="button" class="btn btn-outline-secondary" onclick="closeEditModal(<?= (int)$u['id'] ?>)">Cancel</button>
                                <button type="submit" name="edit_user" class="btn btn-primary">Update User</button>
                            </div>
                        </form>

                        <!-- Face Enrollment Panel -->
                        <div id="facePanel<?= (int)$u['id'] ?>" style="display:<?= $u['role'] === 'employee' ? 'block' : 'none' ?>; margin-top: 25px; padding-top: 22px; border-top: 2px solid var(--fr-border);">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                <div>
                                    <h3 class="fr-section-title">Face Enrollment</h3>
                                    <p style="color: var(--fr-muted); font-size: 13px; margin: 0;">Capture or upload a photo. Only the 128-value face descriptor is stored.</p>
                                </div>
                                <span class="fr-badge followup"><?= $faceCount ?> enrolled</span>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-7">
                                    <div class="position-relative rounded-3 overflow-hidden" style="background: #111; min-height: 280px;">
                                        <video id="faceVideo<?= (int)$u['id'] ?>" autoplay muted playsinline hidden style="width: 100%; height: 280px; object-fit: cover; display: block;"></video>
                                        <canvas id="facePreview<?= (int)$u['id'] ?>" hidden style="width: 100%; height: 280px; object-fit: cover; display: block;"></canvas>
                                        <div id="faceCameraPlaceholder<?= (int)$u['id'] ?>" class="d-flex flex-column align-items-center justify-content-center" style="position: absolute; inset: 0; color: #ddd; text-align: center; padding: 20px;">
                                            <i class="bi bi-person-bounding-box" style="font-size: 46px; color: var(--fr-red);"></i>
                                            <strong>Camera not started</strong>
                                            <span style="font-size: 12px; color: #999;">Start the camera or choose a photo below.</span>
                                        </div>
                                        <div id="faceEnrollmentOverlay<?= (int)$u['id'] ?>" hidden class="d-flex flex-column align-items-center justify-content-center" style="position: absolute; inset: 0; background: rgba(0,0,0,.72); color: #fff; gap: 8px;">
                                            <div class="spinner-border" role="status"></div>
                                            <strong>Enrolling...</strong>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-5">
                                    <div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);">
                                        <div id="faceStatus<?= (int)$u['id'] ?>" class="p-2 rounded-2 mb-3" style="background: #fff; border: 1px solid var(--fr-border); font-size: 13px; min-height: 42px;">Ready to enroll.</div>
                                        <div class="d-flex flex-wrap gap-2 mb-3">
                                            <button type="button" class="btn btn-outline-secondary btn-sm face-camera-btn" onclick="startFaceCamera(<?= (int)$u['id'] ?>)"><i class="bi bi-camera-video me-1"></i>Start Camera</button>
                                            <button type="button" class="btn btn-primary btn-sm face-capture-btn" onclick="captureFaceDescriptor(<?= (int)$u['id'] ?>)" disabled><i class="bi bi-person-check me-1"></i>Capture</button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="stopFaceCamera(<?= (int)$u['id'] ?>)">Stop</button>
                                        </div>
                                        <label class="form-label" for="faceUpload<?= (int)$u['id'] ?>">Upload Photo</label>
                                        <input type="file" id="faceUpload<?= (int)$u['id'] ?>" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" onchange="enrollUploadedFace(<?= (int)$u['id'] ?>, this)">
                                        <small class="form-text">JPG, PNG, or WebP. Single face, good lighting.</small>
                                        <form id="faceEnrollForm<?= (int)$u['id'] ?>" method="POST" action="admin.php?tab=user_management" style="display:none;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="face_user_id" value="<?= (int)$u['id'] ?>">
                                            <input type="hidden" name="face_descriptor" id="faceDescriptorInput<?= (int)$u['id'] ?>">
                                            <input type="hidden" name="add_face_descriptor" value="1">
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h4 class="fr-section-title mb-0">Enrolled Samples</h4>
                                    <?php if ($faceCount > 0): ?>
                                        <form method="POST" action="admin.php?tab=user_management" onsubmit="return confirm('Delete all enrolled faces for this user?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="face_user_id" value="<?= (int)$u['id'] ?>">
                                            <button type="submit" name="clear_face_descriptors" class="btn btn-sm btn-outline-danger">Delete All</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <?php if ($faceCount === 0): ?>
                                    <div class="text-center py-3" style="color: var(--fr-muted); font-size: 13px; border: 1px dashed var(--fr-border); border-radius: 8px;">No face descriptors enrolled yet.</div>
                                <?php else: ?>
                                    <div class="row g-2">
                                        <?php foreach ($userFaces as $faceIndex => $descriptor): ?>
                                            <div class="col-md-4">
                                                <div class="d-flex align-items-center gap-2 p-2 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);">
                                                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 38px; height: 38px; border-radius: 50%; background: var(--fr-red); color: #fff; font-size: 18px;"><i class="bi bi-person-fill"></i></div>
                                                    <div class="flex-grow-1 min-w-0">
                                                        <div style="font-size: 12px; font-weight: 700;">Sample <?= $faceIndex + 1 ?></div>
                                                        <div style="font-size: 10px; color: var(--fr-muted);"><?= count((array)$descriptor) ?>-value</div>
                                                    </div>
                                                    <form method="POST" action="admin.php?tab=user_management" onsubmit="return confirm('Delete this face sample?')">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="face_user_id" value="<?= (int)$u['id'] ?>">
                                                        <input type="hidden" name="face_index" value="<?= (int)$faceIndex ?>">
                                                        <button type="submit" name="delete_face_descriptor" class="btn btn-sm btn-outline-danger border-0"><i class="bi bi-x-lg"></i></button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Add User Modal -->
<div id="addUserModal" class="ad-modal">
    <div class="ad-modal-content">
        <button type="button" class="close" onclick="document.getElementById('addUserModal').classList.remove('show')">&times;</button>
        <h2>Add New User</h2>
        <form method="POST" action="admin.php?tab=user_management" id="addUserForm">
            <?= csrf_field() ?>
            <div class="form-row">
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Password</label>
                    <div class="password-field">
                        <input type="password" name="password" required>
                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility(this)">
                            <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <div class="password-field">
                        <input type="password" name="confirm_password" required>
                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility(this)">
                            <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Phone Number</label><input type="text" name="phone" required maxlength="11" pattern="09[0-9]{9}" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11);"></div>
                <div class="form-group"><label>System Role</label>
                    <select name="system_role" id="add_role" required onchange="toggleAddFields()">
                        <option value="admin">Admin/Project Manager</option>
                        <option value="foreman">Foreman</option>
                        <option value="employee">Employee/Roof Installer</option>
                        <option value="client">Client</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Join Date</label><input type="date" name="join_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="form-group" id="add_daily_rate_wrap" style="display:none;">
                    <label>Daily Rate (₱)</label>
                    <input type="number" step="0.01" name="daily_rate">
                </div>
            </div>
            <div class="form-group" id="add_employee_id_wrap" style="display:none;">
                <label>Employee ID (auto-generated)</label>
                <input type="text" value="Will be generated automatically" readonly style="background: #f9fafb;">
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('addUserModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="add_user" class="btn btn-primary">Save User</button>
            </div>
        </form>
    </div>
</div>

<!--
    Local face-api.js build.
    Copy node_modules/face-api.js/dist/face-api.min.js to:
    eng_roofing/js/face-api.min.js
-->
<script src="js/face-api.min.js"></script>

<script>
(function () {
    // The admin.php page currently includes this tab twice. Keep all state on
    // window instead of declaring top-level const/let variables, which prevents
    // "Identifier has already been declared" errors on the second include.
    var FaceEnrollment = window.ENGRoofingFaceEnrollment || (window.ENGRoofingFaceEnrollment = {
        streams: {},
        modelsLoaded: false,
        modelsLoading: false,
        modelLoadError: null
    });

    var FACE_MODELS_URL = 'models';

    function toggleAddFields() {
        var role = document.getElementById('add_role');
        if (!role) return;
        var dailyWrap = document.getElementById('add_daily_rate_wrap');
        var empWrap = document.getElementById('add_employee_id_wrap');
        var employeeRole = role.value === 'employee';
        if (dailyWrap) dailyWrap.style.display = employeeRole ? 'block' : 'none';
        if (empWrap) empWrap.style.display = (role.value === 'foreman' || role.value === 'employee') ? 'block' : 'none';
    }

    function toggleEditFields(userId) {
        var role = document.getElementById('edit_role_' + userId);
        var dailyWrap = document.getElementById('edit_daily_rate_wrap_' + userId);
        var empWrap = document.getElementById('edit_employee_id_wrap_' + userId);
        var facePanel = document.getElementById('facePanel' + userId);
        if (!role || !dailyWrap || !empWrap) return;
        var employeeRole = role.value === 'employee';
        dailyWrap.style.display = employeeRole ? 'block' : 'none';
        empWrap.style.display = (role.value === 'foreman' || role.value === 'employee') ? 'block' : 'none';
        // Face enrollment only for employee role
        if (facePanel) {
            facePanel.style.display = (role.value === 'employee') ? 'block' : 'none';
        }
    }

    function setFaceStatus(userId, message, type) {
        var status = document.getElementById('faceStatus' + userId);
        if (!status) return;
        status.textContent = message;
        status.classList.remove('success', 'error');
        if (type) status.classList.add(type);
    }

    function setEnrollingState(userId, enrolling) {
        var overlay = document.getElementById('faceEnrollmentOverlay' + userId);
        var captureButton = document.querySelector('#editModal' + userId + ' .face-capture-btn');
        var cameraButton = document.querySelector('#editModal' + userId + ' .face-camera-btn');
        if (overlay) overlay.hidden = !enrolling;
        if (captureButton) {
            captureButton.disabled = enrolling || !FaceEnrollment.streams[userId];
            captureButton.textContent = enrolling ? 'Enrolling...' : 'Capture & Enroll';
        }
        if (cameraButton) cameraButton.disabled = enrolling;
    }

    async function loadFaceModels() {
        if (FaceEnrollment.modelsLoaded) return true;
        if (FaceEnrollment.modelsLoading) {
            while (FaceEnrollment.modelsLoading) {
                await new Promise(function (resolve) { setTimeout(resolve, 100); });
            }
            return FaceEnrollment.modelsLoaded;
        }
        FaceEnrollment.modelsLoading = true;
        FaceEnrollment.modelLoadError = null;
        try {
            if (typeof faceapi === 'undefined') {
                throw new Error('face-api.js is not loaded. Copy face-api.min.js into the js folder first.');
            }
            await faceapi.nets.tinyFaceDetector.loadFromUri(FACE_MODELS_URL);
            await faceapi.nets.faceLandmark68Net.loadFromUri(FACE_MODELS_URL);
            await faceapi.nets.faceRecognitionNet.loadFromUri(FACE_MODELS_URL);
            FaceEnrollment.modelsLoaded = true;
            return true;
        } catch (error) {
            FaceEnrollment.modelLoadError = error;
            throw error;
        } finally {
            FaceEnrollment.modelsLoading = false;
        }
    }

    function stopFaceCamera(userId) {
        var stream = FaceEnrollment.streams[userId];
        if (stream) {
            stream.getTracks().forEach(function (track) { track.stop(); });
            delete FaceEnrollment.streams[userId];
        }
        var video = document.getElementById('faceVideo' + userId);
        var placeholder = document.getElementById('faceCameraPlaceholder' + userId);
        if (video) {
            video.pause();
            video.srcObject = null;
            video.hidden = true;
        }
        if (placeholder) placeholder.style.display = 'flex';
        var captureButton = document.querySelector('#editModal' + userId + ' .face-capture-btn');
        if (captureButton) captureButton.disabled = true;
        setEnrollingState(userId, false);
    }

    async function startFaceCamera(userId) {
        var video = document.getElementById('faceVideo' + userId);
        var placeholder = document.getElementById('faceCameraPlaceholder' + userId);
        if (!video) return;

        try {
            setFaceStatus(userId, 'Loading face-recognition models...');
            await loadFaceModels();

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                throw new Error('Camera access is not supported by this browser.');
            }

            stopFaceCamera(userId);

            var stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: 'user',
                    width: { ideal: 640 },
                    height: { ideal: 480 }
                },
                audio: false
            });

            FaceEnrollment.streams[userId] = stream;
            video.srcObject = stream;
            video.hidden = false;
            if (placeholder) placeholder.style.display = 'none';

            var captureButton = document.querySelector('#editModal' + userId + ' .face-capture-btn');
            if (captureButton) captureButton.disabled = false;
            setFaceStatus(userId, 'Camera ready. Position one face inside the frame, then click Capture & Enroll.');
        } catch (error) {
            console.error(error);
            setFaceStatus(userId, 'Unable to start the camera: ' + error.message, 'error');
            stopFaceCamera(userId);
        }
    }

    async function descriptorFromImage(imageSource) {
        await loadFaceModels();
        var detection = await faceapi
            .detectSingleFace(
                imageSource,
                new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 })
            )
            .withFaceLandmarks()
            .withFaceDescriptor();

        if (!detection) {
            throw new Error('No clear face was detected. Use a photo with one visible face and good lighting.');
        }

        var descriptor = Array.from(detection.descriptor);
        if (descriptor.length !== 128) throw new Error('The generated face descriptor was invalid.');
        return descriptor;
    }

    function submitFaceDescriptor(userId, descriptor) {
        var input = document.getElementById('faceDescriptorInput' + userId);
        var form = document.getElementById('faceEnrollForm' + userId);
        if (!input || !form) throw new Error('The face enrollment form is unavailable.');
        input.value = JSON.stringify(descriptor);
        form.submit();
    }

    async function captureFaceDescriptor(userId) {
        var video = document.getElementById('faceVideo' + userId);
        if (!video || !FaceEnrollment.streams[userId]) {
            setFaceStatus(userId, 'Start the camera before enrolling a face.', 'error');
            return;
        }

        setEnrollingState(userId, true);
        setFaceStatus(userId, 'Enrolling... Detecting your face and generating the descriptor.');

        try {
            if (video.readyState < 2) throw new Error('The camera is not ready yet. Please try again.');
            var descriptor = await descriptorFromImage(video);
            var preview = document.getElementById('facePreview' + userId);
            if (preview) {
                preview.width = video.videoWidth || 640;
                preview.height = video.videoHeight || 480;
                preview.getContext('2d').drawImage(video, 0, 0, preview.width, preview.height);
                preview.hidden = false;
            }
            setFaceStatus(userId, 'Face detected. Saving the enrolled descriptor...', 'success');
            submitFaceDescriptor(userId, descriptor);
        } catch (error) {
            console.error(error);
            setFaceStatus(userId, error.message || 'Face enrollment failed.', 'error');
            setEnrollingState(userId, false);
        }
    }

    async function enrollUploadedFace(userId, inputElement) {
        if (!inputElement || !inputElement.files || !inputElement.files[0]) return;

        var file = inputElement.files[0];
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
            setFaceStatus(userId, 'Please choose a JPG, PNG, or WebP image.', 'error');
            inputElement.value = '';
            return;
        }

        setEnrollingState(userId, true);
        setFaceStatus(userId, 'Enrolling... Loading the uploaded photo and detecting the face.');

        try {
            var imageUrl = URL.createObjectURL(file);
            var image = new Image();
            image.onload = async function () {
                try {
                    var preview = document.getElementById('facePreview' + userId);
                    var placeholder = document.getElementById('faceCameraPlaceholder' + userId);
                    var video = document.getElementById('faceVideo' + userId);
                    if (video && FaceEnrollment.streams[userId]) stopFaceCamera(userId);

                    if (preview) {
                        preview.width = image.naturalWidth || image.width;
                        preview.height = image.naturalHeight || image.height;
                        preview.getContext('2d').drawImage(image, 0, 0, preview.width, preview.height);
                        preview.hidden = false;
                    }
                    if (placeholder) placeholder.style.display = 'none';

                    var descriptor = await descriptorFromImage(image);
                    setFaceStatus(userId, 'Face detected. Saving the enrolled descriptor...', 'success');
                    URL.revokeObjectURL(imageUrl);
                    submitFaceDescriptor(userId, descriptor);
                } catch (error) {
                    console.error(error);
                    URL.revokeObjectURL(imageUrl);
                    setFaceStatus(userId, error.message || 'Uploaded face enrollment failed.', 'error');
                    setEnrollingState(userId, false);
                }
            };
            image.onerror = function () {
                URL.revokeObjectURL(imageUrl);
                setFaceStatus(userId, 'The selected image could not be loaded.', 'error');
                setEnrollingState(userId, false);
            };
            image.src = imageUrl;
        } catch (error) {
            console.error(error);
            setFaceStatus(userId, error.message || 'Uploaded face enrollment failed.', 'error');
            setEnrollingState(userId, false);
        }
    }

    function closeEditModal(userId) {
        stopFaceCamera(userId);
        var modal = document.getElementById('editModal' + userId);
        if (modal) modal.classList.remove('show');
    }

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

    window.toggleAddFields = toggleAddFields;
    window.toggleEditFields = toggleEditFields;
    window.startFaceCamera = startFaceCamera;
    window.stopFaceCamera = stopFaceCamera;
    window.captureFaceDescriptor = captureFaceDescriptor;
    window.enrollUploadedFace = enrollUploadedFace;
    window.closeEditModal = closeEditModal;
    window.togglePasswordVisibility = togglePasswordVisibility;

    document.addEventListener('DOMContentLoaded', function () {
        toggleAddFields();

        // Auto-open edit modal if edit parameter is set
        <?php if (isset($_GET['edit']) && $editUser): ?>
        (function () {
            var editUserId = <?= (int)$_GET['edit'] ?>;
            var modal = document.getElementById('editModal' + editUserId);
            if (modal) modal.classList.add('show');
            toggleEditFields(editUserId);
        })();
        <?php endif; ?>

        // Initialise toggleEditFields for all edit modals to set face panel visibility
        document.querySelectorAll('[id^="editModal"]').forEach(function(modal) {
            var userId = parseInt(modal.id.replace('editModal', ''), 10);
            if (!isNaN(userId)) {
                toggleEditFields(userId);
            }
        });

        // Password confirm validation for add form
        var addForm = document.getElementById('addUserForm');
        if (addForm) {
            addForm.addEventListener('submit', function(e) {
                var pass = addForm.querySelector('input[name="password"]');
                var confirm = addForm.querySelector('input[name="confirm_password"]');
                if (pass.value !== confirm.value) {
                    e.preventDefault();
                    alert('Password and Confirm Password do not match.');
                }
            });
        }

        // Password confirm validation for each edit form
        document.querySelectorAll('form[id^="editUserForm"]').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                var pass = form.querySelector('input[name="password"]');
                var confirm = form.querySelector('input[name="confirm_password"]');
                if (pass.value !== '' && pass.value !== confirm.value) {
                    e.preventDefault();
                    alert('Password and Confirm Password do not match.');
                }
            });
        });
    });

    window.addEventListener('beforeunload', function () {
        Object.keys(FaceEnrollment.streams).forEach(function (userId) {
            stopFaceCamera(userId);
        });
    });
})();
</script>