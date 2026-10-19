<?php
// This file is included inside client.php.

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$client_id = (int)$_SESSION['user_id'];
$client_name = $_SESSION['full_name'] ?? 'Client';

if ($client_id <= 0) {
    die('Invalid client account.');
}


// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------
function ratingStars($rating)
{
    $rating = max(0, min(5, (int)$rating));

    return str_repeat('★', $rating)
        . str_repeat('☆', 5 - $rating);
}

function roundedOverallRating(
    $installation,
    $service,
    $timeliness,
    $professionalism
) {
    $average = (
        $installation +
        $service +
        $timeliness +
        $professionalism
    ) / 4;

    return (int)round($average);
}


// ---------------------------------------------------------
// Handle feedback submission
// ---------------------------------------------------------
if (isset($_POST['submit_feedback'])) {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $project_id = (int)($_POST['project_id'] ?? 0);

    $installation_rating = (int)(
        $_POST['installation_rating'] ?? 0
    );

    $service_rating = (int)(
        $_POST['service_rating'] ?? 0
    );

    $timeliness_rating = (int)(
        $_POST['timeliness_rating'] ?? 0
    );

    $professionalism_rating = (int)(
        $_POST['professionalism_rating'] ?? 0
    );

    $comments = trim(
        $_POST['comments'] ?? ''
    );


    // Validate ratings.
    if (
        $project_id <= 0 ||
        $installation_rating < 1 ||
        $installation_rating > 5 ||
        $service_rating < 1 ||
        $service_rating > 5 ||
        $timeliness_rating < 1 ||
        $timeliness_rating > 5 ||
        $professionalism_rating < 1 ||
        $professionalism_rating > 5
    ) {
        header(
            'Location: client.php?tab=feedback&msg=invalid'
        );
        exit;
    }


    // Make sure the project belongs to the current client.
    $stmt = $pdo->prepare("
        SELECT id, project_name
        FROM projects
        WHERE id = ?
          AND client_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $project_id,
        $client_id
    ]);

    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        header(
            'Location: client.php?tab=feedback&msg=not_allowed'
        );
        exit;
    }


    try {

    // REVISION: Serialize portal and guest feedback writes for this project.
    // The existing portal still allows clients to update their own review.
    $pdo->beginTransaction();
    $feedbackLock = $pdo->prepare('SELECT id FROM projects WHERE id = ? FOR UPDATE');
    $feedbackLock->execute([$project_id]);

    // Check if this client already reviewed the project.
    $stmt = $pdo->prepare("
        SELECT id
        FROM feedback
        WHERE project_id = ?
          AND client_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $project_id,
        $client_id
    ]);

    $existing_feedback = $stmt->fetch(PDO::FETCH_ASSOC);

    $is_update = (bool)$existing_feedback;

        if ($is_update) {

            /*
             * Update the review only.
             *
             * IMPORTANT:
             * created_at is intentionally NOT changed.
             */
            $stmt = $pdo->prepare("
                UPDATE feedback
                SET
                    installation_rating = :installation_rating,
                    service_rating = :service_rating,
                    timeliness_rating = :timeliness_rating,
                    professionalism_rating = :professionalism_rating,
                    comments = :comments
                WHERE id = :id
                  AND project_id = :project_id
                  AND client_id = :client_id
            ");

            $stmt->execute([
                ':installation_rating' => $installation_rating,
                ':service_rating' => $service_rating,
                ':timeliness_rating' => $timeliness_rating,
                ':professionalism_rating' => $professionalism_rating,
                ':comments' => $comments,
                ':id' => $existing_feedback['id'],
                ':project_id' => $project_id,
                ':client_id' => $client_id
            ]);

            // REVISION: Save the review before optional admin notifications.
            $pdo->commit();

            // --- Send notification to admins ---
            try {
                $admin_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
                $admin_stmt->execute();
                $admins = $admin_stmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($admins)) {
                    $project_name = $project['project_name'];
                    $message = "Client $client_name updated their review for project $project_name.";
                    $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, message) VALUES (?, ?, ?)");
                    foreach ($admins as $admin_id) {
                        $notif_stmt->execute([$admin_id, $project_id, $message]);
                    }
                }
            } catch (Throwable $e) {
                error_log("Failed to send admin notification for feedback update: " . $e->getMessage());
            }

            header(
                'Location: client.php?tab=feedback&msg=updated'
            );
            exit;

        } else {

            $stmt = $pdo->prepare("
                INSERT INTO feedback (
                    project_id,
                    client_id,
                    installation_rating,
                    service_rating,
                    timeliness_rating,
                    professionalism_rating,
                    comments
                )
                VALUES (
                    :project_id,
                    :client_id,
                    :installation_rating,
                    :service_rating,
                    :timeliness_rating,
                    :professionalism_rating,
                    :comments
                )
            ");

            $stmt->execute([
                ':project_id' => $project_id,
                ':client_id' => $client_id,
                ':installation_rating' => $installation_rating,
                ':service_rating' => $service_rating,
                ':timeliness_rating' => $timeliness_rating,
                ':professionalism_rating' => $professionalism_rating,
                ':comments' => $comments
            ]);

            // REVISION: Save the review before optional admin notifications.
            $pdo->commit();

            // --- Send notification to admins ---
            try {
                $admin_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
                $admin_stmt->execute();
                $admins = $admin_stmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($admins)) {
                    $project_name = $project['project_name'];
                    $message = "Client $client_name submitted a review for project $project_name.";
                    $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, message) VALUES (?, ?, ?)");
                    foreach ($admins as $admin_id) {
                        $notif_stmt->execute([$admin_id, $project_id, $message]);
                    }
                }
            } catch (Throwable $e) {
                error_log("Failed to send admin notification for new feedback: " . $e->getMessage());
            }

            header(
                'Location: client.php?tab=feedback&msg=submitted'
            );
            exit;
        }

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        header(
            'Location: client.php?tab=feedback&msg=error'
        );
        exit;
    }
}


// ---------------------------------------------------------
// Determine selected project
// ---------------------------------------------------------
$selected_project_id = isset($_GET['project_id'])
    ? (int)$_GET['project_id']
    : 0;


// ---------------------------------------------------------
// Fetch client's projects
// ---------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.project_id,
        p.project_name,
        p.service_type,
        p.start_date,
        p.expected_end_date,
        p.status,

        (
            SELECT b.project_location
            FROM bookings b
            WHERE b.project_id = p.id
            ORDER BY b.id DESC
            LIMIT 1
        ) AS project_location

    FROM projects p

    WHERE p.client_id = ?

    ORDER BY p.start_date DESC,
             p.created_at DESC
");

$stmt->execute([$client_id]);

$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ---------------------------------------------------------
// Selected project's existing review
// ---------------------------------------------------------
$selected_project = null;
$selected_feedback = null;

if ($selected_project_id > 0) {

    foreach ($projects as $project) {

        if ((int)$project['id'] === $selected_project_id) {

            $selected_project = $project;
            break;
        }
    }


    if ($selected_project) {

        $stmt = $pdo->prepare("
            SELECT *
            FROM feedback
            WHERE project_id = ?
              AND client_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $selected_project_id,
            $client_id
        ]);

        $selected_feedback =
            $stmt->fetch(PDO::FETCH_ASSOC);
    }
}


// ---------------------------------------------------------
// Existing reviews
// ---------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT
        f.*,
        p.project_name,
        p.project_id AS project_id_display,
        p.service_type,
        p.start_date,
        p.expected_end_date
    FROM feedback f
    JOIN projects p ON f.project_id = p.id
    WHERE f.client_id = ?
    ORDER BY f.created_at DESC
");

$stmt->execute([$client_id]);

$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ---------------------------------------------------------
// Messages
// ---------------------------------------------------------
$msg = '';
$msg_type = '';

if (isset($_GET['msg'])) {

    $messages = [

        'submitted' => [
            'text' => 'Your feedback has been submitted successfully.',
            'type' => ''
        ],

        'updated' => [
            'text' => 'Your feedback has been updated successfully.',
            'type' => ''
        ],

        'invalid' => [
            'text' => 'Please provide a 1–5 star rating for every category.',
            'type' => 'error'
        ],

        'not_allowed' => [
            'text' => 'You are not allowed to review that project.',
            'type' => 'error'
        ],

        'error' => [
            'text' => 'Unable to save your feedback.',
            'type' => 'error'
        ]
    ];


    if (isset($messages[$_GET['msg']])) {

        $msg = $messages[$_GET['msg']]['text'];
        $msg_type = $messages[$_GET['msg']]['type'];
    }
}
?>

<?php if ($msg): ?>
    <div class="msg <?= $msg_type === 'error' ? 'error' : '' ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="mb-4">
    <h2 class="fw-bold mb-1" style="font-size: 20px;">Client Feedback</h2>
    <p class="mb-0" style="color: var(--fr-muted); font-size: 13.5px;">Share your experience with your completed or ongoing project.</p>
</div>

<div class="fr-card mb-4" id="feedbackFormCard">
    <div class="fr-card-pad">
        <h3 class="fr-section-title"><?= $selected_feedback ? 'Edit Your Review' : 'Submit a Review' ?></h3>
        <p style="color: var(--fr-muted); font-size: 13px;">Select a project and rate each category from 1★ to 5★.</p>

        <?php if (empty($projects)): ?>
            <div class="text-center py-4" style="color: var(--fr-muted); font-size: 13px;">No projects available for review yet.</div>
        <?php else: ?>
            <form method="POST" action="client.php?tab=feedback" id="feedbackForm">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="project_id">Project</label>
                    <select id="project_id" name="project_id" class="form-select" required onchange="changeFeedbackProject(this)">
                        <option value="">Select Project</option>
                        <?php foreach ($projects as $project): ?>
                            <option value="<?= (int)$project['id'] ?>" <?= (int)$project['id'] === $selected_project_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($project['project_name']) ?> — <?= htmlspecialchars(ucfirst($project['service_type'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($selected_project): ?>
                    <div class="p-3 rounded-3 mb-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);">
                        <div class="d-flex flex-wrap gap-3">
                            <strong><?= htmlspecialchars($selected_project['project_name']) ?></strong>
                            <span class="muted"><?= htmlspecialchars(ucfirst($selected_project['service_type'])) ?></span>
                            <span class="muted">Start: <?= date('M d, Y', strtotime($selected_project['start_date'])) ?></span>
                            <span class="muted">End: <?= !empty($selected_project['expected_end_date']) ? date('M d, Y', strtotime($selected_project['expected_end_date'])) : '—' ?></span>
                        </div>
                    </div>

                    <?php
                    $rating_fields = [
                        'installation_rating' => 'Installation',
                        'service_rating' => 'Service',
                        'timeliness_rating' => 'Timeliness',
                        'professionalism_rating' => 'Professionalism'
                    ];
                    ?>

                    <div class="mb-3">
                        <?php foreach ($rating_fields as $field => $label):
                            $current_rating = $selected_feedback ? (int)$selected_feedback[$field] : 0;
                        ?>
                            <div class="d-flex justify-content-between align-items-center py-3 flex-wrap gap-2" style="border-bottom: 1px solid var(--fr-border-soft);">
                                <div class="fw-bold" style="font-size: 13.5px; min-width: 140px;"><?= htmlspecialchars($label) ?></div>
                                <div class="client-star-rating">
                                    <?php for ($star = 5; $star >= 1; $star--): ?>
                                        <input type="radio"
                                               id="<?= htmlspecialchars($field) ?>_<?= $star ?>"
                                               name="<?= htmlspecialchars($field) ?>"
                                               value="<?= $star ?>"
                                               <?= $current_rating === $star ? 'checked' : '' ?>
                                               required>
                                        <label for="<?= htmlspecialchars($field) ?>_<?= $star ?>" title="<?= $star ?> star<?= $star > 1 ? 's' : '' ?>">★</label>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-group">
                        <label for="comments">Comments</label>
                        <textarea id="comments" name="comments" class="form-control" rows="5" placeholder="Tell us about your experience..."><?= htmlspecialchars($selected_feedback['comments'] ?? '') ?></textarea>
                    </div>

                    <div class="form-actions">
                        <a href="client.php?tab=feedback" class="btn btn-outline-secondary">Clear</a>
                        <button type="submit" name="submit_feedback" class="btn btn-primary">
                            <?= $selected_feedback ? 'Update Review' : 'Submit Review' ?>
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="fr-card">
    <div class="fr-card-pad">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="fr-section-title mb-0">Your Reviews</h3>
            <span class="fr-badge followup"><?= count($reviews) ?> review<?= count($reviews) === 1 ? '' : 's' ?></span>
        </div>

        <?php if (empty($reviews)): ?>
            <div class="text-center py-4" style="color: var(--fr-muted); font-size: 13px; border: 1px dashed var(--fr-border); border-radius: 8px;">Your submitted reviews will appear here.</div>
        <?php else: ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($reviews as $review):
                    $overall = roundedOverallRating($review['installation_rating'], $review['service_rating'], $review['timeliness_rating'], $review['professionalism_rating']);
                ?>
                    <div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                            <div>
                                <div style="font-size: 11px; color: var(--fr-muted); font-weight: 700;"><?= htmlspecialchars($review['project_id_display']) ?></div>
                                <h4 style="font-size: 16px; margin: 4px 0 0;"><?= htmlspecialchars($review['project_name']) ?></h4>
                            </div>
                            <a href="client.php?tab=feedback&project_id=<?= (int)$review['project_id'] ?>#feedbackFormCard" class="btn btn-outline-secondary btn-sm">Edit</a>
                        </div>

                        <div class="d-flex flex-wrap gap-3 mb-3" style="font-size: 12px; color: var(--fr-muted);">
                            <span>Submitted: <?= date('M d, Y', strtotime($review['created_at'])) ?></span>
                            <span>Start: <?= date('M d, Y', strtotime($review['start_date'])) ?></span>
                            <span>End: <?= !empty($review['expected_end_date']) ? date('M d, Y', strtotime($review['expected_end_date'])) : '—' ?></span>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-3 col-6"><div class="d-flex justify-content-between p-2 rounded-2" style="background: #fff; border: 1px solid var(--fr-border);"><span class="muted" style="font-size: 11px;">Installation</span><strong style="color: var(--fr-red); letter-spacing: 1px;"><?= ratingStars($review['installation_rating']) ?></strong></div></div>
                            <div class="col-md-3 col-6"><div class="d-flex justify-content-between p-2 rounded-2" style="background: #fff; border: 1px solid var(--fr-border);"><span class="muted" style="font-size: 11px;">Service</span><strong style="color: var(--fr-red); letter-spacing: 1px;"><?= ratingStars($review['service_rating']) ?></strong></div></div>
                            <div class="col-md-3 col-6"><div class="d-flex justify-content-between p-2 rounded-2" style="background: #fff; border: 1px solid var(--fr-border);"><span class="muted" style="font-size: 11px;">Timeliness</span><strong style="color: var(--fr-red); letter-spacing: 1px;"><?= ratingStars($review['timeliness_rating']) ?></strong></div></div>
                            <div class="col-md-3 col-6"><div class="d-flex justify-content-between p-2 rounded-2" style="background: #fff; border: 1px solid var(--fr-border);"><span class="muted" style="font-size: 11px;">Professionalism</span><strong style="color: var(--fr-red); letter-spacing: 1px;"><?= ratingStars($review['professionalism_rating']) ?></strong></div></div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center pt-3" style="border-top: 2px solid var(--fr-border);">
                            <span class="muted fw-bold">Overall Rating</span>
                            <strong style="color: var(--fr-red); font-size: 20px; letter-spacing: 2px;"><?= ratingStars($overall) ?></strong>
                        </div>

                        <?php if (trim($review['comments'] ?? '') !== ''): ?>
                            <div class="mt-3 p-3 rounded-3" style="background: #fff; border: 1px solid var(--fr-border); font-size: 13px; line-height: 1.5; color: var(--fr-text-soft);">
                                <?= nl2br(htmlspecialchars($review['comments'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Star rating CSS + JS -->
<style>
.client-star-rating {
    display: flex;
    flex-direction: row-reverse;
    justify-content: flex-end;
}
.client-star-rating input { display: none; }
.client-star-rating label {
    font-size: 28px;
    line-height: 1;
    color: #d1d5db;
    cursor: pointer;
    transition: 0.15s;
    padding: 0 2px;
}
.client-star-rating label:hover,
.client-star-rating label:hover ~ label,
.client-star-rating input:checked ~ label {
    color: var(--fr-red);
}
</style>


<script>
function changeFeedbackProject(select) {
    const projectId = select.value;
    if (projectId) {
        window.location.href = 'client.php?tab=feedback&project_id=' + encodeURIComponent(projectId) + '#feedbackFormCard';
    } else {
        window.location.href = 'client.php?tab=feedback';
    }
}
</script>