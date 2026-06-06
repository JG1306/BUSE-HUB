<?php
/**
 * get_catalog.php
 *
 * PURPOSE: Returns catalog items (products/services) for a business,
 *          each with their associated images.
 *
 * HOW IT WORKS:
 *   - Two modes depending on which query param is sent:
 *
 *   1. ?business_id=4
 *      Returns all available items for that business.
 *      Used by: marketplace detail modal, provider manage dashboard.
 *
 *   2. ?search=t-shirt
 *      Global search across all active businesses' items.
 *      Also JOINs the businesses table to return contact info alongside
 *      each item, so the marketplace can show "Message Seller" buttons.
 *
 *   - Images are fetched in a SINGLE extra query using IN(...) so we
 *     never do N+1 queries (one per item). They are grouped by item_id
 *     and attached to the correct item in PHP before returning.
 *
 * RETURNS:
 *   { success: true, items: [ { id, item_name, price, unit, images: [...] } ] }
 */
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['success' => false, 'message' => 'Only GET requests allowed'], 405);
}

$params = [];

if (!empty($_GET['business_id'])) {
    // Fetch all available items for one business (sorted cheapest first)
    $sql = 'SELECT p.id, p.business_id, p.item_name, p.price, p.unit, p.is_available
            FROM products_services p
            WHERE p.business_id = :business_id AND p.is_available = 1
            ORDER BY p.price ASC';
    $params[':business_id'] = (int) $_GET['business_id'];

} elseif (!empty($_GET['search'])) {
    // Global item search — JOIN businesses so we can show seller contact info
    $sql = 'SELECT p.id, p.business_id, p.item_name, p.price, p.unit, p.is_available,
                   b.business_name, b.service_type, b.campus, b.exact_location,
                   b.phone_number, b.samples_url
            FROM products_services p
            JOIN businesses b ON b.id = p.business_id
            WHERE p.item_name LIKE :search AND p.is_available = 1 AND b.is_active = 1
            ORDER BY p.price ASC';
    $params[':search'] = '%' . trim($_GET['search']) . '%';

} else {
    json_response(['success' => false, 'message' => 'business_id or search required'], 400);
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Return early with empty array if no items found
    if (empty($rows)) {
        json_response(['success' => true, 'items' => []]);
    }

    // ── Fetch images for all items in ONE query (avoids N+1) ─────────────
    // Collect the IDs of every item returned above
    $itemIds      = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

    $imgStmt = $pdo->prepare(
        "SELECT item_id, id AS image_id, image_path
         FROM catalog_item_images
         WHERE item_id IN ($placeholders)
         ORDER BY item_id, sort_order ASC"
    );
    $imgStmt->execute($itemIds);

    // Group the images by their item_id so we can attach them below
    $imagesByItem = [];
    foreach ($imgStmt->fetchAll() as $img) {
        // Build relative URL — Next.js rewrite proxies this to Apache
        $imagesByItem[(int) $img['item_id']][] = [
            'id'  => (int) $img['image_id'],
            'url' => '/buse-hub/backend/' . ltrim($img['image_path'], '/'),
        ];
    }

    // ── Build the final items array ───────────────────────────────────────
    $items = array_map(static function (array $r) use ($imagesByItem): array {
        $id = (int) $r['id'];
        return [
            'id'           => $id,
            'business_id'  => (int) $r['business_id'],
            'item_name'    => $r['item_name'],
            'price'        => (float) $r['price'],
            'unit'         => $r['unit'],
            'is_available' => (int) $r['is_available'],
            // Attach the pre-grouped images for this item (empty array if none)
            'images'       => $imagesByItem[$id] ?? [],
            // Search-mode extras (null when fetching by business_id)
            'business_name'  => $r['business_name'] ?? null,
            'service_type'   => $r['service_type'] ?? null,
            'campus'         => $r['campus'] ?? null,
            'exact_location' => $r['exact_location'] ?? null,
            'phone_number'   => $r['phone_number'] ?? null,
            'samples_url'    => $r['samples_url'] ?? null,
        ];
    }, $rows);

    json_response(['success' => true, 'items' => $items]);
} catch (PDOException $e) {
    json_response(['success' => false, 'message' => 'Failed to load catalog'], 500);
}
