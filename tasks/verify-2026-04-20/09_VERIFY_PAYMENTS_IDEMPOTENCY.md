# VERIFY-09 — Paiements / Idempotence (double-order, double-payment, Idempotency-Key)

**Date :** 2026-04-20  **Origine :** `AUDIT_POS_110_PAYMENTS_REFUND_*`, `F-SYNC-002`  **Priorité :** P0  **Mode :** AUDIT-ONLY

## 1. Contexte
Risque identifié : un caissier qui clique 2× → 2 commandes ; un retry réseau qui re-poste → double paiement. Le tracker mentionne `pos-orders` sans `Idempotency-Key` scoped `(branch_id, key)`.

## 2. Sources OBLIGATOIRES
- `app/Http/Controllers/Admin/PosOrderController.php`
- `app/Http/Middleware/*` (recherche idempotency)
- `app/Services/OrderService.php` (posOrderStore + changePaymentStatus)
- `app/Services/PaymentService.php`
- Front POS : `PosComponent.vue`, `PaymentComponent.vue` (anti double-clic)
- Tests : recherche `Idempotency`, `concurrent`, `double`
- Audit : `AUDIT_POS_110_PAYMENTS_REFUND_2026-04-19.md`, `AUDIT_POS_SECTION_4_BACKEND_2026-04-18.md`

## 3. Hypothèses à challenger
- H1 : Pas de middleware `idempotency-key` actif.
- H2 : `changePaymentStatus` peut être appelé 2× sur le même order avec succès.
- H3 : Front n'a pas de garde anti double-clic / retry visible.
- H4 : `orderId` réutilisé entre 2 sessions.
- H5 : Transaction DB ne couvre pas Order + Payment + Outbox.

## 4. Plan multi-agent
1. **Explore A** : back — middlewares, contrôleurs, services, transactions.
2. **Explore B** : front — anti double-soumission, retries axios.
3. **GeneralPurpose** : matrice scénario × protection (DB unique, middleware, front debounce, lock applicatif).

## 5. Vérifications obligatoires
- [ ] V1 : Existe middleware `Idempotency-Key` scoped `(branch_id, user_id, key)` avec TTL.
- [ ] V2 : Header obligatoire sur `posOrderStore` et `changePaymentStatus`.
- [ ] V3 : Tests Feature qui prouvent : 2× POST avec même key → 1 seule commande, 2× sans key → 2 (ou 422).
- [ ] V4 : Front POS débounce le bouton "Encaisser" (data-testid + aria-disabled).
- [ ] V5 : Transaction DB ouverte avant insert order, fermée après audit log.
- [ ] V6 : `payment_status` transitions guardées (PENDING → PAID, pas de PAID → PAID).
- [ ] V7 : Outbox écrit dans la même transaction (cf. `PersistOrder*ToOutbox`).

## 6. Critères d'acceptation
- ALL_GREEN si V1–V7 OK.
- WARN si V4 manquant.
- FAIL si V1 absent ou V6 cassable.

## 7. Livrables
- `reports/review/VERIFY_09_PAYMENTS_IDEMPOTENCY_2026-04-20.md`

## 8. Suite
- FAIL → `P11_IDEMPOTENCY_KEY_MIDDLEWARE`, `P12_POS_DOUBLE_SUBMIT_FRONT`.

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY.
Lis tasks/verify-2026-04-20/09_VERIFY_PAYMENTS_IDEMPOTENCY.md, applique §4-§7.

OBLIGATIONS: 2 explore parallèles (A back, B front) + 1 generalPurpose synthèse + matrice scénarios. 0 code modifié.
Livrable: reports/review/VERIFY_09_PAYMENTS_IDEMPOTENCY_2026-04-20.md
Plan 5 lignes. Conclusion "GLOBAL: ..." + cycles P.
```
