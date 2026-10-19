<?php
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { die('Invalid employee account.'); }

function employeeDashHours($timeIn,$timeOut){if(!$timeIn||!$timeOut)return 0;$s=new DateTime($timeIn);$e=new DateTime($timeOut);if($e<=$s)return 0;return round(($e->getTimestamp()-$s->getTimestamp())/3600,2);}
function employeeDashMonday($date){$d=new DateTime($date);if((int)$d->format('N')!==1)$d->modify('monday this week');return $d->format('Y-m-d');}
function employeeDashFriday($monday){$d=new DateTime($monday);$d->modify('+4 days');return $d->format('Y-m-d');}

// Employee details
$stmt=$pdo->prepare("SELECT full_name, employee_id, daily_rate, join_date FROM users WHERE id=? AND role='employee' LIMIT 1");$stmt->execute([$userId]);$employee=$stmt->fetch(PDO::FETCH_ASSOC);if(!$employee){die('Employee account not found.');}

$today=date('Y-m-d');
$stmt=$pdo->prepare("SELECT a.*,p.project_name FROM attendance a LEFT JOIN projects p ON p.id=a.project_id WHERE a.employee_id=? AND a.attendance_date=? LIMIT 1");$stmt->execute([$userId,$today]);$todayAttendance=$stmt->fetch(PDO::FETCH_ASSOC);

// Current active assigned project. Use project completion logic similar to admin attendance.
$stmt=$pdo->prepare("SELECT p.id,p.project_name,p.service_type,p.progress,p.status,p.start_date,p.expected_end_date,f.full_name AS foreman_name,
COALESCE((SELECT si.location FROM site_inspections si WHERE si.project_id=p.id ORDER BY si.id DESC LIMIT 1),(SELECT b.project_location FROM bookings b WHERE b.project_id=p.id ORDER BY b.id DESC LIMIT 1)) AS project_address
FROM project_employees pe INNER JOIN projects p ON p.id=pe.project_id LEFT JOIN users f ON f.id=p.foreman_id
WHERE pe.employee_id=? AND p.start_date<=? AND p.status<>'cancelled' AND (COALESCE(p.completed_at,(SELECT MAX(pp.created_at) FROM project_progress_log pp WHERE pp.project_id=p.id AND pp.progress>=100),CASE WHEN p.status='completed' THEN CONCAT(p.expected_end_date,' 23:59:59') ELSE NULL END) IS NULL OR DATE(COALESCE(p.completed_at,(SELECT MAX(pp.created_at) FROM project_progress_log pp WHERE pp.project_id=p.id AND pp.progress>=100),CASE WHEN p.status='completed' THEN CONCAT(p.expected_end_date,' 23:59:59') ELSE NULL END))>=?)
ORDER BY p.status='active' DESC,p.start_date DESC LIMIT 1");
$stmt->execute([$userId,$today,$today]);$project=$stmt->fetch(PDO::FETCH_ASSOC);

// Recent attendance: up to 2 records
$stmt=$pdo->prepare("SELECT a.attendance_date,a.time_in,a.time_out,a.status,p.project_name FROM attendance a LEFT JOIN projects p ON p.id=a.project_id WHERE a.employee_id=? ORDER BY a.attendance_date DESC LIMIT 2");$stmt->execute([$userId]);$recentAttendance=$stmt->fetchAll(PDO::FETCH_ASSOC);

// Days this week out of 5: count attendance with time-in from Monday through Friday.
$weekStart=employeeDashMonday($today);$weekEnd=employeeDashFriday($weekStart);
$stmt=$pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id=? AND attendance_date BETWEEN ? AND ? AND time_in IS NOT NULL");$stmt->execute([$userId,$weekStart,$weekEnd]);$daysThisWeek=(int)$stmt->fetchColumn();

$estimatedWeeklyPay=((float)$employee['daily_rate'])*5;

// Latest payroll
$stmt=$pdo->prepare("SELECT * FROM payroll_records WHERE employee_id=? ORDER BY week_start DESC LIMIT 1");$stmt->execute([$userId]);$latestPayroll=$stmt->fetch(PDO::FETCH_ASSOC);

$latestDaily=[];
if($latestPayroll){
    $stmt=$pdo->prepare("SELECT attendance_date,time_in,time_out FROM attendance WHERE employee_id=? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date ASC");$stmt->execute([$userId,$latestPayroll['week_start'],$latestPayroll['week_end']]);
    $latestDaily=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $latestDailyMap=[];foreach($latestDaily as $d)$latestDailyMap[$d['attendance_date']]=$d;
    $latestDaily=[];$d=new DateTime($latestPayroll['week_start']);$end=new DateTime($latestPayroll['week_end']);while($d<=$end){$key=$d->format('Y-m-d');$latestDaily[]=['date'=>$key,'time_in'=>$latestDailyMap[$key]['time_in']??null,'time_out'=>$latestDailyMap[$key]['time_out']??null,'hours'=>employeeDashHours($latestDailyMap[$key]['time_in']??null,$latestDailyMap[$key]['time_out']??null)];$d->modify('+1 day');}
}
?>
<div class="fr-welcome">
    <h2>Welcome, <?= htmlspecialchars($employee['full_name']) ?></h2>
    <p>Here is your current attendance, project, and payroll overview.</p>
</div>

<div class="stats">
    <div class="stat-card">
        <div class="number"><?= $todayAttendance && !empty($todayAttendance['time_in']) ? htmlspecialchars(date('h:i A', strtotime($todayAttendance['time_in']))) : '—' ?></div>
        <div class="label">Today's Time In</div>
    </div>
    <div class="stat-card">
        <div class="number"><?= $todayAttendance && !empty($todayAttendance['time_out']) ? htmlspecialchars(date('h:i A', strtotime($todayAttendance['time_out']))) : '—' ?></div>
        <div class="label">Today's Time Out</div>
    </div>
    <div class="stat-card">
        <div class="number" style="font-size: 20px;">
            <?php if ($todayAttendance && !empty($todayAttendance['time_in'])):
                $s = $todayAttendance['status'] ?: 'Present';
                echo '<span class="status-badge status-' . strtolower($s) . '">' . htmlspecialchars($s) . '</span>';
            else: ?>
                —
            <?php endif; ?>
        </div>
        <div class="label">Today's Status</div>
    </div>
    <div class="stat-card">
        <div class="number"><?= $daysThisWeek ?> / 5</div>
        <div class="label">Days This Week</div>
    </div>
    <div class="stat-card">
        <div class="number" style="font-size: 20px;">₱<?= number_format((float)$employee['daily_rate'], 2) ?></div>
        <div class="label">Daily Rate</div>
    </div>
    <div class="stat-card">
        <div class="number" style="font-size: 20px;">₱<?= number_format($estimatedWeeklyPay, 2) ?></div>
        <div class="label">Est. Weekly Pay</div>
    </div>
</div>

<div class="section-card">
    <h3 class="fr-section-title">Assigned Project</h3>
    <?php if (!$project): ?>
        <div class="text-center py-4" style="color: var(--fr-muted); font-size: 13px; border: 1px dashed var(--fr-border); border-radius: 8px;">
            You are not currently assigned to an active project.
        </div>
    <?php else: ?>
        <div class="detail-grid mb-3">
            <div class="detail-item"><span class="label">Project Name</span><span class="value"><?= htmlspecialchars($project['project_name']) ?></span></div>
            <div class="detail-item"><span class="label">Service Type</span><span class="value"><?= htmlspecialchars(ucfirst($project['service_type'])) ?></span></div>
            <div class="detail-item"><span class="label">Project Address / Location</span><span class="value"><?= htmlspecialchars($project['project_address'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="label">Foreman</span><span class="value"><?= htmlspecialchars($project['foreman_name'] ?? '—') ?></span></div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-2">
            <strong style="font-size: 13px;">Project Progress</strong>
            <strong style="color: var(--fr-red);"><?= (int)$project['progress'] ?>%</strong>
        </div>
        <div class="big-progress"><div class="big-progress-fill" style="width: <?= (int)$project['progress'] ?>%;"><?= (int)$project['progress'] ?>%</div></div>
    <?php endif; ?>
</div>

<div class="section-card">
    <h3 class="fr-section-title">Recent Attendance</h3>
    <div class="table-wrap" style="box-shadow: none;">
        <table>
            <thead>
                <tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Project</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php if (!$recentAttendance): ?>
                    <tr><td colspan="6" class="text-center py-4" style="color: var(--fr-muted);">No attendance history yet.</td></tr>
                <?php else: foreach ($recentAttendance as $r):
                    $status = $r['time_in'] ? ($r['status'] ?: 'Present') : 'Absent';
                    $sc = strtolower($status);
                    $hours = employeeDashHours($r['time_in'], $r['time_out']);
                ?>
                    <tr>
                        <td><?= htmlspecialchars(date('M d, Y', strtotime($r['attendance_date']))) ?></td>
                        <td><?= $r['time_in'] ? htmlspecialchars(date('h:i A', strtotime($r['time_in']))) : '—' ?></td>
                        <td><?= $r['time_out'] ? htmlspecialchars(date('h:i A', strtotime($r['time_out']))) : '—' ?></td>
                        <td><?= $hours ? number_format($hours, 2) . ' hrs' : '—' ?></td>
                        <td><?= htmlspecialchars($r['project_name'] ?? '—') ?></td>
                        <td><span class="status-badge status-<?= htmlspecialchars($sc) ?>"><?= htmlspecialchars($status) ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="section-card">
    <div class="top-bar">
        <div>
            <h3>Latest Payroll</h3>
            <?php if ($latestPayroll): ?>
                <p><?= htmlspecialchars(date('M d, Y', strtotime($latestPayroll['week_start']))) ?> – <?= htmlspecialchars(date('M d, Y', strtotime($latestPayroll['week_end']))) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($latestPayroll): ?>
            <a href="employee.php?tab=payroll&week_start=<?= urlencode($latestPayroll['week_start']) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-cash-coin me-1"></i>View Payroll
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$latestPayroll): ?>
        <div class="text-center py-4" style="color: var(--fr-muted); font-size: 13px; border: 1px dashed var(--fr-border); border-radius: 8px;">
            No payroll record available yet.
        </div>
    <?php else: ?>
        <div class="table-wrap" style="box-shadow: none;">
            <table>
                <thead><tr><th>Date</th><th>Hours Worked</th><th>Payroll</th></tr></thead>
                <tbody>
                <?php foreach ($latestDaily as $d):
                    $dailyPay = ($d['hours'] / 8) * (float)$latestPayroll['daily_rate'];
                ?>
                    <tr>
                        <td><?= htmlspecialchars(date('D, M d, Y', strtotime($d['date']))) ?></td>
                        <td><?= number_format($d['hours'], 2) ?></td>
                        <td><strong>₱<?= number_format($dailyPay, 2) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2" style="text-align: right;">Weekly Net Pay</th>
                        <th style="color: var(--fr-red); font-size: 15px;">₱<?= number_format((float)$latestPayroll['net_pay'], 2) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>
