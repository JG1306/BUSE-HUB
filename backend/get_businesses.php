<?php
/**
 * get_businesses.php
 *
 * PURPOSE: Returns a list of businesses from the database.
 *
 * HOW IT WORKS:
 *   - Called via GET request with optional query parameters.
 *   - When ?user_id= is provided (dashboard use), it returns ALL businesses
 *     owned by that user regardless of is_active status, so a provider can
 *     always see and manage their own listing even if it is paused.
 *   - When no user_id is given (public marketplace), it only returns
 *     is_active = 1 businesses and supports search/campus/service_type filters.
 *   - Images are returned as relative paths (/buse-hub/backend/uploads/...)
 *     so they resolve correctly on both localhost and LAN (phone) access.
 *
 * QUERY PARAMS:
 *   ?user_id=7          → owner dashboard: returns that user's businesses
 *   ?search=printing    → public search across name, description, service_type
 *   ?campus=Main Campus → filter by campus
 *   ?service_type=Food  → filter by category
 */
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');

// Handle CORS preflight — browser sends OPTIONS before the real request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['success' => false, 'message' => 'Only GET requests allowed'], 405);
}

// ── Build WHERE clause dynamically based on which params were sent ──────────

if (!empty($_GET['user_id'])) {
    // OWNER / DASHBOARD MODE:
    // Provider fetching their own business — skip is_active filter so they
    // can see and edit their listing even when it is paused/inactive.
    $where  = ['user_id = :user_id'];
    $params = [':user_id' => (int) $_GET['user_id']];
} else {
    // PUBLIC MARKETPLACE MODE:
    // Only show active businesses. Apply optional search/filter params.
    $where  = ['is_active = 1'];
    $params = [];

    // Full-text style search across name, description and category
    if (!empty($_GET['search'])) {
        $where[] = '(business_name LIKE :search OR description LIKE :search OR service_type LIKE :search)';
        $params[':search'] = '%' . trim($_GET['search']) . '%';
    }

    // Exact campus filter (e.g. "Main Campus", "Administrative Campus")
    if (!empty($_GET['campus'])) {
        $where[] = 'campus = :campus';
        $params[':campus'] = trim($_GET['campus']);
    }

    // Exact service type filter (e.g. "Food & Catering")
    if (!empty($_GET['service_type'])) {
        $where[] = 'service_type = :service_type';
        $params[':service_type'] = trim($_GET['service_type']);
    }
}

// Build the final SQL — include is_active so the dashboard route can read it
$sql = 'SELECT id, user_id, business_name, service_type, description, campus,
               exact_location, location_key, samples_url, phone_number,
               is_active, views_count, whatsapp_clicks
        FROM businesses';

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

// Newest listings first
$sql .= ' ORDER BY id DESC';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Map each DB row to a clean API shape
    $businesses = array_map(static function (array $row): array {
        // Build a relative image URL so it works on localhost AND on LAN/phone.
        // The Next.js rewrite proxies /buse-hub/backend/uploads/* → Apache.
        $imageUrl = null;
        if (!empty($row['samples_url'])) {
            $imageUrl = '/buse-hub/backend/' . ltrim($row['samples_url'], '/');
        }

        return [
            'id'             => (int) $row['id'],
            'name'           => $row['business_name'],
            'service_type'   => $row['service_type'],
            'description'    => $row['description'],
            'campus'         => $row['campus'],
            'exact_location' => $row['exact_location'],
            'location_key'   => $row['location_key'] ?? null,
            'samples_url'    => $row['samples_url'],
            'image_url'      => $imageUrl,
            'phone_number'   => $row['phone_number'],
            'is_active'      => (int) $row['is_active'],
            'views_count'    => (int) ($row['views_count'] ?? 0),
            'whatsapp_clicks' => (int) ($row['whatsapp_clicks'] ?? 0),
        ];
    }, $rows);

    json_response(['success' => true, 'businesses' => $businesses]);
} catch (PDOException $e) {
    json_response(['success' => false, 'message' => 'Failed to load businesses'], 500);
}
