<?php
declare(strict_types=1);

function edc_root_path(): string {
  return realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
}

function edc_users_db_path(): string {
  return edc_root_path() . '/storage/accounts/11co2/users.db';
}

function edc_users_dir(): string {
  return edc_root_path() . '/storage/accounts/11co2/users';
}

function edc_pdo(): PDO {
  $dbPath = edc_users_db_path();
  $pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec("PRAGMA busy_timeout = 5000");

  // assure schéma
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
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_folder ON users(folder_name)");

  return $pdo;
}

function edc_last_ping_ts(string $folderName): ?int {
  // Prototype: on considère que le dernier ping = dernier fichier dans loc/ (mtime)
  // Si pas de fichier, retourne null.
  if ($folderName === '' || str_contains($folderName, '..') || str_contains($folderName, '/') || str_contains($folderName, '\\')) {
    return null;
  }

  $locDir = edc_users_dir() . '/' . $folderName . '/loc';
  if (!is_dir($locDir)) return null;

  $items = @scandir($locDir);
  if (!is_array($items)) return null;

  $max = null;
  foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $locDir . '/' . $item;
    if (!is_file($path)) continue;
    $t = @filemtime($path);
    if ($t === false) continue;
    if ($max === null || $t > $max) $max = $t;
  }
  return $max;
}

function edc_last_ping_text(string $folderName): string {
  $ts = edc_last_ping_ts($folderName);
  if ($ts === null) return '—';
  return date('Y-m-d H:i', $ts);
}

function edc_fetch_students(string $order): array {
  $pdo = edc_pdo();
  $stmt = $pdo->prepare('SELECT id, nom, prenom, email, role, created_at, folder_name FROM users WHERE role = :role');
  $stmt->execute([':role' => 'student']);
  $rows = $stmt->fetchAll();
  if (!is_array($rows)) $rows = [];

  // ajoute lastPing pour trier/afficher
  foreach ($rows as &$r) {
    $folder = (string)($r['folder_name'] ?? '');
    $r['last_ping_ts'] = edc_last_ping_ts($folder);
    $r['last_ping_text'] = edc_last_ping_text($folder);
  }
  unset($r);

  $order = strtolower(trim($order));
  if ($order === 'ping_recent') {
    usort($rows, function ($a, $b) {
      $ta = $a['last_ping_ts'] ?? null;
      $tb = $b['last_ping_ts'] ?? null;
      $ta = is_int($ta) ? $ta : -1;
      $tb = is_int($tb) ? $tb : -1;
      return $tb <=> $ta;
    });
  } elseif ($order === 'ping_old') {
    usort($rows, function ($a, $b) {
      $ta = $a['last_ping_ts'] ?? null;
      $tb = $b['last_ping_ts'] ?? null;
      $ta = is_int($ta) ? $ta : PHP_INT_MAX;
      $tb = is_int($tb) ? $tb : PHP_INT_MAX;
      return $ta <=> $tb;
    });
  } else {
    // alpha par défaut
    usort($rows, function ($a, $b) {
      $sa = ((string)($a['nom'] ?? '')) . ' ' . ((string)($a['prenom'] ?? ''));
      $sb = ((string)($b['nom'] ?? '')) . ' ' . ((string)($b['prenom'] ?? ''));
      $na = function_exists('mb_strtolower') ? mb_strtolower($sa, 'UTF-8') : strtolower($sa);
      $nb = function_exists('mb_strtolower') ? mb_strtolower($sb, 'UTF-8') : strtolower($sb);
      return $na <=> $nb;
    });
  }

  return $rows;
}

function edc_find_user(string $id): ?array {
  $pdo = edc_pdo();
  $stmt = $pdo->prepare('SELECT id, nom, prenom, email, role, created_at, folder_name FROM users WHERE id = :id LIMIT 1');
  $stmt->execute([':id' => $id]);
  $row = $stmt->fetch();
  return is_array($row) ? $row : null;
}

function edc_find_user_by_folder(string $folderName): ?array {
  if ($folderName === '' || str_contains($folderName, '..') || str_contains($folderName, '/') || str_contains($folderName, '\\')) {
    return null;
  }
  $pdo = edc_pdo();
  $stmt = $pdo->prepare('SELECT id, nom, prenom, email, role, created_at, folder_name FROM users WHERE folder_name = :f LIMIT 1');
  $stmt->execute([':f' => $folderName]);
  $row = $stmt->fetch();
  return is_array($row) ? $row : null;
}

function edc_update_user_profile(string $id, string $nom, string $prenom, string $email): void {
  $pdo = edc_pdo();
  $stmt = $pdo->prepare('UPDATE users SET nom = :nom, prenom = :prenom, email = :email WHERE id = :id');
  $stmt->execute([':nom' => $nom, ':prenom' => $prenom, ':email' => $email, ':id' => $id]);
}

function edc_update_user_password(string $id, string $passwordHash): void {
  $pdo = edc_pdo();
  $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
  $stmt->execute([':hash' => $passwordHash, ':id' => $id]);
}

function edc_sync_user_files(string $folderName, array $data): void {
  // Met à jour user.json + acc/account.db
  if ($folderName === '' || str_contains($folderName, '..') || str_contains($folderName, '/') || str_contains($folderName, '\\')) {
    return;
  }

  $userDir = edc_users_dir() . '/' . $folderName;
  if (!is_dir($userDir)) return;

  $jsonPath = $userDir . '/user.json';
  if (is_file($jsonPath)) {
    $existing = json_decode((string)file_get_contents($jsonPath), true);
    if (is_array($existing)) {
      $existing['nom'] = $data['nom'] ?? ($existing['nom'] ?? '');
      $existing['prenom'] = $data['prenom'] ?? ($existing['prenom'] ?? '');
      $existing['email'] = $data['email'] ?? ($existing['email'] ?? '');
      if (isset($data['role'])) $existing['role'] = $data['role'];
      file_put_contents($jsonPath, json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
  }

  $accDb = $userDir . '/acc/account.db';
  if (is_file($accDb)) {
    $pdo = new PDO('sqlite:' . $accDb, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("PRAGMA busy_timeout = 5000");
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS profile (" .
      "user_id TEXT PRIMARY KEY, " .
      "nom TEXT NOT NULL, " .
      "prenom TEXT NOT NULL, " .
      "email TEXT NOT NULL, " .
      "role TEXT NOT NULL, " .
      "created_at TEXT NOT NULL, " .
      "extra_json TEXT" .
      ")"
    );

    $createdAt = (string)($data['created_at'] ?? $data['createdAt'] ?? '');
    if ($createdAt === '') $createdAt = gmdate('c');

    $stmt = $pdo->prepare(
      'INSERT INTO profile (user_id, nom, prenom, email, role, created_at, extra_json) '
      . 'VALUES (:id, :nom, :prenom, :email, :role, :created_at, :extra_json) '
      . 'ON CONFLICT(user_id) DO UPDATE SET '
      . 'nom = excluded.nom, prenom = excluded.prenom, email = excluded.email, role = excluded.role'
    );
    $stmt->execute([
      ':id' => (string)($data['id'] ?? ''),
      ':nom' => (string)($data['nom'] ?? ''),
      ':prenom' => (string)($data['prenom'] ?? ''),
      ':email' => (string)($data['email'] ?? ''),
      ':role' => (string)($data['role'] ?? 'student'),
      ':created_at' => $createdAt,
      ':extra_json' => json_encode(new stdClass(), JSON_UNESCAPED_UNICODE),
    ]);
  }
}

function edc_rrmdir(string $dir): void {
  if (!is_dir($dir)) return;
  $items = scandir($dir);
  if (!is_array($items)) return;
  foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $dir . DIRECTORY_SEPARATOR . $item;
    if (is_dir($path) && !is_link($path)) {
      edc_rrmdir($path);
    } else {
      @unlink($path);
    }
  }
  @rmdir($dir);
}

function edc_delete_user(string $id, string $folderName): void {
  $pdo = edc_pdo();
  $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
  $stmt->execute([':id' => $id]);

  if ($folderName !== '' && !str_contains($folderName, '..') && !str_contains($folderName, '/') && !str_contains($folderName, '\\')) {
    $userDir = edc_users_dir() . '/' . $folderName;
    edc_rrmdir($userDir);
  }
}

function edc_presence_db_path(string $folderName): string {
  return edc_users_dir() . '/' . $folderName . '/presence.db';
}

function edc_presence_has_db(string $folderName): bool {
  $p = edc_presence_db_path($folderName);
  return is_file($p) && filesize($p) > 0;
}

function edc_fetch_presences(string $folderName, int $limit = 300): array {
  if ($folderName === '' || str_contains($folderName, '..') || str_contains($folderName, '/') || str_contains($folderName, '\\')) return [];
  $dbPath = edc_presence_db_path($folderName);
  if (!is_file($dbPath) || filesize($dbPath) === 0) return [];
  $pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec('PRAGMA busy_timeout = 5000');
  $pdo->exec(
    'CREATE TABLE IF NOT EXISTS presences (' .
    'id INTEGER PRIMARY KEY AUTOINCREMENT,' .
    'captured_at TEXT NOT NULL,' .
    'user_id TEXT,' .
    'nom TEXT,' .
    'prenom TEXT,' .
    'lat REAL NOT NULL,' .
    'lng REAL NOT NULL,' .
    'accuracy REAL,' .
    'note TEXT,' .
    'photo_path TEXT NOT NULL,' .
    'loc_path TEXT NOT NULL' .
    ')'
  );
  $limit = max(1, min(2000, $limit));
  $stmt = $pdo->prepare('SELECT * FROM presences ORDER BY captured_at DESC, id DESC LIMIT :lim');
  $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll() ?: [];
}

function edc_find_presence(string $folderName, int $presenceId): ?array {
  if ($presenceId <= 0) return null;
  if ($folderName === '' || str_contains($folderName, '..') || str_contains($folderName, '/') || str_contains($folderName, '\\')) return null;
  $dbPath = edc_presence_db_path($folderName);
  if (!is_file($dbPath) || filesize($dbPath) === 0) return null;
  $pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec('PRAGMA busy_timeout = 5000');
  $stmt = $pdo->prepare('SELECT * FROM presences WHERE id = :id');
  $stmt->execute([':id' => $presenceId]);
  $row = $stmt->fetch();
  return is_array($row) ? $row : null;
}

function edc_latest_presence(string $folderName): ?array {
  $rows = edc_fetch_presences($folderName, 1);
  if (count($rows) === 0) return null;
  return $rows[0];
}
