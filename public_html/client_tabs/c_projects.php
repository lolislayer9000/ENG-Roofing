<?php
// This file is included inside client.php.

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$client_id = (int)$_SESSION['user_id'];

if ($client_id <= 0) {
    die('Invalid client account.');
}


// ---------------------------------------------------------
// Fetch the client's projects
// ---------------------------------------------------------
$sql = "
    SELECT
        p.id,
        p.project_id,
        p.project_name,
        p.service_type,
        p.start_date,
        p.expected_end_date,
        p.progress,
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

    ORDER BY
        CASE p.status
            WHEN 'active' THEN 1
            WHEN 'pending' THEN 2
            WHEN 'completed' THEN 3
            WHEN 'cancelled' THEN 4
            ELSE 5
        END,
        p.start_date ASC,
        p.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$client_id]);

$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------
// Preload task attachments for every project the client owns.
// Single query covers all tasks across all of the client's
// projects; the result is keyed by task id for O(1) lookup.
// ---------------------------------------------------------
$clientAttachmentsByTask = [];

$stmt = $pdo->prepare("
    SELECT a.*, pt.id AS task_id, u.full_name AS uploaded_by_name
    FROM project_task_attachments a
    INNER JOIN project_tasks pt ON pt.id = a.task_id
    INNER JOIN projects p ON p.id = pt.project_id
    LEFT JOIN users u ON u.id = a.uploaded_by
    WHERE p.client_id = ?
    ORDER BY a.id ASC
");
$stmt->execute([$client_id]);

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $clientAttachmentsByTask[(int)$r['task_id']][] = [
        'id'               => (int)$r['id'],
        'url'              => 'task_attachment.php?id=' . (int)$r['id'],
        'original_name'    => $r['original_name'],
        'uploaded_by_name' => $r['uploaded_by_name'] ?? '',
        'file_size'        => (int)$r['file_size'],
        'uploaded_at'      => $r['uploaded_at'],
    ];
}


// ---------------------------------------------------------
// Helper for display status
// ---------------------------------------------------------
function clientProjectStatus($status)
{
    switch (strtolower($status)) {

        case 'pending':
            return [
                'label' => 'Pending',
                'class' => 'status-pending'
            ];

        case 'active':
            return [
                'label' => 'Active',
                'class' => 'status-approved'
            ];

        case 'completed':
            return [
                'label' => 'Complete',
                'class' => 'status-approved'
            ];

        case 'cancelled':
            return [
                'label' => 'Cancelled',
                'class' => 'status-rejected'
            ];

        default:
            return [
                'label' => ucfirst($status),
                'class' => 'status-pending'
            ];
    }
}
?>

<div class="mb-4">
    <h2 class="fw-bold mb-1" style="font-size: 20px;">My Projects</h2>
    <p class="mb-0" style="color: var(--fr-muted); font-size: 13.5px;">View the progress and current status of your roofing projects.</p>
</div>

<?php if (empty($projects)): ?>
    <div class="fr-card">
        <div class="fr-card-pad text-center py-5">
            <i class="bi bi-house-door" style="font-size: 36px; color: var(--fr-muted); opacity: .5;"></i>
            <h3 class="mt-3 mb-1" style="font-size: 17px; color: var(--fr-text);">No Projects Yet</h3>
            <p class="mb-0" style="color: var(--fr-muted); font-size: 13px;">You do not have any projects assigned to your account yet.</p>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($projects as $project):
            $status = clientProjectStatus($project['status']);
            $progress = max(0, min(100, (int)$project['progress']));
            $status_class = str_replace(['status-', 'dashboard-status-'], '', $status['class']);
        ?>
            <div class="col-lg-6">
                <div class="fr-card h-100">
                    <div class="fr-card-pad">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                            <div>
                                <div style="font-size: 11px; color: var(--fr-muted); font-weight: 700; letter-spacing: .3px;"><?= htmlspecialchars($project['project_id']) ?></div>
                                <h3 style="font-size: 18px; font-weight: 700; margin: 4px 0 0; color: var(--fr-text);"><?= htmlspecialchars($project['project_name']) ?></h3>
                            </div>
                            <span class="fr-badge <?= $status_class ?>"><?= htmlspecialchars($status['label']) ?></span>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-2" style="font-size: 12.5px;">
                            <strong style="color: var(--fr-text);">Project Progress</strong>
                            <strong style="color: var(--fr-red);"><?= $progress ?>%</strong>
                        </div>
                        <div class="fr-progress mb-3" style="height: 20px;">
                            <div style="width: <?= $progress ?>%;"><?= $progress >= 10 ? $progress . '%' : '' ?></div>
                        </div>

                        <h4 class="fr-section-title" style="margin: 20px 0 10px;">Tasks</h4>
                        <?php $tasks = getProjectTasks($pdo, $project['id']); ?>
                        <?php if (empty($tasks)): ?>
                            <div style="color: var(--fr-muted); font-size: 13px;">No tasks defined.</div>
                        <?php else: ?>
                            <ul style="list-style: none; padding: 0; margin: 0;">
                                <?php foreach ($tasks as $task):
                                    $photoCount = count($clientAttachmentsByTask[(int)$task['id']] ?? []);
                                ?>
                                    <li style="padding: 6px 0; border-bottom: 1px solid var(--fr-border-soft); display: flex; align-items: center; gap: 8px; font-size: 13px;">
                                        <i class="bi <?= $task['is_completed'] ? 'bi-check-circle-fill' : 'bi-circle' ?>" style="color: <?= $task['is_completed'] ? '#10b981' : 'var(--fr-muted)' ?>; font-size: 15px; flex-shrink: 0;"></i>
                                        <span style="color: var(--fr-text-soft); flex: 1; min-width: 0;"><?= htmlspecialchars($task['description']) ?></span>
                                        <?php if ($photoCount > 0): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-secondary client-attachment-open-btn"
                                                    style="flex-shrink: 0; padding: 2px 8px; font-size: 11.5px;"
                                                    data-task-id="<?= (int)$task['id'] ?>"
                                                    data-task-desc="<?= htmlspecialchars($task['description']) ?>"
                                                    title="View photos">
                                                <i class="bi bi-image"></i>
                                                <span class="ms-1"><?= $photoCount ?></span>
                                            </button>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <div class="row g-2 mt-3">
                            <div class="col-6">
                                <div class="p-2 rounded-2" style="background: var(--fr-bg); border: 1px solid var(--fr-border);">
                                    <div style="font-size: 10px; color: var(--fr-muted); font-weight: 700; text-transform: uppercase;">Service Type</div>
                                    <div class="fw-semibold" style="font-size: 13px;"><?= htmlspecialchars(ucfirst($project['service_type'])) ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-2 rounded-2" style="background: var(--fr-bg); border: 1px solid var(--fr-border);">
                                    <div style="font-size: 10px; color: var(--fr-muted); font-weight: 700; text-transform: uppercase;">Location</div>
                                    <div class="fw-semibold" style="font-size: 13px;"><?= htmlspecialchars($project['project_location'] ?? '—') ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-2 rounded-2" style="background: var(--fr-bg); border: 1px solid var(--fr-border);">
                                    <div style="font-size: 10px; color: var(--fr-muted); font-weight: 700; text-transform: uppercase;">Start Date</div>
                                    <div class="fw-semibold" style="font-size: 13px;"><?= !empty($project['start_date']) ? date('M d, Y', strtotime($project['start_date'])) : '—' ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-2 rounded-2" style="background: var(--fr-bg); border: 1px solid var(--fr-border);">
                                    <div style="font-size: 10px; color: var(--fr-muted); font-weight: 700; text-transform: uppercase;">Estimated End Date</div>
                                    <div class="fw-semibold" style="font-size: 13px;"><?= !empty($project['expected_end_date']) ? date('M d, Y', strtotime($project['expected_end_date'])) : '—' ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Read-only task photo viewer -->
<div class="modal fade" id="clientAttachmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Task Photos</h5>
                    <div class="client-attachment-task-desc text-muted small"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="client-attachment-empty text-center text-muted py-5 d-none">
                    No photos attached to this task.
                </div>
                <div class="client-attachment-stage position-relative rounded-3 mb-3 d-none" style="background:#111; overflow:hidden;">
                    <img class="client-attachment-main" src="" alt="" style="width:100%; display:block; max-height:65vh; object-fit:contain; background:#111;">
                    <button type="button" class="client-attachment-prev btn btn-dark position-absolute top-50 start-0 translate-middle-y ms-2" aria-label="Previous">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <button type="button" class="client-attachment-next btn btn-dark position-absolute top-50 end-0 translate-middle-y me-2" aria-label="Next">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                    <div class="client-attachment-meta position-absolute bottom-0 start-0 end-0 p-2 small text-white-50" style="background: linear-gradient(to top, rgba(0,0,0,.7), transparent);"></div>
                </div>
                <div class="client-attachment-strip d-flex gap-2 overflow-auto d-none" style="padding-bottom: 4px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
window.__clientTaskAttachments = <?= json_encode($clientAttachmentsByTask, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('clientAttachmentModal');
    if (!modalEl) return;

    const modal = new bootstrap.Modal(modalEl);

    const descEl  = modalEl.querySelector('.client-attachment-task-desc');
    const emptyEl = modalEl.querySelector('.client-attachment-empty');
    const stageEl = modalEl.querySelector('.client-attachment-stage');
    const stripEl = modalEl.querySelector('.client-attachment-strip');
    const mainImg = modalEl.querySelector('.client-attachment-main');
    const metaEl  = modalEl.querySelector('.client-attachment-meta');
    const prevBtn = modalEl.querySelector('.client-attachment-prev');
    const nextBtn = modalEl.querySelector('.client-attachment-next');

    let items = [];
    let index = 0;

    function formatBytes(n) {
        if (!n) return '0 B';
        const u = ['B','KB','MB','GB'];
        let i = 0, v = n;
        while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
        return v.toFixed(v >= 10 || i === 0 ? 0 : 1) + ' ' + u[i];
    }

    function render() {
        if (!items.length) {
            stageEl.classList.add('d-none');
            stripEl.classList.add('d-none');
            emptyEl.classList.remove('d-none');
            return;
        }
        emptyEl.classList.add('d-none');
        stageEl.classList.remove('d-none');
        stripEl.classList.remove('d-none');

        if (index < 0) index = 0;
        if (index >= items.length) index = items.length - 1;

        const cur = items[index];
        mainImg.src = cur.url;
        mainImg.alt = cur.original_name;

        metaEl.textContent =
            (index + 1) + ' / ' + items.length +
            ' · ' + cur.original_name +
            (cur.uploaded_by_name ? ' · uploaded by ' + cur.uploaded_by_name : '') +
            ' · ' + formatBytes(cur.file_size);

        prevBtn.style.display = items.length > 1 ? '' : 'none';
        nextBtn.style.display = items.length > 1 ? '' : 'none';

        stripEl.innerHTML = '';
        items.forEach((a, i) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn p-0 border rounded-2 flex-shrink-0';
            btn.style.width = '72px';
            btn.style.height = '72px';
            btn.style.overflow = 'hidden';
            if (i === index) { btn.classList.add('border-primary'); btn.style.borderWidth = '2px'; }
            btn.innerHTML = '<img src="' + a.url + '" alt="" style="width:100%;height:100%;object-fit:cover;display:block;">';
            btn.addEventListener('click', () => { index = i; render(); });
            stripEl.appendChild(btn);
        });
    }

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.client-attachment-open-btn');
        if (!btn || btn.disabled) return;
        const taskId = parseInt(btn.dataset.taskId, 10);
        const desc   = btn.dataset.taskDesc || '';
        items = (window.__clientTaskAttachments && window.__clientTaskAttachments[taskId]) || [];
        index = 0;
        descEl.textContent = desc;
        render();
        modal.show();
    });

    prevBtn.addEventListener('click', () => { index--; render(); });
    nextBtn.addEventListener('click', () => { index++; render(); });

    document.addEventListener('keydown', function (e) {
        if (!modalEl.classList.contains('show')) return;
        if (e.key === 'ArrowLeft')  { index--; render(); }
        if (e.key === 'ArrowRight') { index++; render(); }
    });
});
</script>