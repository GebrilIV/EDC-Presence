
# EDC - Présence Europa-Park

## Objectif

Contrôler la **présence des élèves à distance** (sans contrôle physique) pendant la sortie scolaire de fin d’année à **Europa-Park**.

## Principe

Chaque élève peut valider sa présence en envoyant :
- sa **géolocalisation**
- un **selfie (photo)**
- ...

L’enseignant peut ensuite consulter ces informations pour vérifier que tout le monde va bien.

## Démarrage

Le projet doit être servi par un serveur PHP pour que les pages `frontend/users/...` et l’envoi de présence fonctionnent correctement.

Option simple :
```bash
cd /home/gebril/Bureau/EDC-26/V1
./start.sh
```

Puis ouvre `http://localhost:5173/frontend/`.
