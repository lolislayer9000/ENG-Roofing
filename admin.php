<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
require_once 'config.php';
requireLogin();

if ($_SESSION['role'] !== 'admin') {
    die('Access denied.');
}


// ---------------------------------------------------------
// Load global company settings
// ---------------------------------------------------------
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
        'company_phone' => '',
        'company_email' => '',
        'company_tin' => '',
        'company_address' => '',
        'company_logo' => '',
        'down_payment_rate' => 30.00,
        'late_threshold' => '00:15:00'
    ];
}


// ---------------------------------------------------------
// Determine active tab
// ---------------------------------------------------------
$tab = $_GET['tab'] ?? 'dashboard';

$allowed = [
    'dashboard',
    'booking',
    'user_management',
    'site_inspection',
    'quotations',
    'projects',
    'payments',
    'attendance',
    'feedback',
    'reports',
    'settings'
];

if (!in_array($tab, $allowed, true)) {
    $tab = 'dashboard';
}


// ---------------------------------------------------------
// Resolve tab file
// ---------------------------------------------------------
$tabFile = __DIR__ . "/admin_tabs/{$tab}.php";

if (!file_exists($tabFile)) {
    die('Admin tab not found.');
}


// ---------------------------------------------------------
// Execute selected tab exactly once
//
// This is important because the tab files contain both:
// - action processing (POST/GET)
// - HTML output
//
// Output buffering allows actions to perform redirects using
// header() before the main page has been sent.
// ---------------------------------------------------------
ob_start();

include $tabFile;

$tabContent = ob_get_clean();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($company_settings['company_name']) ?> – Admin Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <!-- Flatpickr — used by the Booking tab's Confirmed Inspection Date -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>

    <style>
    /* =========================================================
       ADMIN THEME — LIGHT CONTENT + DARK SIDEBAR
       ========================================================= */
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
        --fr-shadow: 0 1px 3px rgba(16,24,40,.08), 0 1px 2px rgba(16,24,40,.04);

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
        display: flex;
        flex-direction: column;
        padding: 22px 0 16px;
        position: sticky;
        top: 0;
        height: 100vh;
        flex-shrink: 0;
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
        color: #ffffff;
        font-weight: 600;
        border-left-color: var(--fr-red-bright);
    }
    .fr-nav a.active .bi { color: var(--fr-red-bright); }
    .fr-nav a .bi { font-size: 16px; width: 18px; text-align: center; transition: color .15s; }
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
        width: 3px; background: var(--fr-red); opacity: 0; transition: opacity .2s;
    }
    .fr-stat:hover::before { opacity: 1; }
    .fr-stat-label {
        font-size: 11.5px; font-weight: 600; text-transform: uppercase;
        letter-spacing: .5px; color: var(--fr-muted); margin-bottom: 8px;
    }
    .fr-stat-value { font-size: 28px; font-weight: 700; line-height: 1; color: var(--fr-text); font-variant-numeric: tabular-nums; }

    /* ---------- STATUS BADGES ---------- */
    .fr-badge, .status-badge {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 3px 10px; border-radius: 99px;
        font-size: 11px; font-weight: 600; line-height: 1.4;
        border: 1px solid transparent; white-space: nowrap;
    }
    .fr-badge::before, .status-badge::before {
        content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor;
    }
    
    /* Amber — needs attention / waiting */
    .fr-badge.scheduled,
    .fr-badge.pending,
    .fr-badge.waiting-for-action,
    .fr-badge.requires-follow-up,
    .fr-badge.followup,
    .status-pending,
    .status-scheduled {
        background: #fffbeb;
        color: #b45309;
        border-color: #fde68a;
    }
    .fr-badge.waiting-for-down-payment {
        background: #fffbeb;
        color: #b45309;
        border-color: #fde68a;
    }
    
    /* Green — positive / done */
    .fr-badge.active,
    .fr-badge.completed,
    .fr-badge.approved,
    .status-active,
    .status-approved,
    .status-completed {
        background: #ecfdf5;
        color: #047857;
        border-color: #a7f3d0;
    }
    
    /* Blue — in progress / informational */
    .fr-badge.sent,
    .fr-badge.rescheduled,
    .status-sent,
    .status-rescheduled,
    .status-waiting {
        background: #eff6ff;
        color: #1d4ed8;
        border-color: #bfdbfe;
    }
    
    /* Grey — inactive / neutral */
    .fr-badge.cancelled,
    .status-cancelled {
        background: #f3f4f6;
        color: #4b5563;
        border-color: #e5e7eb;
    }
    
    /* Red — negative */
    .fr-badge.rejected,
    .fr-badge.failed,
    .status-rejected {
        background: #fef2f2;
        color: #b91c1c;
        border-color: #fecaca;
    }
    
    .fr-badge.waiting-for-admin-confirmation,
    .status-waiting-for-admin-confirmation {
        background: #f0f9ff;
        color: #0369a1;
        border-color: #bae6fd;
    }
    
    /* Payments */
    .status-fully-paid,
    .status-down-payment-paid {
        background: #ecfdf5;
        color: #047857;
        border-color: #a7f3d0;
    }
    .status-unpaid {
        background: #fef2f2;
        color: #b91c1c;
        border-color: #fecaca;
    }
    .status-changed {
        background: #eff6ff;
        color: #1d4ed8;
        border-color: #bfdbfe;
    }

    /* ---------- TABLES ---------- */
    .table-wrap {
        background: var(--fr-surface);
        border: 1px solid var(--fr-border);
        border-radius: 12px;
        overflow-x: auto;
        box-shadow: var(--fr-shadow-sm);
    }
    .table-wrap table, .fr-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    .table-wrap table th, .fr-table th {
        text-align: left; font-size: 11px; font-weight: 700;
        letter-spacing: .5px; text-transform: uppercase;
        color: var(--fr-muted); padding: 12px 18px;
        border-bottom: 1px solid var(--fr-border);
        background: #fafbfc; white-space: nowrap;
    }
    .table-wrap table td, .fr-table td {
        padding: 14px 18px;
        border-bottom: 1px solid var(--fr-border-soft);
        color: var(--fr-text); vertical-align: middle;
    }
    /* =========================================================
       STANDARD MATERIAL TEMPLATES — refined appearance
       ========================================================= */
    .template-table-wrap {
        max-height: 620px;
        overflow-y: auto;
        overflow-x: auto;
    }
    .template-table-wrap table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #fafbfc;
        box-shadow: inset 0 -1px 0 var(--fr-border);
    }
    
    .template-row td { vertical-align: middle; }
    
    /* --- Template name cell (two-line) --- */
    .template-name-product {
        font-size: 13.5px;
        font-weight: 700;
        color: var(--fr-text);
        line-height: 1.3;
        margin-bottom: 3px;
    }
    .template-name-meta {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 6px;
        font-size: 11.5px;
        color: var(--fr-muted);
        line-height: 1.5;
    }
    .template-name-meta .meta-dot {
        color: #d1d5db;
        font-weight: 700;
    }
    .template-meta-pill {
        display: inline-flex;
        align-items: center;
        padding: 1px 8px;
        border-radius: 99px;
        font-size: 10.5px;
        font-weight: 600;
        background: #f3f4f6;
        color: #4b5563;
        border: 1px solid #e5e7eb;
        line-height: 1.5;
    }
    
    /* --- Item count chip --- */
    .tmpl-count-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        padding: 4px 11px;
        border-radius: 99px;
        font-size: 11.5px;
        font-weight: 600;
        background: #f3f4f6;
        color: #4b5563;
        border: 1px solid #e5e7eb;
        cursor: pointer;
        transition: background .15s, color .15s, border-color .15s;
        line-height: 1.4;
    }
    .tmpl-count-chip:hover {
        background: #eff6ff;
        color: #1d4ed8;
        border-color: #bfdbfe;
    }
    .tmpl-count-chip .bi { font-size: 11px; opacity: .75; }
    .tmpl-count-chip.is-empty {
        background: #fafbfc;
        color: #9ca3af;
        border-color: #f0f1f3;
    }
    
    /* =========================================================
       TEMPLATE MATERIAL PICKER (modal)
       ========================================================= */
    .tmpl-pick-list {
        border: 1px solid var(--fr-border);
        border-radius: 10px;
        overflow-y: auto;
        flex: 1;
        min-height: 320px;
        max-height: 480px;
        background: #fff;
    }
    .tmpl-pick-section-title {
        position: sticky;
        top: 0;
        z-index: 1;
        padding: 7px 14px;
        background: #fafbfc;
        border-bottom: 1px solid var(--fr-border);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: var(--fr-muted);
    }
    .tmpl-pick-row {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 14px;
        border-bottom: 1px solid var(--fr-border-soft);
        transition: background .12s;
    }
    .tmpl-pick-row:hover { background: #fafbfc; }
    .tmpl-pick-row.is-checked { background: #fffafa; }
    .tmpl-pick-row.is-checked:hover { background: #fef7f7; }
    .tmpl-pick-row:last-child { border-bottom: none; }
    
    .tmpl-pick-row > label {
        flex: 1;
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        margin: 0;
        min-width: 0;
    }
    .tmpl-pick-row input[type="checkbox"] {
        flex-shrink: 0;
        width: 16px;
        height: 16px;
        accent-color: var(--fr-red);
        cursor: pointer;
        margin: 0;
    }
    .tmpl-pick-text { min-width: 0; }
    .tmpl-pick-name {
        font-size: 13px;
        font-weight: 600;
        color: var(--fr-text);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        line-height: 1.3;
    }
    .tmpl-pick-sub {
        font-size: 11px;
        color: var(--fr-muted);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        margin-top: 1px;
    }
    .tmpl-pick-rule {
        width: 175px;
        flex-shrink: 0;
        visibility: hidden;
        opacity: 0;
        transition: opacity .15s;
        font-size: 12px;
        padding: 4px 24px 4px 8px;
        height: 30px;
    }
    .tmpl-pick-row.is-checked .tmpl-pick-rule {
        visibility: visible;
        opacity: 1;
    }
    
    @media (max-width: 600px) {
        .tmpl-pick-row { flex-direction: column; align-items: stretch; gap: 6px; }
        .tmpl-pick-rule { width: 100%; }
        .tmpl-pick-row.is-checked .tmpl-pick-rule { visibility: visible; opacity: 1; }
        .tmpl-pick-row .tmpl-pick-rule { visibility: hidden; opacity: 0; height: 0; margin: 0; }
        .tmpl-pick-row.is-checked .tmpl-pick-rule { visibility: visible; opacity: 1; height: 30px; }
    }
    
    /* =========================================================
       TEMPLATE PICKER — section wrappers + inline filters
       ========================================================= */
    .tmpl-pick-section { display: block; }
    
    .tmpl-pick-section-title {
        position: sticky;
        top: 0;
        z-index: 2;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
        padding: 8px 14px;
        background: #fafbfc;
        border-bottom: 1px solid var(--fr-border);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: var(--fr-muted);
    }
    
    .tmpl-pick-section-title > span {
        flex-shrink: 0;
    }
    
    .tmpl-pick-filters {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
        margin-left: auto;
    }
    
    .tmpl-pick-filter {
        font-size: 11.5px;
        font-weight: 500;
        text-transform: none;
        letter-spacing: 0;
        color: var(--fr-text-soft);
        height: 28px;
        padding: 3px 24px 3px 8px;
        border-radius: 6px;
        min-width: 130px;
        max-width: 210px;
        background-position: right 6px center;
        background-size: 10px;
    }
    
    @media (max-width: 600px) {
        .tmpl-pick-section-title {
            font-size: 10px;
            padding: 6px 12px;
        }
        .tmpl-pick-filter {
            min-width: 0;
            flex: 1;
            font-size: 11px;
        }
    }
    .tmpl-pick-filters {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
        margin-left: auto;
    }
    
    .tmpl-pick-filter {
        font-size: 11.5px;
        font-weight: 500;
        text-transform: none;
        letter-spacing: 0;
        color: var(--fr-text-soft);
        height: 28px;
        padding: 3px 24px 3px 8px;
        border-radius: 6px;
        min-width: 130px;
        max-width: 210px;
        background-position: right 6px center;
        background-size: 10px;
    }
    
    @media (max-width: 600px) {
        .tmpl-pick-section-title {
            font-size: 10px;
            padding: 6px 12px;
        }
        .tmpl-pick-filter {
            min-width: 0;
            flex: 1;
            font-size: 11px;
        }
    }

    .table-wrap table tbody tr:last-child td { border-bottom: none; }
    .table-wrap table tbody tr:hover { background: #fafbfc; }
    .client-name { font-weight: 600; color: var(--fr-text); display: block; }
    .client-email { font-size: 11.5px; color: var(--fr-muted); display: block; margin-top: 2px; }
    .roofing-product { font-weight: 600; color: var(--fr-text); display: block; }
    .roofing-category { font-size: 11.5px; color: var(--fr-muted); display: block; margin-top: 2px; }
    .muted { color: var(--fr-muted) !important; }

    /* ---------- ACTION BUTTONS ---------- */
    .actions {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        justify-content: flex-end;
        white-space: normal;
    }
    
    /* ---------- KEBAB MENU (collapses all row actions) ---------- */
    .actions .dropdown { display: inline-block; }
    
    .actions .dropdown-toggle {
        background: #ffffff;
        border: 1px solid #d1d5db;
        color: #374151;
        padding: 6px 10px;
        border-radius: 6px;
        font-size: 14px;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background .15s, border-color .15s;
    }
    .actions .dropdown-toggle:hover,
    .actions .dropdown-toggle[aria-expanded="true"] {
        background: #f9fafb;
        border-color: #9ca3af;
    }
    .actions .dropdown-toggle::after { display: none; } /* kill the caret */
    
    .actions .dropdown-menu {
        font-size: 13px;
        border-radius: 10px;
        border: 1px solid var(--fr-border);
        box-shadow: 0 10px 30px rgba(16,24,40,.12);
        padding: 6px;
        min-width: 200px;
        margin-top: 4px !important;
    }
    .actions .dropdown-menu form {
        display: contents;
        margin: 0;
        padding: 0;
    }
    .actions .dropdown-item {
        border-radius: 6px;
        padding: 8px 12px;
        font-weight: 500;
        color: var(--fr-text);
        display: flex;
        align-items: center;
        line-height: 1.3;
    }
    .actions .dropdown-menu button.dropdown-item {
        width: 100%;
        text-align: left;
        background: transparent;
        border: 0;
        border-radius: 6px;
        padding: 8px 12px;
        font-family: inherit;
        font-size: 13px;
        font-weight: 500;
        line-height: 1.3;
        color: var(--fr-text);
        margin: 0;
        cursor: pointer;
        appearance: none;
        -webkit-appearance: none;
        box-sizing: border-box;
    
        display: flex;
        align-items: center;
        justify-content: flex-start; /* FIX */
    }
    .actions .dropdown-item:hover,
    .actions .dropdown-item:focus {
        background: var(--fr-bg);
        color: var(--fr-text);
    }
    .actions .dropdown-item.text-danger { color: #b91c1c; }
    .actions .dropdown-item.text-danger:hover,
    .actions .dropdown-item.text-danger:focus { background: #fef2f2; color: #b91c1c; }
    .actions .dropdown-item.disabled {
        color: var(--fr-muted);
        opacity: .55;
        cursor: not-allowed;
        background: transparent;
    }
    .actions .dropdown-divider { margin: 4px 0; border-color: var(--fr-border); }
    .actions .action-btn, .actions button {
        display: inline-flex; align-items: center; justify-content: center;
        gap: 4px;
        padding: 5px 10px;
        border-radius: 6px; border: 1px solid transparent;
        font-size: 12px; font-weight: 600; line-height: 1.2;
        cursor: pointer; text-decoration: none;
        transition: opacity .15s, background .15s;
        box-sizing: border-box;
        white-space: nowrap;
    }
    .actions .action-btn:last-child, .actions button:last-child { margin-right: 0; }
    .actions .action-btn:hover, .actions button:hover { opacity: .85; }
    

    /* Green — approve, complete */
    .actions .approve, .actions .action-btn.approve, .actions button.approve {
        background: #10b981; border-color: #10b981; color: #fff;
    }
    /* Red — reject, delete */
    .actions .reject, .actions .action-btn.reject, .actions button.reject,
    .actions .cancel-btn, .actions .action-btn.cancel-btn {
        background: #dc3545; border-color: #dc3545; color: #fff;
    }
    /* Blue — edit */
    .actions .edit, .actions .action-btn.edit, .actions button.edit,
    .actions .edit-btn, .actions .action-btn.edit-btn {
        background: #007bff; border-color: #007bff; color: #fff;
    }
    /* Grey ghost — details, view, secondary */
    .actions .view-btn, .actions .action-btn.view-btn, .actions button.view-btn {
        background: #ffffff; border-color: #d1d5db; color: #374151;
    }
    .actions .view-btn:hover { background: #f9fafb; border-color: #9ca3af; }
    /* PDF icon button */
    .actions .pdf-btn {
        background: #007bff; border-color: #007bff; color: #fff;
        padding: 5px 8px;
    }
    .actions .pdf-btn svg { display: block; width: 13px; height: 13px; }
    
    /* ---------- PAYMENTS ACTIONS ---------- */
    .tab-payments .actions {
        display: table-cell;
        vertical-align: middle;
        text-align: right;
        white-space: nowrap;
        padding: 14px 18px;
        min-width: 0;
    }
    
    /* Buttons align inline and right, matching the header */
    .tab-payments .actions > * {
        display: inline-flex;
        vertical-align: middle;
        margin-left: 4px;
    }
    
    .tab-payments .actions > *:first-child {
        margin-left: 0;
    }
    
    .tab-payments .actions .action-btn {
        flex: 0 0 auto;
    }
    
    /* PDF icon button */
    .tab-payments .actions .pdf-btn {
        width: 32px;
        padding: 5px 8px;
    }
    
    .tab-payments .actions .pdf-btn svg {
        display: block;
        width: 13px;
        height: 13px;
    }
    
    /* User Management — Actions cell (table-cell so fixed layout works) */
    .tab-user_management .actions {
        display: table-cell;
        vertical-align: middle;
        text-align: right;
        white-space: nowrap;
        padding: 12px 10px;
        min-width: 0;
    }
    
    /* ---------- ACTIONS — restore table-cell so vertical-align: middle works ---------- */
    /* The base .actions rule uses display: flex, which breaks vertical alignment
       inside a <td>. These tabs use plain inline buttons or a kebab dropdown,
       so restore the natural table-cell display and let the buttons flow inline. */
    .tab-booking .actions,
    .tab-site_inspection .actions,
    .tab-quotations .actions,
    .tab-projects .actions,
    .tab-settings .actions {
        display: table-cell;
        vertical-align: middle;
        text-align: right;
        white-space: nowrap;
    }
    
    .tab-booking .actions > *,
    .tab-site_inspection .actions > *,
    .tab-quotations .actions > *,
    .tab-projects .actions > *,
    .tab-settings .actions > * {
        display: inline-block;
        vertical-align: middle;
    }
    
    .tab-booking .actions > * + *,
    .tab-site_inspection .actions > * + *,
    .tab-quotations .actions > * + *,
    .tab-projects .actions > * + *,
    .tab-settings .actions > * + * {
        margin-left: 4px;
    }
    
    .tab-user_management .actions > * {
        display: inline-flex;
        vertical-align: middle;
        margin-left: 4px;
    }
    
    .tab-user_management .actions > *:first-child {
        margin-left: 0;
    }
    
    /* Match the <a> Edit and the <button> Deactivate visually */
    .tab-user_management .actions .edit,
    .tab-user_management .actions button {
        height: 28px;
        padding: 0 12px;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    
    /* Force Activate and Deactivate to the same width */
    .tab-user_management .actions button.approve,
    .tab-user_management .actions button.reject {
        min-width: 80px;
    }
    
    /* ---------- USER MANAGEMENT — FIT ALL COLUMNS ---------- */
    .tab-user_management .table-wrap table {
        table-layout: fixed;
        width: 100%;
    }
    
    .tab-user_management .table-wrap th,
    .tab-user_management .table-wrap td {
        padding: 12px 10px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    
    /* Column widths (sum = 100%) */
    .tab-user_management .table-wrap th:nth-child(1),
    .tab-user_management .table-wrap td:nth-child(1) { width: 9%;  }  /* Employee ID */
    .tab-user_management .table-wrap th:nth-child(2),
    .tab-user_management .table-wrap td:nth-child(2) { width: 10%; }  /* Name */
    .tab-user_management .table-wrap th:nth-child(3),
    .tab-user_management .table-wrap td:nth-child(3) { width: 13%; }  /* Email */
    .tab-user_management .table-wrap th:nth-child(4),
    .tab-user_management .table-wrap td:nth-child(4) { width: 10%; }  /* Phone */
    .tab-user_management .table-wrap th:nth-child(5),
    .tab-user_management .table-wrap td:nth-child(5) { width: 8%;  }  /* Role */
    .tab-user_management .table-wrap th:nth-child(6),
    .tab-user_management .table-wrap td:nth-child(6) { width: 7%;  }  /* Daily Rate */
    .tab-user_management .table-wrap th:nth-child(7),
    .tab-user_management .table-wrap td:nth-child(7) { width: 8%;  }  /* Join Date */
    .tab-user_management .table-wrap th:nth-child(8),
    .tab-user_management .table-wrap td:nth-child(8) { width: 9%;  }  /* Status */
    .tab-user_management .table-wrap th:nth-child(9),
    .tab-user_management .table-wrap td:nth-child(9) { width: 10%; text-align: center; }  /* Faces */
    .tab-user_management .table-wrap th:nth-child(10),
    .tab-user_management .table-wrap td:nth-child(10) {
        width: 16%;
        text-align: right;
        overflow: visible;
        text-overflow: clip;
    }
    .tab-projects .table-wrap th:nth-child(2),
    .tab-projects .table-wrap td:nth-child(2) {
        padding-left: 20px;
    }
    
    /* Give the action buttons enough breathing room */
    .tab-user_management .actions {
        padding-right: 12px;
    }
    
    /* Never clip the Actions cell — buttons must render in full */
    .tab-user_management .table-wrap th:nth-child(10),
    .tab-user_management .table-wrap td:nth-child(10) {
        overflow: visible;
        text-overflow: clip;
    }

    
    /* ---------- PROJECTS ACTIONS — kebab menu ---------- */
    .tab-projects .actions .dropdown { display: inline-block; }
    
    .tab-projects .actions .dropdown-toggle {
        background: #ffffff;
        border: 1px solid #d1d5db;
        color: #374151;
        padding: 6px 10px;
        border-radius: 6px;
        font-size: 14px;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background .15s, border-color .15s;
    }
    
    .tab-projects .actions .dropdown-toggle:hover,
    .tab-projects .actions .dropdown-toggle[aria-expanded="true"] {
        background: #f9fafb;
        border-color: #9ca3af;
    }
    
    .tab-projects .actions .dropdown-toggle::after { display: none; }
    
    .tab-projects .actions .dropdown-menu {
        font-size: 13px;
        border-radius: 10px;
        border: 1px solid var(--fr-border);
        box-shadow: 0 10px 30px rgba(16,24,40,.12);
        padding: 6px;
        min-width: 200px;
        margin-top: 4px !important;
    }
    
    .tab-projects .actions .dropdown-menu form {
        display: contents;
        margin: 0;
        padding: 0;
    }
    
    .tab-projects .actions .dropdown-item {
        border-radius: 6px;
        padding: 8px 12px;
        font-weight: 500;
        color: var(--fr-text);
        display: flex;
        align-items: center;
        line-height: 1.3;
    }
    
    .tab-projects .actions .dropdown-menu button.dropdown-item {
        width: 100%;
        text-align: left;
        background: transparent;
        border: 0;
        border-radius: 6px;
        padding: 8px 12px;
        font-family: inherit;
        font-size: 13px;
        font-weight: 500;
        line-height: 1.3;
        color: var(--fr-text);
        margin: 0;
        cursor: pointer;
        appearance: none;
        -webkit-appearance: none;
        box-sizing: border-box;
        display: flex;
        align-items: center;
        justify-content: flex-start;
    }
    
    .tab-projects .actions .dropdown-item:hover,
    .tab-projects .actions .dropdown-item:focus {
        background: var(--fr-bg);
        color: var(--fr-text);
    }
    
    .tab-projects .actions .dropdown-divider {
        margin: 4px 0;
        border-color: var(--fr-border);
    }
    
    /* Align the Actions column header right */
    .tab-projects .table-wrap th:last-child {
        text-align: right;
    }
    
    /* =========================================================
       PROJECTS TABLE — fit all columns on screen (no h-scroll)
       Scoped to .tab-projects so no other table is affected.
       ========================================================= */
    
    /* 1. Let the table actually be constrained by its container */
    .tab-projects .table-wrap {
        overflow-x: hidden;
    }
    .tab-projects .table-wrap table {
        table-layout: fixed;
        width: 100%;
    }
    
    /* 2. Tighter cells + let text wrap inside its column */
    .tab-projects .table-wrap th,
    .tab-projects .table-wrap td {
        padding: 10px 10px;
        vertical-align: middle;
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    .tab-projects .table-wrap th {
        white-space: nowrap;
        font-size: 10.5px;
        letter-spacing: .3px;
        padding: 10px 8px;
    }
    
    /* 3. Fixed column widths — must sum to 100% */
    .tab-projects .table-wrap th:nth-child(1),
    .tab-projects .table-wrap td:nth-child(1)  { width:  8%; }  /* Project ID      */
    .tab-projects .table-wrap th:nth-child(2),
    .tab-projects .table-wrap td:nth-child(2)  { width: 13%; }  /* Project Name    */
    .tab-projects .table-wrap th:nth-child(3),
    .tab-projects .table-wrap td:nth-child(3)  { width: 10%; }  /* Client          */
    .tab-projects .table-wrap th:nth-child(4),
    .tab-projects .table-wrap td:nth-child(4)  { width:  7%; }  /* Service         */
    .tab-projects .table-wrap th:nth-child(5),
    .tab-projects .table-wrap td:nth-child(5)  { width: 10%; }  /* Foreman         */
    .tab-projects .table-wrap th:nth-child(6),
    .tab-projects .table-wrap td:nth-child(6)  { width:  5%;  text-align: center; }  /* Team */
    .tab-projects .table-wrap th:nth-child(7),
    .tab-projects .table-wrap td:nth-child(7)  { width:  9%; }  /* Start Date      */
    .tab-projects .table-wrap th:nth-child(8),
    .tab-projects .table-wrap td:nth-child(8)  { width:  9%; }  /* Expected End    */
    .tab-projects .table-wrap th:nth-child(9),
    .tab-projects .table-wrap td:nth-child(9)  { width: 10%; }  /* Progress        */
    .tab-projects .table-wrap th:nth-child(10),
    .tab-projects .table-wrap td:nth-child(10) { width: 12%; text-align: center;}  /* Status          */
    .tab-projects .table-wrap th:nth-child(11),
    .tab-projects .table-wrap td:nth-child(11) { width:  7%;  text-align: right; }  /* Actions */
    
    /* 4. Kill the one-line badge rule inside the projects table.
          "Waiting for Down Payment" is the widest offender — let it wrap. */
    .tab-projects .table-wrap .fr-badge {
        white-space: normal;
        text-align: center;
        line-height: 1.25;
        font-size: 10.5px;
        padding: 3px 8px;
    }
    
    /* 5. Let PROJ IDs wrap when the column gets tight */
    .tab-projects .table-wrap td strong {
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    
    /* 6. Kebab button doesn't need the base padding bump */
    .tab-projects .actions .dropdown-toggle {
        padding: 6px 8px;
    }
    
    /* ---------- FORMS ---------- */
    .form-control, .form-select {
        border-color: #d1d5db; font-size: 13.5px;
        border-radius: 8px; padding: 8px 12px; background: #fff;
    }
    .form-control:focus, .form-select:focus {
        border-color: var(--fr-red);
        box-shadow: 0 0 0 3px rgba(217,32,39,.1);
    }
    .form-label { font-size: 13px; font-weight: 600; color: var(--fr-text-soft); margin-bottom: 6px; }
    .form-text { font-size: 11.5px; color: var(--fr-muted); }
    .form-group { margin-bottom: 15px; }
    .form-group > label { display: block; font-size: 13px; font-weight: 600; color: var(--fr-text-soft); margin-bottom: 6px; }
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
        text-align: center;
        position: relative;
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
    .top-bar label { font-size: 13px; font-weight: 600; color: var(--fr-text-soft); }
    .top-bar select, .top-bar input[type=text] {
        padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px;
        background: #fff; color: var(--fr-text); font-size: 13.5px;
    }
    .top-bar select:focus, .top-bar input[type=text]:focus {
        border-color: var(--fr-red); outline: none;
        box-shadow: 0 0 0 3px rgba(217,32,39,.1);
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
    .msg.error { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }

    /* ---------- PROGRESS BAR ---------- */
    .fr-progress { height: 8px; background: #eef0f3; border-radius: 99px; overflow: hidden; }
    .fr-progress > div { height: 100%; background: var(--fr-red); border-radius: 99px; transition: width .3s ease; }

    /* =========================================================
       MODAL — base shell (used by every tab)
       Keeps .classList.add('show') / .classList.remove('show').
       ========================================================= */
    .ad-modal,
    .modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1055;
        background: rgba(17, 24, 39, 0.5);
        padding: 32px 18px;
        overflow-y: auto;
        justify-content: center;
        align-items: flex-start;
        backdrop-filter: blur(2px);
    }
    .ad-modal.show,
    .modal.show { display: flex; }

    .ad-modal-content,
    .modal-content {
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
    .ad-modal-content h2,
    .modal-content h2 {
        font-size: 18px;
        font-weight: 700;
        margin: 0 0 16px;
        color: var(--fr-text);
    }

    .ad-modal .close,
    .modal .close {
        position: absolute;
        top: 12px;
        right: 18px;
        font-size: 28px;
        line-height: 1;
        color: var(--fr-muted);
        cursor: pointer;
        text-decoration: none;
        background: none;
        border: none;
    }
    .ad-modal .close:hover,
    .modal .close:hover { color: var(--fr-text); }

    /* Small generic subtitle — used by Booking + Site Inspection modals */
    .ad-modal-subtitle {
        color: var(--fr-muted);
        font-size: 12.5px;
        line-height: 1.5;
        margin: 0 0 20px;
        overflow-wrap: anywhere;
    }

    /* =========================================================
       BOOKING MANAGE MODAL — scoped to .booking-modal-content
       so it never leaks into the Site Inspection / Projects /
       Payments / Quotations modals.
       ========================================================= */
    .ad-modal,
    .modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1055;
        background: rgba(17, 24, 39, 0.5);
        padding: 32px 18px;
        overflow-y: auto;
        justify-content: center;
        align-items: flex-start;
        backdrop-filter: blur(2px);
    }
    .ad-modal.show,
    .modal.show { display: flex; }

    .ad-modal-content,
    .modal-content {
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
    .ad-modal-content h2,
    .modal-content h2 {
        font-size: 18px;
        font-weight: 700;
        margin: 0 0 16px;
        color: var(--fr-text);
    }

    .ad-modal .close,
    .modal .close {
        position: absolute;
        top: 12px;
        right: 18px;
        font-size: 28px;
        line-height: 1;
        color: var(--fr-muted);
        cursor: pointer;
        text-decoration: none;
        background: none;
        border: none;
    }
    .ad-modal .close:hover,
    .modal .close:hover { color: var(--fr-text); }

    /* Muted subtitle — used by Booking + Site Inspection modals */
    .ad-modal-subtitle {
        color: var(--fr-muted);
        font-size: 12.5px;
        line-height: 1.5;
        margin: 0 0 20px;
        overflow-wrap: anywhere;
    }

    /* =========================================================
       BOOKING MANAGE MODAL — scoped to .booking-modal-content
       ========================================================= */
    .ad-modal-content.booking-modal-content {
        max-width: 780px;
        padding: 26px;
    }

    /* --- Header --- */
    .booking-modal-content .ad-modal-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 20px;
        padding-right: 34px;
        margin-bottom: 22px;
    }
    .booking-modal-content .ad-modal-header-main { min-width: 0; }
    .booking-modal-content .ad-modal-header-main h2 {
        margin: 0 0 6px;
        font-size: 19px;
        font-weight: 700;
        line-height: 1.3;
    }
    .booking-modal-content .ad-modal-subtitle {
        margin: 0;
        font-size: 12.5px;
    }
    .booking-modal-content .ad-modal-header-meta {
        display: flex;
        align-items: center;
        gap: 7px;
        flex-wrap: wrap;
        flex-shrink: 0;
    }
    .booking-modal-content .ad-guest-badge {
        background: #eff6ff;
        color: #1d4ed8;
        border-color: #bfdbfe;
    }

    /* --- Body / sections --- */
    .booking-modal-content .ad-modal-body { padding: 0; }

    .booking-modal-content .ad-modal-section { margin-bottom: 26px; }
    .booking-modal-content .ad-modal-section:last-child { margin-bottom: 4px; }

    .booking-modal-content .ad-modal-section-title {
        font-size: 11px !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        letter-spacing: .8px;
        color: var(--fr-muted);
        margin: 0 0 16px !important;
        line-height: 1.3 !important;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .booking-modal-content .ad-modal-section-title::before {
        content: '';
        width: 3px;
        height: 14px;
        background: var(--fr-red);
        border-radius: 2px;
        flex-shrink: 0;
    }

    /* --- Spec grid (label above value, two columns) --- */
    .booking-modal-content .spec-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        column-gap: 32px;
        row-gap: 20px;
    }
    .booking-modal-content .spec-item { min-width: 0; }
    .booking-modal-content .spec-item.full { grid-column: 1 / -1; }

    .booking-modal-content .spec-label {
        display: block;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: var(--fr-muted);
        margin-bottom: 6px;
        line-height: 1.4;
    }
    .booking-modal-content .spec-value {
        font-size: 14px;
        font-weight: 600;
        color: var(--fr-text);
        line-height: 1.45;
        overflow-wrap: anywhere;
        margin: 0;
    }
    .booking-modal-content .spec-value.is-muted {
        color: var(--fr-muted);
        font-weight: 500;
    }

    /* --- Admin Scheduling (the only editable area) --- */
    .booking-modal-content .ad-form-block {
        background: #fafbfc;
        border: 1px solid var(--fr-border);
        border-left: 3px solid var(--fr-red);
        border-radius: 10px;
        padding: 18px 20px;
    }
    .booking-modal-content .ad-form-block .form-group { margin-bottom: 0; }
    .booking-modal-content .ad-form-block .form-text {
        display: block;
        margin-top: 6px;
    }

    /* --- Footer --- */
    .booking-modal-content .ad-modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        padding: 18px 0 0;
        margin-top: 22px;
        border-top: 1px solid var(--fr-border-soft);
    }

    /* --- Flatpickr preferred-date indicator --- */
    .flatpickr-day { position: relative; }
    .fp-pref-count {
        position: absolute;
        top: 1px;
        right: 1px;
        background: #f59e0b;
        color: #fff;
        font-size: 9px;
        font-weight: 700;
        line-height: 1;
        padding: 2px 4px;
        border-radius: 8px;
        min-width: 12px;
        text-align: center;
        pointer-events: none;
    }
    .tmpl-pick-rule {
        width: 175px;
        flex-shrink: 0;
        visibility: hidden;
        opacity: 0;
        transition: opacity .15s;
        font-size: 12px;
        padding: 4px 24px 4px 8px;
        height: 30px;
    }
    .tmpl-pick-row.is-checked .tmpl-pick-rule {
        visibility: visible;
        opacity: 1;
    }
    
    @media (max-width: 600px) {
        .tmpl-pick-row { flex-direction: column; align-items: stretch; gap: 6px; }
        .tmpl-pick-rule { width: 100%; }
        .tmpl-pick-row.is-checked .tmpl-pick-rule { visibility: visible; opacity: 1; }
        .tmpl-pick-row .tmpl-pick-rule { visibility: hidden; opacity: 0; height: 0; margin: 0; }
        .tmpl-pick-row.is-checked .tmpl-pick-rule { visibility: visible; opacity: 1; height: 30px; }
    }
    .tmpl-pick-filter.form-select,
    .tmpl-pick-rule.form-select {
        box-sizing: border-box;
        padding-top: 0 !important;
        padding-bottom: 0 !important;
        line-height: 1.2;
    }
    .tmpl-pick-filter.form-select {
        height: 28px;
        padding-left: 8px !important;
        padding-right: 24px !important;
    }
    .tmpl-pick-rule.form-select {
        height: 30px;
        padding-left: 8px !important;
        padding-right: 24px !important;
    }

    /* --- Responsive --- */
    @media (max-width: 640px) {
        .ad-modal { padding: 16px 10px; }
        .booking-modal-content .ad-modal-header {
            flex-direction: column;
            gap: 12px;
        }
        .booking-modal-content .spec-grid {
            grid-template-columns: 1fr;
        }
        .booking-modal-content .spec-item.full { grid-column: auto; }
    }
    
    /* =========================================================
       SITE INSPECTION DETAILS MODAL
       Scoped to modals whose id starts with "detailsModal" so the
       rules never leak into Booking / Quotations / Payments.
       ========================================================= */
    [id^="detailsModal"] .ad-detail-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        column-gap: 32px;
        row-gap: 18px;
        margin-bottom: 22px;
    }
    [id^="detailsModal"] .ad-detail-card { min-width: 0; }
    [id^="detailsModal"] .ad-detail-card.full { grid-column: 1 / -1; }

    [id^="detailsModal"] .ad-detail-row { margin-bottom: 4px; }

    [id^="detailsModal"] .ad-detail-label {
        display: block;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: var(--fr-muted);
        margin-bottom: 5px;
        line-height: 1.4;
    }
    [id^="detailsModal"] .ad-detail-value {
        font-size: 13.5px;
        font-weight: 600;
        color: var(--fr-text);
        line-height: 1.45;
        overflow-wrap: anywhere;
    }
    [id^="detailsModal"] .ad-detail-value.is-muted {
        color: var(--fr-muted);
        font-weight: 500;
    }

    @media (max-width: 640px) {
        [id^="detailsModal"] .ad-detail-grid {
            grid-template-columns: 1fr;
        }
        [id^="detailsModal"] .ad-detail-card.full { grid-column: auto; }
    }

    /* The details modal also reuses .ad-modal-subtitle — the global
       rule already covers it, but the extra margin below the <h2>
       keeps the header looking right here too. */
    [id^="detailsModal"] .ad-modal-subtitle {
        margin: -12px 0 20px;
        font-size: 12px;
    }

    /* ---------- PASSWORD TOGGLE ---------- */
    .password-field { position: relative; }
    .password-field input { padding-right: 44px; }
    .password-toggle {
        position: absolute; top: 50%; right: 12px; transform: translateY(-50%);
        background: transparent; border: none; cursor: pointer;
        color: var(--fr-muted); padding: 4px;
        line-height: 0; display: inline-flex; align-items: center; justify-content: center;
        border-radius: 4px; transition: color .15s;
    }
    .password-toggle:hover { color: var(--fr-red); }
    .password-toggle svg { width: 18px; height: 18px; display: block; pointer-events: none; }
    .password-toggle .icon-eye-off { display: none; }
    .password-toggle.is-visible .icon-eye { display: none; }
    .password-toggle.is-visible .icon-eye-off { display: block; }
    
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
        color: var(--fr-muted);
        font-size: 11.5px;
    }
    .date-field-note {
        color: var(--fr-muted);
        font-size: 11px;
        font-weight: 400;
        margin-left: 3px;
    }

    /* ---------- FLATPICKR THEME — matches the admin brand red ---------- */
    .flatpickr-calendar.arrowTop::before,
    .flatpickr-calendar.arrowTop::after { border-bottom-color: #fff; }

    .flatpickr-day.selected,
    .flatpickr-day.selected:hover,
    .flatpickr-day.selected:focus {
        background: var(--fr-red);
        border-color: var(--fr-red);
        color: #fff;
    }
    .flatpickr-day.today { border-color: var(--fr-red); }
    .flatpickr-day.today:hover,
    .flatpickr-day.today:focus {
        background: var(--fr-red);
        border-color: var(--fr-red);
        color: #fff;
    }
    .flatpickr-day.flatpickr-disabled,
    .flatpickr-day.flatpickr-disabled:hover {
        color: #cbd5e1;
        background: #f9fafb;
        text-decoration: line-through;
        cursor: not-allowed;
    }
    .flatpickr-day.prevMonthDay,
    .flatpickr-day.nextMonthDay { color: #cbd5e1; }

    /* ---------- LOCKED FORM CONTROLS ---------- */
    /* Fields explicitly marked with .locked-field get the muted grey
       treatment. We don't use [readonly] here because Flatpickr's
       alt-input also carries readonly, and it needs to stay white so
       the user knows it's clickable. */
    .form-group input.form-control.locked-field,
    .form-control.locked-field {
        background-color: #e9ecef;
        cursor: default;
    }
    .form-group input.form-control.locked-field:focus,
    .form-control.locked-field:focus {
        background-color: #e9ecef;
        border-color: #d1d5db;
        box-shadow: none;
    }
    
    /* =========================================================
       STANDARD MATERIAL TEMPLATES
       ========================================================= */
    #templatesBody tr { transition: background .1s; }
    #templatesBody tr:hover { background: #fafbfc; }
    #templatesBody .fr-badge.active   { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
    #templatesBody .fr-badge.cancelled { background:#f3f4f6; color:#4b5563; border-color:#e5e7eb; }
    
    #mtm_items_body select.form-select-sm,
    #mtm_items_body .action-btn { font-size: 12px; }
    
    #manageTemplateMaterialsModal .table-wrap table th,
    #manageTemplateMaterialsModal .table-wrap table td { padding: 10px 12px; }
    
    /* =========================================================
       SERVICE & PROPERTY CONFIGURATION (Settings tab)
       ========================================================= */
    .spc-tabs {
        display: flex;
        gap: 4px;
        border-bottom: 1px solid var(--fr-border);
        margin-bottom: 16px;
        flex-wrap: wrap;
    }
    .spc-tab {
        background: transparent;
        border: none;
        padding: 10px 18px;
        font-size: 13.5px;
        font-weight: 600;
        color: var(--fr-muted);
        cursor: pointer;
        border-bottom: 2px solid transparent;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: color .15s, border-color .15s;
        margin-bottom: -1px;
    }
    .spc-tab:hover { color: var(--fr-text); }
    .spc-tab.is-active {
        color: var(--fr-red);
        border-bottom-color: var(--fr-red);
    }
    .spc-tab .bi { font-size: 14px; }
    
    .spc-panel { display: none; }
    .spc-panel.is-active { display: block; }
    
    .spc-table-wrap {
        background: #fff;
        border: 1px solid var(--fr-border);
        border-radius: 10px;
        overflow: hidden;
    }
    .spc-table-wrap table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13.5px;
    }
    .spc-table-wrap th {
        text-align: left;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
        color: var(--fr-muted);
        padding: 12px 18px;
        border-bottom: 1px solid var(--fr-border);
        background: #fafbfc;
        white-space: nowrap;
    }
    .spc-table-wrap td {
        padding: 16px 18px;
        border-bottom: 1px solid var(--fr-border-soft);
        vertical-align: middle;
    }
    .spc-table-wrap tbody tr:last-child td { border-bottom: none; }
    .spc-table-wrap tbody tr:hover { background: #fafbfc; }
    .spc-table-wrap .spc-name strong {
        font-size: 13.5px;
        font-weight: 700;
        color: var(--fr-text);
    }
    
    /* ---------- Status badge (inline pill with icon) ---------- */
    .spc-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 11px;
        border-radius: 6px;
        font-size: 11.5px;
        font-weight: 600;
        line-height: 1.4;
        border: 1px solid transparent;
        white-space: nowrap;
    }
    .spc-status-badge .bi { font-size: 12px; }
    .spc-status-badge.is-active {
        background: #d1fae5;
        color: #047857;
        border-color: #6ee7b7;
    }
    .spc-status-badge.is-inactive {
        background: #fee2e2;
        color: #b91c1c;
        border-color: #fca5a5;
    }
    
    /* ---------- Action buttons ---------- */
    .spc-actions {
        display: inline-flex;
        gap: 6px;
        justify-content: flex-end;
        white-space: nowrap;
    }
    .spc-btn {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 6px 12px;
        border-radius: 7px;
        font-size: 12.5px;
        font-weight: 600;
        cursor: pointer;
        border: 1px solid #d1d5db;
        background: #fff;
        color: var(--fr-text);
        transition: background .15s, border-color .15s, color .15s;
        white-space: nowrap;
        line-height: 1.2;
    }
    .spc-btn:hover { background: #f9fafb; border-color: #9ca3af; }
    .spc-btn .bi { font-size: 13px; }
    
    .spc-btn.is-deactivate {
        color: var(--fr-red);
        border-color: #fecaca;
        background: #fef2f2;
    }
    .spc-btn.is-deactivate:hover {
        background: #fee2e2;
        border-color: #fca5a5;
    }
    .spc-btn.is-activate {
        color: #047857;
        border-color: #a7f3d0;
        background: #ecfdf5;
    }
    .spc-btn.is-activate:hover {
        background: #d1fae5;
        border-color: #6ee7b7;
    }
    
    /* ---------- Footer note ---------- */
    .spc-footer-note {
        display: flex;
        gap: 10px;
        align-items: flex-start;
        padding: 12px 16px;
        margin-top: 16px;
        background: var(--fr-bg);
        border: 1px solid var(--fr-border);
        border-radius: 8px;
        font-size: 12.5px;
        color: var(--fr-text-soft);
        line-height: 1.55;
    }
    .spc-footer-note .bi {
        color: var(--fr-red);
        font-size: 15px;
        margin-top: 1px;
        flex-shrink: 0;
    }
    .spc-footer-note strong { color: var(--fr-text); }
    
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
    
    @media (max-width: 720px) {
        .spc-tab { padding: 8px 12px; font-size: 12.5px; }
        .spc-table-wrap th, .spc-table-wrap td { padding: 10px 12px; }
    }
    
    /* ---------- MOBILE DRAWER ---------- */
    .fr-menu-toggle { display: none; }

    @media (max-width: 768px) {
        .fr-sidebar {
            position: fixed;
            top: 0; bottom: 0; left: 0;
            height: 100vh;
            width: 280px; max-width: 82vw;
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
            display: flex;
            align-items: center; justify-content: center;
            width: 40px; height: 40px; padding: 0;
            border: 1px solid var(--fr-border); background: #fff; border-radius: 8px;
            cursor: pointer; flex-shrink: 0;
        }
        .fr-menu-toggle .bi { font-size: 20px; color: var(--fr-text); }

        .fr-header { padding: 12px 16px; }
        .fr-header h1 { font-size: 15px; }
        .fr-header .meta { font-size: 11px; }
        .fr-content { padding: 16px 14px 30px; }
        .table-wrap table th, .table-wrap table td { padding: 10px 12px; }
        .stat-card { padding: 14px 16px; }
        .stat-card .number, .stat-card .stat-number { font-size: 22px; }
        .form-row { grid-template-columns: 1fr; }
        .top-bar { flex-direction: column; align-items: stretch; }
    }
    
    /* User Management — switch back to natural layout on small screens
   so the table can scroll horizontally instead of squeezing columns */
    @media (max-width: 900px) {
        .tab-user_management .table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
    
        .tab-user_management .table-wrap table {
            table-layout: auto;
            width: max-content;
            min-width: 100%;
        }
    
        /* Undo the fixed-layout column widths */
        .tab-user_management .table-wrap th,
        .tab-user_management .table-wrap td {
            width: auto !important;
            padding: 12px 14px;
            white-space: nowrap;
        }
    
        /* Email is still long, so cap it so the table doesn't get absurdly wide */
        .tab-user_management .table-wrap th:nth-child(3),
        .tab-user_management .table-wrap td:nth-child(3) {
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    
        /* The Actions cell has two buttons; never clip them */
        .tab-user_management .table-wrap td:nth-child(10) {
            overflow: visible;
            text-overflow: clip;
        }
    
        /* Restore centered Faces column */
        .tab-user_management .table-wrap th:nth-child(9),
        .tab-user_management .table-wrap td:nth-child(9) {
            text-align: center;
        }
    }

    </style>
</head>
<body>

<div class="fr-layout">

    <div class="fr-backdrop" id="frBackdrop" onclick="toggleAdminSidebar(false)"></div>

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
            <a href="admin.php?tab=dashboard" class="<?= $tab === 'dashboard' ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i><span>Dashboard</span>
            </a>
            <a href="admin.php?tab=user_management" class="<?= $tab === 'user_management' ? 'active' : '' ?>">
                <i class="bi bi-people"></i><span>User Management</span>
            </a>
            <a href="admin.php?tab=booking" class="<?= $tab === 'booking' ? 'active' : '' ?>">
                <i class="bi bi-calendar-check"></i><span>Bookings</span>
            </a>
            <a href="admin.php?tab=site_inspection" class="<?= $tab === 'site_inspection' ? 'active' : '' ?>">
                <i class="bi bi-clipboard-check"></i><span>Site Inspection</span>
            </a>
            <a href="admin.php?tab=quotations" class="<?= $tab === 'quotations' ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-text"></i><span>Quotations</span>
            </a>
            <a href="admin.php?tab=projects" class="<?= $tab === 'projects' ? 'active' : '' ?>">
                <i class="bi bi-kanban"></i><span>Projects</span>
            </a>
            <a href="admin.php?tab=payments" class="<?= $tab === 'payments' ? 'active' : '' ?>">
                <i class="bi bi-cash-coin"></i><span>Payments</span>
            </a>
            <a href="admin.php?tab=attendance" class="<?= $tab === 'attendance' ? 'active' : '' ?>">
                <i class="bi bi-clock-history"></i><span>Attendance &amp; Payroll</span>
            </a>
            <a href="admin.php?tab=feedback" class="<?= $tab === 'feedback' ? 'active' : '' ?>">
                <i class="bi bi-star-half"></i><span>Feedback Analysis</span>
            </a>
            <div class="separator"></div>
            <a href="admin.php?tab=settings" class="<?= $tab === 'settings' ? 'active' : '' ?>">
                <i class="bi bi-gear"></i><span>Settings</span>
            </a>
        </nav>

        <div class="fr-user">
            <div class="name"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
            <div class="role"><?= htmlspecialchars(ucfirst($_SESSION['role'])) ?></div>
            <a href="logout.php"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
        </div>
    </aside>

    <main class="fr-main">
        <header class="fr-header">
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="fr-menu-toggle" onclick="toggleAdminSidebar(true)" aria-label="Open menu">
                    <i class="bi bi-list"></i>
                </button>
                <h1><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $tab))) ?></h1>
            </div>
            <div class="meta">
                <span class="d-none d-md-inline"><?= date('l, F j, Y') ?></span>
                <?php include_once 'notification_bell.php'; ?>
            </div>
        </header>

        <div class="fr-content tab-<?= htmlspecialchars($tab) ?>">
            <?= $tabContent ?>
        </div>
    </main>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
        bootstrap.Dropdown.getOrCreateInstance(el, {
            boundary: 'viewport',
            popperConfig: { strategy: 'fixed' }
        });
    });
});
</script>
<script>
function toggleAdminSidebar(open) {
    var sidebar = document.getElementById('frSidebar');
    var backdrop = document.getElementById('frBackdrop');
    if (!sidebar || !backdrop) return;
    if (open) {
        sidebar.classList.add('open');
        backdrop.classList.add('show');
    } else {
        sidebar.classList.remove('open');
        backdrop.classList.remove('show');
    }
}
</script>
<script>
(function () {
    function fmtMoney(v) {
        var raw = String(v == null ? '' : v).replace(/[^\d.]/g, '');
        var firstDot = raw.indexOf('.');
        if (firstDot !== -1) {
            raw = raw.slice(0, firstDot + 1) + raw.slice(firstDot + 1).replace(/\./g, '');
        }
        var parts = raw.split('.');
        var intPart = (parts[0] || '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        var decPart = parts.length > 1 ? parts[1].slice(0, 2) : null;
        return decPart !== null ? intPart + '.' + decPart : intPart;
    }

    document.addEventListener('input', function (e) {
        var el = e.target;
        if (!el.matches || !el.matches('input, textarea')) return;
        if (!el.classList.contains('money-input') && el.dataset.numeric !== '1') return;

        if (el.classList.contains('money-input')) {
            var formatted = fmtMoney(el.value);
            if (el.value !== formatted) el.value = formatted;
        } else {
            var raw = String(el.value).replace(/[^\d.]/g, '');
            var firstDot = raw.indexOf('.');
            if (firstDot !== -1) {
                raw = raw.slice(0, firstDot + 1) + raw.slice(firstDot + 1).replace(/\./g, '');
            }
            if (raw !== el.value) el.value = raw;
        }
        el.dispatchEvent(new CustomEvent('fr:numeric-change', { bubbles: true }));
    });

    window.frParseMoney = function (v) {
        return parseFloat(String(v == null ? '' : v).replace(/,/g, '')) || 0;
    };
})();
</script>
</body>
</html>