<?php
// inspection_attachment.php
//
// Authenticated file server for site inspection attachments.
// Authorization:
//   - admin  : any attachment
//   - foreman: only attachments on inspections they are the inspector of
//   - client : only attachments on inspections belonging to their projects
//   - others : denied

ob_start();

require_once 'config.php';
requireLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    ob_end_clean();
    http_response_code(400);
    exit('Bad request.');
}

$stmt = $pdo->prepare("
    SELECT
        a.stored_name,
        a.mime_type,
        a.original_name,
        si.inspector_id,
        si.client_id
    FROM site_inspection_attachments a
    INNER JOIN site_inspections si
        ON si.id = a.inspection_id
    WHERE a.id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    ob_end_clean();
    http_response_code(404);
    exit('Attachment not found.');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$role   = $_SESSION['role'] ?? '';

$allowed = false;

if ($role === 'admin') {
    $allowed = true;
} elseif ($role === 'foreman' && (int)$row['inspector_id'] === $userId) {
    $allowed = true;
} elseif ($role === 'client' && (int)$row['client_id'] === $userId) {
    $allowed = true;
}

if (!$allowed) {
    ob_end_clean();
    http_response_code(403);
    exit('Forbidden.');
}

$storedName = basename((string)$row['stored_name']);

if ($storedName === '') {
    ob_end_clean();
    http_response_code(404);
    exit('Invalid stored filename.');
}

$path = INSPECTION_ATTACHMENT_DIR . DIRECTORY_SEPARATOR . $storedName;

if (!is_file($path)) {
    ob_end_clean();
    http_response_code(404);
    exit('File missing.');
}

if (!is_readable($path)) {
    ob_end_clean();
    http_response_code(500);
    exit('File is not readable.');
}

$mime = (string)($row['mime_type'] ?? '');
if (!preg_match('#^image/(jpeg|png|gif|webp|bmp)$#i', $mime)) {
    $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (is_string($detectedMime)) {
        $mime = $detectedMime;
    }
}
if (!preg_match('#^image/(jpeg|png|gif|webp|bmp)$#i', $mime)) {
    ob_end_clean();
    http_response_code(415);
    exit('Unsupported image type.');
}

$fileSize = filesize($path);
if ($fileSize === false) {
    ob_end_clean();
    http_response_code(500);
    exit('Unable to determine file size.');
}

ob_end_clean();

header('Content-Type: ' . $mime);
header('Content-Length: ' . $fileSize);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . addcslashes($row['original_name'], "\"\\") . '"');

readfile($path);
exit;