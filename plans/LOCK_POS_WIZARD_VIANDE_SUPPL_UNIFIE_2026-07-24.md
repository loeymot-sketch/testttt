# LOCK + DESIGN — Unification supplément VIANDE (caisse + borne) + ticket cuisine nommé

**Date** : 2026-07-24 · **Gate owner** : ✅ EXPLICITE (message 2026-07-24)

## Demande owner (résumé)
Wizard perso caisse (viandes/sauces) : (1) **supprimer Viande Hachée + Merguez** (non utilisées) ; (2) **unifier** — viandes incluses gratuites jusqu'au max du produit, cliquer **au-delà = supplément viande @2,50 NOMMÉ** depuis les MÊMES tuiles (plus dans la liste générique de suppléments) ; (3) **même logique sur la borne** (incluses en haut, extra facturé) ; (4) **ticket cuisine** montre le supplément viande AVEC le nom de la viande. + tester en boucle jusqu'à validation absolue (interface + technique + sync + ticket cuisine). Partie A séparée = réorg header caisse.

## État actuel (mappé, 3 agents)
- **Caisse** (`pos-wizard.js` FROZEN, single-page ~3071-3149) : viandes incluses = variations gratuites bornées `maxViandes` ; supplément viande = mécanisme SÉPARÉ `selections.viandeSupplItems` (@`VIANDE_SUPPL_PRICE=2.50`) via toggle dépliable « + Viande supplémentaire » (nommé, prix + scellé via `data-wizard-qty` OK). **BUG** : l'ItemExtra « Viande supplémentaire » apparaît AUSSI dans les suppléments génériques (filtre `renderSinglePage:~2990` n'exclut que « sauce ») → double exposition/anonyme.
- **Borne** (`KioskStepViandeComponent.vue` non-frozen ; `KioskWizardComponent.vue:buildCartItem` FROZEN) : étape viande plafonne DUR à `maxViandes` (clic au-delà bloqué `canIncrement`), extra générique dans l'étape suppléments. Chemin viande-payante nommée `source:'extra'` EXISTE mais MORT (aucun extra `group_label='viande'`).
- **Ticket cuisine** (`KitchenTicketSymbolicFormatter.php` + `kdsSymbolic.js`, non-frozen) : supplément viande sort « + Viande supplémentaire ×N » GÉNÉRIQUE ; résolution de nom depuis l'instruction ne couvre QUE les sauces (2026-07-18) ; ligne « VIANDES: … » effacée par `cleanInstruction`.
- **Data** : « Viande Hachée » = variation DB réelle (11) ; « Merguez » = seulement fallback frozen `VIANDES:50`.

## DESIGN retenu — Approche A (générique + nom-instruction + résolution ticket), UNIFIÉE
Cohérent avec le pattern sauce déjà validé (2026-07-18). Extra unique « Viande supplémentaire » @2,50 + noms des viandes dans l'instruction, résolus au ticket. Même modèle caisse + borne (pas de divergence).

### Frozen (sous CE LOCK)
1. **`public/js/pos-wizard.js`** : (a) FUSION geste — au-delà de `maxViandes`, un clic « + » sur une tuile viande alimente `viandeSupplItems[cette viande]` (au lieu de no-op) → mêmes tuiles gèrent inclus→supplément ; badge/état « +2,50 » sur la tuile en mode supplément. (b) EXCLURE « Viande supplémentaire » des suppléments génériques (filtre `/viande\s*suppl/i` à `renderSinglePage:~2990` + `prepareSteps:~700`, miroir du filtre « sauce »). (c) Retirer « Merguez » (+ « Viande Hachée ») de la constante `VIANDES`. (Toggle dépliable séparé conservé comme repli explicite OU simplifié.)
2. **`public/css/pos-wizard.css`** : état supplément sur tuile (badge +2,50, compteur).
3. **`resources/js/components/frontend/kiosk/KioskWizardComponent.vue`** (`buildCartItem`) : router les viandes sélectionnées AU-DELÀ de `maxViandes` vers l'extra « Viande supplémentaire » (qty = dépassement) + noms dans l'instruction (miroir caisse).

### Non-frozen
4. **`KioskStepViandeComponent.vue`** : autoriser la sélection au-delà de `maxViandes` (le dépassement = supplément, affordance « +2,50 »).
5. **`KitchenTicketSymbolicFormatter.php` + `kdsSymbolic.js`** : résoudre le NOM de la/les viande(s) en supplément depuis l'instruction (« Viandes en plus : X, Y » ou équivalent) → ticket « + Viande supplémentaire : Poulet » (miroir exact de la résolution sauce). PARITÉ PHP↔JS.
6. **DATA** : migration désactiver (soft-delete) les 11 variations « Viande Hachée ».

## Pourquoi le gate est satisfait
Demande owner explicite et détaillée de refondre précisément cette logique + ces suppressions. Scope limité à la sélection viande/supplément + son affichage ticket. Pricing SSOT inchangé (@2,50 backend), pas de nouveau chemin fiscal.

## Preuves attendues (validation absolue, test en boucle)
- Vitest wizard caisse : geste fusionné (inclus gratuit ≤ max, au-delà = viandeSupplItems @2,50), « Viande supplémentaire » ABSENTE des suppléments génériques, Merguez/Hachée absentes.
- Vitest borne : au-delà de maxViandes → extra « Viande supplémentaire » qty=dépassement + noms instruction.
- PHPUnit ticket : supplément viande → « + Viande supplémentaire : <noms> » (PHP + JS parité).
- e2e navigateur : caisse (harness real payload) + borne — capture grille viande + supplément + panier prix correct.
- Money-path : inclus = 0, chaque supplément = +2,50 exact, affiché == scellé (pas de double-comptage).
- Frozen diff limité (pos-wizard.js/.css + KioskWizardComponent.vue) ; NF525 chaîne OK.

## Réversibilité
`git checkout <ref> -- <fichiers frozen>` + rebuild ; migration `migrate:rollback`.
