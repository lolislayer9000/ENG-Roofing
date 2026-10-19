<?php
// REVISION: Private feedback form for one completed project, without login.
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__ . '/config.php';

function gfEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$project = null;
$error = '';
$reviewExists = false;
$fields = [
    'installation_rating' => 'Installation Quality',
    'service_rating' => 'Service Quality',
    'timeliness_rating' => 'Timeliness',
    'professionalism_rating' => 'Professionalism'
];

if (preg_match('/^[a-f0-9]{64}$/D', $token)) {
    $stmt = $pdo->prepare("SELECT p.id, p.project_id, p.project_name, p.client_id
        FROM project_feedback_links l JOIN projects p ON p.id = l.project_id
        WHERE l.token = ? AND l.revoked_at IS NULL AND p.status = 'completed'");
    $stmt->execute([$token]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$project) {
    http_response_code(404);
} else {
    // Existing portal reviews also prevent a second review through the guest link.
    $stmt = $pdo->prepare('SELECT id FROM feedback WHERE project_id = ? AND client_id = ? LIMIT 1');
    $stmt->execute([$project['id'], $project['client_id']]);
    $reviewExists = (bool)$stmt->fetchColumn();
}

if ($project && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null) || !csrf_validate($_POST['csrf_token'])) {
        $error = 'Your session expired. Refresh this page and try again.';
    }
    $ratings = [];
    foreach ($fields as $field => $label) {
        $rating = filter_var($_POST[$field] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 5]]);
        if ($rating === false) {
            $error = 'Please select a rating from 1 to 5 for every category.';
        }
        $ratings[] = $rating;
    }
    $comments = is_string($_POST['comments'] ?? null) ? trim($_POST['comments']) : '';
    if (strlen($comments) > 5000) {
        $error = 'Please keep your comments within 5,000 bytes.';
    }
    if (!$error) {
        try {
            $pdo->beginTransaction();
            // Same project lock as the existing portal: concurrent submissions serialize.
            $stmt = $pdo->prepare('SELECT status FROM projects WHERE id = ? FOR UPDATE');
            $stmt->execute([$project['id']]);
            if ($stmt->fetchColumn() !== 'completed') {
                throw new RuntimeException('Project is no longer completed.');
            }
            $stmt = $pdo->prepare('SELECT submitted_at, revoked_at FROM project_feedback_links
                WHERE project_id = ? AND token = ? FOR UPDATE');
            $stmt->execute([$project['id'], $token]);
            $link = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$link || $link['revoked_at']) {
                throw new RuntimeException('Feedback link is unavailable.');
            }
            $stmt = $pdo->prepare('SELECT id FROM feedback WHERE project_id = ? AND client_id = ? LIMIT 1');
            $stmt->execute([$project['id'], $project['client_id']]);
            $reviewExists = (bool)$stmt->fetchColumn();
            $inserted = !$reviewExists && !$link['submitted_at'];
            if ($inserted) {
                $stmt = $pdo->prepare('INSERT INTO feedback (project_id, client_id,
                    installation_rating, service_rating, timeliness_rating, professionalism_rating, comments)
                    VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute(array_merge([$project['id'], $project['client_id']], $ratings, [$comments]));
                $stmt = $pdo->prepare('UPDATE project_feedback_links SET submitted_at = NOW() WHERE project_id = ?');
                $stmt->execute([$project['id']]);
            }
            $pdo->commit();
            if ($inserted) {
                // Optional notification failure must not undo the saved feedback.
                try {
                    $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")
                        ->fetchAll(PDO::FETCH_COLUMN);
                    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, project_id, message) VALUES (?, ?, ?)');
                    foreach ($admins as $adminId) {
                        $stmt->execute([$adminId, $project['id'], 'Client submitted email-linked feedback for project ' . $project['project_name'] . '.']);
                    }
                } catch (Throwable $e) {
                    error_log('Feedback notification failed: ' . $e->getMessage());
                }
            }
            // Redirect prevents browser refresh from reposting the form.
            header('Location: guest_feedback.php?token=' . rawurlencode($token));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Guest feedback failed: ' . $e->getMessage());
            $error = 'Unable to save feedback. Please refresh and try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ENG Roofing — Project Feedback</title>
    <!-- REVISION: Shared guest branding; no changes to project/feedback rules. -->
<link rel="stylesheet" href="assets/guest-pages.css?v=1">
</head>
<body class="feedback-page">
    <header>
        <div class="brand">
            <img class="brand-logo" src="assets/eng-roofing-logo.jpg" alt="ENG Roofing logo" width="76" height="76">
            <div class="brand-copy">
                <strong>ENG ROOFING</strong>
                <small>Roofing Supply and Installation</small>
                <small>Project Feedback</small>
            </div>
        </div>
    </header>
    <main>
        <section class="card">
            <?php if (!$project): ?>
                <h1>Feedback link unavailable</h1>
                <p>Please use the private link in your completion email, or contact ENG Roofing.</p>
            <?php elseif ($reviewExists): ?>
                <h1>Thank you for your feedback!</h1>
                <p>A review has already been recorded for this project.</p>
                <p class="muted"><?= gfEscape($project['project_id']) ?></p>
            <?php else: ?>
                <h1>How was your experience?</h1>
                <p><?= gfEscape($project['project_name']) ?> · <?= gfEscape($project['project_id']) ?></p>
                <p class="muted">Rate each category from 1 (Poor) to 5 (Excellent). No login required.</p>
                <?php if ($error): ?><p class="error" role="alert"><?= gfEscape($error) ?></p><?php endif; ?>
                <form method="POST">
                    <?= csrf_field() ?>
                    <?php foreach ($fields as $field => $label): ?>
                        <label for="<?= $field ?>"><?= gfEscape($label) ?> *</label>
                        <select id="<?= $field ?>" name="<?= $field ?>" required>
                            <option value="">Select rating</option>
                            <?php for ($rating = 1; $rating <= 5; $rating++): ?>
                                <option value="<?= $rating ?>"
                                    <?= is_scalar($_POST[$field] ?? null) && (string)$_POST[$field] === (string)$rating ? 'selected' : '' ?>>
                                    <?= $rating ?> <?= str_repeat('★', $rating) ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    <?php endforeach; ?>
                    <label for="comments">Comments (optional)</label>
                    <textarea id="comments" name="comments" rows="5" maxlength="5000"><?= gfEscape(is_string($_POST['comments'] ?? null) ? $_POST['comments'] : '') ?></textarea>
                    <button type="submit">Submit Feedback</button>
                </form>
                <p class="muted">Keep this link private. One review per project through this link.</p>
            <?php endif; ?>
        </section>
    </main>
<footer>ENG Roofing · Roofing Supply and Installation</footer>
</body>
</html>
