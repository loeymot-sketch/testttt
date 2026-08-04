# RED-team — CUMUL DE POINTS FIDÉLITÉ (earn) + CLAWBACK

**Date** : 2026-08-04 · **Mode** : READ-ONLY adversarial · **Branche** : `pos/category-first-caisse-2026-06-23` (HEAD `0c7bca42f`)
**Périmètre** : `AwardLoyaltyPointsOnDelivery` · `ClawbackLoyaltyPointsOnRefund` · `LoyaltyService::clawbackEarnedPoints` · `LoyaltyTransaction` · `users.loyalty_points` / `orders.loyalty_points_awarded`

**Harnais de reproduction** : sqlite `:memory:`, migrations réelles, listeners/jobs RÉELS (aucun mock), taux `loyalty_points_per_euro=10`, commandes 30,00 € → 300 pts = 3,00 € de valeur client (`loyalty_points_for_1_euro_discount=100`).
Scripts : `<scratchpad>/repro-loyalty.php`, `repro2.php`, `repro3.php`. Zéro écriture dans la base du projet.

---

## Tableau de bord

| # | Attaque | Verdict | Fuite |
|---|---------|---------|-------|
| A1 | Double award (sentinelle -1 concurrente) | **REFUTED** (+ P2 dérivé : perte at-most-once) | — |
| A2 | Award sur commande annulée / RETURNED / non payée | **P1-1** + **P1-2** + **P1-3** | maison → client |
| A3 | Assiette du cumul (TTC / net remise / net fidélité) | **REFUTED** (+ P2 : livraison créditée) | — |
| A4 | Clawback au refund (symétrie, négatif, double) | **P0-1** | maison → client |
| A5 | Grand-livre vs solde (colonne) | **REFUTED** (+ P2 : désync commande↔grand-livre) | — |
| A5b | Changement de taux entre commande et DELIVERED | **P2-3** | promesse ≠ crédit |
| A6 | Idempotence award / fenêtre de revert de sentinelle | **REFUTED** pour le double-award, **P2-1** pour la perte | client → néant |

**Verdict global : 1 P0 · 3 P1 · 3 P2 · 3 REFUTED.**

---

## [P0-1] `LoyaltyService.php:180` — le clawback exige `status=ACTIVE`, l'award n'exige RIEN : compte legacy `status=1` ou compte désactivé = points gagnés JAMAIS repris au remboursement

**file:line**
- Débit/clawback filtré : `app/Services/LoyaltyService.php:179-187`
  ```php
  $query = User::where('id', $userId)
      ->where('status', \App\Enums\Status::ACTIVE);   // ACTIVE = 5, STRICTEMENT
  ...
  $user = $query->first();
  if (!$user) { return; }                              // silence total : ni log, ni ledger, ni exception
  ```
- Award SANS aucun filtre de statut : `app/Listeners/AwardLoyaltyPointsOnDelivery.php:66-74`
  ```php
  $user = User::where('loyalty_code', $order->loyalty_customer_code)->first();  // n'importe quel status
  ```
- Le `return` muet est **avalé deux fois** : `clawbackEarnedPoints` retourne sans rien écrire, puis `ClawbackLoyaltyPointsOnRefund.php:76-81` ne teste pas le retour (void) — aucune alerte n'est possible.

**Scénario**
1. Client legacy (`users.status = 1`) ou client désactivé par l'admin après sa commande (`status = 10`).
2. Commande 30 € → DELIVERED → award = 300 pts (l'award ne regarde pas `status`).
3. Remboursement (caisse, Stripe, counter-entry — n'importe quel `RefundCreated`).
4. `clawbackEarnedPoints` ne trouve pas d'utilisateur `ACTIVE` → `return` silencieux.
5. Le client garde 300 pts = **3,00 € de remise** sur une commande intégralement remboursée. **Répétable à volonté.**

**Preuve (repro exécuté)**
```
== P1-A: legacy status=1 ==
after DELIVERED earn: balance=300 awarded=300
after RefundCreated: balance=300 manual_deduct_rows=0
>>> CONFIRMED : refund left 300 pts on legacy account (no clawback row)

== Control ACTIVE(5) ==
after earn: 300 / after refund: 0     <- le chemin nominal fonctionne

===== A4 : compte DÉSACTIVÉ entre earn et refund =====
earn -> 300 pts ; status=10 ; refund -> balance=300 manual_deduct=0

===== A4bis : earn accepte TOUT statut =====
compte INACTIVE(10) -> earn balance=300 (earn ne filtre pas)
puis refund -> balance=300 (clawback exige ACTIVE)
```

**Le legacy `status=1` n'est pas hypothétique — le repo le documente lui-même :**
- `app/Services/Loyalty/PosRedemptionService.php:124-130` : *« Fallback : status=1 legacy (matches LoyaltyController::isCustomerActive) »* — le **débit** de points accepte explicitement `status=1`.
- `app/Http/Controllers/Frontend/LoyaltyController.php:971-975` : `isCustomerActive()` = `status === 1 || status === ACTIVE`.
- `app/Services/LoyaltyService.php:54-58` : le heal **P0-1 du 2026-08-01** a retiré ce même filtre de `refundPointsToOwner` avec le motif *« Filtrer plus strictement ICI détruisait les points de ces clients »*.
- `tests/Feature/Loyalty/KioskLoyaltyEarnCycleProofTest.php:29` crée son client avec `'status' => 1`.

**C'est exactement le P0-1 du 2026-08-01, sur la fonction jumelle, jamais corrigée.** Le heal a traité `refundPointsToOwner` (remboursement de rachat) et laissé `clawbackEarnedPoints` (reprise de cumul) avec le filtre. Le miroir fuit dans l'autre sens : le premier détruisait l'argent du client, celui-ci donne l'argent de la maison.

Aucun test existant ne couvre le cas : `LoyaltyClawbackOnRefundSentinelTest.php:51` et `LoyaltyRefundPointsIdempotentTest.php:45` créent tous leurs clients en `Status::ACTIVE`. La suite est **aveugle par construction**.

**Repro** : `php repro-loyalty.php` (bloc P1-A) — ou en test :
```php
$c = User::factory()->create(['loyalty_code'=>'L1','loyalty_points'=>0,'status'=>1]);
$o = Order::factory()->create(['total'=>30,'status'=>OrderStatus::DELIVERED,'loyalty_customer_code'=>'L1','loyalty_points_awarded'=>null]);
(new AwardLoyaltyPointsOnDelivery)->handle(new OrderStatusChanged($o->fresh(), 7, 13));
RefundCreated::dispatchNow($o->fresh());
// attendu 0 — observé 300
```

---

## [P1-1] `AwardLoyaltyPointsOnDelivery.php:33` + `:55` — la garde ne couvre que CANCELED : une commande **RETURNED (remboursée)** peut encore être créditée

**file:line**
```php
// :32-35  garde mémoire
if ($currentStatus === OrderStatus::CANCELED) { return; }        // 16 seulement
// :52-56  garde SQL
->where('status', '!=', OrderStatus::CANCELED)                    // 16 seulement
```
`OrderStatus` (`app/Enums/OrderStatus.php`) compte **trois** états terminaux : `CANCELED=16`, `REJECTED=19`, `RETURNED=22`. Deux sur trois passent la garde.

**Scénario reproductible (interleaving réel, pas théorique)** — bump cuisine vs remboursement caissier :
- `app/Services/KitchenDisplaySystemOrderService.php:625` capture `$snapshot = $result['model']` **dans** la transaction, puis dispatche `OrderStatusChanged` **après le commit**, à la ligne `:645`, *derrière trois dispatch de jobs* (`:637-639` SendOrderMail/Sms/Push). La fenêtre entre le commit et l'event est de plusieurs dizaines de ms.
- Pendant cette fenêtre, le caissier rembourse : `PREPARED → RETURNED` est **légal** (`OrderStateMachine.php:66-70`, permission `pos-refund`).
- `RefundCreated` part, `ClawbackLoyaltyPointsOnRefund.php:51-55` lit `loyalty_points_awarded = null` → `return` (rien à reprendre).
- L'event différé arrive enfin. `status` en base = RETURNED(22) ≠ CANCELED(16) → **la garde SQL passe** → 300 pts crédités sur une commande remboursée, alors que le clawback est déjà passé. **Définitif.**

**Preuve**
```
(a) KDS commit  : status=8 awarded=NULL
(b) refund      : clawback voit awarded=NULL -> manual_deduct=0 (NOOP)
(c) award tardif: status EN BASE=22 (RETURNED) -> awarded=300 | solde client=300
>>> CONFIRMÉ : 300 pts (3,00 EUR) crédités sur une commande REMBOURSÉE
```
Variante REJECTED également reproduite (`repro2.php` bloc A2bis : `status=REJECTED(19)` + `newStatus=PREPARED` → 300 pts).

**Repro** : `php repro3.php` bloc A2-RACE.

---

## [P1-2] `CleanupStalePendingKioskOrders.php:353-410` — le janitor purge une commande borne **jamais payée** sans reprendre les points gagnés

**file:line**
- `app/Jobs/CleanupStalePendingKioskOrders.php:105-118` : la lane « phantom PREPARED » sélectionne `status=PREPARED` + `payment_status ∈ {UNPAID, PENDING_COUNTER}` + `source_surface='kiosk'`.
- `:353-410` `softDeleteStalePreparedPhantom()` : appelle `$locked->delete()` (soft-delete) et **n'appelle NI `refundPoints` NI `clawbackEarnedPoints`** — contrairement à la lane jumelle `cleanupStaleDeferredOrder()` qui, elle, appelle `refundPoints` (`:288`).

**Scénario** — c'est l'exploit *« scanner QR + faire préparer + repartir sans payer »* que `OrderService.php:2455-2465` a fermé sur le chemin **interactif** (annulation caisse), resté ouvert sur le chemin **automatique** :
1. Commande borne Plan B avec code fidélité, `PENDING_COUNTER` (impayée).
2. La cuisine la passe PREPARED → `AwardLoyaltyPointsOnDelivery` crédite **sans tester `payment_status`** (comportement assumé, cf. commentaire `OrderService.php:2452-2455`).
3. Le client part sans payer.
4. Le cron purge après TTL (défaut 180 min) → soft-delete.
5. **300 pts conservés, aucune ligne `manual_deduct`, commande disparue.** Répétable une fois par TTL.

**Preuve**
```
PREPARED (payment_status=PENDING_COUNTER, JAMAIS payée) -> balance=300 awarded=300
purge: deleted_at='2026-08-04 05:32:13' | balance=300 | manual_deduct=0
>>> CONFIRMÉ : commande jamais payée purgée, client garde 300 pts (= 3.00 EUR) — répétable
```
Le commentaire de `OrderService.php:2458-2462` décrit précisément ce trou (« *si la commande est ensuite annulée SANS avoir été payée…* ») et le referme — mais uniquement dans `changeStatus`. Le job ne passe pas par `changeStatus`.

**Repro** : `php repro2.php` bloc A2/A4-B.

---

## [P1-3] Lane manquante : commande **site web** impayée bloquée à PREPARED — ni purgée, ni reprise

**file:line**
- `app/Listeners/AwardLoyaltyPointsOnDelivery.php:37-43` : `TAKEAWAY(10)` est traité comme borne → award dès **PREPARED**. Or `app/Services/FrontendOrderService.php:610` documente que **« le site web envoie `order_type=TAKEAWAY(10)` »** → toute commande web à emporter est créditée à PREPARED, avant encaissement.
- `app/Jobs/CleanupStalePendingKioskOrders.php:110` : la lane PREPARED exige `source_surface='kiosk'` → ignore le web.
- `:193` : la lane web couvre `PENDING/ACCEPT/PREPARING` → **exclut PREPARED** (commentaire `:185-186` : *« PREPARED exclu (PREPARED→CANCELED illégale) »*).

**Scénario** : commande web `PENDING_COUNTER` → cuisine PREPARED → award 300 pts → client ne vient jamais. Aucune lane ne la voit : elle reste **PREPARED impayée à vie** (stock déplété, file caisse polluée) **et** les 300 pts sont acquis.

**Preuve**
```
web order_type=TAKEAWAY(10) => branche 'kiosk' du listener (l.37) => award dès PREPARED, IMPAYÉE
solde après PREPARED = 300
après janitor : status=8 deleted_at=NULL solde=300
>>> CONFIRMÉ : commande web impayée immortelle + 300 pts gardés
```

**Repro** : `php repro3.php` bloc A2-WEB.

---

## [P2-1] `AwardLoyaltyPointsOnDelivery.php:52-56` — sentinelle `-1` bloquée = cumul perdu à vie, aucun reaper

Si le process meurt entre la revendication `-1` (`:52-56`) et la finalisation (`:139-141`) sans passer par le `catch` (SIGKILL, timeout worker, OOM, coupure réseau DB), la ligne reste à `-1` **définitivement** :
- tout rejeu est bloqué (`whereNull(...)` ne matche plus) → **le client ne reçoit jamais ses points** ;
- `ClawbackLoyaltyPointsOnRefund.php:51-55` ignore `-1` (`$awarded <= 0`) → cohérent, pas de fuite ;
- **aucun cron ne balaie les `-1`** : `grep -rn "loyalty_points_awarded" app/` ne retourne aucun reaper. Le seul nettoyage a été un backfill *ponctuel* de migration (`database/migrations/2026_05_11_010000_fix_orders_loyalty_points_awarded_signed.php:53-54`), joué une seule fois.

**Preuve** : `rejeu DELIVERED sur sentinelle -1 -> balance=0 (cumul perdu à vie)`.
Même forme sur le chemin `catch` (`:155-158`) : il remet `null`, mais l'event a déjà été consommé par le dispatcher **synchrone** (le listener n'est pas `ShouldQueue`) → pas de retry. Sémantique réelle = **at-most-once**, pas exactly-once.

## [P2-2] `AwardLoyaltyPointsOnDelivery.php:98-102` — les points sont calculés sur le total **frais de livraison inclus**

`$orderTotal` = `orders.total`, qui vaut `subtotal + tax + delivery_charge − discount` (`app/Services/Pricing/PricingService.php:351-353`). Le client cumule donc sur les frais de livraison.
**Preuve** : `subtotal=20.00 remise=5.00 livraison=4.00 total=19.00 -> points=190` (sur produits nets 15,00 € on attendrait 150). 4 € de livraison = **40 pts = 0,40 € rendus** par commande livrée. Décision produit à trancher owner, pas un bug de code.

## [P2-3] `AwardLoyaltyPointsOnDelivery.php:84` — le taux est lu **au DELIVERED**, pas à la commande

`Settings::group('loyalty_setup')->get('loyalty_points_per_euro')` est évalué au moment de l'attribution. Or l'écran de confirmation borne affiche le gain **immédiatement après commande** avec le taux du moment : `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue:207-213` (`Math.floor(total * rate)`).
**Preuve** : `taux 10 à la commande -> 1 au moment du DELIVERED -> points=30 (promis: 300)`. Aucun snapshot du taux sur la commande. Impact faible (le taux bouge rarement) mais la promesse affichée est démentie sans trace.

---

## REFUTED

### [REFUTED] A1 — double award par course sur la sentinelle
La revendication est un **UPDATE conditionnel unique** (`AwardLoyaltyPointsOnDelivery.php:52-56`) : `UPDATE orders SET loyalty_points_awarded=-1 WHERE id=? AND loyalty_points_awarded IS NULL AND status<>16`. Un seul processus obtient `$updated=1` (verrou de ligne InnoDB ; sqlite mono-writer). Vérifié : `updated1=1 updated2=0`.
Contrôles complémentaires : `shouldDiscoverEvents()` retourne `false` (`EventServiceProvider.php:360-363`) → pas de double enregistrement du listener ; aucun `Event::listen` concurrent ; le listener est enregistré **une seule fois** (`:157`) ; **un seul** chemin écrit `type='earn'` avec mutation de solde (`grep "'earn'"` → seule autre occurrence = `LoyaltyController.php:620`, lecture d'historique).
Les rollbacks vers `null` (`:76-79`, `:86-89`, `:105-108`, `:155-158`) sont tous gardés par `where('loyalty_points_awarded', -1)` : un award déjà finalisé (valeur > 0) ne peut pas être ré-ouvert. **Aucun double-crédit démontrable.** Le défaut réel est l'inverse (perte) → P2-1.

### [REFUTED] A3 — cumul sur des points déjà dépensés (inflation)
`orders.total` est **net de TOUTE remise, remise fidélité incluse** : la remise fidélité est agrégée dans `$calculatedDiscount` (`PricingService.php:333-353` ; borne : `FrontendOrderService.php:562-564`, POS : `PosRedemptionService.php:215-230` recalcule `total` après rachat). Un client qui rachète 5 € de points sur 20 € cumule sur 15 €, pas sur 20 €. **Pas d'inflation.**
Note annexe : le commentaire `AwardLoyaltyPointsOnDelivery.php:93-101` affirme que « Order (POS) uses `order_amount` ». **La colonne `order_amount` n'existe dans aucune migration ni modèle** (`grep -rn "order_amount" database/ app/Models/` → 0 résultat). L'expression `$order->order_amount ?? $order->total` retombe toujours sur `total` — inoffensif aujourd'hui, mais le commentaire est faux et induit en erreur.

### [REFUTED] A5 — dérive colonne `users.loyalty_points` vs grand-livre
Les 8 chemins de mutation écrivent solde **et** ligne de grand-livre dans la même transaction :
`AwardLoyaltyPointsOnDelivery.php:115-142` · `LoyaltyService.php:102-117` (refund) · `:193-206` (clawback) · `:324-339` (reaper) · `LoyaltyController.php:277-290` (addPoints) · `:397-412` (redeem) · `FrontendOrderService.php:1113-1145` (redeem borne) · `PosRedemptionService.php:180-206` (redeem POS).
Contrôle exécuté sur 7 clients du harnais : `ledger == colonne` **partout** (`OK` ×7).
Deux réserves qui ne sont pas des dérives aujourd'hui : (a) `LoyaltyController.php:277-283` conditionne l'écriture du grand-livre à `Schema::hasTable('loyalty_transactions')` — la table existe, mais la garde crédite le solde sans trace si elle disparaissait ; (b) `LoyaltyService.php:106` calcule `balance_after` à partir d'une lecture antérieure à l'`increment` — correct sous `lockForUpdate` MySQL (`:60-62`), donc pas exploitable.
**La vraie désynchronisation prouvée n'est pas colonne↔grand-livre mais commande↔grand-livre** : après un clawback sauté (P0-1) ou une purge (P1-2), il subsiste une ligne `earn +300` sans contrepartie pour une commande remboursée ou soft-deletée, avec `orders.loyalty_points_awarded=300` intact. L'audit fidélité affiche un cumul légitime pour une vente qui n'existe plus.

### [REFUTED] A4 — solde négatif / double clawback
`clawbackEarnedPoints` clampe à 0 (`LoyaltyService.php:190` `max(0, ...)`) et journalise le montant **réellement** déduit (`:191`, `:202`) → le grand-livre reste cohérent avec la colonne même en clamp. Double reprise impossible : pré-check d'existence (`:165-176`) + index UNIQUE `(user_id, order_id, type)` (migration `2026_03_26_075919`). Vérifié par `LoyaltyClawbackOnRefundSentinelTest` (3 cas) et re-exécuté ici. **Le seul trou de symétrie est le filtre de statut → P0-1.**
Réserve documentée non testée ici : remboursement **partiel** → reprise du montant **intégral** (`ClawbackLoyaltyPointsOnRefund.php:30-35`, backlog V1.0.2 assumé).

---

## Verdict

**BLOCK sur le money-path fidélité.** Le cumul est solide côté assiette (net de remise, pas d'inflation) et côté unicité (sentinelle réellement atomique, un seul chemin de crédit). **La reprise, elle, fuit par quatre trous distincts, tous dans le même sens : la maison paie.**

Un même défaut structurel les relie : **l'award est permissif (aucun filtre de statut client, aucun filtre de paiement, garde terminale partielle) alors que le clawback est restrictif et muet** (`ACTIVE` strict, `return` sans log, valeur de retour ignorée par l'appelant). Chaque chemin d'annulation ajouté depuis 2026-07 (`changeStatus`, `RefundCreated`, janitor, counter-cancel) a été câblé séparément — trois l'ont été, le soft-delete du janitor et la lane web ne l'ont pas été.

**Ordre de traitement recommandé**
1. **P0-1** — aligner `clawbackEarnedPoints` sur `refundPointsToOwner` (identification par `user_id`, aucun filtre de statut) ; le heal du 2026-08-01 est le patron exact. Une ligne. Sentinelle de non-régression obligatoire avec un client `status=1` **et** un client `status=10`.
2. **P1-2 / P1-3** — appeler le clawback dans `softDeleteStalePreparedPhantom` ; ouvrir une lane janitor pour le web PREPARED impayé.
3. **P1-1** — étendre les deux gardes à `[CANCELED, REJECTED, RETURNED]` (mémoire `:33` et SQL `:55`).
4. **P2-1** — cron de balayage des sentinelles `-1` plus vieilles que N minutes (remise à `null` + re-award, ou alerte).

**Aucun de ces quatre correctifs ne touche une frozen-zone ni la chaîne NF525** : la fidélité est hors chaîne fiscale (aucune écriture `audit_logs` / `z_reports` / `fiscal_sequence_no` dans les chemins concernés).

**Gate owner requis** sur P2-2 (les frais de livraison doivent-ils générer des points ?) et P2-3 (figer le taux à la commande ou assumer le taux à la livraison ?).
