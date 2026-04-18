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
| `run_php_feature_batches.sh` | Lance les tests PHP par lots stables (`auth-security`, `kiosk-pos-sync`, `admin-seeders-reports`) | Actif |
| `profile_php_memory.sh` | Produit un rapport markdown de profilage des lots PHP pour contourner le crash mémoire du run monolithique | Actif |

## Validation par lots

```bash
bash scripts/run_php_feature_batches.sh auth-security
bash scripts/run_php_feature_batches.sh kiosk-pos-sync
bash scripts/run_php_feature_batches.sh admin-seeders-reports
bash scripts/run_php_feature_batches.sh all
```

## Profilage mémoire

```bash
bash scripts/profile_php_memory.sh
```

Le rapport est écrit par défaut dans `reports/execution/php_memory_profile_latest.md`.
