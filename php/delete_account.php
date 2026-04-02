<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// EDC-26 — delete account (requires session)

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

function pdo_sqlite(string $path): PDO {
  $pdo = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec("PRAGMA busy_timeout = 5000");
  return $pdo;
}

function rrmdir(string $dir): void {
  if (!is_dir($dir)) return;
  $items = scandir($dir);
  if (!is_array($items)) return;
  foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $dir . DIRECTORY_SEPARATOR . $item;
    if (is_dir($path) && !is_link($path)) {
      rrmdir($path);
    } else {
      @unlink($path);
    }
  }
  @rmdir($dir);
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
if (!is_array($user) || !isset($user['id'], $user['folderName'], $user['role'])) {
  respond(401, ['ok' => false, 'error' => 'not_authenticated']);
}

$rootDir = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
$dbPath = $rootDir . '/storage/accounts/11co2/users.db';
$usersDir = $rootDir . '/storage/accounts/11co2/users';

if (!is_file($dbPath) || filesize($dbPath) === 0) {
  respond(500, ['ok' => false, 'error' => 'users_db_missing']);
}

$folderName = (string)$user['folderName'];
if ($folderName === '' || str_contains($folderName, '..') || str_contains($folderName, '/') || str_contains($folderName, '\\')) {
  respond(400, ['ok' => false, 'error' => 'invalid_folder']);
}

$userDir = $usersDir . '/' . $folderName;

try {
  $pdo = pdo_sqlite($dbPath);
  $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
  $stmt->execute([':id' => $user['id']]);

  // supprime dossier utilisateur
  rrmdir($userDir);

  // détruit session
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
  }
  session_destroy();

  respond(200, ['ok' => true]);
} catch (Throwable $e) {
  respond(500, ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
}
