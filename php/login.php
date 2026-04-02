<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// EDC-26 — connexion (prototype)
// POST JSON ou form-data -> vérifie email+password dans SQLite et renvoie userId + infos.

// CORS (prototype): support same-origin + optional cross-origin with credentials
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
  echo json_encode([
    'ok' => false,
    'error' => 'method_not_allowed',
    'allowed' => ['POST'],
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// Session (prototype)
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  ]);
  session_start();
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

function normalize_whitespace(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return $s ?? '';
}

function pdo_sqlite(string $path): PDO {
  $pdo = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec("PRAGMA busy_timeout = 5000");
  return $pdo;
}

$input = read_input();
$email = normalize_whitespace((string)($input['email'] ?? ''));
$password = (string)($input['password'] ?? $input['motdepasse'] ?? $input['mdp'] ?? '');

if ($email === '' || $password === '') {
  respond(400, ['ok' => false, 'error' => 'missing_fields', 'required' => ['email', 'password']]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  respond(400, ['ok' => false, 'error' => 'invalid_email']);
}

$rootDir = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
$globalDbPath = $rootDir . '/storage/accounts/11co2/users.db';
if (!is_file($globalDbPath) || filesize($globalDbPath) === 0) {
  respond(404, ['ok' => false, 'error' => 'no_users_db']);
}

try {
  $pdo = pdo_sqlite($globalDbPath);

  // Assure que la table existe (au cas où)
  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS users (" .
    "id TEXT PRIMARY KEY, " .
    "nom TEXT NOT NULL, " .
    "prenom TEXT NOT NULL, " .
    "email TEXT NOT NULL UNIQUE, " .
    "password_hash TEXT NOT NULL, " .
    "role TEXT NOT NULL, " .
    "created_at TEXT NOT NULL, " .
    "folder_name TEXT NOT NULL" .
    ")"
  );

  $stmt = $pdo->prepare('SELECT id, nom, prenom, email, password_hash, role, created_at, folder_name FROM users WHERE email = :email LIMIT 1');
  $stmt->execute([':email' => $email]);
  $row = $stmt->fetch();

  if (!$row || !isset($row['password_hash']) || !is_string($row['password_hash'])) {
    // ne pas révéler si l'email existe
    respond(401, ['ok' => false, 'error' => 'invalid_credentials']);
  }

  if (!password_verify($password, $row['password_hash'])) {
    respond(401, ['ok' => false, 'error' => 'invalid_credentials']);
  }

  $_SESSION['user'] = [
    'id' => $row['id'],
    'nom' => $row['nom'],
    'prenom' => $row['prenom'],
    'email' => $row['email'],
    'role' => $row['role'],
    'createdAt' => $row['created_at'],
    'folderName' => $row['folder_name'],
  ];

  respond(200, [
    'ok' => true,
    'userId' => $row['id'],
    'nom' => $row['nom'],
    'prenom' => $row['prenom'],
    'email' => $row['email'],
    'role' => $row['role'],
    'createdAt' => $row['created_at'],
    'folderName' => $row['folder_name'],
    'userDir' => 'storage/accounts/11co2/users/' . $row['folder_name'],
  ]);
} catch (Throwable $e) {
  respond(500, ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
}
