<?php
// REVISION: Shared feedback invitation helper. Requires config.php first.
// Install install_feedback_links.sql before using these functions.
function sendProjectFeedbackInvitation(PDO $pdo, int $projectId): bool
{
    try {
        // Persist the invitation before sending so a failed email can be retried.
        $stmt = $pdo->prepare("SELECT p.project_name, u.email, u.full_name
            FROM projects p JOIN users u ON u.id = p.client_id
            WHERE p.id = ? AND p.status = 'completed'");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$project || !filter_var($project['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $stmt = $pdo->prepare('INSERT INTO project_feedback_links (project_id, token)
            VALUES (?, ?) ON DUPLICATE KEY UPDATE project_id = VALUES(project_id)');
        $stmt->execute([$projectId, bin2hex(random_bytes(32))]);

        // Serialize retries: a successful invitation is never automatically resent.
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT token, email_sent_at, revoked_at
            FROM project_feedback_links WHERE project_id = ? FOR UPDATE');
        $stmt->execute([$projectId]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($link['revoked_at']) {
            $pdo->rollBack();
            return false;
        }
        if ($link['email_sent_at']) {
            $pdo->commit();
            return true;
        }

        // Fixed trusted origin; never construct email links from the Host header.
        $url = 'https://bisque-lobster-868115.hostingersite.com/guest_feedback.php?token='
            . rawurlencode($link['token']);
        $name = htmlspecialchars($project['full_name'], ENT_QUOTES, 'UTF-8');
        $projectName = htmlspecialchars($project['project_name'], ENT_QUOTES, 'UTF-8');
        $body = '<p>Hello ' . $name . ',</p><p>Your project <strong>' . $projectName
            . '</strong> is completed. Please share your experience with ENG Roofing.</p>'
            . '<p><a href="' . $url . '">Leave Feedback / Rate Our Service</a></p>'
            . '<p>No login is required. Keep this private link to yourself. '
            . 'If you already submitted a review, no new submission is needed.</p>';
        if (!sendMail($project['email'], $project['full_name'], 'ENG Roofing — Rate Your Completed Project', $body)) {
            $pdo->rollBack();
            return false;
        }
        $stmt = $pdo->prepare('UPDATE project_feedback_links SET email_sent_at = NOW()
            WHERE project_id = ?');
        $stmt->execute([$projectId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Feedback invitation failed: ' . $e->getMessage());
        return false;
    }
}
