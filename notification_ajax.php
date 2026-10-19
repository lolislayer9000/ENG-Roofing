<?php
// notification_ajax.php – AJAX endpoint for notifications

require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

$userId = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? '';

try {

    if ($action === 'get') {
        $unread = getUnreadNotificationCount($pdo, $userId);
        $notifications = getNotifications($pdo, $userId, 20);

        echo json_encode([
            'success' => true,
            'unread_count' => $unread,
            'notifications' => $notifications
        ]);
        exit;
    }

    if ($action === 'mark_read') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

        if ($id <= 0) {
            echo json_encode([
                'success' => false,
                'error' => 'Invalid ID'
            ]);
            exit;
        }

        echo json_encode([
            'success' => markNotificationRead($pdo, $id, $userId)
        ]);
        exit;
    }

    if ($action === 'mark_all_read') {
        echo json_encode([
            'success' => markAllNotificationsRead($pdo, $userId)
        ]);
        exit;
    }

    echo json_encode([
        'success' => false,
        'error' => 'Unknown action'
    ]);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}