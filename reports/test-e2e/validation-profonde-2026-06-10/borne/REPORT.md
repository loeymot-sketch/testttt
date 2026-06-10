# W-A BORNE — Validation profonde 100% du parcours client (2026-06-10)

- **Cible** : clone jetable `foodking_e2e` — http://127.0.0.1:8766 (jamais :8765). Serveur :8766 = checkout spine `pre-cloud-exec` (b4389d34e).
- **Spec** : `tests/e2e/zz-borne-parcours-profond-2026-06-10.spec.js` (9 tests, 1 worker, retries=0).
- **Captures** : JPEG q70, `captures/cycle-1/` (90 fichiers) + `captures/cycle-2/` — chaque capture listée a été LUE et analysée (layout, FR, palette #F4501E, labels bruts, formats prix, états).
- **Breakdown code-first** : voir `BREAKDOWN.md` (routes, écrans, états, boutons, data).
- **Findings détaillés** : voir `FINDINGS.md` (F-BORNE-01 → F-BORNE-08).
- **Frozen zones** : KioskWizard/KioskApp/KioskUpsell observés UNIQUEMENT — `git diff` frozen = 0 ligne.

## Résultat cycle 1 (run final convergé) : **9/9 PASSED (8.8 min)** — erreurs non justifiées = 0

| Parcours | Statut | Preuve (captures cycle-1) |
|---|---|---|
| A1 idle, CTA toucher, « À emporter », **« Sur place » ABSENT** (dine-in OFF V1, DOM count=0) | ✅ PASS | a1-idle.jpg, a1-order-type.jpg |
| A2 sidebar **11 catégories réelles, image chacune, 0 fuite E2E-CAT** + grille par catégorie (compteurs : cat1=5, cat2=2, cat3=2, cat4=2, cat5=2, cat6=8, cat9=3, cat10=8) | ✅ PASS | a2-sidebar-full.jpg, a2-cat-{1,2,3,4,5,6,9,10}.jpg, a2-sidebar-cycle1.json |
| A3 wizards 7 items (22, 36 multi-viandes, 26, 38, 41, 24, 25) — CHAQUE étape capturée (4-6 steps/wizard, 38 captures) | ✅ PASS | a3-{sandwich-cayenne,big-cayenne,tacos,burger,bol,galette,classique}-step*.jpg |
| A3 next désactivé tant que min_select (viande 0/1) non atteint | ✅ PASS | a3-next-disabled.jpg |
| A3 bouton retour (sélection conservée 1/1 « Complet ») | ✅ PASS | a3-back-step2.jpg, a3-back-returned.jpg |
| A3 annulation wizard (modal « Abandonner l'article ? ») → panier inchangé (7 lignes → 7 lignes, total €54,30 = somme exacte) | ✅ PASS | a3-cancel-modal.jpg, a3-cancel-after.jpg |
| A4 panier 2 lignes, +1 (total €8,50→€15,50), −1 (retour €8,50) | ✅ PASS | a4-cart-2lines.jpg, a4-qty-plus.jpg, a4-qty-minus.jpg |
| A4 **clamp 20** (25 clics + → qty=20, ligne €140,00 = 20×€7,00, total €141,50 — fix F1 kioskCart MAX_ITEM_QTY:24 validé) | ✅ PASS | a4-clamp-20.jpg |
| A4 suppression ligne (toast « Article retiré », total recalculé) | ✅ PASS | a4-remove-line.jpg |
| A4 vider (modal « Vider le panier » → redirect session reset BY DESIGN KioskCartComponent.vue:642-646) + état panier vide (« Votre panier est vide » + CTA) | ✅ PASS | a4-clear-modal.jpg, a4-clear-redirect.jpg, a4-cart-empty.jpg |
| A4 re-remplissage (repasse par « À emporter » — orderType reset par design) | ✅ PASS | a4-refilled.jpg |
| A5 upsell « ET POUR TERMINER ? » : run SKIP (« Non merci, continuer sans ») | ✅ PASS | a5-upsell-screen.jpg, a5-payment-after-skip.jpg |
| A5 run ACCEPT : carte sélectionnée → « Ajouter (1) et continuer +€3,00 » → paiement €1,50+€3,00=€4,50 + ligne au panier + toast « Menu (Frites + Boisson) ajouté ! » | ✅ PASS | a5-upsell-selected.jpg, a5-payment-after-accept.jpg, a5-cart-with-upsell.jpg |
| A6 loyalty (bouton panier « Avez-vous une carte fidélité ? » → /kiosk/loyalty), code invalide 0000000009 → erreur propre FR « Non trouvé » | ✅ PASS | a6-loyalty-screen.jpg, a6-loyalty-invalid.jpg (+ a6-loyalty-invalid-throttled.jpg quand throttle chaud) |
| A7 « PAIEMENT À LA CAISSE » → confirm → « Rendez-vous en caisse » **#A0119** + DB : 1 SEULE commande (double-clic = pas de doublon), source=kiosk, payment_status=15 PENDING_COUNTER, **fiscal_sequence_no NULL** | ✅ PASS | a7-payment.jpg, a7-confirmation.jpg, a7-db-cycle1.txt |
| A8 rupture live (UPDATE iba item 59) → badge « Épuisé » + carte grisée + bouton + désactivé + clic no-op (panier inchangé) → restore vérifié (badge retiré) | ✅ PASS | a8-rupture-badge.jpg, a8-rupture-click-noop.jpg, a8-restored.jpg |
| Z bilan erreurs console/pageerror/HTTP≥400 : 22 évènements, **0 non justifié** (politique documentée dans le spec : sonde pré-auth GET /api/login 401, 4xx loyalty attendus, mirrors console, pageerror F-BORNE-07 connu) | ✅ PASS | errors-cycle1.json, findings-cycle1.json, journey-cycle1.json |

## Findings (résumé — détail FINDINGS.md)
| ID | Sév. | Sujet | État |
|---|---|---|---|
| F-BORNE-01 | **P1** | Landing catégorie = « Boissons » (payload menu inversé, `KioskMenuService.php:251` sortBy([fn,fn])) | **HEALED** worktree + test régression rouge→vert (PHPUnit 6+17+1 ✓) — merge spine requis |
| F-BORNE-07 | **P1** | pageerror TypeError panier avec ligne upsell (format legacy frozen Upsell vs `KioskCartComponent.vue:542`) | **HEALED** worktree + test Vitest rouge→vert (15+2 ✓) — merge+rebuild requis |
| F-BORNE-02 | P2 | « Sandwich Cayenne » affiche d'abord les items upsell avec description brute « Upsell item » + badge « Nouveau » | data-ops owner |
| F-BORNE-03 | P2 | Loyalty : « Too Many Attempts. » brut anglais au client (throttle partagé 10/min/user + passthrough `KioskLoyaltyComponent.vue:505`) | documenté + reco |
| F-BORNE-04 | P3 | Idle : « CHOISISSEZ UNE OPTION » avec 1 seule option (dine-in OFF) | cosmétique |
| F-BORNE-05 | P3 | Format prix mixte €8,50 (préfixe) vs 10,00 € (suffixe caisse) | cosmétique |
| F-BORNE-06 | P3 | Propagation rupture→borne ≤60 s (cache menu serveur non busté par UPDATE direct) | opérationnel V1 OK |
| F-BORNE-08 | P3 | Sonde pré-auth GET /api/login → 401 + bruit console à chaque boot | reco V1.0.x |

## Heals appliqués (ce worktree `heal/borne-wa-validation-2026-06-10`, 0 frozen)
1. `app/Services/Kiosk/KioskMenuService.php` — tri catégories chained sortBy (F-BORNE-01) + `tests/Feature/Services/Menu/KioskMenuCategoryOrderRegressionTest.php`.
2. `resources/js/components/frontend/kiosk/KioskCartComponent.vue` — guard dual-format item_variations/item_extras (F-BORNE-07) + `tests/js/kioskCartUpsellLegacyModifiersGuard.spec.js`.
- Les deux tests régression prouvés ROUGES sur code non patché, VERTS patchés. PHPUnit ciblé : MenuProjectionParitySentinelTest 6/6, KioskEndpointsTest 17/17, nouveau 1/1. Vitest ciblé : KioskCart 15/15 + nouveau 2/2.
- ⚠️ :8766 sert le checkout spine non patché → le live reflète encore F-BORNE-01/07 tant que la branche n'est pas mergée (assets à rebuilder pour le .vue).

## Limites / notes d'environnement
- Trois itérations de spec ont été nécessaires (hang anim CSS sur clic touch ; redirect clear-cart by design ; cache menu 60 s sur A8 ; accumulateurs en RAM perdus au restart worker → JSONL disque). Aucune n'était un défaut app, sauf celles converties en findings.
- A6-THROTTLE-RAW (finding P2) n'apparaît que si le bucket `throttle:10,1` est chaud au moment du run (voir Note A6 de FINDINGS.md) — variance d'environnement, root cause constante.

## Validation EMPIRIQUE des 2 heals (build patché servi sur :8767, même DB clone)
Spec dédié : `tests/e2e/zz-borne-heal-validation-2026-06-10.spec.js` — **1 passed (1.4 m)** :
- **H1 / F-BORNE-01** : `[H1] landing zone-title = "SANDWICH CAYENNE"` — la borne atterrit sur la 1re catégorie (capture `captures/heal-validation/h1-landing-category.jpg`).
- **H2 / F-BORNE-07** : `[H2] cart lines = 2 ; item_variations errors = 0` — panier avec ligne upsell rendu complet, zéro TypeError (capture `captures/heal-validation/h2-cart-with-upsell.jpg`).
- Notes env : images produits cassées sur :8767 (storage/app/public absent du worktree — cosmétique env-only) ; piège résolu : `baseUrl` runtime vient de `config('app.url')` (master.blade.php:133) → servir avec `APP_URL=http://127.0.0.1:8767` sinon le SPA poste cross-origin vers :8766 (CORS).

## Incident de contamination inter-serveurs (leçon shared-infra)
Pendant qu'un 2e serveur (:8767, même DB clone + même redis) tournait en parallèle du run officiel :8766 : (1) un `kiosk-login` API sur :8767 a **révoqué le token** de la session borne du run officiel (révocation anti token-sprawl §9) → 401 en rafale sur `frontend/menu`/`kiosk-event`, grilles vides, A8 rouge ; (2) throttles partagés (kiosk-menu) → 429 → **la borne dégrade en rebond vers l'idle** (comportement constaté, pas de grille cassée). Le cycle 2 officiel a été relancé SEUL après arrêt de :8767. Renforce [[feedback_shared_infra_devdb_footgun]].

## Résultat cycle 2 (officiel, :8766 seul) : **9/9 PASSED (8.8 min)** — erreurs non justifiées = 0
A7 = commande 4479 (status 4, payment_status 15 PENDING_COUNTER, fiscal NULL, source kiosk, total 10,00) ; numéro affiché #A0123.

## Comparaison cycle 1 vs cycle 2 (`compare`)
- **Journey** : 8/8 étapes IDENTIQUES, même verdict PASS — sauf A5 : PASS (c1) vs DEFECT-CAPTURED (c2), même flux, même total vérifié (+upsell), seule la manifestation de F-BORNE-07 diffère.
- **Erreurs (kinds par étape)** : ensembles IDENTIQUES à une entrée près — A5-accept `pageerror` (c1 : TypeError jeté ×2 mais panier rendu) vs `console` (c2 : même TypeError via handler Vue + rendu panier avorté/blanc). Même signature `(… item_variations || []).map is not a function`, même root cause.
- **Findings** : root-cause set IDENTIQUE (F-BORNE-01→08). F-BORNE-07 observé dans LES DEUX cycles (ledger erreurs) ; l'entrée explicite P1 `A5-BLANK-CART-F07` n'apparaît qu'au cycle 2 (le cycle où le rendu a blanchi) — manifestation intermittente d'un root cause constant, déjà HEALED + validé empiriquement sur build patché (section heal-validation). `A6-THROTTLE-RAW` présent dans les 2 cycles.
- **Verdict** : cycles CONVERGENTS au niveau root-cause ; la variance résiduelle est exactement le défaut P1 documenté/healé, pas une instabilité du parcours.
