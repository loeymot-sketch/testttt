# RED-VERIFIER — CAISSE / Tiroir-caisse · cash · Z

## Finding sous test
[P3] `app/Http/Controllers/Admin/Fiscal/ZReportController.php:53` — Clôture Z sans alerte
sur commandes PENDING_COUNTER en file (386,80€ « invisible à l'opérateur »).

## VERDICT : **REFUTED**

Les `file:line` cités sont tous exacts, MAIS la prémisse de nuisance (« 386,80€ d'argent
non encaissé invisible à l'opérateur ») est **factuellement fausse**. Le défaut n'existe pas :
l'opérateur dispose d'un écran dédié temps-réel qui liste exactement ces commandes, et un cron
5-min empêche leur accumulation en prod. Le Z est fiscalement correct (concédé par la finding).

---

## Preuves de réfutation

### 1. La repro reproduit MAIS l'argent N'EST PAS invisible
```
mysql -u root foodking_e2e -e "SELECT COUNT(*),ROUND(SUM(total),2) FROM orders
  WHERE payment_status=15 AND fiscal_sequence_no IS NULL AND deleted_at IS NULL;"
→ 91 / 386.80   (repro confirmée)
```
Il EXISTE une file d'encaissement dédiée qui surface ces commandes :
- UI : `resources/js/components/admin/encaissement/EncaissementComponent.vue`
  - `fetchPending()` (l.113) → `axios.get('admin/pos/counter-collect/pending')` (l.115)
  - polling 20s (l.99) + Echo live `OrderCreated/OrderPaidAtCounter/OrderStatusChanged` (l.148-152)
- Endpoint : `routes/api.php:800-837` `counter-collect.pending`
  - `where('payment_status', PaymentStatus::PENDING_COUNTER)` (l.812)
  - clause kiosk `source_surface='kiosk' AND order_type IN (KIOSK=25, TAKEAWAY=10)` (l.815-816)
  - clause pos `source_surface='pos' AND pos_payment_method=COUNTER_DEFERRED=6` (l.818-819)
  - **FIFO `orderBy('created_at')` (l.822), cap 200 (l.836)**
  - Commentaire l.829-835 : le cap 50→200 a été relevé *précisément* pour corriger
    « a waiting-to-pay customer became invisible to the cashier ». La régression que la
    finding prétend exister a déjà été traitée à la source.

Combien des 91 cette file affiche réellement :
```
SELECT SUM(CASE WHEN source_surface='kiosk' AND order_type IN (25,10) THEN 1 ELSE 0 END) kiosk,
       SUM(CASE WHEN source_surface='pos' THEN 1 ELSE 0 END) pos, COUNT(*) total ...
→ kiosk=88, pos=2, total=91   (90/91 surfacés ; le 1 restant = source_surface NULL test-row)
```
→ Conclusion : **90 des 91 commandes SONT visibles à l'opérateur** sur un écran bâti pour ça.
« invisible » est faux.

### 2. Les 91 sont un artefact de la DB de test, pas une réalité prod
```
SELECT MIN(TIMESTAMPDIFF(HOUR,created_at,NOW())), MAX(...) ...
→ youngest=52h, oldest=386h   (2,2 à 16 jours ; ZÉRO d'aujourd'hui)
```
Réparties sur 2026-06-10 → 2026-06-23 (sessions dev passées). En prod elles n'existeraient pas :
- `app/Jobs/CleanupStalePendingKioskOrders.php` auto-annule les PENDING_COUNTER kiosk
  abandonnées > TTL (`kiosk.stale_collect_ttl_minutes`, défaut 180 min = 3h ; l.27).
  Cible `status IN [PENDING, ACCEPT] AND payment_status IN [UNPAID, PENDING_COUNTER]
  AND source_surface='kiosk' AND order_type IN [KIOSK, TAKEAWAY]` (l.57-60).
- Planifié `everyFiveMinutes()->withoutOverlapping()->onOneServer()` :
  `app/Console/Kernel.php:105-108`.
- NF525-safe : ne touche QUE `fiscal_sequence_no IS NULL` (l.56) → aucune commande sealée mutée.

Les 90 kiosk auraient été purgées sous ~3h. Elles ne stagnent que parce que le scheduler
ne tourne pas sur `foodking_e2e`.

### 3. Le Z est fiscalement correct (concédé par la finding elle-même)
`ZReportService::aggregate` (vérifié l.337-341) :
```php
Order::withoutGlobalScope(BranchScope::class)->withTrashed()
  ->where('branch_id', $branchId)
  ->whereNotNull('fiscal_sequence_no')          // l.340
  ->where('payment_status', '!=', UNPAID);       // l.341
```
PENDING_COUNTER (15) n'a AUCUN `fiscal_sequence_no` (aucun encaissement) → **correctement EXCLU**
du Z. Aucun argent encaissé manquant, aucun n° fiscal absent. `warnOnOrphanedPaidOrders`
(l.611-641 vérifié) ne couvre QUE `payment_status=PAID && fiscal NULL` (orphelin kiosk-payé,
problème distinct et réel) — l'absence de couverture PENDING_COUNTER est **correcte** : un
PENDING_COUNTER non collecté n'est pas un orphelin fiscal, c'est une vente pas encore payée.

---

## Pourquoi pas même P3 utile
La justification du P3 (« confort opérateur ») repose entièrement sur « invisible à l'opérateur »,
réfuté par §1. Un nudge à la clôture Z serait **redondant** avec la file d'encaissement déjà
temps-réel + le cron de purge. Aucun argent perdu, aucun défaut NF525, aucune fuite, aucune
sécurité. PENDING_COUNTER = commande passée, cash pas encore remis ; par design hors-Z et par
design dans la file de collecte jusqu'à encaissement OU auto-purge. Sémantique V1-LOCAL correcte.

## Sévérité confirmée : REFUTED
- `real`: false (la nuisance prétendue n'existe pas ; garde déjà présente = file encaissement + cron)
- `heal_safe_nonfrozen`: true (le nudge proposé serait non-frozen, mais inutile — pas de heal requis)
- Lentille : commerçant — il VOIT bien ses commandes en attente (écran Encaissement dédié),
  il ne fait pas « confiance à un Z qui cache de l'argent » car le Z ne cache rien (aucun encaissé).
