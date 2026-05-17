<?php
declare(strict_types=1);
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_data.php';

$me = edc_require_auth(['admin']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function normalize_ws(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return $s ?? '';
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

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf'];

$id = (string)($_GET['id'] ?? '');
$id = trim($id);
if ($id === '') {
  http_response_code(400);
  echo 'missing id';
  exit;
}

$msgType = '';
$msg = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $postCsrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($csrf, $postCsrf)) {
    http_response_code(403);
    echo 'csrf';
    exit;
  }

  $action = (string)($_POST['action'] ?? '');

  try {
    $userRow = edc_find_user($id);
    if (!$userRow || (string)($userRow['role'] ?? '') !== 'student') {
      http_response_code(404);
      echo 'not found';
      exit;
    }

    if ($action === 'upload_profile') {
      $folder = (string)($userRow['folder_name'] ?? '');
      if ($folder === '' || str_contains($folder, '..') || str_contains($folder, '/') || str_contains($folder, '\\')) {
        throw new RuntimeException('Dossier utilisateur invalide.');
      }
      if (!isset($_FILES['profile'])) {
        throw new RuntimeException('Fichier manquant.');
      }
      $f = $_FILES['profile'];
      if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload échoué.');
      }
      $tmp = (string)($f['tmp_name'] ?? '');
      $size = (int)($f['size'] ?? 0);
      if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Fichier invalide.');
      }
      if ($size <= 0 || $size > 2 * 1024 * 1024) {
        throw new RuntimeException('Taille max: 2MB');
      }

      $isPng = false;
      if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? (string)finfo_file($fi, $tmp) : '';
        if ($fi) finfo_close($fi);
        if ($mime === 'image/png') {
          $isPng = true;
        } elseif ($mime !== '') {
          $isPng = false;
        }
      }
      if (!$isPng) {
        $sig = @file_get_contents($tmp, false, null, 0, 8);
        if ($sig === "\x89PNG\r\n\x1a\n") {
          $isPng = true;
        }
      }
      if (!$isPng) {
        throw new RuntimeException('Format accepté: PNG uniquement');
      }

      $dest = edc_users_dir() . '/' . $folder . '/profil.png';
      if (!@move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException("Impossible d'enregistrer l'image");
      }
      @chmod($dest, 0644);
      header('Location: ./user.php?id=' . rawurlencode($id) . '&img=1');
      exit;
    }

    if ($action === 'save_profile') {
      $nom = normalize_ws((string)($_POST['nom'] ?? ''));
      $prenom = normalize_ws((string)($_POST['prenom'] ?? ''));
      $email = normalize_ws((string)($_POST['email'] ?? ''));

      if ($nom === '' || $prenom === '' || $email === '') {
        throw new RuntimeException('Champs manquants.');
      }
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Email invalide.');
      }

      $allowedDomains = parse_allowed_domains();
      if (count($allowedDomains) > 0) {
        $domain = email_domain($email);
        if ($domain === '' || !in_array($domain, $allowedDomains, true)) {
          throw new RuntimeException('Domaine email interdit.');
        }
      }

      edc_update_user_profile($id, $nom, $prenom, $email);

      $folder = (string)($userRow['folder_name'] ?? '');
      edc_sync_user_files($folder, [
        'id' => $id,
        'nom' => $nom,
        'prenom' => $prenom,
        'email' => $email,
        'role' => 'student',
        'created_at' => (string)($userRow['created_at'] ?? ''),
      ]);

      header('Location: ./user.php?id=' . rawurlencode($id) . '&ok=1');
      exit;
    }

    if ($action === 'save_password') {
      $newPassword = (string)($_POST['new_password'] ?? '');
      if (trim($newPassword) === '') {
        throw new RuntimeException('Mot de passe vide.');
      }
      $hash = password_hash($newPassword, PASSWORD_DEFAULT);
      if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('password_hash_failed');
      }
      edc_update_user_password($id, $hash);

      header('Location: ./user.php?id=' . rawurlencode($id) . '&pwd=1');
      exit;
    }

    if ($action === 'delete_user') {
      $confirm = (string)($_POST['confirm'] ?? '');
      if ($confirm !== 'DELETE') {
        throw new RuntimeException('Tapez DELETE pour confirmer.');
      }
      $folder = (string)($userRow['folder_name'] ?? '');
      edc_delete_user($id, $folder);
      header('Location: ./liste.php?deleted=1');
      exit;
    }

    throw new RuntimeException('Action inconnue.');
  } catch (PDOException $e) {
    // Cas classique: email unique
    $msgType = 'err';
    $msg = 'Erreur DB: ' . $e->getMessage();
  } catch (Throwable $e) {
    $msgType = 'err';
    $msg = $e->getMessage();
  }
}

$student = edc_find_user($id);
if (!$student || (string)($student['role'] ?? '') !== 'student') {
  http_response_code(404);
  echo 'not found';
  exit;
}

$folder = (string)($student['folder_name'] ?? '');
$lastPing = edc_last_ping_text($folder);
$latest = $folder !== '' ? edc_latest_presence($folder) : null;
$latestId = (int)(is_array($latest) ? ($latest['id'] ?? 0) : 0);

if (isset($_GET['ok'])) {
  $msgType = 'ok';
  $msg = 'Profil mis à jour.';
}
if (isset($_GET['pwd'])) {
  $msgType = 'ok';
  $msg = 'Mot de passe mis à jour.';
}
if (isset($_GET['img'])) {
  $msgType = 'ok';
  $msg = 'Image de profil mise à jour.';
}

$initials = '';
$prenom = (string)($student['prenom'] ?? '');
$nom = (string)($student['nom'] ?? '');
if (preg_match('/^\p{L}/u', $prenom, $m1)) $initials .= $m1[0];
if (preg_match('/^\p{L}/u', $nom, $m2)) $initials .= $m2[0];

$hasImg = false;
$imgUrl = '';
if ($folder !== '' && !str_contains($folder, '..') && !str_contains($folder, '/') && !str_contains($folder, '\\')) {
  $imgFs = edc_users_dir() . '/' . $folder . '/profil.png';
  if (is_file($imgFs) && filesize($imgFs) > 0) {
    $hasImg = true;
    $v = (int)@filemtime($imgFs);
    $imgUrl = '/storage/accounts/11co2/users/' . rawurlencode($folder) . '/profil.png?v=' . $v;
  }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Panel — Admin — Élève</title>
  <link rel="stylesheet" href="/frontend/style.css" />
</head>
<body>
  <div class="container" style="padding-top: 18px;">
    <div class="card">
      <h1 class="page-title">Gérer un élève</h1>
      <p class="page-subtitle">Admin: <?= h((string)($me['prenom'] ?? '')) ?> <?= h((string)($me['nom'] ?? '')) ?></p>
      <hr />

      <div class="todo">
        <div class="row" style="justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
          <div>Dernier ping: <b><?= h($lastPing) ?></b></div>
          <div class="row" style="gap: 8px;">
            <a class="btn" href="./presences.php?u=<?= rawurlencode($folder) ?>">Historique</a>
            <?php if ($latestId > 0): ?>
              <a class="btn secondary" href="./presence.php?u=<?= rawurlencode($folder) ?>&id=<?= (int)$latestId ?>">Détail</a>
            <?php else: ?>
              <span class="btn secondary" style="opacity: 0.55; pointer-events: none;">Détail</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="spacer"></div>

      <div class="kv">
        <div class="k">ID</div><div><?= h((string)($student['id'] ?? '')) ?></div>
        <div class="k">Créé le</div><div><?= h(edc_format_datetime_string((string)($student['created_at'] ?? ''))) ?></div>
        <div class="k">Dossier</div><div><?= h($folder) ?></div>
      </div>

      <?php if ($msg !== ''): ?>
        <div class="msg <?= h($msgType) ?>" style="margin-top: 14px;"><?= h($msg) ?></div>
      <?php endif; ?>

      <div class="spacer"></div>

      <div class="grid">
        <section class="card">
          <h2>Photo de profil</h2>
          <div class="row" style="gap: 12px; align-items: center;">
            <?php if ($hasImg): ?>
              <img src="<?= h($imgUrl) ?>" alt="Profil" style="width: 72px; height: 72px; border-radius: 18px; object-fit: cover; border: 1px solid var(--border);" />
            <?php else: ?>
              <div style="width: 72px; height: 72px; border-radius: 18px; border: 1px solid var(--border); background: rgba(0,0,0,0.18); display: grid; place-items: center; color: var(--muted); font-size: 14px;">
                <?= h($initials !== '' ? $initials : '—') ?>
              </div>
            <?php endif; ?>

            <form method="post" action="" enctype="multipart/form-data" class="row" style="gap: 10px; align-items: center;">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
              <input type="hidden" name="action" value="upload_profile" />
              <input type="file" name="profile" accept="image/png" required />
              <button class="btn primary" type="submit">Uploader (PNG)</button>
            </form>
          </div>
          <p class="small" style="margin-top: 10px;">Fichier: <code>profil.png</code> dans le dossier élève.</p>
        </section>

        <section class="card">
          <h2>Profil</h2>
          <form method="post" action="">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
            <input type="hidden" name="action" value="save_profile" />

            <div class="row">
              <div class="field">
                <label>Nom</label>
                <input type="text" name="nom" value="<?= h((string)($student['nom'] ?? '')) ?>" />
              </div>
              <div class="field">
                <label>Prénom</label>
                <input type="text" name="prenom" value="<?= h((string)($student['prenom'] ?? '')) ?>" />
              </div>
            </div>

            <div class="row mt-10">
              <div class="field">
                <label>Email</label>
                <input type="email" name="email" autocomplete="email" value="<?= h((string)($student['email'] ?? '')) ?>" />
              </div>
            </div>

            <div class="row mt-10">
              <button class="btn primary" type="submit">Enregistrer</button>
            </div>
          </form>
        </section>

        <section class="card">
          <h2>Mot de passe</h2>
          <form method="post" action="">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
            <input type="hidden" name="action" value="save_password" />

            <div class="row">
              <div class="field">
                <label>Nouveau mot de passe</label>
                <input type="text" name="new_password" placeholder="Nouveau mot de passe" />
              </div>
            </div>
            <div class="row mt-10">
              <button class="btn primary" type="submit">Mettre à jour</button>
            </div>
          </form>
        </section>

        <section class="card">
          <h2>Supprimer</h2>
          <p class="small">Action irréversible: supprime le compte et le dossier utilisateur.</p>
          <form method="post" action="" onsubmit="return confirm('Supprimer définitivement cet élève ?');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
            <input type="hidden" name="action" value="delete_user" />
            <div class="row">
              <div class="field">
                <label>Confirmer (tapez DELETE)</label>
                <input type="text" name="confirm" placeholder="DELETE" />
              </div>
            </div>
            <div class="row mt-10">
              <button class="btn" style="border-color: rgba(255,107,107,0.6);" type="submit">Supprimer</button>
            </div>
          </form>
        </section>
      </div>

      <div class="spacer"></div>
      <div class="row">
        <a class="btn" href="./liste.php">← Retour liste</a>
        <a class="btn" href="./index.php">Retour panel</a>
      </div>

      <div class="spacer"></div>
      <a class="small" href="/frontend/">← retour accueil</a>
    </div>
  </div>
</body>
</html>
