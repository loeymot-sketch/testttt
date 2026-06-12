# DISPUTE round-1 — Vague C : BORNE flux edge PROFOND (mutations réelles)

- Date : 2026-06-12 · App : http://127.0.0.1:8768 · DB : foodking_e2e (jetable)
- Agent : GSTACK MAIN TEAM (Architect+Tester+A11y+SRE) — capture + observation factuelle.
- Verdict sévérité = adversaire (pas ici). Toute anomalie = « suspectée » + evidence.
- Artefacts : quartet PNG/.dom.html/.console.json/.network.json dans ce dossier.

## Recon DB/code (verify-before-report, fait AVANT les flux)

| Fait | Evidence |
|---|---|
| Promo `BORNEAUDIT5` existe | `kiosk_promos` id=1, branch_id=1, type=amount, value=5.00, min_cart=0, active=1, deleted_at NULL (SQL direct foodking_e2e) |
| Compte fidélité dispo | `users` id=44 « Victim Secret », phone `0612345678`, loyalty_code `VICT1234`, loyalty_points=165 |
| Allergènes : 1 SEUL item câblé | pivot `item_allergen` → item 51 Tiramisu = gluten,oeufs,lait ; `items.allergen_flags` de Tiramisu = `[]` (vide) — TOUS les autres items NULL |
| Inactivité : timer global DÉSACTIVÉ sur payment | `KioskAppComponent.vue:881` `noTimerRoutes = ['kiosk.idle','kiosk.waiting','kiosk.payment','kiosk.confirmation']` (commentaire AUDIT-52-BUG3 : TPE physique) |
| Timeouts idle configurables persistés | `kioskSettings.idleMs` défaut 180000/confirm 30000, bornes min 10s/3s (`kioskSettings.js:44-50`), persisté localStorage `vuex` (`store/index.js:312-314`) |
| Cash-instruction auto-redirect | `KioskCashInstructionComponent.vue:83` `autoRedirectSeconds default 45`, countdown→`acknowledge('timeout')` ; la route lit `query.timeout` (`kioskRoutes.js:255-257`), navTarget payment hard-code `timeout:45` |
| Anti double-tap codé (3 couches) | `KioskPaymentComponent.vue:469` guard `if (this.submitting) return` synchrone ; bouton `v-if="!submitting && !submitted"` (ligne 72) ; `kioskCart.js:714-731` X-Idempotency-Key UUID généré 1×/panier et RÉUTILISÉ (« retries/double-tap reuse the same key ») |
| Promo validate endpoint | `routes/api.php:1487` POST `/api/frontend/promo/validate` → `PromoController::check` (kiosk_promos prio > coupons globaux ; table `coupons` e2e = VIDE) |

## États capturés (incrémental)

### C1 (run 1 — partiellement invalidé par le harness, artefacts conservés)
- `c1-01..c1-13` : flux Galette+Coca → promo → loyalty → payment → cash. Commande créée id=4533 queue=A0008 total=3,00 €.
- Harness : mes `page.goto()` (full reload) entre panier et fidélité ont réinitialisé l'état non persisté → C1-bis refait 100% in-SPA. MAIS ce reload a révélé l'asymétrie de persistance (voir anomalie S1).
- Intégrité relevée run 1 : panier 3,00 € → payment « TOTAL À RÉGLER : 3,00 € » → POST 201 total=3 discount=0 → cash « #A0008 / 3,00 € ». Cohérent (promo perdue AVANT checkout, cf. S1).

### C1-bis / C1-c (in-SPA, montants vérifiés écran par écran)
- `c1b-01..c1b-11` : run perturbé par 429 partagés (voir S4) — commande créée id=4537 A0011 9,50 € SANS remise (cohérent : promo avait échoué en 429, total panier 9,50 = payment 9,50 = POST 9,5 = cash 9,50 ✓).
- `c1c-01..c1c-05` : qty+ et suppression de ligne arithmétiquement justes :
  - T0 : Galette 6,50 + Coca 1,50 + Eau 1,00 = sous-total **9,00 €** ✓
  - T1 (Coca→2) : 9,00 + 1,50 = **10,50 €** ✓
  - T2 (suppr Eau) : 10,50 − 1,00 = **9,50 €** ✓
  - T3 (BORNEAUDIT5, essai 2) : 9,50 − **5,00** = **4,50 €** ✓ — capture `c1c-04-cart-promo.png` : ligne « Code promo BORNEAUDIT5 −5,00 € », total 4,50 €, CTA « Valider ma commande 4,50 € » — tous identiques.
- Le run C1c s'est terminé par un RESET borne pendant les retries fidélité (S4/S5) — la fin du parcours promo+fidélité simultanés n'a pas pu être capturée dans ce run (re-tenté en C1d).

### C2 — File d'attente : 3 commandes d'affilée (task 2) ✅ artefacts `c2-o1/o2/o3-{cart,payment,cash}`
- Séquence observée : **#A0013 (id 4544, 23,00 €) → #A0014 (id 4545, 1,50 €) → #A0015 (id 4547, 3,80 €)** — UI = POST = DB sur les 3 (SQL re-vérifié). Aucune collision ; numéros contigus MÊME avec une commande d'une autre vague intercalée (id 4543 source=15 → A0012). Gap d'id 4546 sans gap de queue_number.
- La 1re commande = **Tacos MENU COMPLET ×2 (composé, wizard complet, qty>1)** : 8,50 base + 3,00 formule = 11,50 €/unité ×2 = 23,00 € — panier/payment/cash/DB identiques (capture `c2-o1-cart.png`, ligne « Viandes : Poulet mariné ×1. Formule : Menu complet (frites + boisson) (Coca-Cola 33cl). Sauce frites : Algérienne »).
- Enchaînement par CTA « J'AI COMPRIS » → retour idle propre à chaque fois.

## Anomalies suspectées (avec evidence)

### S0 — i18n leak « Too Many Attempts. » (EN brut) sous le champ code promo
- **Observé** (capture `c1b-04-cart-promo.png` + `c1c-04a-promo-fail-inline.png`) : sur 429, l'erreur inline panier affiche le message Laravel brut **« Too Many Attempts. »** en anglais, alors que le toast global est lui FR (« Trop de requêtes — patientez 11s avant de réessayer. ») et que l'écran fidélité gère le même 429 en FR (« Trop de tentatives, patientez quelques secondes. » — capture `c1b-06-loyalty-balance.png`).
- **Code (re-greppé)** : `resources/js/store/modules/kioskCart.js:599-600` `const message = err?.response?.data?.message || 'kiosk.promo.error.network'; commit('SET_PROMO_ERROR', message)` — le message serveur brut est stocké ; affiché verbatim par `KioskCartComponent.vue:310` `{{ $te(promoError) ? $t(promoError) : promoError }}` (fallback non traduit). Toute erreur serveur non-i18n-key (429, 500 custom) fuit en l'état sur l'écran client.

### S1 — Promo non persistée vs fidélité persistée (reload = remise silencieusement perdue)
- **Observé** : panier `{subtotal:"3,00 €", promo:"-3,00 €", total:"0,00 €"}` → full reload de page (mon goto) → panier `{subtotal:"3,00 €", promo:null, total:"3,00 €"}` ; les ITEMS survivent, la promo disparaît sans message.
- **Code (re-greppé)** : `resources/js/store/index.js:283-289` persiste `kioskCart.items`, `kioskCart.loyaltyDiscount`, `kioskCart.loyaltyCustomer`, `kioskCart.idempotencyKey`… mais AUCUNE clef `kioskCart.promoCode/promoDiscount/promoMeta` (state promo défini `kioskCart.js` Phase 9.1.6, RESET ligne ~428).
- **Pertinence réelle** : le commentaire du code défend lui-même le scénario reload (`store/index.js:290-293` « must survive a page refresh (e.g. Electron reload on the payment screen) » pour orderType). Une borne Electron qui reload entre panier et paiement garde les articles + remise fidélité mais PERD la remise promo → le client paie plus que le total affiché avant reload. À sévériser par l'adversaire.

### S2 — Promo « amount » clampe le total à 0,00 € (commande gratuite borne)
- **Observé** : BORNEAUDIT5 (amount 5,00) sur panier 3,00 € → remise affichée **-3,00 €**, total **0,00 €** (capture `c1-05-cart-promo-applied.png`). Le flux continue (checkout possible).
- Question pour l'adversaire : une commande 0,00 € route comptoir est-elle légitime côté NF525/caisse (encaissement 0) ? Aucune erreur UI, pas de minimum.

### S3 — Écran `kiosk.error.product-removed` apparemment ORPHELIN (jamais déclenché par le code de prod)
- **Code (re-greppé)** : la route existe (`kioskRoutes.js:273-274`) + composant `KioskErrorProductRemovedComponent.vue` ; le mapping `KIOSK_ERROR_ROUTES[PRODUCT_REMOVED]` existe (`kioskCart.js:31`) ; MAIS `goToKioskError`/`resolveKioskErrorRoute` ne sont importés QUE par les tests (`tests/js/kioskGlobalErrors.spec.js`, `tests/Playwright/kiosk-errors.spec.js` — grep complet resources/js : zéro call-site de prod).
- La rupture temps réel est gérée AUTREMENT : `kioskCart.js:676 pruneUnavailableLines` (drop silencieux de la ligne + `SET_CART_LINES`) — donc l'écran d'erreur dédié « produit retiré » n'est jamais montré ; à vérifier par l'adversaire si le drop de ligne a un feedback utilisateur (toast CatalogChangeToast ?) — testé en C4.

### S4 — Throttles partagés par le user borne : 401/429 en cascade sous contention multi-clients
- **Observé** : `frontend/promo/validate` 429 (throttle:30,1 — `routes/api.php:1487`), `frontend/loyalty/check` **401 puis 429** (`c1c-05-loyalty-balance.network.json`), `frontend/kiosk-event` 401.
- **Mécanisme (code re-greppé)** : chaque login borne **révoque les tokens précédents du même compte machine** (commentaire `kioskCart.js:445-451` « Each concurrent login deletes prior kiosk-token rows in KioskMachineLoginController::login ») et tous les buckets throttle sont keyés par user id (`RouteServiceProvider.php:54-57`). Plusieurs contextes navigateur simultanés sur le même compte borne (mes runs + autres vagues du dispute) se révoquent mutuellement → 401 → re-login → retry → 429.
- **Lecture honnête** : en prod mono-borne ce scénario exige 2 sessions sur le même compte machine (ex. 2 bornes partageant les credentials) — à l'adversaire de juger si c'est un risque réel V1 (la CONSTITUTION dit 1 borne). Je le documente car les artefacts en sont teintés.

### S5 — Échec fidélité terminal → RESET borne complet (panier + promo perdus) sans écran d'info
- **Observé** (`_c1c-log.txt` 00:51:44→00:52:24) : après le 401 terminal sur `loyalty/check`, l'app a navigué seule vers `/kiosk/idle` et le panier (9,50 € avec promo −5,00) a été vidé — pendant que « le client » était devant l'écran fidélité.
- **Code** : listener `kiosk-auth-failed` (`KioskAppComponent.vue:368`) → reset. Comportement défendable (token mort), mais le client perd sa commande composée sans message d'excuse/erreur dédié — l'écran `error/network` ou un toast aurait été attendu. À sévériser par l'adversaire.

## Intégrité numérique chiffre par chiffre

(rempli au fil du flux C1)
