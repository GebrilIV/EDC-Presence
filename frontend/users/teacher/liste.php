<?php
declare(strict_types=1);
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_data.php';

$me = edc_require_auth(['teacher']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$order = strtolower(trim((string)($_GET['order'] ?? 'alpha')));
$allowedOrders = ['alpha', 'ping_recent', 'ping_old'];
if (!in_array($order, $allowedOrders, true)) $order = 'alpha';

$students = edc_fetch_students($order);
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Panel — Teacher — Liste</title>
  <link rel="stylesheet" href="/frontend/style.css" />
</head>
<body>
  <div class="container" style="padding-top: 18px;">
    <div class="card">
      <h1 class="page-title">Liste des élèves</h1>
      <p class="page-subtitle">Teacher: <?= h((string)($me['prenom'] ?? '')) ?> <?= h((string)($me['nom'] ?? '')) ?></p>
      <hr />

      <form class="row" method="get" action="">
        <div class="field" style="min-width: 220px;">
          <label>Tri</label>
          <select name="order" class="btn" style="border-radius: 12px; padding: 10px 12px;">
            <option value="alpha" <?= $order === 'alpha' ? 'selected' : '' ?>>Alphabetique</option>
            <option value="ping_recent" <?= $order === 'ping_recent' ? 'selected' : '' ?>>Dernier ping (récent)</option>
            <option value="ping_old" <?= $order === 'ping_old' ? 'selected' : '' ?>>Dernier ping (ancien)</option>
          </select>
        </div>
        <button class="btn primary" type="submit">Appliquer</button>
        <a class="btn" href="./index.php">Retour panel</a>
      </form>

      <div class="spacer"></div>

      <?php if (count($students) === 0): ?>
        <div class="todo">Aucun élève trouvé.</div>
      <?php else: ?>
        <div style="display: grid; gap: 10px;">
          <?php foreach ($students as $s):
            $id = (string)($s['id'] ?? '');
            $nom = (string)($s['nom'] ?? '');
            $prenom = (string)($s['prenom'] ?? '');
            $email = (string)($s['email'] ?? '');
            $ping = (string)($s['last_ping_text'] ?? '—');
            $folder = (string)($s['folder_name'] ?? '');
            $latest = null;
            $latestId = 0;
            if ($folder !== '') {
              $latest = edc_latest_presence($folder);
              $latestId = (int)(is_array($latest) ? ($latest['id'] ?? 0) : 0);
            }
            $initials = '';
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
            <div class="todo" style="display: grid; gap: 8px;">
              <div class="row" style="justify-content: space-between; align-items: baseline;">
                <div class="row" style="gap: 12px; align-items: center;">
                  <?php if ($hasImg): ?>
                    <img src="<?= h($imgUrl) ?>" alt="Profil" style="width: 44px; height: 44px; border-radius: 12px; object-fit: cover; border: 1px solid var(--border);" />
                  <?php else: ?>
                    <div style="width: 44px; height: 44px; border-radius: 12px; border: 1px solid var(--border); background: rgba(0,0,0,0.18); display: grid; place-items: center; color: var(--muted); font-size: 12px;">
                      <?= h($initials !== '' ? $initials : '—') ?>
                    </div>
                  <?php endif; ?>
                  <div>
                    <div style="font-weight: 650; color: var(--text);">
                      <?= h($prenom) ?> <?= h($nom) ?>
                    </div>
                    <div class="small"><?= h($email) ?></div>
                  </div>
                </div>
                <a class="btn secondary" href="./user.php?id=<?= rawurlencode($id) ?>">En savoir plus</a>
              </div>
              <div class="row" style="justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
                <div class="small">Dernier ping à <b><?= h($ping) ?></b></div>
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
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="spacer"></div>
      <a class="small" href="/frontend/">← retour accueil</a>
    </div>
  </div>
</body>
</html>
