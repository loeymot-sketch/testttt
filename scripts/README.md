# Scripts de maintenance

Ce dossier contient des scripts PHP one-shot de maintenance de données.

## ⚠️ Règles d'utilisation

- Ces scripts doivent être exécutés **uniquement en ligne de commande** via `php scripts/NOM_SCRIPT.php`
- Ils ne sont **jamais accessibles via navigateur** (ce dossier est hors du `public/`)
- Les exécuter en production requiert validation du lead développeur
- Chaque script est idempotent ou documente son impact

## Scripts disponibles

| Fichier | Description | Statut |
|---------|-------------|--------|
| `EXECUTE_MENU_FIX.php` | Fix menu data | Archivé (sprint 2026-03-11) |
| `RESET_MENU_FRENCH.php` | Reset menu FR | Archivé (sprint 2026-03-11) |
