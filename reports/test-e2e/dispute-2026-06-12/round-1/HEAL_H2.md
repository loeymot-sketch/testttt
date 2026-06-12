# HEAL_H2 — BORNE UX/ROBUSTESSE (dispute round-1, 2026-06-12)

Healer: H2 · Branche `release/v1-2026-06-10` (worktree partagé 3 healers) ·
Périmètre strict respecté : `resources/js/components/frontend/kiosk/**` (hors 3 frozen),
`kioskRoutes.js`, `kioskCart.js`, `helpers/** kiosk`, `languages/*.json`, `css/** kiosk`, `tests/js/**`.

## État global
- **13/13 fixes assignés HEALÉS** — 12 commits H2, 0 SKIPPED.
- Vitest battery kiosk complète : **102 fichiers / 786 tests verts** (1 skipped pré-existant) après le dernier commit.
- **Tripwire frozen : 0 ligne** sur chacun des 12 commits (vérifié `git diff --stat <c>~1..<c> -- <13 chemins frozen §7>` → vide pour 9960df426, 36d71fbc2, 43c5f2d76, 1eeb3b2ed, 3538e1a04, dcf675617, ab84dd6ac, 8db1ebf1a, 41708df10, 16d869911, 0438406eb, 4a96f8009).
- ⚠️ **Preuves live :8768 = PENDING REBUILD CENTRAL** : `public/js/app.js` servi date du 2026-06-11 11:20 (pré-fixes) et la consigne commune interdit `npm build` (rebuild central après les 3 healers). Capture baseline AVANT prise : `heal-proofs/H2-before-idle-dark-bundle-2026-06-11.png` (idle sombre = état RED F-01 confirmé sur le bundle servi). Toutes les preuves comportementales ci-dessous sont des Vitest exécutés verts sur le code source.

---

## Fix 1 — D-001 [P1] : /kiosk/error/network ne rendait JAMAIS offline (prefetch RÉFUTÉ)
- **SHA** `9960df426`
- **Root cause** (verdict D §D-001) : chunk lazy `kiosk-errors` re-fetché réseau au `import()` (artisan serve sans Cache-Control → `<link rel=prefetch>` non réutilisable) → offline : `ChunkLoadError: Loading chunk 28 failed` + pageerror non gérée sur idle/payment.
- **Fix réel** : les 4 composants d'erreur sortis du chunk lazy → **imports statiques** dans le bundle principal — `resources/js/router/modules/kioskRoutes.js:22-25` (eager imports), routes :component = objets concrets (zéro round-trip réseau au rendu). Chunk `kiosk-errors` supprimé.
- **Preuve mécanique** : `tests/js/kioskErrorScreensEagerOffline.spec.js` (9 tests, RED prouvé 9/9 fail avant fix → GREEN) — dont `typeof route.component === 'object'` qui prouve la résolution SYNCHRONE dans le graphe module (c'est précisément ce qui rend l'écran offline-safe : aucun `import()` réseau). Baseline `tests/js/kioskPerfChunks.spec.js:33` mise à jour (assertion inversée, rationale en commentaire).
- **Preuve offline live (capture)** : à rejouer post-rebuild central — `context.setOffline(true)` → push `kiosk.error.network` → l'écran rend. Le mécanisme d'échec (fetch réseau du chunk) n'existe structurellement plus.

## Fix 2 — D-003 + ADV-F-P1-1 [P1] : panier jamais vidé post-commande → 409 brut EN ; écrans Plan B cul-de-sac
- **SHA** `43c5f2d76`
- **Root cause** : aucun `kioskCart/reset` à l'entrée cash-instruction (grep verdict = 0 hit) ; clé d'idempotence générée 1×/panier, nullée seulement au RESET → re-validation même panier (même modifié, probe D-003b) = 409 → `err?.message` brut EN (`KioskPaymentComponent` catch). Écran Plan B payment : seul élément interactif = « Confirmer ma commande » (header back gaté `!paymentRouteAllToCounter`).
- **Fix** :
  - `KioskCashInstructionComponent.vue:107` — `dispatch('kioskCart/reset')` au mount (RESET = items + idempotencyKey + promo + loyalty nettoyés ; kioskToken machine intact, prouvé par test). L'écran ne dépend que des props query.
  - `KioskPaymentComponent.vue:696` — `isConflict` (409) → message FR `kiosk.pay_screen.order_conflict` + retour `kiosk.idle` (sans empiler le compteur de refus TPE).
  - CTA cash-instruction → « Retour à l'accueil » (`kiosk.cash_instruction.cta_back_home`, fr/en).
  - Bouton « Retour au panier » sur l'écran Plan B (`KioskPaymentComponent.vue:89` `data-testid="kiosk-payment-counter-back"`, `$router.replace kiosk.cart`, cible ≥64px).
- **Test** : `tests/js/kioskPlanBOrderLifecycle.spec.js` (8 tests verts : reset au mount, RESET nettoie clé/promo/loyalty PAS le token, 409→FR+idle+compteur intact, échappatoire présente, clefs fr/en).

## Fix 3 — D-002 [P1] : checkout panier offline → « Network Error » brut EN
- **SHA** `36d71fbc2`
- **Root cause** : `KioskCartComponent.proceedToUpsell` catch affichait `err?.message` axios brut ; le mapping K10 n'existait que sur l'écran payment.
- **Fix** : `KioskCartComponent.vue:730-746` — même détection que payment (`!response && (ERR_NETWORK | 'Network Error' | request)`) → FR `kiosk.network_lost_cart` (« …Votre panier est conservé… », clef créée fr/en) ; 429 quote → `error.kiosk_rate_limited` FR ; aucun reset/navigation (panier intact).
- **Test** : `tests/js/kioskCartCheckoutErrorsFr.spec.js` bloc D-002 (3 tests : réseau→FR sans « Network Error », 429→FR, clef FR mentionne le panier conservé + parité EN).

## Fix 4 — C-RED-03 [P1] : /kiosk/payment sans timeout d'inactivité en Plan B
- **SHA** `1eeb3b2ed`
- **Root cause** : `noTimerRoutes` inclut `kiosk.payment` dans `KioskAppComponent.vue:881` (**FROZEN**) — rationale « client interagit avec le TPE » caduque en Plan B (`payment_route_all_to_counter=true`, AUCUNE transaction TPE sur la borne). Prouvé live : 25 s sans overlay (r4).
- **Fix (contournement côté appelant, frozen intouché)** : timer local porté par `KioskPaymentComponent` (non frozen), **actif uniquement en Plan B** : `_startPlanBIdleTimer` (`KioskPaymentComponent.vue:518`, mêmes sources kioskSettings idleMs/confirmMs + fallbacks 180s/30s que le shell), overlay « Toujours là ? » réutilisé (`KioskInactivityOverlayComponent`, libellés FR healés W4-K3), listeners pointerdown/touchstart/keydown relançant la fenêtre, **jamais armé pendant submitting/submitted** (watcher), leave = `kioskCart/reset` + retour idle, démontage propre.
- **Test** : `tests/js/kioskPaymentPlanBInactivity.spec.js` (8 tests verts, fake timers : warn à idleMs−confirmMs, leave à idleMs avec reset+idle, interaction relance, « Je suis là » ré-arme sans vider, jamais armé en submit, no-op hors Plan B, câblage template + cleanup listeners).

## Fix 5 — C-ADV-01 [P1] : « Too Many Attempts. » EN brut inline sous le champ promo
- **SHA** `36d71fbc2` (même cluster que D-002 — même classe d'erreur, mêmes fichiers)
- **Root cause** : `kioskCart.validatePromo` catch → `err?.response?.data?.message` verbatim (messages framework EN pour 429/5xx). Bonus découvert : les clefs `kiosk.promo.error.{empty,invalid,network}` étaient **référencées mais ABSENTES de fr.json** (fuite de clef brute latente).
- **Fix** : `kioskCart.js:646-650` — mapping 429→`kiosk.promo.error.too_many` (« Trop de tentatives, patientez quelques secondes. »), pas-de-response→`.network`, autre exception→`.server` ; chemin métier non-exception (message FR serveur) inchangé. Clefs `kiosk.promo.error.*` créées fr+en.
- **Test** : `tests/js/kioskCartCheckoutErrorsFr.spec.js` blocs C-ADV-01 (14 tests : mapping 429/réseau/500 + existence FR/EN des 5 clefs + copy).

## Fix 6 — C-ADV-06 + E-ADV-6 [P1] : « Paiement en espèces uniquement à la caisse. » FAUX
- **SHA** `3538e1a04`
- **Root cause** : copy périmée vs mandat owner encaissement UNIFIÉ (la caisse encaisse la même commande en Espèces/SumUp manuel/Mobile/TR — E21-01). Vente perdue possible (client sans espèces renonce).
- **Fix** : `kiosk.cash_instruction.help` → FR « Réglez à la caisse — espèces, carte ou ticket restaurant. » + EN « Pay at the counter — cash, card or meal voucher. » + miroirs bn/de (qui portaient le même texte FR faux).
- **Test** : `tests/js/kioskCashInstructionUnifiedPaymentCopy.spec.js` (sentinel toutes-locales : plus de « uniquement »/« only », les 3 modes mentionnés).

## Fix 7 — ADV-F-P1-3 [P1] : idle borne à dominante SOMBRE vs mandat light 100%
- **SHA** `dcf675617`
- **Root cause** : `.kiosk-idle-fallback` gradient brun/noir `#1A1410→#0E0A07` + overlay sombre 0.85 + scrim A-001 (l'« ellipse floue centrale ») + texte crème — sur le PREMIER écran client, contredit littéralement `DESIGN_SYSTEM_POLICY_2026-06-10.md:10`.
- **Fix** (`KioskIdleScreenComponent.vue`, non frozen) : **light par défaut** — fallback gradient clair blanc→pêche avec accents brand doux (`rgba(244,80,30,.10)` + `rgba(255,184,0,.14)`), overlay `background:none`, scrim supprimé, textes encre (`#1A1410` / `#4A4036` ≥7:1 sur #FFF4EE). La variante sombre (overlay+scrim+crème) est **conservée sous `.kiosk-idle--has-video`** (`:class` ligne 14) : lisibilité texte sur vidéo préservée — V1 Le Cayenne n'a pas de vidéo → light. `kiosk-fallback.css` (kill-switch) intouché.
- **Test** : `tests/js/kioskIdleLightMode.spec.js` (5 tests : binding has-video, fallback sans stops sombres, overlay/scrim gatés vidéo, texte encre par défaut). Capture AVANT (bundle servi) : `heal-proofs/H2-before-idle-dark-bundle-2026-06-11.png` ; APRÈS = post-rebuild central.

## Fix 8 — D-005 [P1] : « Animations réduites » TRIPLEMENT inerte (placebo)
- **SHA** `ab84dd6ac`
- **Root cause** (probe D-005b) : (1) pas de watcher runtime (shell frozen ne câble que contrast/pmr/audio), (2) `useKioskA11y()` jamais monté en prod, (3) reducedMotion/audioDescription absents des paths vuex-persistedstate (`store/index.js:308-316`, hors périmètre H2) → aucun chemin, même F5, par lequel l'option agit.
- **Fix frozen-safe, 100% dans le périmètre** : nouveau helper `resources/js/helpers/kioskMotionPrefs.js` —
  - `applyKioskMotionPrefs` : attributs `data-kiosk-reduced-motion`/`data-kiosk-audio-description` (sélecteurs par-composant existants) **+ classe globale `html.ks-reduced-motion`** ;
  - `persistKioskMotionPrefs` : localStorage `foodking:kiosk-a11y-motion` ;
  - `hydrateKioskMotionPrefs` : re-dispatch store + apply au boot, invoqué par le guard `kioskRoutes.js:61` (AVANT le mount du shell — le `applyKioskA11yFromStore` one-shot frozen relit le store hydraté).
  - Drawer `KsA11ySettings.vue` : apply+persist sur toggleReducedMotion / toggleAudioDescription / reset.
  - CSS : `resources/css/kiosk/tokens.css:200-202` — kill global animations/transitions/scroll sous `html.ks-reduced-motion` + variables --kiosk-duration-* à 0.
- **Test** : `tests/js/kioskReducedMotionWiring.spec.js` (9 tests verts : toggle→attribut+classe LIVE sans F5, désactivation retire, audioDescription même câblage, reset nettoie+persiste, persistance+hydratation boot, idempotence, guard câblé, règle CSS présente).

## Fix 9 — D-006 + C-ADV-05 [P2] : toast technique « Session rafraîchie automatiquement » visible client
- **SHA** `8db1ebf1a`
- **Root cause** : émetteur `KioskAppComponent.vue:380` (**FROZEN**) sur l'event `kiosk-auth-retried` (app.js:125, hors périmètre) — chevauchait le CTA upsell (c1-11).
- **Fix frozen-safe** : `helpers/kioskAuthInterceptor.js:84-100` (point d'interception capture-phase Wave-Y existant, dans mon périmètre) — `kiosk-auth-retried` désormais **TOUJOURS avalé** (`stopImmediatePropagation` avant le listener bubble du shell) + `console.debug` pour l'audit. `kiosk-auth-failed` (actionnable « Borne déconnectée ») préservé + débouncé comme avant.
- **Test** : `tests/js/kioskAuthRetriedToastSilenced.spec.js` (3 tests : retried n'atteint jamais le shell, trace console.debug, failed délivré 1×/burst).

## Fix 10 — D-007 [P2] : téléphone numpad non reporté dans l'inscription
- **SHA** `41708df10`
- **Root cause** : `KioskLoyaltyComponent` `registerPhone: ''` jamais affecté depuis `code` (d4c-03 : TÉLÉPHONE* vide).
- **Fix** : `KioskLoyaltyComponent.vue:498` — `goToRegister()` pré-remplit `registerPhone` si la saisie ressemble à un numéro (chiffres/espaces/+/()-, 6-15 chiffres) ; jamais un code alphanumérique, jamais d'écrasement d'une saisie existante.
- **Test** : `tests/js/kioskLoyaltyRegisterPhonePrefill.spec.js` (5 tests).

## Fix 11 — C-ADV-02 [P2] : promo non persistée au reload (asymétrie fidélité)
- **SHA** `16d869911`
- **Root cause** : paths vuex-persistedstate couvrent loyaltyDiscount/loyaltyCustomer mais aucune clef promo (`store/index.js:283-296`, hors périmètre H2) → reload = remise silencieusement perdue (c1-05→c1-09).
- **Fix dans le périmètre (kioskCart.js + composant)** : code persisté localStorage `foodking:kiosk-promo-code` (`kioskCart.js:29`) au succès `validatePromo` ; **restauration = re-validation SERVEUR systématique** (`restorePersistedPromo`, `kioskCart.js:669` — min_cart/expiration/uses re-vérifiés, montant JAMAIS rejoué localement, SSOT serveur respecté) appelée au mount du panier (`KioskCartComponent.vue:477`) ; purge sur clearPromo / reset / kioskLogout / refus métier (anti-boucle).
- **Test** : `tests/js/kioskCartPromoPersistence.spec.js` (9 tests).
- Note : pleinement utile maintenant que C-RED-01 (promo facturée — healer H1, `c0518cf50`) est fixé.

## Fix 12 — C-ADV-08 [P2] : échec re-login machine → panier client détruit
- **SHA** `0438406eb`
- **Root cause** : relogin auto OK → `KioskLoginComponent` replace **systématique** vers `kiosk.idle` dont le mount dispatch `kioskCart/reset` → commande composée détruite (captures c1c-05/06 = Bienvenue). `CLEAR_KIOSK_TOKEN` ne touche PAS les items → le panier était récupérable.
- **Fix sûr et minimal** : `KioskLoginComponent.vue:118` — si `kioskCart/count > 0` après relogin → `replace kiosk.cart ?recovered=1` (sinon idle, comportement historique) ; le panier toaste FR « Connexion rétablie. Votre commande a été conservée. » (`kiosk.session_recovered_cart`, fr/en) et strip la query (pas de re-toast au F5).
- **Test** : `tests/js/kioskLoginCartRecovery.spec.js` (4 tests : retour panier, retour idle si vide, CLEAR_KIOSK_TOKEN préserve les items, clef+câblage toast).

## Fix 13 — D-009 [P3] : jargon normatif exposé client dans le drawer a11y
- **SHA** `4a96f8009`
- **Fix** : hints `kiosk.a11y.*` fr+en débarrassés de « (FR/EN) » (borne FR-locked ADR-007), « (EAA 2025) », « (WCAG 2.3.3) », « (WCAG 2.2) », « AAA • 7:1 » → libellés sobres décrivant l'effet (« Lecture vocale des étapes », « Lisibilité renforcée », …).
- **Test** : `tests/js/kioskA11yDrawerSoberLabels.spec.js` (13 tests anti-jargon FR+EN).

---

## Récap commits H2 (12)
| SHA | Fix(s) |
|---|---|
| 9960df426 | D-001 |
| 36d71fbc2 | D-002 + C-ADV-01 |
| 43c5f2d76 | D-003 + ADV-F-P1-1 |
| 1eeb3b2ed | C-RED-03 |
| 3538e1a04 | C-ADV-06 + E-ADV-6 |
| dcf675617 | ADV-F-P1-3 |
| ab84dd6ac | D-005 |
| 8db1ebf1a | D-006 + C-ADV-05 |
| 41708df10 | D-007 |
| 16d869911 | C-ADV-02 |
| 0438406eb | C-ADV-08 |
| 4a96f8009 | D-009 |

## À faire au rebuild central (orchestrateur)
1. `npm run` build central puis re-capturer :
   - D-001 : `context.setOffline(true)` → navigation vers `/kiosk/error/network` → l'écran rend (plus de ChunkLoadError).
   - ADV-F-P1-3 : `/kiosk/idle` light (vs `heal-proofs/H2-before-idle-dark-bundle-2026-06-11.png`).
   - D-005 : toggle drawer → `data-kiosk-reduced-motion="true"` + `html.ks-reduced-motion` LIVE + survie au F5.
   - C-RED-03 : idleMs court → overlay « Toujours là ? » sur /kiosk/payment Plan B.
   - D-003 : cmd1 → retour catalogue → panier VIDE ; plus de 409.
2. PHPUnit non requis pour H2 (aucun fichier app/** touché) — `.env.testing` non vérifié car AUCUN run PHPUnit lancé (DEVDB-GUARD respecté).
