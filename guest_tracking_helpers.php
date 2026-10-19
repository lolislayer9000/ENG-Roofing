<?php
// REVISION: Booking-scoped private links. Never grant a login session from this token.
function guestTrackingToken(PDO $pdo, int $bookingId): string {
    $stmt = $pdo->prepare("SELECT token FROM guest_tracking_links WHERE booking_id = ? AND revoked_at IS NULL");
    $stmt->execute([$bookingId]);
    $token = $stmt->fetchColumn();
    if ($token) return $token;
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare("INSERT INTO guest_tracking_links (booking_id, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE token = VALUES(token), revoked_at = NULL");
    $stmt->execute([$bookingId, $token]);
    // Read the persisted value to handle concurrent email requests.
    $stmt = $pdo->prepare("SELECT token FROM guest_tracking_links WHERE booking_id = ?");
    $stmt->execute([$bookingId]);
    return (string)$stmt->fetchColumn();
}
function guestTrackingUrl(PDO $pdo, int $bookingId): string {
    // Fixed trusted origin: do not build private email links from an untrusted Host header.
    return 'https://bisque-lobster-868115.hostingersite.com/guest_track.php?token=' . rawurlencode(guestTrackingToken($pdo, $bookingId));
}
