<?php
/**
 * foreman_profile.php — Public employee verification page.
 *
 * Reached only via a rotatable opaque token (?t=<32 hex chars>).
 * No login required. Shows the minimum needed to verify that the
 * person at the door is who they claim to be.
 *
 * Deliberately excludes: password, daily_rate, email, address,
 * face_descriptor, attendance data, username, created_at.
 *
 * Includes: name, photo, employee_id, phone, join_date, role, active status.
 * The phone number is only exposed so a client at their door can call the
 * foreman directly; nothing else about the account is reachable from this page.
 */

require_once 'config.php';

/* ------------------------------------------------------------------
 * Shared tiny renderer for the error states.
 * ------------------------------------------------------------------ */
function renderForemanProfileError(string $title, string $message, int $status = 404): void
{
    http_response_code($status);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Profile Not Available</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body {
    background: #f6f7f9; color: #111827; margin: 0;
    min-height: 100vh; display: flex; align-items: center; justify-content: center;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    padding: 20px;
  }
  .err-card {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 14px;
    max-width: 420px; width: 100%; padding: 32px; text-align: center;
    box-shadow: 0 1px 3px rgba(16,24,40,.08);
  }
  .err-card .bi { font-size: 40px; color: #d92027; }
  .err-card h1 { font-size: 18px; font-weight: 700; margin: 14px 0 8px; }
  .err-card p { color: #6b7280; font-size: 13.5px; margin: 0; line-height: 1.55; }
</style>
</head>
<body>
  <div class="err-card">
    <i class="bi bi-shield-exclamation"></i>
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($message) ?></p>
  </div>
</body>
</html>
    <?php
}

/* ------------------------------------------------------------------
 * 1. Validate the token format BEFORE touching the database.
 * ------------------------------------------------------------------ */
$token = $_GET['t'] ?? '';
if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/i', $token)) {
    renderForemanProfileError(
        'Invalid Link',
        'This verification link is malformed. Please scan the QR code again.'
    );
    exit;
}

/* ------------------------------------------------------------------
 * 2. Fetch the foreman by token — column whitelist only.
 * ------------------------------------------------------------------ */
$stmt = $pdo->prepare("
    SELECT
        id,
        full_name,
        employee_id,
        phone,
        profile_photo,
        join_date,
        status,
        public_profile_enabled
    FROM users
    WHERE public_profile_token = ?
      AND role = 'foreman'
    LIMIT 1
");
$stmt->execute([$token]);
$foreman = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$foreman || (int)$foreman['public_profile_enabled'] !== 1) {
    renderForemanProfileError(
        'Profile Not Available',
        'This verification link is no longer active. If someone is at your door claiming to be an ENG Roofing foreman, please contact our office directly.'
    );
    exit;
}

/* ------------------------------------------------------------------
 * 3. Company branding (public info — no secrets in this table).
 * ------------------------------------------------------------------ */
$stmt = $pdo->query("
    SELECT company_name, company_subtitle, company_phone, company_email, company_logo
    FROM system_settings
    ORDER BY id ASC
    LIMIT 1
");
$company = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'company_name'     => 'Eng Roofing',
    'company_subtitle' => 'Supply and Installation',
    'company_phone'    => '',
    'company_email'    => '',
    'company_logo'     => '',
];

/* ------------------------------------------------------------------
 * 4. Resolve asset paths safely (only allow files that actually exist).
 * ------------------------------------------------------------------ */
function resolvePublicAsset(?string $relative): string
{
    if (empty($relative)) return '';
    $relative = ltrim($relative, '/\\');
    foreach ([__DIR__, dirname(__DIR__)] as $base) {
        if (is_file($base . '/' . $relative)) return $relative;
    }
    return '';
}

$logoPath  = resolvePublicAsset($company['company_logo']);
$photoPath = resolvePublicAsset($foreman['profile_photo']);

$isActive = ($foreman['status'] === 'active');
$initial  = strtoupper(mb_substr(trim($foreman['full_name']), 0, 1));

/* Don't let browsers cache a stale "Active" badge. */
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>Employee Verification — <?= htmlspecialchars($company['company_name']) ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<style>
:root {
    --fr-red: #d92027;
    --fr-red-soft: #fef2f2;
    --fr-bg: #f6f7f9;
    --fr-surface: #ffffff;
    --fr-border: #e5e7eb;
    --fr-text: #111827;
    --fr-muted: #6b7280;
}

* { -webkit-tap-highlight-color: transparent; }
html, body {
    margin: 0; padding: 0; min-height: 100%; background: var(--fr-bg);
    color: var(--fr-text);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 14px; -webkit-font-smoothing: antialiased;
}

.verify-shell {
    max-width: 460px; margin: 0 auto;
    padding: 32px 18px calc(40px + env(safe-area-inset-bottom, 0px));
}

.verify-card {
    background: var(--fr-surface);
    border: 1px solid var(--fr-border);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(16,24,40,.08);
}

/* -------- Company header -------- */
.verify-header {
    padding: 22px 22px 20px;
    text-align: center;
    border-bottom: 1px solid var(--fr-border);
    background: #fafbfc;
}
.verify-brand-logo {
    width: 56px; height: 56px; border-radius: 12px;
    object-fit: contain; display: inline-block;
    background: #fff; border: 1px solid var(--fr-border); padding: 4px;
}
.verify-brand-logo-fallback {
    display: inline-flex; align-items: center; justify-content: center;
    width: 56px; height: 56px; border-radius: 12px;
    background: var(--fr-red); color: #fff;
    font-weight: 800; font-size: 18px; letter-spacing: -0.5px;
}
.verify-brand-name {
    font-size: 16px; font-weight: 700; margin: 12px 0 2px;
    color: var(--fr-text);
}
.verify-brand-sub {
    font-size: 12px; color: var(--fr-muted); margin: 0;
}

/* -------- Photo + name -------- */
.verify-body { padding: 28px 22px 22px; text-align: center; }

.verify-photo-frame {
    width: 140px; height: 140px; border-radius: 50%;
    overflow: hidden; margin: 0 auto 16px;
    border: 4px solid var(--fr-surface);
    outline: 3px solid var(--fr-border);
    background: var(--fr-bg);
    display: flex; align-items: center; justify-content: center;
}
.verify-photo-frame img {
    width: 100%; height: 100%; object-fit: cover; display: block;
}
.verify-photo-fallback {
    font-size: 52px; font-weight: 800; color: var(--fr-red);
    letter-spacing: -1px;
}

.verify-name {
    font-size: 24px; font-weight: 800; line-height: 1.15;
    color: var(--fr-text); margin: 0 0 6px;
}
.verify-role {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 1px; color: var(--fr-muted); margin: 0;
}

/* -------- Verification seal -------- */
.verify-seal {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 7px 14px; border-radius: 99px;
    font-size: 12.5px; font-weight: 700;
    margin-top: 16px;
    border: 1px solid transparent;
}
.verify-seal.is-active {
    background: #ecfdf5; color: #047857; border-color: #a7f3d0;
}
.verify-seal.is-inactive {
    background: #fef2f2; color: #b91c1c; border-color: #fecaca;
}
.verify-seal .bi { font-size: 15px; }

/* -------- Details grid -------- */
.verify-details {
    margin-top: 22px;
    border-top: 1px solid var(--fr-border);
    padding-top: 18px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px 18px;
    text-align: left;
}
.verify-detail {
    min-width: 0;
}
.verify-detail.full { grid-column: 1 / -1; }
.verify-detail-label {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .6px; color: var(--fr-muted); margin-bottom: 4px;
    display: block;
}
.verify-detail-value {
    font-size: 14px; font-weight: 600; color: var(--fr-text);
    line-height: 1.4; overflow-wrap: anywhere;
}
.verify-detail-value.is-muted {
    color: var(--fr-muted); font-weight: 500;
}
/* Phone number — clickable, brand red, no underline, monospaced digits */
.verify-phone-link {
    color: var(--fr-red);
    text-decoration: none;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    letter-spacing: .3px;
}
.verify-phone-link:hover,
.verify-phone-link:focus {
    text-decoration: underline;
    color: var(--fr-red);
}

/* -------- Footer -------- */
.verify-footer {
    padding: 16px 22px 20px;
    border-top: 1px solid var(--fr-border);
    background: #fafbfc;
    font-size: 11.5px;
    color: var(--fr-muted);
    text-align: center;
    line-height: 1.6;
}
.verify-footer strong { color: var(--fr-text); }
.verify-footer a {
    color: var(--fr-red); text-decoration: none; font-weight: 600;
}
.verify-footer a:hover { text-decoration: underline; }
</style>
</head>
<body>

<div class="verify-shell">
    <div class="verify-card">

        <!-- Company -->
        <div class="verify-header">
            <?php if ($logoPath): ?>
                <img src="<?= htmlspecialchars($logoPath) ?>"
                     alt="<?= htmlspecialchars($company['company_name']) ?>"
                     class="verify-brand-logo">
            <?php else: ?>
                <div class="verify-brand-logo-fallback">ENG</div>
            <?php endif; ?>
            <div class="verify-brand-name">
                <?= htmlspecialchars($company['company_name']) ?>
            </div>
            <?php if (!empty($company['company_subtitle'])): ?>
                <p class="verify-brand-sub"><?= htmlspecialchars($company['company_subtitle']) ?></p>
            <?php endif; ?>
        </div>

        <!-- Foreman -->
        <div class="verify-body">

            <div class="verify-photo-frame">
                <?php if ($photoPath): ?>
                    <img src="<?= htmlspecialchars($photoPath) ?>"
                         alt="<?= htmlspecialchars($foreman['full_name']) ?>">
                <?php else: ?>
                    <span class="verify-photo-fallback"><?= htmlspecialchars($initial) ?></span>
                <?php endif; ?>
            </div>

            <h1 class="verify-name"><?= htmlspecialchars($foreman['full_name']) ?></h1>
            <p class="verify-role">Site Foreman</p>

            <?php if ($isActive): ?>
                <div class="verify-seal is-active">
                    <i class="bi bi-patch-check-fill"></i>
                    <span>Verified Company Employee</span>
                </div>
            <?php else: ?>
                <div class="verify-seal is-inactive">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span>No Longer Active</span>
                </div>
            <?php endif; ?>

            <div class="verify-details">
                <div class="verify-detail">
                    <span class="verify-detail-label">Employee ID</span>
                    <div class="verify-detail-value">
                        <?= htmlspecialchars($foreman['employee_id'] ?: '—') ?>
                    </div>
                </div>
                <div class="verify-detail">
                    <span class="verify-detail-label">Role</span>
                    <div class="verify-detail-value">Foreman</div>
                </div>

                <div class="verify-detail">
                    <span class="verify-detail-label">Contact Number</span>
                    <?php if (!empty($foreman['phone'])): ?>
                        <div class="verify-detail-value">
                            <a href="tel:<?= htmlspecialchars($foreman['phone']) ?>"
                               class="verify-phone-link">
                                <?= htmlspecialchars($foreman['phone']) ?>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="verify-detail-value is-muted">—</div>
                    <?php endif; ?>
                </div>

                <div class="verify-detail">
                    <span class="verify-detail-label">With Company Since</span>
                    <div class="verify-detail-value <?= empty($foreman['join_date']) ? 'is-muted' : '' ?>">
                        <?= !empty($foreman['join_date'])
                            ? htmlspecialchars(date('F j, Y', strtotime($foreman['join_date'])))
                            : '—' ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="verify-footer">
            <div style="margin-bottom: 6px;">
                Verified by <strong><?= htmlspecialchars($company['company_name']) ?></strong>
            </div>
            <?php if (!empty($company['company_phone']) || !empty($company['company_email'])): ?>
                <div>
                    Need to confirm?
                    <?php if (!empty($company['company_phone'])): ?>
                        Call <a href="tel:<?= htmlspecialchars($company['company_phone']) ?>">
                            <?= htmlspecialchars($company['company_phone']) ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($company['company_phone']) && !empty($company['company_email'])): ?>
                        &nbsp;·&nbsp;
                    <?php endif; ?>
                    <?php if (!empty($company['company_email'])): ?>
                        <a href="mailto:<?= htmlspecialchars($company['company_email']) ?>">
                            <?= htmlspecialchars($company['company_email']) ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div>Please contact our office to confirm this employee.</div>
            <?php endif; ?>
        </div>

    </div>
</div>

</body>
</html>