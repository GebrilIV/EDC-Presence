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
          ?>
            <div class="todo" style="display: grid; gap: 8px;">
              <div class="row" style="justify-content: space-between; align-items: baseline;">
                <div>
                  <div style="font-weight: 650; color: var(--text);">
                    <?= h($prenom) ?> <?= h($nom) ?>
                  </div>
                  <div class="small"><?= h($email) ?></div>
                </div>
                <a class="btn secondary" href="./user.php?id=<?= rawurlencode($id) ?>">En savoir plus</a>
              </div>
              <div class="small">Dernier ping à <b><?= h($ping) ?></b></div>
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
