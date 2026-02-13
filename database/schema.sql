-- Schéma cible (quand vous passerez d'un stockage fichiers -> SQLite/PostgreSQL)

-- Utilisateurs
CREATE TABLE IF NOT EXISTS users (
  id TEXT PRIMARY KEY,
  email TEXT NOT NULL UNIQUE,
  name TEXT,
  created_at TEXT NOT NULL
);

-- Événements de présence (une entrée = 1 envoi)
CREATE TABLE IF NOT EXISTS presences (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL REFERENCES users(id),
  lat REAL NOT NULL,
  lng REAL NOT NULL,
  accuracy REAL,
  captured_at TEXT,
  saved_at TEXT NOT NULL,
  photo_path TEXT NOT NULL
);

-- Index utiles pour vue prof
CREATE INDEX IF NOT EXISTS idx_presences_user_saved_at ON presences(user_id, saved_at);
