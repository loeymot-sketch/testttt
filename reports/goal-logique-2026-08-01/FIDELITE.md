# AUDIT MONEY-PATH FIDÉLITÉ — Le Cayenne

**Date** : 2026-08-01/02 · **Auditeur** : agent money-path fidélité
**Périmètre** : page fidélité du site client (`#loyalty`) + acquisition + utilisation des points + abus + cohérence cross-surface
**Méthode** : lecture code (`file:line`) + preuves d'exécution RÉELLES (HTTP live sur `127.0.0.1:8000`, requêtes DB via `php artisan tinker`, DOM/screenshots Playwright sur `127.0.0.1:8899`)
**Données de test** : comptes `ZZ-TEST-LOYALTY-B` (`4AD04F0B`) et `ZZ-TEST-LOYALTY-C` (`ZZTESTC1`), commandes `ZZ-TEST-LOY-*` — **toutes purgées en fin d'audit**
**Code applicatif modifié** : AUCUN (`git diff --stat -- app/ resources/ public/ config/ routes/` = vide)

---

## 0. BARÈME RÉEL CONSTATÉ (source de vérité = backend)

| Paramètre | Valeur RÉELLE (DB `settings`, groupe `loyalty_setup`) | Endpoint public `/api/frontend/loyalty/config` | Annoncé site + CGV | Verdict |
|---|---|---|---|---|
| **Acquisition** | `loyalty_points_per_euro = 10` | `points_per_euro: 10` | « 1 € dépensé = 10 points » | ✅ **CONFORME** |
| **Conversion** | `loyalty_points_for_1_euro_discount = 100` | `points_for_1_euro_discount: 100` | « 100 points = 1 € » | ✅ **CONFORME** |
| **Minimum** | `loyalty_min_redeem_points = 50` | `min_redeem_points: 50` | « dès 50 points » | ❌ **FAUX — le minimum RÉEL est 100** |
| **Paliers** | `loyalty_tiers = NULL` → défaut backend `[100,250,500,1000,2000]` | `tiers: [100,250,500,1000,2000]` | Novice 0 / Pepper 500 / Master 1500 / Légende 5000 | ❌ **INVENTÉS côté front** |

**Preuve barème** (live) :
```
$ curl -s http://127.0.0.1:8000/api/frontend/loyalty/config
{"status":true,"data":{"points_per_euro":10,"points_for_1_euro_discount":100,
 "min_redeem_points":50,"tiers":[100,250,500,1000,2000],
 "label":"Dépensez 100 points = 1€ de remise"}}
```

**Barème effectif = 1 € → 10 pts (acquisition) · 100 pts → 1 € (conversion) · minimum RÉEL 100 pts (et non 50).**

---

## 1. DÉCOMPTE

| Sévérité | Nombre |
|---|---|
| **P0** | **2** |
| **P1** | **2** |
| **P2** | **4** |
| **P3** | **1** |

---

## 2. P0 — ARGENT / POINTS FAUX OU VOLABLES

### P0-1 — Points DÉTRUITS à l'annulation pour tout compte `status = 1` (asymétrie redeem/refund)

**Fichiers**
- `app/Services/LoyaltyService.php:40-41` (refund) — filtre `->where('status', \App\Enums\Status::ACTIVE)` **(= 5 uniquement)**
- `app/Services/Loyalty/PosRedemptionService.php:117-131` (redeem) — accepte `Status::ACTIVE` **PUIS retombe explicitement sur `->where('status', 1)`**
- `app/Http/Controllers/Frontend/LoyaltyController.php:175` — `/loyalty/register` (endpoint PUBLIC) crée les comptes avec `$user->status = 1`

**Le défaut** : la caisse ACCEPTE de débiter les points d'un compte `status=1` (fallback legacy explicite), mais `refundPoints()` REFUSE de les lui rendre (il n'accepte que `status=5`). Aucune compensation, aucune ligne de contre-passation, aucune alerte : le seul effet est un `Log::warning('[Loyalty] Refund skipped: customer not found')`.

**Reproduction LIVE (chiffres exacts)**
```
Compte B (id=323, status=1, code=4AD04F0B) — solde initial : 500 pts
POST /api/admin/pos-order/6045/redeem-loyalty {"points":300,"loyalty_code":"4AD04F0B"}
  → HTTP 200 {"discount_eur":3,"balance_after":200}
  → order 6045 : subtotal 10,00 € · discount 3,00 € · total 7,00 €
  → B = 200 pts                                            (débit ACCEPTÉ)

Annulation → LoyaltyService::refundPoints($order, 'pos')
  → B = 200 pts                                            (AUCUN remboursement)
```
**Perte client : 300 pts = 3,00 € détruits définitivement, en silence.**

**Population exposée** (DB dev) : `6` comptes porteurs d'un `loyalty_code` sont en `status=1`. Tout compte créé via `/loyalty/register` ou `/loyalty/opt-in` (adhésion borne/comptoir) tombe dans ce cas.

---

### P0-2 — Deux codes fidélité sur une même commande : à l'annulation, TOUS les points partent au DERNIER code (vol entre clients)

**Fichiers**
- `app/Services/Loyalty/PosRedemptionService.php:253` — `'loyalty_customer_code' => $customer->loyalty_code` **écrase** la valeur précédente (colonne mono-valeur)
- `app/Services/LoyaltyService.php:27-45` — `refundPoints()` **somme TOUTES** les lignes `redeem` de la commande (`->where('order_id', $order->id)`, l.35) puis crédite **un seul** utilisateur, résolu depuis `$order->loyalty_customer_code` (l.40)
- Le garde anti-double-rachat est l'index `UNIQUE(user_id, order_id, type)` (migration `2026_03_26_075919`) → **par UTILISATEUR**, il n'empêche pas un 2ᵉ code sur la même commande
- `resources/js/components/admin/posOrders/PosOrderShowComponent.vue:558-569` — `canShowLoyaltyRedeem` ne teste **ni** `order.discount > 0` **ni** l'existence d'un rachat → le bouton caisse reste cliquable après un rachat réussi

**Reproduction LIVE (chiffres exacts)** — commande 6046, sous-total 10,00 €, les deux comptes en `status=5` :
```
Départ                : C(324) = 500 pts · B(323) = 500 pts
redeem #1 code ZZTESTC1 (C), 200 pts  → HTTP 200 · discount 2,00 € · C = 300
redeem #2 code 4AD04F0B (B), 100 pts  → HTTP 200 · discount cumulé 3,00 € · total 7,00 € · B = 400
   → orders.loyalty_customer_code : 'ZZTESTC1'  ->  '4AD04F0B'   (ÉCRASÉ)

Annulation → refundPoints()
   → ledger : 1 seule ligne manual_add de +300 sur user_id=323
   → C = 300 pts  (PERD 200 pts = 2,00 € définitivement)
   → B = 700 pts  (GAGNE 200 pts = 2,00 € qui ne lui appartiennent pas)
```
**200 points téléportés d'un client à un autre. Ni création ni destruction nette, mais mauvaise attribution intégrale.**

**Atteignable depuis l'UI caisse réelle** : 2 clics (le bouton « Utiliser les points fidélité » ne disparaît pas après un rachat). Scénario quotidien plausible : deux amis commandent ensemble, chacun veut utiliser ses points ; le caissier applique les deux.

---

## 3. P1 — PROMESSE CLIENT NON TENUE / ABUS POSSIBLE

### P1-1 — Le « minimum 50 points » annoncé est INATTEIGNABLE (le vrai minimum est 100)

**Fichiers**
- `app/Http/Controllers/Frontend/LoyaltyController.php:383-386` — rejet si `$pointsToRedeem % $rate !== 0` (`$rate = 100`)
- `app/Services/Loyalty/PosRedemptionService.php:103-109` — même règle côté caisse
- Front : `screens.jsx:804`, `screens.jsx:817`, `screens.jsx:1047` affichent `cfg.min_redeem_points` (= 50)
- CGV : `legal/cgv.html:229-230` — « à partir d'un minimum de 50 points cumulés »

**Reproduction LIVE** — compte à EXACTEMENT 50 points (le minimum annoncé) :
```
WEB    POST /api/frontend/loyalty/redeem {"points":50}
       → HTTP 400 « Les points doivent être un multiple de 100. Montant valide le plus proche : 0. »
CAISSE POST /api/admin/pos-order/{id}/redeem-loyalty {"points":50}
       → HTTP 422 POINTS_NOT_MULTIPLE « Les points doivent etre un multiple de 100 »
```
Le front lui-même neutralise le réglage : `screens.jsx:756-759` calcule `usablePoints = floor(points/100)*100` puis `p >= 50 ? p : 0` → un client à 50-99 pts voit « 0,00 € » alors que la page lui promet, deux lignes plus haut, une utilisation « dès 50 points ». **Le réglage `loyalty_min_redeem_points = 50` est purement décoratif : le pas de 100 le rend inopérant.** Engagement contractuel (CGV art. 12) non tenu.

---

### P1-2 — Compte fidélité créé au comptoir/borne : connexion web possible mais TOUT est 401 (boucle de login, solde et QR inaccessibles)

**Fichiers**
- `app/Http/Controllers/Frontend/LoyaltyController.php:175` — `/loyalty/register` pose `status = 1`
- `app/Http/Controllers/Auth/GuestSignupController.php:215-264` — le chemin « compte existant non supprimé » **ne remonte JAMAIS** `status` à `Status::ACTIVE` (seuls la création l.253-262 et la restauration soft-delete l.236-238 le font) ; le token est pourtant émis l.319
- `app/Http/Middleware/EnsureUserStatusActive.php:72-90` — exige `status === Status::ACTIVE` (5), sinon **supprime le token** et renvoie 401
- Front : `api.js:193-196` transforme le 401 en `kind:'auth'`, purge le token ; `screens.jsx:704-708` rappelle `onAccount()` → **boucle de reconnexion infinie**

**Reproduction LIVE**
```
POST /api/auth/guest-signup/verify {"phone":"0699000222","token":"7983"}
  → HTTP 200 {"message":"Connexion réussie.","token":"8142|tYImQwAAbRxpNGwO7NaaDiF428YpLGB3WGdWnpx968e6fa9c", ...}
GET  /api/profile  (avec CE token)
  → HTTP 401 {"message":"User account inactive"}
DB   users.status après signup = 1        (jamais remonté à 5)
```
Conséquence : solde fidélité invisible, **QR de la caisse impossible à générer** (`/loyalty/qr` → 401), historique vide, et **aucune commande web possible**. Les points existent en base mais sont hors d'atteinte depuis le site.
**Population exposée** (DB dev) : `3` comptes `status=1` détiennent des points > 0.

---

## 4. P2 — FRICTION / LATENT

### P2-1 — Remise fidélité à la création de commande : lookup figé sur `status = 1`, invisible pour 88 % des clients
`app/Services/FrontendOrderService.php:954-957` et `app/Services/Order/OrderQuoteService.php:354-357` cherchent le porteur du code avec `->where('status', 1)`. Un client moderne (`Status::ACTIVE` = 5, créé par `GuestSignupController`) n'est **pas trouvé** → `return` silencieux, remise = 0, **plein tarif sans le moindre message**.
```
Requête EXACTE de FrontendOrderService.php:954 rejouée :
  B (status=1) → TROUVÉ
  C (status=5) → NULL  (remise fidélité silencieusement ignorée)
Population : 44 comptes status=5 (2 820 pts) vs 6 comptes status=1  → 88 % exclus
```
Preuve HTTP : commande web avec `loyalty_code` + `discount:1.00` → `{'subtotal': 9.5, 'discount': 0, 'total': 9.5}`.
**Actuellement DORMANT** : ni la borne (`kiosk.promo_enabled = false`, `KioskLoyaltyComponent.vue:194`) ni le site (`api.js:635-660` n'envoie aucun champ `discount`) n'empruntent ce chemin. Devient P0 le jour où l'owner active `kiosk.promo_enabled` : points pré-débités par `/loyalty/redeem`, remise jamais appliquée.

### P2-2 — Paliers Novice/Pepper/Master/Légende inventés côté front, jamais lus du backend
`screens.jsx:625-630` code en dur `TIERS = [0, 500, 1500, 5000]`. `screens.jsx:719-723` ne récupère de `/loyalty/config` que `points_per_euro`, `points_for_1_euro_discount`, `min_redeem_points` — **`c.tiers` est ignoré**. Le backend expose `[100, 250, 500, 1000, 2000]`. Les CGV (`legal/cgv.html:230-231`) publient la version FRONT. Deux barèmes de paliers coexistent ; aucun avantage monétaire n'y est attaché en V1 (badges cosmétiques), d'où P2 et non P1 — mais l'engagement CGV pointe vers la source non canonique.

### P2-3 — `discount_value` renvoyé par `/loyalty/check` sur-promet (3,47 € pour 347 pts, réel 3,00 €)
`app/Http/Controllers/Frontend/LoyaltyController.php:1000-1009` calcule `round($points / $rate, 2)` sans arrondir au multiple de 100 exigé au rachat.
```
POST /api/frontend/loyalty/check {"code":"ZZTESTC1"}   (solde 347)
 → {"points":347,"discount_value":3.47}    ← utilisable RÉEL : 3,00 €
```
Consommé par `KioskLoyaltyComponent.vue:170-171, 208, 543, 562`, mais **gaté OFF** (`discountsEnabled && kioskPromoEnabled`, défaut false) → dormant aujourd'hui. Le site web, lui, calcule correctement (3,00 € affiché — cf. capture).

### P2-4 — Le rachat WEB brûle les points ~30 min sans aucune remise (endpoint toujours ouvert)
`LoyaltyController::redeem` écrit une ligne `redeem` `order_id = NULL` / `source_surface = 'pos'` (l.401-407, `'pos'` car un client web n'est pas une KioskMachine). Le seul consommateur de ces lignes filtre `source_surface = 'kiosk'` (`FrontendOrderService.php:987`) → jamais rattachée. `PosRedemptionService` crée une ligne NEUVE et redébite.
```
C = 190 pts → POST /api/frontend/loyalty/redeem {"points":100} → HTTP 200 « 100 points utilisés »
  → C = 90 pts · ledger : type=redeem, points=-100, order_id=NULL, surface='pos'
  → AUCUNE remise créée nulle part
```
**Atténué** : `screens.jsx:763-780` (heal S4 2026-07-29) n'appelle PLUS cet endpoint et renvoie le client vers le QR en caisse ; et le reaper `LoyaltyService::reapOrphanRedemptions` (`CleanupStalePendingKioskOrders.php:223`, planifié toutes les 5 min, fenêtre 30 min) re-crédite. **Vérifié live : 2 orphelins re-crédités, solde restauré 0 → 200 pts.** Reste un trou de ~30 min si un client (ou une app mobile) appelle l'endpoint directement.

---

## 5. P3

### P3-1 — Ratio d'earn appliqué au total TTC livraison incluse
`AwardLoyaltyPointsOnDelivery.php:96-102` : `floor($orderTotal * 10)` où `$orderTotal` = `total` (TTC, `delivery_charge` inclus). Un client cumule donc des points sur les frais de livraison et sur la TVA. Décision métier à assumer, sans impact de correction sur V1 (100 % à emporter, livraison OFF).

---

## 6. CE QUI EST SAIN (vérifié, pas supposé)

| Contrôle | Résultat live |
|---|---|
| **Acquisition exacte** | Commande web 19,00 € → `loyalty_points_awarded = 190`, solde 0 → **190**. `19,00 × 10 = 190` ✅ |
| **Moment du crédit** | À `PREPARED` (TAKEAWAY/KIOSK) ou `DELIVERED` (autres) — `AwardLoyaltyPointsOnDelivery.php:39-47`. Ni à la commande, ni au paiement. Ligne ledger `earn` / `source_surface='web'` |
| **Idempotence du crédit** | Sentinelle atomique `-1` sur `loyalty_points_awarded` (l.52-60) — exactement-une-fois |
| **Clawback au remboursement** | `clawbackEarnedPoints(324, 190, ...)` → 200 → **10 pts**. Clampé à 0, idempotent (`manual_deduct`) ✅ |
| **Clawback même si NON payée** | `OrderService.php:2466-2485` reprend les points gagnés sur tout état terminal d'annulation (ferme l'exploit « faire préparer et repartir ») ✅ |
| **Calcul de la remise 100 % BACKEND (NF525)** | Le front n'envoie que `points` + `code` ; le € est calculé serveur : `PosRedemptionService.php:111` `round($points / $rate, 2)`. 200 pts → 2,00 € · total 10,00 → **8,00 €** ✅ |
| **Débit == remise obtenue** | 200 pts débités ⇔ 2,00 € accordés ; 100 pts ⇔ 1,00 €. Aucun écart au centime ✅ |
| **Rachat < minimum / non-multiple** | 30 pts → 400 · 150 pts → 400/422 ✅ |
| **Points négatifs** | `-100` → **422** `The points must be at least 1` ✅ |
| **Dépassement du solde** | 10 000 pts sur 190 → **400 « Points insuffisants »** ✅ |
| **Remise > sous-total** | 2 000 pts (20 €) sur commande 10 € → **422 DISCOUNT_EXCEEDS_SUBTOTAL** (garde CUMULATIVE, `PosRedemptionService.php:155-164`) ✅ |
| **Double-rachat même commande, même client** | **409 ALREADY_REDEEMED** (UNIQUE `user_id,order_id,type`) ✅ |
| **Double-clic / 2 onglets (course)** | **6 requêtes concurrentes** de 100 pts sur un solde de 100 : **1 × HTTP 200, 5 × 400 « Points insuffisants »**, solde final **exactement 0**, jamais négatif (`lockForUpdate`) ✅ |
| **IDOR — débiter le compte d'un AUTRE client** | Client C ciblant le code de B → **403 « Non autorisé »**, solde de B inchangé (500). Garde miroir sur les 3 surfaces : `LoyaltyController.php:343-347 & 369-371`, `:805`, `FrontendOrderService.php:1022-1039` ✅ |
| **Rachat sur commande déjà PAYÉE** | **409 ORDER_ALREADY_FINALIZED** ✅ |
| **Permission caisse** | Token sans `pos.redeem-loyalty` → **403** ✅ |
| **Cohérence cross-surface** | Solde 347 identique partout : DB `users.loyalty_points` = 347 · caisse `/loyalty/check` par code = 347 · caisse par téléphone = 347 · site `/api/profile` = 347 · QR signé scanné borne `loyalty_balance_points` = 347 ✅ |
| **Page fidélité — non connecté** | Titre « Connecte-toi pour cumuler », barème affiché « 1 € dépensé = 10 points. 100 points = 1 € de réduction », CTA « Créer mon compte ». Aucun solde inventé ✅ (capture `01-fidelite-non-connecte.png`) |
| **Page fidélité — connecté** | Solde affiché **347 pts == DB 347** · utilisable **3,00 €** (arrondi correct au multiple de 100) · QR signé `lqr.*` avec compte à rebours 5 min · identifiant `ZZTESTC1` réel · 1/4 trophées cohérent · onglet « Mes réductions » honnête (« Présente ton QR en caisse », **aucun débit**) ✅ (capture `02-fidelite-connecte.png`) |

**Captures** : `reports/goal-logique-2026-08-01/screens/01-fidelite-non-connecte.png`, `02-fidelite-connecte.png`, `03-comment-ca-marche.png`

---

## 7. CGV (art. 12) vs CODE

`legal/cgv.html:225-234` :

| Affirmation CGV | Code | Verdict |
|---|---|---|
| « 1 € dépensé = 10 points » | `loyalty_points_per_euro = 10` | ✅ |
| « 100 points = 1 € » | `loyalty_points_for_1_euro_discount = 100` | ✅ |
| « à partir d'un minimum de 50 points » | rachat par pas de 100 → minimum réel **100** | ❌ **P1-1** |
| « Novice 0 → Pepper 500 → Master 1500 → Légende 5000 » | front `screens.jsx:625-630` ; backend expose `[100,250,500,1000,2000]` | ⚠️ **P2-2** (CGV alignées sur le front, pas sur le backend) |
| « Toute commande validée crédite des points » | crédit à `PREPARED`/`DELIVERED`, pas à la validation | ⚠️ formulation imprécise (P3) |
| « Tes points expirent au bout de 12 mois d'inactivité » (`screens.jsx:1051`) | **aucun mécanisme d'expiration** : 0 commande, 0 tâche planifiée, `type='expire'` de l'enum jamais écrit | ⚠️ annonce sans implémentation (favorable au client — non compté) |

---

## 8. ORDRE DE TRAITEMENT RECOMMANDÉ

1. **P0-1** — aligner `LoyaltyService::refundPoints` (`:40-41`) sur le prédicat déjà utilisé au débit (`status ∈ {ACTIVE, 1}`, cf. `PosRedemptionService:124-131` / `LoyaltyController::isCustomerActive:965-969`). 1 ligne, zéro frozen-zone.
2. **P0-2** — refuser un 2ᵉ code fidélité sur une commande portant déjà un `loyalty_customer_code` différent (`PosRedemptionService`), **et** masquer le CTA caisse quand une réduction fidélité existe (`PosOrderShowComponent:558`). Alternative : rembourser par ligne de ledger plutôt que par code de commande.
3. **P1-2** — remonter `status = Status::ACTIVE` sur le compte invité existant dans `GuestSignupController::register` avant l'émission du token.
4. **P1-1** — décision owner : soit `loyalty_min_redeem_points = 100` (aligne la promesse sur la mécanique), soit autoriser un pas plus fin. Répercuter sur `legal/cgv.html:229`.
5. P2-1 / P2-2 / P2-3 / P2-4 — dettes latentes, à traiter **avant** toute activation de `kiosk.promo_enabled`.

⚠️ **Aucune de ces corrections ne touche une frozen-zone ni un invariant NF525** (le redeem est une réduction famille F1, déjà couverte par `ZReportDiscountNettingTest`).
