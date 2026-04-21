# GATE_BRIEF P-MEGA-13 — TPE handshake + multi-tender + idempotence

**Cycle** : P_MEGA_W5_EATIN_TPE_RECEIPT_2026-04-20 — Phase D
**Source** : `reports/execution/AUDIT_P_MEGA_13_TPE_MULTI_TENDER_IDEMPOTENCE_2026-04-20.md`
**Niveau gate** : 🔴 **HUMAN_GATE payments + idempotence + dispatch-after-commit + NF525**
**Décideur attendu** : Owner produit + tech lead + responsable conformité fiscale

---

## Questions business à trancher (3)

1. **Les commandes kiosk doivent-elles être dans le Z signé (NF525) ?** Aujourd'hui : NON — toutes les ventes kiosk sont **invisibles au journal fiscal**.
2. **Multi-tender (split CB+cash+TR) doit-il être implémenté ?** Aujourd'hui : NON — un seul tender par commande.
3. **L'endpoint `payment-confirm` doit-il avoir une Idempotency-Key dédiée ?** Aujourd'hui : NON — protection via `payment_status === PAID` only.

## État actuel (résumé)

- Pas de `OrderService::pay()` unifié — encaissement éclaté entre POS, kiosk, web.
- TPE physique géré côté JS/Electron via bridge `kioskHardware.tpeCharge`.
- `FrontendOrderService::myOrderStore` (kiosk) **ne réserve jamais** `fiscal_sequence_no` → `ZReportService` filtre `whereNotNull('fiscal_sequence_no')` → **kiosk hors Z**.
- POS regenère `idempotency_key` à chaque ouverture du modal paiement → **double-clic → 2 commandes** possibles.
- Aucune table `order_payments` ou `payment_attempts` pour multi-tender ou réconciliation TPE↔DB.

## Risques business concrets

| # | Scénario | Sévérité |
|---|---|---|
| 1 | Z fiscal sous-déclare les ventes kiosk → contrôle URSSAF/DGFiP → écart caisse réelle vs déclaré → **redressement** | 🔴 P0 |
| 2 | Double-clic POS → 2 commandes facturées → **double-débit client** | 🔴 P0 |
| 3 | TPE charged + `payment-confirm` crash → client débité, cuisine silencieuse → **plainte client** | 🔴 P0 |
| 4 | Pas de multi-tender → impossibilité d'encaisser 30€ CB + 5€ cash + 10€ TR → **perte vente** | 🟡 P1 |
| 5 | Refund sans `Transaction` → pas de traçabilité fiscale du remboursement → **audit cassé** | 🟡 P1 |
| 6 | Pas de `correlation_id` TPE↔confirm → **dépannage impossible** sans inspection manuelle logs | 🟢 P2 |

## Options (par ordre de criticité)

### Bloc A — KIOSK FISCAL (P0 absolu, débloque Z signé)
- A.1 : Appeler `FiscalSequenceService::next` dans `finalizePaidKioskOrder` ou `paymentConfirm` (~80 LOC + migration fillable + tests)
- A.2 : Inclure les commandes kiosk dans `ZReportService` (~20 LOC ajustement filtre)
- A.3 : Tests sentinelles concurrentiels `payment-confirm` x2 (~60 LOC PHPUnit)

### Bloc B — IDEMPOTENCE PAYMENT-CONFIRM (P0)
- B.1 : Ajouter header `X-Idempotency-Key` requis sur `payment-confirm` + table `idempotency_keys(branch_id, order_id, key)` ou contrainte unique sur `transaction_id` (~150 LOC)
- B.2 : POS — clé stable par panier UI (PosComponent.vue:1506-1508 fix) (~30 LOC)

### Bloc C — UNIFICATION PAY (P1, refactor)
- C.1 : Créer `OrderService::pay($order, PaymentRequest)` façade transactionnelle (~200-400 LOC)
- C.2 : Migrer kiosk + POS + web → utiliser `OrderService::pay`

### Bloc D — MULTI-TENDER (P1, schema change majeur)
- D.1 : Migration `order_payments(order_id, method, amount, transaction_id, status, created_at)` (~50 LOC)
- D.2 : Validation `sum(payments.amount) === order.total ± 0.01` (~80 LOC)
- D.3 : UX kiosk + POS pour split (~200 LOC)
- D.4 : Z report par méthode de paiement (~50 LOC)
- **Total D** : ~500-700 LOC

### Bloc E — OBSERVABILITÉ & REFUND
- E.1 : `Log::channel('fiscal')` + `correlation_id` payment (~50 LOC)
- E.2 : `Transaction` systématique sur tout refund POS (~80 LOC)

## Recommandation orchestrator

**Phasing prioritaire** :
1. **Bloc A** (kiosk fiscal) — IMPÉRATIF SI prod-ready visé. ~160 LOC. Sans cela, NF525 invalide.
2. **Bloc B** (idempotence payment-confirm) — IMPÉRATIF pour éviter double-débit. ~180 LOC.
3. **Bloc E** (observabilité) — léger, déploiable rapidement, prépare le terrain. ~130 LOC.
4. **Bloc D** (multi-tender) — fonctionnalité business — différable selon roadmap commerciale.
5. **Bloc C** (unification pay) — refactor — peut attendre, ne casse rien actuellement.

**Total Bloc A+B+E** : ~470 LOC, faisable en 2-3 cycles routine + 1 cycle complex (A.1 fiscal sequence touche zone critique).

## Tests sentinelles à créer AVANT fix

1. `test_kiosk_paid_order_has_fiscal_sequence_and_appears_in_z_aggregate` (RED expected)
2. `test_payment_confirm_idempotent_under_concurrency` (RED expected)
3. `test_pos_double_modal_same_cart_single_order` (RED expected)
4. `test_change_payment_status_rejects_duplicate_paid_transition` (RED expected)
5. Vitest : `kioskPaymentConfirmIdempotency.spec.js` (RED expected)

## Décision attendue (matrice)

| Question | Choix |
|---|---|
| Bloc A approuvé ? | ☐ Oui ☐ Non ☐ Différé |
| Bloc B approuvé ? | ☐ Oui ☐ Non ☐ Différé |
| Bloc C (unification pay) priorité ? | ☐ P0 ☐ P1 ☐ Backlog |
| Bloc D (multi-tender) priorité ? | ☐ V1 ☐ V2 ☐ Backlog |
| Bloc E (obs) approuvé ? | ☐ Oui ☐ Non |
| Z partial (POS only) acceptable temporairement ? | ☐ Oui ☐ Non — bloquer prod |
| Cycle de remediation autorisé sans Bloc A ? | ☐ Oui ☐ Non |

## Impact LOC total (selon scope retenu)

- A+B+E : ~470 LOC, 3 cycles
- A+B+C+E : ~870 LOC, 5 cycles
- Tout : ~1500-1700 LOC, 8-10 cycles

## Zones touchées (toutes options)

- `app/Services/FrontendOrderService.php`
- `app/Services/OrderService.php`
- `app/Services/Fiscal/FiscalSequenceService.php` (read)
- `app/Services/Fiscal/ZReportService.php`
- `app/Http/Controllers/Frontend/OrderController.php`
- `database/migrations/*` (NEW)
- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`
- `tests/Feature/Payments/*` (NEW)
