# GOAL — Méga borne/cuisine/stock/mobile (owner 2026-07-22)

## §0 Preamble
- **Working tree** : commits web déjà poussés (afda9a2, f5bd810). Backend branche `pos/category-first-caisse-2026-06-23` HEAD `3f81344c7` (poussé). Nouveaux fix = commits atomiques par vague.
- **Pipeline par tâche** : `ultra-audit-profond` (14 étapes) ; audit adversaire = `test-e2e` (dual-team) ; frozen = `lock-plan`.
- **Convergence** : 2 cycles consécutifs P0+P1=0, findings identiques. Money-path scellé==affiché au centime (invariant NF525).
- **Frozen (data/config only)** : `KioskWizardComponent.vue`, `pos-wizard.js`, `KioskApp/Upsell`, `PaymentComponent`, fiscal services. Tout fix borne passe par **steps/*.vue (non-frozen)**, **config**, **data**, **profil composer DB**, **traductions**.

## §1 Map principal (6 systèmes · anchors vérifiés 2026-07-22)
| Sys | Maturité | Anchor réel (vérifié) | Tests existants |
|---|---|---|---|
| S1 Borne tacos+formule UX | à corriger | `resources/js/components/frontend/kiosk/steps/*.vue` (9 steps), `KioskWizardComponent.vue:556 case 'tacos'`, `:1060 template!=='tacos'`, `:922 tacos detect` | `tests/js/posWizard*`, `tests/e2e/*kiosk*` |
| S2 Ticket/écran cuisine | à raffiner | `app/Services/Hardware/KitchenTicketSymbolicFormatter.php:134 mainLine/:136 produitAndSize/:163 viandes`, `OrderReceiptEscPosRenderer.php:317`, `resources/js/helpers/kdsSymbolic.js`, `KitchenDisplaySystemComponent.vue` | `tests/js/kdsSymbolic.spec.js`, `tests/Feature/**Kitchen**` (à vérifier) |
| S3 Formule prix affichés | à corriger | web `wizard-v2.jsx` (formule/menu step), borne `steps/KioskStepMenuComponent.vue` | `tests/js/posWizard*`, `tests/e2e/web-wizard-*` |
| S4 Stock sync + rupture | partiel | `app/Http/Controllers/Admin/StockRuptureDashboardController.php`, `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue`, `KitchenDisplaySystemComponent.vue` (signal KDS à créer) | `tests/Feature/**Stock**`, `tests/js/stockRupture*` |
| S5 Mobile admin access | à exposer | `mobile/` (api,components,data), routes `app/Http/Controllers/Admin/*`, `docs/AUTHZ_MATRIX.md` | `tests/Feature/**Admin**` |

## §2 Contrats owner (source de vérité des specs)
- **P1 tacos crudités** : la borne DOIT proposer l'étape crudités (garnitures) pour tacos (aujourd'hui absente).
- **P2 ticket cuisine** (minimal, MÊME sur borne/caisse/KDS/web) :
  - Sauce EN PLUS écrite DEVANT sa catégorie : sauce **menu**→ligne menu ; sauce **produit**(sandwich/tacos)→ligne produit, sur la MÊME ligne que la 1ʳᵉ (« 1 et 2 »).
  - **Tacos : PAS de taille** (retirer `[Taille]` pour tacos). Afficher le NOMBRE de viandes directement, symboles séparés d'un espace (ex hachée+effilée = `K P`). Ordre ligne : produit → viandes → sauce(s) → suppléments. Boisson seule si prise. Menu+boisson pour la formule.
- **P3 prix formule** : afficher le prix de chaque option — menu complet **+2,50 €**, boisson seule **1,90 €**, frites seules **1,90 €** (⚠ vérifier SSOT `config/menu`/PricingService — les valeurs owner priment sur l'ancien +1,50/+1,00, à réconcilier) + prix suppléments visibles.
- **P4 UX formule borne** : SPLIT en pages dédiées (façon concurrents) — page1 = choix formule (menu / boisson seule / frites seules), page2 = boissons (dédiée), page3 = sauces (dédiée). Fini le scroll-sans-indication + blocage.
- **P5 stock** : sync parfaite tous systèmes, 0 doublage ; rupture signalable depuis **KDS cuisine + caisse + admin** (pas que admin).
- **P6 mobile** : accès admin depuis Internet (stock+ruptures) + accès compta/gestion/employés/factures/dépenses (déjà bâti, à exposer). Déployer + fournir l'accès + prouver.

## §3 S1 — Borne tacos + formule UX
**Frozen** : KioskWizardComponent (logique via `computeActiveSteps` + steps/*.vue non-frozen + profil composer).
**Sub 1.1 — Tacos crudités**
- T-1.1.1 Trouver pourquoi tacos saute crudités — anchor `KioskWizardComponent.vue:1060 (template!=='tacos')` + `shouldShowCompositionStep('crudites')` + le profil composer tacos DB. • test: `(à créer tests/js/kioskTacosCruditesStep.spec.js)`
- T-1.1.2 Ajouter l'étape crudités pour tacos (data/profil composer OU computeActiveSteps non-frozen) sans casser les autres templates. • test: idem + `tests/e2e/*kiosk*`
- T-1.1.3 Acceptance : borne tacos → étape « Crudités » visible + sélectionnable + sync backend (item_extras crudité) ; e2e capture LUE.
**Sub 1.2 — Formule split 3 pages**
- T-1.2.1 Découper la cascade formule : page1 formule(menu/boisson-seule/frites-seule), page2 boissons, page3 sauces — anchor `steps/KioskStepMenuComponent.vue` + `computeActiveSteps`. • test: `(à créer tests/js/kioskFormuleSplit.spec.js)`
- T-1.2.2 Chaque page : header clair, indication de scroll/validation, auto-scroll ou liste extensible, CTA gaté (min sélection). • visual: `/kiosk` (capture LUE)
- T-1.2.3 Acceptance : 3 pages distinctes, plus de blocage « aucune indication », e2e clic-par-clic vert.
**Sub 1.3 — Prix affichés (borne)**
- T-1.3.1 Afficher +2,50/1,90/1,90 + prix suppléments sur chaque option. • test: `(à créer tests/js/kioskFormulePrices.spec.js)` • visual capture LUE.

## §4 S2 — Ticket + écran cuisine (le cœur owner)
**Frozen** : aucun (formatter PHP + kdsSymbolic.js + renderer + KDS vue = non-frozen). Jumeau PHP↔JS OBLIGATOIRE (parité).
**Sub 2.1 — Sauce devant sa catégorie**
- T-2.1.1 Sauce produit (sandwich/tacos) sur la ligne produit (1ʳᵉ + en plus) ; sauce menu sur la ligne MENU — anchor `KitchenTicketSymbolicFormatter.php:134 mainLine` + `:supplementLines` + `OrderReceiptEscPosRenderer.php:317 menuLine`. Refactor : la sauce en plus du sandwich rejoint la ligne 1 (pas une ligne `+ Sauce supplémentaire` séparée). • test: `tests/js/kdsSymbolic.spec.js` + `(à créer tests/Feature/Hardware/KitchenTicketSauceCategoryTest.php)`
- T-2.1.2 Jumeau JS `kdsSymbolic.js` aligné (parité stricte, tests miroir).
**Sub 2.2 — Tacos sans taille + viandes comptées**
- T-2.2.1 Retirer `[Taille]` pour tacos dans `produitAndSize`/`mainLine` (anchor `:136`) ; afficher les viandes en symboles espacés `K P`. • test: `(à créer tests/Feature/Hardware/KitchenTicketTacosTest.php)`
- T-2.2.2 Ordre ligne : produit → viandes → sauce → suppléments ; boisson seule si prise. Parité JS.
**Sub 2.3 — Même logique partout**
- T-2.3.1 Vérifier caisse (pos ticket) + KDS écran + borne + web consomment le MÊME formatter/twin — anchor `PosTicketBytesController` + `KitchenDisplaySystemComponent.vue`. • test: `tests/Feature/**escpos**` + e2e KDS capture LUE.
- Acceptance S2 : ticket + écran = ligne1 produit(+ses sauces) / ligne2 MENU(+sa sauce) ; tacos = « Tacos | K P | <sauce> | <supp> », 0 taille ; parité PHP↔JS (tests miroir verts).

## §5 S3 — Prix formule (SSOT réconcilié)
**Sub 3.1** T-3.1.1 Réconcilier les prix formule (owner : 2,50/1,90/1,90) avec le SSOT `PricingService`/`config` — anchor `app/Services/Pricing/PricingService.php` (FROZEN → data/config seulement, sinon gate). • test: `tests/Feature/Pricing/*` — ⚠ si divergence prix = **owner gate G-PRIX**.
**Sub 3.2** T-3.2.1 web `wizard-v2.jsx` + borne affichent le prix par option + suppléments. • test: `tests/e2e/web-wizard-*` capture LUE.

## §6 S4 — Stock sync + rupture multi-surface
**Sub 4.1 — Rupture depuis KDS + caisse**
- T-4.1.1 Bouton « signaler rupture » sur KDS `KitchenDisplaySystemComponent.vue` → endpoint availability existant (anchor `StockRuptureDashboardController` + toggle). • test: `(à créer tests/Feature/Stock/KdsRuptureSignalTest.php)`
- T-4.1.2 Idem depuis la caisse (POS) — bouton rupture par produit. • test: `(à créer tests/Feature/Pos/PosRuptureSignalTest.php)`
- T-4.1.3 Sync temps-réel : rupture signalée n'importe où → propagée borne/caisse/KDS/admin (SYNC_CONTRACT). • test: `tests/Feature/**Stock**Sync**` + e2e cross-surface.
**Sub 4.2 — Anti-doublage** T-4.2.1 Vérifier SSOT unique de disponibilité (pas 2 sources) — anchor `StockRuptureDashboardController` + `ItemBranchAvailability`. • test: sentinel existant.

## §7 S5 — Mobile admin (exposer + déployer)
**Sub 5.1 — Accès stock/rupture mobile** T-5.1.1 Exposer le dashboard stock + ruptures en responsive/mobile (l'admin est déjà là) — anchor routes `Admin/*` + `mobile/`. • test: `tests/e2e/*admin*` mobile viewport capture LUE.
**Sub 5.2 — Accès compta/gestion** T-5.2.1 Vérifier + exposer compta/employés/factures/dépenses (déjà bâti) ; **owner gate G-MOBILE** : domaine/URL + auth mobile (owner fournit). • WHO owner.
**Sub 5.3 — Deploy + preuve** T-5.3.1 Déployer + prouver l'accès mobile réel (login mobile → dashboard stock).

## §A Agent army (fan-out par type de tâche — cf. skill Axis 4)
- Frontend visual (S1/S3 borne, S4 KDS bouton) : Architect+UX+Implementer+RED+QA-Vis+RED-Vis.
- Backend logic (S2 formatter, S4 endpoints) : Architect+DBA+Implementer+RED.
- Sync (S4 rupture cross-surface) : Architect+SRE+DBA+Implementer+RED.
- Parité PHP↔JS (S2) : Implementer + tests miroir obligatoires.
- Reports → `reports/goal-mega-2026-07-22/wave-<W>-<role>.json`.

## §X Vagues (checkpoint + interrupt-resume à chaque)
- **W1 — S2 ticket/écran cuisine** (owner priorité #1 « surtout la cuisine »). Séquentiel. Checkpoint : formatter+twin parité, tacos sans taille, sauce par catégorie, tests miroir verts, capture KDS LUE.
- **W2 — S1 borne tacos crudités + formule split 3 pages + prix**. Séquentiel (touche steps). Checkpoint : e2e borne clic-par-clic, 0 blocage, prix visibles.
- **W3 — S3 prix formule SSOT** (réconciliation + gate prix si divergence).
- **W4 — S4 stock rupture multi-surface + sync**. Checkpoint : rupture depuis KDS/caisse → propagée, 0 doublage, cross-surface e2e.
- **W5 — S5 mobile admin** (gate owner domaine/auth) + deploy.
- **W6 — Convergence** : test-e2e adversarial full (borne→caisse→KDS→ticket→web→mobile), money-path centime, 2 cycles identiques P0+P1=0, deploy VPS + Vercel.
- **Interrupt** : commit WIP `wip(Wn): …`, manifest `reports/goal-mega-2026-07-22/INTERRUPT_<Wn>.md`, BRAIN §2 màj.

## §G Owner gates
| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G-PRIX | Réconcilier prix formule (owner 2,50/1,90/1,90 vs SSOT existant) | Owner (confirme) | valeurs finales | commit + PricingService | PENDING |
| G-MOBILE | Domaine/URL + auth accès admin mobile | Owner | URL + creds/clé | `.env` + deploy report | PENDING |
| G-MOLLIE | (rappel) clé Mollie sur VPS | Owner | commande VPS | .env VPS | PENDING |
| G-PUSH | Autorisation push/deploy chaque vague | Owner | « deploy » | commit tag | par-vague |

## §F Final rule
DONE = les 6 problèmes corrigés + prouvés e2e (captures LUES) + parité ticket↔écran + money-path centime + rupture multi-surface synchronisée + mobile admin accessible & prouvé + 0 frozen touché (data/config only) + convergence 2 cycles P0+P1=0 + déployé (VPS+Vercel) avec go owner. Pas « presque » — production-parfait.
