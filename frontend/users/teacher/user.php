<?php
declare(strict_types=1);
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_data.php';

$me = edc_require_auth(['teacher']);

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
      <p class="page-subtitle">Lecture seule</p>
      <hr />

      <div class="kv">
        <div class="k">Nom</div><div><?= h((string)($student['nom'] ?? '')) ?></div>
        <div class="k">Prénom</div><div><?= h((string)($student['prenom'] ?? '')) ?></div>
        <div class="k">Email</div><div><?= h((string)($student['email'] ?? '')) ?></div>
        <div class="k">Créé le</div><div><?= h((string)($student['created_at'] ?? '')) ?></div>
        <div class="k">Dossier</div><div><?= h($folder) ?></div>
        <div class="k">Dernier ping</div><div><?= h($lastPing) ?></div>
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
