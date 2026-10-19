<?php
require_once 'config.php';

// ---------------------------------------------------------
// If already logged in, send the user to their dashboard.
// ---------------------------------------------------------
if (isLoggedIn()) {
    switch ($_SESSION['role'] ?? '') {
        case 'admin':    header('Location: admin.php');    exit;
        case 'client':   header('Location: client.php');   exit;
        case 'foreman':  header('Location: foreman.php');  exit;
        case 'employee': header('Location: employee.php'); exit;
        default:
            session_destroy();
            header('Location: login.php');
            exit;
    }
}

// ---------------------------------------------------------
// Load company settings.
// ---------------------------------------------------------
$stmt = $pdo->query("SELECT * FROM system_settings ORDER BY id ASC LIMIT 1");
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    $company = [
        'company_name'     => 'ENG Roofing',
        'company_subtitle' => 'Supply and Installation',
        'company_logo'     => '',
        'company_phone'    => '',
        'company_email'    => '',
        'company_address'  => ''
    ];
}

// ---------------------------------------------------------
// Static content. Edit here to change the landing page.
// ---------------------------------------------------------

// Services — mirrors the service_type options in c_bookings.php.
$services = [
    [
        'key'   => 'roof installation',
        'label' => 'Roof Installation',
        'desc'  => 'Brand-new roof setups for residential and commercial structures using APO-certified roofing materials. Includes full structural assessment before installation.',
        'icon'  => '<path d="M6 30L24 10L42 30H36V42H12V30H6Z" fill="#cc1b1b" opacity=".15" stroke="#cc1b1b" stroke-width="2.5" stroke-linejoin="round"></path><path d="M20 42V34H28V42" stroke="#cc1b1b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>'
    ],
    [
        'key'   => 'roof repair',
        'label' => 'Roof Repair',
        'desc'  => 'Fast, reliable fixes for leaks, damaged panels, loose sheets, and rust problems. We restore your roof without a full replacement whenever possible.',
        'icon'  => '<path d="M8 28L24 12L40 28H34V40H14V28H8Z" fill="#cc1b1b" opacity=".12" stroke="#cc1b1b" stroke-width="2.5" stroke-linejoin="round"></path><path d="M20 24l3 3 5-6" stroke="#cc1b1b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></path>'
    ],
    [
        'key'   => 'roof replacement',
        'label' => 'Roof Replacement',
        'desc'  => 'Complete tear-off and re-roofing for aging or extensively damaged roofs. We handle removal, disposal, and full installation of your new APO roofing system.',
        'icon'  => '<path d="M6 30L24 10L42 30H36V42H12V30H6Z" fill="#cc1b1b" opacity=".12" stroke="#cc1b1b" stroke-width="2" stroke-linejoin="round" stroke-dasharray="3 2"></path><path d="M10 38L24 22L38 38" stroke="#cc1b1b" stroke-width="2.5" stroke-linejoin="round"></path>'
    ],
];

// Materials — mirrors the product catalog used in c_bookings.php.
$materials = [
    ['name' => 'Banawe',                                   'category' => 'Tile Profile'],
    ['name' => 'Hacienda (Royal Mediterranean Series)',    'category' => 'Tile Profile'],
    ['name' => 'Valencia (Royal Mediterranean Series)',    'category' => 'Tile Profile'],
    ['name' => 'Retrofitter',                              'category' => 'Tile Profile'],
    ['name' => 'Tilespan Elite',                           'category' => 'Tile Profile'],
    ['name' => 'Tilespan Maxi',                            'category' => 'Tile Profile'],
    ['name' => 'Satori Tile',                              'category' => 'Tile Profile'],
    ['name' => 'Twin Rib',                                 'category' => 'Rib / Corrugated'],
    ['name' => 'R-Span',                                   'category' => 'Rib / Corrugated'],
    ['name' => 'Multi R-Span',                             'category' => 'Rib / Corrugated'],
    ['name' => 'EPR',                                      'category' => 'Rib / Corrugated'],
    ['name' => 'Corspan',                                  'category' => 'Rib / Corrugated'],
    ['name' => 'Mega 2A Rib',                              'category' => 'Rib / Corrugated'],
    ['name' => 'Big V',                                    'category' => 'Rib / Corrugated'],
    ['name' => 'Super R-Span',                             'category' => 'Special Profile'],
    ['name' => 'Standing S-Span',                          'category' => 'Special Profile'],
    ['name' => 'S-Rib Standing Seam',                      'category' => 'Special Profile'],
    ['name' => 'Curve Roof',                               'category' => 'Special Profile'],
    ['name' => 'Spandrel',                                 'category' => 'Fascia & Ceiling'],
    ['name' => 'Steel Decking',                            'category' => 'Structural'],
];

$material_categories = ['All', 'Tile Profile', 'Rib / Corrugated', 'Special Profile', 'Fascia & Ceiling', 'Structural'];

// ---------------------------------------------------------
// Image URLs — replace with files you upload to
// uploads/landing/ when you're ready.
// ---------------------------------------------------------
$hero_image      = 'https://images.unsplash.com/photo-1632759145351-1d592919f522?w=1920&q=80';
$about_image     = 'https://images.unsplash.com/photo-1581094794329-c8112a89af12?w=900&q=80';
$materials_image = 'https://images.unsplash.com/photo-1621905251189-08b45d6a269e?w=800&q=80';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($company['company_name']) ?> – <?= htmlspecialchars($company['company_subtitle']) ?></title>
    <meta name="description" content="<?= htmlspecialchars($company['company_name']) ?> — quality roofing supply and installation for residential and commercial projects.">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
    /* =========================================================
       RESET & TOKENS
       ========================================================= */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
        --red: #cc1b1b;
        --red-dark: #aa1515;
        --red-soft: #fef2f2;
        --ink: #111111;
        --ink-soft: #333333;
        --gray-100: #f3f3f3;
        --gray-200: #e5e5e5;
        --gray-300: #cccccc;
        --gray-500: #6b7280;
        --bg-light: #f8f8f8;
        --white: #ffffff;
        --border: #e5e7eb;
        --radius-sm: 8px;
        --radius: 12px;
        --radius-lg: 16px;
        --radius-pill: 99px;
        --font-head: "Barlow Condensed", Arial, sans-serif;
        --font-body: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        --max-w: 1152px;
    }

    html { scroll-behavior: smooth; -webkit-text-size-adjust: 100%; }
    body {
        font-family: var(--font-body);
        color: var(--ink);
        background: var(--bg-light);
        line-height: 1.5;
        -webkit-font-smoothing: antialiased;
    }
    img { display: block; max-width: 100%; height: auto; }
    a { color: inherit; text-decoration: none; }
    button { font: inherit; cursor: pointer; border: none; background: none; color: inherit; }

    /* =========================================================
       LAYOUT
       ========================================================= */
    .container { max-width: var(--max-w); margin: 0 auto; padding: 0 24px; }

    /* =========================================================
       HEADER
       ========================================================= */
    .site-header {
        position: sticky;
        top: 0;
        z-index: 40;
        background: var(--white);
        border-bottom: 1px solid var(--border);
    }
    .site-header-inner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        height: 68px;
        gap: 16px;
    }
    .brand {
        display: flex;
        align-items: center;
        gap: 12px;
        font-family: var(--font-head);
        font-weight: 800;
        font-size: 20px;
        letter-spacing: .3px;
        color: var(--red);
        flex-shrink: 0;
    }
    .brand img { height: 44px; width: auto; object-fit: contain; }
    .brand .brand-text { line-height: 1.1; }
    .brand .brand-text small { display: block; font-family: var(--font-body); font-size: 11px; font-weight: 500; color: var(--gray-500); letter-spacing: 0; text-transform: none; }

    .site-nav {
        display: flex;
        align-items: center;
        gap: 32px;
        font-size: 14px;
        font-weight: 500;
        color: var(--gray-500);
    }
    .site-nav a:hover { color: var(--red); transition: color .15s; }

    .header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
    }
    
    .header-actions .btn-guest {
        margin-right: 6px;
    }

    /* =========================================================
       BUTTONS
       ========================================================= */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 12px 24px;
        border-radius: var(--radius-sm);
        font-family: var(--font-body);
        font-size: 14px;
        font-weight: 600;
        line-height: 1;
        white-space: nowrap;
        transition: background .15s, color .15s, border-color .15s;
        cursor: pointer;
        border: 1px solid transparent;
    }
    .btn-primary {
        background: var(--red);
        color: #fff;
        border-color: var(--red);
    }
    .btn-primary:hover { background: var(--red-dark); border-color: var(--red-dark); }

    .btn-ghost {
        background: transparent;
        color: var(--ink-soft);
        border-color: transparent;
    }
    .btn-ghost:hover { color: var(--red); }
    
    /* Guest booking button */
    .btn-guest {
        background: #fff;
        color: var(--red);
        border-color: var(--red);
        box-shadow: 0 2px 6px rgba(0,0,0,.06);
        font-weight: 700;
    }
    
    .btn-guest:hover {
        background: var(--red-soft);
        color: var(--red-dark);
        border-color: var(--red-dark);
        box-shadow: 0 4px 10px rgba(204,27,27,.12);
    }
    
    .guest-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 16px;
        height: 16px;
        flex-shrink: 0;
    }
    
    .guest-icon svg {
        width: 16px;
        height: 16px;
    }
    
    .guest-hero-btn {
        font-weight: 700;
        box-shadow: 0 6px 18px rgba(0,0,0,.18);
    }
    
    .guest-hero-btn:hover {
        box-shadow: 0 8px 22px rgba(0,0,0,.22);
    }

    .btn-outline-light {
        background: transparent;
        color: #fff;
        border-color: rgba(255,255,255,.3);
    }
    .btn-outline-light:hover {
        border-color: rgba(255,255,255,.7);
    }

    .btn-outline-red {
        background: #fff;
        color: var(--red);
        border-color: var(--red);
    }
    .btn-outline-red:hover {
        background: var(--red);
        color: #fff;
    }

    .btn-sm {
        padding: 9px 18px;
        font-size: 13px;
    }

    /* =========================================================
       HERO
       ========================================================= */
    .hero {
        position: relative;
        background: var(--ink);
        overflow: hidden;
        color: #fff;
    }
    .hero-bg {
        position: absolute; inset: 0;
        width: 100%; height: 100%;
        object-fit: cover;
        opacity: .35;
    }
    .hero-inner {
        position: relative;
        max-width: var(--max-w);
        margin: 0 auto;
        padding: 112px 24px;
    }
    .hero-badge {
        display: inline-block;
        background: var(--red);
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 2px;
        text-transform: uppercase;
        padding: 6px 12px;
        border-radius: 6px;
        margin-bottom: 24px;
    }
    .hero h1 {
        font-family: var(--font-head);
        font-weight: 800;
        font-size: clamp(3rem, 8vw, 6.5rem);
        line-height: 1;
        text-transform: uppercase;
        letter-spacing: .5px;
        margin-bottom: 24px;
        max-width: 900px;
    }
    .hero h1 .accent { color: var(--red); }
    .hero p {
        color: #d1d5db;
        font-size: 17px;
        line-height: 1.6;
        max-width: 560px;
        margin-bottom: 40px;
    }
    .hero-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
    }

    /* =========================================================
       SECTIONS
       ========================================================= */
    section.landing { padding: 80px 0; }
    section.landing--alt { background: var(--white); }

    .section-header {
        text-align: center;
        margin-bottom: 56px;
    }
    .section-eyebrow {
        display: inline-block;
        color: var(--red);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 2px;
        text-transform: uppercase;
        margin-bottom: 8px;
    }
    .section-header h2 {
        font-family: var(--font-head);
        font-weight: 700;
        font-size: clamp(2rem, 5vw, 3rem);
        text-transform: uppercase;
        letter-spacing: .5px;
        color: var(--ink);
        margin-bottom: 16px;
        line-height: 1.1;
    }
    .section-header p {
        color: var(--gray-500);
        font-size: 14px;
        line-height: 1.6;
        max-width: 480px;
        margin: 0 auto;
    }

    /* =========================================================
       SERVICES
       ========================================================= */
    .services-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 24px;
    }
    .service-card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 32px;
        display: flex;
        flex-direction: column;
        transition: border-color .2s, box-shadow .2s;
    }
    .service-card:hover {
        border-color: rgba(204,27,27,.3);
        box-shadow: 0 10px 24px rgba(0,0,0,.06);
    }
    .service-card .icon {
        width: 44px;
        height: 44px;
        margin-bottom: 20px;
    }
    .service-card h3 {
        font-family: var(--font-body);
        font-size: 18px;
        font-weight: 700;
        color: var(--ink);
        margin-bottom: 12px;
        transition: color .15s;
    }
    .service-card:hover h3 { color: var(--red); }
    .service-card p {
        color: var(--gray-500);
        font-size: 14px;
        line-height: 1.6;
        flex: 1;
    }
    .service-card .btn { margin-top: 24px; }

    /* CTA Banner */
    .cta-banner {
        background: var(--red);
        border-radius: var(--radius);
        padding: 40px;
        margin-top: 40px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 24px;
        flex-wrap: wrap;
    }
    .cta-banner h3 {
        font-family: var(--font-head);
        font-weight: 700;
        font-size: 30px;
        text-transform: uppercase;
        color: #fff;
        margin-bottom: 4px;
        line-height: 1.1;
        letter-spacing: .5px;
    }
    .cta-banner p {
        color: #ffd7d7;
        font-size: 14px;
    }
    .cta-banner .btn {
        background: #fff;
        color: var(--red);
        border-color: #fff;
        padding: 14px 28px;
    }
    .cta-banner .btn:hover { background: #fef2f2; border-color: #fef2f2; }

    /* =========================================================
       MATERIALS
       ========================================================= */
    .materials-layout {
        display: grid;
        grid-template-columns: 1fr 1.1fr;
        gap: 56px;
        align-items: start;
    }
    .materials-left h2 {
        font-family: var(--font-head);
        font-weight: 700;
        font-size: clamp(2rem, 5vw, 3rem);
        text-transform: uppercase;
        letter-spacing: .5px;
        line-height: 1.1;
        margin: 8px 0 16px;
    }
    .materials-left p {
        color: var(--gray-500);
        font-size: 14px;
        line-height: 1.6;
        margin-bottom: 8px;
    }
    .materials-left strong { color: var(--ink-soft); }

    .filter-pills {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin: 24px 0 20px;
    }
    .filter-pill {
        padding: 6px 14px;
        border-radius: var(--radius-pill);
        font-size: 12px;
        font-weight: 600;
        background: var(--gray-100);
        color: var(--gray-500);
        transition: background .15s, color .15s;
        cursor: pointer;
        border: none;
    }
    .filter-pill:hover { background: var(--gray-200); }
    .filter-pill.active { background: var(--red); color: #fff; }

    .materials-list {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    .material-item {
        background: var(--bg-light);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 14px 16px;
    }
    .material-item .name {
        font-size: 13px;
        font-weight: 600;
        color: var(--ink-soft);
        line-height: 1.3;
    }
    .material-item .category {
        font-size: 10px;
        font-weight: 700;
        color: var(--red);
        letter-spacing: 1px;
        text-transform: uppercase;
        margin-top: 6px;
    }

    .materials-note {
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: var(--radius);
        padding: 12px 16px;
        display: flex;
        gap: 12px;
        align-items: flex-start;
        margin-top: 22px;
    }
    .materials-note svg { width: 20px; height: 20px; flex-shrink: 0; color: #f59e0b; }
    .materials-note p { font-size: 12px; color: #92400e; line-height: 1.5; }
    .materials-note strong { color: #78350f; }

    .materials-right { position: relative; }
    .materials-right img {
        position: relative;
        z-index: 1;
        width: 100%;
        border-radius: var(--radius-lg);
        box-shadow: 0 8px 24px rgba(0,0,0,.08);
        object-fit: cover;
    }
    .materials-right::before {
        content: "";
        position: absolute;
        top: -12px;
        right: -12px;
        width: 100%;
        height: 100%;
        border: 2px solid rgba(204,27,27,.15);
        border-radius: var(--radius-lg);
        z-index: 0;
    }

    /* =========================================================
       ABOUT
       ========================================================= */
    .about-layout {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 56px;
        align-items: center;
    }
    .about-left h2 {
        font-family: var(--font-head);
        font-weight: 700;
        font-size: clamp(2rem, 5vw, 3rem);
        text-transform: uppercase;
        letter-spacing: .5px;
        line-height: 1.1;
        margin: 8px 0 24px;
    }
    .about-left p {
        color: var(--gray-500);
        font-size: 14px;
        line-height: 1.7;
        margin-bottom: 24px;
    }

    .about-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
    }
    .about-stat {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 16px 12px;
        text-align: center;
    }
    .about-stat .value {
        font-family: var(--font-head);
        font-weight: 700;
        font-size: 30px;
        color: var(--red);
        line-height: 1;
        margin-bottom: 4px;
    }
    .about-stat .label {
        font-size: 11px;
        color: var(--gray-500);
        line-height: 1.3;
    }

    .about-right { position: relative; }
    .about-right img {
        border-radius: var(--radius-lg);
        width: 100%;
        height: 340px;
        object-fit: cover;
    }
    .about-badge {
        position: absolute;
        bottom: -20px;
        left: -20px;
        background: var(--red);
        color: #fff;
        border-radius: var(--radius);
        padding: 16px 22px;
        box-shadow: 0 10px 24px rgba(0,0,0,.18);
    }
    .about-badge .big {
        font-family: var(--font-head);
        font-weight: 700;
        font-size: 22px;
        line-height: 1;
        letter-spacing: .5px;
        margin-bottom: 2px;
    }
    .about-badge .small {
        font-size: 11px;
        color: #ffd7d7;
    }

    /* =========================================================
       CONTACT
       ========================================================= */
    .contact-wrap {
        max-width: 640px;
        margin: 0 auto;
        text-align: center;
    }
    .contact-card {
        background: var(--bg-light);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 32px;
        text-align: left;
        margin-top: 40px;
    }
    .contact-row {
        display: flex;
        gap: 16px;
        align-items: flex-start;
        padding: 10px 0;
    }
    .contact-row .label {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 2px;
        text-transform: uppercase;
        color: var(--red);
        width: 80px;
        padding-top: 2px;
        flex-shrink: 0;
    }
    .contact-row .value {
        font-size: 14px;
        color: var(--ink-soft);
        word-break: break-word;
    }
    .contact-card .divider {
        border-top: 1px solid var(--border);
        margin: 16px 0;
    }
    .contact-card .btn { width: 100%; padding: 14px; }

    /* =========================================================
       FOOTER
       ========================================================= */
    .site-footer {
        background: #fff;
        border-top: 1px solid var(--border);
        padding: 24px 0;
    }
    .site-footer-inner {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
    }
    .site-footer img { height: 36px; width: auto; object-fit: contain; }
    .site-footer p {
        font-size: 12px;
        color: #9ca3af;
    }

    /* =========================================================
       RESPONSIVE
       ========================================================= */
    @media (max-width: 1024px) {
        .materials-layout { grid-template-columns: 1fr; gap: 40px; }
        .about-layout { grid-template-columns: 1fr; gap: 40px; }
        .about-right { margin-bottom: 30px; }
    }

    @media (max-width: 900px) {
        .services-grid { grid-template-columns: 1fr 1fr; }
        .site-nav { display: none; }
    }

    @media (max-width: 640px) {
        .container { padding: 0 20px; }
        .hero-inner { padding: 72px 20px; }
        .hero-actions { flex-direction: column; align-items: stretch; }
        .hero-actions .btn { width: 100%; }
        .services-grid { grid-template-columns: 1fr; }
        .materials-list { grid-template-columns: 1fr; }
        .about-stats { grid-template-columns: 1fr 1fr; }
        .cta-banner { padding: 28px; }
        .cta-banner .btn { width: 100%; }
        .contact-card { padding: 22px; }
        .contact-row { flex-direction: column; gap: 4px; }
        .contact-row .label { width: auto; }
        .about-badge { left: 0; bottom: -12px; }
        .header-actions .btn { padding: 9px 14px; font-size: 13px; }
        .brand { font-size: 17px; }
        .brand img { height: 38px; }
    }
    </style>
</head>
<body>

<!-- =============================================================
     HEADER
     ============================================================= -->
<header class="site-header">
    <div class="container site-header-inner">
        <a href="index.php" class="brand">
            <?php if (!empty($company['company_logo'])): ?>
                <img src="<?= htmlspecialchars($company['company_logo']) ?>" alt="<?= htmlspecialchars($company['company_name']) ?>">
            <?php else: ?>
                <span class="brand-text">
                    <?= htmlspecialchars($company['company_name']) ?>
                    <?php if (!empty($company['company_subtitle'])): ?>
                        <small><?= htmlspecialchars($company['company_subtitle']) ?></small>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </a>

        <nav class="site-nav">
            <a href="#services">Services</a>
            <a href="#materials">Materials</a>
            <a href="#about">About</a>
            <a href="#contact">Contact</a>
        </nav>

        <div class="header-actions">
            <a href="guest_booking.php" class="btn btn-guest btn-sm">
                <span class="guest-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"
                              stroke="currentColor" stroke-width="1.8"/>
                        <path d="M4.5 20a7.5 7.5 0 0 1 15 0"
                              stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                </span>
                Book Without an Account
            </a>
            <a href="login.php" class="btn btn-ghost btn-sm">Log In</a>
            <a href="login.php?mode=register" class="btn btn-primary btn-sm">Register</a>
        </div>
    </div>
</header>


<!-- =============================================================
     HERO
     ============================================================= -->
<section class="hero">
    <img src="<?= htmlspecialchars($hero_image) ?>" alt="" class="hero-bg" aria-hidden="true">
    <div class="hero-inner">
        <span class="hero-badge">Est. 2012 · Authorized APO Roofs Installer</span>

        <h1>
            Quality Roofing<br>
            <span class="accent">Supply &amp;</span><br>
            Installation
        </h1>

        <p>
            <?= htmlspecialchars($company['company_name']) ?>
            installs, repairs, and replaces roofs using APO Galfan Engineered Roofing Systems — the country's first roofing system with a 10-year corrosion warranty.
        </p>

        <div class="hero-actions">
            <a href="guest_booking.php" class="btn btn-primary guest-hero-btn">
                <span class="guest-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"
                              stroke="currentColor" stroke-width="1.8"/>
                        <path d="M4.5 20a7.5 7.5 0 0 1 15 0"
                              stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                </span>
                Book Without an Account
            </a>
            <a href="login.php?mode=register" class="btn btn-outline-light">Register</a>
            <a href="#services" class="btn btn-outline-light">View Services</a>
        </div>
    </div>
</section>


<!-- =============================================================
     SERVICES
     ============================================================= -->
<section id="services" class="landing">
    <div class="container">
        <div class="section-header">
            <span class="section-eyebrow">What We Offer</span>
            <h2>Our Services</h2>
            <p>Register to book any service. Our team will reach out within 24 hours to schedule your project.</p>
        </div>

        <div class="services-grid">
            <?php foreach ($services as $svc): ?>
                <div class="service-card">
                    <svg class="icon" viewBox="0 0 48 48" fill="none" aria-hidden="true">
                        <?= $svc['icon'] ?>
                    </svg>
                    <h3><?= htmlspecialchars($svc['label']) ?></h3>
                    <p><?= htmlspecialchars($svc['desc']) ?></p>
                    <a href="login.php?mode=register&amp;service=<?= urlencode($svc['key']) ?>" class="btn btn-outline-red">
                        Book This Service
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="cta-banner">
            <div>
                <h3>Ready to get started?</h3>
                <p>Create a free account to request a service and track your project.</p>
            </div>
            <a href="login.php?mode=register" class="btn">Register for Free</a>
        </div>
    </div>
</section>


<!-- =============================================================
     MATERIALS
     ============================================================= -->
<section id="materials" class="landing landing--alt">
    <div class="container">
        <div class="materials-layout">

            <div class="materials-left">
                <span class="section-eyebrow">What We Use</span>
                <h2>APO Roofs<br>Materials</h2>
                <p>
                    We exclusively use <strong>APO Galfan Engineered Roofing System</strong> by Puyat Steel Corporation —
                    the Philippines' first roofing brand with a <strong>10-year warranty against corrosion</strong> and a
                    2-year installation warranty.
                </p>

                <div class="filter-pills" id="materialFilters">
                    <?php foreach ($material_categories as $i => $cat): ?>
                        <button type="button"
                                class="filter-pill <?= $i === 0 ? 'active' : '' ?>"
                                data-category="<?= htmlspecialchars($cat) ?>">
                            <?= htmlspecialchars($cat) ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="materials-list" id="materialsList">
                    <?php foreach ($materials as $m): ?>
                        <div class="material-item" data-category="<?= htmlspecialchars($m['category']) ?>">
                            <div class="name"><?= htmlspecialchars($m['name']) ?></div>
                            <div class="category"><?= htmlspecialchars($m['category']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="materials-note">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" stroke-linecap="round" stroke-linejoin="round"></path>
                    </svg>
                    <p><strong>Lead-free · ISO 9001 &amp; ISO 14001 Certified</strong> — Awarded Architect's Choice Award 2014 by BCI Asia.</p>
                </div>
            </div>

            <div class="materials-right">
                <img src="<?= htmlspecialchars($materials_image) ?>" alt="APO Roofs product catalog">
            </div>

        </div>
    </div>
</section>


<!-- =============================================================
     ABOUT
     ============================================================= -->
<section id="about" class="landing">
    <div class="container">
        <div class="about-layout">

            <div class="about-left">
                <span class="section-eyebrow">About <?= htmlspecialchars($company['company_name']) ?></span>
                <h2>Over a Decade<br>of Expertise</h2>
                <p>
                    <?= htmlspecialchars($company['company_name']) ?>
                    has been serving Filipino homeowners and businesses since 2012.
                    We combine quality APO materials, skilled workmanship, and honest pricing to deliver roofs built to last.
                </p>

                <div class="about-stats">
                    <div class="about-stat">
                        <div class="value">12+</div>
                        <div class="label">Years in Business</div>
                    </div>
                    <div class="about-stat">
                        <div class="value">1,000+</div>
                        <div class="label">Projects Done</div>
                    </div>
                    <div class="about-stat">
                        <div class="value">100%</div>
                        <div class="label">Client Satisfaction</div>
                    </div>
                </div>
            </div>

            <div class="about-right">
                <img src="<?= htmlspecialchars($about_image) ?>" alt="Our team on site">
                <div class="about-badge">
                    <div class="big">Est. 2012</div>
                    <div class="small">Trusted Since Day One</div>
                </div>
            </div>

        </div>
    </div>
</section>


<!-- =============================================================
     CONTACT
     ============================================================= -->
<section id="contact" class="landing landing--alt">
    <div class="container">
        <div class="contact-wrap">
            <span class="section-eyebrow">Get in Touch</span>
            <h2 class="section-header" style="margin: 8px 0 8px;">Contact Us</h2>
            <p style="color: var(--gray-500); font-size: 14px; margin-bottom: 8px;">
                Have questions? Reach us directly and we'll get back to you shortly.
            </p>

            <div class="contact-card">
                <?php if (!empty($company['company_phone'])): ?>
                    <div class="contact-row">
                        <span class="label">Phone</span>
                        <span class="value"><?= htmlspecialchars($company['company_phone']) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($company['company_email'])): ?>
                    <div class="contact-row">
                        <span class="label">Email</span>
                        <span class="value"><?= htmlspecialchars($company['company_email']) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($company['company_address'])): ?>
                    <div class="contact-row">
                        <span class="label">Address</span>
                        <span class="value"><?= htmlspecialchars($company['company_address']) ?></span>
                    </div>
                <?php endif; ?>

                <div class="divider"></div>

                <a href="login.php?mode=register" class="btn btn-primary">
                    Register to Book a Service
                </a>
            </div>
        </div>
    </div>
</section>


<!-- =============================================================
     FOOTER
     ============================================================= -->
<footer class="site-footer">
    <div class="container site-footer-inner">
        <?php if (!empty($company['company_logo'])): ?>
            <img src="<?= htmlspecialchars($company['company_logo']) ?>" alt="<?= htmlspecialchars($company['company_name']) ?>">
        <?php else: ?>
            <strong style="color: var(--red); font-family: var(--font-head); font-size: 18px;">
                <?= htmlspecialchars($company['company_name']) ?>
            </strong>
        <?php endif; ?>

        <p>
            © <?= date('Y') ?> <?= htmlspecialchars($company['company_name']) ?>.
            All rights reserved.
        </p>
    </div>
</footer>


<script>
/* =========================================================
   Material filter pills
   ========================================================= */
(function () {
    var pills = document.querySelectorAll('#materialFilters .filter-pill');
    var items = document.querySelectorAll('#materialsList .material-item');

    pills.forEach(function (pill) {
        pill.addEventListener('click', function () {
            var cat = pill.dataset.category;

            pills.forEach(function (p) { p.classList.remove('active'); });
            pill.classList.add('active');

            items.forEach(function (item) {
                var show = (cat === 'All' || item.dataset.category === cat);
                item.style.display = show ? '' : 'none';
            });
        });
    });
})();
</script>

</body>
</html>