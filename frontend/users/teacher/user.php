<?php
declare(strict_types=1);
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_data.php';

$me = edc_require_auth(['teacher']);

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf'];

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$id = (string)($_GET['id'] ?? '');
$id = trim($id);
if ($id === '') {
  http_response_code(400);
  echo 'missing id';
  exit;
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

$msgType = '';
$msg = '';
if (isset($_GET['img']) && (string)$_GET['img'] === '1') {
  $msgType = 'ok';
  $msg = 'Image de profil mise à jour.';
} elseif (isset($_GET['err'])) {
  $msgType = 'err';
  $msg = (string)$_GET['err'];
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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $postCsrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($csrf, $postCsrf)) {
    http_response_code(403);
    echo 'csrf';
    exit;
  }
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'upload_profile') {
    if ($folder === '' || str_contains($folder, '..') || str_contains($folder, '/') || str_contains($folder, '\\')) {
      header('Location: ./user.php?id=' . rawurlencode($id) . '&err=' . rawurlencode('Dossier utilisateur invalide'));
      exit;
    }
    if (!isset($_FILES['profile'])) {
      header('Location: ./user.php?id=' . rawurlencode($id) . '&err=' . rawurlencode('Fichier manquant'));
      exit;
    }
    $f = $_FILES['profile'];
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      header('Location: ./user.php?id=' . rawurlencode($id) . '&err=' . rawurlencode('Upload échoué'));
      exit;
    }
    $tmp = (string)($f['tmp_name'] ?? '');
    $size = (int)($f['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
      header('Location: ./user.php?id=' . rawurlencode($id) . '&err=' . rawurlencode('Fichier invalide'));
      exit;
    }
    if ($size <= 0 || $size > 2 * 1024 * 1024) {
      header('Location: ./user.php?id=' . rawurlencode($id) . '&err=' . rawurlencode('Taille max: 2MB'));
      exit;
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
      header('Location: ./user.php?id=' . rawurlencode($id) . '&err=' . rawurlencode('Format accepté: PNG uniquement'));
      exit;
    }

    $dest = edc_users_dir() . '/' . $folder . '/profil.png';
    if (!@move_uploaded_file($tmp, $dest)) {
      header('Location: ./user.php?id=' . rawurlencode($id) . '&err=' . rawurlencode("Impossible d'enregistrer l'image"));
      exit;
    }
    @chmod($dest, 0644);
    header('Location: ./user.php?id=' . rawurlencode($id) . '&img=1');
    exit;
  }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Panel — Teacher — Élève</title>
  <link rel="stylesheet" href="/frontend/style.css" />
</head>
<body>
  <div class="container" style="padding-top: 18px;">
    <div class="card">
      <h1 class="page-title">Élève</h1>
      <p class="page-subtitle">Lecture seule (+ upload photo)</p>
      <hr />

      <?php if ($msg !== ''): ?>
        <div class="msg <?= h($msgType) ?>" style="margin-top: 14px;"><?= h($msg) ?></div>
      <?php endif; ?>

      <div class="row" style="gap: 12px; align-items: center;">
        <?php if ($hasImg): ?>
          <img src="<?= h($imgUrl) ?>" alt="Profil" style="width: 72px; height: 72px; border-radius: 18px; object-fit: cover; border: 1px solid var(--border);" />
        <?php else: ?>
          <div style="width: 72px; height: 72px; border-radius: 18px; border: 1px solid var(--border); background: rgba(0,0,0,0.18); display: grid; place-items: center; color: var(--muted); font-size: 14px;">
            <?= h($initials !== '' ? $initials : '—') ?>
          </div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="row" style="gap: 10px; align-items: center;">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
          <input type="hidden" name="action" value="upload_profile" />
          <input type="file" name="profile" accept="image/png" required />
          <button class="btn" type="submit">Uploader profil (PNG)</button>
        </form>
      </div>

      <div class="kv">
        <div class="k">Nom</div><div><?= h((string)($student['nom'] ?? '')) ?></div>
        <div class="k">Prénom</div><div><?= h((string)($student['prenom'] ?? '')) ?></div>
        <div class="k">Email</div><div><?= h((string)($student['email'] ?? '')) ?></div>
        <div class="k">Créé le</div><div><?= h(edc_format_datetime_string((string)($student['created_at'] ?? ''))) ?></div>
        <div class="k">Dossier</div><div><?= h($folder) ?></div>
        <div class="k">Dernier ping</div>
        <div>
          <div class="row" style="justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
            <div><?= h($lastPing) ?></div>
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
