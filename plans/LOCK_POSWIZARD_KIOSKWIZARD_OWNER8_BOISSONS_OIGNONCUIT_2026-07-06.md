# LOCK_POSWIZARD_KIOSKWIZARD_OWNER8 — boissons catalogue caisse + oignon cuit exclusif

> Frozen-zone override authorization. Contrat entre Owner (gate humaine), Claude
> (planner), sub-agent (implémenteur) et le pre-commit guard.

## §1. Identification
- **LOCK ID** : `LOCK_POSWIZARD_KIOSKWIZARD_OWNER8`
- **Créé** : 2026-07-06
- **Cycle** : GOAL owner-8-problemes (`plans/GOAL_OWNER_8_PROBLEMES_POS_KDS_PRINT_2026-07-06.md`)
- **Phase** : EXECUTE (Wave 2/3-B)
- **Status** : `APPROVED` — gate G1 PRE-APPROVED : mandat explicite owner dans le goal du 2026-07-06 (« l'owner a tranché : il VEUT les vraies boissons » + « ajoute l'option oignon cuit […] au lieu de l'écrire ») ; le présent LOCK documente le périmètre exact de ce mandat.

## §2. Fichiers frozen ciblés
| Path | Pourquoi frozen | Lignes ciblées |
|---|---|---|
| `public/js/pos-wizard.js` | CLAUDE.md §7 — « design parfait selon owner », POS Vanilla wizard production-validated | IIFE boissonItems ~936-976 (~12-15 l.) + garnitures 2876-2879/3044-3064/3226-3233/3840-3870 (~30 l.) |
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | CLAUDE.md §7 — wizard borne production-validated | section crudités/extras (~15 l.) |

## §3. Justification
**Problème** : (a) au wizard caisse, formule « Menu (Frites+Boisson) »/« Boisson Seule » n'offre AUCUN choix de boisson — reproduit LIVE (captures `reports/test-e2e/owner-8-problemes/w2-audit/01-…png`, `02-…png`) ; cause : `boissonItems` filtré à vide car les items n'ont que 3 addons génériques et le catalogue DOM est vide (audit w2 §2). (b) Aucune option « oignon cuit » sélectionnable : l'owner l'écrit en note libre — demande explicite d'un toggle exclusif cru↔cuit avec symbole O̲ (audit w3 §B).

**Pas d'alternative non-frozen** : (a) l'option data-only (attacher 15 boissons en addons role=drink) est REJETÉE — `syncAndSubmit` cliquerait les cartes Vue → facturation pleine (+1,90 €/menu, 9,90→11,80 €) ; le repli catalogue DOIT vivre dans l'IIFE du wizard (frozen). (b) Le nouvel extra « Oignons cuits » (DATA, non-frozen) serait inclus PAR DÉFAUT et cumulable avec « Oignons » à cause de la logique crudités du wizard — le défaut OFF + l'exclusivité exigent ~30 lignes dans la section garnitures (frozen), idem borne.

## §4. Scope — surgical
1. pos-wizard.js (a) : dans l'IIFE `boissonItems`, si le filtre addons rend vide ET `data-pos-drinks-catalog` non-vide → construire `boissonItems` depuis `catalogList` avec `price: 0`, libellé « Incluse ». Aucun autre chemin touché ; la sélection alimente le canal existant `BOISSON: <nom>` (pos-wizard:2581-2588) ; facturation inchangée (matching par nom → no-op, modèle borne).
2. pos-wizard.js (b) : extra « Oignons cuits » (matché `isCruditeName`) → défaut NON-inclus + exclusivité mutuelle avec « Oignons » (sélection de l'un désélectionne l'autre) ; défaut global = cru.
3. KioskWizardComponent.vue : même exclusivité cru↔cuit côté borne, défaut cru.
4. pos-wizard.js (c) : cartes viande = image réelle si disponible (URL par variation/asset inventorié) au lieu de la pastille couleur seule — mandat owner goal 2026-07-06 (« Mets-moi des images réelles ») ; repli = pastille actuelle si asset absent (gate G2).

## §5. Files
Modifiés : les 2 frozen ci-dessus uniquement. Contexte (lus, non modifiés) : `PosComponent.vue` (drinksCatalog persistant — non-frozen, hors LOCK), `kdsSymbolic.js`/`KitchenTicketSymbolicFormatter.php` (symbole O̲ — non-frozen, hors LOCK), seeder boissons (renommage Hawaï — hors LOCK). NON touchés : `PricingService.php` (frozen, zéro changement — prix formule inchangé par construction), `KioskAppComponent.vue`, `KioskUpsellComponent.vue`, `PaymentComponent.vue`.

## §6. Acceptance (binaire)
- [ ] Playwright caisse : Cayenne → Menu Complet → liste boissons réelles affichée, sélection « Hawaï 33cl » → récap + instruction `BOISSON: Hawaï 33cl` — capture analysée
- [ ] Devis/DB : total Menu Complet AVANT = APRÈS (9,90 € Cayenne) ; Boisson Seule 9,40 € = 9,40 € — `tests/Feature/Pos/PosMenuDrinkChoiceTest.php` (TO BE CREATED) PASS
- [ ] Oignon cuit : toggle exclusif (cuit ON → cru OFF et inversement), défaut cru, instruction/extra sérialisé → symbole O̲ sur ticket+KDS — `tests/js/posWizardOnionCuit.spec.js` + `tests/js/kdsSymbolicOnionCuit.spec.js` (TO BE CREATED) PASS
- [ ] Borne non régressée : Vitest kiosk wizard suite verte + parité fixture 391 rows verte
- [ ] Baseline SHA wizard (sentinelle fraîcheur/baseline) mise à jour dans le MÊME commit
- [ ] `git diff --stat` du commit LOCK = uniquement les 2 fichiers frozen (+ baselines de tests)

## §7. Rollback
1. Code : `git revert <sha-patch>` (les patches frozen = commits séparés du reste de la vague). Branche filet : `backup/pre-owner8-2026-07-06`.
2. Data : extra « Oignons cuits » créé par seeder/SQL scopé → `UPDATE items/extras SET status=5` (soft-disable), aucune migration.
3. Bundle : `npm run development` post-revert (pos-wizard.js est non-compilé, servi tel quel — resync VPS par SCP).
4. Notification : dev local uniquement tant que non déployé.

## §8. Sub-agent
- Implémenteur unique (aucun parallélisme sur ces fichiers), spawn par l'orchestrateur après clôture du batch 1 (W3/W4-impl) pour éviter tout conflit de working tree.
- Vérification post-patch : orchestrateur (tests + captures + diff frozen hors-LOCK = 0).

## §9. Guard
Pre-commit guard : le commit des fichiers frozen référencera ce LOCK dans son message (`LOCK: LOCK_POSWIZARD_KIOSKWIZARD_OWNER8`). Si le hook bloque malgré le LOCK, escalade owner (PAS de --no-verify sans OK explicite).

## §10. Sign-off
- **Owner** : PRE-APPROVED via mandat goal 2026-07-06 (« câbler les vraies boissons » + « option oignon cuit ») — ce LOCK exécute ce mandat sans l'excéder. Toute extension de périmètre = nouveau gate.
- **Statut transitions** : APPROVED (mandat) → APPLIED (commit patch) → CLOSED (acceptance §6 toutes vertes).
