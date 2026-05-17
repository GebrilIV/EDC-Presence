<?php
declare(strict_types=1);

require_once __DIR__ . '/../users/_auth.php';
require_once __DIR__ . '/../users/_data.php';

$me = edc_require_auth(['student']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function wants_json_response(): bool {
	$accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
	$ajax = (string)($_POST['_ajax'] ?? '');
	if ($ajax === '1') return true;
	return str_contains($accept, 'application/json');
}

function respond_json(int $status, array $payload): void {
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function presence_debug_enabled(): bool {
	if ((string)($_GET['debug'] ?? '') === '1') return true;
	if ((string)($_POST['_debug'] ?? '') === '1') return true;
	return false;
}

function presence_log(string $reqId, string $stage, array $ctx = []): void {
	$root = edc_root_path();
	$logPath = $root . '/storage/accounts/11co2/presence_debug.log';
	$line = [
		't' => date('c'),
		'req' => $reqId,
		'stage' => $stage,
	] + $ctx;
	@file_put_contents($logPath, json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

if (empty($_SESSION['csrf_presence'])) {
	$_SESSION['csrf_presence'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_presence'];

function ensure_dir(string $path): void {
	if (is_dir($path)) return;
	if (!@mkdir($path, 0775, true) && !is_dir($path)) {
		throw new RuntimeException('mkdir_failed');
	}
}

function pdo_sqlite(string $path): PDO {
	$pdo = new PDO('sqlite:' . $path, null, null, [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	]);
	$pdo->exec('PRAGMA busy_timeout = 5000');
	return $pdo;
}

function safe_folder(string $folder): bool {
	if ($folder === '') return false;
	if (str_contains($folder, '..') || str_contains($folder, '/') || str_contains($folder, '\\')) return false;
	return true;
}

$folder = (string)($me['folderName'] ?? $me['folder_name'] ?? '');
$userId = (string)($me['id'] ?? '');
$nom = (string)($me['nom'] ?? '');
$prenom = (string)($me['prenom'] ?? '');

$msgType = '';
$msg = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $json = wants_json_response();
  $reqId = bin2hex(random_bytes(5));
  $dbg = presence_debug_enabled();

  if ($dbg) {
		presence_log($reqId, 'start', [
			'folder' => (string)($folder ?? ''),
			'user_id' => (string)($userId ?? ''),
		]);
	}

	$postCsrf = (string)($_POST['csrf'] ?? '');
	if (!hash_equals($csrf, $postCsrf)) {
		presence_log($reqId, 'csrf_fail', ['folder' => (string)$folder]);
		if ($json) respond_json(403, ['ok' => false, 'error' => 'csrf', 'reqId' => $reqId]);
		http_response_code(403);
		echo 'csrf';
		exit;
	}

	try {
		if (!safe_folder($folder)) {
			throw new RuntimeException('Dossier utilisateur invalide.');
		}

		$root = edc_root_path();
		$userDir = $root . '/storage/accounts/11co2/users/' . $folder;
		$picsDir = $userDir . '/pics';
		$locDir = $userDir . '/loc';

		ensure_dir($picsDir);
		ensure_dir($locDir);

		$note = trim((string)($_POST['note'] ?? ''));
		if (strlen($note) > 500) {
			$note = substr($note, 0, 500);
		}

		$noGeo = ((string)($_POST['no_geo'] ?? '') === '1');
		$lat = (string)($_POST['lat'] ?? '');
		$lng = (string)($_POST['lng'] ?? '');
		$accuracy = (string)($_POST['accuracy'] ?? '');

		if ($noGeo) {
			if ($note === '') {
				$note = 'Pas de géolocalisation fournie.';
			}
			$latF = 0.0;
			$lngF = 0.0;
			$accF = null;
			$locRel = '';
		} else {
			if ($lat === '' || $lng === '') {
				throw new RuntimeException('Localisation manquante (autorisez la localisation précise).');
			}

			$latF = (float)$lat;
			$lngF = (float)$lng;
			$accF = $accuracy !== '' ? (float)$accuracy : null;
			if (!is_finite($latF) || !is_finite($lngF) || $latF < -90 || $latF > 90 || $lngF < -180 || $lngF > 180) {
				throw new RuntimeException('Localisation invalide.');
			}
		}

		if (!isset($_FILES['photo'])) {
			throw new RuntimeException('Photo manquante.');
		}
		$f = $_FILES['photo'];
		if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			throw new RuntimeException('Upload photo échoué.');
		}
		$tmp = (string)($f['tmp_name'] ?? '');
		$size = (int)($f['size'] ?? 0);
		if ($tmp === '' || !is_uploaded_file($tmp)) {
			throw new RuntimeException('Fichier photo invalide.');
		}
		if ($size <= 0 || $size > 6 * 1024 * 1024) {
			throw new RuntimeException('Photo trop lourde (max 6MB).');
		}

		// Timestamp serveur (précis à la seconde)
		$ts = time();
		$stamp = date('Ymd_His', $ts) . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
		$capturedAt = date('c', $ts);

		// Détection mime + extension
		$mime = '';
		if (function_exists('finfo_open')) {
			$fi = finfo_open(FILEINFO_MIME_TYPE);
			$mime = $fi ? (string)finfo_file($fi, $tmp) : '';
			if ($fi) finfo_close($fi);
		}

		$ext = '';
		$finalMime = $mime;

		if ($mime === 'image/png') {
			$ext = 'png';
		} elseif ($mime === 'image/jpeg' || $mime === 'image/jpg') {
			$ext = 'jpg';
			$finalMime = 'image/jpeg';
		} elseif ($mime === 'image/webp') {
			$ext = 'webp';
			$finalMime = 'image/webp';
		} elseif ($mime === 'image/gif') {
			$ext = 'gif';
			$finalMime = 'image/gif';
		} elseif ($mime === 'image/svg+xml') {
			// SVG can contain scripts; disallow
			$ext = '';
		} else {
			// fallback signature checks
			$sig8 = @file_get_contents($tmp, false, null, 0, 8);
			if ($sig8 === "\x89PNG\r\n\x1a\n") {
				$ext = 'png';
				$finalMime = 'image/png';
			} else {
				$sig2 = @file_get_contents($tmp, false, null, 0, 2);
				if ($sig2 === "\xFF\xD8") {
					$ext = 'jpg';
					$finalMime = 'image/jpeg';
				} else {
					$sig6 = @file_get_contents($tmp, false, null, 0, 6);
					if ($sig6 === 'GIF87a' || $sig6 === 'GIF89a') {
						$ext = 'gif';
						$finalMime = 'image/gif';
					} else {
						$riff = @file_get_contents($tmp, false, null, 0, 12);
						if (is_string($riff) && strlen($riff) === 12 && substr($riff, 0, 4) === 'RIFF' && substr($riff, 8, 4) === 'WEBP') {
							$ext = 'webp';
							$finalMime = 'image/webp';
						}
					}
				}
			}
		}

		if ($ext === '') {
			throw new RuntimeException('Format photo non supporté. Utilisez PNG, JPEG, WebP ou GIF.');
		}

		$photoRel = 'pics/' . $stamp . '.' . $ext;
		$photoFs = $userDir . '/' . $photoRel;

		// Optional conversion JPEG -> PNG if GD is available
		if ($ext === 'jpg' && function_exists('imagecreatefromjpeg') && function_exists('imagepng')) {
			$im = @imagecreatefromjpeg($tmp);
			if ($im !== false) {
				$photoRel = 'pics/' . $stamp . '.png';
				$photoFs = $userDir . '/' . $photoRel;
				if (!@imagepng($im, $photoFs)) {
					imagedestroy($im);
					throw new RuntimeException("Impossible d'enregistrer l'image");
				}
				imagedestroy($im);
				$finalMime = 'image/png';
				$ext = 'png';
			} else {
				// fallback: keep jpg
				if (!@move_uploaded_file($tmp, $photoFs)) {
					throw new RuntimeException("Impossible d'enregistrer l'image");
				}
			}
		} elseif ($ext === 'webp' && function_exists('imagecreatefromwebp') && function_exists('imagepng')) {
			// Optional conversion WebP -> PNG if possible
			$im = @imagecreatefromwebp($tmp);
			if ($im !== false) {
				$photoRel = 'pics/' . $stamp . '.png';
				$photoFs = $userDir . '/' . $photoRel;
				if (!@imagepng($im, $photoFs)) {
					imagedestroy($im);
					throw new RuntimeException("Impossible d'enregistrer l'image");
				}
				imagedestroy($im);
				$finalMime = 'image/png';
				$ext = 'png';
			} else {
				if (!@move_uploaded_file($tmp, $photoFs)) {
					throw new RuntimeException("Impossible d'enregistrer l'image");
				}
			}
		} else {
			if (!@move_uploaded_file($tmp, $photoFs)) {
				throw new RuntimeException("Impossible d'enregistrer l'image");
			}
		}
		@chmod($photoFs, 0644);

		// GeoJSON
		$locRel = '';
		if (!$noGeo) {
			$geo = [
				'type' => 'Feature',
				'geometry' => [
					'type' => 'Point',
					'coordinates' => [$lngF, $latF],
				],
				'properties' => [
					'accuracy_m' => $accF,
					'captured_at' => $capturedAt,
					'user_id' => $userId,
					'nom' => $nom,
					'prenom' => $prenom,
				],
			];
			$locRel = 'loc/' . $stamp . '.geojson';
			$locFs = $userDir . '/' . $locRel;
			$geoJson = json_encode($geo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if (!is_string($geoJson) || $geoJson === '' || @file_put_contents($locFs, $geoJson) === false) {
				throw new RuntimeException('Impossible de sauvegarder la localisation.');
			}
			@chmod($locFs, 0644);
		}

		// Presence DB (par utilisateur)
		$presenceDb = $userDir . '/presence.db';
		$pdo = pdo_sqlite($presenceDb);
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
			'loc_path TEXT NOT NULL,' .
			'location_missing INTEGER NOT NULL DEFAULT 0' .
			')'
		);
		$columns = $pdo->query("PRAGMA table_info(presences)")->fetchAll(PDO::FETCH_ASSOC);
		$hasLocationMissing = false;
		foreach ($columns as $column) {
			if (is_array($column) && isset($column['name']) && $column['name'] === 'location_missing') {
				$hasLocationMissing = true;
				break;
			}
		}
		if (!$hasLocationMissing) {
			$pdo->exec('ALTER TABLE presences ADD COLUMN location_missing INTEGER NOT NULL DEFAULT 0');
		}
		$stmt = $pdo->prepare(
			'INSERT INTO presences (captured_at, user_id, nom, prenom, lat, lng, accuracy, note, photo_path, loc_path, location_missing) ' .
			'VALUES (:captured_at, :user_id, :nom, :prenom, :lat, :lng, :accuracy, :note, :photo_path, :loc_path, :location_missing)'
		);
		$stmt->execute([
			':captured_at' => $capturedAt,
			':user_id' => $userId,
			':nom' => $nom,
			':prenom' => $prenom,
			':lat' => $latF,
			':lng' => $lngF,
			':accuracy' => $accF,
			':note' => $note,
			':photo_path' => $photoRel,
			':loc_path' => $locRel,
			':location_missing' => $noGeo ? 1 : 0,
		]);

		if ($dbg) {
			presence_log($reqId, 'ok', [
				'folder' => (string)$folder,
				'captured_at' => (string)$capturedAt,
				'accuracy_m' => $accF,
			]);
		}

		if ($json) {
			respond_json(200, [
				'ok' => true,
				'reqId' => $reqId,
				'captured_at' => $capturedAt,
				'photo_path' => $photoRel,
				'loc_path' => $locRel,
				'accuracy_m' => $accF,
			]);
		}
		header('Location: /frontend/?presence=ok');
		exit;
	} catch (Throwable $e) {
		if ($json) {
			presence_log($reqId, 'error', [
				'folder' => (string)$folder,
				'error' => $e->getMessage(),
			]);
			respond_json(400, ['ok' => false, 'error' => $e->getMessage(), 'reqId' => $reqId]);
		}
		presence_log($reqId, 'error', [
			'folder' => (string)$folder,
			'error' => $e->getMessage(),
		]);
		$msgType = 'err';
		$msg = $e->getMessage() . ' (req ' . $reqId . ')';
	}
}

// (OK message shown only when staying on this page)
if (isset($_GET['ok'])) {
	$msgType = 'ok';
	$msg = 'Présence envoyée.';
}

?>

<!doctype html>
<html lang="fr">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Présence</title>
	<link rel="stylesheet" href="/frontend/style.css" />
	<link
		rel="stylesheet"
		href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
		integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
		crossorigin=""
	/>
	<style>
		.presence-grid { display: grid; gap: 12px; }
		.presence-map { height: 220px; border-radius: 14px; overflow: hidden; border: 1px solid var(--border); }
		.hint { color: var(--muted); font-size: 12px; }
		textarea { width: 100%; min-height: 82px; resize: vertical; }
		.clock { font-variant-numeric: tabular-nums; font-size: 28px; font-weight: 750; letter-spacing: 0.5px; }
		.clock-sub { color: var(--muted); font-size: 12px; }
		.preview { width: 100%; max-height: 260px; object-fit: cover; border-radius: 14px; border: 1px solid var(--border); background: rgba(0,0,0,0.15); }
		.debug { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 12px; white-space: pre-wrap; color: var(--muted); }
	</style>
</head>
<body>
	<div class="container" style="padding-top: 18px;">
		<div class="card">
			<h1 class="page-title">Présence</h1>
			<p class="page-subtitle">Envoi: heure + photo + position si disponible</p>
			<hr />

			<?php if ($msg !== ''): ?>
				<div class="msg <?= h($msgType) ?>"><?= h($msg) ?></div>
				<div class="spacer"></div>
			<?php endif; ?>

			<div class="presence-grid">
				<section class="card">
					<div class="small">Heure exacte</div>
					<div id="clock" class="clock">--:--:--</div>
					<div id="clockSub" class="clock-sub">--</div>
				</section>

				<section class="card" style="display: grid; gap: 10px;">
					<div class="row" style="justify-content: space-between; align-items: baseline;">
						<div>
							<div class="small">Photo + Localisation</div>
							<div class="hint">Autorisation localisation demandée via “LOCA*”. Photo via le bouton photo.</div>
						</div>
						<button id="startBtn" class="btn success" type="button">LOCA*</button>
					</div>				<div style="display:flex; justify-content:flex-end;">
					<label class="small" style="display:inline-flex; align-items:center; gap:8px; opacity:0.75;">
						<input id="noGeo" type="checkbox" name="no_geo" value="1" style="width:14px; height:14px; margin:0;" />
						<span>Pas de géolocalisation</span>
					</label>
				</div>
					<div class="row" style="gap: 10px; align-items: center;">
						<button id="pickBtn" class="btn" type="button">Choisir / Prendre une photo</button>
						<div class="small" id="photoInfo">Aucune photo</div>
					</div>

					<img id="preview" class="preview" alt="Aperçu photo" style="display:none;" />

					<div class="presence-map" id="map"></div>
					<div class="row" style="justify-content: space-between; align-items: baseline;">
						<div class="small">Position</div>
						<div class="small" id="locInfo">Pas de localisation</div>
					</div>
					<details style="margin-top: 6px;">
						<summary class="small">Debug</summary>
						<div id="debugLog" class="debug">(logs)</div>
					</details>
				</section>

				<section class="card">
					<div class="small">Note (optionnel)</div>
					<div class="hint">Écris seulement s’il y a un problème / une info à donner.</div>
					<div class="spacer"></div>
					<textarea id="note" name="note" form="presenceForm" maxlength="500" placeholder="Ex: je suis avec le groupe X, batterie faible, ..."></textarea>
				</section>

				<section class="card">
					<form id="presenceForm" method="post" action="" enctype="multipart/form-data">
						<input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
						<input type="hidden" name="_ajax" id="_ajax" value="0" />
						<input type="hidden" name="lat" id="lat" value="" />
						<input type="hidden" name="lng" id="lng" value="" />
						<input type="hidden" name="accuracy" id="accuracy" value="" />

						<input
							id="photo"
							type="file"
							name="photo"
							accept="image/*"
							capture="environment"
							style="display:none;"
							required
						/>

						<div class="row" style="justify-content: space-between; align-items: center;">
							<button id="sendBtn" class="btn primary" type="submit">Envoyer la présence</button>
							<a class="btn" href="/frontend/">Retour accueil</a>
						</div>
						<div class="hint" style="margin-top: 10px;">Compte: <?= h($prenom . ' ' . $nom) ?></div>
					</form>
				</section>
			</div>
		</div>
	</div>

	<script
		src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
		integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
		crossorigin=""
	></script>
	<script>
		const clockEl = document.getElementById('clock');
		const clockSubEl = document.getElementById('clockSub');
		const startBtn = document.getElementById('startBtn');
		const pickBtn = document.getElementById('pickBtn');
		const photoInput = document.getElementById('photo');
		const previewEl = document.getElementById('preview');
		const photoInfoEl = document.getElementById('photoInfo');
		const locInfoEl = document.getElementById('locInfo');
		const formEl = document.getElementById('presenceForm');
		const sendBtn = document.getElementById('sendBtn');
		const latEl = document.getElementById('lat');
		const lngEl = document.getElementById('lng');
		const accEl = document.getElementById('accuracy');
		const noGeoEl = document.getElementById('noGeo');
		const noteEl = document.getElementById('note');
		const debugEl = document.getElementById('debugLog');
		const ajaxEl = document.getElementById('_ajax');
		if (ajaxEl) ajaxEl.value = '1';
		if (noGeoEl) {
			noGeoEl.addEventListener('change', updateNoGeoDisplay);
			updateNoGeoDisplay();
		}

		const DEBUG = new URLSearchParams(window.location.search).get('debug') === '1';
		function log(...args) {
			try { console.log('[presence]', ...args); } catch {}
			if (!debugEl) return;
			const line = args.map(a => {
				try { return typeof a === 'string' ? a : JSON.stringify(a); } catch { return String(a); }
			}).join(' ');
			debugEl.textContent = (debugEl.textContent === '(logs)' ? '' : debugEl.textContent + '\n') + line;
		}

function tickClock() {
		const d = new Date();
		const options = { timeZone: 'Europe/Zurich', hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' };
		clockEl.textContent = d.toLocaleTimeString('fr-FR', options);
		clockSubEl.textContent = d.toLocaleDateString('fr-FR', {
			timeZone: 'Europe/Zurich',
			weekday: 'long',
			year: 'numeric',
			month: 'long',
			day: 'numeric'
		});
	}
	tickClock();
	setInterval(tickClock, 1000);

		// Leaflet map
		const map = L.map('map', { zoomControl: true });
		const tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; OpenStreetMap',
		});
		tiles.addTo(map);
		const marker = L.marker([48.0, 7.0]);
		marker.addTo(map);
		map.setView([48.0, 7.0], 5);

		let watchId = null;
		let bestPos = null;
		let bestAt = 0;

		function setPosition(pos) {
			const { latitude, longitude, accuracy } = pos.coords;
			const now = Date.now();
			if (accuracy != null && (bestPos == null || accuracy < (bestPos.coords.accuracy ?? Infinity))) {
				bestPos = pos;
				bestAt = now;
				log('best accuracy', Math.round(accuracy) + 'm');
			}
			latEl.value = String(latitude);
			lngEl.value = String(longitude);
			accEl.value = String(accuracy ?? '');
			locInfoEl.textContent =
				'lat ' + latitude.toFixed(6) + ' / lng ' + longitude.toFixed(6) +
				(accuracy ? (' — ±' + Math.round(accuracy) + 'm') : '');
			marker.setLatLng([latitude, longitude]);
			map.setView([latitude, longitude], 17);
		}

		async function requestLocationOnce() {
			if (!('geolocation' in navigator)) {
				throw new Error('Géolocalisation non supportée.');
			}
			return await new Promise((resolve, reject) => {
				navigator.geolocation.getCurrentPosition(resolve, reject, {
					enableHighAccuracy: true,
					timeout: 30000,
					maximumAge: 0,
				});
			});
		}

		async function getBestLocation() {
			try {
				const pos = await requestLocationOnce();
				return pos;
			} catch (e) {
				// fallback: use recent best watch position if available
				const ageMs = Date.now() - bestAt;
				if (bestPos && ageMs < 60000) {
					log('fallback to watchPosition (age ms)', ageMs);
					return bestPos;
				}
				throw e;
			}
		}

		function startWatch() {
			if (!('geolocation' in navigator)) return;
			if (watchId != null) return;
			log('start watchPosition');
			watchId = navigator.geolocation.watchPosition(
				(pos) => { log('watchPosition ok', { acc: pos?.coords?.accuracy, lat: pos?.coords?.latitude, lng: pos?.coords?.longitude }); setPosition(pos); },
				(err) => {
					log('watchPosition error', err);
					locInfoEl.textContent = 'Erreur localisation: ' + (err?.message || err);
				},
				{ enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }
			);
		}

		function updateNoGeoDisplay() {
			if (noGeoEl && noGeoEl.checked) {
				locInfoEl.textContent = 'Aucune géolocalisation demandée.';
				return;
			}
			if (!latEl.value || !lngEl.value) {
				locInfoEl.textContent = 'Pas de localisation';
			}
		}

		function pickPhoto() {
			log('pickPhoto');
			photoInput.click();
		}

		async function toPngFile(file) {
			// Best-effort: convert to PNG client-side (so server can save .png reliably)
			try {
				if (!file || !file.type || !file.type.startsWith('image/')) return null;
				if (!('createImageBitmap' in window)) return null;
				const bmp = await createImageBitmap(file);
				const canvas = document.createElement('canvas');
				canvas.width = bmp.width;
				canvas.height = bmp.height;
				const ctx = canvas.getContext('2d');
				ctx.drawImage(bmp, 0, 0);
				const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
				if (!blob) return null;
				return new File([blob], 'presence.png', { type: 'image/png' });
			} catch (e) {
				log('toPngFile failed', e);
				return null;
			}
		}

		let photoPng = null;

		photoInput.addEventListener('change', () => {
			const f = photoInput.files && photoInput.files[0];
			if (!f) {
				photoInfoEl.textContent = 'Aucune photo';
				photoPng = null;
				previewEl.style.display = 'none';
				previewEl.src = '';
				return;
			}
			log('photo selected', { name: f.name, type: f.type, size: f.size });
			photoInfoEl.textContent = f.name ? f.name : ('photo (' + Math.round((f.size || 0) / 1024) + ' KB)');
			try {
				const url = URL.createObjectURL(f);
				previewEl.src = url;
				previewEl.style.display = 'block';
			} catch {
				previewEl.style.display = 'none';
			}

			// async conversion
			photoPng = null;
			toPngFile(f).then((png) => {
				if (png) {
					photoPng = png;
					log('photo converted to PNG', { size: png.size });
				} else {
					log('photo kept original');
				}
			});
		});

		pickBtn.addEventListener('click', () => {
			pickPhoto();
		});

		startBtn.addEventListener('click', async () => {
			startBtn.disabled = true;
			try {
				log('start clicked');
				if (noGeoEl && noGeoEl.checked) {
					locInfoEl.textContent = 'Pas de géolocalisation demandée.';
					return;
				}
				// Request location permission + start watch
				const pos = await getBestLocation();
				setPosition(pos);
				startWatch();
			} catch (e) {
				log('start error', e);
				locInfoEl.textContent = 'Erreur localisation: ' + (e?.message || e);
			} finally {
				startBtn.disabled = false;
			}
		});

		formEl.addEventListener('submit', async (ev) => {
			ev.preventDefault();
			debugEl && (debugEl.textContent = '(logs)');
			log('submit');
			sendBtn.disabled = true;
			sendBtn.textContent = 'Envoi...';
			try {
				if (!(noGeoEl && noGeoEl.checked)) {
					// refresh location at submit time for best precision
					const pos = await getBestLocation();
					setPosition(pos);
				} else {
					log('no geolocation mode active');
					locInfoEl.textContent = 'Pas de géolocalisation demandée.';
				}
			} catch (e) {
				if (noGeoEl && noGeoEl.checked) {
					locInfoEl.textContent = 'Pas de géolocalisation demandée.';
				} else {
					log('location failed', e);
					sendBtn.disabled = false;
					sendBtn.textContent = 'Envoyer la présence';
					alert('Localisation requise (précise). Erreur: ' + (e?.message || e));
					return;
				}
			}

			const hasPhoto = photoInput.files && photoInput.files.length > 0;
			if (!hasPhoto) {
				log('missing photo');
				sendBtn.disabled = false;
				sendBtn.textContent = 'Envoyer la présence';
				alert('Photo requise.');
				return;
			}
			if (!(noGeoEl && noGeoEl.checked) && (!latEl.value || !lngEl.value)) {
				log('missing lat/lng');
				sendBtn.disabled = false;
				sendBtn.textContent = 'Envoyer la présence';
				alert('Localisation requise.');
				return;
			}

			try {
				if (noGeoEl && noGeoEl.checked) {
					latEl.value = '';
					lngEl.value = '';
					accEl.value = '';
				}
				const fd = new FormData();
				fd.append('csrf', formEl.querySelector('input[name="csrf"]').value);
				fd.append('_ajax', '1');
				fd.append('no_geo', noGeoEl && noGeoEl.checked ? '1' : '0');
				fd.append('lat', latEl.value);
				fd.append('lng', lngEl.value);
				fd.append('accuracy', accEl.value || '');
				fd.append('note', noteEl?.value || '');

				const orig = photoInput.files[0];
				const toSend = photoPng || orig;
				fd.append('photo', toSend, toSend.name || 'presence.png');

				log('sending', { lat: latEl.value, lng: lngEl.value, acc: accEl.value, file: { name: toSend.name, type: toSend.type, size: toSend.size } });
				const res = await fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
				let data = null;
				try { data = await res.json(); } catch { data = null; }
				log('server response', { status: res.status, data });
				if (!res.ok || !data?.ok) {
					throw new Error(data?.error || ('HTTP ' + res.status));
				}

				window.location.href = '/frontend/?presence=ok';
				return;
			} catch (e) {
				log('send failed', e);
				sendBtn.disabled = false;
				sendBtn.textContent = 'Envoyer la présence';
				alert('Envoi échoué: ' + (e?.message || e));
				return;
			}
		});

		// start watch silently after load (no prompt), then user can click Démarrer
		try {
			if ('geolocation' in navigator) {
				log('ready');
			}
		} catch {}
	</script>
</body>
</html>
