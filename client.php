<?php
require_once 'config.php';
requireLogin();

$stmt = $pdo->query("
    SELECT *
    FROM system_settings
    ORDER BY id ASC
    LIMIT 1
");

$company_settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company_settings) {
    $company_settings = [
        'company_name' => 'Eng Roofing',
        'company_subtitle' => 'Supply and Installation',
        'company_logo' => ''
    ];
}

// Only clients can access client.php
if ($_SESSION['role'] !== 'client') {
    die('Access denied.');
}

$tab = $_GET['tab'] ?? 'dashboard';

$allowed = [
    'dashboard',
    'bookings',
    'projects',
    'payments',
    'feedback',
    'profile'
];

if (!in_array($tab, $allowed, true)) {
    $tab = 'dashboard';
}

$tabFile = __DIR__ . "/client_tabs/c_{$tab}.php";

if (!file_exists($tabFile)) {
    die('Client tab not found.');
}

/*
 * Execute the tab BEFORE sending the page headers/HTML.
 *
 * The tab files contain both:
 * - action processing (POST/GET)
 * - HTML output
 *
 * Output buffering lets the tab safely use header()
 * for redirects before anything is actually sent
 * to the browser.
 */
ob_start();

include $tabFile;

$tabContent = ob_get_clean();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($company_settings['company_name']) ?> – Client Dashboard</title>

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

    /* ---------- SIDEBAR ---------- */
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
    .fr-nav .separator { height: 1px; background: var(--fr-sidebar-border); margin: 10px 12px; }

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

    /* ---------- SHARED COMPONENTS ---------- */
    .fr-card { background: var(--fr-surface); border: 1px solid var(--fr-border); border-radius: 12px; box-shadow: var(--fr-shadow-sm); }
    .fr-card-pad { padding: 20px 22px; }

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

    .fr-stat {
        background: var(--fr-surface); border: 1px solid var(--fr-border);
        border-radius: 12px; padding: 18px 20px; box-shadow: var(--fr-shadow-sm);
        position: relative; overflow: hidden;
    }
    .fr-stat::before {
        content: ''; position: absolute; left: 0; top: 0; bottom: 0;
        width: 3px; background: var(--fr-red);
    }
    .fr-stat-label {
        font-size: 11.5px; font-weight: 600; text-transform: uppercase;
        letter-spacing: .5px; color: var(--fr-muted); margin-bottom: 8px;
    }
    .fr-stat-value { font-size: 28px; font-weight: 700; line-height: 1; color: var(--fr-text); font-variant-numeric: tabular-nums; }
    .fr-stat-note { font-size: 12px; color: var(--fr-muted); margin-top: 8px; line-height: 1.5; }

    /* ---------- STATUS BADGES ---------- */
    .fr-badge, .status-badge, .dashboard-status {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 3px 10px; border-radius: 99px;
        font-size: 11px; font-weight: 600; line-height: 1.4;
        border: 1px solid transparent; white-space: nowrap;
    }
    .fr-badge::before, .status-badge::before, .dashboard-status::before {
        content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor;
    }
    .fr-badge.pending, .fr-badge.scheduled, .status-pending, .status-scheduled,
    .dashboard-status-pending { background: #fffbeb; color: #b45309; border-color: #fde68a; }
    .fr-badge.active, .fr-badge.completed, .fr-badge.approved,
    .status-active, .status-approved, .status-completed,
    .dashboard-status-active, .dashboard-status-completed { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
    .fr-badge.sent, .fr-badge.rescheduled, .status-sent, .status-rescheduled,
    .status-changed, .dashboard-status-default { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
    .fr-badge.cancelled, .fr-badge.rejected, .status-cancelled, .status-rejected { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
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
    .muted { color: var(--fr-muted) !important; }

    /* ---------- FORMS ---------- */
    .form-control, .form-select {
        border-color: #d1d5db; font-size: 13.5px;
        border-radius: 8px; padding: 8px 12px; background: #fff;
    }
    .form-control:focus, .form-select:focus {
        border-color: var(--fr-red);
        box-shadow: 0 0 0 3px rgba(217,32,39,.1);
    }
    .form-label, .form-group > label {
        font-size: 13px; font-weight: 600; color: var(--fr-text-soft); margin-bottom: 6px;
        display: block;
    }
    .form-text, .required-note { font-size: 11.5px; color: var(--fr-muted); }
    .form-group { margin-bottom: 15px; }
    .form-group input:not([type=checkbox]):not([type=radio]),
    .form-group select, .form-group textarea {
        width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px;
        background: #fff; color: var(--fr-text); font-size: 13.5px;
        box-sizing: border-box;
    }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
        border-color: var(--fr-red); outline: none;
        box-shadow: 0 0 0 3px rgba(217,32,39,.1);
    }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    .form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }

    /* ---------- BUTTONS ---------- */
    .btn { font-size: 13px; font-weight: 600; border-radius: 8px; padding: 8px 14px; transition: all .15s; }
    .btn-primary { background: var(--fr-red); border-color: var(--fr-red); color: #fff; }
    .btn-primary:hover, .btn-primary:focus { background: var(--fr-red-dark); border-color: var(--fr-red-dark); color: #fff; }
    .btn-secondary { background: #6c757d; border-color: #6c757d; color: #fff; }
    .btn-secondary:hover, .btn-secondary:focus { background: #5a6268; border-color: #5a6268; color: #fff; }
    .btn-outline-secondary { color: #374151; border-color: #d1d5db; background: #fff; }
    .btn-outline-secondary:hover { background: #f9fafb; color: var(--fr-text); border-color: #9ca3af; }
    .btn-sm { padding: 5px 10px; font-size: 12px; border-radius: 6px; }

    /* Alias old classes */
    .btn-primary.action-btn,
    button.btn-primary { background: var(--fr-red); border-color: var(--fr-red); color: #fff; }
    .btn-secondary.action-btn,
    button.btn-secondary { background: #6c757d; border-color: #6c757d; color: #fff; }

    /* ---------- STATS GRID ---------- */
    .stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    .stat-card {
        background: var(--fr-surface);
        border: 1px solid var(--fr-border);
        border-radius: 12px;
        padding: 18px 20px;
        box-shadow: var(--fr-shadow-sm);
        text-align: center; position: relative;
    }
    .stat-card::before {
        content: ''; position: absolute; left: 0; top: 0; bottom: 0;
        width: 3px; background: var(--fr-red); border-radius: 12px 0 0 12px;
    }
    .stat-card .number, .stat-card .stat-number {
        font-size: 28px; font-weight: 700; color: var(--fr-red);
        font-variant-numeric: tabular-nums; line-height: 1; margin-bottom: 6px;
    }
    .stat-card .label, .stat-card .stat-label {
        font-size: 11.5px; font-weight: 600; text-transform: uppercase;
        letter-spacing: .5px; color: var(--fr-muted);
    }

    /* ---------- TOP BAR ---------- */
    .top-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        margin-bottom: 18px;
        flex-wrap: wrap;
    }
    .top-bar h2 { margin: 0; font-size: 20px; font-weight: 700; color: var(--fr-text); }
    .top-bar label { font-size: 13px; font-weight: 600; color: var(--fr-text-soft); }
    .top-bar select, .top-bar input[type=text] {
        padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px;
        background: #fff; color: var(--fr-text); font-size: 13.5px;
    }

    /* ---------- MESSAGES ---------- */
    .msg {
        padding: 12px 16px;
        border-radius: 8px;
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #047857;
        margin-bottom: 16px;
        font-size: 13.5px;
    }
    .msg.error, .msg.error-type { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
    .msg.warning { background: #fffbeb; border-color: #fde68a; color: #b45309; }

    /* ---------- PROGRESS ---------- */
    .fr-progress, .dashboard-big-progress, .big-progress, .client-big-progress {
        height: 10px; background: #eef0f3; border-radius: 99px; overflow: hidden;
        display: block;
    }
    .fr-progress > div, .dashboard-big-progress-fill, .big-progress-fill, .client-big-progress-fill {
        height: 100%; background: var(--fr-red); border-radius: 99px;
        transition: width .3s ease;
        display: flex; align-items: center; justify-content: flex-end;
        color: #fff; font-size: 11px; font-weight: 700; padding-right: 8px;
    }

    /* ---------- MODAL (custom, JS-compatible) ---------- */
    .ad-modal, .modal {
        display: none;
        position: fixed; inset: 0; z-index: 1055;
        background: rgba(17, 24, 39, 0.5);
        padding: 30px 18px;
        overflow-y: auto;
        justify-content: center;
        align-items: flex-start;
        backdrop-filter: blur(2px);
    }
    .ad-modal.show, .modal.show { display: flex; }

    .ad-modal-content, .modal-content {
        background: #fff;
        border-radius: 14px;
        width: 100%;
        max-width: 720px;
        margin: 20px auto;
        padding: 26px;
        position: relative;
        box-shadow: 0 20px 60px rgba(0,0,0,.2);
        color: var(--fr-text);
        border: 1px solid var(--fr-border);
    }
    .ad-modal-content h2, .modal-content h2 { font-size: 18px; font-weight: 700; margin: 0 0 16px; color: var(--fr-text); }
    .ad-modal .close, .modal .close {
        position: absolute; top: 12px; right: 18px;
        font-size: 28px; line-height: 1; color: var(--fr-muted);
        cursor: pointer; text-decoration: none;
        background: none; border: none;
    }
    .ad-modal .close:hover, .modal .close:hover { color: var(--fr-text); }
    
    /* ---------- BOOKING DATE LABELS ---------- */
    .date-column-note {
        display: block;
        margin-top: 3px;
        font-size: 10px;
        font-weight: 400;
        line-height: 1.3;
        letter-spacing: 0;
        text-transform: none;
        color: var(--fr-muted);
    }
    .date-subtext {
        color: var(--fr-muted) !important;
        font-size: 11.5px;
    }
    .date-field-note {
        color: var(--fr-muted);
        font-size: 11px;
        font-weight: 400;
        margin-left: 3px;
    }
    
    /* ===== Quotation tabs ===== */
    .quotation-tabs {
        display: flex;
        gap: 4px;
        padding: 4px;
        background: var(--fr-bg);
        border: 1px solid var(--fr-border);
        border-radius: 10px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }
    .quotation-tab {
        padding: 8px 14px;
        border-radius: 8px;
        border: 1px solid transparent;
        background: transparent;
        color: var(--fr-text-soft);
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: background .15s, border-color .15s, color .15s;
    }
    .quotation-tab:hover {
        background: #fff;
        border-color: var(--fr-border);
    }
    .quotation-tab.is-active {
        background: #fff;
        border-color: var(--fr-red);
        color: var(--fr-red);
    }
    .quotation-tab .tab-status {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .4px;
        color: var(--fr-muted);
        padding: 1px 6px;
        border-radius: 99px;
        background: rgba(0,0,0,.04);
    }
    .quotation-tab.is-active .tab-status {
        color: var(--fr-red);
        background: rgba(217,32,39,.08);
    }

    /* ---------- MOBILE NAV ---------- */
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
        .fr-content { padding: 16px 14px calc(30px + env(safe-area-inset-bottom, 0px)); }

        .table-wrap table th, .table-wrap table td { padding: 10px 12px; }
        .stat-card { padding: 14px 16px; }
        .stat-card .number, .stat-card .stat-number { font-size: 22px; }
        .form-row { grid-template-columns: 1fr; }
        .top-bar { flex-direction: column; align-items: stretch; }
        .top-bar > * { width: 100%; }
    }
    </style>
</head>
<body>

<div class="fr-layout">

    <div class="fr-backdrop" id="frBackdrop" onclick="toggleClientSidebar(false)"></div>

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
            <a href="client.php?tab=dashboard" class="<?= $tab === 'dashboard' ? 'active' : '' ?>">
                <i class="bi bi-house-door"></i><span>Dashboard</span>
            </a>
            <a href="client.php?tab=bookings" class="<?= $tab === 'bookings' ? 'active' : '' ?>">
                <i class="bi bi-calendar-check"></i><span>My Bookings</span>
            </a>
            <a href="client.php?tab=projects" class="<?= $tab === 'projects' ? 'active' : '' ?>">
                <i class="bi bi-kanban"></i><span>My Projects</span>
            </a>
            <a href="client.php?tab=payments" class="<?= $tab === 'payments' ? 'active' : '' ?>">
                <i class="bi bi-cash-coin"></i><span>Payments</span>
            </a>
            <a href="client.php?tab=feedback" class="<?= $tab === 'feedback' ? 'active' : '' ?>">
                <i class="bi bi-star-half"></i><span>Feedback</span>
            </a>
            <div class="separator"></div>
            <a href="client.php?tab=profile" class="<?= $tab === 'profile' ? 'active' : '' ?>">
                <i class="bi bi-person"></i><span>My Profile</span>
            </a>
        </nav>

        <div class="fr-user">
            <div class="name"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
            <div class="role">Client</div>
            <a href="logout.php"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
        </div>
    </aside>

    <main class="fr-main">
        <header class="fr-header">
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="fr-menu-toggle" onclick="toggleClientSidebar(true)" aria-label="Open menu">
                    <i class="bi bi-list"></i>
                </button>
                <h1><?= htmlspecialchars(ucwords(str_replace('_', ' ', $tab))) ?></h1>
            </div>
            <div class="meta">
                <span class="d-none d-md-inline"><?= date('l, F j, Y') ?></span>
                <a href="client.php?tab=profile" class="icon-btn" title="My Profile" aria-label="My Profile">
                    <i class="bi bi-person"></i>
                </a>
                <?php include_once 'notification_bell.php'; ?>
                <a href="logout.php" class="icon-btn logout" title="Logout" aria-label="Logout">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </header>

        <div class="fr-content tab-client-<?= htmlspecialchars($tab) ?>">
            <?= $tabContent ?>
        </div>
    </main>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleClientSidebar(open) {
    var sidebar = document.getElementById('frSidebar');
    var backdrop = document.getElementById('frBackdrop');
    if (!sidebar || !backdrop) return;
    if (open) { sidebar.classList.add('open'); backdrop.classList.add('show'); }
    else { sidebar.classList.remove('open'); backdrop.classList.remove('show'); }
}
</script>
</body>
</html>