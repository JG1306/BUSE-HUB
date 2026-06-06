<?php
/**
 * db.php — Shared database connection and helpers
 *
 * PURPOSE: Creates a single PDO connection that every PHP endpoint requires.
 *          Also defines the json_response() helper used throughout the backend.
 *
 * HOW IT WORKS:
 *   - Every backend PHP file starts with: require_once __DIR__ . '/config/db.php';
 *   - This gives them the $pdo object (ready to query) and the json_response()
 *     function (outputs JSON and exits cleanly).
 *   - PDO is configured to throw exceptions on errors (ERRMODE_EXCEPTION) so
 *     any DB failure is caught by the try/catch in each endpoint.
 *   - CORS headers are set by cors.php which this file requires first.
 *
 * DATABASE: buse_hub (MySQL via XAMPP)
 * DEFAULT CREDENTIALS: root / (empty password) — change for production.
 */
declare(strict_types=1);

// Load CORS headers so browser requests from localhost:3000 are allowed
require_once __DIR__ . '/cors.php';

$host = '127.0.0.1';
$db   = 'buse_hub';
$user = 'root';
$pass = '';  // Change this if your MySQL root has a password

$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        // Throw exceptions instead of silently returning false on errors
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        // Return rows as associative arrays by default (column name => value)
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    // Connection failed — send a JSON error and stop execution
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

/**
 * json_response()
 *
 * Sends a JSON response with the given HTTP status code and exits.
 * Used by every endpoint instead of echo + exit to keep responses consistent.
 *
 * @param array $data   The data to encode as JSON
 * @param int   $code   HTTP status code (default 200)
 */
function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
