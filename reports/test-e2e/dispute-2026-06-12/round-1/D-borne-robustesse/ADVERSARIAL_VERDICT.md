# ADVERSARIAL VERDICT — Round 1, Vague D : borne robustesse (dispute-2026-06-12)
Superviseur adversarial · 2026-06-12 · App :8768 (foodking_e2e) · ÉCRIT INCRÉMENTALEMENT

## Statut : TERMINÉ — verdict RED (4 P1 ouverts) · 23 PNG lus + 63 quartets balayés + 2 probes live (6 captures d1red-01→06)

### Notes de cadrage
- WAVE_REPORT couvre scénarios 1-5 ; **scénario 6 (idle 3 min) a des artefacts (d6-01→06) mais AUCUNE analyse écrite** (agent coupé) — je le passe en revue moi-même.
- Tous les `.dom.html` font exactement 81923 octets = troncature 80 Ko déclarée — les fins de DOM (modals tardives, toasts injectés en fin de body) peuvent manquer dans les artefacts ; je m'appuie sur les PNG + re-vérif live pour ce qui dépasse.

### Findings confirmés (au fil de l'eau)

#### D-001 — P1 (silent_error/robustesse) : fix W4-N1 webpackPrefetch INEFFECTIF — l'écran /kiosk/error/network ne rend JAMAIS offline (CONFIRME D-A1)
- Visuel : `d1-02-error-network-offline.png` = écran idle « Bienvenue ! » intact alors que le script a poussé `kiosk.error.network` offline. `d1-10-payment-confirm-offline.png` = reste sur /payment.
- Console : `ChunkLoadError: Loading chunk 28 failed (/js/kiosk-errors.js)` + `[pageerror]` non gérée sur **2 surfaces** (idle d1-02/05, payment d1-10/11) — pageerror = catégorie 9.
- Network : `FAILED GET /js/kiosk-errors.js :: net::ERR_INTERNET_DISCONNECTED` dans 11/14 états du scénario 1.
- Code re-greppé : `resources/js/router/modules/kioskRoutes.js:22-27` — `webpackPrefetch: true` présent ; le `<link rel=prefetch>` est injecté (d1-01) mais le chunk est RE-FETCHÉ réseau au moment du import() (pas de Cache-Control sur artisan serve → prefetch non réutilisable). Le chemin K10 `KioskPaymentComponent.vue:592-596` (`router.push kiosk.error.network`, frozen-observé) ne peut donc jamais aboutir offline.
- Atténuation réelle : toast/inline FR « Connexion perdue… » sur payment (d1-10) — l'utilisateur n'est pas muet, mais l'écran dédié (CTA Réessayer + appel staff) livré en W4 est inatteignable précisément dans son unique cas d'usage, et chaque tentative laisse une pageerror non gérée.
- Sévérité : P1 (l'utilisateur garde un filet FR sur payment ; sans le toast ce serait P0). Cul-de-sac réel sur idle/catalogue où AUCUN message n'apparaît (cf. D-004).

#### D-002 — P1 (i18n/UX) : checkout panier offline → toast brut « Network Error » (EN) (CONFIRME D-A2)
- Visuel : `d1-07-cart-checkout-offline.png` — toast « ✕ Network Error » par-dessus le CTA « Valider ma commande », + `quoteError` inline.
- Code re-greppé : `resources/js/components/frontend/kiosk/KioskCartComponent.vue:705-710` — `err?.message` axios brut affiché. Le mapping FR existe à `KioskPaymentComponent.vue:561-568` (`isNetworkError → kiosk.pay_screen.network_lost`) mais n'a pas été porté sur le catch du panier (fichier NON frozen).
- Dispute directe du FINAL_REPORT W3→W4 : le P1 « offline “Network Error” » était déclaré healé — il ne l'est que sur l'écran payment, PAS sur le panier (même session, même erreur, 2 wordings).

#### D-003 — P1 (UX cul-de-sac + i18n) : flux Plan B cash-instruction ne vide JAMAIS le panier → re-validation = « Request failed with status code 409 » brut EN, sans issue (CONFIRME + AGGRAVE D-A5)
- Visuel : `d2b-03-second-order.png` — inline + toast EN « Request failed with status code 409 » sur /kiosk/payment, panier toujours plein, aucune mention que la commande A0009 existe déjà.
- Code re-greppé : `grep clearCart|kioskCart/reset|resetKiosk` dans `KioskCashInstructionComponent.vue` = 0 hit (exit 1) ; reset seulement via retour idle (`autoRedirectSeconds: 45`, `KioskCashInstructionComponent.vue:83`). Clé d'idempotence générée 1×/panier (`kioskCart.js:714-720`, nullée seulement au RESET `:423`) → toute re-soumission du même panier (modifié ou non) repart avec la MÊME clé.
- Branche raw-EN : `KioskPaymentComponent.vue:570-573` — 409 a une `err.response` sans `data.errors` → `err?.message` brut.
- Probe live complémentaire (panier MODIFIÉ avant re-confirm) : voir D-003b ci-dessous.

#### D-004 — P2 (UX robustesse) : aucun indicateur offline sur catalogue/wizard — le 1er signal est le toast EN du checkout (CONFIRME D-A4)
- d1-03/d1-04 : navigation + ajout panier offline sans AUCUN signal ; recovery sans toast. L'indicateur `kiosk-offline-indicator` n'apparaît que s'il existe des commandes en attente offline.

#### D-005 — P1 (a11y inopérante, user-visible pour le public cible) : « Animations réduites » du drawer N'A AUCUN EFFET en session (CONFIRME D-A6)
- Empirique artefacts : d3-07 — `aria-checked=true` mais `data-kiosk-reduced-motion` reste `"false"` sur `<html>` (log `_d3-a11y-drawer-log.txt`).
- Code re-greppé : `KioskAppComponent.vue:480-494` `_wireA11yWatchers()` ne câble QUE contrast/pmr/audio. Le composable `useKioskA11y()` (qui contient les watchers reducedMotion + audioDescription, `resources/js/composables/useKioskA11y.js:97+`) n'a AUCUN consommateur runtime (`grep useKioskA11y(` → composable + 2 specs). Seul `applyKioskA11yFromStore` one-shot au mount (`KioskAppComponent.vue:331`).
- Conséquence : les CSS `[data-kiosk-reduced-motion='true']` (KioskIdleScreenComponent.vue:711-713, KioskInactivityOverlayComponent.vue:243-244, KioskOfflineConflictModalComponent.vue:181-184) ne s'activent jamais sans F5 — un client borne ne recharge jamais. Une option d'accessibilité affichée + togglable + annoncée WCAG 2.3.3 qui ne fait RIEN = défaut user-visible pour l'utilisateur qui en a besoin (vestibulaire). Pattern « tests verts ≠ feature opérante » : les specs testent un composable que la prod ne monte pas.
- Même trou pour `data-kiosk-audio-description` (watcher absent aussi).
- Vérif live : voir probe D-005b.

#### D-006 — P2 (bruit récurrent + toast technique client) : vague 401 stale-token à CHAQUE boot de page + toast « Session rafraîchie automatiquement » visible du client (PRÉCISE D-A3)
- Network artefacts : `401 GET /api/login` dans 60/63 états ; vague complète `401 /api/frontend/menu ×2 + 401 POST /api/frontend/kiosk-event + 401 /api/login ×2` dans tous les états post-navigation (d1-05→11, d2-01→08, d4c, d5-01/05). PAS uniquement le « one-shot boot broadcasting » du gate connu : la vague stale-token → auto-relogin → revoke → prochaine page re-401 est auto-entretenue.
- Visuel : toast jaune « Session rafraîchie automatiquement » rendu DEVANT le client en plein parcours (d3-07 bas d'écran pendant le drawer a11y ; aussi signalé pendant d4c run 1). Code : `KioskAppComponent.vue:379-381` (toast warning role=alert 2500 ms). Un message technique de session n'a rien à faire sur une surface client borne.
- Perte de télémétrie associée : `401 POST /api/frontend/kiosk-event` swallowed `.catch(() => {})` fire-and-forget SANS queue dans `kioskHardware.js:128-141` (les events hardware_error partis pendant la fenêtre stale sont perdus) — alors que `kioskAnalytics.js` (W4-K5) a, lui, une queue + nouvel endpoint `/frontend/kiosk/event`. Le heal W4 « analytics perdues » n'a couvert qu'UN des émetteurs.

#### D-007 — P2 (UX friction) : téléphone saisi au numpad NON reporté dans le formulaire d'inscription (CONFIRME D-A9)
- Visuel : `d4c-03-register-prefill.png` — TÉLÉPHONE* vide après « Non trouvé » + S'inscrire.
- Code re-greppé : `KioskLoyaltyComponent.vue:344` `registerPhone: ''` — aucune affectation depuis `code` (grep complet : v-model/focus/validation uniquement).

#### D-008 — P2 (perte silencieuse multi-tab) : localStorage last-writer-wins, panier perdu sans message (CONFIRME D-A11, sévérité bornée)
- Artefacts d5-01→07 : la Glace de tab A disparaît silencieusement (d5-05 : panier = Tarte Daim + Tiramisu, 7,60 € ✓ arithmétique). Pas de listener `storage`. Token partagé sans kick (pas de single-session borne — info, pas défaut).
- Sur borne physique mono-page le risque réel est faible → P2 (deviendrait P1 si kiosque Chrome session-restore multi-onglets est possible en exploitation).

#### D-009 — P3 (cohérence FR-lock) : drawer a11y « Lecture vocale des étapes (FR/EN) » sur une borne FR-locked ADR-007 + jargon « (EAA 2025) » / « (WCAG 2.3.3) » exposé au client (d3-02).

#### D-010 — OBSERVATION (pas un défaut) : `404 POST /api/frontend/loyalty/check` (d4-04→09) = sémantique REST « membre non trouvé », message FR visible (« Non trouvé » + CTA inscription) → pas un silent error. 429 throttle = FR avec compte à rebours (d4c-01/02) ✓. Idle 3 min : overlay « Toujours là ? » countdown OK (d6-03), retour idle + panier vidé + borne réutilisable (log `_d6-idle-log.txt`, d6-05/06) ✓ — scénario 6 NON rapporté par l'agent coupé : RAS après ma revue.

### Probes live adversaires (scripts `tests/e2e/_d1red-D-borne-robustesse-{1,2}.mjs`, captures d1red-01→06)

#### D-003b — probe panier MODIFIÉ après commande Plan B : 409 dead-end CONFIRMÉ, doublon RÉFUTÉ
- Run live :8768 : cmd1 = `ORDER-POST 201 id=4563 queue=A0018 total=3.8` → cash-instruction. `idempotencyKey` figée `6cf62770-…` (générée à la 1re soumission, jamais renouvelée). Retour SPA catalogue, ajout **Boursin** (payload DIFFÉRENT : Glace+Boursin), re-checkout + re-confirm → `ORDER-POST 409`, messages visibles `["Request failed with status code 409", toast]` (d1red-03).
- Verdict intégrité : **PAS de doublon possible** (middleware conflict-409 sur payload diff, défense backend saine — P0 écarté).
- Verdict UX : **pire que rapporté par l'équipe** — même un client qui MODIFIE légitimement son panier (veut commander autre chose) est bloqué dur en anglais, sans aucune mention de la commande A0018 déjà créée, jusqu'au reset idle (45 s cash-instruction ou 3 min). Cul-de-sac total sur le chemin n°1 de la borne. P1.

#### D-005b — probe « Animations réduites » : option TRIPLEMENT inerte (pire que le WAVE_REPORT)
- Run live : clic switch → `aria-checked=true`, store Vuex LIVE `kioskSettings.reducedMotion=true`, MAIS `data-kiosk-reduced-motion` reste `"false"` (d1red-06).
- **F5 ensuite : store retombe à `reducedMotion=false`** — la claim du WAVE_REPORT « l'effet prendrait après un F5 (re-application boot depuis le store persisté) » est FAUSSE : `reducedMotion` (et `audioDescription`) sont ABSENTS des paths persistés `resources/js/store/index.js:308-316` (contrast/pmr/audio/keyboardEnabled/idleMs/confirmMs/receiptMs/consent* seulement, malgré le commentaire « uniquement les toggles a11y »).
- Triple trou re-greppé : (1) pas de watcher runtime (`KioskAppComponent.vue:480-494`), (2) composable `useKioskA11y()` jamais monté en prod (0 consommateur hors specs), (3) pas de persistance → AUCUN chemin, même reload, par lequel l'option agit. Le switch est un placebo pur.
- Note positive vérifiée : les switches sont correctement nommés via `aria-labelledby` (`KsA11ySettings.vue:71-133`) — pas de défaut de labelling (mon 1er probe lisait `aria-label` seul, faux signal écarté).

### DISPUTES des claims du FINAL_REPORT 2026-06-11 (vague D)

| # | Claim FINAL_REPORT | Verdict | Preuve |
|---|---|---|---|
| 1 | « le PARTIAL (N1 chunk kiosk-errors lazy injoignable offline) healé par l'orchestrateur (webpackPrefetch) » | **REFUTED** | Sur CE harnais (celui de la convergence) : offline → `ChunkLoadError: Loading chunk 28 failed` + pageerror non gérée, écran /kiosk/error/network ne rend JAMAIS (d1-02, d1-05, d1-10, d1-11 console+network ; PNG = écran courant inchangé). Le `<link rel=prefetch>` est injecté mais le chunk est re-fetché réseau au `import()` (artisan serve sans Cache-Control). Nuance : derrière nginx avec en-têtes cache le prefetch POURRAIT devenir réutilisable — mais la claim « healé » a été validée ici même, où c'est démontrablement inopérant. |
| 2 | W3 P1 « offline “Network Error” » healé en W4 | **WEAKENED** | Healé sur /kiosk/payment uniquement (FR « Connexion perdue… », d1-10 ✓). Le MÊME défaut subsiste au checkout panier : toast brut « Network Error » EN (d1-07, `KioskCartComponent.vue:705-710` NON frozen) + « Request failed with status code 409 » EN sur payment pour les erreurs axios non-réseau (d2b-03, d1red-03, `KioskPaymentComponent.vue:570-573`). |
| 3 | « VERDICT CONVERGED — Cycle 2 : P0=0 P1=0 **P2=0**, aucun nouveau finding » | **WEAKENED** | La vague D (offline à chaque étape, F5, multi-tab, options a11y EN USAGE) sort 4 P1 + 4 P2 reproductibles sur la même app — les cycles de convergence n'ont jamais exercé ces chemins de robustesse. « Production-perfect » était vrai au périmètre testé, pas au périmètre borne-réelle. |
| 4 | W4 « boutons overlay inactivité vides » healé | **UPHELD** | d6-03/04 + `_d6-idle-log.txt` : « JE SUIS LÀ » / « ABANDONNER LA COMMANDE » libellés, countdown 30 s, retour idle, panier vidé, borne réutilisable (scénario 6 que l'agent coupé n'avait pas rapporté — revu par moi : RAS). |
| 5 | W4 « écran blanc inscription fidélité (crash vue-i18n @) » healé | **UPHELD** | d4-05→09 : formulaire rend, AZERTY complet, saisie email avec `@` OK, submit correctement disabled. |
| 6 | W4 « analytics perdues (sendBeacon sans Bearer) » healé | **WEAKENED** | `kioskAnalytics.js` migré (endpoint `/frontend/kiosk/event` + queue 200, W4-K5) ✓ MAIS `kioskHardware.js:128-141` poste encore fire-and-forget sur le legacy `frontend/kiosk-event` avec `.catch(() => {})` sans queue → `401 POST /api/frontend/kiosk-event` récurrent dans ~20 états (fenêtre stale-token), events hardware silencieusement perdus. |
| 7 | W4 « 401 rotation token » healé | **WEAKENED** | L'auto-relogin récupère ✓ mais (a) vague 401 complète à CHAQUE boot de page (menu ×2 + kiosk-event + login ×2, 60/63 états — au-delà du gate « 401 one-shot boot broadcasting »), (b) toast technique « Session rafraîchiе automatiquement » rendu DEVANT le client en plein parcours (d3-07, d4c run 1 ; `KioskAppComponent.vue:379-381`). |

### SYNTHÈSE
| Sév | Count | IDs |
|---|---|---|
| P0 | 0 | — (doublon Plan B RÉFUTÉ par probe live : 409 conflict, intégrité numérique saine ; chaîne 13,40 € / 3,80 € / 7,60 € recalculée juste partout) |
| P1 | 4 | D-001 (N1 prefetch ineffectif + pageerror ×2 surfaces), D-002 (« Network Error » EN panier), D-003 (Plan B panier jamais vidé → 409 dead-end EN même panier modifié), D-005 (« Animations réduites » placebo total : ni watcher ni mount ni persistance) |
| P2 | 4 | D-004 (zéro indicateur offline catalogue), D-006 (vague 401/boot + toast session client + perte events hardware), D-007 (téléphone non reporté inscription), D-008 (multi-tab last-writer-wins perte silencieuse) |
| P3 | 1 | D-009 (« (FR/EN) » sur borne FR-locked + jargon EAA/WCAG client) |

**VERDICT : RED** (4 P1 > 0). Le WAVE_REPORT GStack est globalement honnête et bien sourcé (toutes ses citations file:line re-greppées exactes) ; mes ajouts : doublon-modifié réfuté empiriquement (D-003b), placebo total reduced-motion via non-persistance (D-005b), scénario 6 idle revu (RAS), hardware-events leg du heal analytics, et 3 claims du FINAL_REPORT d'hier REFUTED/WEAKENED.
