# DISPUTE Round 2 — Vague D : BORNE robustesse (vérif heals post-Round-1)

Date : 2026-06-12 · App : http://127.0.0.1:8768 (DB foodking_e2e jetable, bundles REBUILDÉS 12/06 13:07) · Viewport borne 1080×1920 fr-FR, `chromium.launch({channel:'chrome'})`.
Agent : GSTACK MAIN TEAM Round 2. Quartet par état : `<tag>.png` + `<tag>.dom.html` (#app outerHTML, 120 KB par la fin) + `<tag>.console.txt` + `<tag>.network.txt`.
Scripts : `tests/e2e/_d2-D-*.mjs`.

## Grounding pré-test (verify-before-report)
- Bundle servi `public/js/app.js` daté **12 juin 13:07** (post-heal), contient les marqueurs `ks-reduced-motion`, `cta_back_home`, `foodking:kiosk-promo-code` (grep sur le bundle servi via curl) → les heals H2 SONT dans le bundle live.
- Heals re-greppés source : eager imports `kioskRoutes.js:22-25` (D-001) · `KioskCashInstructionComponent.vue:107` reset + `:54` cta_back_home (D-003/ADV-F-P1-1) · `KioskCartComponent.vue:735,739` network_lost_cart/kiosk_rate_limited (D-002) · `helpers/kioskMotionPrefs.js` + `tokens.css:193-209` (D-005) · `KioskLoyaltyComponent.vue:498-506` goToRegister prefill (D-007) · `kioskCart.js:29,675` promo persistée + restorePersistedPromo (C-ADV-02) · `kioskAuthInterceptor.js:84` SILENCED_EVENTS kiosk-auth-retried (D-006).
- Promo seedée DB réelle : `kiosk_promos` id=1 `BORNEAUDIT5` amount 5,00 €, min_cart 0, active, uses_count=1 (SELECT read-only).

## États couverts (incrémental)

### Scénario 1 — Offline (heals D-001 + D-002) — script `_d2-D-1-offline.mjs`
| Tag | État | Verdict factuel |
|---|---|---|
| d2D1-01-idle-online-baseline | Idle online | **0 requête réseau vers `kiosk-errors`** depuis le boot (chunk lazy supprimé, imports eager dans app.js). PNG : idle **CLAIR** (fond blanc→pêche, titre encre « Bienvenue ! »). |
| d2D1-02-error-network-offline-renders | OFFLINE (`context.setOffline(true)`) → push `kiosk.error.network` | **✅ D-001 CONFIRMÉ : l'écran « Connexion perdue » REND offline** — PNG lu : 📡 + « Connexion perdue / Nous n'arrivons plus à joindre le service de commande » + CTA « RÉESSAYER » + « PRÉVENIR UN MEMBRE DE L'ÉQUIPE », pleinement stylé. 0 requête chunk (network.txt : seulement kiosk/event + favicon FAILED, attendus offline). Round 1 : ChunkLoadError + écran jamais rendu → **HEALÉ**. |
| d2D1-03-error-network-retry-online | Online + clic RÉESSAYER (+1,2 s) | PNG = page BLANCHE transitoire : `retry()` = `window.location.reload()` à +600 ms (`KioskErrorNetworkComponent.vue:62-72`, comportement FP-01 documenté) — capture prise mi-reload (DOM = `<router-view>` vide, 81 octets). Pas un défaut du heal D-001 ; état final re-sondé au scénario 7 (voir d2D7). |
| d2D1-04-cart-checkout-offline-toastFR | Panier (Glace 3,80 €) → OFFLINE → « Valider ma commande » | **✅ D-002 CONFIRMÉ** — toast FR « Connexion perdue. Votre panier est conservé — réessayez dans un instant. » (PNG lu, toast bas d'écran), **AUCUN « Network Error » EN** (assert texte page), panier intact (1 article, totaux 3,80 € cohérents). Round 1 : toast EN brut → **HEALÉ**. |
| d2D1-05-cart-recovery | Retour online | Panier intact (Glace ×1), page fonctionnelle. |
| d2D1-06→08 | Tentative payment offline | `gotoPayment` n'a pas quitté /kiosk/cart sur ce run : la **récupération C-ADV-08** a redirigé vers le panier conservé avec toast vert « Connexion rétablie. Votre commande a été conservée. » (PNG d2D1-06 — heal observé organiquement). La branche « confirm offline depuis /kiosk/payment » est re-couverte au scénario 7. |

Intégrité chiffres scénario 1 : Glace 3,80 € ×1 → Sous-total 3,80 € → Total 3,80 € → CTA « Valider ma commande 3,80 € » ✓ format FR partout ✓.

### Scénario 2 — Plan B bout-en-bout (heals D-003 + ADV-F-P1-1) — script `_d2-D-2-planb.mjs`
| Tag | État | Verdict factuel |
|---|---|---|
| d2D2-01-payment-before-confirm | /kiosk/payment Plan B | Écran atteint. **Bouton « Retour au panier » présent** (`data-testid="kiosk-payment-counter-back"` visible=true — échappatoire ADV-F-P1-1 part-payment, fix 43c5f2d76). |
| d2D2-02-cash-instruction-cart-cleared | Confirm → cash-instruction | ORDER-POST **201** id=4517 queue=A0006 total=3.8. **✅ D-003 CONFIRMÉ : panier VIDÉ au mount** — `kioskCart.items=[]`, `idempotencyKey=null`, promo/loyalty nettoyés (localStorage lu). PNG lu : « Rendez-vous en caisse / #A0006 / 3,80 € » + **copy unifiée « Réglez à la caisse — espèces, carte ou ticket restaurant. »** (C-ADV-06 visible live) + compte à rebours « Retour à l'accueil dans 42 s » + **CTA « RETOUR À L'ACCUEIL » pleine largeur** (ADV-F-P1-1 ✅). |
| d2D2-03-cart-after-order-empty | router.push(/kiosk/cart) depuis cash-instruction | **Panier rendu VIDE** (0 ligne, « Votre panier est vide », bouton checkout ABSENT) → **re-validation impossible** ; round-1 D-A5 (panier plein + 409 brut EN) → **HEALÉ**. |
| d2D2-05-after-cta-home | Clic « RETOUR À L'ACCUEIL » | → /kiosk/idle propre. |

Intégrité chiffres scénario 2 : panier 3,80 € → ORDER 201 total=3.8 → cash-instruction « #A0006 / 3,80 € » — chaîne cohérente ✓. ORDERS totaux du run = **UN SEUL 201, zéro 409** ✓.

### Scénario 3 — Drawer a11y + idle light + toast technique (heals D-005 + D-009 + ADV-F-P1-3 + D-006) — script `_d2-D-3-a11y.mjs`
| Tag | État | Verdict factuel |
|---|---|---|
| d2D3-01-idle-light-mode | Idle attract | **✅ ADV-F-P1-3 CONFIRMÉ : dominante CLAIRE** — computed `.kiosk-idle-fallback` backgroundImage = `linear-gradient(rgb(255,255,255), rgb(255,232,221) 55%, rgb(244,80,30))` (blanc→pêche→orange brand), AUCUNE classe `kiosk-idle--has-video`, titre encre `rgb(15,15,15)`. PNG lu : écran majoritairement blanc/pêche, « Bienvenue ! » noir. Round 1 : gradient brun/noir #1A1410→#0E0A07 → **HEALÉ**. |
| d2D3-02-auth-retried-no-toast | `window.dispatchEvent(CustomEvent('kiosk-auth-retried'))` | **✅ D-006 CONFIRMÉ : AUCUN toast « Session rafraîchie »** rendu après l'event (assert innerText avant/après, 1,5 s). L'intercepteur capture-phase (`kioskAuthInterceptor.js:84-100`) avale l'event avant le listener du shell frozen. |
| d2D3-03-drawer-labels-sober | Drawer a11y ouvert | **✅ D-009 CONFIRMÉ : 0 hit jargon** (regex `WCAG\|EAA\|AAA •\|7:1\|(FR/EN)\|2.3.3\|2.2` sur l'innerText drawer). Libellés lus (PNG) : « Standard / Lisibilité standard », « Renforcé / Lisibilité renforcée », « Mode PMR / Textes plus grands, zones tactiles élargies », « Assistance audio / Lecture vocale des étapes », « Description audio détaillée / Lit à voix haute boutons, prix et choix », « Animations réduites / Désactive animations et défilements ». |
| d2D3-04-reduced-motion-on-live | Toggle « Animations réduites » ON (sans F5) | **✅ D-005 CONFIRMÉ — EFFET RÉEL LIVE** : `data-kiosk-reduced-motion` false→**true**, `html.ks-reduced-motion` posée, `--kiosk-duration-fast` 140ms→**0ms**, `--kiosk-duration-idle` 800ms→**0ms**, transitionDuration CTA computed `0.14s`→**`1e-06s`**, animationDuration `0s`→`1e-06s`. localStorage `foodking:kiosk-a11y-motion={"reducedMotion":true,…}`. Round 1 : triplement inerte (placebo) → **HEALÉ**. |
| d2D3-05-reduced-motion-after-f5 | F5 | **Persiste** : attr=true, classe présente, vars 0ms, transitions 1e-06s — hydratation boot OK (guard `kioskRoutes.js`). |
| d2D3-06/07 | Réinitialiser puis F5 | Retour normale COMPLET (attr=false, classe retirée, 140ms/800ms/0.14s restaurés) et le reset PERSISTE au reload (pas de résurrection). |

### Scénario 4 — Inscription fidélité (heal D-007) — script `_d2-D-4-phone.mjs`
| Tag | État | Verdict factuel |
|---|---|---|
| d2D4-01-loyalty-typed | Numpad : saisie 0788123456 | 10 chiffres exacts dans le champ. |
| d2D4-02-not-found | « Vérifier mon code » (numéro inconnu) | Écran propose « Pas encore membre ? S'inscrire ». |
| d2D4-03-register-phone-prefilled | Clic S'inscrire | **✅ D-007 CONFIRMÉ : TÉLÉPHONE\* PRÉ-REMPLI « 0788123456 »** (PNG lu + dump inputs : `{ph:"06 12 34 56 78", val:"0788123456"}`) ; NOM et E-MAIL vides (pas d'écrasement). Round 1 d4c-03 : champ vide → **HEALÉ**. |

### Scénario 5 — Promo survit au reload (heal C-ADV-02) — script `_d2-D-5-promo-reload.mjs`
| Tag | État | Verdict factuel |
|---|---|---|
| d2D5-01-promo-applied | Panier Glace 3,80 € + code réel `BORNEAUDIT5` (amount 5,00 €, DB) | Promo appliquée : « ✓ Code promo BORNEAUDIT5 appliqué (−3,80 €) », ligne −3,80 €, **Total 0,00 €** (remise CLAMPÉE au total — pas de négatif). localStorage `foodking:kiosk-promo-code=BORNEAUDIT5`. POST /api/frontend/promo/validate : 401 puis 200 (réplay auth-interceptor — bruit 401 connu). |
| d2D5-02-promo-after-f5 | F5 sur le panier | **✅ C-ADV-02 CONFIRMÉ : la promo SURVIT au reload** — 3e POST promo/validate **200** émis au mount (= re-validation SERVEUR, pas un rejeu local), bandeau + ligne −3,80 € + Total 0,00 € identiques (PNG lu). Round 1 c1-05→09 : remise silencieusement perdue → **HEALÉ**. |

Intégrité chiffres scénario 5 : Sous-total 3,80 € − 3,80 € = **0,00 €** affiché ✓ (clamp 5,00→3,80 cohérent) ; CTA « Valider ma commande 0,00 € ».

### Scénario 6 — F5 à chaque étape + multi-tab — script `_d2-D-6-f5-multitab.mjs`
| Tag | État | Verdict factuel |
|---|---|---|
| d2D6-01-f5-catalogue / d2D6-01-f5-cart | F5 | URL conservée, **panier SURVIT** (1 item) aux deux étapes. |
| d2D6-02-f5-payment | F5 sur /kiosk/payment | Reste sur /payment, « TOTAL À RÉGLER : 3,80 € » conservé (PNG lu) + **« Retour au panier » présent post-F5**. |
| d2D6-03→05 | Tab B même contexte : /kiosk/cart puis ajout produit | localStorage PARTAGÉ : tab B voit le panier de A (1 ligne). L'ajout en B = **fusion de quantité** (PNG d2D6-04 : barre commande « 2 articles · 7,60 € », Glace ×2) — `items.length` reste 1. Store EN MÉMOIRE de tab A (payment) = 1 item ×1, inchangé (pas de sync cross-tab temps réel). |
| d2D6-06-tabA-confirmed | Tab A confirme | ORDER **201** id=4527 queue=A0014 **total=3.8 = EXACTEMENT ce que l'écran de tab A affichait** (l'état mémoire de l'onglet acheteur fait foi). L'incrément de quantité fait en tab B (7,60 € localStorage) n'est PAS facturé et est perdu au reset — **perte documentée**, scénario multi-tab impossible sur une borne mono-écran réelle (pas de double commande, totaux affichés=facturés). |
| d2D6-07-tabB-after-orderA | Tab B re-navigue /kiosk/cart | **Panier vide (0 ligne)** — le reset D-003 de tab A s'est propagé via localStorage à la navigation suivante de B. Comportement multi-tab SAIN pour une borne mono-écran (dernier-écrivain-gagne, convergence au reset, aucune double commande). |

### Scénario 7 — Confirm OFFLINE depuis /kiosk/payment (D-001 flux réel) + état post-RÉESSAYER — script `_d2-D-7-payment-offline.mjs`
| Tag | État | Verdict factuel |
|---|---|---|
| d2D7-01-payment-online | /kiosk/payment Plan B (Glace 3,80 €) | Baseline. |
| d2D7-02-payment-confirm-offline | OFFLINE → « Confirmer ma commande » | **✅ D-001 CONFIRMÉ EN FLUX RÉEL** : routé vers `/kiosk/error/network` et **l'écran REND offline** (PNG lu : « Connexion perdue » + RÉESSAYER + PRÉVENIR UN MEMBRE DE L'ÉQUIPE) + toast FR « Connexion perdue. Votre commande n'a pas été envoyée. » + chip « 1 · Mon panier · 3,80 € » (panier conservé). Round 1 d1-10 : ChunkLoadError ×4, restait sur /payment → **HEALÉ**. |
| d2D7-03-retry-final-state | Online rétabli (+2,5 s) → clic RÉESSAYER → attente 9 s | ⚠ **L'écran « Connexion perdue » RE-S'AFFICHE** : `retry()` = `window.location.reload()` (`KioskErrorNetworkComponent.vue:62-72`) → le reload re-land sur la ROUTE `/kiosk/error/network` qui re-rend l'écran d'erreur même connexion rétablie (panier toujours conservé, chip « Mon panier » = seule échappatoire). Voir D-R2-A1. |

## Observations & anomalies suspectées (round 2)

### D-R2-A1 (SUSPECTÉ — UX robustesse, P2) : « RÉESSAYER » de l'écran erreur réseau boucle sur lui-même même une fois la connexion RÉTABLIE
- **Evidence** : quartets d2D1-03 (capture mi-reload blanche, DOM `<router-view>` vide 81 o) + d2D7-03 (état FINAL +9 s online : URL `/kiosk/error/network`, écran « Connexion perdue » re-rendu, PNG lu ; panier intact `Glace ×1`).
- **Code re-greppé** : `KioskErrorNetworkComponent.vue:62-72` — `retry()` = `logEvent('error_retry')` + `$emit('retry')` (non câblé par le parent frozen, commentaire FP-01) + `setTimeout(window.location.reload, 600)`. Le reload re-boot la SPA **sur la même route d'erreur** ; aucun health-check au boot ne redirige vers idle/panier quand le backend redevient joignable → le client peut taper RÉESSAYER en boucle infinie alors que tout marche. Échappatoire réelle = chip « Mon panier » (présente, d2D7-03) — non guidée.
- **Périmètre** : composant NON frozen. Distinct du heal D-001 (l'écran rend offline = CONFIRMÉ) — ce défaut était INVISIBLE au round 1 précisément parce que l'écran ne rendait jamais.
- Proposition de fix (pour healer) : au retour `navigator.onLine` / heartbeat OK, `retry()` devrait `router.replace` vers la route d'origine (query `?from=`) ou `/kiosk/cart` si panier non vide, au lieu d'un reload aveugle sur la route erreur.

### D-R2-A2 (OBSERVATION — persiste post-heal, recoupe round-1 D-A3 / gate « 401 one-shot boot ») : bruit 401 RÉCURRENT en ligne
- Agrégat des 36 quartets de cette vague : `401 GET /api/frontend/menu` ×64, `401 POST /api/frontend/kiosk-event` ×52, `401 GET /api/login` ×47 (cumul des recorders par run, navigation EN LIGNE) — chaque fois auto-réparé par le replay intercepteur (fonctionnel, ex. promo/validate 401→200, d2D5-01). Pas un nouveau défaut : le même pattern que D-A3 round 1, toujours présent post-heal ; le toast technique est désormais silencé (D-006) donc le client ne voit plus rien — reste le bruit console/network pour l'observabilité.
- À noter pour l'adversaire : le gate connu couvre le « one-shot boot » ; ici c'est récurrent en usage (mais sans impact client visible désormais).

### D-R2-A3 (OBSERVATION mineure — edge promo, à router vers la vague C si retenue) : promo `amount` > total panier → Total 0,00 € commandable
- d2D5-01/02 : BORNEAUDIT5 (5,00 €) sur panier 3,80 € → remise clampée −3,80 €, Total **0,00 €**, CTA « Valider ma commande 0,00 € » actif. Le clamp (pas de négatif) est correct ; la possibilité d'une commande Plan B à 0,00 € (rien à encaisser en caisse) n'a PAS été exercée (pas de commit pour ne pas consommer `uses_count`). Compétence vague C/E ; mentionné pour complétude.

### Scénario 8 — Idle 3 min panier abandonné — script `_d2-D-8-idle.mjs`
| Tag | État | Verdict factuel |
|---|---|---|
| d2D8-01-cart-T0 | Panier abandonné (Glace 3,80 €) sur /kiosk/cart | Baseline T0. |
| d2D8-02-T155-overlay | T+155 s sans interaction | **Overlay « Toujours là ? » visible** (PNG lu : modal centré, « Votre commande sera effacée dans 24 secondes », CTA « JE SUIS LÀ » focus-ring + « ABANDONNER LA COMMANDE », fond panier estompé) — timing conforme idleMs=180 s − confirmMs=30 s. |
| d2D8-03-T195-after-timeout | T+195 s | **Retour /kiosk/idle**, cart=0. |
| d2D8-04-final-reset | T+210 s | Reset PROPRE confirmé : `items=[]`, promo/loyalty/idemKey nettoyés, idle light affiché. |
| d2D8-05-reusable | Re-entrée flux | Borne réutilisable (catalogue Sandwich Cayenne, panier 0). Bonus observé : la grille Sandwich Cayenne = **2 produits réels uniquement** (Sandwich Cayenne 7,00 € + Big Cayenne 9,50 €) — les 3 SKU techniques upsell ne polluent plus la grille (seeder ADV-F-P1-2 effectif, périmètre vague C/F pour le verdict formel). |

### Bonus observé hors checklist — heal C-ADV-08 (récupération panier après relogin) VU LIVE
- d2D1-06 (PNG lu) : après le cycle offline→online du scénario 1, le relogin machine automatique a redirigé vers **/kiosk/cart avec le panier CONSERVÉ** + toast VERT « Connexion rétablie. Votre commande a été conservée. » (clé `kiosk.session_recovered_cart`, fix 0438406eb) — au lieu du retour idle+reset destructeur du round 1. Observé organiquement (c'est ce qui a interrompu mon `gotoPayment` du scénario 1 — le harnais a été détourné par la récupération, preuve involontaire mais réelle).

## SYNTHÈSE — verdicts heals du périmètre D (10/10 exercés)

| Heal | Verdict | Preuve |
|---|---|---|
| D-001 écran erreur réseau offline (imports eager) | **CONFIRMÉ HEALÉ** | d2D1-02 (push direct) + d2D7-02 (flux réel payment) — écran rend offline, 0 requête chunk |
| D-002 toast checkout panier offline FR | **CONFIRMÉ HEALÉ** | d2D1-04 — FR « …Votre panier est conservé… », 0 « Network Error » |
| D-003 panier vidé à cash-instruction | **CONFIRMÉ HEALÉ** | d2D2-02/03 — items=[] + idemKey null au mount, re-validation impossible, 0×409 |
| ADV-F-P1-1 CTA « Retour à l'accueil » + échappatoires Plan B | **CONFIRMÉ HEALÉ** | d2D2-02 (CTA pleine largeur → idle) + d2D2-01/d2D6-02 (« Retour au panier » sur payment) |
| D-005 « Animations réduites » effet réel + persistance | **CONFIRMÉ HEALÉ** | d2D3-04/05 — attr+classe LIVE, vars 140→0 ms, transition 0.14 s→1e-06 s computed, survit F5, reset propre |
| D-009 libellés a11y sans jargon | **CONFIRMÉ HEALÉ** | d2D3-03 — 0 hit regex jargon, libellés sobres lus |
| D-007 téléphone pré-rempli inscription | **CONFIRMÉ HEALÉ** | d2D4-03 — TÉLÉPHONE\*=0788123456 |
| C-ADV-02 promo survit au reload | **CONFIRMÉ HEALÉ** | d2D5-01/02 — re-validation SERVEUR (POST 200 au mount), −3,80 € identique post-F5 |
| D-006 toast « Session rafraîchie » silencé | **CONFIRMÉ HEALÉ** | d2D3-02 (event synthétique → 0 toast) + 0 occurrence dans les 36 DOM malgré ~50 cycles 401-replay organiques |
| ADV-F-P1-3 idle light | **CONFIRMÉ HEALÉ** | d2D3-01 — gradient computed blanc→pêche→orange, texte encre, pas de has-video |

## Intégrité chiffre par chiffre (vague entière)
- Glace 3,80 € — constant sur cart/payment/cash-instruction/ORDER-POST (4517=3.8, 4527=3.8) ✓
- Promo : 3,80 − 5,00 → clamp −3,80 → Total 0,00 € (jamais négatif) ✓
- Cash-instruction : #A0006/3,80 € et #A0014/3,80 € = queue+total des ORDER-POST correspondants ✓
- Fidélité : saisie numpad 0788123456 → restituée à l'identique dans l'inscription ✓
- Format FR `X,XX €` partout, aucun « NaN/undefined/0undefined » dans les 36 DOM ✓

## Anomalies suspectées à coter par l'adversaire
1. **D-R2-A1 (P2 suspecté)** — « RÉESSAYER » de l'écran erreur réseau re-land en boucle sur l'écran d'erreur même connexion rétablie (`KioskErrorNetworkComponent.vue:62-72`, reload sur la route erreur, pas de health-redirect). Nouveau — observable seulement depuis que D-001 est healé.
2. **D-R2-A2 (observation)** — bruit 401 récurrent menu/kiosk-event/login en ligne persiste post-heal (auto-réparé, invisible client depuis D-006) — recoupe D-A3 round 1/gate « 401 one-shot boot » : à trancher si le récurrent est couvert par le gate.
3. **D-R2-A3 (mineur, périmètre C)** — promo amount > total → commande 0,00 € possible (clamp OK ; commit 0 € non exercé).
4. (non coté) d2D4-02 : le feedback « Non trouvé » du check fidélité n'était pas visible 2,5 s après « Vérifier mon code » (latence probable — l'inscription, elle, fonctionne) ; round 1 l'avait vu apparaître. Simple note de variabilité.

## Inventaire artefacts
- 40 états × quartet complet (`png` + `dom.html` #app-outerHTML + `console.txt` + `network.txt`) + 8 logs `_d2D-*-log.txt` dans ce dossier (169 fichiers).
- **Chaque PNG lu** (les 5 doublons byte-identiques sha1 — d2D1-07/d2D1-08/d2D6-01-f5-cart/d2D6-03/d2D8-01 et d2D7-01≡d2D2-01 — vérifiés par hash après lecture d'un exemplaire).
- Scripts pérennisés : `tests/e2e/_d2-D-helper.mjs`, `_d2-D-1-offline.mjs`, `_d2-D-2-planb.mjs`, `_d2-D-3-a11y.mjs`, `_d2-D-4-phone.mjs`, `_d2-D-5-promo-reload.mjs`, `_d2-D-6-f5-multitab.mjs`, `_d2-D-7-payment-offline.mjs`, `_d2-D-8-idle.mjs`.
- Aucun git/artisan/npm exécuté ; DB touchée en SELECT read-only uniquement ; frozen observés seulement.
