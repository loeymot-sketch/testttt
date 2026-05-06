# SPLIT PAYMENT — Implementation Report (2026-05-06)

**Mission** : CV1-POS-SPLIT-PAYMENT-001
**Cycle** : 6 (suite cycles POS audit 1-5, 38/38 PASS)
**Statut** : 🟢 FRONTEND LIVRÉ — backend en attente PLAN_P12

---

## 1. Synthèse

Le système POS FoodKing acceptait jusqu'ici **un seul mode de paiement** par facture (cash XOR card). User demande un **multi-paiement** :
- paiement partiel (ex 25 € = 10 € cash + 15 € card)
- division entre N personnes (parts égales OU items assignés — V1 = parts égales)
- calcul retour de monnaie par tranche cash
- vérification montant reçu suffisant pour chaque tranche cash

**Frontend complet et autonome** est livré dans ce cycle. Le backend persiste encore en single-tender (frozen-zone respectée). Le payload `payment_breakdown[]` est attaché à toute soumission multi-paiement et sera consommé par le backend une fois `PLAN_P12` exécuté (Cursor/Codex).

---

## 2. Frontend implémenté

### 2.1 Helpers purs

| Fichier | Lignes | Rôle |
|---|---:|---|
| `resources/js/helpers/posSplitPayment.js` | ~190 | Cents-arithmetic + validation + `splitEqually` + `serializeTranches` + `canConfirm` (zero Vue dépendance, testable Vitest) |
| `resources/js/helpers/posReceiptBuilder.js` | +50 | Extension `buildPaymentBreakdownLines(tranches)` + `sumPaymentBreakdownTotal` pour preview pré-paiement |

**Décisions clés** (helpers) :
- **Cents int math** : tout passe par `Math.round(x * 100)` pour éviter la dérive flottante (`30.01 € / 3 → 10.00 / 10.00 / 10.01` exact, jamais `10.0033333…`).
- **canConfirm** : 1 ct slack côté reste-dû (rounding noise) ; 1 € overpay toléré côté covered.
- **splitEquallyCents(total, n)** : remainder cents poussés sur la dernière tranche (canonique).
- **serializeTranches** : forme API stable `{ mode, amount, tendered, change, note }` — frozen-zone backend pourra consommer sans renégocier le contrat.

### 2.2 Atom V5

| Fichier | Lignes | Rôle |
|---|---:|---|
| `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue` | ~310 | Row éditable d'une tranche (mode select / amount / tendered / change live / ✕). Émet `update(patch)` + `remove`. |

Design :
- POS V5 tokens uniquement (`--pos-v5-*`).
- `aria-live="polite"` sur le champ change.
- `role="alert"` sur le message de validation.
- testids stables : `pos-payment-tranche-row-{i}`, `pos-payment-tranche-amount-{i}`, `pos-payment-tranche-tendered-{i}`, `pos-payment-tranche-remove-{i}`.

### 2.3 PaymentComponent.vue — refonte additive

**Stratégie** : zéro modification des paths cash/card historiques. Le mode `multi` est un nouveau branch v-if.

Changements :
1. Segmented control 2-cols → 3-cols (`pos-v5-payment-methods--3col`) avec nouveau bouton `🔀 Multi-paiement`.
2. Switch local `paymentMode: 'cash' | 'card' | 'multi'` (data) — découplé de `props.form.pos_payment_method` qui reste piloté par les paths historiques.
3. **Mount-time sync** : `mounted()` + `watch('props.form.pos_payment_method')` → `syncPaymentModeFromForm()` mappe CARD→`'card'`, sinon→`'cash'`. Évite la désync UI quand le modal est rouvert avec un `pos_payment_method` non-CASH (re-load form depuis localStorage).
4. Bloc `<div v-if="paymentMode === 'multi'">` ajouté entre Numpad et bouton Confirmer :
   - Summary aria-live : Couvert / Reste dû / Monnaie totale
   - "Diviser entre N personnes" (input + bouton)
   - Liste de tranches (`v-for` sur `PosV5TrancheRow`)
   - Bouton `+ Ajouter une tranche`
5. `confirmOrder()` : guard early-return si mode multi & non équilibré.
6. `runConfirmOrderAttempt()` : injection de `payment_breakdown[]` dans le payload (mode dominant copié dans `pos_payment_method` legacy + tendered cash → `pos_received_amount` legacy pour rétrocompat).
7. `reset()` : nettoie les tranches et le splitCount à la fermeture du modal.
8. `addTranche()` : si `remaining=0` (cashier ajoute une tranche après équilibre), pré-remplit 1 cent au lieu de 0 — évite l'erreur "amount required" instantanée.

**Testids ajoutés** (existant `pos-payment-confirm` & `pos-v5-pay` intouchés) :
- `pos-payment-mode-cash` / `pos-payment-mode-card` / `pos-payment-mode-multi`
- `pos-payment-split-block`
- `pos-payment-total-covered` / `pos-payment-remaining-due` / `pos-payment-remaining-due-row` / `pos-payment-total-change`
- `pos-payment-split-count` / `pos-payment-split-equal`
- `pos-payment-tranche-add`
- `pos-payment-tranche-row-{i}` / `pos-payment-tranche-amount-{i}` / `pos-payment-tranche-tendered-{i}` / `pos-payment-tranche-remove-{i}`

### 2.4 Tests créés

| Fichier | Type | Lignes | Couverture |
|---|---|---:|---|
| `tests/js/posSplitPaymentValidation.spec.js` | Vitest | ~250 | 27 assertions sur cents math, validateTranche, computeChange, sumCovered, canConfirm, splitEquallyCents (incl. 30.01€/3 → 10/10/10.01), serializeTranches, totalChangeCents, scénarios workflow |
| `tests/e2e/audit-pos-split-payment-2026-05-06.spec.js` | Playwright | ~280 | 4 scénarios : SP-01 toggle 3-modes, SP-02 cash+card balanced, SP-03 split 3-personnes, SP-04 a11y |

---

## 3. Plan backend Codex livré

`docs/audit/plans/PLAN_P12_SPLIT_PAYMENT_BACKEND_2026-05-06.md` (~570 lignes)

Sections (mirror PLAN_P11) :
- §0 mismatches & alignements
- §1 contexte + invariants
- §2 décisions architecturales (table `order_payments` 1:N, branch_id denormalisé, validation sum >= total serveur, feature flag)
- §3 fichiers à créer (migration, model, service, config, tests)
- §4 fichiers à modifier — frozen-zone (PosOrderRequest, Order model, OrderService) — gate Codex
- §5 migration (purement additive, rollback safe)
- §6 step-by-step Cursor (7 étapes)
- §7 critères d'acceptation (8+5 tests, 0 régression sur 28 sentinels)
- §8 risques + rollback (3 niveaux)
- §9 suivi finding
- §10 squelettes complémentaires (test skel, sentinel skel)
- §11 backlog (refund partiel, multi-currency, kiosk split, Z-report enrichi)

Décisions critiques du plan :
1. Storage **table `order_payments`** (PAS JSON column) — queryable, indexable, audit NF525 par tranche.
2. Service **wrapper `SplitPaymentService`** — ne MODIFIE PAS `PaymentService`. Appelé depuis `OrderService::posOrderStore` via 1 bloc additif (3 lignes guardées par flag).
3. Validation : **sum tranches >= total SERVEUR** (jamais le total client). Sentinel obligatoire.
4. Feature flag `SPLIT_PAYMENT_ENABLED=false` default — strip du champ avant validation quand off (sentinel `test_split_payment_disabled_flag_silently_ignores_breakdown_field`).
5. Backward compat : payload sans `payment_breakdown[]` continue le path legacy zéro changement.

---

## 4. État des tests

| Test | Status | Note |
|---|---|---|
| `tests/js/posSplitPaymentValidation.spec.js` | ⚠️ Non exécuté (sandbox bloque `npm test`) | Helpers déterministes ; 27 assertions ; logique vérifiée à la lecture. Demander au user de lancer `npm test -- posSplitPaymentValidation`. |
| `tests/e2e/audit-pos-split-payment-2026-05-06.spec.js` | ⚠️ Non exécuté (sandbox + nécessite backend up) | Spec capture l'UI ; submit attempt capturé en INFO avec status code (attendu 422 tant que PLAN_P12 pas livré). Demander au user de lancer `E2E_BACKEND_AVAILABLE=1 npx playwright test tests/e2e/audit-pos-split-payment-2026-05-06.spec.js`. |
| 685 phpunit existants | ✅ Inchangés | Aucune modif backend dans ce cycle — frozen-zone respectée à 100%. |
| 28 sentinels POS | ✅ Inchangés | Idem. |

---

## 5. Captures clés

`tests/e2e/screenshots/audit-pos-split-payment-2026-05-06/` (généré au run Playwright) :
- `01-modal-open-success.png` — modal paiement avec 3 boutons (cash | card | multi)
- `02-multi-mode-active-success.png` — bloc multi actif avec summary aria-live
- `01-tranche-1-cash-5-tendered-10-success.png` — première tranche cash avec change "5 €"
- `02-two-tranches-balanced-success.png` — 2 tranches, reste dû = 0, confirm enabled
- `01-split-3-tranches-success.png` — 3 tranches générées par "Diviser à parts égales"

`findings.json` + `index.md` dans le même dossier.

---

## 6. Étape suivante

1. **User** : exécute `npm test -- posSplitPaymentValidation` (Vitest sandbox bloqué pour l'agent)
2. **User** : exécute `E2E_BACKEND_AVAILABLE=1 npx playwright test tests/e2e/audit-pos-split-payment-2026-05-06.spec.js`
3. **Cursor / Codex** : exécute `PLAN_P12_SPLIT_PAYMENT_BACKEND_2026-05-06.md` (gate frozen-zone — `OrderService` + `PosOrderRequest` + `Order` model)
4. **Validation** : 685+8+5 = 698 phpunit + 29 sentinels (28 inchangés + 1 nouveau) + 27 vitest assertions PASS
5. **Rollout** : flag `SPLIT_PAYMENT_ENABLED=true` en staging → pilote Châtelet → global

---

## 7. Suggestions cycle 7

- **Refund partiel d'une tranche** : besoin évident UX — annuler une CB seulement, pas la facture entière. Backlog §11 du plan.
- **Multi-currency par tranche** : touriste paye 10 € + 5 USD + 5 GBP. Cycle 8.
- **Split par items assignés** (pas seulement parts égales) : pivot `order_payment_items`. Cycle 9.
- **Multi-tender en counter-collect** : étendre `PaymentService::confirmCounterPaymentMulti`. Cycle 7 si capacity.
- **Multi-tender en kiosk** : aujourd'hui la borne ne fait que single-tender. Cycle 8 si demande utilisateur.

---

## 8. Annexes — fichiers touchés

### Créés (5)

```
resources/js/helpers/posSplitPayment.js                                    +190
resources/js/components/admin/pos/v5/PosV5TrancheRow.vue                   +310
tests/js/posSplitPaymentValidation.spec.js                                 +250
tests/e2e/audit-pos-split-payment-2026-05-06.spec.js                       +280
docs/audit/plans/PLAN_P12_SPLIT_PAYMENT_BACKEND_2026-05-06.md              +570
docs/audit/SPLIT_PAYMENT_IMPLEMENTATION_2026-05-06.md (ce document)        +200
```

### Modifiés (2)

```
resources/js/components/admin/pos/PaymentComponent.vue                     +220
resources/js/helpers/posReceiptBuilder.js                                  +50
```

### NON touchés (frozen-zone)

```
app/Services/PaymentService.php
app/Services/OrderService.php
app/Services/FrontendOrderService.php
app/Http/Requests/PosOrderRequest.php
routes/api.php
```

---

**Total** : ~2 070 lignes ajoutées / modifiées côté frontend + plan backend, 0 ligne backend modifiée.
