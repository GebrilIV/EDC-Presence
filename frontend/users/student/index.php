<?php
declare(strict_types=1);
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_data.php';
$user = edc_require_auth(['student']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$folderName = (string)($user['folderName'] ?? $user['folder_name'] ?? '');
$lastPing = edc_last_ping_text($folderName);
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Panel — Élève</title>
  <link rel="stylesheet" href="/frontend/style.css" />
</head>
<body>
  <div class="container" style="padding-top: 18px;">
    <div class="card">
      <h1 class="page-title">Panel élève</h1>
      <p class="page-subtitle">Bonjour <?= h((string)($user['prenom'] ?? '')) ?> <?= h((string)($user['nom'] ?? '')) ?></p>
      <hr />

      <div class="todo">Dernier ping: <b><?= h($lastPing) ?></b></div>

      <div class="row mt-10">
        <button class="btn" type="button" onclick="toggleParams()">Paramètres</button>
      </div>

      <div id="params" class="grid" style="display: none; margin-top: 14px;">
        <section class="card">
          <h2>Changer email</h2>
          <div class="row">
            <div class="field">
              <label>Nouvel email</label>
              <input id="newEmail" type="email" autocomplete="email" placeholder="nouveau@edu.vs.ch" />
            </div>
          </div>
          <div class="row mt-10">
            <button class="btn primary" type="button" onclick="changeEmail()">Valider</button>
          </div>
        </section>

        <section class="card">
          <h2>Changer mot de passe</h2>
          <div class="row">
            <div class="field">
              <label>Nouveau mot de passe</label>
              <input id="newPassword" type="password" autocomplete="new-password" placeholder="Nouveau mot de passe" />
            </div>
          </div>
          <div class="row mt-10">
            <button class="btn primary" type="button" onclick="changePassword()">Valider</button>
          </div>
        </section>

        <section class="card">
          <h2>Déconnexion</h2>
          <p class="small">Quitte la session sur cet appareil.</p>
          <div class="row mt-10">
            <button class="btn" type="button" onclick="doLogout()">Se déconnecter</button>
          </div>
        </section>

        <section class="card">
          <h2>Supprimer le compte</h2>
          <p class="small">Action irréversible (prototype): supprime aussi le dossier utilisateur.</p>
          <div class="row mt-10">
            <button class="btn" style="border-color: rgba(255,107,107,0.6);" type="button" onclick="deleteAccount()">Supprimer</button>
          </div>
        </section>
      </div>

      <div id="msg" class="msg" style="margin-top: 14px;"></div>
      <div class="spacer"></div>
      <a class="small" href="/frontend/">← retour accueil</a>
    </div>
  </div>

<script>
  function toggleParams() {
    const el = document.getElementById('params');
    el.style.display = (el.style.display === 'none' || !el.style.display) ? 'grid' : 'none';
  }

  function setMsg(type, text) {
    const el = document.getElementById('msg');
    el.className = 'msg ' + (type || '');
    el.textContent = text || '';
  }

  async function postJson(url, body) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify(body || {}),
    });
    let data = null;
    try { data = await res.json(); } catch { data = null; }
    return { ok: res.ok, status: res.status, data };
  }

  async function changeEmail() {
    setMsg('', '');
    const newEmail = document.getElementById('newEmail').value;
    const { ok, status, data } = await postJson('/change_email.php', { newEmail });
    if (!ok) return setMsg('err', 'Erreur: ' + (data?.error || ('HTTP ' + status)));
    setMsg('ok', 'Email mis à jour.');
  }

  async function changePassword() {
    setMsg('', '');
    const newPassword = document.getElementById('newPassword').value;
    const { ok, status, data } = await postJson('/change_password.php', { newPassword });
    if (!ok) return setMsg('err', 'Erreur: ' + (data?.error || ('HTTP ' + status)));
    setMsg('ok', 'Mot de passe mis à jour.');
  }

  async function doLogout() {
    setMsg('', '');
    const { ok, status, data } = await postJson('/logout.php', {});
    if (!ok) return setMsg('err', 'Erreur: ' + (data?.error || ('HTTP ' + status)));
    try { localStorage.removeItem('edc.user'); } catch {}
    window.location.href = '/frontend/';
  }

  async function deleteAccount() {
    if (!confirm('Supprimer le compte définitivement ?')) return;
    setMsg('', '');
    const { ok, status, data } = await postJson('/delete_account.php', {});
    if (!ok) return setMsg('err', 'Erreur: ' + (data?.error || ('HTTP ' + status)));
    try { localStorage.removeItem('edc.user'); } catch {}
    window.location.href = '/frontend/';
  }
</script>
</body>
</html>
