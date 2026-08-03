# FINDING B2 (P2) — /admin/items pollué par des fixtures de test (DB dev)

## Constat (capture r3-b2-items.png)
Le catalogue admin affiche des lignes lorem-ipsum/faker (« qui iusto », cat « Eligendi/Minima »)
et des artefacts de test : **E2E-CAT-*, E2E-ADMIN-*, wval*, CENTRAL-CAT-VIS, E2E-AUDIT**.
DB dev : 38 catégories dont ~25 test-préfixées, 109 items dont 12 test-préfixés (E2E-ADMIN, E2E-AUDIT).

## Sévérité = P2 (gérant-only, DB dev) — PAS un bloqueur client
**Preuve no-leak (lecture seule)** : `KioskMenuService::build(branch 1)` = ce que la BORNE reçoit
→ **9 catégories propres** (Boissons, Menu enfant, Frites, Desserts, Bols, Tacos, Burgers, Galette,
Sandwichs) + **42 items**, **0 préfixe test** (`MENU_LEAK_testprefix=0`). Confirmé aussi visuellement
R1/R2 (borne/caisse/web tous propres). Le débris n'apparaît QUE dans la vue admin non-filtrée.
Origine = mes propres runs e2e sur la DB dev locale ; la DB prod VPS est seedée séparément.

## Remédiation (action OWNER — destructive, refusée en auto-mode à raison)
Commande dédiée NF525-safe (garde `whereNotNull('fiscal_sequence_no')` + tables immuables, l.195-203) :
```
php artisan foodking:cleanup-test-fixtures --prefix=E2E    --dry-run   # vérifier
php artisan foodking:cleanup-test-fixtures --prefix=E2E    --apply --confirm=<token>
# répéter pour: wval, CENTRAL-CAT, PW-, RED-TEAM, AUDIT-, ZZ-TEST
```
Dry-run E2E : orders 8, order_items 9, domain_events 16, items 8, item_categories 7 (fiscalisées protégées).
→ Je n'ai PAS exécuté la suppression (owner gate ; irréversible ; hors périmètre « audit visuel »).
