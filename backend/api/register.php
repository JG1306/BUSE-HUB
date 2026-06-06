<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$email    = trim($body['email'] ?? '');
$password = $body['password'] ?? '';
$fullName = trim($body['full_name'] ?? '');
$role     = $body['role'] ?? '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['success' => false, 'message' => 'Invalid email'], 422);
}
if (!str_ends_with(strtolower($email), '@students.buse.ac.zw')) {
    json_response([
        'success' => false,
        'message' => 'Registration rejected. Valid BUSE student domain required (e.g., b2431224b@students.buse.ac.zw).',
    ], 422);
}
if (strlen($password) < 8) {
    json_response(['success' => false, 'message' => 'Password must be at least 8 characters'], 422);
}
if ($fullName === '') {
    json_response(['success' => false, 'message' => 'Full name is required'], 422);
}
if (!in_array($role, ['client', 'provider'], true)) {
    json_response(['success' => false, 'message' => 'Role must be client or provider'], 422);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare(
        'INSERT INTO users (email, password, name, role) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$email, $hash, $fullName, $role]);
} catch (PDOException $e) {
    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
        json_response(['success' => false, 'message' => 'Email already registered'], 409);
    }
    json_response(['success' => false, 'message' => 'Registration failed'], 500);
}

json_response([
    'success' => true,
    'message' => 'Account created',
    'user' => [
        'id' => (int) $pdo->lastInsertId(),
        'email' => $email,
        'full_name' => $fullName,
        'role' => $role,
    ],
], 201);
