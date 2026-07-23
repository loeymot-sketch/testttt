# Dimension C — MONEY-PATH + NF525 fiscal — findings (ADVERSAIRE, READ-ONLY)

HEAD `b8084b310` · branche `pos/category-first-caisse-2026-06-23` · DB-safe (PHPUnit sqlite :memory:) + lectures read-only sur dev `foodking_e2e`. **Rien modifié.**

## Verdict : GREEN (cœur money-path/NF525 sain). 1 observation P2 réelle, 2 P3.

---

## 1. Prix formule 1,90 / full 2,50 / 2ᵉ sauce +0,50 — PROUVÉ au centime
Query réelle DB (`Item::withoutGlobalScopes`) :
- `Frites Seules`=**1,90** · `Boisson Seule`=**1,90** · `Menu (Frites + Boisson)`=**2,50**.
- 2 chemins CONVERGENT à 1,90 : (a) item standalone (caisse/POS) = prix catalogue 1,90 ; (b) formule borne = 2,50 × `fries_ratio 0.76` = **1,90** exact (pas d'arrondi flottant). `menu_full` = 2,50 × 1.0 = 2,50.
- 2ᵉ sauce **jamais droppée** : `PricingService` 422 fail-loud si extra introuvable (l.177) ; `CompositionSnapshotBuilder:101` **throw** si un extra facturé manque du snapshot (l'ancien seul silent-drop, fermé 2026-07-21). Sauce supplément scellée @0,50.
- Verts : Pricing 21, PosKioskParity 4 (mix viandes 1+1/3/4 identiques POS↔kiosk), Snapshot 6, SauceSupplement 4, MenuRoleAdjusted (0.76→1,90 lu), MultiSauceTicket 12.

## 2. Refunds MP-01/02/03/04 — attaqués, tenus
- **MP-01 (double cash+avoir)** : `cashBack` crédite `users.balance` UNIQUEMENT si gateway≠`cash` (l.154-159) ; cash → sortie tiroir seule. gateway **hard-dérivé** à `'cash'|'credit'` depuis `pos_payment_method` (OrderService:2235/2386) — jamais arbitraire.
- **ATTAQUE split cash+carte → avoir plein + tiroir cash = double** : **RÉFUTÉE**. Un split crée des lignes `order_payments` mais **AUCUN `Transaction`** (SplitPaymentService = OrderPayment only). Le refund tombe donc dans le `elseif payment_status=PAID` (OrderService:2392) → `recordCashRefundMovement` (portion CASH seule, **0 crédit wallet**) + `RefundCreated`. Le chemin `cashBack` (créditeur wallet) exige un `Transaction` ; le counter-collect en a un mais est mono-tender (0 `order_payments` → `refundCashTranchePortion`=0). Aucun chemin double atteignable.
- **MP-03 (sortie tiroir fantôme)** : garde `hasPaymentTranches` empêche le repli-total de s'armer quand des tranches existent sans cash (split carte+mobile, gateway `cash`). Jumelle dans les 2 writers (l.204 + l.692). Verts : `single tender card refund records no cashback out`.
- **MP-02 (ticket split aveugle)** : chaque tranche imprimée (Σ tranches = total). Vert : `split payment prints each tender line`.
- **MP-04 (type d'opération)** : `isRefundReceipt` = status RETURNED + `parent_order_id` → « REMBOURSEMENT » ; vente → « VENTE ». Verts : `refund mirror marks operation remboursement` / `normal sale marks vente`.
- Verts refund : CashBackAtomicity 3, CashHook 8, RefundCounterNettedInZ 4, ReceiptSplitTenderRefund 3, RefundMirrorSplit 3.

## 3. NF525
- `fiscal:verify-chain --all` = **CHAIN OK** sur 4 branches (dev réel). `verify-immutability-triggers` = **10/10** (inclut `orders_no_delete_when_fiscalized`).
- `next()` = `MAX(fiscal_sequence_no)+1` `withTrashed()` (soft-delete safe) + Cache::lock 5s + lockForUpdate + unique DB. Hard-delete reuse (zone faible A) fermé par le trigger BEFORE DELETE (fiscal_sequence_no NOT NULL → SIGNAL 45000 / RAISE ABORT).
- NF525 e2e 9/9 (Z monotone, HMAC chaîné, mirror refund post-Z, destroy-post-Z **409**).

---

## Findings
| # | Sév | Titre | Statut |
|---|-----|-------|--------|
| **C-01** | **P2** | `fiscal:verify-chain` (HMAC) **ne détecte PAS** les trous/réuse de `orders.fiscal_sequence_no`. La continuité gap-free repose ENTIÈREMENT sur (a) le trigger `orders_no_delete_when_fiscalized` **et** (b) un `REVOKE TRUNCATE` au niveau grant DB prod (deploy-doc, hors migration). `verify-sequence-continuity` montre déjà sur dev branche 1 un **trou 2506-2508** + la migration elle-même cite une réuse historique (seq 2579 revendiqué par 6 orders) — pollution **pré-trigger** (orders du 19-20/06, trigger posé 18/07). Action prod : confirmer trigger installé + REVOKE TRUNCATE + câbler le cron `verify-sequence-continuity` en alarme (l'HMAC seul est aveugle à ce trou). | Réel, pré-existant/documenté, mitigé en dev |
| **C-02** | P3 | Migration `set_frites_boisson_seule_price_190` gardée sur `name IN('Frites Seules','Boisson Seule') AND price=2.00` : si le prix n'est pas exactement 2,00 au moment du jeu (renommage/reseed), **no-op silencieux** → seul le chemin borne (ratio) resterait à 1,90. Sur dev : a bien tiré (1,90 confirmé). Data-migration couplée au nom = fragile. | Observation |
| **C-03** | P3 | MP-01 crédite le wallet du **total complet** pour tout refund non-cash. Pour une vente CARTE POS walk-in, l'avoir échoit à `order->user_id` (client par défaut/partagé POS ?) — reversal TPE simulé V1 assumé en commentaire, pas un déséquilibre tiroir/chaîne, mais à revoir au câblage TPE réel. | Design, non-cent |

**Aucun P0/P1.** Écart panier↔scellé↔ticket : **0** trouvé. ~99 tests DB-safe verts + chaîne/triggers/prix réels vérifiés.
