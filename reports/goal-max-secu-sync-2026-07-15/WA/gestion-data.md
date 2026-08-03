# Audit intégrité données — GESTION (WA) — 2026-07-15

Agent : sous-agent GESTION (goal-max-secu-sync). Méthode : lecture code ancré (ItemCategoryService,
CouponService, Kernel, ItemExtraRequest/ItemVariationRequest, EventServiceProvider) + preuves DB live
(`foodking_e2e`) + tests HTTP jetables (créés, exécutés, supprimés) + tinker. Zéro fichier de prod modifié.

## Verdict rapide

| # | Sév. | Titre | Preuve |
|---|------|-------|--------|
| 1 | P1 | Scheduler Laravel jamais piloté sur la box → backup 21 j stale + purges mortes + filets NF525 inactifs | `crontab -l` vide + dernier daily 2026-06-24 + 0 heartbeat + 617 quotes purgeables |
| 2 | P2 | Coupon scopé surface/branche : check « valide », création = 422 systématique (4 chokepoints sans contexte) | tinker coupon 21 + fail-closed Coupon.php:141/149 |
| 3 | P2 | Unicité variation sans scope attribut → 66 lignes live (6 produits tacos) inéditables via l'endpoint dédié | 2 tests HTTP rouges + SQL 66 rows |
| 4 | P3 | `end_date` coupon stocké à minuit → dernier jour de validité exclu | DB coupons 9/14/15 `2026-12-31 00:00:00` |
| 5 | P3 | Compteur `usage_count` mort : gestion affiche 0 à vie, quota borne testé contre colonne morte | coupon 9 : usage_count=0 vs 1 row order_coupons |

## Détail

### 1. P1 — Scheduler non piloté : backup/purges/filets NF525 tous morts sur la box
- **Evidence** :
  - `crontab -l` → `no crontab for 1millnonstop` ; aucun LaunchAgent schedule (seuls litellm/neo4j).
  - `storage/backups/db-daily/` : dernier fichier `daily-2026-06-24.sql.gz` (**21 jours**, cadence attendue quotidienne 03:00, `app/Console/Kernel.php:144-149`).
  - `storage/logs/heartbeat.log` **inexistant** alors que `healthz:check` est schedulé toutes les 5 min (`Kernel.php:119-125`) → le schedule n'a jamais tourné en continu.
  - DB : **617** `order_quotes` éligibles au prune 7 j (`Kernel.php:203-209`) toujours présents ; **28** tokens sanctum expirés >24 h (`Kernel.php:222-228`) ; oldest domain_events 2026-05-28.
- **Impact** : perte de données réelle en cas de panne disque (21 j de commandes + fiscal non sauvegardés) ;
  lanes NF525 opérationnelles inactives : Z-close 23:59 (`Kernel.php:412-420`), Z-open 00:01 (457-465),
  chain-monitor 03:30 (308-342), archive quotidienne 02:00 (350-380), verify-z-membership 06:05 (91-103),
  backup-verify-restore 05:00 (153-158), outbox rescue/monitor chaque minute (40-53).
- **Repro** : `crontab -l ; ls -la storage/backups/db-daily/ ; mysql foodking_e2e -e "SELECT COUNT(*) FROM order_quotes WHERE consumed_at IS NULL AND expires_at < NOW() - INTERVAL 7 DAY"`.
- **Fix minimal** : appliquer la ligne du runbook `docs/GO_LIVE_RUNBOOK_LECAYENNE.md:110` (crontab `* * * * * … php artisan schedule:run`) — sur macOS préférer un LaunchAgent (`launchd` relance après veille, cron non) + ajouter une alerte « dernier backup > 24h » dans healthz. Note : le VPS a sa ligne via `scripts/deploy/server-setup-hetzner.sh:194` ; le trou concerne LA box locale qui héberge la DB fiscale courante.

### 2. P2 — Coupons scopés surface/branche : annoncés valides, refusés à la création (422)
- **Evidence code** : `app/Models/Coupon.php:141` (branch_scope non vide + branchId null → false) et `:149`
  (surfaces non vide + surface null → false) = fail-closed. Or AUCUN chokepoint de création ne passe le contexte :
  - `app/Services/OrderService.php:555-559` (web/app `myOrderStore`) — `resolveCouponById(id, subtotal, Auth::id())`
  - `app/Services/OrderService.php:1074-1078` (POS `posOrderStore`)
  - `app/Services/OrderService.php:1638-1642` (table `tableOrderStore`)
  - `app/Services/FrontendOrderService.php:495-511` (kiosk/web frontend)
  - `app/Services/Pricing/DiscountCalculator.php:17` (devis)
  Alors que le check annonce OK : `CouponService::couponChecking` (l.350-365) passe branch+surface, et
  `app/Services/Kiosk/KioskPromoService.php:79` appelle `isUsableNow($branchId,'kiosk')` — son commentaire dit
  explicitement vouloir éviter « annoncé valide sur la borne puis rejeté au checkout », ce qui est EXACTEMENT
  ce qui se produit.
- **Preuve exécutée** (tinker, DB live — coupons 21/22 `ADVKIOSKB1`/`ADVWEBONLY`, surfaces+branch_scope non NULL) :
  `isUsableNow(1,'kiosk')=true` / `isUsableNow(null,null)=false` / `resolveCouponById(21,50.0,144)` →
  `EXCEPTION 422: This coupon is not applicable to your branch, surface, or current day/hour.`
- **Impact** : toute promo créée en gestion avec canal/branche renseignés est inutilisable — le client borne/web
  voit « coupon appliqué » au check puis la commande échoue en 422 générique au paiement.
- **Fix minimal** : passer `branch_id` de la commande + surface du path (`'pos'`, `'kiosk'`, `'web'`, table→`'pos'`)
  aux 5 appels `resolveCouponById`.

### 3. P2 — Unicité variation ignore `item_attribute_id` : 66 lignes live inéditables
- **Evidence** : `app/Http/Requests/ItemVariationRequest.php:34-38` — `Rule::unique('item_variations','name')`
  scopée `item_id` seulement. Catalogue LIVE légitime : mêmes noms de viande sous « Viande 1 » ET « Viande 2 »
  (item 27 Big Tacos rows 47/51, 48/52, 49/53, 50/54 ; pareil items 36, 37, 97, 104, 105 — **66 rows**,
  0 vrai doublon par (item, name, attribut)).
- **Preuve exécutée** (tests HTTP jetables, supprimés après run) :
  - POST `/api/admin/item/variation/{item}` « Poulet mariné » sous attribut B quand il existe sous A → **422** (attendu 201) ;
  - PUT `/api/admin/item/variation/{item}/{twin}` en GARDANT son propre nom (changement de prix) → **422** (attendu 200).
  - (Contrôle : la garde par item fonctionne — doublon même attribut → 422 correct ; pattern pointé `route('item.id')` résout bien ici, contrairement au constat du heal extras 6b2d762ea.)
- **Impact** : le gérant ne peut plus MODIFIER (prix/statut/nom) aucune des 66 variations « jumelles » des 6 produits
  tacos via l'endpoint dédié, ni recréer ce shape. Contournement partiel : le formulaire item complet
  (`ItemService::update` nested l.323-343, non validé par ItemVariationRequest).
- **Fix minimal** : ajouter `->where('item_attribute_id', (int) $this->input('item_attribute_id'))` à la règle.
  ⚠️ Vérifier le miroir extras : `ItemExtraRequest.php:38` scope item seulement — OK aujourd'hui (0 extra
  même-nom-même-item en DB) mais même piège si des groupes `group_label` dupliquent un nom.

### 4. P3 — `end_date` coupon = minuit → dernier jour affiché exclu
- **Evidence** : `app/Services/CouponService.php:179-182` (`date('Y-m-d H:i:s', strtotime($request->end_date))`
  → `2026-12-31 00:00:00`) + `:433` (`$now->gt(end_date)` → expiré dès 00:00:01 le 31/12). Miroir
  `Coupon::isUsableNow` l.107. DB live : coupons 9/14/15 ont `end_date=2026-12-31 00:00:00`.
- **Impact** : un coupon « valable jusqu'au 31/12 » meurt le 30/12 au soir. Incohérence donnée-affichage gestion.
- **Fix minimal** : stocker `->endOfDay()` quand l'input est date-seule (ou comparer `$now->gt(Carbon::parse($end)->endOfDay())` si heure absente).

### 5. P3 — `usage_count` colonne morte : dashboard à 0 pour toujours + quota borne contre colonne morte
- **Evidence** : aucun writer (`grep usage_count` → seulement init 0 `CouponService.php:236`, lecture resource
  `app/Http/Resources/CouponResource.php:57`, check quota `app/Models/Coupon.php:155-157`). DB : coupon 9 a
  1 row `order_coupons` mais `usage_count=0`. Le checkout est protégé par le vrai count (heal COUPON-CAP-01,
  `CouponService.php:453-459`), MAIS `KioskPromoService.php:79` → `isUsableNow` teste le quota global contre
  la colonne morte → un coupon épuisé reste ANNONCÉ sur la borne (puis rejeté au checkout, encore le pattern #2).
- **Fix minimal** : soit incrémenter `usage_count` à chaque `OrderCoupon::create`, soit remplacer les lectures
  (resource + isUsableNow quota) par un count `order_coupons`.

## Zones vérifiées SAINES (pas de finding)
- `ItemCategoryService::destroy` : gardes items actifs (l.176-182) + sous-catégories (l.188-194) opérantes (heal 6b2d762ea confirmé).
- Propagation CRUD → surfaces : événements complets (`EventServiceProvider.php:225-289` — Item/Category
  Created/Updated/Deleted + extras/variations/addons via `ItemAvailabilityChanged` → invalidation cache kiosk + outbox).
- Anti-doublon extras (heal 6b2d762ea) : garde opérante, 0 doublon live.
- Coupons : validation négatif/%>100/min_order (`CouponRequest.php:52-96`), plafond `maximum_discount` +
  `min(subtotal)` (`CouponService.php:397-409`), anti-cumul kiosk coupon⊕fidélité (`DiscountCalculator.php:38-40`),
  cumul POS fidélité plafonné au subtotal (heal 2026-07-11 vérifié `PosRedemptionService.php:146-154`).
- Soft-delete Coupon (historique NF525 préservé), OrderCoupon stocke la remise recalculée serveur.
- Data hygiene mineure (non comptée) : coupons 21/22 = graines adversariales avec `discount_type=2` (enum
  inconnu → traité FIXED) — débris de test en DB, à purger.
