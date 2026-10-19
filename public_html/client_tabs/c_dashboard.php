<?php
// This file is included inside client.php.

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$client_id = (int)$_SESSION['user_id'];

if ($client_id <= 0) {
    die('Invalid client account.');
}

// ---------------------------------------------------------
// Dashboard statistics
// ---------------------------------------------------------

// Number of bookings submitted by this client.
$stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE client_id = ?");
$stmt->execute([$client_id]);
$booking_count = (int)$stmt->fetchColumn();

// Number of active projects.
$stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE client_id = ? AND status = 'active'");
$stmt->execute([$client_id]);
$active_project_count = (int)$stmt->fetchColumn();

// Balance Due = Total - Paid.
$stmt = $pdo->prepare("\n    SELECT\n        COALESCE(SUM(total), 0) AS total_amount,\n        COALESCE(SUM(paid), 0) AS paid_amount\n    FROM payments\n    WHERE client_id = ?\n");
$stmt->execute([$client_id]);
$payment_summary = $stmt->fetch(PDO::FETCH_ASSOC);

$total_amount = (float)($payment_summary['total_amount'] ?? 0);
$paid_amount = (float)($payment_summary['paid_amount'] ?? 0);
$balance_due = max(0, $total_amount - $paid_amount);

// Number of reviews submitted by this client.
$stmt = $pdo->prepare("SELECT COUNT(*) FROM feedback WHERE client_id = ?");
$stmt->execute([$client_id]);
$review_count = (int)$stmt->fetchColumn();

// ---------------------------------------------------------
// Fetch project progress
// ---------------------------------------------------------
// Show all non-cancelled projects, with active projects first.
$stmt = $pdo->prepare("\n    SELECT\n        p.id,\n        p.project_id,\n        p.project_name,\n        p.service_type,\n        p.progress,\n        p.status,\n        p.start_date,\n        p.expected_end_date\n    FROM projects p\n    WHERE p.client_id = ?\n      AND p.status <> 'cancelled'\n    ORDER BY\n        CASE p.status\n            WHEN 'active' THEN 1\n            WHEN 'pending' THEN 2\n            WHEN 'completed' THEN 3\n            ELSE 4\n        END,\n        p.start_date ASC,\n        p.created_at DESC\n");
$stmt->execute([$client_id]);
$project_progress = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------
// Display helper
// ---------------------------------------------------------
function clientDashboardStatus($status)
{
    switch (strtolower($status)) {
        case 'active':
            return ['label' => 'Active', 'class' => 'dashboard-status-active'];
        case 'pending':
            return ['label' => 'Pending', 'class' => 'dashboard-status-pending'];
        case 'completed':
            return ['label' => 'Completed', 'class' => 'dashboard-status-completed'];
        default:
            return ['label' => ucfirst($status), 'class' => 'dashboard-status-default'];
    }
}
?>

<div class="mb-4">
    <h2 class="fw-bold mb-1" style="font-size: 22px;">Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></h2>
    <p class="mb-0" style="color: var(--fr-muted); font-size: 13.5px;">Here is a quick overview of your roofing projects and account.</p>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="fr-stat">
            <div class="fr-stat-label">Bookings</div>
            <div class="fr-stat-value"><?= $booking_count ?></div>
            <div class="fr-stat-note">Total booking requests</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="fr-stat">
            <div class="fr-stat-label">Active Projects</div>
            <div class="fr-stat-value"><?= $active_project_count ?></div>
            <div class="fr-stat-note">Projects in progress</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="fr-stat">
            <div class="fr-stat-label">Balance Due</div>
            <div class="fr-stat-value" style="font-size: 22px;">₱<?= number_format($balance_due, 2) ?></div>
            <div class="fr-stat-note">Total ₱<?= number_format($total_amount, 2) ?> · Paid ₱<?= number_format($paid_amount, 2) ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="fr-stat">
            <div class="fr-stat-label">Reviews Submitted</div>
            <div class="fr-stat-value"><?= $review_count ?></div>
            <div class="fr-stat-note">Reviews in your account</div>
        </div>
    </div>
</div>

<div class="fr-card">
    <div class="fr-card-pad">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <h3 class="fr-section-title mb-1">Project Progress</h3>
                <p class="mb-0" style="color: var(--fr-muted); font-size: 13px;">Track the latest progress of your roofing projects.</p>
            </div>
            <a href="client.php?tab=projects" class="btn btn-outline-secondary btn-sm">View My Projects</a>
        </div>

        <?php if (empty($project_progress)): ?>
            <div class="text-center py-5" style="color: var(--fr-muted); border: 1px dashed var(--fr-border); border-radius: 8px;">
                <i class="bi bi-kanban" style="font-size: 32px; opacity: .4;"></i>
                <h4 class="mt-2 mb-1" style="color: var(--fr-text);">No Projects Yet</h4>
                <p class="mb-0" style="font-size: 13px;">Your project progress will appear here once a project has been created.</p>
            </div>
        <?php else: ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($project_progress as $project):
                    $progress = max(0, min(100, (int)$project['progress']));
                    $status = clientDashboardStatus($project['status']);
                ?>
                    <div class="p-3 rounded-3" style="border: 1px solid var(--fr-border);">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                            <div>
                                <div style="font-size: 11px; color: var(--fr-muted); font-weight: 700; letter-spacing: .3px;"><?= htmlspecialchars($project['project_id']) ?></div>
                                <div class="fw-bold" style="font-size: 15px; color: var(--fr-text);"><?= htmlspecialchars($project['project_name']) ?></div>
                            </div>
                            <span class="fr-badge <?= str_replace('dashboard-status-', '', $status['class']) ?>"><?= htmlspecialchars($status['label']) ?></span>
                        </div>

                        <div class="row g-2 mb-3" style="font-size: 12.5px;">
                            <div class="col-md-4">
                                <div style="color: var(--fr-muted); font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px;">Service</div>
                                <div class="fw-semibold" style="color: var(--fr-text-soft);"><?= htmlspecialchars(ucfirst($project['service_type'])) ?></div>
                            </div>
                            <div class="col-md-4">
                                <div style="color: var(--fr-muted); font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px;">Start Date</div>
                                <div class="fw-semibold" style="color: var(--fr-text-soft);"><?= !empty($project['start_date']) ? htmlspecialchars(date('M d, Y', strtotime($project['start_date']))) : '—' ?></div>
                            </div>
                            <div class="col-md-4">
                                <div style="color: var(--fr-muted); font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px;">Expected End</div>
                                <div class="fw-semibold" style="color: var(--fr-text-soft);"><?= !empty($project['expected_end_date']) ? htmlspecialchars(date('M d, Y', strtotime($project['expected_end_date']))) : '—' ?></div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-2" style="font-size: 12px;">
                            <span style="color: var(--fr-muted); font-weight: 600;">Project Progress</span>
                            <span class="fw-bold"><?= $progress ?>%</span>
                        </div>
                        <div class="fr-progress"><div style="width: <?= $progress ?>%;"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

