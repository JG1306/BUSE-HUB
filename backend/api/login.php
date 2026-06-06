<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$email    = trim($body['email'] ?? '');
$password = $body['password'] ?? '';
$role     = $body['role'] ?? '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['success' => false, 'message' => 'Invalid email'], 422);
}

$stmt = $pdo->prepare(
    'SELECT id, email, password, name, role FROM users WHERE email = ? LIMIT 1'
);
$stmt->execute([$email]);
$row = $stmt->fetch();

if (!$row || !password_verify($password, $row['password'])) {
    json_response(['success' => false, 'message' => 'Invalid credentials'], 401);
}

// Providers may log in as client to browse the marketplace.
// Clients cannot elevate to provider — they must register a separate provider account.
$requestedRole = (isset($role) && $role !== '') ? $role : $row['role'];
if ($row['role'] === 'client' && $requestedRole === 'provider') {
    json_response(['success' => false, 'message' => 'This account is not registered as a provider. Register a provider account to list your business.'], 403);
}

// Session role: provider browsing as client gets a client session.
$sessionRole = ($row['role'] === 'provider' && $requestedRole === 'client') ? 'client' : $row['role'];

$user = [
    'id'           => (int) $row['id'],
    'email'        => $row['email'],
    'full_name'    => $row['name'],
    'role'         => $sessionRole,
    'account_role' => $row['role'],
];

json_response([
    'success' => true,
    'message' => 'Login successful',
    'user' => $user,
]);
