# EDC-26 — Site léger de présence (sortie scolaire)

But: un élève envoie **position + photo** pour prouver sa présence pendant une sortie.

## Structure du workspace

- `frontend/` : SPA Vue 3 (CDN) avec UI minimal (inscription + envoi présence)
- `backend/` : API Express (squelette)
- `database/` : schéma SQL cible (optionnel)
- `storage/` : emplacement de stockage (optionnel)

## Démarrage rapide

### 1) Lancer le backend

```bash
cd backend
npm i
npm run dev
```

Option: limiter les inscriptions aux emails de l’école

```bash
ALLOWED_EMAIL_DOMAINS="ecole.fr;lycee.fr" npm run dev
```

### 2) Servir le frontend (important pour la géolocalisation)

La géolocalisation peut être bloquée si tu ouvres `frontend/index.html` en `file://`.

⚠️ Important: le système de comptes + panel utilise des endpoints **PHP** (dans `php/` : `php/create_account.php`, `php/login.php`, etc + pages `frontend/users/.../*.php`).
Donc il faut servir le projet via **PHP** (sinon `python -m http.server` ne pourra pas exécuter les `.php`).

Option simple:
```bash
cd /chemin/vers/EDC-26/V1
php -S 0.0.0.0:5173 -t .
```

Puis ouvre `http://localhost:5173/frontend/`.

## APIs / Technos utilisées (pour mener le projet)

### Côté navigateur (frontend)

- **Geolocation API**: `navigator.geolocation.getCurrentPosition()`
- **File / Media capture** (mobile): `<input type="file" accept="image/*" capture>`
- **Fetch API**: `fetch()` + `FormData` (envoi multipart photo + champs)
- **Storage**: `localStorage` (prototype, pour mémoriser `userId`)

### Côté serveur (backend)

- **HTTP API** (Express)
- Endpoints actuels:
- `GET /api/health`




# D1: 13.02.26 (1h30~)
(22:32 - 00:07~) workspace (architecture), debut page (visuel), plannification rapide.


# D2: 29.03.26 (40min | 14h58>16h30 | 16h43> 17h40 | 20h>)
compte (dossier compte, panel compte, modification du compte, ..)
debut systeme presence


a faire: finalisé css, affichage, fiable, teste



'ok bien,
on va faire des verification. (que verifier on patch apres)
1) verifie que on puisse pas crée deux compte avec meme prenom et nom (prenom et nom car ya des personne avec deux fois meme prenom mais pas le nom).

2) question: quand on envoie un ping (presence) pourquoi ces autant long (plusieur seconde genre 20s) comment modifier ca?

3) ces possible de depuis le panel admin modifier le role d'un student pour le mettre teacher ou admin (car je voudrais bien) une option en plus.

4)'



student@edu.vs.ch

rôle : student
mot de passe : 1234
teacher@edu.vs.ch

rôle : teacher
mot de passe : 1234
admin@edu.vs.ch

rôle : admin
mot de passe : 1234