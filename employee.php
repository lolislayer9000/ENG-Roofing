<?php
require_once 'config.php';
requireLogin();

if (($_SESSION['role'] ?? '') !== 'employee') {
    die('Access denied.');
}

$stmt = $pdo->query("SELECT * FROM system_settings ORDER BY id ASC LIMIT 1");
$company_settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company_settings) {
    $company_settings = [
        'company_name' => 'Eng Roofing',
        'company_subtitle' => 'Supply and Installation',
        'company_logo' => ''
    ];
}

$tab = $_GET['tab'] ?? 'dashboard';
$allowed = ['dashboard', 'attendance', 'payroll', 'projects'];
if (!in_array($tab, $allowed, true)) {
    $tab = 'dashboard';
}

$tabFile = __DIR__ . "/employee_tabs/e_{$tab}.php";
if (!file_exists($tabFile)) {
    die('Employee tab not found.');
}

ob_start();
include $tabFile;
$tabContent = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($company_settings['company_name']) ?> – Employee Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <style>
    :root {
        --fr-red: #d92027;
        --fr-red-dark: #b0161c;
        --fr-red-soft: #fef2f2;
        --fr-red-bright: #ef4444;
        --fr-bg: #f6f7f9;
        --fr-surface: #ffffff;
        --fr-border: #e5e7eb;
        --fr-border-soft: #f0f1f3;
        --fr-text: #111827;
        --fr-text-soft: #374151;
        --fr-muted: #6b7280;
        --fr-shadow-sm: 0 1px 2px rgba(16,24,40,.05);

        --fr-sidebar-bg: #1a1d21;
        --fr-sidebar-hover: #24282e;
        --fr-sidebar-border: #2b2f35;
        --fr-sidebar-text: #9da2a8;
        --fr-sidebar-text-bright: #f3f4f6;
    }

    * { -webkit-tap-highlight-color: transparent; }
    html, body { height: 100%; }
    body {
        background: var(--fr-bg);
        color: var(--fr-text);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        font-size: 14px;
        margin: 0;
        -webkit-font-smoothing: antialiased;
    }

    .fr-layout { display: flex; min-height: 100vh; }

    /* ---------- SIDEBAR (DARK) ---------- */
    .fr-sidebar {
        width: 248px;
        background: var(--fr-sidebar-bg);
        border-right: 1px solid var(--fr-sidebar-border);
        display: flex; flex-direction: column;
        padding: 22px 0 16px;
        position: sticky; top: 0;
        height: 100vh; flex-shrink: 0;
        overflow-y: auto;
    }
    .fr-brand {
        padding: 0 20px 22px;
        display: flex; align-items: center; gap: 12px;
        border-bottom: 1px solid var(--fr-sidebar-border);
        margin-bottom: 14px;
    }
    .fr-brand-logo {
        width: 44px; height: 44px; border-radius: 10px;
        object-fit: contain;
        background: rgba(217,32,39,.15);
        border: 1px solid rgba(217,32,39,.25);
        padding: 4px; flex-shrink: 0;
    }
    .fr-brand-name { font-size: 15px; font-weight: 700; color: var(--fr-sidebar-text-bright); line-height: 1.2; }
    .fr-brand-name small { display: block; font-size: 11px; color: var(--fr-sidebar-text); font-weight: 500; margin-top: 2px; }

    .fr-nav { display: flex; flex-direction: column; gap: 2px; padding: 0 12px; flex: 1; }
    .fr-nav a {
        display: flex; align-items: center; gap: 10px;
        padding: 10px 14px 10px 11px; border-radius: 8px;
        color: var(--fr-sidebar-text); text-decoration: none;
        font-weight: 500; font-size: 13.5px;
        transition: background .15s, color .15s;
        border-left: 3px solid transparent;
    }
    .fr-nav a:hover { background: var(--fr-sidebar-hover); color: var(--fr-sidebar-text-bright); }
    .fr-nav a.active {
        background: rgba(217,32,39,.15);
        color: #ffffff; font-weight: 600;
        border-left-color: var(--fr-red-bright);
    }
    .fr-nav a.active .bi { color: var(--fr-red-bright); }
    .fr-nav a .bi { font-size: 16px; width: 18px; text-align: center; }

    .fr-user {
        margin: 16px 12px 0; padding: 16px 20px 0;
        border-top: 1px solid var(--fr-sidebar-border);
        display: flex; flex-direction: column;
    }
    .fr-user .name { font-weight: 600; font-size: 13px; color: var(--fr-sidebar-text-bright); }
    .fr-user .role { font-size: 10.5px; color: var(--fr-red-bright); font-weight: 700; text-transform: uppercase; letter-spacing: .5px; margin-top: 2px; }
    .fr-user a { font-size: 12.5px; color: var(--fr-sidebar-text); text-decoration: none; margin-top: 8px; display: inline-flex; align-items: center; transition: color .15s; }
    .fr-user a:hover { color: var(--fr-red-bright); }

    /* ---------- MAIN ---------- */
    .fr-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }

    .fr-header {
        background: var(--fr-surface);
        border-bottom: 1px solid var(--fr-border);
        padding: 16px 28px;
        display: flex; align-items: center; justify-content: space-between;
        gap: 16px; position: sticky; top: 0; z-index: 50;
    }
    .fr-header h1 { font-size: 17px; font-weight: 700; margin: 0; color: var(--fr-text); }
    .fr-header .meta { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--fr-muted); }
    .fr-header .icon-btn {
        width: 36px; height: 36px; border-radius: 8px;
        display: inline-flex; align-items: center; justify-content: center;
        color: var(--fr-muted); text-decoration: none; border: none; background: none;
        transition: background .15s, color .15s;
    }
    .fr-header .icon-btn:hover { background: #f3f4f6; color: var(--fr-text); }
    .fr-header .icon-btn.logout:hover { color: var(--fr-red); background: var(--fr-red-soft); }

    .fr-content { padding: 24px 28px 40px; flex: 1; min-width: 0; }

    /* ---------- CARDS ---------- */
    .fr-card, .section-card {
        background: var(--fr-surface);
        border: 1px solid var(--fr-border);
        border-radius: 12px;
        box-shadow: var(--fr-shadow-sm);
        padding: 20px 22px;
        margin-bottom: 18px;
    }

    .fr-section-title {
        font-size: 11px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .8px; color: var(--fr-muted);
        margin: 0 0 14px;
        display: flex; align-items: center; gap: 10px;
    }
    .fr-section-title::before {
        content: ''; width: 3px; height: 14px;
        background: var(--fr-red); border-radius: 2px; flex-shrink: 0;
    }

    /* Welcome strip */
    .fr-welcome {
        background: var(--fr-surface);
        border: 1px solid var(--fr-border);
        border-left: 4px solid var(--fr-red);
        border-radius: 12px;
        padding: 20px 22px;
        margin-bottom: 18px;
    }
    .fr-welcome h2 { margin: 0 0 4px; font-size: 22px; font-weight: 700; color: var(--fr-text); }
    .fr-welcome p { margin: 0; font-size: 13.5px; color: var(--fr-muted); }

    /* ---------- STATS ---------- */
    .stats, .fr-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    .stat-card, .fr-stat {
        background: var(--fr-surface);
        border: 1px solid var(--fr-border);
        border-radius: 12px;
        padding: 18px 20px;
        box-shadow: var(--fr-shadow-sm);
        position: relative;
        text-align: center;
    }
    .stat-card::before {
        content: ''; position: absolute; left: 0; top: 0; bottom: 0;
        width: 3px; background: var(--fr-red); border-radius: 12px 0 0 12px;
    }
    .stat-card .number, .fr-stat-number {
        font-size: 28px; font-weight: 700; color: var(--fr-red);
        font-variant-numeric: tabular-nums; line-height: 1.1; margin-bottom: 6px;
    }
    .stat-card .label, .fr-stat-label {
        font-size: 11.5px; font-weight: 600; text-transform: uppercase;
        letter-spacing: .5px; color: var(--fr-muted);
    }

    /* ---------- STATUS BADGES ---------- */
    .status-badge {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 3px 10px; border-radius: 99px;
        font-size: 11px; font-weight: 600; line-height: 1.4;
        border: 1px solid transparent; white-space: nowrap;
    }
    .status-badge::before {
        content: ''; width: 5px; height: 5px;
        border-radius: 50%; background: currentColor;
    }
    .status-pending, .status-for-review { background: #fffbeb; color: #b45309; border-color: #fde68a; }
    .status-active, .status-approved, .status-completed, .status-present { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
    .status-sent, .status-rescheduled, .status-changed, .status-released { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
    .status-cancelled, .status-late { background: #fffbeb; color: #b45309; border-color: #fde68a; }
    .status-rejected, .status-absent { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
    .status-unpaid { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
    .status-fully-paid, .status-down-payment-paid { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }

    /* ---------- TABLES ---------- */
    .table-wrap {
        background: var(--fr-surface);
        border: 1px solid var(--fr-border);
        border-radius: 12px;
        overflow-x: auto;
        box-shadow: var(--fr-shadow-sm);
    }
    .table-wrap table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    .table-wrap table th {
        text-align: left; font-size: 11px; font-weight: 700;
        letter-spacing: .5px; text-transform: uppercase;
        color: var(--fr-muted); padding: 12px 18px;
        border-bottom: 1px solid var(--fr-border);
        background: #fafbfc; white-space: nowrap;
    }
    .table-wrap table td {
        padding: 14px 18px;
        border-bottom: 1px solid var(--fr-border-soft);
        color: var(--fr-text); vertical-align: middle;
    }
    .table-wrap table tbody tr:last-child td { border-bottom: none; }
    .table-wrap table tbody tr:hover { background: #fafbfc; }
    .table-wrap table tfoot th {
        background: #fafbfc;
        border-top: 2px solid var(--fr-border);
        padding: 12px 18px;
        color: var(--fr-text);
        font-size: 13px;
        text-transform: none;
        letter-spacing: 0;
    }

    /* ---------- DETAIL GRID ---------- */
    .detail-grid, .fr-detail-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }
    .detail-item, .fr-detail-item {
        background: var(--fr-bg);
        border: 1px solid var(--fr-border);
        border-radius: 8px;
        padding: 12px 14px;
    }
    .detail-item .label, .fr-detail-item .label {
        display: block;
        font-size: 10.5px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .3px; color: var(--fr-muted);
        margin-bottom: 5px;
    }
    .detail-item .value, .fr-detail-item .value {
        display: block;
        color: var(--fr-text); font-weight: 600;
        font-size: 13.5px; word-break: break-word;
    }

    /* ---------- PROGRESS ---------- */
    .big-progress, .fr-progress {
        height: 22px;
        background: #eef0f3;
        border-radius: 99px;
        overflow: hidden;
        display: block;
    }
    .big-progress-fill, .fr-progress > div {
        height: 100%;
        background: var(--fr-red);
        color: #fff;
        display: flex; align-items: center; justify-content: flex-end;
        padding-right: 8px;
        font-size: 11px; font-weight: 700;
        border-radius: 99px;
        transition: width .3s ease;
    }

    /* ---------- BUTTONS ---------- */
    .btn { font-size: 13px; font-weight: 600; border-radius: 8px; padding: 8px 14px; transition: all .15s; }
    .btn-primary, .btn-primary.action-btn { background: var(--fr-red); border-color: var(--fr-red); color: #fff; }
    .btn-primary:hover, .btn-primary:focus { background: var(--fr-red-dark); border-color: var(--fr-red-dark); color: #fff; }
    .btn-secondary, .btn-secondary.action-btn { background: #6c757d; border-color: #6c757d; color: #fff; }
    .btn-secondary:hover, .btn-secondary:focus { background: #5a6268; border-color: #5a6268; color: #fff; }
    .btn-outline-secondary { color: #374151; border-color: #d1d5db; background: #fff; }
    .btn-outline-secondary:hover { background: #f9fafb; color: var(--fr-text); border-color: #9ca3af; }
    .btn-sm { padding: 5px 10px; font-size: 12px; border-radius: 6px; }

    /* ---------- TOP BAR ---------- */
    .top-bar {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 14px;
        margin-bottom: 18px;
        flex-wrap: wrap;
    }
    .top-bar h2 { margin: 0; font-size: 20px; font-weight: 700; color: var(--fr-text); }
    .top-bar h3 { margin: 0 0 4px; font-size: 17px; font-weight: 700; color: var(--fr-text); }
    .top-bar p { margin: 0; font-size: 13px; color: var(--fr-muted); }
    .top-bar label { font-size: 13px; font-weight: 600; color: var(--fr-text-soft); }
    .top-bar input[type=date], .top-bar input[type=text] {
        padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px;
        background: #fff; color: var(--fr-text); font-size: 13.5px;
    }

    /* ---------- MESSAGES ---------- */
    .msg {
        padding: 12px 16px; border-radius: 8px;
        background: #ecfdf5; border: 1px solid #a7f3d0; color: #047857;
        margin-bottom: 16px; font-size: 13.5px;
    }
    .msg.error { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }

    /* ---------- MOBILE DRAWER ---------- */
    .fr-menu-toggle { display: none; }

    @media (max-width: 768px) {
        html, body { display: block; width: 100%; max-width: 100vw; overflow-x: hidden; }

        .fr-sidebar {
            position: fixed; top: 0; bottom: 0; left: 0;
            height: 100vh; width: 280px; max-width: 82vw;
            transform: translateX(-100%);
            transition: transform .25s ease;
            z-index: 1100;
            box-shadow: 4px 0 24px rgba(0,0,0,.5);
        }
        .fr-sidebar.open { transform: translateX(0); }

        .fr-backdrop {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 1090;
        }
        .fr-backdrop.show { display: block; }

        .fr-menu-toggle {
            display: flex; align-items: center; justify-content: center;
            width: 40px; height: 40px; padding: 0;
            border: 1px solid var(--fr-border); background: #fff; border-radius: 8px;
            cursor: pointer; flex-shrink: 0;
        }
        .fr-menu-toggle .bi { font-size: 20px; color: var(--fr-text); }

        .fr-main { width: 100%; max-width: 100vw; min-width: 0; }
        .fr-header { padding: 12px 16px; flex-wrap: wrap; }
        .fr-header h1 { font-size: 15px; }
        .fr-header .meta { font-size: 11px; }
        .fr-content { padding: 16px 14px 30px; }

        .table-wrap table th, .table-wrap table td { padding: 10px 12px; }
        .stat-card { padding: 14px 16px; }
        .stat-card .number, .fr-stat-number { font-size: 22px; }
        .detail-grid { grid-template-columns: 1fr; }
        .top-bar { flex-direction: column; align-items: stretch; }
        .top-bar > * { width: 100%; }
    }
    </style>
</head>
<body>

<div class="fr-layout">

    <div class="fr-backdrop" id="frBackdrop" onclick="toggleEmployeeSidebar(false)"></div>

    <aside class="fr-sidebar" id="frSidebar">
        <div class="fr-brand">
            <?php if (!empty($company_settings['company_logo'])): ?>
                <img src="<?= htmlspecialchars($company_settings['company_logo']) ?>" alt="Logo" class="fr-brand-logo">
            <?php else: ?>
                <div class="fr-brand-logo d-flex align-items-center justify-content-center" style="color: var(--fr-red-bright); font-weight: 800;">ENG</div>
            <?php endif; ?>
            <div class="fr-brand-name">
                <?= htmlspecialchars($company_settings['company_name']) ?>
                <?php if (!empty($company_settings['company_subtitle'])): ?>
                    <small><?= htmlspecialchars($company_settings['company_subtitle']) ?></small>
                <?php endif; ?>
            </div>
        </div>

        <nav class="fr-nav">
            <a href="employee.php?tab=dashboard" class="<?= $tab === 'dashboard' ? 'active' : '' ?>">
                <i class="bi bi-house-door"></i><span>Dashboard</span>
            </a>
            <a href="employee.php?tab=attendance" class="<?= $tab === 'attendance' ? 'active' : '' ?>">
                <i class="bi bi-clock-history"></i><span>Attendance</span>
            </a>
            <a href="employee.php?tab=projects" class="<?= $tab === 'projects' ? 'active' : '' ?>">
                <i class="bi bi-kanban"></i><span>Projects</span>
            </a>
            <a href="employee.php?tab=payroll" class="<?= $tab === 'payroll' ? 'active' : '' ?>">
                <i class="bi bi-cash-coin"></i><span>Payroll</span>
            </a>
        </nav>

        <div class="fr-user">
            <div class="name"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
            <div class="role">Employee</div>
            <a href="logout.php"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
        </div>
    </aside>

    <main class="fr-main">
        <header class="fr-header">
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="fr-menu-toggle" onclick="toggleEmployeeSidebar(true)" aria-label="Open menu">
                    <i class="bi bi-list"></i>
                </button>
                <h1><?= htmlspecialchars(ucwords(str_replace('_', ' ', $tab))) ?></h1>
            </div>
            <div class="meta">
                <span class="d-none d-md-inline"><?= date('l, F j, Y') ?></span>
                <?php include_once 'notification_bell.php'; ?>
                <a href="logout.php" class="icon-btn logout" title="Logout" aria-label="Logout">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </header>

        <div class="fr-content tab-employee-<?= htmlspecialchars($tab) ?>">
            <?= $tabContent ?>
        </div>
    </main>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleEmployeeSidebar(open) {
    var sidebar = document.getElementById('frSidebar');
    var backdrop = document.getElementById('frBackdrop');
    if (!sidebar || !backdrop) return;
    if (open) { sidebar.classList.add('open'); backdrop.classList.add('show'); }
    else { sidebar.classList.remove('open'); backdrop.classList.remove('show'); }
}
</script>
</body>
</html>