<?php
/**
 * track_event.php
 *
 * PURPOSE: Records a view or WhatsApp click for a business listing.
 *
 * HOW IT WORKS:
 *   - Accepts a POST request with JSON body: { business_id, event }
 *   - "event" must be either "view" or "whatsapp_click"
 *   - Uses an atomic SQL increment (col = col + 1) so concurrent requests
 *     from multiple clients never overwrite each other's counts.
 *   - Only increments if the business is active (is_active = 1) to avoid
 *     counting phantom views on paused listings.
 *
 * CALLED FROM:
 *   - /api/track (Next.js proxy route)
 *   - Fired client-side in marketplace/page.tsx when a card is opened (view)
 *     or a WhatsApp button is tapped (whatsapp_click).
 */
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'POST required'], 405);
}

// Read JSON body sent by the Next.js frontend
$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$businessId = isset($body['business_id']) ? (int) $body['business_id'] : 0;
$event      = isset($body['event']) ? trim((string) $body['event']) : '';

if ($businessId <= 0) {
    json_response(['success' => false, 'message' => 'Invalid business_id'], 400);
}

// Only these two events are valid — prevents arbitrary column injection
$allowed = ['view', 'whatsapp_click'];
if (!in_array($event, $allowed, true)) {
    json_response(['success' => false, 'message' => 'Unknown event'], 400);
}

// Map event name to the correct DB column
$col = $event === 'view' ? 'views_count' : 'whatsapp_clicks';

try {
    // Atomic increment — safe under concurrent requests
    $stmt = $pdo->prepare("UPDATE businesses SET {$col} = {$col} + 1 WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $businessId]);
    json_response(['success' => true]);
} catch (PDOException $e) {
    json_response(['success' => false, 'message' => 'DB error'], 500);
}
