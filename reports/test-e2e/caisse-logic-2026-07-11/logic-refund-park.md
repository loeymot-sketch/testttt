# Audit adversaire — LOGIQUE REMBOURSEMENT / ANNULATION / PARK-RECALL

Date : 2026-07-11 · DB live `foodking_e2e` (mysql, :8766) · Auth acteur `user 67` (Admin+BM+POS, `can(pos-refund)=Y`).
Méthode : lecture code + repro tinker réelle, chaque scénario dans `DB::beginTransaction()` … `rollBack()` (aucune donnée live modifiée ; triggers `*_no_delete` respectés).
Fichiers audités : `RefundWithCounterEntryService.php`, `PosParkedOrderService.php` + modèle `PosParkedOrder`, `OrderStateMachine.php` (FROZEN — audit), `PaymentService.php`, `OrderService::{changeStatus,destroy}`, `SealedOrderGuard.php`, `FiscalSequenceService.php`, `ParkedOrderController.php`.

---

## VERDICT : 1 défaut réel P2 + 1 P3 + 1 lacune de capacité. Les chemins argent/fiscal PRINCIPAUX sont corrects.

---

## FINDING 1 — P2 (cash-trail) — Sortie tiroir FANTÔME sur remboursement NON-espèces
`app/Services/PaymentService.php:176-178` (`cashBack()` → `recordCashBackMovement`), méthode `:635-673`.

### Repro (réel, rollback)
Commande `order_type=DELIVERY`, `pos_payment_method=CARD`, PAYÉE, AVEC une ligne `Transaction(type=payment)` (paiement en ligne Stripe/wallet), fiscalisée. Caissier `user 67` a une session tiroir OUVERTE. Remboursement pré-Z via `OrderService::changeStatus → RETURNED` :
```
order has_txn=Y pos_pm=CARD order_type=DELIVERY
RESULT non-cash refund w/ Transaction: drawer delta=-10
  cash_movements on order: cashback/out/10.00   <-- FANTÔME
  customer wallet credited? balance now=10.00
```
Un `CashMovement TYPE_CASHBACK / OUT / 10,00` est écrit alors qu'AUCUNE espèce n'a quitté le tiroir (vente carte/en ligne).

### Cause
`cashBack()` appelle `recordCashBackMovement()` **inconditionnellement** pour tout `$order instanceof Order`, SANS garder sur `pos_payment_method === CASH`. Or TOUS les chemins frères posent ce garde :
- chemin cash direct pré-Z : `OrderService.php:2311` garde `pos_payment_method === CASH`.
- miroir post-Z tranches : `RefundWithCounterEntryService.php:273` garde `mode === CASH`.
- miroir post-Z single-tender : `RefundWithCounterEntryService.php:336` garde `pos_payment_method === CASH`.
Seul `cashBack()` (chemin des commandes portant une `Transaction`, c.-à-d. les ventes EN LIGNE) a été oublié.

### Impact
Au rapprochement (`CashDrawerService::reconcileSession`, `expected = opening + Σ signedAmount`), l'`expected` est sous-évalué du total remboursé → variance = fausse SURPLUS d'espèces inexpliquée pour le caissier. Le Z SIGNÉ reste juste (il agrège les commandes, pas `cash_movements`) ; c'est la surface de rapprochement tiroir qui devient incohérente. Déclencheur réaliste Le Cayenne : un manager/caissier avec tiroir ouvert rembourse/annule une commande de livraison payée en ligne. (Auto-annulation client = pas de session ouverte pour le client → pas de fantôme.)

### Fix (non-frozen — `PaymentService` hors frozen-zone)
Garder l'appel `recordCashBackMovement` dans `cashBack()` sur `($order->pos_payment_method ?? null) === PosPaymentMethod::CASH`, exactement comme les 3 chemins frères. Un vrai remboursement carte/en ligne ne doit pas mouvementer le tiroir espèces. (Le crédit `user->balance += total` — modèle legacy de remboursement-vers-wallet — reste hors périmètre.)

---

## FINDING 2 — P3 (edge) — `recall` ne valide PAS la disponibilité de l'ITEM de base
`app/Services/PosParkedOrderService.php:113-193` (`pruneUnavailableParkedVariations`).

### Repro (réel, rollback)
```
CASE A base-item soft-deleted : recall=OK kept_lines=1 var_kept=1 warn={"unavailable_variations":[]}   <-- ligne périmée rendue SILENCIEUSEMENT
CASE B variation soft-deleted : var_kept=0 warn=[{item_id:23,variation_id:5,name:"Poulet mariné"}]      <-- correct
```
Un produit entier soft-supprimé (ou `status != ACTIVE`) entre le park et le recall : `Item::find($itemId)` renvoie `null` → la boucle fait `continue` → la ligne + ses variations sont conservées TELLES QUELLES, **sans warning**. Seules les VARIATIONS sont validées, et uniquement quand l'item de base est encore trouvable.

### Impact
Le recall « ne casse pas » (pas d'exception) mais réinjecte dans le panier caisse une ligne pointant un produit non-vendable, sans signal. Risque réel borné par la validation de prix au re-submit (surface distincte). C'est le P3 mentionné au contrat. Faible sévérité (nécessite suppression/désactivation produit pendant qu'un ticket est garé).

### Fix suggéré
Dans le prune, si `Item::find` est `null` OU `status != ACTIVE` OU `trashed()`, retirer la ligne entière et pousser un warning `unavailable_item` (symétrique du warning variation existant).

---

## FINDING 3 — INFO (lacune de capacité, pas un bug) — Remboursement PARTIEL impossible
`RefundWithCounterEntryService::execute` négative TOUJOURS le parent COMPLET (total/subtotal/tax + toutes les lignes ×-1). Aucun paramètre de sélection de ligne (`item_ids`/`line_ids`/`partial`) n'existe ni dans le service ni dans `PosOrderController`. Le chemin pré-Z (`changeStatus→RETURNED`) rembourse aussi la commande entière. → **« rembourser 1 ligne sur 3 » n'est pas réalisable**. À décider produit (V1 = remboursement total uniquement).

---

## CE QUI EST CORRECT (prouvé, repro réelle)

| # | Scénario | Résultat |
|---|----------|----------|
| Q1a | Remboursement CASH pré-Z | `status→RETURNED(22)`, tiroir `-10,00` (juste), `fiscal_seq` **préservé** (gap-free), audit `order.returned=1`. OK |
| Q1b | Remboursement CASH post-Z (miroir) | miroir `total=-10`, `RETURNED/REFUNDED`, `fseq` frais, `parent_order_id` lié, **parent immuable** (reste PREPARED), items qty×-1, tiroir `-10,00`. OK |
| Q3 | Annulation/soft-delete d'une commande fiscalisée | `FiscalSequenceService::next` = `MAX(withTrashed)+1` → n° A retenu, B contigu → **AUCUN gap, aucune réutilisation**. OK |
| Q5 | Double-recall même ticket | 2e recall = `NULL` (consume-once via `lockForUpdate` + `delete` in-tx). Isolation par opérateur (user3 ≠ user6). OK |
| Q6 | `PAID → REFUNDED` (PaymentStateMachine) | rejeté (`InvalidArgumentException`). Le parent pré-Z reste PAID by-design (le marqueur remboursé = `status=RETURNED`). OK |

Observation (non-défaut) : le double-terminal post-Z (miroir puis CANCELED en place → double-négatif Z) est bien bloqué par `SealedOrderGuard::assertMutable` sur tout état terminal fiscalisé (`OrderService.php:2277`). `cashBack()` est idempotent (early-return sur `type=cash_back` existant) → pas de double sortie.
