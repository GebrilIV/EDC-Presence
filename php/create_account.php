<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// EDC-26 — création de compte (prototype)
// POST JSON ou form-data -> crée dossiers + DBs, et enregistre l'utilisateur.

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

  // application/x-www-form-urlencoded ou multipart/form-data
  return is_array($_POST) ? $_POST : [];
}

function normalize_whitespace(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return $s ?? '';
}

function slugify(string $s): string {
  $s = normalize_whitespace($s);
  $s = mb_strtolower($s, 'UTF-8');

  if (function_exists('iconv')) {
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if (is_string($converted) && $converted !== '') {
      $s = $converted;
    }
  }

  // remplace tout ce qui n'est pas alphanum, point, tiret, underscore
  $s = preg_replace('/[^a-z0-9._-]+/i', '-', $s);
  $s = trim($s ?? '', '-');
  $s = preg_replace('/-+/', '-', $s);
  return $s ?: 'user';
}

function ensure_dir(string $path): void {
  if (is_dir($path)) return;
  if (@mkdir($path, 0700, true)) return;
  if (is_dir($path)) return;
  throw new RuntimeException('failed_to_create_dir: ' . $path);
}

function ensure_unique_dir_name(string $parentDir, string $baseName): string {
  $candidate = $baseName;
  $i = 2;
  while (is_dir($parentDir . DIRECTORY_SEPARATOR . $candidate)) {
    $candidate = $baseName . '-' . $i;
    $i++;
    if ($i > 200) {
      throw new RuntimeException('too_many_similar_users');
    }
  }
  return $candidate;
}

function parse_allowed_domains(): array {
  $raw = (string)(getenv('ALLOWED_EMAIL_DOMAINS') ?: '');
  $parts = preg_split('/[;,\s]+/', strtolower($raw));
  $parts = array_values(array_filter(array_map('trim', $parts ?: []), fn($v) => $v !== ''));
  return $parts;
}

function email_domain(string $email): string {
  $pos = strrpos($email, '@');
  if ($pos === false) return '';
  return strtolower(substr($email, $pos + 1));
}

function pdo_sqlite(string $path): PDO {
  $pdo = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec("PRAGMA journal_mode = WAL");
  $pdo->exec("PRAGMA foreign_keys = ON");
  $pdo->exec("PRAGMA busy_timeout = 5000");
  return $pdo;
}

$input = read_input();

$nom = normalize_whitespace((string)($input['nom'] ?? $input['name'] ?? ''));
$prenom = normalize_whitespace((string)($input['prenom'] ?? $input['firstName'] ?? ''));
$email = normalize_whitespace((string)($input['email'] ?? ''));
$password = (string)($input['password'] ?? $input['motdepasse'] ?? $input['mdp'] ?? '');
$role = normalize_whitespace((string)($input['role'] ?? 'student'));

if ($nom === '' || $prenom === '' || $email === '' || $password === '') {
  respond(400, [
    'ok' => false,
    'error' => 'missing_fields',
    'required' => ['nom', 'prenom', 'email', 'password'],
  ]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  respond(400, ['ok' => false, 'error' => 'invalid_email']);
}

$allowedRoles = ['admin', 'teacher', 'student'];
if (!in_array($role, $allowedRoles, true)) {
  respond(400, ['ok' => false, 'error' => 'invalid_role', 'allowed' => $allowedRoles]);
}

$allowedDomains = parse_allowed_domains();
if (count($allowedDomains) > 0) {
  $domain = email_domain($email);
  if ($domain === '' || !in_array($domain, $allowedDomains, true)) {
    respond(403, ['ok' => false, 'error' => 'email_domain_not_allowed', 'allowedDomains' => $allowedDomains]);
  }
}

$rootDir = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');

// Config chemins
$accountRoot = $rootDir . '/storage/accounts/11co2';
$usersDir = $accountRoot . '/users';
$globalDbPath = $accountRoot . '/users.db';

try {
  ensure_dir($accountRoot);
  ensure_dir($usersDir);

  $folderBase = slugify($nom) . '.' . slugify($prenom);
  $folderName = ensure_unique_dir_name($usersDir, $folderBase);

  $userDir = $usersDir . '/' . $folderName;
  ensure_dir($userDir);
  ensure_dir($userDir . '/pics');
  ensure_dir($userDir . '/loc');
  ensure_dir($userDir . '/acc');

  $userId = bin2hex(random_bytes(16));
  $createdAt = gmdate('c');
  $passwordHash = password_hash($password, PASSWORD_DEFAULT);
  if (!is_string($passwordHash) || $passwordHash === '') {
    throw new RuntimeException('password_hash_failed');
  }

  // 1) DB globale (liste des utilisateurs)
  $pdo = pdo_sqlite($globalDbPath);
  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS users (\n" .
    "  id TEXT PRIMARY KEY,\n" .
    "  nom TEXT NOT NULL,\n" .
    "  prenom TEXT NOT NULL,\n" .
    "  email TEXT NOT NULL UNIQUE,\n" .
    "  password_hash TEXT NOT NULL,\n" .
    "  role TEXT NOT NULL,\n" .
    "  created_at TEXT NOT NULL,\n" .
    "  folder_name TEXT NOT NULL\n" .
    ")"
  );
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)");

  $pdo->beginTransaction();

  $stmtExists = $pdo->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
  $stmtExists->execute([':email' => $email]);
  if ($stmtExists->fetchColumn()) {
    $pdo->rollBack();
    respond(409, ['ok' => false, 'error' => 'email_already_exists']);
  }

  $stmtIns = $pdo->prepare(
    'INSERT INTO users (id, nom, prenom, email, password_hash, role, created_at, folder_name) '
    . 'VALUES (:id, :nom, :prenom, :email, :password_hash, :role, :created_at, :folder_name)'
  );
  $stmtIns->execute([
    ':id' => $userId,
    ':nom' => $nom,
    ':prenom' => $prenom,
    ':email' => $email,
    ':password_hash' => $passwordHash,
    ':role' => $role,
    ':created_at' => $createdAt,
    ':folder_name' => $folderName,
  ]);

  // 2) DB locale dans /acc
  $accDbPath = $userDir . '/acc/account.db';
  $pdoAcc = pdo_sqlite($accDbPath);
  $pdoAcc->exec(
    "CREATE TABLE IF NOT EXISTS profile (\n" .
    "  user_id TEXT PRIMARY KEY,\n" .
    "  nom TEXT NOT NULL,\n" .
    "  prenom TEXT NOT NULL,\n" .
    "  email TEXT NOT NULL,\n" .
    "  role TEXT NOT NULL,\n" .
    "  created_at TEXT NOT NULL,\n" .
    "  extra_json TEXT\n" .
    ")"
  );

  $stmtProf = $pdoAcc->prepare(
    'INSERT OR REPLACE INTO profile (user_id, nom, prenom, email, role, created_at, extra_json) '
    . 'VALUES (:user_id, :nom, :prenom, :email, :role, :created_at, :extra_json)'
  );
  $stmtProf->execute([
    ':user_id' => $userId,
    ':nom' => $nom,
    ':prenom' => $prenom,
    ':email' => $email,
    ':role' => $role,
    ':created_at' => $createdAt,
    ':extra_json' => json_encode(new stdClass(), JSON_UNESCAPED_UNICODE),
  ]);

  // 3) JSON de base (facile à lire côté admin)
  $userJsonPath = $userDir . '/user.json';
  $userJson = [
    'userId' => $userId,
    'nom' => $nom,
    'prenom' => $prenom,
    'email' => $email,
    'role' => $role,
    'createdAt' => $createdAt,
    'paths' => [
      'pics' => 'pics/',
      'loc' => 'loc/',
      'acc' => 'acc/',
      'accDb' => 'acc/account.db',
    ],
  ];
  file_put_contents($userJsonPath, json_encode($userJson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

  $pdo->commit();

  // Auto-login: session
  $_SESSION['user'] = [
    'id' => $userId,
    'nom' => $nom,
    'prenom' => $prenom,
    'email' => $email,
    'role' => $role,
    'createdAt' => $createdAt,
    'folderName' => $folderName,
  ];

  respond(201, [
    'ok' => true,
    'userId' => $userId,
    'folderName' => $folderName,
    'createdAt' => $createdAt,
    'userDir' => 'storage/accounts/11co2/users/' . $folderName,
  ]);
} catch (Throwable $e) {
  // rollback si besoin
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }

  // Note: on ne supprime pas automatiquement les dossiers créés
  // pour éviter d'effacer des données si une insertion ultérieure échoue.

  respond(500, [
    'ok' => false,
    'error' => 'server_error',
    'message' => $e->getMessage(),
  ]);
}
