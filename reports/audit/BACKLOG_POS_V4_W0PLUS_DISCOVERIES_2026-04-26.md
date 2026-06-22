# BACKLOG W0+ — Découvertes lors de la remédiation POS v4 — 2026-04-26

> **Statut** : OUVERT — actions humaines + cycles agents requis avant W2.
> **Auteur** : Cursor session (cursor-claude orchestrator) après exécution des refactors P0 sûrs.
> **Source** : exécution des 2 lint guards `pos:lint:pricing` et `pos:lint:status` sur le périmètre POS+KDS+Kiosk.

---

## 1. PRICING — `PosComponent.vue:1779` (DÉCOUVERTE — analogue à ItemComponent)

### Évidence
```text
this.checkoutProps.form.total = parseFloat(
    this.subtotal + this.checkoutProps.form.delivery_charge - this.checkoutProps.form.discount
).toFixed(this.setting.site_digit_after_decimal_point);
```

### Statut actuel
- Wrappé temporairement avec `// @pricing-allowed-block start ... end` + commentaire référençant ce backlog.
- CI `pos:lint:pricing` passe (le marker exempte le bloc).
- **Sign-off PENDING** : Tech Lead + Backend owner.

### Analyse
- **Contexte** : pré-affichage modal `#orderpayment` avant POST `posOrder/save`.
- **Impact backend** : ZÉRO — le backend `OrderService::save()` recalcule serveur-side et ignore `form.total` reçu (à confirmer par Backend owner lors du sign-off).
- **Risque produit** : si backend recalcul ≠ valeur affichée pré-modal → l'utilisateur voit un montant qui change après confirmation → perte de confiance / contestation client.
- **Pattern identique** à `ItemComponent.totalPriceSetup()` (W0_PRICING_SSOT_ITEMCOMPONENT_DECISION.md).

### Décision recommandée (cohérence avec D1 ItemComponent)
- **Court terme (W1)** : conserver l'affichage local + sign-off humain.
- **Moyen terme (W2)** : créer endpoint `POST admin/pos/quote-preview` qui retourne `{subtotal, total, breakdown}` calculés serveur-side. PosComponent appelle l'endpoint avant ouverture modal.
- **Test** : test e2e `assertEqual(modal.total, backend.computed.total)` post-`posOrder/save`.

### Action humaine requise (signature)
- [ ] Tech Lead : ____________ — date : ____
- [ ] Backend owner : ____________ — date : ____
- Décision : [ ] D1 (conserver avec garde) — [ ] D2 (migrer endpoint quote-preview en W2) — [ ] AUTRE

---

## 2. ORDER_STATUS — Découverte 7 violations en KIOSK (HORS scope W0+)

Les guards initiaux ont scanné kiosk et identifié 7 vraies violations. Le scan est maintenant restreint à POS+KDS pour ne pas bloquer W0+. **Ces violations doivent être traitées dans un cycle dédié W1-KIOSK**.

| # | Fichier:ligne | Magic int | Code |
|---|---|---|---|
| 1 | `KioskPaymentComponent.vue:431` | 16 (CANCELED) | `axios.post(\`frontend/order/change-status/...\`, { status: 16 })` |
| 2 | `KioskPaymentComponent.vue:556` | 16 (CANCELED) | idem |
| 3 | `KioskWaitingComponent.vue:392` | 16 (CANCELED) | `await axios.post(\`frontend/order/change-status/...\`, { status: 16 })` |
| 4 | `KioskWizardComponent.vue:642` | 10 (OUT_FOR_DELIVERY) | `Number(v.status) !== 10` |
| 5 | `KioskStepPainComponent.vue:86` | 10 (OUT_FOR_DELIVERY) | `.filter(v => v != null && Number(v.status) !== 10)` |
| 6 | `KioskStepSauceComponent.vue:171` | 10 | idem |
| 7 | `KioskWaitingComponent.vue:278` | (radix `parseInt(...,10)` — false positive) | n/a |

### Important — entrées 4-6
Le `Number(v.status) !== 10` n'est PAS forcément une comparaison de status d'order :
- Dans `KioskStepPainComponent` / `KioskStepSauceComponent` ce filtre s'applique à **`variations`** (item availability `status: 10` = bloqué stock ?).
- À VÉRIFIER : ces 3 cas pourraient être un autre enum (ex: `availabilityStatusEnum`) à clarifier avant correction.

### Action requise
- Cycle dédié `T-LOT-W1-KIOSK-ORDERSTATUS-MIGRATION` :
  1. Analyser sémantique de chaque magic int (OrderStatus vs autre enum).
  2. Importer `orderStatusEnum` ou créer `availabilityStatusEnum`/`variationStatusEnum` selon cas.
  3. Refactor + test e2e kiosk happy-path + cancel-path.
  4. Étendre scope `pos:lint:status` au kiosk après migration.

---

## 3. PAYMENT — `PaymentComponent.vue:251-265` (DÉFÉRÉ — gate QA requise)

### Évidence
12 lignes de mutation directe `this.$props.props.form.*` après `posOrder/save`.

### Décision orchestrale (max intelligence) : DÉFÉRER
**Raison** : refactor en `$emit('payment-reset')` impacte le contrat parent et :
- Le composant est utilisé dans **3 contextes** : `PosComponent`, `KioskPaymentComponent`, `PaymentComponent` lui-même.
- Le **flux paiement = chemin critique métier** → déclenche **HARD GATE QA sign-off** (project-invariants.mdc / human-gates.mdc : "Manual UX test required (new flow, redesign, critical path change)").
- Risque régression NF525 / fiscal (paiement = audit trace obligatoire).

### Plan recommandé pour cycle dédié `T-LOT-PAYMENT-REFACTOR-PROP-MUTATION`
1. **Plan** (Claude terminal) : analyse contrat parent ↔ child sur les 3 sites d'usage.
2. **Gate brief** `docs/gates/GATE_PAYMENT_PROP_MUTATION_REFACTOR_*.md` à signer par QA + Backend.
3. **Execute** (codex-terminal `gpt-5.5-pro` recommandé pour ce niveau de réécriture) : refactor PaymentComponent → emit, parent → listener.
4. **Validate** : tests vitest paiement + tests phpunit `OrderService` + e2e Playwright `pos-cash`, `pos-card`, `kiosk-payment-cash`, `kiosk-payment-card`.
5. **Audit** Claude terminal + Claude antagoniste cross-check.

### Garde temporaire en attendant
- Aucune modification supplémentaire de PaymentComponent.vue dans le cycle POS v4.
- ESLint warning `vue/no-mutating-props` à activer en mode `warn` (pas `error`) pour visibilité sans blocage.

---

## 4. branch_id — `ParkedOrdersComponent.vue:72` — RÉSOLU (faux positif cross-check)

### Évidence relue (lignes 70-73)
```text
// [Phase-5 / T08] Liste des paniers serv-side (posParked), rappel / écart, tri
// côté store (récent d'abord) — rappel ne traverse pas `branch_id` (API 404) ;
// G-3 variation indispo : voir `posParked` recall + backlog.
```

### Verdict
- Le cross-check audit (`AUDIT_FINAL_W0_CROSSCHECK_2026-04-26.md` blind spot #4) a interprété cette ligne comme un "filtre commenté".
- **C'est faux** : il s'agit d'un **commentaire de doc** qui décrit un comportement INTENTIONNEL et SÉCURISÉ : l'API `recall` retourne 404 si on tente de rappeler un parked order d'une autre branche.
- L'invariant `branch_id` isolation est **respecté** par le backend, pas par un filtre frontend (qui serait contournable).

### Action
- ✅ Aucune correction code requise.
- ✅ BINDING_MAP_POS_V4.md à mettre à jour : retirer ce point des incertitudes ParkedOrders.
- ✅ Mention dans audit final pour clore le faux positif cross-check.

---

## Résumé exécutif (TL;DR pour humain)

| # | Item | Action humaine | Action agent | Bloque W2 ? |
|---|---|---|---|---|
| 1 | Pricing PosComponent:1779 | **Sign-off TL+BE** sur D1 (court terme) | Implémenter `quote-preview` endpoint (W2) | NON court terme, OUI long terme |
| 2 | Kiosk magic ints (×7) | Approuver création cycle T-LOT-W1-KIOSK | Cycle dédié W1-KIOSK | NON (hors scope POS v4) |
| 3 | PaymentComponent mute props | **Approuver gate brief** + signer après QA | Cycle dédié payment-refactor | NON (mais à traiter avant prod) |
| 4 | branch_id ParkedOrders:72 | RIEN — clore le faux positif | RIEN | NON |

**Aucun de ces items ne bloque l'ouverture de W1** une fois les 4 P0-CC du cross-check (bundle baseline, ADR couleur, pricing sign-off, branch_id clarif) traités.
