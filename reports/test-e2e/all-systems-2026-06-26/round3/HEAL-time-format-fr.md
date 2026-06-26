# HEAL time-format FR (superviseur, découvert via analyse API CENTRAL)

## Finding [P3] — heure affichée en 12h anglais « PM » (viole mandat FR ADR-007)
- Symptôme : `/api/admin/sales-report` order_datetime = « 08:18 PM, 26-06-2026 » (12h AM/PM anglais) sur le rapport ventes / historique / KDS, vu par le COMMERÇANT.
- Root : `AppLibrary::time/datetime` (app/Libraries/AppLibrary.php:29-43) lit `env('TIME_FORMAT')` = « h:i A » (12h) ALORS QUE la DB `settings.site_time_format` = « H:i » (24h FR). env↔DB dérivés (SiteService:54 propage site_time_format→env à la sauvegarde, mais l'env avait dérivé). De plus `env()` direct = fragile (null après config:cache, même classe que le bug money flatAmountFormat).

## Fix appliqué
1. **Code (committable)** : `AppLibrary::date/time/datetime` — défauts FR-safe `env('DATE_FORMAT') ?: 'd-m-Y'` et `env('TIME_FORMAT') ?: 'H:i'` (miroir du heal money `?? 2` ; protège config:cache→null). N'override PAS un env non-vide.
2. **Live (.env, gitignored)** : `TIME_FORMAT="h:i A"` → `TIME_FORMAT="H:i"` + `config:clear` + restart serveur :8766. **PROUVÉ live** : order_datetime = « 14:21, 26-06-2026 » (24h FR). Backup `.env.bak-timefix-2026-06-26`.
- Owner-note : en déploiement, s'assurer que `.env TIME_FORMAT="H:i"` (ou re-sauver Site settings qui re-propage). Le code FR-safe couvre le cas env-vide.
