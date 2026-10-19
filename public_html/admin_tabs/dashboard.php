<?php
// admin_tabs/dashboard.php
// Included inside admin.php.

$today = date('Y-m-d');

// ---------------------------------------------------------
// Statistics
// ---------------------------------------------------------
$stmt = $pdo->query("SELECT COUNT(*) FROM projects");
$totalProjects = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM projects WHERE status='active'");
$activeProjects = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM projects WHERE status='completed'");
$completedProjects = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status='pending'");
$pendingBookings = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM payments WHERE remaining > 0");
$pendingPayments = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='employee' AND status='active'");
$activeEmployees = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT employee_id)
    FROM attendance
    WHERE attendance_date = ? AND time_in IS NOT NULL
");
$stmt->execute([$today]);
$timedInToday = (int)$stmt->fetchColumn();

// ---------------------------------------------------------
// Project status chart
// ---------------------------------------------------------
$statusCounts = ['active'=>0,'pending'=>0,'completed'=>0];

$stmt = $pdo->query("
    SELECT status, COUNT(*) AS cnt
    FROM projects
    WHERE status IN ('active','pending','completed')
    GROUP BY status
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (isset($statusCounts[$row['status']])) {
        $statusCounts[$row['status']] = (int)$row['cnt'];
    }
}

$pieData = [
    'labels' => ['Active', 'Pending', 'Completed'],
    'values' => [
        $statusCounts['active'],
        $statusCounts['pending'],
        $statusCounts['completed']
    ],
    'colors' => ['#28a745', '#ffc107', '#17a2b8']
];

// ---------------------------------------------------------
// Service type chart
// ---------------------------------------------------------
$stmt = $pdo->query("
    SELECT service_type, COUNT(*) AS cnt
    FROM projects
    GROUP BY service_type
");

$serviceLabels = [];
$serviceValues = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $serviceLabels[] = ucfirst($row['service_type']);
    $serviceValues[] = (int)$row['cnt'];
}

if (empty($serviceLabels)) {
    $serviceLabels = ['No Projects'];
    $serviceValues = [1];
}

$donutData = [
    'labels' => $serviceLabels,
    'values' => $serviceValues
];

// ---------------------------------------------------------
// Financial summary
// ---------------------------------------------------------
$stmt = $pdo->query("
    SELECT
        COALESCE(SUM(paid),0) AS total_revenue,
        COALESCE(SUM(remaining),0) AS outstanding
    FROM payments
");
$paymentSummary = $stmt->fetch(PDO::FETCH_ASSOC);

$totalRevenue = (float)$paymentSummary['total_revenue'];
$outstanding = (float)$paymentSummary['outstanding'];
$contractValue = $totalRevenue + $outstanding;

$stmt = $pdo->query("
    SELECT COALESCE(SUM(net_pay),0)
    FROM payroll_records
    WHERE status='Released'
");
$totalPayrollReleased = (float)$stmt->fetchColumn();

// ---------------------------------------------------------
// Average feedback
// ---------------------------------------------------------
$stmt = $pdo->query("
    SELECT AVG(
        (
            installation_rating +
            service_rating +
            timeliness_rating +
            professionalism_rating
        ) / 4
    )
    FROM feedback
");
$avgFeedback = (float)$stmt->fetchColumn();

// ---------------------------------------------------------
// Active / pending projects
// ---------------------------------------------------------
$stmt = $pdo->query("
    SELECT
        p.project_id,
        p.project_name,
        p.service_type,
        p.progress,
        p.status,
        u.full_name AS client_name
    FROM projects p
    LEFT JOIN users u ON p.client_id=u.id
    WHERE p.status IN ('active','pending')
    ORDER BY
        CASE p.status
            WHEN 'active' THEN 1
            WHEN 'pending' THEN 2
            ELSE 3
        END,
        p.start_date ASC,
        p.created_at DESC
    LIMIT 20
");
$projectsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------
// Recent bookings
// ---------------------------------------------------------
$stmt = $pdo->query("
    SELECT
        client_full_name,
        service_type,
        preferred_inspection_date,
        created_at
    FROM bookings
    ORDER BY created_at DESC
    LIMIT 5
");
$recentBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Feedback stars
$fullStars = (int)floor($avgFeedback);
$halfStar = ($avgFeedback - $fullStars) >= 0.5;
$emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
?>

<?php if (!empty($_GET['msg'])): ?>
<div class="alert alert-success d-flex align-items-center" role="alert">
    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($_GET['msg']) ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="stats">
    <div class="stat-card"><div class="number"><?= $totalProjects ?></div><div class="label">Total Projects</div></div>
    <div class="stat-card"><div class="number"><?= $activeProjects ?></div><div class="label">Active Projects</div></div>
    <div class="stat-card"><div class="number"><?= $completedProjects ?></div><div class="label">Completed</div></div>
    <div class="stat-card"><div class="number"><?= $pendingBookings ?></div><div class="label">Pending Bookings</div></div>
    <div class="stat-card"><div class="number"><?= $pendingPayments ?></div><div class="label">Pending Payments</div></div>
    <div class="stat-card"><div class="number"><?= $activeEmployees ?></div><div class="label">Active Employees</div></div>
    <div class="stat-card"><div class="number"><?= $timedInToday ?></div><div class="label">Timed In Today</div></div>
</div>

<!-- Charts -->
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="fr-card">
            <div class="fr-card-pad text-center">
                <h3 class="fr-section-title" style="justify-content: center;">Project Status</h3>
                <div style="width: 210px; height: 210px; margin: 0 auto;">
                    <canvas id="projectStatusPie"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="fr-card">
            <div class="fr-card-pad text-center">
                <h3 class="fr-section-title" style="justify-content: center;">Service Types</h3>
                <div style="width: 210px; height: 210px; margin: 0 auto;">
                    <canvas id="serviceTypeDonut"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Financial Overview -->
<div class="fr-card mb-3">
    <div class="fr-card-pad">
        <h3 class="fr-section-title">Financial Overview</h3>
        <div class="row g-3">
            <div class="col-md-3 col-6">
                <div class="fr-stat">
                    <div class="fr-stat-label">Total Revenue</div>
                    <div class="fr-stat-value">₱<?= number_format($totalRevenue, 2) ?></div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="fr-stat">
                    <div class="fr-stat-label">Outstanding</div>
                    <div class="fr-stat-value">₱<?= number_format($outstanding, 2) ?></div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="fr-stat">
                    <div class="fr-stat-label">Contract Value</div>
                    <div class="fr-stat-value">₱<?= number_format($contractValue, 2) ?></div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="fr-stat">
                    <div class="fr-stat-label">Payroll Released</div>
                    <div class="fr-stat-value">₱<?= number_format($totalPayrollReleased, 2) ?></div>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-between align-items-center pt-3 mt-3" style="border-top: 1px solid var(--fr-border);">
            <span style="color: var(--fr-muted); font-weight: 600;">Average Feedback Rating</span>
            <strong style="color: var(--fr-red); font-size: 16px;">
                <?= number_format($avgFeedback, 2) ?> / 5
                <?php if ($avgFeedback > 0): ?>
                    <span style="color: var(--fr-red); letter-spacing: 2px; margin-left: 6px;">
                        <?= str_repeat('★', $fullStars) ?><?= $halfStar ? '★' : '' ?><?= str_repeat('☆', $emptyStars) ?>
                    </span>
                <?php endif; ?>
            </strong>
        </div>
    </div>
</div>

<!-- Active / Pending Projects -->
<div class="fr-card mb-3">
    <div class="fr-card-pad">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="fr-section-title mb-0">Active &amp; Pending Projects</h3>
            <a href="admin.php?tab=projects" class="btn btn-outline-secondary btn-sm">View All</a>
        </div>
        <?php if (empty($projectsList)): ?>
            <div class="text-center py-4" style="color: var(--fr-muted); font-size: 13px;">No active or pending projects found.</div>
        <?php else: ?>
            <div class="table-wrap" style="box-shadow: none; border: 1px solid var(--fr-border-soft);">
                <table>
                    <thead>
                        <tr>
                            <th>Project ID</th><th>Project Name</th><th>Service</th><th>Client</th><th>Progress</th><th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($projectsList as $project): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($project['project_id']) ?></strong></td>
                            <td><?= htmlspecialchars($project['project_name']) ?></td>
                            <td><?= htmlspecialchars(ucfirst($project['service_type'])) ?></td>
                            <td><?= htmlspecialchars($project['client_name'] ?? '—') ?></td>
                            <td style="min-width: 140px;">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="fr-progress flex-grow-1" style="max-width: 90px;">
                                        <div style="width: <?= (int)$project['progress'] ?>%;"></div>
                                    </div>
                                    <span style="font-weight: 600; font-size: 12px;"><?= (int)$project['progress'] ?>%</span>
                                </div>
                            </td>
                            <td>
                                <span class="fr-badge <?= htmlspecialchars($project['status']) ?>">
                                    <?= htmlspecialchars(ucfirst($project['status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Bookings -->
<div class="fr-card">
    <div class="fr-card-pad">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="fr-section-title mb-0">Recent Bookings</h3>
            <a href="admin.php?tab=booking" class="btn btn-outline-secondary btn-sm">View All</a>
        </div>
        <?php if (empty($recentBookings)): ?>
            <div class="text-center py-4" style="color: var(--fr-muted); font-size: 13px;">No bookings yet.</div>
        <?php else: ?>
            <div class="table-wrap" style="box-shadow: none; border: 1px solid var(--fr-border-soft);">
                <table>
                    <thead>
                        <tr><th>Client Name</th><th>Service Type</th><th>Inspection Date</th><th>Booked On</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentBookings as $booking): ?>
                        <tr>
                            <td><?= htmlspecialchars($booking['client_full_name']) ?></td>
                            <td><?= htmlspecialchars(ucfirst($booking['service_type'])) ?></td>
                            <td><?= !empty($booking['preferred_inspection_date']) ? htmlspecialchars(date('M d, Y', strtotime($booking['preferred_inspection_date']))) : '—' ?></td>
                            <td><?= htmlspecialchars(date('M d, Y', strtotime($booking['created_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const pieData = <?= json_encode($pieData) ?>;
    const donutData = <?= json_encode($donutData) ?>;
    const serviceColors = ['#dc3545','#fd7e14','#007bff','#28a745','#6f42c1','#20c997','#e83e8c'];

    new Chart(document.getElementById('projectStatusPie'), {
        type: 'pie',
        data: {
            labels: pieData.labels,
            datasets: [{ data: pieData.values, backgroundColor: pieData.colors, borderWidth: 1 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            color: '#374151',
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, padding: 10, font: { size: 11 }, color: '#374151' } }
            }
        }
    });

    new Chart(document.getElementById('serviceTypeDonut'), {
        type: 'doughnut',
        data: {
            labels: donutData.labels,
            datasets: [{ data: donutData.values, backgroundColor: serviceColors.slice(0, donutData.labels.length), borderWidth: 1 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '62%',
            color: '#374151',
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, padding: 10, font: { size: 11 }, color: '#374151' } }
            }
        }
    });
});
</script>