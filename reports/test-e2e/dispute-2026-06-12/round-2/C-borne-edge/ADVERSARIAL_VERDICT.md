# ADVERSARIAL VERDICT — Vague C borne-edge (Round 2 post-heal, dispute-2026-06-12)

- Superviseur adversarial R2. App http://127.0.0.1:8768 (PID 38797, cwd = CE worktree — provenance re-vérifiée), DB foodking_e2e jetable. Bundle `public/js/app.js` 2026-06-12 13:07 (post-heals, post wire-up `956933ec5`).
- Heals à juger dans ma vague : C-RED-01 (H1 Fix2 `c0518cf50`), C-RED-02 (H1 Fix3 `00dcbffda` + wire-up orchestrateur `956933ec5`), C-RED-03 (H2 Fix4 `1eeb3b2ed`), C-ADV-01 (H2 Fix5 `36d71fbc2`), C-ADV-06 (H2 Fix6 `3538e1a04`), ADV-F-P1-2 (H1 Fix9 `bbeecd437` + seeder exécuté).
- WAVE_REPORT R2 GStack présent mais agent COUPÉ après l'état C20 (C30 artefacts sans section ; C-RED-03 et C-ADV-01-champ-promo jamais couverts par le GStack — je les couvre moi-même).

## ÉTAT INITIAL (vérifié moi-même, pas hérité)
- `kiosk_promos` #1 BORNEAUDIT5 amount 5,00 active=1 **uses_count=4**
- user 44 « Victim Secret » VICT1234 status=**5**, **loyalty_points=0** (les 165 pts brûlés par le test C12 du GStack — ledger 3 rows) → re-crédit documenté requis pour MA repro fidélité
- max(orders.id)=4532 au boot de ma session
- Gate statut RE-GREPPÉE moi-même : `app/Services/Order/OrderQuoteService.php:272` `->where('status', 1)` + `app/Services/FrontendOrderService.php:936` `->where('status', 1)` ; `app/Enums/Status.php:7` `ACTIVE=5` → le claim C-RED-02-R2 du WAVE_REPORT est structurellement réel. Wire-up confirmé : `resources/js/store/modules/kioskCart.js:183` `loyalty_redeem_discount: state.loyaltyDiscount || 0`.
- Trace `_c11c-trace.json` relue : quote req PORTE `loyalty_redeem_discount:1.65` → resp `discount:0` ; order 4529 `discount:0,total:6.5` — la réfutation GStack est self-consistent.

## 1. VISUEL — PNG R2 GStack relus (échantillon par état unique, 11 PNG lus pixel par pixel)
- `c10-02` panier : ligne « Code promo BORNEAUDIT5 −5,00 € » + bannière verte + « Retirer le code », total 6,50 €. `c10-04` cash : #A0004 / 6,50 € + copy « Réglez à la caisse — espèces, carte ou ticket restaurant. » + CTA « RETOUR À L'ACCUEIL » + countdown 41 s. Layout sain.
- `c11-03` : run c11, la fidélité n'a même PAS été appliquée côté UI (bannière encore « Avez-vous une carte fidélité ? », total 6,50) — payment c11-04 = 6,50. Le run probant est **c11b** : payment `c11b-03` = **4,85 €**, cash `c11b-04` = **6,50 €** + toast bas « ⚠ Votre réduction fidélité n'a pas pu être appliquée (points insuffisants au moment du paiement). Votre commande a été validée sans réduction. » — toast `role="alert" aria-live="assertive"` (DOM re-greppé) **qui CHEVAUCHE le CTA « RETOUR À L'ACCUEIL »**.
- `c12-02` (mutation status=1) : 2 lignes remise distinctes (fidélité −1,65 + promo −5,00), total 4,85 ; `c12-04` cash #A0019 4,85 — la mécanique marche quand le lookup passe.
- `c20-01` : grille Sandwich Cayenne = 2 produits seulement (7,00 / 9,50) — les 3 SKU techniques absents. ✅
- `c30-02/03` : toast rouge « **Article 34** indisponible dans le catalogue. Commande rejetée. » — ID interne exposé client, ligne « Grande Frites » toujours dans le panier SANS marquage, 2e checkout = même boucle.

## 2. TECHNIQUE — quartets
- DOM R2 corrigé (#app outerHTML) : exploitable ✅ (re-grep role=alert, textes toasts, badges — pas de P1 process).
- Console/network agrégés sur 14 quartets : 12×401 `/api/login` + 401 mid-flow one-shot (pricing/preview, menu, order/quote, loyalty/check, promo/validate, order) = guerre de révocation token entre agents parallèles partageant le compte machine — pattern « 401 one-shot » = gate connue, non recompté. 422 order/quote ×2 = rejet rupture C30 attendu (validation). 429 loyalty/check ×3 = throttle exercé volontairement. **Aucun 4xx/5xx silencieux nouveau** : chaque 4xx a un feedback UI (toast/inline).
- `_c11c-trace.json` : quote req `loyalty_redeem_discount:1.65` → resp `discount:0` ; order 4529 discount 0. Trace SANS 401 — l'échec fidélité n'est PAS la guerre de tokens.

## 3. LIVE — mes propres vérifications (script `tests/e2e/_d2red-C-borne-edge-1.mjs`, log `_d2red-log.txt`, captures `d2r-*`)
- **Mission A (promo, vérif DB OBLIGATOIRE)** : Galette 6,50 → BORNEAUDIT5 essai 1 → panier promo −5,00 / total **1,50** → payment « TOTAL À RÉGLER : 1,50 € » → confirm → cash **#A0022 / 1,50 €**. UI cohérente bout-en-bout. (DB ci-dessous §3bis.)
- **Mission B (fidélité client RÉEL status=5, 165 pts re-crédités par mutation SQL documentée)** : balance « 165 points = 1,65 € » → panier loyalty **−1,65 € / total 4,85** → payment **4,85 €** → confirm → cash **#A0023 / 6,50 €** + toast trompeur « points insuffisants au moment du paiement » (les points étaient là ; la vraie cause = gate `status=1`). **REPRODUIT PAR MOI — le heal C-RED-02 ne fonctionne PAS pour les clients réels.**
- Inline fidélité 429 vu en passant : « Trop de tentatives, patientez quelques secondes. » (FR ✅).
- Toast fallback = garde pré-existante AUDIT-P2 (`KioskPaymentComponent.vue:650-654`, race points) — elle attrape le symptôme avec un DIAGNOSTIC FAUX exposé au client.

## 3bis. LIVE (suite) — missions C/D/E + vérifs DB OBLIGATOIRES
- **Mission C (heal C-RED-03)** : kioskSettings idleMs=12s/confirmMs=4s → /kiosk/payment Plan B → **overlay « Toujours là ? Votre commande sera effacée dans 4 secondes » vu à ~6 s** (capture `d2r-c-payment-overlay.png`, boutons « JE SUIS LÀ » / « ABANDONNER LA COMMANDE ») → **sortie payment → /kiosk/idle à ~10 s, panier purgé (0 ligne persistée)**. Le trou R1 (25 s sans timer) est fermé, frozen intouché (timer porté par KioskPaymentComponent:518-541, re-greppé).
- **Mission D+D2 (heal C-ADV-01)** : burst UI 12 codes invalides = chemin métier FR (pas de throttle, limite 30/min `routes/api.php:1488`) → script dédié `_d2red-C-borne-edge-2-429.mjs` : burst in-page 34 POST avec les headers app → **429 ×2 forcés** → essai UI immédiat → **inline FR « Trop de tentatives, patientez quelques secondes. »** + toast « Trop de requêtes — patientez 48s avant de réessayer. » (capture `d2r-d2-promo-429-inline.png`). Plus aucun « Too Many Attempts. ». Clefs `kiosk.promo.error.*` présentes fr+en (re-greppé), mapping kioskCart.js:649-656 re-lu.
- **Mission E (upsell)** : mon SQL pool = **0** (`items status=5, non supprimés, is_upsell=5 OU is_featured=5, catégorie incluse` → COUNT 0 ; tous les is_featured=5 restants sont soft-deleted, les 3 véhicules défeaturés is_featured=10 cat 27) — converge avec l'artefact GStack `_c20-kiosk-upsell-api.json` = `{"data":[]}`. L'écran upsell borne est MORT (auto-skip no_suggestions, frozen observé seulement).
- **Mission F (C-ADV-02, reload)** : promo appliquée 6,50→1,50 → **reload → promo TOUJOURS là** (re-validation serveur, `_d2red-promoreload-log.txt`, capture `d2r-e-cart-promo-after-reload.png`). FERMÉ.

### Vérifs DB (refaites MOI-MÊME, mysql foodking_e2e)
| Quoi | Résultat |
|---|---|
| Order **4536** (#A0022, ma promo) | subtotal 6.500000 / **discount 5.000000 / total 1.500000** = écran(1,50)=API(201 total 1.5 discount 5)=DB ✅ |
| `kiosk_promos.uses_count` | **4 → 5** (+1 = ma commande) ✅ consommation réelle |
| Order **4537** (#A0023, ma fidélité status=5) | **discount 0.000000 / total 6.500000** vs panier/payment 4,85 € affiché ❌ |
| user 44 points | **165 → 165 (jamais débités)** ; ledger user 44 inchangé (3 rows, dernier = order 4532 du test status=1 GStack) ❌ |
| Gate re-greppée | `OrderQuoteService.php:272` + `FrontendOrderService.php:936` `->where('status', 1)` ; `Status::ACTIVE=5` ; LoyaltyController.php:100-105 documente EXACTEMENT ce piège (« the prior == 1 gate 404'd caisse-created customers ») avec le helper canonique `isCustomerActive()` que H1 n'a pas utilisé |

---

## 4. FINDINGS R2 (ouverts)

### C-RED-02-R2 — **P0 SURVIVANT** (numeric_integrity) : rachat fidélité borne — promesse écran 4,85 € / facturation 6,50 € pour TOUT client réel (status=5), root cause RELOCALISÉE du frontend (réparé) vers la gate backend `status=1`
- **Ma repro UI+API+DB** (order 4537 ci-dessus) + repro GStack ×2 (orders 4524/4529, trace c11c SANS 401). Le wire-up `loyalty_redeem_discount` (kioskCart.js:183) traverse bien quote+order (trace) — c'est le SERVEUR qui refuse silencieusement : lookup `User::where('loyalty_code',…)->where('status', 1)` rate les clients status=5 (= seed + créés caisse = la population de prod).
- Atténuation : toast `role=alert` « …Votre commande a été validée sans réduction. » (garde pré-existante AUDIT-P2, KioskPaymentComponent.vue:650-654) — MAIS (a) le client a déjà confirmé au prix faux, (b) la raison affichée « points insuffisants au moment du paiement » est FAUSSE (165 pts disponibles), (c) le toast chevauche le CTA « RETOUR À L'ACCUEIL » (c11b-04).
- Fix attendu : `whereIn('status', [1, Status::ACTIVE])` ou helper `isCustomerActive` aux 2 sites (parité LoyaltyController) — 1 ligne ×2 + test factory status=5.

### C-R2-NEW-1 — **P1 NOUVEAU** : écran upsell borne MORT (auto-skip permanent) — régression collatérale du seeder ADV-F-P1-2
- Pool upsell = 0 (mon SQL ci-dessus) : les SEULS items featured vivants étaient les 3 véhicules techniques, que `HideUpsellVehicleItemsFromGridSeeder` a défeaturés. `GET /api/frontend/item/kiosk-upsell` → `{"data":[]}` → `KioskUpsellComponent` (frozen, observé) auto-skip `no_suggestions` → checkout saute panier→payment en ~2 s. R1 affichait l'écran (c1-11/c1b-09/c1e-05) — la surface merchandising a disparu EN SILENCE post-heal. Fix DATA-only : flagger `is_upsell=5` de vrais add-ons (desserts/boissons) — zéro frozen.

### C-R2-NEW-2 — **P1 NOUVEAU** : rupture produit en session → checkout rejeté en boucle avec « **Article 34** indisponible dans le catalogue. Commande rejetée. » — ID interne exposé, ligne jamais marquée
- Artefacts GStack c30-02/c30-03 (re-lus) + log `_c30-log.txt` : item 34 (Grande Frites) passé indisponible → checkout → toast FR role=alert mais « Article 34 » = jargon DB (le client ne sait PAS quelle ligne retirer), la ligne reste dans le panier SANS badge/strike, 2e checkout = même boucle. Source : `app/Services/Menu/AvailabilityService.php:247` (message serveur rendu verbatim). Cul-de-sac sur le chemin d'achat → P1. (Le path mount-prune silencieux C-ADV-07 reste lui aussi ouvert, P2.)

### P2 ouverts
- **COV-2 (process)** : agent GStack R2 coupé AVANT les états C30 (artefacts sans section), C-RED-03 et C-ADV-01-champ-promo (jamais couverts par lui — couverts par moi). Moins grave que COV-1 R1 (5/6 missions ont des artefacts).
- **C-ADV-07 (survivant, non healé)** : prune silencieux des lignes panier au mount (chemin non-broadcast) — hors liste heals R1, non re-couvert R2.
- **C-RED-04 (survivant, gate owner DATA)** : allergènes invisibles avant ajout + 44/45 items sans data.

### P3 ouverts
- **C-ADV-04** (option « −0,00 € » confirmable), **C-RED-05** (preview 0,27 € TVA résiduelle sur panier 100 % remisé — edge non re-testé), **C-ADV-09** (écran erreur produit-retiré orphelin), **C-R2-NEW-3** (toast warning chevauche le CTA « RETOUR À L'ACCUEIL » sur cash-instruction, c11b-04).

---

## 5. CONVERGENCE findings R1 → R2
| R1 | Sev R1 | Statut R2 | Preuve |
|---|---|---|---|
| C-RED-01 promo jamais facturée | P0 | **FERMÉ** | Ma commande 4536 : écran=API=DB 1,50 €, discount 5.0, uses_count 4→5 ; + GStack 4515 |
| C-RED-02 fidélité jamais facturée | P0 | **SURVIVANT (P0, C-RED-02-R2)** | Root cause relocalisée backend `status=1` ; ma commande 4537 (UI 4,85 / DB 6,50, points intacts) |
| C-ADV-01 « Too Many Attempts. » EN | P1 | **FERMÉ** | 429 forcé ×2 → inline FR « Trop de tentatives… » (`d2r-d2-promo-429-inline.png`) |
| C-RED-03 payment sans timeout | P1 | **FERMÉ** | Overlay ~6 s, leave ~10 s → idle, panier purgé (`d2r-c-payment-overlay.png`) |
| COV-1 missions sans artefact | P1 | **PARTIELLEMENT FERMÉ → COV-2 P2** | WAVE_REPORT R2 re-coupé (C30 sans section, 2 vérifs manquantes faites par moi) |
| C-ADV-02 promo perdue au reload | P2 | **FERMÉ** | Reload → promo restaurée re-validée serveur (mission F live) |
| C-ADV-05 toast « Session rafraîchie » | P2 | **FERMÉ** | Swallow `kioskAuthInterceptor.js:84-100` re-grep + 0 occurrence dans les 14 DOM R2 |
| C-ADV-06 « espèces uniquement » | P2 | **FERMÉ** | c10-04 + ma `d2r-a-cash` : « Réglez à la caisse — espèces, carte ou ticket restaurant. » + fr.json:1715 re-grep |
| C-ADV-07 prune silencieux mount | P2 | **SURVIVANT** | Non healé, non re-couvert ; le path checkout a maintenant un toast mais cf. C-R2-NEW-2 |
| C-ADV-08 reset borne détruit panier | P2 | **FERMÉ (code+tests, non re-vérifié live)** | H2 `0438406eb` recovery `?recovered=1` + Vitest ; déclencheur (rotation token) non force-able proprement en R2 |
| C-RED-04 allergènes | P2 | **SURVIVANT** | Gate owner DATA (44/45 items vides) |
| C-ADV-04 « −0,00 € » confirmable | P3 | SURVIVANT | Non healé |
| C-RED-05 preview 0,27 € | P3 | SURVIVANT (edge non re-testé) | À trancher avec le moteur quote (désormais TTC-aware `discountedKioskTotal`) |
| C-ADV-09 écran erreur orphelin | P3 | SURVIVANT | Non câblé |

## 6. VERDICTS HEALS (mon périmètre)
| Heal | SHA | Verdict | Preuve |
|---|---|---|---|
| C-RED-01 promo facturée + uses_count (H1 Fix2) | `c0518cf50` | **CONFIRMED** | Ma vérif live+DB obligatoire : 4536 discount 5/total 1,50, uses_count 4→5, écran=API=DB au centime ; anti-tamper non re-testé mais PHPUnit 5/5 |
| C-RED-02 fidélité facturée + débit (H1 Fix3 + wire-up orchestrateur) | `00dcbffda` + `956933ec5` | **PARTIAL** | Wire-up frontend prouvé (payload trace c11c + kioskCart.js:183) ; mécanique complète prouvée UNIQUEMENT pour status=1 (c12 : order 4532 discount 6,65, points 165→0, ledger, uses_count+1) ; **RÉFUTÉ pour tout client réel status=5** (ma repro 4537) — gate `status=1` réintroduite contre le précédent documenté LoyaltyController:100-105 ; les tests H1 passent car factories status=1 |
| C-RED-03 timeout payment Plan B (H2 Fix4) | `1eeb3b2ed` | **CONFIRMED** | Live : overlay 6 s, sortie 10 s → idle, panier purgé ; jamais armé en submit (non re-testé, Vitest 8/8) ; frozen intouché |
| C-ADV-01 429 FR champ promo (H2 Fix5) | `36d71fbc2` | **CONFIRMED** | 429 forcé (30/min épuisé par burst app-headers) → inline FR live + capture ; loyalty inline FR aussi vu (mission B essai 1) |
| C-ADV-06 copy paiement unifié (H2 Fix6) | `3538e1a04` | **CONFIRMED** | Visuel ×3 (c10-04, c11b-04, d2r-a-cash) + fr.json re-grep — 3 modes mentionnés, plus de « uniquement » |
| ADV-F-P1-2 SKU techniques hors grille (H1 Fix9 + seeder) | `bbeecd437` | **PARTIAL** | Grille nettoyée CONFIRMÉE (c20-01 visuel + DB cat 27 admin-only + formule menu toujours commandable C10/C12) ; MAIS régression collatérale **C-R2-NEW-1 P1** : le seeder a défeaturé les 3 seuls items featured vivants → pool upsell 0 → écran upsell borne mort en silence |

## 7. DISPUTE du WAVE_REPORT R2 GStack
| Claim GStack | Verdict |
|---|---|
| C10 : C-RED-01 + C-ADV-06 confirmés | **UPHELD** — re-vérifié indépendamment (ma commande 4536 + visuels) |
| C11 : C-RED-02 réfuté pour status=5, root = gate `status=1` ×2 | **UPHELD** — re-greppé + reproduit moi-même (4537) ; nuance : leur tableau symptôme agrège les runs (le run c11 n'a même pas appliqué la fidélité côté UI, payment 6,50 ; le probant = c11b/c11c) ; ils ont OMIS le toast fallback role=alert (raison fausse) que j'ajoute au dossier |
| C12 : mécanique OK via mutation status=1 (restaurée) | **UPHELD** — DB relue (4532 discount 6,65, ledger, uses 3→4), user 44 re-trouvé status=5 points 0 (j'ai re-crédité 165 pour MA repro, documenté) |
| C20 : grille confirmée + upsell mort P1 | **UPHELD** — pool 0 re-calculé moi-même en SQL |
| C30 : (aucune section — agent coupé) | Jugé par MOI depuis les artefacts → C-R2-NEW-2 P1 |

## RÉSUMÉ
| Sev | n ouverts | IDs |
|---|---|---|
| P0 | 1 | C-RED-02-R2 (survivant, gate backend status=1) |
| P1 | 2 | C-R2-NEW-1 (upsell mort, régression seeder), C-R2-NEW-2 (rupture « Article 34 » cul-de-sac) |
| P2 | 3 | COV-2, C-ADV-07, C-RED-04 (gate owner DATA) |
| P3 | 4 | C-ADV-04, C-RED-05, C-ADV-09, C-R2-NEW-3 |

**VERDICT VAGUE C ROUND 2 : RED** — 1 P0 survivant (fix 1-ligne ×2 identifié, parité `isCustomerActive`) + 2 P1 nouveaux (1 régression collatérale DATA-only, 1 copy/UX rupture). Heals : 4 CONFIRMED / 2 PARTIAL / 0 REFUTED sec.

## Artefacts adversariaux R2
- Scripts : `tests/e2e/_d2red-C-borne-edge-1.mjs` (missions A/B/C/D/E), `_d2red-C-borne-edge-2-429.mjs`, `_d2red-C-borne-edge-3-promoreload.mjs`
- Logs : `_d2red-log.txt`, `_d2red-429-log.txt`, `_d2red-promoreload-log.txt`, `_d2red-orders.json`
- Captures : `d2r-a-cart-promo`, `d2r-a-payment`, `d2r-a-cash`, `d2r-b-cart-loyalty`, `d2r-b-payment`, `d2r-b-cash`, `d2r-c-payment-overlay`, `d2r-c-payment-after-idle`, `d2r-d-promo-429-inline`, `d2r-d2-promo-429-inline`, `d2r-e-cart-promo-after-reload` (+ quartets)
- DB : orders 4536/4537 ; uses_count 4→5 ; user 44 (status 5, points 165 re-crédités → 165 intacts post-4537, ledger 3 rows inchangé). Mutations documentées : `UPDATE users SET loyalty_points=165 WHERE id=44` (re-crédit pour repro, DB jetable).
