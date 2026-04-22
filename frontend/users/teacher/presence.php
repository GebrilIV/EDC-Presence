<?php
// Teacher: détail d'un ping (présence)

declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_data.php';

$me = edc_require_auth(['teacher']);

$folder = (string)($_GET['u'] ?? '');
$id = (int)($_GET['id'] ?? 0);

if ($folder === '' || $id <= 0) {
	header('Location: ./liste.php');
	exit;
}

$user = edc_find_user_by_folder($folder);
if (!$user) {
	http_response_code(404);
	echo 'Utilisateur introuvable';
	exit;
}

$row = edc_find_presence($folder, $id);
if (!$row) {
	http_response_code(404);
	echo 'Ping introuvable';
	exit;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$photoRel = (string)($row['photo_path'] ?? '');
$locRel = (string)($row['loc_path'] ?? '');
$locationMissing = ((int)($row['location_missing'] ?? 0) === 1) || $locRel === '';

$photoUrl = '';
if ($photoRel !== '') {
	if (str_starts_with($photoRel, '/')) {
		$photoUrl = $photoRel;
	} else {
		$photoUrl = '/storage/accounts/11co2/users/' . rawurlencode($folder) . '/' . ltrim($photoRel, '/');
	}
}

$geojson = null;
if ($locRel !== '') {
	$locAbs = '';
	if (str_starts_with($locRel, '/')) {
		$locAbs = edc_root_path() . $locRel;
	} else {
		$locAbs = edc_users_dir() . '/' . $folder . '/' . ltrim($locRel, '/');
	}
	if ($locAbs !== '' && is_file($locAbs)) {
		$raw = @file_get_contents($locAbs);
		if ($raw !== false) {
			$geojson = $raw;
		}
	}
}

?><!doctype html>
<html lang="fr">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Détail ping — <?= h(($user['nom'] ?? '') . ' ' . ($user['prenom'] ?? '')) ?></title>
	<link rel="stylesheet" href="../../style.css" />
	<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
	<style>
		.grid { display: grid; gap: 12px; }
		.map { height: 320px; border-radius: 14px; overflow: hidden; border: 1px solid rgba(255,255,255,0.12); }
		.preview { width: 100%; max-height: 480px; object-fit: cover; border-radius: 14px; border: 1px solid rgba(255,255,255,0.12); }
		.kv { display: grid; grid-template-columns: 160px 1fr; gap: 8px; }
		.k { opacity: 0.75; }
	</style>
</head>
<body>
	<div class="container">
		<div class="card">
			<h2>Détail du ping</h2>
			<div class="kv">
				<div class="k">Élève</div><div><?= h(($user['nom'] ?? '') . ' ' . ($user['prenom'] ?? '')) ?></div>
				<div class="k">Date</div><div><?= h((string)($row['captured_at'] ?? '')) ?></div>
				<div class="k">Localisation</div><div><?= $locationMissing ? 'Absente' : 'Fourni' ?></div>
				<div class="k">Précision</div><div><?= ($row['accuracy'] !== null && $row['accuracy'] !== '') ? h((string)$row['accuracy']) . ' m' : '—' ?></div>
				<div class="k">Note</div><div><?= h((string)($row['note'] ?? '')) ?></div>
			</div>

			<div style="margin-top: 10px; display:flex; gap: 8px; flex-wrap: wrap;">
				<a class="btn" href="./presences.php?u=<?= urlencode($folder) ?>">Historique</a>
				<a class="btn" href="./user.php?id=<?= urlencode((string)($user['id'] ?? '')) ?>">Fiche</a>
				<a class="btn" href="./liste.php">Liste</a>
			</div>
		</div>

		<div class="grid" style="margin-top: 12px;">
			<div class="card">
				<h3>Carte</h3>
				<div class="map" id="map"></div>
			</div>

			<div class="card">
				<h3>Photo</h3>
				<?php if ($photoUrl): ?>
					<img class="preview" src="<?= h($photoUrl) ?>" alt="photo ping" />
				<?php else: ?>
					<div>Pas de photo.</div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
	<script>
		const geojsonText = <?= $geojson ? json_encode($geojson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null' ?>;

		const map = L.map('map', { zoomControl: true });
		L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; OpenStreetMap',
		}).addTo(map);

		if (geojsonText) {
			try {
				const gj = JSON.parse(geojsonText);
				const layer = L.geoJSON(gj).addTo(map);
				const b = layer.getBounds();
				if (b.isValid()) map.fitBounds(b.pad(0.5));
				else map.setView([0,0], 2);
			} catch (e) {
				map.setView([0,0], 2);
			}
		} else {
			map.setView([0,0], 2);
		}
	</script>
</body>
</html>
