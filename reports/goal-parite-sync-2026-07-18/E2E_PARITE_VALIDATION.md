# Validation E2E abusive — PARITÉ borne==web + SYNC unifiée POS/KDS

**Date** : 2026-07-18
**Spec** : `tests/e2e/_teste2e-parite-sync-2026-07-18.spec.js`
**Cible** : serveur `:8000` (changes backend-only, pas de rebuild), DB `foodking_e2e`
**Commande** : `PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/_teste2e-parite-sync-2026-07-18.spec.js`
**Résultat** : **5/5 PASS** (32,0 s) — chaîne fiscale `CHAIN OK` sur 4 branches — fixtures `PARITEVAL-` nettoyées (orders=0, coupons=0, users=0).
**Discipline** : aucun code applicatif modifié (seul le spec + ce dossier ajoutés). Aucun paiement non-test finalisé hors flux de test.

---

## Verdict par vague

| Vague | Objet | Résultat | Preuve clé |
|-------|-------|----------|------------|
| **W1** | Parité pricing borne==web | ✅ PASS | subtotal/total/TVA byte-identiques + `composition_snapshot` identique |
| **W2** | Sync unifiée → POS | ✅ PASS | web + borne dans la MÊME file counter-collect → `confirmCounterPayment` → PAID + fiscal_seq |
| **W3** | Sync unifiée → KDS | ✅ PASS | les 2 board-released (`applyBoardReleaseFilter`) + endpoint `kds-order` + OSS |
| **W4** | Heal S1 non-COD | ✅ PASS | web NON-COD Accept → PAS board-released orpheline ; web COD → board-released + encaissable |
| **W5** | Heal S3 coupon (legacy) | ✅ PASS | coupon `surfaces=[kiosk]` OK kiosk / REJET web / REJET null(pré-fix) ; sans restriction OK partout |

**Aucun défaut de parité ni de divergence de sync détecté.**

---

## Fondation architecturale prouvée

La parité n'est pas cosmétique — elle est **structurelle**, vérifiée dans le code puis prouvée en runtime :

1. **Même endpoint** : borne ET web postent sur `POST /api/frontend/order` (`FrontendOrderController::store`).
2. **Même pricing SSOT** : `FrontendOrderService.php:303` appelle `PricingRequest::forKiosk(...)` **inconditionnellement** (borne comme web). Le quote `/api/frontend/order/quote` force `surface='kiosk'` pour tout appelant frontend (`PosController::quote`). → « le web est traité forKiosk ».
3. **Seule différence** : `source_surface = KioskMachine ? 'kiosk' : 'web'` (`FrontendOrderService.php:590`) — la présence d'une KioskMachine liée au token, PAS l'order_type.
4. **Même file d'encaissement** : `routes/api.php:804` `counter-collect/pending` liste kiosk **ET** web (clause `source_surface='web' + COUNTER_DEFERRED`, heal P1-3).
5. **Même board KDS** : `KitchenReleaseRule::applyBoardReleaseFilter` (PAID | PENDING_COUNTER | POS-cash) — dimension paiement uniquement, surface-agnostique.
6. **Même allowlist OSS** : `OrderStatusScreenOrderService` filtre par `order_type IN (KIOSK, TAKEAWAY)` — surface-agnostique.

---

## W1 — Parité pricing borne == web (POST /api/frontend/order)

Même panier des deux côtés : item 1 « Menu (Frites + Boisson) » ×2 + extra 234 « Grande Portion » ×1.

```
[W1] WEB   = {"source_surface":"web",  "order_type":10,"subtotal":7,"total":7,"total_tax":0.64,"fiscal_sequence_no":null}
[W1] KIOSK = {"source_surface":"kiosk","order_type":10,"subtotal":7,"total":7,"total_tax":0.64,"fiscal_sequence_no":null}
[W1] pricing identique → subtotal 7 | total 7 | tva 0.64
[W1] WEB   snapshot = {"lines":[],"addons":[],"extras":[{"extra_id":234,"quantity":1,"extra_name":"Grande Portion","line_total":1,"unit_price":1}],"schema_version":1}
[W1] KIOSK snapshot = {"lines":[],"addons":[],"extras":[{"extra_id":234,"quantity":1,"extra_name":"Grande Portion","line_total":1,"unit_price":1}],"schema_version":1}
```

- `subtotal` / `total` / `total_tax` **byte-identiques** (web crée sans quote — recalcul serveur via `forKiosk` ; borne quotée+scellée → même résultat).
- `composition_snapshot` **deep-equal** modulo `captured_at` (horodatage naturel) — l'option (extra 234) figée à l'identique.
- La seule différence : `source_surface` (web vs kiosk). **Parité prouvée.**

> Note : le quote `/api/frontend/order/quote` exige une KioskMachine (503 pour un token web) → le web ne quote pas, il crée directement (`sealForCommit` gaté `if ($isKioskMachineOrder)`, ligne 566). Les deux chemins convergent sur `PricingRequest::forKiosk` dans le store.

---

## W2 — Sync unifiée → POS (file counter-collect + encaissement fiscal commun)

```
[W2] web=5768  kiosk=5769
[W2] web before accept   = {"source_surface":"web",  "status":1,"payment_status":10,"pos_payment_method":null}      ← PENDING/UNPAID
[W2] kiosk (Plan B, auto)= {"source_surface":"kiosk","status":4,"payment_status":15,"pos_payment_method":6}          ← auto PENDING_COUNTER+COUNTER_DEFERRED
[W2] web after accept     = {"source_surface":"web", "status":4,"payment_status":15,"pos_payment_method":6}           ← flip COD → encaissable
[W2] counter-collect pending ids = [5528,5529,5530,5531,5532,5767,5768,5769]                                          ← web(5768) ET borne(5769) présents
[W2] web final  = {"payment_status":5,"pos_payment_method":1,"fiscal_sequence_no":2677}                               ← PAID + fiscal
[W2] kiosk final= {"payment_status":5,"pos_payment_method":1,"fiscal_sequence_no":2678}                               ← PAID + fiscal
```

- Web takeaway COD → **Accept** (`OnlineOrderController::changeStatus`) → flip `PENDING_COUNTER` + marqueur `COUNTER_DEFERRED` (heal P1-3/S1).
- Borne Plan B COD → auto-accept direct à la création → déjà `PENDING_COUNTER` + `COUNTER_DEFERRED`.
- **File d'encaissement UNIFIÉE** : les deux (5768 web, 5769 borne) remontent dans `counter-collect/pending`.
- **Encaissement commun** : `confirmCounterPayment` (mode CASH) → les deux → `PAID` + `fiscal_sequence_no` alloué (web **2677**, borne **2678** — consécutifs, gap-free). **Même chemin d'encaissement, même allocation fiscale NF525.**

---

## W3 — Sync unifiée → KDS (board) + OSS

```
[W3] board-released contient web? true | borne? true            ← KitchenReleaseRule::applyBoardReleaseFilter
[W3] endpoint kds-order status = 200 | web présent? true | borne présent? true | total board = 27
[W3] OSS status = 200 | web présent? true | borne présent? true
```

- **Board KDS (SSOT)** : la query `whereIn(status, visibleStatuses) + applyBoardReleaseFilter` (PAID) contient les deux commandes → cuisine voit web ET borne.
- **Board KDS (endpoint réel)** : `GET /api/admin/kds-order` (admin, toutes branches) → 200, les deux présents (board de 27 < cap 50).
- **OSS** : mur public `GET /api/frontend/oss-order?branch_id=1` (après passage PREPARING) → 200, les deux présents. L'allowlist OSS étant `order_type IN (KIOSK, TAKEAWAY)`, une web takeaway (order_type=10) est admise **exactement comme la borne**.

---

## W4 — Heal S1 : web non-COD PAS board-released orpheline (+ non-régression COD)

```
[W4] non-COD (carte) after accept = {"status":4,"payment_status":10,"pos_payment_method":null}   ← reste UNPAID, PAS de marqueur
     → board-released ? NON (assertion : board NE contient PAS nonCod)
[W4] COD after accept = {"status":4,"payment_status":15,"pos_payment_method":6}                   ← flip PENDING_COUNTER+COUNTER_DEFERRED
     → board-released ? OUI  |  encaissable (counter-collect) ? true
```

- Une web takeaway **NON-COD** (carte) acceptée → le flip `PENDING_COUNTER` est **gaté COD** (`OnlineOrderController.php:165`) → reste `UNPAID`, aucun marqueur → **PAS board-released** → pas d'orpheline « préparée jamais encaissable ». (Régression P1 évitée dès l'activation carte web.)
- Une web takeaway **COD** acceptée → flip + marqueur → **board-released + encaissable** (non-régression du heal vivant P1-3). Prédicat du flip désormais cohérent avec celui du marqueur (tous deux exigent COD).

---

## W5 — Heal S3 : coupon surface au commit (chemin legacy)

```
[W5] matrice = {"ssot":true,
                "kiosk_on_kiosk":"OK", "kiosk_on_web":"REJECT", "kiosk_on_null":"REJECT",
                "open_on_kiosk":"OK",  "open_on_web":"OK"}
```

Prouvé au niveau `CouponService::resolveCouponById($id,$sub,$user,$branch,$surface)` — le **code EXACT** threadé par S3 (signature 5-arg, `isUsableNow($branch,$surface)`) :

- Coupon `surfaces=["kiosk"]` : **OK sur kiosk**, **REJET sur web** (bonne surface exigée), **REJET sur `surface=null`** — ce dernier = le comportement **pré-fix** (le commit passait toujours `null` → sur-rejet du coupon même sur SA propre surface). C'est précisément le défaut que S3 corrige.
- Coupon sans restriction (`surfaces=null`) : **OK sur kiosk ET web** (inchangé).

### ⚠️ Escalade documentée (frozen, cf. commit `6c7701214`)
`pricing.use_ssot_service = true` (défaut prod). Sur ce chemin, le coupon est validé **en premier** par `DiscountCalculator`/`PricingService` (**FROZEN**) qui appelle encore `resolveCouponById` en **3-arg** (surface non threadée). Le heal S3 vit donc dans le **chemin legacy** (`config('pricing.use_ssot_service')=false`) + défense-en-profondeur. Étendre l'accept-on-match surface au chemin SSOT = **touch frozen (LOCK+gate)**, non fait. → le test plein-API cross-surface est **volontairement non exercé** (gaté SSOT) ; le heal est prouvé au niveau du service, seule surface non-frozen.

---

## Hygiène & evidence

- **Chaîne fiscale** : `php artisan fiscal:verify-chain --all` → `CHAIN OK` sur branches 1/7/8/9 (les allocations de test 2677/2678… sont des empreintes NF525 sur la DB e2e ; les orders sont purgés, les `audit_logs`/`cash_movements` immuables restent — comportement CORRECT, jamais de suppression d'écriture fiscale).
- **Fixtures** : préfixe `PARITEVAL-` ; cleanup `beforeAll`+`afterAll` → résiduels **0/0/0** (orders/coupons/users).
- **Frozen zones** : aucun fichier applicatif modifié (`git status` : seuls le spec + ce dossier ajoutés).
- **Item** : item réel du menu (id 1) — aucun item fixture créé.

---

## Conclusion

La **parité borne↔web est structurelle et prouvée** : même endpoint, même pricing SSOT (`forKiosk`), même snapshot ; seule la `source_surface` diffère. La **synchronisation vers POS et KDS est unifiée** : file counter-collect commune, encaissement `confirmCounterPayment` commun, board KDS + OSS communs. Les **heals S1 (flip gaté COD) et S3 (coupon surface au commit) tiennent** et ne cassent aucun parcours. **0 défaut.**
