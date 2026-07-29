# V1 — VERDICTS RED + HEALS (2026-07-29)

2 agents RED (reproduce → dispute → prove). Résumé ; détail des preuves file:line dans
les transcripts d'agents (chat) et recoupé ci-dessous. **Aucun P0, aucun P1 structurel.**

## Confirmés → HEALÉS par S2 (cette vague)
| ID | Défaut | Cause racine | Heal | Test |
|---|---|---|---|---|
| G4/V-13 | Radios « Oui / N° » (13 surfaces) | `fr.json:1166 "no": "N°"` (mistraduction) | → `"Non"` | visuel à re-capturer |
| G8a/V-10 | Pagination « Previous/Next » anglaise partout | `lang/fr/pagination.php` jamais traduit | → « Précédent / Suivant » | visuel |
| G2 | PIN Carnet défaut commité `2468` actif (DAILY_BOOK_PIN absent .env) | défaut non fail-closed vs mobile-stock | `config/daily_book.php` défaut `''` + garde 403 fail-closed dans `DailyBookAuthController::unlock` | `test_unconfigured_pin_is_fail_closed` — DailyBook 16/16 vert (worktree) |
| F1/V-08 | Tracker « À encaisser »=0 vs Encaissement=5 | double cause : (a) cash-pending évalué seulement sous status=ACCEPT (5 cmd PREPARED invisibles) ; (b) fetch borné au jour courant vs file all-time | (a) hoist `isCashPending` avant le switch de bucket ; (b) fusion `counter-collect/pending` (dédup id, fail-soft) — `PosOrdersTrackerComponent.vue` | e2e V2 + re-capture |
| F2/V-07 | POS dit « borne » pour une file 4-origines (badge Encaissement « Caisse » EXACT) | libellés POS hardcodés hérités | `pos_shortcut_cash_title` → « À encaisser — comptoir ({count}) » + header panneau → « À encaisser au comptoir (borne · caisse · tél · web) » | visuel |
| F4/V-01 | Header POS superposé 1280×800 (titre width=0) | `.pos-v5-operator-bar` grid `minmax(0,1fr) auto`, empilement seulement ≤767px | media query ≤1439px → colonnes empilées (`pos-v5.css`, non-frozen) | re-capture 1280×800 |
| F5/V-15 | « tout est en stock ✅ » /m trompeur | portée = ruptures signalées seulement (pas matières) | libellé → « Aucune rupture signalée (produits & ingrédients) ✅ » | visuel |
| F6/V-06 | « 1 Articles » | clé plurielle en dur ×2 | ternaire `label.item`/`label.items` (`PosComponent.vue:776,1503`) | visuel |
| F3-data/V-04 | « Upsell item » anglais sur tuiles | description DATA seedée EN (`MenuSeeder.php:505`) | DB : 3 items → « Complément de commande » + seeder aligné | visuel |

## Confirmés → HORS VOIE S2 (handoff `plans/handoffs/S2-vers-S6-...md`)
- F3-backend : `ItemService::list` ne filtre pas la catégorie INACTIVE pour surface pos (items techniques cat 27 fuient dans « Toutes »/recherche).
- G3 : `V1_PRIMARY_SIDEBAR_MENUS` court-circuite `V1_HIDDEN_MENU_MODULES` (delivery-boy-cash-sessions visible à tous) — `BackendMenuComponent.vue` = CENTRAL.

## Confirmés → décisions S2 SANS action code
- F3-tri : fix RED « order_column:'order' » **REJETÉ preuve data** — colonne `order` non curée (Tacos M=0, Cheddar=0, techniques=1, 5 valeurs distinctes) : le tri serait pire. Documenté au handoff S6.
- G5 : pollution `E2E_PLAYWRIGHT_STUDIO_*` = seeder re-semé à CHAQUE run (`global-setup.js:61`) → un nettoyage DB serait annulé. Fix propre = global-teardown Playwright (infra tests partagée, en backlog S2-V5 ; purge des résidus INACTIVE historiques = **gate owner**).
- G7a : `items/wizard/ProductCreateWizardComponent.vue` DEAD confirmé — suppression groupée plus tard (pas de commit isolé).
- V-05 : wizard caisse « €6.90 » format anglais — `pos-wizard.js` **FROZEN strict** → **gate owner** (signalement, zéro touche).
- V-03 : images manquantes tuiles/pastilles POS = DATA images (backlog vague UX V4).

## Réfutés (aucune action)
- G1 : pos-v4 sans auth serveur = pattern SPA global (RootController idem), 0 donnée exposée (curl prouvé), apiKey identique sur /login (finding déjà tracké 2026-07-15). P3, rien à faire seul.
- G6 : état vide Historique EXISTE (`HistoriqueListComponent.vue:141-152`).
- G7b : `AvailabilityToggleComponent.vue` couvert par 2 specs vitest — pas mort.
- G8b : « DATE tronquée » = scroll horizontal volontaire du design system (`overflow-auto`+`nowrap`), pas de perte.
- F3 « catégorie Tacos polluée » : réfuté — la catégorie 5 réelle ne contient que les 3 Tacos ; le symptôme n'existe qu'en vue « Toutes »/recherche.

## Re-vérification post-heals (captures `tests/captures/goal-s2-v1-recheck-2026-07-29/`, serveur worktree :8010, build prod)
| Point | Méthode | Verdict |
|---|---|---|
| V-01 header POS 1280×800 | capture r1 LUE | **PASS** — plus aucun chevauchement, clusters COMMANDES/CAISSE empilés, « Commande rapide » lisible, pilule bien placée |
| F2 libellés comptoir | capture r1 | **PASS** — « À ENCAISSER — COMPTOIR », plus de « borne » mensonger |
| V-08 tracker À encaisser | capture r2 LUE | **PASS** — colonne = 25 cartes + CTA Encaisser (avant : 0) ; fusion all-time + bucket cash-pending opérationnels |
| V-13 Oui/Non | grep bundle compilé | **PASS** — `"no":"Non"` dans `public/js/app.js` (drawer non ouvert au run, fix = clé i18n unique) |
| V-10 pagination FR | `trans()` serveur | **PASS** — « Précédent / Suivant » |
| V-15 message /m | curl :8010/m | **PASS** — « Aucune rupture signalée (produits & ingrédients) ✅ » |
| F3-data | UPDATE DB (3 lignes) + seeder | **PASS** — « Complément de commande » |
| Résidu détecté à la re-capture | r2 | sous-titre colonne tracker « Borne — paiement comptoir » → **corrigé** (`col_accept_subtitle`, rebuild au prochain build V5) |

## Gates owner ouvertes (notées, on continue)
1. Poser `DAILY_BOOK_PIN` réel dans le `.env` du poste (le carnet est désormais fail-closed sans lui — dev inclus).
2. Wizard caisse : format monétaire « €6.90 » → LOCK pos-wizard.js si l'owner veut le format FR.
3. Purge des items/catégories E2E historiques INACTIVE (ids 13-15, 22-25, 61-64, 77-80).
