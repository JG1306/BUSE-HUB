<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$required = [
    'business_name',
    'service_type',
    'description',
    'campus',
    'exact_location',
    'phone_number',
];

foreach ($required as $field) {
    if (!isset($_POST[$field]) || trim((string) $_POST[$field]) === '') {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => "Missing required field: $field",
        ]);
        exit;
    }
}

// Capture and normalize WhatsApp number (digits only, keep leading country code)
$phoneNumber = preg_replace('/\D+/', '', trim((string) $_POST['phone_number']));
if (strlen($phoneNumber) < 9) {
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'message' => 'Enter a valid WhatsApp number with country code (e.g. 263771234567)',
    ]);
    exit;
}

$businessId = isset($_POST['id']) ? (int) $_POST['id'] : null;
$userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : null;
$isActive = isset($_POST['is_active']) ? ((string) $_POST['is_active'] === '1' ? 1 : 0) : 1;
$locationKey = isset($_POST['location_key']) ? trim((string) $_POST['location_key']) : 'main_block_a';
$locationUrl = isset($_POST['location_url']) ? trim((string) $_POST['location_url']) : '';

if ($locationUrl !== '' && !filter_var($locationUrl, FILTER_VALIDATE_URL)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Enter a valid location link, or leave it empty.',
    ]);
    exit;
}

if ($userId === null || $userId <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing or invalid user_id',
    ]);
    exit;
}

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$sampleWorkPath = null;
$uploadField = null;
if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
    $uploadField = 'image';
} elseif (isset($_FILES['sample_work']) && $_FILES['sample_work']['error'] !== UPLOAD_ERR_NO_FILE) {
    $uploadField = 'sample_work';
} elseif (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
    $uploadField = 'file';
}

if ($uploadField !== null) {
    $file = $_FILES[$uploadField];
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid upload payload.',
        ]);
        exit;
    }

    $maxBytes = 5 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        http_response_code(413);
        echo json_encode([
            'status' => 'error',
            'message' => 'Image must be 5MB or smaller.',
        ]);
        exit;
    }

    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!array_key_exists($mimeType, $allowedMime)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid image type. Allowed: JPG, JPEG, PNG.',
        ]);
        exit;
    }

    if (@getimagesize($file['tmp_name']) === false) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Uploaded file is not a valid image.',
        ]);
        exit;
    }

    $extension = $allowedMime[$mimeType];
    $filename = uniqid('sample_', true) . '.' . $extension;
    $targetPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to save uploaded image.',
        ]);
        exit;
    }

    $sampleWorkPath = 'uploads/' . $filename;
}

try {
    if ($businessId !== null) {
        $sql = 'UPDATE businesses SET 
                    business_name = :business_name, 
                    service_type = :service_type, 
                    description = :description, 
                    campus = :campus, 
                    exact_location = :exact_location, 
                    location_url = :location_url,
                    phone_number = :phone_number, 
                    is_active = :is_active, 
                    location_key = :location_key';

        if ($sampleWorkPath !== null) {
            $sql .= ', samples_url = :samples_url';
        }
        $sql .= ' WHERE id = :id AND user_id = :user_id';

        $params = [
            ':business_name'  => trim((string) $_POST['business_name']),
            ':service_type'   => trim((string) $_POST['service_type']),
            ':description'    => trim((string) $_POST['description']),
            ':campus'         => trim((string) $_POST['campus']),
            ':exact_location' => trim((string) $_POST['exact_location']),
            ':location_url'   => $locationUrl !== '' ? $locationUrl : null,
            ':phone_number'   => $phoneNumber,
            ':is_active'      => $isActive,
            ':location_key'  => $locationKey,
            ':id'             => $businessId,
            ':user_id'        => $userId,
        ];
        if ($sampleWorkPath !== null) {
            $params[':samples_url'] = $sampleWorkPath;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode([
            'success' => true,
            'message' => 'Business profile updated successfully',
            'id' => $businessId,
            'phone_number' => $phoneNumber,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO businesses (
                user_id, business_name, service_type, description, campus, exact_location, location_url, location_key, phone_number, samples_url, is_active
            ) VALUES (
                :user_id, :business_name, :service_type, :description, :campus, :exact_location, :location_url, :location_key, :phone_number, :samples_url, :is_active
            )'
        );
        $stmt->execute([
            ':user_id'        => $userId,
            ':business_name'  => trim((string) $_POST['business_name']),
            ':service_type'   => trim((string) $_POST['service_type']),
            ':description'    => trim((string) $_POST['description']),
            ':campus'         => trim((string) $_POST['campus']),
            ':exact_location' => trim((string) $_POST['exact_location']),
            ':location_url'   => $locationUrl !== '' ? $locationUrl : null,
            ':location_key'   => $locationKey,
            ':phone_number'   => $phoneNumber,
            ':samples_url'    => $sampleWorkPath,
            ':is_active'      => $isActive,
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Business listed successfully',
            'id' => (int) $pdo->lastInsertId(),
            'phone_number' => $phoneNumber,
        ]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database operation failed',
    ]);
}
