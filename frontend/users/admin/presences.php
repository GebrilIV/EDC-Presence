<?php
// Admin: historique des pings (présences)

declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_data.php';

$me = edc_require_auth(['admin']);

$folder = (string)($_GET['u'] ?? '');
if ($folder === '') {
	header('Location: ./liste.php');
	exit;
}

$user = edc_find_user_by_folder($folder);
if (!$user) {
	http_response_code(404);
	echo 'Utilisateur introuvable';
	exit;
}

$rows = edc_fetch_presences($folder, 500);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

?><!doctype html>
<html lang="fr">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Historique pings — <?= h(($user['nom'] ?? '') . ' ' . ($user['prenom'] ?? '')) ?></title>
	<link rel="stylesheet" href="../../style.css" />
	<style>
		.table { width: 100%; border-collapse: collapse; }
		.table th, .table td { padding: 10px; border-bottom: 1px solid rgba(255,255,255,0.08); vertical-align: top; }
		.small { opacity: 0.8; font-size: 12px; }
		.actions { display: flex; gap: 8px; flex-wrap: wrap; }
		.badge { display: inline-block; padding: 2px 8px; border-radius: 999px; background: rgba(255,255,255,0.08); font-size: 12px; }
	</style>
</head>
<body>
	<div class="container">
		<div class="card">
			<h2>Historique des pings</h2>
			<div class="small"><?= h(($user['nom'] ?? '') . ' ' . ($user['prenom'] ?? '')) ?> — dossier: <span class="badge"><?= h($folder) ?></span></div>
			<div class="actions" style="margin-top: 10px;">
				<a class="btn" href="./user.php?id=<?= urlencode((string)($user['id'] ?? '')) ?>">Retour fiche</a>
				<a class="btn" href="./liste.php">Retour liste</a>
			</div>
		</div>

		<div class="card" style="margin-top: 12px;">
			<?php if (!$rows): ?>
				<div>Aucun ping trouvé.</div>
			<?php else: ?>
				<table class="table">
					<thead>
						<tr>
							<th>Date</th>
							<th>Précision</th>
							<th>Note</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($rows as $r): ?>
							<tr>
								<td>
									<div><?= h((string)($r['captured_at'] ?? '')) ?></div>
									<div class="small">ID #<?= (int)($r['id'] ?? 0) ?></div>
								</td>
								<td>
									<?= ($r['accuracy'] !== null && $r['accuracy'] !== '') ? h((string)$r['accuracy']) . ' m' : '—' ?>
								</td>
								<td>
									<?= h((string)($r['note'] ?? '')) ?>
								</td>
								<td>
									<a class="btn" href="./presence.php?u=<?= urlencode($folder) ?>&id=<?= (int)($r['id'] ?? 0) ?>">Détail</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
</body>
</html>
