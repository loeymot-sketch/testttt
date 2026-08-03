# Audit synchronisation caisse / borne → KDS

**Rapport unique consolidé (V2)** : lire en priorité  
[`RAPPORT_AUDIT_SYNC_COMPLET_CONSOLIDE.md`](./RAPPORT_AUDIT_SYNC_COMPLET_CONSOLIDE.md)  
(captures dans `./screenshots/`, données backend dans `./raw-trace.json`, empreintes dans **`./MANIFEST.json`**).

L’ancien fichier `RAPPORT_PARCOURS_CAISSE_KDS_BORNE_KDS.md` (génération précédente) n’est plus alimenté par le spec Playwright actuel.

**Regénérer** :

```bash
php artisan serve --host=127.0.0.1 --port=8000
PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/audit-max-sync-order-journey-documentation.spec.js --project=chromium
```
