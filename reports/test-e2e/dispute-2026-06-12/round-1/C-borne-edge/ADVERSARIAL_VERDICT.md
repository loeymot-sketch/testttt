# ADVERSARIAL VERDICT — Vague C borne-edge (Round 1, dispute-2026-06-12)

- Superviseur adversarial. App http://127.0.0.1:8768, DB foodking_e2e. Viewport 1080×1920, chrome fr-FR.
- Périmètre vague : promo+fidélité même commande, qty composé >1, séquence file, allergènes, inactivité payment, double-tap réseau ralenti.
- États revus : 26 états uniques du run GStack (c1/c1b/c1c/c1d/c1e/c2, quartets PNG+DOM+console+network) + 8 captures de re-vérification live (`r1a..r5`, scripts `tests/e2e/_d1red-C-borne-edge-1.mjs` + `_d1red-C-borne-edge-2-quoteprobe.mjs`, log `_d1red-log.txt`).
- **VERDICT : RED.** 2 P0 d'intégrité monétaire sur la borne, prouvés UI + API + DB, sur la branche même que la « convergence » d'hier déclarait production-perfect.

## RÉSUMÉ
| Sev | n | IDs |
|---|---|---|
| P0 | 2 | C-RED-01 (promo affichée, jamais facturée → client surfacturé vs écran), C-RED-02 (fidélité −1,65 € affichée, commande pleine) |
| P1 | 3 | C-ADV-01 (i18n leak « Too Many Attempts. »), C-RED-03 (écran payment sans timeout Plan B → borne bloquée + fuite de commande), COV-1 (3 missions de la vague sans AUCUN artefact — agent coupé) |
| P2 | 6 | C-ADV-02, C-ADV-05, C-ADV-06, C-ADV-07, C-ADV-08, C-RED-04 |
| P3 | 3 | C-ADV-04, C-RED-05, C-ADV-09 |

---

## P0

### C-RED-01 — P0 numeric_integrity : code promo borne AFFICHÉ et confirmé à 0,00 € → commande créée et encaissable à 3,00 € (le quote ignore `kiosk_promo_code`)
- **Live in-SPA, AUCUN reload** (mission B, `_d1red-log.txt` 09:41:12→09:41:28) : panier Coca×2 3,00 € → BORNEAUDIT5 appliqué (ligne « −3,00 € », total **0,00 €**) → checkout → écran payment « TOTAL À RÉGLER : **0,00 €** » (capture `r2a-payment-zero.png`) → Confirmer → POST `frontend/order` **201 id=4566 total=3 discount=0** → écran cash « #A0020 / Montant à régler **3,00 €** » (capture `r2b-after-confirm-zero.png`).
- **DB re-vérifiée** : `orders` id 4566 → subtotal 3.000000, **discount 0.000000, total 3.000000** ; `kiosk_promos.uses_count` = **0** (jamais consommé).
- **Root cause (API probe `_d1red-quoteprobe-log.txt`)** : `POST /api/frontend/pricing/preview` avec `kiosk_promo_code` → `discount:3, discount_source:"kiosk_promo"` (c'est ce que le PANIER affiche) ; `POST /api/frontend/order/quote` avec le MÊME code → **discount:0, total_ttc:3**. Le pipeline commande signe le quote (kioskCart.js:170-177 `payload.discount = quote.discount`) et la commande naît sans promo. `OrderQuoteService.php` ne porte aucune logique d'application du promo : `kiosk_promo_code` n'apparaît qu'en métadonnée canonique (app/Services/Order/OrderQuoteService.php:416) alors que `PricingPreviewService.php:27` l'applique. Deux moteurs de prix divergents → l'écran promet, la caisse encaisse plein pot.
- Impact client : on lui dit GRATUIT (ou −5 €) jusqu'au dernier écran, puis le numéro de file réclame le plein tarif au comptoir. Catégorie 11 du protocole (cart total ≠ payment ≠ cash) — P0 sans discussion.
- Note anti-drift : un heal « promo dormante » existe sur la branche `heal/ultra-audit-w4-2026-06-11` (mémoire projet) — il n'est PAS sur cette branche ; la convergence d'hier sur CETTE branche n'a jamais poussé un promo jusqu'au checkout.

### C-RED-02 — P0 numeric_integrity : rachat fidélité « −1,65 € » affiché panier + payment 7,85 € → commande créée à 9,50 €, cash 9,50 €
- **Live** (mission A, `_d1red-log.txt` 09:40:37→09:41:03) : panier 9,50 € → fidélité 0612345678 (Victim Secret, 165 pts) → « Utiliser mes points −1,65 € » + Confirmer → panier « Réduction fidélité **−1,65 €** », total **7,85 €**, bandeau « Fidélité appliquée (−1,65 €) » (capture `r1b-cart-promo-plus-loyalty.png`) → payment « TOTAL À RÉGLER : **7,85 €** » → POST **201 id=4565 total=9.5 discount=0** → cash « #A0019 / **9,50 €** » (capture `r1c-cash-promo-loyalty.png`).
- **DB** : id 4565 discount 0.000000 total 9.500000 ; `users.id=44 loyalty_points` = 165 inchangé (aucun point brûlé — au moins pas de double peine).
- **Root cause (code re-greppé)** : `OrderQuoteService::withKioskLoyaltyDiscount` (app/Services/Order/OrderQuoteService.php:238-244) exige `request->input('discount') > 0` pour activer la redemption ; or `buildKioskQuotePayload` (kioskCart.js:160-176) envoie `loyalty_code` mais **jamais de champ `discount`** → la garde `requestedDiscount <= 0.0` court-circuite TOUJOURS. Probe API : quote avec `loyalty_code:'VICT1234'` → discount 0. L'affichage borne (loyaltyDiscount local, store) n'est jamais honoré par le serveur.
- Même famille de root cause que C-RED-01 (préview ≠ quote), mécanisme distinct (garde discount>0 vs absence totale de logique promo). Fix hint : envoyer le discount demandé dans le quote payload OU calculer la redemption côté quote à partir de loyalty_code seul.

## P1

### C-ADV-01 — P1 i18n_leak : « Too Many Attempts. » EN brut inline sous le champ code promo
- Visuel : `c1b-04-cart-promo.png` (inline rouge « Too Many Attempts. » sous l'input, pendant que le toast haut-droit est FR « Trop de requêtes — patientez 11s avant de réessayer. ») + `c1c-04a-promo-fail-inline.png` ; network 429 `frontend/promo/validate`.
- Code : kioskCart.js:599-600 `const message = err?.response?.data?.message || 'kiosk.promo.error.network'; commit('SET_PROMO_ERROR', message)` ; rendu verbatim KioskCartComponent.vue:310 `{{ $te(promoError) ? $t(promoError) : promoError }}`. Toute erreur serveur non-clef i18n (429, 500) fuit en anglais sur l'écran client. Contraste : l'écran fidélité gère le même 429 en FR (`c1b-06-loyalty-balance.png` « Trop de tentatives, patientez quelques secondes. »).

### C-RED-03 — P1 : l'écran payment n'a AUCUN timeout (noTimerRoutes) alors que Plan B = pas de TPE → borne bloquée sur la commande du client parti + fuite de commande possible
- **Prouvé live** (mission D) : kioskSettings idleMs=12s/confirmMs=4s → overlay inactivité apparaît bien sur le PANIER (≈10 s), mais sur `/kiosk/payment` **25 s sans aucun overlay ni redirect** (capture `r4-payment-25s-sans-timer.png`, log 09:42:37→09:43:03) — l'écran reste indéfiniment.
- Code : KioskAppComponent.vue:881 `noTimerRoutes = ['kiosk.idle','kiosk.waiting','kiosk.payment','kiosk.confirmation']` — justification AUDIT-52-BUG3 = « client interagit avec le TPE physique ». Or en V1 Plan B (`kiosk.payment_route_all_to_counter=true`, config/kiosk.php:161) il n'y a PAS de TPE : cet écran est un simple « Confirmer ma commande ». Un client qui abandonne ici laisse la borne bloquée sur SON panier ; le client suivant n'a qu'à toucher « Confirmer » pour envoyer la commande abandonnée en caisse (numéro de file orphelin, gaspillage cuisine potentiel). L'écran cash-instruction, lui, a son auto-redirect 45 s — l'asymétrie est exactement sur l'écran où l'argent se décide.
- ⚠ KioskAppComponent.vue = FROZEN — fix = gate owner (retirer `kiosk.payment` de noTimerRoutes quand route_all_to_counter=true, ou timer long dédié).

### COV-1 — P1 process : 3 des 6 missions de la vague C n'ont AUCUN artefact GStack (agent coupé) + section « intégrité numérique » du WAVE_REPORT vide
- WAVE_REPORT s'arrête après C2. Allergènes / inactivité payment / double-tap : scripts écrits (`_d1-C-c3-allergens.mjs`, `_d1-C-c5-inactivity.mjs`, `_d1-C-c6-doubletap.mjs`) mais **jamais exécutés** (0 artefact c3/c5/c6 dans le dossier). La mission phare « promo+fidélité même commande » n'a JAMAIS atteint le checkout dans leurs 4 tentatives (résets S4/S5) — c'est précisément le parcours qui cachait les 2 P0. J'ai re-couvert les 3 missions moi-même (résultats ci-dessus + PASS ci-dessous).

## P2

### C-ADV-02 — P2 : promo non persistée au reload (asymétrie avec fidélité) → remise silencieusement perdue
- Visuel : `c1-05` (total 0,00 € promo appliquée) → reload → `c1-09` (même panier 3,00 €, ligne promo disparue, bandeau revenu à « Avez-vous une carte fidélité ? ») → payment 3,00 → cash #A0008 3,00.
- Code : store/index.js:283-296 persiste `kioskCart.items/loyaltyDiscount/loyaltyCustomer/idempotencyKey/orderType/kioskToken` — aucune clef promo ; le commentaire défend pourtant le scénario « Electron reload on the payment screen ». Aujourd'hui masqué par C-RED-01 (la promo n'est de toute façon jamais facturée) ; redeviendra un vrai bug d'affichage dès le fix P0.

### C-ADV-05 — P2 : toast « Session rafraîchie automatiquement » exposé au client + chevauche le CTA upsell
- `c1-07-loyalty-balance.png` et `c1-11-upsell.png` : jargon technique sur écran client ; en c1-11 le toast recouvre partiellement « Non merci, continuer sans (29s) ». Source : KioskAppComponent.vue:380 (kiosk-auth-retried). Compréhensible pour l'audit (app.js:114-127), pas pour un client.

### C-ADV-06 — P2 : « Paiement en espèces uniquement à la caisse. » contredit l'encaissement unifié Plan B
- Clef `kiosk.cash_instruction.help` (resources/js/languages/fr.json:1715), rendue inconditionnellement (KioskCashInstructionComponent.vue:36). Avec route_all_to_counter=true, TOUTES les commandes borne (y compris payeurs carte SumUp manuel / TR au comptoir — mandat owner 2026-06-05) lisent « espèces uniquement » → risque d'abandon. Copy fausse.

### C-ADV-07 — P2 : rupture produit = drop silencieux de la ligne panier au mount (pas de toast sur ce chemin)
- `pruneUnavailableLines` (kioskCart.js:676-691) recommit SET_CART_LINES sans message quand déclenché par KioskCartComponent.vue:672 (mount) / KioskAppComponent.vue:701 ; seul le chemin broadcast (useCatalogChangeNotifier.js:312) a le CatalogChangeToast. Ligne qui disparaît + total qui baisse sans explication entre deux écrans.

### C-ADV-08 — P2 : échec auth terminal → reset borne complet, panier client détruit, seul un toast technique 6 s
- Logs GStack `_c1c-log.txt`/`_c1d-log.txt` : 7 échecs fidélité err="null" (l'inline reste VIDE sur 401) puis bascule seule vers /kiosk/idle — captures « loyalty-balance »/« after-redeem » du run c1c sont en réalité l'écran Bienvenue (`c1c-05/06`). Code : app.js:131-149 (CLEAR_KIOSK_TOKEN + push kiosk.login) + toast « Borne déconnectée. Reconnexion en cours… » (KioskAppComponent.vue:361-368). En prod mono-borne le déclencheur = rotation/expiration token (TTL 480 min) en pleine commande : le client perd sa commande composée sans écran d'excuse.

### C-RED-04 — P2 : allergènes invisibles AVANT ajout au panier + données présentes sur 1 seul item /45
- Live (mission C) : grille Desserts → badge `kiosk-product-allergens-51` **absent du DOM** (`exists:false`, capture `r3a-desserts-grid-allergens.png`) — le badge grille est `compact` (KioskCategoriesComponent.vue:228) = n'affiche QUE l'intersection avec les allergènes déclarés du client →客 client anonyme ne voit RIEN ; Tiramisu (seul item câblé : gluten/oeufs/lait, pivot item_allergen) s'ajoute sans wizard → aucune surface détail. Le badge n'apparaît qu'EN PANIER (`r3b-cart-tiramisu-allergens.png` « 🌾 Gluten 🥛 Lait 🥚 Œufs » — rendu OK).
- Double problème : (a) UX/conformité INCO UE 1169/2011 — info disponible seulement après l'ajout ; (b) DATA — 44/45 items sans aucune donnée allergène (gate owner type G3).

## P3

### C-ADV-04 — P3 : « Utiliser mes points : −0,00 € » sélectionnable et confirmable quand le plafond = 0
- `c1-07/c1-08` : panier à 0,00 € (promo clampée) → option active « −0,00 € sur cette commande », Confirmer possible = choix mort. Cause : `appliedDiscount = Math.min(discountValue, total)` (KioskLoyaltyComponent.vue:521) sans désactivation à 0. (Le « 0,00 € » du run GStack venait du total 0, pas du palier — root identifiée.)

### C-RED-05 — P3 : preview backend renvoie total 0,27 € (TVA résiduelle) là où le panier affiche 0,00 €
- Probe : preview Coca×2 + BORNEAUDIT5 → `{subtotal:3, discount:3, total:0.27}` (la TVA 0,27 survit à la remise totale) alors que l'UI panier affiche « Total 0,00 € » (calc local). À trancher au moment du fix C-RED-01 : quel moteur a raison sur un panier 100 % remisé (prix TTC ?).

### C-ADV-09 — P3 : écran d'erreur « produit retiré » orphelin (code mort)
- `goToKioskError`/`resolveKioskErrorRoute` définis kioskCart.js:77-88 + route kioskRoutes.js:273 + KioskErrorProductRemovedComponent : **zéro call-site prod** (grep resources/js hors specs) — jamais montré.

## PASS confirmés (re-vérifiés, pas « tested by another spec »)
- **Séquence file** : mes 3 commandes A0019→A0020→A0021 (ids 4565/4566/4567) contiguës, UI=POST=DB ; + GStack A0013→A0015 avec id-gap 4546 sans queue-gap. PASS.
- **Qty composé >1** : Tacos MENU COMPLET ×2 = 11,50×2 = 23,00 € panier/payment/POST/DB identiques (c2-o1, et c1d-01 31,00 € = 23+6,50+1,50). PASS (hors remises).
- **Double-tap réseau ralenti** : latence CDP 1500 ms + triple `btn.click()` synchrone sur Confirmer → **1 seul POST 201** (id 4567), UI atterrit proprement sur cash-instruction (`r5-after-doubletap.png`). Guards `submitting` + X-Idempotency-Key réutilisé tiennent. PASS.
- **Overlay inactivité hors payment** : avec idleMs=12s l'overlay apparaît sur le panier ≈10 s. PASS (le défaut est circonscrit à payment, cf. C-RED-03).
- **Arithmétique panier** (qty+, suppression, clamp d'affichage promo) : T0 9,00 → T1 10,50 → T2 9,50 → promo −5,00 → 4,50 tous justes à l'écran. PASS côté affichage (la facturation, elle, est le P0).

## DISPUTES du FINAL_REPORT 2026-06-11 (`reports/test-e2e/uiux-caisse-borne-2026-06-11/FINAL_REPORT.md`)
| Claim | Verdict | Preuve |
|---|---|---|
| « VERDICT CONVERGED — production-perfect ; Cycle 2 : P0=0 · P1=0 · P2=0, aucun nouveau finding » | **REFUTED** | 2 P0 d'intégrité monétaire borne (C-RED-01/02) prouvés UI+API+DB sur la même app :8768/branche, + 1 P1 i18n (cat.1 protocole) visible en 2 captures du round. Les cycles n'ont jamais poussé promo/fidélité jusqu'au POST `frontend/order`. |
| W4 heal borne : « 429 fidélité déclenché live → FR » | **UPHELD (périmètre étroit) / WEAKENED comme classe** | L'inline fidélité 429 est bien FR (c1b-06). Mais la même classe d'erreur serveur sur l'input promo du panier fuit en EN brut « Too Many Attempts. » (c1b-04, kioskCart.js:599) — le heal n'a couvert qu'un des deux champs jumeaux. |
| « W5 cross-flow : commandes borne créées → visibles et encaissées côté caisse » | **UPHELD** (côté création) | Mes 3 POST 201 + queue contiguë + DB cohérente le re-confirment ; l'encaissement caisse est hors de ma vague. |
| P3 résiduel « 401 one-shot boot kiosk (broadcasting/auth → /api/login) » présenté comme le seul bruit 401 | **WEAKENED** | Les quartets montrent des 401 mid-flow récurrents (order/quote, pricing/preview ×3, menu, kiosk-event — c1-11, c1d-01, c2-o3) récupérés par re-login silencieux + toast. Teintés par la contention multi-vagues (S4) ; en prod mono-borne la fenêtre = rotation TTL 480 min. Pas un P0 (toast role=alert by design), mais le « one-shot boot » sous-vend le phénomène. |

## Jugements sur les anomalies suspectées du WAVE_REPORT GStack
- S0 (i18n promo) → confirmé **P1** (C-ADV-01). S1 (promo non persistée) → confirmé **P2** (C-ADV-02), subsumé par C-RED-01. S2 (commande 0,00 €) → tranché : la commande gratuite n'existe PAS ; pire, le client est re-facturé 3,00 € (C-RED-01). S3 (écran orphelin) → confirmé **P3** (C-ADV-09) + P2 prune silencieux (C-ADV-07). S4 (throttles partagés) → env-tinted, non compté (1 borne en V1) ; documenté dans le dispute « 401 one-shot ». S5 (reset silencieux) → confirmé **P2** (C-ADV-08, toast 6 s existe mais panier détruit).
- « wizard-stuck » ×2 (c1c-00) → flake du script GStack (groupe sauce-frites non satisfait), PAS un bug app : C2 et mes runs complètent le même wizard. Non compté.

## Artefacts adversariaux
- Scripts : `tests/e2e/_d1red-C-borne-edge-1.mjs`, `tests/e2e/_d1red-C-borne-edge-2-quoteprobe.mjs`
- Logs : `_d1red-log.txt`, `_d1red-quoteprobe-log.txt`, `_d1red-orders.json`
- Captures (8) : `r1a-loyalty-balance-4eur50`, `r1b-cart-promo-plus-loyalty`, `r1c-cash-promo-loyalty`, `r2a-payment-zero`, `r2b-after-confirm-zero`, `r3a-desserts-grid-allergens`, `r3b-cart-tiramisu-allergens`, `r4-payment-25s-sans-timer`, `r5-after-doubletap` (+ quartets)
- DB : orders 4565/4566/4567 ; users.44 loyalty_points 165 (inchangé) ; kiosk_promos.uses_count 0.
