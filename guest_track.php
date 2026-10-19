<?php
// REVISION: Read-only guest tracking. A private token authorizes exactly one booking.
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__ . '/config.php';

$token = (string)($_GET['token'] ?? '');
$booking = null;
$inspection = null;
$projects = [];

// Load the booking authorized by this private link.
if (preg_match('/^[a-f0-9]{64}$/D', $token)) {
    $stmt = $pdo->prepare('
        SELECT b.*
        FROM guest_tracking_links g JOIN bookings b ON b.id = g.booking_id
        WHERE g.token = ? AND g.revoked_at IS NULL LIMIT 1
    ');
    $stmt->execute([$token]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$booking) {
    http_response_code(404);
}
if ($booking) {
    $stmt = $pdo->prepare('
        SELECT inspection_id, inspection_date, status
        FROM site_inspections
        WHERE booking_id = ?
        ORDER BY id DESC LIMIT 1
    ');
    $stmt->execute([$booking['booking_id']]);
    $inspection = $stmt->fetch(PDO::FETCH_ASSOC);
    // REVISION: Follow only explicit booking/inspection/quotation links, never client_id alone.
    $stmt = $pdo->prepare('
        SELECT DISTINCT p.id, p.project_id, p.project_name, p.status, p.progress, p.start_date, p.expected_end_date
        FROM projects p
        WHERE p.id = ? OR p.id IN (
            SELECT q.project_id
            FROM quotations q
            JOIN site_inspections si ON si.id = q.inspection_id
            WHERE si.booking_id = ? AND q.project_id IS NOT NULL
        )
    ');
    $stmt->execute([(int)($booking['project_id'] ?? 0), $booking['booking_id']]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Escape dynamic content before displaying it.
function gtEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Format saved dates for the client.
function gtDate($value): string
{
    return $value && strtotime($value) !== false
        ? date('M d, Y', strtotime($value))
        : 'Not set yet';
}
?>

<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>ENG Roofing — Track Your Project</title>
        <!-- REVISION: Shared guest branding; no changes to project/feedback rules. -->
        <link rel="stylesheet" href="assets/guest-pages.css?v=1">
    </head>
    <body>
        <header>
            <div class="brand">
                <img class="brand-logo" src="assets/eng-roofing-logo.jpg" alt="ENG Roofing logo" width="76" height="76">
                <div class="brand-copy">
                    <strong>ENG ROOFING</strong>
                    <small>Roofing Supply and Installation</small>
                    <small>Booking &amp; Project Tracking</small>
                </div>
            </div>
        </header>
        <main>
            <?php if (!$booking): ?>
                <section class="card">
                    <h1>Tracking link unavailable</h1>
                    <p>Please use the private tracking link in your booking email, or contact ENG Roofing for a replacement.</p>
                    <a href="login.php">Sign in</a>
                </section>
            <?php else: ?>
                <section class="card">
                    <p class="muted">
                        <?= gtEscape($booking['booking_id']) ?>
                    </p>
                    <h1>Your booking</h1>
                    <span class="badge">
                        <?= gtEscape(ucfirst($booking['status'])) ?>
                    </span>
                    <div class="grid">
                        <p>
                            <strong>Service</strong>
                            <br>
                            <?= gtEscape(ucwords(strtolower($booking['service_type']))) ?>
                        </p>
                        <p>
                            <strong>Preferred inspection</strong>
                            <br>
                            <?= gtEscape(gtDate($booking['preferred_inspection_date'] ?? null)) ?> <?= gtEscape($booking['preferred_schedule'] ?? '') ?>
                        </p>
                    </div>
                    <?php if (!empty($booking['is_guest']) && empty($booking['guest_email_verified'])): ?>
                        <p>Please confirm your email using the separate confirmation link in your booking email.</p>
                    <?php endif ?>
                    <?php if ($inspection): ?>
                        <p>
                            <strong>Site inspection:</strong> <?= gtEscape($inspection['status']) ?>
                            <br>
                            <strong>Inspection date:</strong> <?= gtEscape(gtDate($inspection['inspection_date'])) ?>
                        </p>
                    <?php endif ?>
                </section>
                <?php if (!$projects): ?>
                    <section class="card">
                        <h2>Project not started yet</h2>
                        <p>Your booking is being processed. Project details will appear here once a project is created and linked to this booking.</p>
                    </section>
                <?php endif ?>
                <?php foreach ($projects as $project): ?>
                    <?php $progress = max(0, min(100, (int)$project['progress'])); ?>
                    <section class="card">
                        <p class="muted">
                            <?= gtEscape($project['project_id']) ?>
                        </p>
                        <h2>
                            <?= gtEscape($project['project_name']) ?>
                        </h2>
                        <span class="badge">
                            <?= gtEscape(ucfirst($project['status'])) ?>
                        </span>
                        <p>
                            <strong>Project progress: <?= $progress ?>%</strong>
                        </p>
                        <div class="bar" role="progressbar" aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100">
                            <div style="width:<?= $progress ?>%">
                            </div>
                        </div>
                        <div class="grid">
                            <p>
                                <strong>Start date</strong>
                                <br>
                                <?= gtEscape(gtDate($project['start_date'])) ?>
                            </p>
                            <p>
                                <strong>Estimated completion</strong>
                                <br>
                                <?= gtEscape(gtDate($project['expected_end_date'])) ?>
                            </p>
                        </div>
                        <h3>Tasks</h3>
                        <ul>
                            <?php $tasks = getProjectTasks($pdo, (int)$project['id']); ?>
                            <?php foreach ($tasks as $task): ?>
                                <li>
                                    <span class="<?= $task['is_completed'] ? 'done' : 'muted' ?>">
                                        <?= $task['is_completed'] ? '✓ Completed' : '○ Pending' ?>
                                    </span>
                                    <br>
                                    <?= gtEscape($task['description']) ?>
                                </li>
                            <?php endforeach ?>
                            <?php if (!$tasks): ?>
                                <li>No tasks have been assigned yet.</li>
                            <?php endif ?>
                        </ul>
                    </section>
                <?php endforeach ?>
                <section class="card">
                    <h2>Need to interact with the admin?</h2>
                    <p>You can keep tracking here without an account. Sign in or create an account for the client portal.</p>
                    <a class="button" href="login.php">Sign In / Create Account</a>
                    <p class="muted">Keep this link private. Anyone with the link can view this booking's progress. Refresh this page for the latest updates.</p>
                </section>
            <?php endif ?>
        </main>
        <footer>ENG Roofing · Roofing Supply and Installation</footer>
    </body>
</html>
