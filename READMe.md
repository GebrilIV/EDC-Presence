



















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

Option simple:
```bash
cd frontend
python3 -m http.server 5173
```

Puis ouvre `http://localhost:5173`.

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


