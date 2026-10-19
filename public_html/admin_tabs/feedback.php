<?php
// This file is included inside admin.php.


// ---------------------------------------------------------
// Helper functions
// ---------------------------------------------------------
function feedbackOverallRating(
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

function feedbackStars($rating)
{
    $rating = max(
        0,
        min(
            5,
            (int)$rating
        )
    );

    return str_repeat('★', $rating)
        . str_repeat('☆', 5 - $rating);
}

/**
 * Returns the rating label and analysis text for a given score and category.
 *
 * @param float  $score    Average rating (1–5)
 * @param string $category 'installation', 'service', 'timeliness', 'professionalism', or 'overall'
 *
 * @return array ['label' => string, 'text' => string]
 */
function getCategoryAnalysis($score, $category)
{
    $score = round($score, 2);

    $rules = [
        'installation' => [
            'ranges' => [
                [4.50, 5.00, 'Excellent', 'Installation performance is excellent. Clients are highly satisfied with the quality and execution of the installation. Maintain current standards and consistent workmanship.'],
                [3.50, 4.49, 'Very Good', 'Installation performance is very good. Clients are generally satisfied, although minor improvements in installation quality or execution may still be considered. Monitor and maintain performance.'],
                [2.50, 3.49, 'Good', 'Installation performance is acceptable but has room for improvement. Identify possible concerns with installation quality or execution and monitor performance.'],
                [1.50, 2.49, 'Poor', 'Installation performance is poor. Clients have noticeable concerns regarding the quality or execution of installation work. Investigate the issues and implement improvements.'],
                [1.00, 1.49, 'Very Poor', 'Installation performance is very poor. Major concerns regarding installation work require immediate attention and corrective action.']
            ]
        ],
        'service' => [
            'ranges' => [
                [4.50, 5.00, 'Excellent', 'Service performance is excellent. Clients are highly satisfied with the service provided. Maintain the current level of service and responsiveness.'],
                [3.50, 4.49, 'Very Good', 'Service performance is very good. Clients are generally satisfied, with only minor areas that may still be improved. Monitor and maintain service quality.'],
                [2.50, 3.49, 'Good', 'Service performance is acceptable but should be improved where necessary. Identify possible service-related concerns and monitor client satisfaction.'],
                [1.50, 2.49, 'Poor', 'Service performance is poor. Clients have noticeable concerns with the service provided. Investigate the issues and implement improvements.'],
                [1.00, 1.49, 'Very Poor', 'Service performance is very poor. Significant service-related concerns require immediate management attention and corrective action.']
            ]
        ],
        'timeliness' => [
            'ranges' => [
                [4.50, 5.00, 'Excellent', 'Timeliness performance is excellent. Clients are highly satisfied with the company\'s ability to complete work and provide services on schedule. Maintain consistent and timely performance.'],
                [3.50, 4.49, 'Very Good', 'Timeliness performance is very good. Projects and services are generally completed on schedule, although minor improvements may still be considered. Monitor and maintain performance.'],
                [2.50, 3.49, 'Good', 'Timeliness performance is acceptable but has room for improvement. Identify possible causes of delays and monitor project completion schedules.'],
                [1.50, 2.49, 'Poor', 'Timeliness performance is poor. Clients have noticeable concerns regarding delays or project completion schedules. Investigate the causes and implement improvements.'],
                [1.00, 1.49, 'Very Poor', 'Timeliness performance is very poor. Significant delays or scheduling concerns require immediate attention and corrective action.']
            ]
        ],
        'professionalism' => [
            'ranges' => [
                [4.50, 5.00, 'Excellent', 'Professionalism is excellent. Clients are highly satisfied with the conduct, communication, and behavior of company personnel. Maintain current professional standards.'],
                [3.50, 4.49, 'Very Good', 'Professionalism is very good. Personnel generally demonstrate professional conduct, although minor improvements may still be considered. Monitor and maintain standards.'],
                [2.50, 3.49, 'Good', 'Professionalism is acceptable but has room for improvement. Identify possible concerns regarding employee conduct or communication and monitor performance.'],
                [1.50, 2.49, 'Poor', 'Professionalism is poor. Clients have noticeable concerns regarding employee conduct or communication. Management should investigate and implement improvements.'],
                [1.00, 1.49, 'Very Poor', 'Professionalism is very poor. Serious concerns regarding employee conduct or communication require immediate management attention and corrective action.']
            ]
        ],
        'overall' => [
            'ranges' => [
                [4.50, 5.00, 'Excellent', 'Overall client satisfaction is excellent. The company is performing strongly across its services and should maintain its current standards.'],
                [3.50, 4.49, 'Very Good', 'Overall client satisfaction is very good. The company is performing well, although some areas may benefit from further improvement.'],
                [2.50, 3.49, 'Good', 'Overall client satisfaction is good but requires attention. The company should identify lower-performing areas and implement improvements.'],
                [1.50, 2.49, 'Poor', 'Overall client satisfaction is poor. Several aspects of the service may require improvement, and management attention is recommended.'],
                [1.00, 1.49, 'Very Poor', 'Overall client satisfaction is very poor. Immediate action is needed to address major concerns and improve the client experience.']
            ]
        ]
    ];

    // Fallback if category not found
    if (!isset($rules[$category])) {
        return [
            'label' => 'Unknown',
            'text' => 'No analysis available for this category.'
        ];
    }

    foreach ($rules[$category]['ranges'] as $range) {
        list($min, $max, $label, $text) = $range;
        if ($score >= $min && $score <= $max) {
            return [
                'label' => $label,
                'text' => $text
            ];
        }
    }

    // If score is outside the defined ranges (should not happen)
    return [
        'label' => 'N/A',
        'text' => 'Rating score is outside the expected range.'
    ];
}


// ---------------------------------------------------------
// Overall statistics
// ---------------------------------------------------------
$stmt = $pdo->query("
    SELECT
        COUNT(*) AS total_feedback,

        AVG(installation_rating) AS avg_installation,
        AVG(service_rating) AS avg_service,
        AVG(timeliness_rating) AS avg_timeliness,
        AVG(professionalism_rating) AS avg_professionalism

    FROM feedback
");

$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$total_feedback = (int)(
    $stats['total_feedback'] ?? 0
);

$avg_installation = (float)(
    $stats['avg_installation'] ?? 0
);

$avg_service = (float)(
    $stats['avg_service'] ?? 0
);

$avg_timeliness = (float)(
    $stats['avg_timeliness'] ?? 0
);

$avg_professionalism = (float)(
    $stats['avg_professionalism'] ?? 0
);


// Average across all four rating categories.
if ($total_feedback > 0) {

    $average_rating = (
        $avg_installation +
        $avg_service +
        $avg_timeliness +
        $avg_professionalism
    ) / 4;

} else {

    $average_rating = 0;
}


// ---------------------------------------------------------
// Rating distribution
//
// Distribution is based on each review's displayed overall
// rating, which uses CEIL() according to the requested rule.
// ---------------------------------------------------------
$distribution = [
    5 => 0,
    4 => 0,
    3 => 0,
    2 => 0,
    1 => 0
];

$stmt = $pdo->query("
    SELECT
        installation_rating,
        service_rating,
        timeliness_rating,
        professionalism_rating
    FROM feedback
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

    $overall =
        feedbackOverallRating(
            $row['installation_rating'],
            $row['service_rating'],
            $row['timeliness_rating'],
            $row['professionalism_rating']
        );

    if (isset($distribution[$overall])) {
        $distribution[$overall]++;
    }
}

$max_distribution =
    max(1, max($distribution));


// ---------------------------------------------------------
// Reviews pagination
// ---------------------------------------------------------
$reviews_per_page = 5;

$review_page = isset($_GET['review_page'])
    ? max(1, (int)$_GET['review_page'])
    : 1;

$review_offset =
    ($review_page - 1)
    * $reviews_per_page;


// Count total reviews.
$review_count_stmt = $pdo->query("
    SELECT COUNT(*)
    FROM feedback
");

$total_reviews =
    (int)$review_count_stmt->fetchColumn();

$total_review_pages =
    max(
        1,
        (int)ceil(
            $total_reviews / $reviews_per_page
        )
    );

if ($review_page > $total_review_pages) {
    $review_page = $total_review_pages;

    $review_offset =
        ($review_page - 1)
        * $reviews_per_page;
}


// Fetch current page of reviews.
$stmt = $pdo->prepare("
    SELECT
        f.*,

        u.full_name AS client_name,

        p.project_id,
        p.project_name,
        p.service_type,
        p.start_date,
        p.expected_end_date

    FROM feedback f

    JOIN users u
        ON f.client_id = u.id

    JOIN projects p
        ON f.project_id = p.id

    ORDER BY f.created_at DESC

    LIMIT :limit
    OFFSET :offset
");

$stmt->bindValue(
    ':limit',
    $reviews_per_page,
    PDO::PARAM_INT
);

$stmt->bindValue(
    ':offset',
    $review_offset,
    PDO::PARAM_INT
);

$stmt->execute();

$reviews =
    $stmt->fetchAll(PDO::FETCH_ASSOC);


// ---------------------------------------------------------
// Message
// ---------------------------------------------------------
$msg = '';

if (isset($_GET['msg'])) {

    $messages = [
        'error' => 'Unable to load feedback data.'
    ];

    $msg = $messages[$_GET['msg']] ?? '';
}
?>

<?php if ($msg): ?><div class="msg error"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="mb-3">
    <h2 class="fw-bold mb-1" style="font-size: 20px;">Client Feedback Analysis</h2>
    <p class="mb-0" style="color: var(--fr-muted); font-size: 13.5px;">Review customer satisfaction and overall service performance.</p>
</div>

<!-- Summary -->
<div class="stats mb-4">
    <div class="stat-card" style="text-align: left;">
        <div style="font-size: 31px; font-weight: 700; color: var(--fr-red);"><?= number_format($average_rating, 2) ?> <span style="font-size: 15px; color: var(--fr-muted);">/ 5</span></div>
        <div style="color: var(--fr-red); font-size: 18px; letter-spacing: 2px; margin: 5px 0;"><?= feedbackStars((int)ceil($average_rating)) ?></div>
        <div class="fr-stat-label">Average Rating</div>
    </div>
    <div class="stat-card" style="text-align: left;">
        <div style="font-size: 31px; font-weight: 700; color: var(--fr-red);"><?= $total_feedback ?></div>
        <div class="fr-stat-label">Total Feedback</div>
    </div>
</div>

<!-- Distribution -->
<div class="fr-card mb-4">
    <div class="fr-card-pad">
        <h3 class="fr-section-title">Rating Distribution</h3>
        <div class="d-flex flex-column gap-2">
            <?php for ($rating = 5; $rating >= 1; $rating--):
                $count = $distribution[$rating];
                $width = $max_distribution > 0 ? ($count / $max_distribution) * 100 : 0;
            ?>
                <button type="button" class="d-grid align-items-center border-0 bg-transparent text-start p-1" style="grid-template-columns: 45px 1fr 90px; gap: 10px; cursor: pointer;" onclick="showRatingCount(<?= $rating ?>, <?= $count ?>)">
                    <span style="font-weight: 700;"><?= $rating ?>★</span>
                    <span style="height: 20px; background: #f3f4f6; border-radius: 10px; overflow: hidden; display: block;">
                        <span style="display: block; height: 100%; background: var(--fr-red); border-radius: 10px; width: <?= $width ?>%; transition: width .3s;"></span>
                    </span>
                    <span style="display: flex; gap: 5px; justify-content: flex-end; align-items: baseline;">
                        <strong><?= $count ?></strong><small style="color: var(--fr-muted);"><?= $count === 1 ? 'review' : 'reviews' ?></small>
                    </span>
                </button>
            <?php endfor; ?>
        </div>
        <div id="ratingCountMessage" class="mt-3 p-2 rounded-2" style="background: var(--fr-bg); border: 1px solid var(--fr-border); font-size: 13px;">Click a rating bar above to see its count.</div>
    </div>
</div>

<!-- Radar Chart -->
<div class="fr-card mb-4">
    <div class="fr-card-pad">
        <h3 class="fr-section-title">Performance by Category</h3>
        <div class="d-flex justify-content-center align-items-center" style="min-height: 430px;">
            <canvas id="feedbackRadarChart" width="520" height="420"></canvas>
        </div>
        <div class="row g-2 mt-2">
            <div class="col-md-3 col-6"><div class="p-3 rounded-3 text-center" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><span style="display: block; color: var(--fr-muted); font-size: 12px; margin-bottom: 3px;">Installation</span><strong><?= number_format($avg_installation, 2) ?>/5</strong></div></div>
            <div class="col-md-3 col-6"><div class="p-3 rounded-3 text-center" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><span style="display: block; color: var(--fr-muted); font-size: 12px; margin-bottom: 3px;">Service</span><strong><?= number_format($avg_service, 2) ?>/5</strong></div></div>
            <div class="col-md-3 col-6"><div class="p-3 rounded-3 text-center" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><span style="display: block; color: var(--fr-muted); font-size: 12px; margin-bottom: 3px;">Timeliness</span><strong><?= number_format($avg_timeliness, 2) ?>/5</strong></div></div>
            <div class="col-md-3 col-6"><div class="p-3 rounded-3 text-center" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><span style="display: block; color: var(--fr-muted); font-size: 12px; margin-bottom: 3px;">Professionalism</span><strong><?= number_format($avg_professionalism, 2) ?>/5</strong></div></div>
        </div>
    </div>
</div>

<!-- Category Analysis -->
<div class="fr-card mb-4">
    <div class="fr-card-pad">
        <h3 class="fr-section-title">Analysis by Category</h3>
        <?php
        $categories = [
            'installation' => ['label' => 'Installation', 'score' => $avg_installation, 'icon' => '🔧'],
            'service' => ['label' => 'Service', 'score' => $avg_service, 'icon' => '🤝'],
            'timeliness' => ['label' => 'Timeliness', 'score' => $avg_timeliness, 'icon' => '⏱️'],
            'professionalism' => ['label' => 'Professionalism', 'score' => $avg_professionalism, 'icon' => '🎯']
        ];
        $highest_category = null; $lowest_category = null; $highest_score = -1; $lowest_score = 6;
        foreach ($categories as $key => $data) {
            if ($data['score'] > $highest_score) { $highest_score = $data['score']; $highest_category = $key; }
            if ($data['score'] < $lowest_score) { $lowest_score = $data['score']; $lowest_category = $key; }
        }
        ?>

        <div class="d-flex flex-column gap-3">
            <?php foreach ($categories as $key => $data):
                $analysis = getCategoryAnalysis($data['score'], $key);
                $stars = feedbackStars((int)ceil($data['score']));
            ?>
                <div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <span style="font-size: 22px;"><?= $data['icon'] ?></span>
                            <span style="font-size: 18px; font-weight: 700;"><?= htmlspecialchars($data['label']) ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span style="font-size: 22px; font-weight: 700; color: var(--fr-red);"><?= number_format($data['score'], 2) ?></span>
                            <span style="color: var(--fr-muted); font-size: 14px;">/ 5</span>
                            <span style="color: var(--fr-red); font-size: 18px;"><?= $stars ?></span>
                        </div>
                    </div>
                    <div class="mt-2 mb-3">
                        <span class="fr-badge <?= strtolower(str_replace(' ', '-', $analysis['label'])) === 'excellent' || strtolower(str_replace(' ', '-', $analysis['label'])) === 'very-good' ? 'completed' : (strtolower(str_replace(' ', '-', $analysis['label'])) === 'good' ? 'scheduled' : 'rejected') ?>">
                            <?= htmlspecialchars($analysis['label']) ?>
                        </span>
                    </div>
                    <div style="color: var(--fr-text-soft); line-height: 1.5; font-size: 13.5px;"><?= nl2br(htmlspecialchars($analysis['text'])) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="d-flex flex-wrap gap-4 mt-3 p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);">
            <div><span style="color: var(--fr-muted); font-size: 13px;">⬆ Highest:</span> <strong><?= htmlspecialchars(ucfirst($highest_category)) ?> — <?= number_format($highest_score, 2) ?>/5</strong></div>
            <div><span style="color: var(--fr-muted); font-size: 13px;">⬇ Lowest:</span> <strong><?= htmlspecialchars(ucfirst($lowest_category)) ?> — <?= number_format($lowest_score, 2) ?>/5</strong></div>
        </div>

        <?php $overall_analysis = getCategoryAnalysis($average_rating, 'overall'); ?>
        <div class="p-4 rounded-3 mt-4" style="background: var(--fr-red-soft); border: 2px solid var(--fr-red);">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span style="font-size: 18px; font-weight: 700;">⭐ Final Analysis</span>
                <span style="font-size: 20px; font-weight: 700; color: var(--fr-red);"><?= number_format($average_rating, 2) ?>/5 <?= feedbackStars((int)ceil($average_rating)) ?></span>
            </div>
            <div class="mt-2 mb-3">
                <span class="fr-badge <?= strtolower(str_replace(' ', '-', $overall_analysis['label'])) === 'excellent' || strtolower(str_replace(' ', '-', $overall_analysis['label'])) === 'very-good' ? 'completed' : (strtolower(str_replace(' ', '-', $overall_analysis['label'])) === 'good' ? 'scheduled' : 'rejected') ?>"><?= htmlspecialchars($overall_analysis['label']) ?></span>
            </div>
            <div style="color: var(--fr-text-soft); line-height: 1.6; font-size: 14px;"><?= nl2br(htmlspecialchars($overall_analysis['text'])) ?></div>
        </div>
    </div>
</div>

<!-- Client Reviews -->
<div class="fr-card">
    <div class="fr-card-pad">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="fr-section-title mb-0">Client Reviews</h3>
            <span style="color: var(--fr-muted); font-size: 13px;">Page <?= $review_page ?> of <?= $total_review_pages ?></span>
        </div>

        <?php if (empty($reviews)): ?>
            <div class="text-center py-5" style="color: var(--fr-muted); border: 1px dashed var(--fr-border); border-radius: 8px;">
                <i class="bi bi-chat-square-text" style="font-size: 32px; opacity: .5;"></i>
                <h4 class="mt-2 mb-1" style="color: var(--fr-text);">No Feedback Yet</h4>
                <p class="mb-0" style="font-size: 13px;">Client reviews will appear here once submitted.</p>
            </div>
        <?php else: ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($reviews as $review):
                    $overall = feedbackOverallRating($review['installation_rating'], $review['service_rating'], $review['timeliness_rating'], $review['professionalism_rating']);
                ?>
                    <div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);">
                        <div class="d-flex justify-content-between gap-3 flex-wrap">
                            <div>
                                <div style="font-weight: 700; font-size: 15px;"><?= htmlspecialchars($review['client_name']) ?></div>
                                <div style="color: var(--fr-muted); font-size: 12.5px; margin-top: 3px;"><?= htmlspecialchars($review['project_name']) ?> · <?= htmlspecialchars(ucfirst($review['service_type'])) ?></div>
                            </div>
                            <div style="color: var(--fr-muted); font-size: 12px; white-space: nowrap;"><?= date('M d, Y', strtotime($review['created_at'])) ?></div>
                        </div>

                        <div class="d-flex align-items-center gap-3 my-3">
                            <strong style="color: var(--fr-red); font-size: 20px; letter-spacing: 1px;"><?= feedbackStars($overall) ?></strong>
                            <span style="color: var(--fr-muted); font-size: 13px;">Overall: <?= $overall ?>/5</span>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-3 col-6"><div class="p-2 rounded-2" style="background: #fff; border: 1px solid var(--fr-border);"><span style="display: block; color: var(--fr-muted); font-size: 10.5px; margin-bottom: 3px;">Installation</span><strong style="color: var(--fr-red); letter-spacing: 1px;"><?= feedbackStars($review['installation_rating']) ?></strong></div></div>
                            <div class="col-md-3 col-6"><div class="p-2 rounded-2" style="background: #fff; border: 1px solid var(--fr-border);"><span style="display: block; color: var(--fr-muted); font-size: 10.5px; margin-bottom: 3px;">Service</span><strong style="color: var(--fr-red); letter-spacing: 1px;"><?= feedbackStars($review['service_rating']) ?></strong></div></div>
                            <div class="col-md-3 col-6"><div class="p-2 rounded-2" style="background: #fff; border: 1px solid var(--fr-border);"><span style="display: block; color: var(--fr-muted); font-size: 10.5px; margin-bottom: 3px;">Timeliness</span><strong style="color: var(--fr-red); letter-spacing: 1px;"><?= feedbackStars($review['timeliness_rating']) ?></strong></div></div>
                            <div class="col-md-3 col-6"><div class="p-2 rounded-2" style="background: #fff; border: 1px solid var(--fr-border);"><span style="display: block; color: var(--fr-muted); font-size: 10.5px; margin-bottom: 3px;">Professionalism</span><strong style="color: var(--fr-red); letter-spacing: 1px;"><?= feedbackStars($review['professionalism_rating']) ?></strong></div></div>
                        </div>

                        <?php if (trim($review['comments'] ?? '') !== ''): ?>
                            <div class="p-3 rounded-3" style="background: #fff; border: 1px solid var(--fr-border); font-size: 13px; line-height: 1.5;"><?= nl2br(htmlspecialchars($review['comments'])) ?></div>
                        <?php endif; ?>

                        <div class="d-flex flex-wrap gap-3 mt-3" style="color: var(--fr-muted); font-size: 11.5px;">
                            <span>Start: <?= !empty($review['start_date']) ? date('M d, Y', strtotime($review['start_date'])) : '—' ?></span>
                            <span>End: <?= !empty($review['expected_end_date']) ? date('M d, Y', strtotime($review['expected_end_date'])) : '—' ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($total_review_pages > 1): ?>
                <div class="d-flex justify-content-center align-items-center gap-3 mt-4">
                    <?php if ($review_page > 1): ?><a href="admin.php?tab=feedback&review_page=<?= $review_page - 1 ?>" class="btn btn-outline-secondary btn-sm">&larr; Previous</a><?php endif; ?>
                    <span><?= $review_page ?> / <?= $total_review_pages ?></span>
                    <?php if ($review_page < $total_review_pages): ?><a href="admin.php?tab=feedback&review_page=<?= $review_page + 1 ?>" class="btn btn-primary btn-sm">Next &rarr;</a><?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function showRatingCount(rating, count) {
    const message = document.getElementById('ratingCountMessage');
    if (!message) return;
    message.textContent = rating + '★ received ' + count + ' ' + (count === 1 ? 'review.' : 'reviews.');
}
</script>

<!-- Radar chart JS — canvas draws to WHITE background now, so use DARK colors -->
<script>
(function() {
    const canvas = document.getElementById('feedbackRadarChart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const values = [<?= json_encode(round($avg_installation, 2)) ?>, <?= json_encode(round($avg_service, 2)) ?>, <?= json_encode(round($avg_timeliness, 2)) ?>, <?= json_encode(round($avg_professionalism, 2)) ?>];
    const labels = ['Installation', 'Service', 'Timeliness', 'Professionalism'];
    const centerX = canvas.width / 2;
    const centerY = canvas.height / 2 + 10;
    const radius = 145;
    const points = labels.length;

    function pointAt(index, value) {
        const angle = -Math.PI / 2 + (index * 2 * Math.PI / points);
        const distance = radius * (value / 5);
        return { x: centerX + Math.cos(angle) * distance, y: centerY + Math.sin(angle) * distance };
    }

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    // Grid — light grey on white background
    for (let level = 1; level <= 5; level++) {
        ctx.beginPath();
        for (let i = 0; i < points; i++) {
            const p = pointAt(i, level);
            if (i === 0) ctx.moveTo(p.x, p.y); else ctx.lineTo(p.x, p.y);
        }
        ctx.closePath();
        ctx.strokeStyle = '#e5e7eb';
        ctx.lineWidth = 1;
        ctx.stroke();
    }

    // Axis lines
    for (let i = 0; i < points; i++) {
        const p = pointAt(i, 5);
        ctx.beginPath();
        ctx.moveTo(centerX, centerY);
        ctx.lineTo(p.x, p.y);
        ctx.strokeStyle = '#e5e7eb';
        ctx.stroke();
    }

    // Data polygon
    ctx.beginPath();
    values.forEach(function(value, index) {
        const p = pointAt(index, value);
        if (index === 0) ctx.moveTo(p.x, p.y); else ctx.lineTo(p.x, p.y);
    });
    ctx.closePath();
    ctx.fillStyle = 'rgba(217, 32, 39, 0.15)';
    ctx.fill();
    ctx.strokeStyle = 'rgba(217, 32, 39, 0.7)';
    ctx.lineWidth = 3;
    ctx.stroke();

    // Data points
    values.forEach(function(value, index) {
        const p = pointAt(index, value);
        ctx.beginPath();
        ctx.arc(p.x, p.y, 3, 0, Math.PI * 2);
        ctx.fillStyle = '#d92027';
        ctx.fill();
    });

    // Labels — dark grey text now
    labels.forEach(function(label, index) {
        const angle = -Math.PI / 2 + (index * 2 * Math.PI / points);
        const labelDistance = radius + 35;
        const x = centerX + Math.cos(angle) * labelDistance;
        const y = centerY + Math.sin(angle) * labelDistance;
        ctx.fillStyle = '#374151';
        ctx.font = 'bold 13px Arial';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(label, x, y);
    });

    // Scale labels
    for (let level = 1; level <= 5; level++) {
        ctx.fillStyle = '#9ca3af';
        ctx.font = '10px Arial';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillText(level + '★', centerX + 5, centerY - (radius * level / 5));
    }
})();
</script>