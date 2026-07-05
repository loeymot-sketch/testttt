# LOCK — POS Wizard : facturer la "Viande supplémentaire" (fantôme +2,50€)

- **Date**: 2026-07-01
- **Frozen-zone target**: `public/js/pos-wizard.js` (CLAUDE.md §7 — POS Vanilla JS wizard, owner-protected)
- **Fichier non-frozen associé**: `resources/js/components/admin/pos/ItemComponent.vue` (pont composer-aware)
- **Severity**: P1 — affichage caisse ≠ encaissement (le client voit +2,50€/viande, le backend facture 0 → sous-facturation vs devis affiché)
- **LOCK requestor**: session Claude (audit prix caisse 2026-07-01)
- **Owner countersign**: **ACCORDÉ 2026-07-01** — décision explicite : « Viande supplémentaire = 2,50€, et la FACTURER » (affichage = encaissement).
- **Branch target**: `pos/category-first-caisse-2026-06-23` (courante)

---

## Section 1 — Justification (preuve owner-gate)

### 1.1 Bug prouvé (données live foodking_e2e)

Le wizard propose une section « ➕ Viande supplémentaire (+2,50€/viande) » (stepper +/-)
qui incrémente `selections.viandeSupplItems` (clés `v_<idVariationViande>`). L'affichage :
- Total "running" (`calculateRunningTotal`, l.~1292) : `extra += supplTotal * VIANDE_SUPPL_PRICE` (2,50€) ✓ affiché.
- Sérialisation (`[X6 FIX]`, l.~3883) : marque `allSelectedExtras[parseInt(idVariation)]` — mais
  les checkboxes extra du pont sont indexées par **id d'item_extra**, pas par id de variation
  → aucune checkbox cochée → `item_extras` ne contient PAS l'extra « Viande supplémentaire »
  (item_extras id 395/398, prix 2,50€ en base) → **backend facture 0€**.

**Preuve** : `PricingService::calculateOrder` somme `item_extras.price` ; l'extra « Viande
supplémentaire » (2,50€) n'est jamais transmis → line total = base seulement. Devis affiché
(base + 2,50) ≠ encaissé (base). Vérifié via quotes tinker (Terminator/Méga) 2026-07-01.

En plus : `renderRecapStep` (récap final) omet la viande suppl dans `totalExtra` → total récap
inférieur au total "running" de 2,50€×N (incohérence interne d'affichage).

### 1.2 Décision business (owner)

Extra viande = **2,50€ facturé** (aligné sur le prix enregistré en base). L'écran affiche déjà
2,50€ ; il faut donc **l'encaisser** (sérialiser vers l'extra « Viande supplémentaire »).

---

## Section 2 — Scope de la modification (chirurgical)

### 2.1 `public/js/pos-wizard.js` (FROZEN) — 3 éditions

1. **Bloc `[X6 FIX]` (~l.3883)** : au lieu de marquer l'id de variation, calculer la quantité
   totale de viandes suppl (`viandeSupplQty`), trouver l'extra dont le nom matche `/viande\s*suppl/i`
   dans `lastItemData.extras`, et marquer `allSelectedExtras[extra.id] = true`. Mémoriser
   `viandeSupplExtraId` + `viandeSupplQty`.
2. **Boucle de sync des checkboxes extra (~l.3901)** : sur la checkbox de l'extra viande suppl,
   poser `data-wizard-qty = viandeSupplQty` avant `cb.click()` (transmet la quantité au pont).
3. **`renderRecapStep` total (~l.2325)** : ajouter `totalExtra += N * VIANDE_SUPPL_PRICE` pour que
   le récap affiche = running = encaissé.

### 2.2 `resources/js/components/admin/pos/ItemComponent.vue` (NON-frozen) — 1 édition

- `onWizardBridgeExtra` : lire `data-wizard-qty` sur la checkbox ; si présent (>0), appeler
  `setExtraQuantity(extra, N)` au lieu de 1. Sinon binaire (comportement inchangé pour les autres
  extras : Cheddar, etc.).

Aucune autre ligne touchée. `setExtraQuantity` est idempotent (pas de double-comptage si l'extra
« Viande supplémentaire » est aussi coché via la liste suppléments — un seul id d'extra).

---

## Section 3 — Invariants préservés

- NF525 : le prix reste 100% recalculé backend depuis `item_extras` (SSOT). On ne pousse AUCUN prix
  côté client ; on transmet uniquement `{extra_id, quantity}`. `composition_snapshot` capture l'extra.
- Pas de changement de la logique de prix backend (`PricingService` intouché).
- Le design/UX du wizard (frozen) est inchangé visuellement (mêmes libellés, mêmes sections).
- Les autres extras (crudités, suppléments 0,90€, sauces) : comportement identique.

## Section 4 — Rollback

```bash
git checkout -- public/js/pos-wizard.js resources/js/components/admin/pos/ItemComponent.vue
npm run production   # rebuild ItemComponent (app.js)
```
pos-wizard.js est chargé direct (cache-bust `?v=time()` déjà présent dans la blade).

## Section 5 — Tests (triple-vert requis)

- Vitest : `posWizardComposerAware.spec.js` (helpers composer intacts) + nouveau test unitaire
  `onWizardBridgeExtra` quantité.
- Backend : quote avec extra « Viande supplémentaire » → +2,50€ (déjà prouvé).
- Live caisse (Playwright) : composer un produit, +1 viande suppl via stepper → commande sérialise
  l'extra → total encaissé = affiché.
- Frozen diff : seul `pos-wizard.js` (autorisé par ce LOCK).

## Section 6 — Sign-off

- [x] Owner business decision (facturer 2,50€) — 2026-07-01
- [x] Owner frozen-zone gate (autorise modif pos-wizard.js) — 2026-07-01
- [x] Tests triple-vert : Vitest 2092/2096 (1 fail PRÉ-EXISTANT `KeyboardNavigationSentinel`
      focus-visible regex vs CSS minifié, non lié) ; sentinelle `posWizardComposerAware` 9/9 ;
      nouveau `posWizardBridgeExtraQuantity` 4/4 ; freshness app+pos 3/3 ; backend quote prouvé
      (Méga 8→10,50→13 ; Tacos M 6,90→9,40) ; e2e LIVE caisse : « Tacos M · +Poulet mariné · 9,40€ »
      encaissé = affiché, décimales OK.
- [ ] Cérémonie commit (LOCK doc d'abord, puis fichier frozen citant ce LOCK) — NON committé (attente owner)
