<?php
declare(strict_types=1);

// Switzerland timezone (covers all pages including this file)
@date_default_timezone_set('Europe/Zurich');
@ini_set('date.timezone', 'Europe/Zurich');

function edc_require_auth(array $allowedRoles): array {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
      'httponly' => true,
      'samesite' => 'Lax',
      'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
  }

  $user = $_SESSION['user'] ?? null;
  if (!is_array($user) || !isset($user['id'], $user['role'])) {
    http_response_code(401);
    echo '<!doctype html><html lang="fr"><meta charset="utf-8" />';
    echo '<title>Non connecté</title>';
    echo '<body style="font-family: system-ui; padding: 18px;">';
    echo '<h1>Non connecté</h1>';
    echo '<p>Retour: <a href="/frontend/">connexion</a></p>';
    echo '</body></html>';
    exit;
  }

  $role = (string)$user['role'];
  if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    echo '<!doctype html><html lang="fr"><meta charset="utf-8" />';
    echo '<title>Accès interdit</title>';
    echo '<body style="font-family: system-ui; padding: 18px;">';
    echo '<h1>Accès interdit</h1>';
    echo '<p>Votre rôle: <b>' . htmlspecialchars($role) . '</b></p>';
    echo '<p>Retour: <a href="/frontend/">accueil</a></p>';
    echo '</body></html>';
    exit;
  }

  return $user;
}
