<?php
// REVISION: One confirmed inspection per date + AM/PM across the company.
// All booking schedule writes acquire the same InnoDB mutex row in a transaction.
function bookingSlotName($value): ?string
{
    $value = strtolower(trim((string)$value));
    if (strpos($value, 'morning') !== false || $value === 'am') return 'AM';
    if (strpos($value, 'afternoon') !== false || $value === 'pm') return 'PM';
    return null;
}

function bookingScheduleRows(PDO $pdo): array
{
    return $pdo->query("SELECT b.id, b.booking_id, b.status, b.preferred_schedule,
        COALESCE(s.inspection_date,
            (SELECT si.inspection_date FROM site_inspections si
             WHERE si.booking_id = b.booking_id ORDER BY si.id DESC LIMIT 1),
            b.inspection_date, b.preferred_inspection_date) AS effective_date,
        s.confirmed_slot
        FROM bookings b LEFT JOIN booking_schedule_details s ON s.booking_id = b.id
        WHERE LOWER(b.status) IN ('approved', 'rescheduled')")->fetchAll(PDO::FETCH_ASSOC);
}

function bookingScheduleConflict(PDO $pdo, int $excludeId, string $date, string $slot): bool
{
    foreach (bookingScheduleRows($pdo) as $row) {
        if ((int)$row['id'] === $excludeId || $row['effective_date'] !== $date) continue;
        $occupied = $row['confirmed_slot'] ?: bookingSlotName($row['preferred_schedule']);
        // Unknown legacy slots are treated as occupied until Admin resolves them.
        if (!$occupied || $occupied === $slot) return true;
    }
    return false;
}

function bookingFullInspectionDates(PDO $pdo): array
{
    $dates = [];
    foreach (bookingScheduleRows($pdo) as $row) {
        $date = $row['effective_date'];
        if (!$date) continue;
        $slot = $row['confirmed_slot'] ?: bookingSlotName($row['preferred_schedule']);
        if (!$slot) $dates[$date] = ['AM' => true, 'PM' => true];
        else $dates[$date][$slot] = true;
    }
    return array_values(array_keys(array_filter($dates,
        function ($slots) { return isset($slots['AM'], $slots['PM']); })));
}

function saveBookingSchedule(PDO $pdo, int $id, bool $approve, string $date = '', string $slot = ''): array
{
    try {
        $pdo->beginTransaction();
        $pdo->query('SELECT id FROM booking_schedule_mutex WHERE id = 1 FOR UPDATE')->fetchColumn();
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking || ($approve && $booking['status'] !== 'pending')) {
            throw new RuntimeException('error');
        }
        if (!$approve && $booking['status'] === 'rejected') throw new RuntimeException('error');
        $stmt = $pdo->prepare('SELECT status FROM site_inspections WHERE booking_id = ? FOR UPDATE');
        $stmt->execute([$booking['booking_id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $status) {
            if (strtolower(trim($status)) === 'completed') throw new RuntimeException('inspection_locked');
        }
        if ($approve) {
            $stmt = $pdo->prepare('SELECT inspection_date, confirmed_slot FROM booking_schedule_details WHERE booking_id = ?');
            $stmt->execute([$id]);
            $saved = $stmt->fetch(PDO::FETCH_ASSOC);
            $date = $saved['inspection_date'] ?? ($booking['inspection_date'] ?: $booking['preferred_inspection_date']);
            $slot = $saved['confirmed_slot'] ?? (bookingSlotName($booking['preferred_schedule']) ?? '');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || !in_array($slot, ['AM', 'PM'], true)) {
            throw new RuntimeException('invalid_schedule');
        }
        // Preserve a legacy past date for an unchanged record; never create a new past appointment.
        $oldDate = $booking['inspection_date'] ?: $booking['preferred_inspection_date'];
        if ($date < (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d')
            && ($approve || $date !== $oldDate)) throw new RuntimeException('invalid_schedule');
        if (bookingScheduleConflict($pdo, $id, $date, $slot)) throw new RuntimeException('slot_conflict');

        $stmt = $pdo->prepare('INSERT INTO booking_schedule_details
            (booking_id, inspection_date, confirmed_slot, approved_at)
            VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE
            inspection_date = VALUES(inspection_date), confirmed_slot = VALUES(confirmed_slot),
            approved_at = COALESCE(approved_at, VALUES(approved_at))');
        $stmt->execute([$id, $date, $slot, $approve ? date('Y-m-d H:i:s') : null]);
        $stmt = $pdo->prepare('UPDATE bookings SET inspection_date = ?' . ($approve ? ", status = 'approved'" : '') . ' WHERE id = ?');
        $stmt->execute([$date, $id]);
        $booking['inspection_date'] = $date;
        if ($approve) {
            $booking['status'] = 'approved';
            createInspectionFromBooking($pdo, $booking);
        } else {
            $stmt = $pdo->prepare('UPDATE site_inspections SET inspection_date = ? WHERE booking_id = ?');
            $stmt->execute([$date, $booking['booking_id']]);
        }
        $pdo->commit();
        return ['ok' => true, 'booking' => $booking];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $known = ['error', 'inspection_locked', 'invalid_schedule', 'slot_conflict'];
        $message = in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'error';
        if ($message === 'error') error_log('Booking schedule failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => $message];
    }
}
