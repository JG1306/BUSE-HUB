<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Only POST allowed'], 405);
}

$action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';
$userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : null;

if ($userId === null || $userId <= 0) {
    json_response(['success' => false, 'message' => 'Missing or invalid user_id'], 400);
}

// ── Shared image upload helper ──────────────────────────────────────────────
function save_uploaded_images(array $files, int $itemId, PDO $pdo): void
{
    $uploadDir = __DIR__ . '/uploads/catalog/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $allowedMime = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $maxBytes    = 5 * 1024 * 1024;

    // Normalise $_FILES multi-upload structure
    $list = [];
    if (isset($files['tmp_name'])) {
        if (is_array($files['tmp_name'])) {
            foreach ($files['tmp_name'] as $i => $tmp) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $list[] = ['tmp' => $tmp, 'size' => $files['size'][$i], 'name' => $files['name'][$i]];
                }
            }
        } elseif ($files['error'] === UPLOAD_ERR_OK) {
            $list[] = ['tmp' => $files['tmp_name'], 'size' => $files['size'], 'name' => $files['name']];
        }
    }

    // Get current max sort_order for this item
    $orderStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM catalog_item_images WHERE item_id = :id');
    $orderStmt->execute([':id' => $itemId]);
    $sortOrder = (int) $orderStmt->fetchColumn();

    $insertStmt = $pdo->prepare(
        'INSERT INTO catalog_item_images (item_id, image_path, sort_order) VALUES (:item_id, :image_path, :sort_order)'
    );

    foreach ($list as $f) {
        if ($f['size'] > $maxBytes) continue;

        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($f['tmp']);
        if (!array_key_exists($mimeType, $allowedMime)) continue;
        if (@getimagesize($f['tmp']) === false) continue;

        $ext      = $allowedMime[$mimeType];
        $filename = 'item_' . $itemId . '_' . uniqid('', true) . '.' . $ext;
        $target   = $uploadDir . $filename;

        if (!move_uploaded_file($f['tmp'], $target)) continue;

        $sortOrder++;
        $insertStmt->execute([
            ':item_id'    => $itemId,
            ':image_path' => 'uploads/catalog/' . $filename,
            ':sort_order' => $sortOrder,
        ]);
    }
}

// ── Actions ─────────────────────────────────────────────────────────────────
try {
    if ($action === 'create') {
        $businessId  = isset($_POST['business_id']) ? (int) $_POST['business_id'] : 0;
        $itemName    = isset($_POST['item_name']) ? trim((string) $_POST['item_name']) : '';
        $price       = isset($_POST['price']) ? (float) $_POST['price'] : 0.0;
        $unit        = isset($_POST['unit']) ? trim((string) $_POST['unit']) : 'per item';
        $isAvailable = isset($_POST['is_available']) && (string) $_POST['is_available'] === '1' ? 1 : 0;

        if ($businessId <= 0 || $itemName === '' || $price <= 0) {
            json_response(['success' => false, 'message' => 'Missing required fields'], 400);
        }

        // Ownership check
        $check = $pdo->prepare('SELECT id FROM businesses WHERE id = :id AND user_id = :user_id');
        $check->execute([':id' => $businessId, ':user_id' => $userId]);
        if ($check->fetch() === false) {
            json_response(['success' => false, 'message' => 'Business not found or not owned by user'], 403);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO products_services (business_id, item_name, price, unit, is_available)
             VALUES (:business_id, :item_name, :price, :unit, :is_available)'
        );
        $stmt->execute([
            ':business_id' => $businessId,
            ':item_name'   => $itemName,
            ':price'       => $price,
            ':unit'        => $unit,
            ':is_available' => $isAvailable,
        ]);
        $newId = (int) $pdo->lastInsertId();

        // Save any uploaded images
        if (!empty($_FILES['images'])) {
            save_uploaded_images($_FILES['images'], $newId, $pdo);
        } elseif (!empty($_FILES['image'])) {
            save_uploaded_images($_FILES['image'], $newId, $pdo);
        }

        json_response(['success' => true, 'message' => 'Item added', 'id' => $newId]);

    } elseif ($action === 'update') {
        $id          = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $itemName    = isset($_POST['item_name']) ? trim((string) $_POST['item_name']) : null;
        $price       = isset($_POST['price']) && $_POST['price'] !== '' ? (float) $_POST['price'] : null;
        $unit        = isset($_POST['unit']) ? trim((string) $_POST['unit']) : null;
        $isAvailable = isset($_POST['is_available']) ? ((string) $_POST['is_available'] === '1' ? 1 : 0) : null;

        if ($id <= 0) {
            json_response(['success' => false, 'message' => 'Invalid item id'], 400);
        }

        // Ownership check
        $q = $pdo->prepare(
            'SELECT p.id FROM products_services p
             JOIN businesses b ON b.id = p.business_id
             WHERE p.id = :id AND b.user_id = :user_id'
        );
        $q->execute([':id' => $id, ':user_id' => $userId]);
        if ($q->fetch() === false) {
            json_response(['success' => false, 'message' => 'Item not found or not authorized'], 403);
        }

        $sets   = [];
        $params = [':id' => $id];
        if ($itemName !== null && $itemName !== '') { $sets[] = 'item_name = :item_name'; $params[':item_name'] = $itemName; }
        if ($price !== null)       { $sets[] = 'price = :price';             $params[':price']       = $price; }
        if ($unit !== null)        { $sets[] = 'unit = :unit';               $params[':unit']        = $unit; }
        if ($isAvailable !== null) { $sets[] = 'is_available = :is_available'; $params[':is_available'] = $isAvailable; }

        if (!empty($sets)) {
            $sql  = 'UPDATE products_services SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        // Save any new uploaded images
        if (!empty($_FILES['images'])) {
            save_uploaded_images($_FILES['images'], $id, $pdo);
        } elseif (!empty($_FILES['image'])) {
            save_uploaded_images($_FILES['image'], $id, $pdo);
        }

        json_response(['success' => true, 'message' => 'Item updated']);

    } elseif ($action === 'delete') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) {
            json_response(['success' => false, 'message' => 'Invalid item id'], 400);
        }

        $q = $pdo->prepare(
            'SELECT p.id FROM products_services p
             JOIN businesses b ON b.id = p.business_id
             WHERE p.id = :id AND b.user_id = :user_id'
        );
        $q->execute([':id' => $id, ':user_id' => $userId]);
        if ($q->fetch() === false) {
            json_response(['success' => false, 'message' => 'Item not found or not authorized'], 403);
        }

        // Delete image files from disk before removing DB rows (CASCADE handles DB)
        $imgs = $pdo->prepare('SELECT image_path FROM catalog_item_images WHERE item_id = :id');
        $imgs->execute([':id' => $id]);
        foreach ($imgs->fetchAll() as $img) {
            $file = __DIR__ . '/' . ltrim($img['image_path'], '/');
            if (file_exists($file)) @unlink($file);
        }

        $stmt = $pdo->prepare('DELETE FROM products_services WHERE id = :id');
        $stmt->execute([':id' => $id]);

        json_response(['success' => true, 'message' => 'Item deleted']);

    } elseif ($action === 'delete_image') {
        // Remove a single image from a catalog item
        $imageId = isset($_POST['image_id']) ? (int) $_POST['image_id'] : 0;
        if ($imageId <= 0) {
            json_response(['success' => false, 'message' => 'Invalid image_id'], 400);
        }

        // Ownership: image → item → business → user
        $q = $pdo->prepare(
            'SELECT ci.image_path FROM catalog_item_images ci
             JOIN products_services p ON p.id = ci.item_id
             JOIN businesses b ON b.id = p.business_id
             WHERE ci.id = :image_id AND b.user_id = :user_id'
        );
        $q->execute([':image_id' => $imageId, ':user_id' => $userId]);
        $row = $q->fetch();
        if ($row === false) {
            json_response(['success' => false, 'message' => 'Image not found or not authorized'], 403);
        }

        // Delete file
        $file = __DIR__ . '/' . ltrim($row['image_path'], '/');
        if (file_exists($file)) @unlink($file);

        $pdo->prepare('DELETE FROM catalog_item_images WHERE id = :id')->execute([':id' => $imageId]);

        json_response(['success' => true, 'message' => 'Image removed']);

    } else {
        json_response(['success' => false, 'message' => 'Unknown action'], 400);
    }
} catch (PDOException $e) {
    json_response(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
}
