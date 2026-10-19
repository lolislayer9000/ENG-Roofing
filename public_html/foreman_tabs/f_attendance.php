<?php
// This file is included inside foreman.php.
// Attendance POST actions are handled by foreman.php BEFORE HTML output.

$foreman_id = (int)($_SESSION['user_id'] ?? 0);

if ($foreman_id <= 0) {
    die('Invalid foreman account.');
}


// -----------------------------------------------------------------------------
// Active projects for this foreman
// -----------------------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT p.id, p.project_name
    FROM projects p
    WHERE p.foreman_id = ?
      AND " . projectAttendanceEligibleSql('CURDATE()', 'p') . "
    ORDER BY p.project_name ASC
");
$stmt->execute([$foreman_id]);
$active_projects = $stmt->fetchAll(PDO::FETCH_ASSOC);


// -----------------------------------------------------------------------------
// Project filter (defaults to the first active project)
// -----------------------------------------------------------------------------
$selected_project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$project_ids = array_column($active_projects, 'id');

if ($selected_project_id <= 0 || !in_array($selected_project_id, $project_ids, true)) {
    $selected_project_id = !empty($active_projects) ? (int)$active_projects[0]['id'] : 0;
}

$selected_project = null;
foreach ($active_projects as $p) {
    if ((int)$p['id'] === $selected_project_id) {
        $selected_project = $p;
        break;
    }
}


// -----------------------------------------------------------------------------
// Selected date
// -----------------------------------------------------------------------------
$selected_date = $_GET['date'] ?? date('Y-m-d');

$date_obj = DateTime::createFromFormat('Y-m-d', $selected_date);

if (!$date_obj || $date_obj->format('Y-m-d') !== $selected_date) {
    $selected_date = date('Y-m-d');
}

$today = date('Y-m-d');
$is_today = ($selected_date === $today);


// -----------------------------------------------------------------------------
// Messages
// -----------------------------------------------------------------------------
$msg = '';
$msg_error = false;

if (isset($_GET['msg'])) {

    $messages = [
        'attendance_recorded' => 'Attendance recorded successfully.',
        'already_in' => 'This employee has already timed in for today.',
        'already_out' => 'This employee has already timed out for today.',
        'not_allowed' => 'That employee is not assigned to one of your active projects.',
        'invalid_credentials' => 'Invalid employee email or password.',
        'no_project' => 'The employee is not currently assigned to an active project.',
        'face_not_verified' => 'Facial recognition failed. Please make sure the correct employee is in front of the camera.',
        'location_required' => 'Location is required. Please allow location access and try again.',
        'error' => 'Unable to record attendance.',
        'today_only' => 'Time In and Time Out can only be recorded for the current date.'
    ];

    $msg = $messages[$_GET['msg']] ?? '';

    if (in_array($_GET['msg'], [
        'invalid_credentials',
        'not_allowed',
        'no_project',
        'face_not_verified',
        'location_required',
        'error',
        'today_only'
    ], true)) {
        $msg_error = true;
    }
}

// One-shot personalized confirmation (set by foreman.php on success).
$attendance_flash = $_SESSION['attendance_flash'] ?? null;
unset($_SESSION['attendance_flash']);

// -----------------------------------------------------------------------------
// Attendance rows for the selected project + date
// -----------------------------------------------------------------------------
$attendance_rows = [];

if ($selected_project_id > 0) {

    $sql = "
        SELECT
            u.id AS employee_id,
            u.employee_id AS employee_code,
            u.full_name,
            u.email,
            u.join_date,

            p.id AS project_id,
            p.project_name,

            a.time_in,
            a.time_out,
            a.hours_worked,
            a.status AS attendance_status

        FROM users u

        INNER JOIN project_employees pe
            ON pe.employee_id = u.id

        INNER JOIN projects p
            ON p.id = pe.project_id
           AND p.foreman_id = :foreman_id
           AND p.id = :project_id

        LEFT JOIN attendance a
            ON a.employee_id = u.id
           AND a.project_id = p.id
           AND a.attendance_date = :selected_date

        WHERE u.role = 'employee'
          AND u.status = 'active'
          AND u.join_date <= :selected_date_join
          AND " . projectAttendanceEligibleSql(':selected_date_elig', 'p') . "

        GROUP BY
            u.id, u.employee_id, u.full_name, u.email, u.join_date,
            p.id, p.project_name, a.time_in, a.time_out,
            a.hours_worked, a.status

        ORDER BY u.full_name ASC
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':foreman_id'         => $foreman_id,
        ':project_id'         => $selected_project_id,
        ':selected_date'      => $selected_date,
        ':selected_date_join' => $selected_date,
        ':selected_date_elig' => $selected_date,
    ]);

    $attendance_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// -----------------------------------------------------------------------------
// Late threshold (for display)
// -----------------------------------------------------------------------------
$standard_start_time = '08:00:00';
$late_threshold = '08:15:00';

$stmt = $pdo->query("
    SELECT late_threshold, standard_start_time
    FROM system_settings
    ORDER BY id ASC
    LIMIT 1
");

$settings = $stmt->fetch(PDO::FETCH_ASSOC);

if ($settings) {

    if (!empty($settings['standard_start_time'])) {
        $standard_start_time = $settings['standard_start_time'];
    }

    if (!empty($settings['late_threshold'])) {

        $parts = explode(':', $settings['late_threshold']);

        $hours = isset($parts[0]) ? (int)$parts[0] : 0;
        $minutes = isset($parts[1]) ? (int)$parts[1] : 0;
        $seconds = isset($parts[2]) ? (int)$parts[2] : 0;

        $seconds_after_start =
            ($hours * 3600) +
            ($minutes * 60) +
            $seconds;

        $late_threshold = date(
            'H:i:s',
            strtotime($standard_start_time) + $seconds_after_start
        );
    }
}


// -----------------------------------------------------------------------------
// Employees assigned to the selected project (for the manual fallback dropdown)
// -----------------------------------------------------------------------------
$foreman_employees = [];

if ($selected_project_id > 0) {

    $stmt = $pdo->prepare("
        SELECT DISTINCT
            u.id,
            u.full_name,
            u.email,
            u.employee_id AS employee_code
        FROM users u
        INNER JOIN project_employees pe
            ON pe.employee_id = u.id
        WHERE pe.project_id = :project_id
          AND u.role = 'employee'
          AND u.status = 'active'
        ORDER BY u.full_name ASC
    ");

    $stmt->execute([
        ':project_id' => $selected_project_id,
    ]);

    $foreman_employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// -----------------------------------------------------------------------------
// Tally counts
// -----------------------------------------------------------------------------
$present_count = 0;
$late_count = 0;
$absent_count = 0;

foreach ($attendance_rows as &$row) {

    if (empty($row['time_in'])) {
        $row['display_status'] = 'Absent';
        $absent_count++;
    } else {
        $stored_status = $row['attendance_status'];

        if ($stored_status === 'Late') {
            $row['display_status'] = 'Late';
            $late_count++;
        } elseif ($stored_status === 'Present') {
            $row['display_status'] = 'Present';
            $present_count++;
        } else {
            $time_in = strtotime($row['time_in']);
            $late_cutoff = strtotime($late_threshold);

            if ($time_in > $late_cutoff) {
                $row['display_status'] = 'Late';
                $late_count++;
            } else {
                $row['display_status'] = 'Present';
                $present_count++;
            }
        }
    }
}
unset($row);


// -----------------------------------------------------------------------------
// Format helpers
// -----------------------------------------------------------------------------
function formatAttendanceTime($time)
{
    if (empty($time)) return '—';
    return date('g:i A', strtotime($time));
}

function formatAttendanceHours($hours)
{
    if ($hours === null || $hours === '') return '—';
    return number_format((float)$hours, 2);
}
?>

<?php if ($attendance_flash): ?>
    <div class="attendance-confirm-notice">
        <i class="bi bi-check-circle-fill"></i>
        <span>
            <?php if (!empty($attendance_flash['employee_code'])): ?>
                <strong><?= htmlspecialchars($attendance_flash['employee_code']) ?></strong>
            <?php endif; ?>
            <strong><?= htmlspecialchars($attendance_flash['employee_name']) ?></strong>
            <?= $attendance_flash['action'] === 'time_out' ? 'time out' : 'time in' ?>
            confirmed<?= !empty($attendance_flash['time']) ? ' at ' . htmlspecialchars(date('g:i A', strtotime($attendance_flash['time']))) : '' ?>.
        </span>
    </div>
<?php elseif ($msg): ?>
    <div class="alert <?= $msg_error ? 'alert-danger' : 'alert-success' ?> d-flex align-items-center" role="alert">
        <i class="bi <?= $msg_error ? 'bi-exclamation-triangle' : 'bi-check-circle' ?> me-2"></i>
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<!-- Header + filters -->
<div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
    <div>
        <h2 class="fw-bold mb-1" style="font-size: 20px;">Employee Attendance</h2>
        <p class="mb-0" style="color: var(--fr-muted); font-size: 13.5px;">
            Pick a project once, then record attendance by face.
        </p>
    </div>

    <form method="GET" action="foreman.php" class="d-flex align-items-end gap-2 flex-wrap">
        <input type="hidden" name="tab" value="attendance">

        <div>
            <label for="project_filter" class="form-label mb-1" style="font-size: 11.5px;">Project</label>
            <select id="project_filter" name="project_id" class="form-select form-select-sm"
                    onchange="this.form.submit()"
                    <?= empty($active_projects) ? 'disabled' : '' ?>>
                <?php if (empty($active_projects)): ?>
                    <option>No active projects</option>
                <?php else: ?>
                    <?php foreach ($active_projects as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"
                                <?= (int)$p['id'] === $selected_project_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['project_name']) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>

        <div>
            <label for="attendance_date" class="form-label mb-1" style="font-size: 11.5px;">Attendance Date</label>
            <input type="date" id="attendance_date" name="date"
                   class="form-control form-control-sm"
                   value="<?= htmlspecialchars($selected_date) ?>"
                   max="<?= htmlspecialchars($today) ?>"
                   onchange="this.form.submit()">
        </div>
    </form>
</div>

<?php if (empty($active_projects)): ?>
    <div class="fr-card mb-4">
        <div class="fr-card-pad text-center py-5" style="color: var(--fr-muted);">
            <i class="bi bi-clipboard-x" style="font-size: 36px; opacity: .4; display: block; margin-bottom: 10px;"></i>
            <div style="font-size: 14px; font-weight: 600; color: var(--fr-text);">No active projects</div>
            <div style="font-size: 13px; margin-top: 4px;">You need an active project before you can record attendance.</div>
        </div>
    </div>
<?php else: ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-4">
        <div class="fr-stat">
            <div class="fr-stat-label">Present</div>
            <div class="fr-stat-value" style="color: #047857;"><?= $present_count ?></div>
        </div>
    </div>
    <div class="col-4">
        <div class="fr-stat">
            <div class="fr-stat-label">Late</div>
            <div class="fr-stat-value" style="color: #b45309;"><?= $late_count ?></div>
        </div>
    </div>
    <div class="col-4">
        <div class="fr-stat">
            <div class="fr-stat-label">Absent</div>
            <div class="fr-stat-value" style="color: #b91c1c;"><?= $absent_count ?></div>
        </div>
    </div>
</div>

<!-- Actions -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <button type="button" class="btn btn-primary"
            onclick="openAttendanceModal('time_in')"
            <?= !$is_today ? 'disabled' : '' ?>>
        <i class="bi bi-box-arrow-in-right me-1"></i>Time In
    </button>
    <button type="button" class="btn btn-outline-secondary"
            onclick="openAttendanceModal('time_out')"
            <?= !$is_today ? 'disabled' : '' ?>>
        <i class="bi bi-box-arrow-right me-1"></i>Time Out
    </button>
    <?php if (!$is_today): ?>
        <div class="d-flex align-items-center" style="font-size: 12.5px; color: var(--fr-muted);">
            <i class="bi bi-info-circle me-1"></i>
            Only available for the current date.
        </div>
    <?php endif; ?>
</div>

<!-- Table -->
<div class="fr-table-wrap">
    <table class="fr-table">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Time In</th>
                <th>Time Out</th>
                <th>Hours</th>
                <th>Project</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($attendance_rows)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5" style="color: var(--fr-muted);">
                        <i class="bi bi-people" style="font-size: 28px; opacity: .4; display: block; margin-bottom: 8px;"></i>
                        No active employees are assigned to this project for this date.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($attendance_rows as $row): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($row['full_name']) ?></div>
                            <?php if (!empty($row['employee_code'])): ?>
                                <div style="font-size: 11.5px; color: var(--fr-muted);"><?= htmlspecialchars($row['employee_code']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="fw-num"><?= htmlspecialchars(formatAttendanceTime($row['time_in'])) ?></td>
                        <td class="fw-num"><?= htmlspecialchars(formatAttendanceTime($row['time_out'])) ?></td>
                        <td class="fw-num">
                            <?php if (!empty($row['hours_worked'])): ?>
                                <?= htmlspecialchars(formatAttendanceHours($row['hours_worked'])) ?> <span style="color: var(--fr-muted); font-size: 11.5px;">hrs</span>
                            <?php else: ?>
                                <span style="color: var(--fr-muted);">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($row['project_name']) ?></td>
                        <td>
                            <?php $s = strtolower($row['display_status']); ?>
                            <span class="fr-badge <?= $s ?>"><?= htmlspecialchars($row['display_status']) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>


<!-- Attendance Modal (Bootstrap) -->
<div class="modal fade" id="attendanceModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="attendanceModalTitle">Employee Time In</h5>
                <button type="button" class="btn-close" onclick="closeAttendanceModal()"></button>
            </div>

            <form method="POST" action="foreman.php?tab=attendance" id="attendanceActionForm"
                  onsubmit="return prepareAttendanceSubmission();">
                <?= csrf_field() ?>
                <input type="hidden" name="attendance_action" id="attendance_action">
                <input type="hidden" name="project_id" id="attendanceProjectId" value="<?= (int)$selected_project_id ?>">
                <input type="hidden" name="employee_email" id="attendanceEmployeeEmail" value="">
                <input type="hidden" name="captured_photo" id="captured_photo">
                <input type="hidden" name="face_verified" id="face_verified" value="0">
                <input type="hidden" name="face_distance" id="face_distance">
                <input type="hidden" name="latitude" id="latitude">
                <input type="hidden" name="longitude" id="longitude">
                <input type="hidden" name="location_accuracy" id="location_accuracy">

                <div class="modal-body">
                    <p class="mb-3" style="color: var(--fr-muted); font-size: 13px;">
                        Look at the camera. The system will identify the employee automatically.
                    </p>

                    <div class="rounded-3 overflow-hidden position-relative mb-3" style="background: #111;">
                        <video id="attendanceCamera" autoplay muted playsinline style="width: 100%; display: block; aspect-ratio: 4/3; object-fit: cover;"></video>
                        <canvas id="attendanceCanvas" style="display:none;"></canvas>
                        <div id="cameraPlaceholder" class="position-absolute top-50 start-50 translate-middle text-center" style="color: #9ca3af;">
                            <i class="bi bi-camera-video" style="font-size: 36px;"></i>
                            <div style="font-size: 13px;">Starting camera…</div>
                        </div>
                    </div>

                    <!-- Identified employee card -->
                    <div id="identifiedEmployeeCard" class="attendance-identified" style="display:none;">
                        <i class="bi bi-person-check-fill" id="identifiedIcon"></i>
                        <div>
                            <div class="name" id="identifiedName">—</div>
                            <div class="code" id="identifiedCode"></div>
                        </div>
                    </div>

                    <!-- Status lines -->
                    <div class="attendance-status-line" id="faceStatus">
                        Loading enrolled faces…
                    </div>
                    <div class="attendance-status-line" id="locationStatus">
                        Location has not been obtained.
                    </div>
                    <div class="attendance-status-line" id="photoStatus">
                        No photo captured yet.
                    </div>
                </div>


                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="closeAttendanceModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>


<script src="js/face-api.min.js"></script>

<script>
/* =========================================================================
   STATE
   ========================================================================= */

let attendanceStream      = null;
let faceModelsLoaded      = false;
let enrolledEmployees     = [];      // [{id, email, full_name, employee_id, descriptors: [Float32Array]}]
let currentMatchedEmployee = null;

let detectionLoopHandle   = null;    // setInterval handle
let detectionInFlight     = false;
let stableMatch           = null;
let stableCount           = 0;
let autoSubmitTimerHandle = null;
let autoSubmitDeadline    = 0;
let modalIsOpen           = false;
let submissionStarted     = false;
let manualCaptureInFlight = false;

const FACE_MATCH_THRESHOLD   = 0.55;
const DETECTION_INTERVAL_MS  = 550;
const REQUIRED_STABLE_FRAMES = 3;
const AUTO_SUBMIT_DELAY_MS   = 1800;

/* =========================================================================
   LIVENESS (anti-spoofing) — active blink challenge

   A static photo cannot blink. We compute the Eye Aspect Ratio (EAR)
   every frame from the 68 landmarks face-api.js already returns. A full
   open → closed → open cycle counts as a real blink and marks the
   session as "live". A 30-second timeout resets the challenge.
   ========================================================================= */

const EAR_BLINK_THRESHOLD    = 0.255;   // below = eye considered closed
const EAR_OPEN_THRESHOLD     = 0.265;   // above = eye considered open
const REQUIRED_CLOSED_FRAMES = 1;      // must be closed for this many frames
const LIVENESS_TIMEOUT_MS    = 30000;  // 30s to complete the blink
const LIVENESS_FAST_INTERVAL = 60;    // sample faster during the challenge

let liveness = {
    passed:         false,
    forEmployeeId:  null,
    closedFrames:   0,
    hasSeenOpen:    false,
    startedAt:      0
};

function resetLiveness() {
    liveness.passed        = false;
    liveness.forEmployeeId = null;
    liveness.closedFrames  = 0;
    liveness.hasSeenOpen   = false;
    liveness.startedAt     = 0;
}

/** Eye Aspect Ratio for one 6-point eye. */
function eyeAspectRatio(p1, p2, p3, p4, p5, p6) {
    const vertical   = Math.hypot(p2.x - p6.x, p2.y - p6.y)
                     + Math.hypot(p3.x - p5.x, p3.y - p5.y);
    const horizontal = Math.hypot(p1.x - p4.x, p1.y - p4.y);
    if (horizontal === 0) return 0;
    return vertical / (2 * horizontal);
}

/** Average EAR across both eyes from a 68-point landmark set. */
function computeAverageEAR(landmarks) {
    if (!landmarks) return null;
    const positions = landmarks.positions;
    if (!Array.isArray(positions) || positions.length < 48) return null;

    const rightEye = eyeAspectRatio(
        positions[36], positions[37], positions[38],
        positions[39], positions[40], positions[41]
    );
    const leftEye = eyeAspectRatio(
        positions[42], positions[43], positions[44],
        positions[45], positions[46], positions[47]
    );
    return (rightEye + leftEye) / 2;
}

/**
 * Advance the blink state machine one frame.
 * Returns true once an open → closed → open cycle is confirmed.
 */
function advanceBlinkCheck(ear) {
    if (ear === null) return false;

    if (ear > EAR_OPEN_THRESHOLD) {
        liveness.hasSeenOpen = true;
        if (liveness.closedFrames >= REQUIRED_CLOSED_FRAMES) {
            liveness.closedFrames = 0;
            liveness.hasSeenOpen  = false;
            return true;
        }
        liveness.closedFrames = 0;
    } else if (ear < EAR_BLINK_THRESHOLD && liveness.hasSeenOpen) {
        liveness.closedFrames++;
    }
    console.log(
        'EAR:', ear.toFixed(3),
        '| Open detected:', liveness.hasSeenOpen,
        '| Closed frames:', liveness.closedFrames
    );
    return false;
}


/* =========================================================================
   Load face-api models
   ========================================================================= */

async function loadFaceModels() {
    if (faceModelsLoaded) return true;
    try {
        const modelPath = 'models';
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(modelPath),
            faceapi.nets.faceLandmark68Net.loadFromUri(modelPath),
            faceapi.nets.faceRecognitionNet.loadFromUri(modelPath)
        ]);
        faceModelsLoaded = true;
        return true;
    } catch (error) {
        console.error(error);
        document.getElementById('faceStatus').textContent =
            'Unable to load facial-recognition models.';
        return false;
    }
}


/* =========================================================================
   Fetch all enrolled employees for the current project
   ========================================================================= */

async function loadEnrolledEmployees() {
    const projectId = document.getElementById('attendanceProjectId').value;
    if (!projectId || parseInt(projectId, 10) <= 0) {
        enrolledEmployees = [];
        document.getElementById('faceStatus').textContent = 'No project selected.';
        return false;
    }
    try {
        const response = await fetch(
            'foreman.php?face_employees_for_project=' + encodeURIComponent(projectId)
        );
        const data = await response.json();
        if (!data.success || !Array.isArray(data.employees)) {
            enrolledEmployees = [];
            document.getElementById('faceStatus').textContent =
                'Could not load enrolled faces.';
            return false;
        }
        enrolledEmployees = data.employees.map(function (emp) {
            let parsed = [];
            try { parsed = JSON.parse(emp.face_descriptor); } catch (e) { parsed = []; }
            if (!Array.isArray(parsed)) parsed = [];
            const descriptors = parsed
                .filter(function (d) {
                    return Array.isArray(d) && d.length === 128 &&
                        d.every(function (v) { return Number.isFinite(Number(v)); });
                })
                .map(function (d) { return new Float32Array(d.map(Number)); });
            return {
                id: emp.id, email: emp.email, full_name: emp.full_name,
                employee_id: emp.employee_id, descriptors: descriptors,
            };
        });
        const totalWithFaces = enrolledEmployees.filter(function (e) {
            return e.descriptors.length > 0;
        }).length;
        document.getElementById('faceStatus').textContent =
            totalWithFaces + ' enrolled employee' +
            (totalWithFaces === 1 ? '' : 's') + ' ready.';
        return true;
    } catch (error) {
        console.error(error);
        enrolledEmployees = [];
        document.getElementById('faceStatus').textContent =
            'Unable to load the enrolled faces.';
        return false;
    }
}


/* =========================================================================
   Open / close modal
   ========================================================================= */

function openAttendanceModal(action) {
    const projectId = parseInt(
        document.getElementById('attendanceProjectId').value, 10
    );
    if (projectId <= 0) {
        alert('Select a project first.');
        return;
    }

    currentMatchedEmployee = null;
    stableMatch            = null;
    stableCount            = 0;
    submissionStarted      = false;
    resetLiveness();

    document.getElementById('attendance_action').value        = action;
    document.getElementById('attendanceEmployeeEmail').value   = '';
    document.getElementById('face_verified').value             = '0';
    document.getElementById('face_distance').value             = '';
    document.getElementById('captured_photo').value            = '';
    document.getElementById('latitude').value                  = '';
    document.getElementById('longitude').value                 = '';
    document.getElementById('location_accuracy').value         = '';

    document.getElementById('photoStatus').textContent    = 'No photo captured yet.';
    document.getElementById('locationStatus').textContent = 'Location has not been obtained.';
    document.getElementById('faceStatus').textContent     = 'Loading enrolled faces…';

    document.getElementById('attendanceModalTitle').textContent =
        action === 'time_out' ? 'Employee Time Out' : 'Employee Time In';

    hideIdentifiedCard();

    const modalEl  = document.getElementById('attendanceModal');
    const instance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    instance.show();

    modalIsOpen = true;

    (async function () {
        const modelsOk = await loadFaceModels();
        if (!modelsOk) return;
        await loadEnrolledEmployees();
        startAttendanceCamera();
    })();
}


function closeAttendanceModal() {
    modalIsOpen = false;
    stopDetectionLoop();
    cancelAutoSubmit();
    stopAttendanceCamera();

    const modalEl  = document.getElementById('attendanceModal');
    const instance = bootstrap.Modal.getInstance(modalEl);
    if (instance) instance.hide();
}


/* =========================================================================
   Camera
   ========================================================================= */

async function startAttendanceCamera() {
    const video       = document.getElementById('attendanceCamera');
    const placeholder = document.getElementById('cameraPlaceholder');

    try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('Camera access is not supported by this browser.');
            return;
        }

        stopAttendanceCamera();

        attendanceStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
            audio: false
        });

        video.srcObject = attendanceStream;
        if (placeholder) placeholder.style.display = 'none';

        obtainLocation();

        const waitForVideo = setInterval(function () {
            if (video.videoWidth > 0) {
                clearInterval(waitForVideo);
                startDetectionLoop();
            }
        }, 150);

    } catch (error) {
        console.error(error);
        alert('Unable to access the camera. Please allow camera permission.');
    }
}


function stopAttendanceCamera() {
    stopDetectionLoop();
    if (attendanceStream) {
        attendanceStream.getTracks().forEach(function (t) { t.stop(); });
        attendanceStream = null;
    }
}


/* =========================================================================
   Geolocation
   ========================================================================= */

function obtainLocation() {
    const status = document.getElementById('locationStatus');
    if (!navigator.geolocation) {
        status.textContent = 'Geolocation is not supported by this browser.';
        return;
    }
    status.textContent = 'Obtaining location...';
    navigator.geolocation.getCurrentPosition(
        function (position) {
            document.getElementById('latitude').value          = position.coords.latitude;
            document.getElementById('longitude').value         = position.coords.longitude;
            document.getElementById('location_accuracy').value = position.coords.accuracy;
            status.textContent =
                'Location captured (±' + Math.round(position.coords.accuracy) + ' m).';
        },
        function (error) {
            console.error(error);
            status.textContent = 'Location permission was not granted.';
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}


/* =========================================================================
   Identified-employee card
   ========================================================================= */

function showIdentifiedCard(employee, distance, isWarning) {
    const card = document.getElementById('identifiedEmployeeCard');
    const name = document.getElementById('identifiedName');
    const code = document.getElementById('identifiedCode');
    const icon = document.getElementById('identifiedIcon');

    card.style.display = 'flex';

    if (isWarning) {
        card.classList.add('is-warn');
        icon.className = 'bi bi-exclamation-triangle-fill';
    } else {
        card.classList.remove('is-warn');
        icon.className = 'bi bi-person-check-fill';
    }

    name.textContent = employee.full_name;

    const bits = [];
    if (employee.employee_id) bits.push(employee.employee_id);
    if (distance != null && isFinite(distance)) bits.push('match ' + distance.toFixed(3));
    code.textContent = bits.join(' · ');
}

function hideIdentifiedCard() {
    const card = document.getElementById('identifiedEmployeeCard');
    if (card) {
        card.style.display = 'none';
        card.classList.remove('is-warn');
    }
}


/* =========================================================================
   DETECTION LOOP
   ========================================================================= */

function startDetectionLoop() {
    if (detectionLoopHandle) return;
    if (!modalIsOpen) return;
    if (!faceModelsLoaded || !enrolledEmployees.length) return;

    detectionLoopHandle = setTimeout(runDetectionCycle, 0);
}

function stopDetectionLoop() {
    if (detectionLoopHandle) {
        clearTimeout(detectionLoopHandle);
        detectionLoopHandle = null;
    }
    detectionInFlight = false;
    stableMatch = null;
    stableCount = 0;
}

async function runDetectionCycle() {
    detectionLoopHandle = null;
    await detectionLoopTick();

    if (!modalIsOpen || submissionStarted || manualCaptureInFlight) return;
    if (!faceModelsLoaded || !enrolledEmployees.length) return;
    if (autoSubmitTimerHandle) return;   // countdown is running — pause ticks

    // Sample faster while we're waiting for a blink.
    const interval = (stableMatch && !liveness.passed)
        ? LIVENESS_FAST_INTERVAL
        : DETECTION_INTERVAL_MS;

    detectionLoopHandle = setTimeout(runDetectionCycle, interval);
}

async function detectionLoopTick() {
    if (!modalIsOpen || submissionStarted || manualCaptureInFlight) return;
    if (detectionInFlight) return;
    if (autoSubmitTimerHandle) return;
    if (!faceModelsLoaded || !enrolledEmployees.length) return;

    const video = document.getElementById('attendanceCamera');
    if (!video || !video.srcObject || video.videoWidth === 0) return;

    detectionInFlight = true;

    try {
        const detection = await faceapi
            .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({
                inputSize: 320, scoreThreshold: 0.5
            }))
            .withFaceLandmarks()
            .withFaceDescriptor();

        if (!modalIsOpen || submissionStarted) return;

        if (!detection) {
            handleNoMatch();
            return;
        }

        let bestEmployee = null;
        let bestDistance = Infinity;

        for (const emp of enrolledEmployees) {
            for (const desc of emp.descriptors) {
                const d = faceapi.euclideanDistance(desc, detection.descriptor);
                if (d < bestDistance) {
                    bestDistance = d;
                    bestEmployee = emp;
                }
            }
        }

        if (!bestEmployee || bestDistance > FACE_MATCH_THRESHOLD) {
            handleNoMatch();
            return;
        }

        if (stableMatch && stableMatch.employee.id === bestEmployee.id) {
            stableCount++;
        } else {
            // A different face entered the frame — restart the challenge.
            if (liveness.forEmployeeId !== bestEmployee.id) {
                resetLiveness();
            }
            stableMatch = { employee: bestEmployee };
            stableCount = 1;
        }

        showIdentifiedCard(bestEmployee, bestDistance, false);

        if (stableCount >= REQUIRED_STABLE_FRAMES) {

            if (!liveness.passed) {

                // Anchor the challenge to this employee.
                if (liveness.forEmployeeId === null) {
                    liveness.forEmployeeId = bestEmployee.id;
                    liveness.startedAt     = Date.now();
                }

                // Hard timeout — don't leave the modal stuck forever.
                if (Date.now() - liveness.startedAt > LIVENESS_TIMEOUT_MS) {
                    resetLiveness();
                    document.getElementById('faceStatus').textContent =
                        'Liveness check timed out. Please blink clearly to retry.';
                    return;
                }

                const ear = computeAverageEAR(detection.landmarks);

                if (advanceBlinkCheck(ear)) {
                    liveness.passed = true;
                    document.getElementById('faceStatus').textContent =
                        'Liveness confirmed. ' + bestEmployee.full_name + ' recognized…';
                    startAutoSubmitCountdown(bestEmployee, bestDistance, detection);
                } else {
                    document.getElementById('faceStatus').textContent =
                        'Please blink once to confirm you are live (' +
                        bestEmployee.full_name + ').';
                }

            } else {
                // Already passed — keep the normal flow going.
                startAutoSubmitCountdown(bestEmployee, bestDistance, detection);
            }
        }
    } catch (err) {
        console.error(err);
    } finally {
        detectionInFlight = false;
    }
}

function handleNoMatch() {
    if (stableMatch) {
        hideIdentifiedCard();
        stableMatch = null;
        stableCount = 0;
        resetLiveness();   // restart the challenge when a face returns
    }
    if (!autoSubmitTimerHandle) {
        document.getElementById('faceStatus').textContent =
            'Looking for a face… Please stand in front of the camera.';
    }
}


/* =========================================================================
   Auto-submit countdown
   ========================================================================= */

function startAutoSubmitCountdown(employee, distance, detection) {
    if (autoSubmitTimerHandle) return;
    if (manualCaptureInFlight) return;

    autoSubmitDeadline = Date.now() + AUTO_SUBMIT_DELAY_MS;

    const tick = function () {
        if (!modalIsOpen || submissionStarted) {
            cancelAutoSubmit();
            return;
        }

        const remaining = autoSubmitDeadline - Date.now();

        if (remaining <= 0) {
            autoSubmitTimerHandle = null;
            performAutoSubmit(employee, distance, detection);
            return;
        }

        const secs = (remaining / 1000).toFixed(1);
        document.getElementById('faceStatus').textContent =
            'Recognized ' + employee.full_name + '. Confirming in ' + secs + 's…';

        autoSubmitTimerHandle = requestAnimationFrame(tick);
    };

    autoSubmitTimerHandle = requestAnimationFrame(tick);
}

function cancelAutoSubmit() {
    if (autoSubmitTimerHandle) {
        cancelAnimationFrame(autoSubmitTimerHandle);
        autoSubmitTimerHandle = null;
    }
    autoSubmitDeadline = 0;
}


/* =========================================================================
   Auto-submit
   ========================================================================= */

async function performAutoSubmit(employee, distance, detection) {
    if (submissionStarted) return;
    submissionStarted = true;

    stopDetectionLoop();
    cancelAutoSubmit();

    const faceStatus  = document.getElementById('faceStatus');
    const photoStatus = document.getElementById('photoStatus');

    document.getElementById('attendanceEmployeeEmail').value = employee.email;
    document.getElementById('face_verified').value           = '1';
    document.getElementById('face_distance').value           = distance.toFixed(6);

    const video  = document.getElementById('attendanceCamera');
    const canvas = document.getElementById('attendanceCanvas');
    if (video && video.videoWidth > 0) {
        canvas.width  = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        document.getElementById('captured_photo').value = canvas.toDataURL('image/jpeg', 0.75);
        photoStatus.textContent = 'Photo captured.';
    }

    let lat = document.getElementById('latitude').value;
    let lng = document.getElementById('longitude').value;

    if (!lat || !lng) {
        faceStatus.textContent = 'Waiting for location…';
        try {
            const pos = await new Promise(function (resolve, reject) {
                navigator.geolocation.getCurrentPosition(resolve, reject, {
                    enableHighAccuracy: true, timeout: 8000, maximumAge: 0
                });
            });
            document.getElementById('latitude').value          = pos.coords.latitude;
            document.getElementById('longitude').value         = pos.coords.longitude;
            document.getElementById('location_accuracy').value = pos.coords.accuracy;
            document.getElementById('locationStatus').textContent =
                'Location captured (±' + Math.round(pos.coords.accuracy) + ' m).';
        } catch (e) {
            faceStatus.textContent =
                'Location is required. Please allow location access and try again.';
            submissionStarted = false;
            startDetectionLoop();
            return;
        }
    }

    faceStatus.textContent = 'Confirming attendance for ' + employee.full_name + '…';

    stopAttendanceCamera();

    setTimeout(function () {
        document.getElementById('attendanceActionForm').requestSubmit();
    }, 180);
}

/* =========================================================================
   Manual capture — "Taking too long? Take picture manually"
   ========================================================================= */

async function manualCaptureAndSubmit() {
    if (submissionStarted || manualCaptureInFlight) return;

    const video      = document.getElementById('attendanceCamera');
    const faceStatus = document.getElementById('faceStatus');
    const manualBtn  = document.getElementById('manualCaptureBtn');

    // ---- Sanity checks -----------------------------------------------------
    if (!video || !video.srcObject || video.videoWidth === 0) {
        alert('Camera is not ready yet. Please wait a moment and try again.');
        return;
    }
    if (!faceModelsLoaded) {
        alert('The face recognition models are still loading. Please wait a moment.');
        return;
    }
    if (!enrolledEmployees.length) {
        alert('No enrolled faces are available for this project.');
        return;
    }

    // ---- Pause the auto-detection loop for the duration of the capture ----
    manualCaptureInFlight = true;
    if (manualBtn) manualBtn.disabled = true;

    stopDetectionLoop();
    cancelAutoSubmit();

    faceStatus.textContent = 'Analyzing photo…';

    try {
        // Freeze the current frame into a temporary canvas so the frame
        // being analyzed is exactly the one the user saw on screen.
        const tempCanvas = document.createElement('canvas');
        tempCanvas.width  = video.videoWidth;
        tempCanvas.height = video.videoHeight;
        tempCanvas.getContext('2d').drawImage(video, 0, 0);

        const detection = await faceapi
            .detectSingleFace(tempCanvas, new faceapi.TinyFaceDetectorOptions({
                inputSize: 320, scoreThreshold: 0.5
            }))
            .withFaceLandmarks()
            .withFaceDescriptor();

        if (!detection) {
            faceStatus.textContent =
                'No face detected in the photo. Please face the camera and try again.';
            manualCaptureInFlight = false;
            if (manualBtn) manualBtn.disabled = false;
            if (modalIsOpen) startDetectionLoop();
            return;
        }

        // ---- Find the closest enrolled match ---------------------------------
        let bestEmployee = null;
        let bestDistance = Infinity;

        for (const emp of enrolledEmployees) {
            for (const desc of emp.descriptors) {
                const d = faceapi.euclideanDistance(desc, detection.descriptor);
                if (d < bestDistance) {
                    bestDistance = d;
                    bestEmployee = emp;
                }
            }
        }

        if (!bestEmployee || bestDistance > FACE_MATCH_THRESHOLD) {
            faceStatus.textContent =
                'Face does not match any enrolled employee. Please try again.';
            manualCaptureInFlight = false;
            if (manualBtn) manualBtn.disabled = false;
            if (modalIsOpen) startDetectionLoop();
            return;
        }

        // ---- Match found: show it and hand off to performAutoSubmit --------
        showIdentifiedCard(bestEmployee, bestDistance, false);
        faceStatus.textContent = 'Recognized ' + bestEmployee.full_name + '. Confirming…';

        manualCaptureInFlight = false;
        performAutoSubmit(bestEmployee, bestDistance, detection);

    } catch (err) {
        console.error(err);
        faceStatus.textContent = 'An error occurred while analyzing the photo. Please try again.';
        manualCaptureInFlight = false;
        if (manualBtn) manualBtn.disabled = false;
        if (modalIsOpen) startDetectionLoop();
    }
}

/* =========================================================================
   Submit-time validation (safety net — same as before)
   ========================================================================= */

function prepareAttendanceSubmission() {
    if (document.getElementById('face_verified').value !== '1') {
        alert('Facial recognition must succeed before attendance can be recorded.');
        return false;
    }
    if (!document.getElementById('attendanceEmployeeEmail').value) {
        alert('No employee identified.');
        return false;
    }
    if (!document.getElementById('captured_photo').value) {
        alert('Please capture a photo.');
        return false;
    }
    if (!document.getElementById('latitude').value ||
        !document.getElementById('longitude').value) {
        alert('Location is required. Please allow location access and try again.');
        return false;
    }
    stopAttendanceCamera();
    return true;
}
</script>