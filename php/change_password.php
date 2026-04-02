<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// EDC-26 — change password (requires session)

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
  header('Access-Control-Allow-Origin: ' . $origin);
  header('Vary: Origin');
} else {
  header('Access-Control-Allow-Origin: *');
}
  header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method_not_allowed', 'allowed' => ['POST']], JSON_UNESCAPED_UNICODE);
  exit;
}

function respond(int $status, array $payload): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function read_input(): array {
  $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
  if (str_contains($contentType, 'application/json')) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
  }
  return is_array($_POST) ? $_POST : [];
}

function pdo_sqlite(string $path): PDO {
  $pdo = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec("PRAGMA busy_timeout = 5000");
  return $pdo;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  ]);
  session_start();
}

$user = $_SESSION['user'] ?? null;
if (!is_array($user) || !isset($user['id'], $user['email'])) {
  respond(401, ['ok' => false, 'error' => 'not_authenticated']);
}

$input = read_input();
$newPassword = (string)($input['newPassword'] ?? $input['new_password'] ?? $input['password'] ?? '');

if (trim($newPassword) === '') {
  respond(400, ['ok' => false, 'error' => 'missing_fields', 'required' => ['newPassword']]);
}

if (strlen($newPassword) < 6) {
  respond(400, ['ok' => false, 'error' => 'password_too_short', 'minLength' => 6]);
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);
if (!is_string($hash) || $hash === '') {
  respond(500, ['ok' => false, 'error' => 'password_hash_failed']);
}

$rootDir = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
$dbPath = $rootDir . '/storage/accounts/11co2/users.db';
if (!is_file($dbPath) || filesize($dbPath) === 0) {
  respond(500, ['ok' => false, 'error' => 'users_db_missing']);
}

try {
  $pdo = pdo_sqlite($dbPath);
  $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
  $stmt->execute([':hash' => $hash, ':id' => $user['id']]);

  respond(200, ['ok' => true]);
} catch (Throwable $e) {
  respond(500, ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
}
