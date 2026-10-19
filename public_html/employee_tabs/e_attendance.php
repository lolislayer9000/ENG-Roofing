<?php
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { die('Invalid employee account.'); }

$stmt = $pdo->prepare("SELECT
    a.attendance_date, a.time_in, a.time_out, a.status,
    p.project_name
    FROM attendance a
    LEFT JOIN projects p ON p.id = a.project_id
    WHERE a.employee_id = ?
    ORDER BY a.attendance_date DESC, a.time_in DESC");
$stmt->execute([$userId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function employeeAttendanceHours($timeIn, $timeOut) {
    if (!$timeIn || !$timeOut) return 0;
    $start = new DateTime($timeIn);
    $end = new DateTime($timeOut);
    if ($end <= $start) return 0;
    return round(($end->getTimestamp() - $start->getTimestamp()) / 3600, 2);
}
?>
<div class="top-bar">
    <div>
        <h2>Attendance History</h2>
        <p>Attendance records recorded through the project attendance system.</p>
    </div>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Project</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-center py-5" style="color: var(--fr-muted);">
                <i class="bi bi-clock-history" style="font-size: 28px; opacity: .4; display: block; margin-bottom: 8px;"></i>
                No attendance records yet.
            </td></tr>
        <?php else: foreach ($rows as $row):
            $status = $row['time_in'] ? ($row['status'] ?: 'Present') : 'Absent';
            $statusClass = strtolower($status);
            $hours = employeeAttendanceHours($row['time_in'], $row['time_out']);
        ?>
            <tr>
                <td><?= htmlspecialchars(date('M d, Y', strtotime($row['attendance_date']))) ?></td>
                <td><?= $row['time_in'] ? htmlspecialchars(date('h:i A', strtotime($row['time_in']))) : '—' ?></td>
                <td><?= $row['time_out'] ? htmlspecialchars(date('h:i A', strtotime($row['time_out']))) : '—' ?></td>
                <td><?= $hours > 0 ? number_format($hours, 2) . ' hrs' : '—' ?></td>
                <td><?= htmlspecialchars($row['project_name'] ?? '—') ?></td>
                <td><span class="status-badge status-<?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($status) ?></span></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
