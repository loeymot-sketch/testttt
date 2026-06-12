# DISPUTE Round 1 — Vague D : BORNE robustesse & a11y EN USAGE
Date : 2026-06-12 · App : http://127.0.0.1:8768 (DB foodking_e2e jetable) · Viewport borne 1080×1920 fr-FR, channel:'chrome'
Agent : GSTACK MAIN TEAM (Architect+Tester+A11y+SRE). Verdicts de sévérité = adversaire (round 2).

Artefacts : `reports/test-e2e/dispute-2026-06-12/round-1/D-borne-robustesse/` — quartet par état (`<tag>.png` + `<tag>.dom.html` (80 KB max) + `<tag>.console.txt` + `<tag>.network.txt`).
Scripts jetables : `tests/e2e/_d1-D-*.mjs`.

## Grounding code (re-greppé avant test)
- Fix N1 webpackPrefetch : `resources/js/router/modules/kioskRoutes.js:22-27` — chunk `kiosk-errors` (4 écrans erreur) en `webpackPrefetch: true`.
- Drawer a11y : `resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue` — options réelles = contraste AA/AAA (radiogroup), PMR (switch), Audio (switch), Audio-description (switch), Animations réduites (switch), Réinitialiser, Terminé. **Pas d'option « taille de texte » dans le drawer** (PMR = reflow bas d'écran). Section THÈME retirée (W4-K9), langue retirée (ADR-007 FR-lock).
- Application effets : `KioskAppComponent.vue:470-487` → attributs `data-kiosk-contrast` / `data-kiosk-pmr` sur `<html>`.
- Inactivité : `kioskSettings.js:43-47` — idleMs=180000 (3 min), confirmMs=30000 (« Toujours là ? » 30 s), receiptMs=30000.
- Persistance panier : Vuex persisté dans `localStorage.vuex` (clé `kioskCart`).
- Routage erreur réseau : `kioskCart.js:28-33` `KIOSK_ERROR_ROUTES[NETWORK]='kiosk.error.network'` ; route `kioskRoutes.js:261-262`.

## États couverts (incrémental)

### Scénario 1 — Offline/Online à chaque étape (scripts `_d1-D-1-offline.mjs`, `_d1-D-1b-wizard-offline.mjs`)
| Tag | État | Verdict factuel |
|---|---|---|
| d1-01-idle-prefetch | Idle, online | `<link rel="prefetch" href="/js/kiosk-errors.js">` PRÉSENT dans le DOM (1 seul link prefetch). |
| d1-02-error-network-offline | Idle → offline → router.push(kiosk.error.network) | **L'écran erreur ne rend PAS** : `ChunkLoadError: Loading chunk 28 failed (/js/kiosk-errors.js)` + `net::ERR_INTERNET_DISCONNECTED`. URL reste /kiosk/idle, l'idle s'affiche toujours. |
| d1-03-catalogue-offline | Catalogue offline, clic catégorie + ajout produit | Navigation catégories + ajout panier (local Vuex) fonctionnent offline. Aucun message offline affiché à l'utilisateur sur le catalogue. |
| d1-04-catalogue-recovery | Retour online | Panier intact (Glace ×1). Pas de toast de récupération. |
| d1b-01→03 (focus wizard) | Wizard « Boisson Seule » ouvert → offline → AJOUTER AU PANIER | Ajout local OK offline, overlay se ferme normalement, item au panier. **Pas un défaut** (le « close silencieux » du 1er run = complétion 1-step normale). |
| d1-07-cart-checkout-offline | Panier, clic « Valider ma commande » offline | Toast brut **« Network Error » (EN)** + `quoteError` « Network Error » dans la page. Panier intact (4 articles 13,40 €). |
| d1-08-cart-recovery | Retour online sur panier | Panier intact : Glace×2 7,60 + Boisson Seule 2,00 + Tarte Daim 3,80 = 13,40 € ✓. |
| d1-09-payment-online | /kiosk/payment atteint | « PAIEMENT À LA CAISSE », TOTAL À RÉGLER 13,40 € ✓ (cohérent panier). |
| d1-10-payment-confirm-offline | Payment, clic « Confirmer ma commande » offline | Message FR « Connexion perdue. Votre commande n'a pas été envoyée. » (inline + toast) ✓ MAIS le router.push vers /kiosk/error/network échoue (`ChunkLoadError` ×4 dans console) → reste sur /payment. |
| d1-11-payment-recovery | Retour online payment | Panier intact, écran fonctionnel. Pas de bouton « Réessayer » (re-tap Confirmer = le retry). |

### Intégrité numérique relevée (scénario 1)
- Glace 3,80 € ×2 = 7,60 € ✓ · Boisson Seule 2,00 € ×1 ✓ · Tarte Daim 3,80 € ×1 ✓ · Sous-total = Total = 13,40 € ✓ · « 4 articles » = 2+1+1 ✓ · Payment « TOTAL À RÉGLER : 13,40 € » = total panier ✓. Format FR `13,40 €` partout ✓.

## Observations factuelles & anomalies suspectées

### D-A1 (SUSPECTÉ — robustesse offline) : le fix W4-N1 webpackPrefetch est INEFFECTIF à l'exécution — l'écran /kiosk/error/network ne rend JAMAIS offline
- **Evidence** : d1-02 + d1-10 quartets. Le link `rel=prefetch` est bien injecté (`kioskRoutes.js:22-27`, re-greppé), MAIS dès que le réseau est coupé, le dynamic import refait un GET réseau `/js/kiosk-errors.js` → `net::ERR_INTERNET_DISCONNECTED` → `ChunkLoadError: Loading chunk 28 failed` (console.txt des 2 états, pageerror) → la navigation vue-router est abandonnée, l'utilisateur reste sur l'écran courant.
- **Cause technique observée** : `curl -sI http://127.0.0.1:8768/js/kiosk-errors.js` → réponse SANS `Cache-Control`/`ETag`/`Last-Modified` (serveur dev artisan). Un prefetch sans en-têtes de cache n'est pas réutilisable par le `<script>` du chunk → re-fetch réseau. Le commentaire du fix (« le chunk doit être en cache navigateur AVANT une coupure ») n'est pas tenu sur ce harnais — celui-là même où la convergence d'hier a validé N1.
- **Impact en usage** : le chemin K10 (`KioskPaymentComponent.vue:588-596`, frozen — observé seulement) qui route les erreurs réseau vers l'écran dédié (CTA Réessayer + appel staff) ne s'exécute jamais offline ; pageerror non gérée à chaque tentative. Le filet de sécurité réel = toast FR (payment) — il sauve l'UX mais l'écran dédié livré en W4 est inatteignable précisément dans le seul cas où il sert.
- Nuance pour l'adversaire : en prod derrière nginx, des en-têtes cache pourraient rendre le prefetch réutilisable — à trancher, mais la claim « N1 healé » a été validée sur CE harnais.

### D-A2 (SUSPECTÉ — i18n/UX) : checkout panier offline → toast brut « Network Error » (EN), incohérent avec l'écran payment
- **Evidence** : d1-07 quartet (toast visible sur le PNG, bas d'écran) + texte page `quoteError` = « Network Error ».
- **Code re-greppé** : `resources/js/components/frontend/kiosk/KioskCartComponent.vue:705-710` — `catch (err) { const message = err?.response?.data?.message || err?.message || …}` → `err.message` axios brut (« Network Error ») affiché tel quel. Le mapping réseau→FR existe pourtant à `KioskPaymentComponent.vue:560-568` (K10, `kiosk.pay_screen.network_lost`) mais n'a PAS été appliqué au même catch du panier (KioskCartComponent NON frozen).
- Même UX : aucune redirection vers /kiosk/error/network depuis le panier (le quote échoue en toast EN, point).

### D-A3 (OBSERVATION — bruit 401 récurrent en ligne, à distinguer du « 401 one-shot boot » connu)
- **Evidence** : network.txt cumulés scénario 1 : `401 GET /api/login` répété à chaque phase (idle, catalogue, wizard) + `401 GET /api/frontend/menu` ×2 + `401 POST /api/frontend/kiosk-event` pendant la navigation catalogue EN LIGNE (pas offline — offline produit des ERR_INTERNET_DISCONNECTED, distincts).
- Le gate connu couvre « 401 one-shot boot kiosk broadcasting » ; ici on observe des 401 menu/kiosk-event/login récurrents en usage. L'app se rétablit seule (auto-relogin `KioskAppComponent.vue:376-380` « Session rafraîchie automatiquement ») mais le bruit console/network en session normale est réel et reproductible.

### D-A4 (OBSERVATION mineure) : aucun indicateur offline sur le catalogue
- d1-03 : réseau coupé, l'utilisateur peut naviguer/ajouter sans aucun signal « hors-ligne » (l'indicateur `kiosk-offline-indicator` de KioskAppComponent n'apparaît que sur commandes en attente). Le 1er signal arrive seulement au checkout (toast EN, cf. D-A2).

### Scénario 2 — Reload sauvage (F5) à chaque étape (script `_d1-D-2-reload.mjs`)
| Tag | État | Verdict factuel |
|---|---|---|
| d2-01-catalogue-after-f5 | F5 sur /kiosk/categories?cat=9 | URL conservée, panier SURVIT (Glace ×1, vuex persisté localStorage). Pas de redirect idle. |
| d2-02-wizard-after-f5 | F5 avec wizard ouvert | Overlay perdu (composition en cours abandonnée silencieusement), retombe sur la catégorie, panier intact. Aucun message. |
| d2-03-cart-after-f5 | F5 sur /kiosk/cart | 1 ligne rendue, total 3,80 € ✓, panier intact. |
| d2-04-loyalty-after-f5 | F5 sur /kiosk/loyalty | Reste sur /kiosk/loyalty, panier intact. |
| d2-05-upsell-after-f5 | F5 sur /kiosk/upsell | Reste sur /kiosk/upsell, panier intact. |
| d2-06-payment-after-f5 | F5 sur /kiosk/payment | Reste sur /payment, « TOTAL À RÉGLER : 3,80 € » ✓. |
| d2-07/08-cash-instruction | Confirm → F5 sur /kiosk/cash-instruction?number=A0007&total=3.8 | Avant F5 : #A0007 / 3,80 € ; après F5 : identiques (props query). MAIS panier localStorage ENCORE PLEIN après commande créée (cf. D-A5). |

Intégrité numérique scénario 2 : Glace 3,80 € → cart total 3,80 € → payment 3,80 € → cash-instruction « #A0007 » + 3,80 € — chaîne cohérente bout-en-bout ✓ (query `total=3.8` brut EN dans l'URL mais rendu localisé « 3,80 € »).

### Scénario 2b — Probe double-commande depuis cash-instruction (script `_d1-D-2b-double-order.mjs`)
- ORDER-POST #1 : 201, id=4535, queue=A0009, total=3.8.
- À cash-instruction, `kioskCart.items` contient toujours Glace×1. `router.push('/kiosk/cart')` (nav SPA utilisateur) → panier rendu PLEIN (1 ligne) — d2b-02.
- Re-checkout + re-confirm → ORDER-POST #2 : **409** (idempotency backend bloque le doublon — aucune 2e commande créée ✓).
- UX résultante (d2b-03 lu) : message brut **« Request failed with status code 409 » (EN)** inline + toast sur l'écran payment ; le panier reste plein ; aucune indication que la commande A0009 est déjà enregistrée. Cul-de-sac.

### D-A5 (SUSPECTÉ — flux Plan B) : le panier n'est JAMAIS vidé sur le flux cash-instruction → re-navigation = panier plein post-commande + 409 brut EN
- **Code re-greppé** : `kioskCart/reset` est dispatché par `KioskConfirmationComponent.vue:316,436`, `KioskIdleScreenComponent.vue:241`, `KioskWaitingComponent.vue` — **AUCUN dispatch dans `KioskCashInstructionComponent.vue`** (grep `clearCart|kioskReset|resetKiosk|kioskCart/reset` = 0 hit dans ce fichier). Or le flux Plan B (`kiosk.payment_route_all_to_counter=true`) navigue payment→cash-instruction SANS passer par Confirmation → le panier ne se vide qu'au retour idle (45 s timeout).
- **Conséquences observées** : (1) fenêtre de 45 s où un client peut revenir à un panier plein alors que sa commande existe (A0009) ; (2) la re-validation affiche « Request failed with status code 409 » non traduit (même gap de mapping d'erreur que D-A2, ici dans `KioskPaymentComponent.vue:570-573` — la branche `err?.message` brute) ; (3) la défense anti-doublon est PORTÉE par l'idempotency backend seule. À l'adversaire de coter : si la clé d'idempotence change (panier modifié d'1 article), un vrai doublon partiel devient plausible — non testé ici (1 seul payload identique testé).

### Scénario 3 — Drawer accessibilité : chaque option, effet réel, retour normale (script `_d1-D-3-a11y-drawer.mjs`)
| Tag | État | Verdict factuel |
|---|---|---|
| d3-01-idle-baseline | Idle AA | `data-kiosk-contrast=aa`, `--kiosk-text #1a1a1a`, `--kiosk-primary #f4501e`, focus 4px. |
| d3-02-drawer-open | Drawer ouvert | 5 contrôles présents (AA/AAA, PMR, Audio, Description audio, Animations réduites) + Réinitialiser/Terminé. FR propre (« Textes plus grands… » — vérifié `resources/js/languages/fr.json`). Focus initial sur `.ks-a11y-drawer` ✓, Escape ferme ✓. |
| d3-03/04 contraste AAA | AAA sélectionné | EFFET RÉEL : `data-kiosk-contrast=aaa`, `--kiosk-text→#000`, `--kiosk-primary→#a00013`, focus 4px→6px, aria-checked ✓. MAIS le CTA orange idle reste rgb(244,80,30)/blanc (hardcodé, ne suit pas la var — cf. D-A8). |
| d3-05 PMR ON | Mode PMR | EFFET RÉEL : `data-kiosk-pmr=true`, font-size CTA 16px→19.2px. aria-checked ✓. |
| d3-07 tous toggles ON | Audio + Description audio + Animations réduites | aria-checked=true ×3, `data-kiosk-audio=true` ✓ — **MAIS `data-kiosk-reduced-motion` RESTE "false"** (cf. D-A6). |
| d3-09/10 RESET | Réinitialiser | Retour complet : aa/false ×5, vars restaurées (#1a1a1a/#f4501e/4px), aria tous remis ✓, CTA identique baseline ✓. |

### D-A6 (SUSPECTÉ — a11y INOPÉRANTE) : « Animations réduites » ne s'applique JAMAIS en live — le watcher n'est pas câblé
- **Empirique** : d3-07 — toggle ON (aria-checked=true, store muté) mais `document.documentElement.getAttribute('data-kiosk-reduced-motion')` reste `"false"` après le clic (log `_d3-a11y-drawer-log.txt`).
- **Code re-greppé** :
  - `KioskAppComponent.vue:480-494` `_wireA11yWatchers()` ne câble QUE contrast/pmr/audio — aucun watcher reducedMotion ni audioDescription.
  - Le composable complet `useKioskA11y()` (`resources/js/composables/useKioskA11y.js`) qui contient les watchers reducedMotion/audioDescription a **zéro consommateur runtime** : `grep -rn "useKioskA11y(" resources/js` → seuls les tests (`tests/js/kioskA11yComposable.spec.js`, kioskRtl, kioskFrLockImmutable). Le runtime n'utilise que `applyKioskA11yFromStore` one-shot au mount (`KioskAppComponent.vue:331`).
  - Conséquence : les CSS consommateurs `[data-kiosk-reduced-motion='true']` (`KioskIdleScreenComponent.vue:711-713`, `KioskInactivityOverlayComponent.vue:243-244`, `KioskOfflineConflictModalComponent.vue:181-184`) ne s'activent jamais dans la session. L'effet ne prendrait qu'après un F5 (re-application boot depuis le store persisté) — un client borne ne recharge jamais la page.
  - Pattern « tests verts ≠ feature opérante » : les specs testent le composable que la prod ne monte pas.
- Même câblage manquant pour `data-kiosk-audio-description` (attribut) — impact moindre si le TTS lit le store directement (non audité ici).

### D-A8 (OBSERVATION — recoupe le gate contraste orange) : en mode AAA le CTA principal idle ne suit pas la palette renforcée
- d3-04 : `--kiosk-primary` passe à #a00013 mais le bouton tactile idle conserve un fond hardcodé #F4501E avec texte blanc (~3,0:1). L'option « Renforcé (AAA • 7:1) » n'agit donc pas sur le CTA n°1 de l'écran d'accueil. Recoupe le gate owner « contraste orange marque » mais l'angle « le mode AAA opt-in reste sous 7:1 sur le CTA principal » est distinct — à arbitrer par l'adversaire.
- Mineur connexe : `audio_hint` FR = « Lecture vocale des étapes (FR/EN) » alors que la borne est FR-locked ADR-007.

### Scénario 4 — Clavier virtuel : numpad téléphone fidélité + AZERTY email (scripts `_d1-D-4-keyboard.mjs`, `_d1-D-4b-del.mjs`, `_d1-D-4c-found.mjs`)
| Tag | État | Verdict factuel |
|---|---|---|
| d4-01/02 | Saisie 0612345678 au numpad | 10 chiffres saisis exactement ✓. |
| d4b-01 | Correction : touche del | **Del FONCTIONNE** : `button[aria-label="Effacer le dernier chiffre"]` (`KioskLoyaltyComponent.vue:46-50,341`) → 0612345678 → ×3 del → « 0612345 » ✓ → re-saisie → « 0612345678 » ✓. (1er run : mes sélecteurs ⌫/`.kiosk-numpad-btn-del` ne matchaient pas — faux négatif corrigé, PAS un défaut.) |
| d4-04 | Validation numéro inconnu | Message « Non trouvé » + CTA « Pas encore membre ? S'inscrire ». NB : le champ accepte jusqu'à 20 caractères (champ mixte code OU téléphone, `maxlength=20` — un 13-chiffres part au serveur et revient « Non trouvé », pas de validation format tel côté client). |
| d4-05→08 | Inscription : claviers virtuels | **AZERTY complet CONFIRMÉ** (rangées `azertyuiop / qsdfghjklm / wxcvbn` + `- _ . @` + Effacer/Espace/⌫/OK) sur nom ET email ; saisie « jean » puis « jean@x.fr » exactes ✓ ; miroir de saisie au-dessus du clavier ✓. |
| d4-09 | Submit inscription | « Créer mon compte » correctement DISABLED tant que TÉLÉPHONE* vide ✓ (pas de soumission silencieuse). |
| d4c-01 (run 1) | Tel CONNU 0612345678 | Fiche membre : « Victim Secret · 165 points = 1,65 € de réduction · Plus que 85 points pour le prochain palier » — 1 pt = 0,01 €, palier 250 = 165+85 ✓ cohérent. Choix Utiliser/Garder + Confirmer/Annuler. |
| d4c-02 (run 2) | Anti-abus | Re-tests rapprochés → **throttle actif** : « Trop de tentatives, patientez quelques secondes. » + « Trop de requêtes — patientez 14s avant de réessayer. » (FR, compte à rebours) ✓. |
| d4c-03 | Prefill téléphone inscription | TÉLÉPHONE prérempli = "" (vide). |

DB vérifiée (SELECT read-only foodking_e2e) : `users` id=44 « Victim Secret » tel 0612345678 ; id=60 « central-dash-vis Admin » tel 0699999991 — mes 2 « numéros inconnus » étaient en fait des comptes seedés (artefacts de tests précédents), d'où l'écran membre inattendu. Pas un défaut app.

### D-A9 (SUSPECTÉ — UX friction) : le téléphone saisi à l'étape « vérifier » n'est PAS reporté dans le formulaire d'inscription
- Empirique : d4-09 + d4c-03 — après saisie complète au numpad et « Non trouvé », l'écran inscription présente TÉLÉPHONE* VIDE → le client borne retape son numéro.
- Code re-greppé : `KioskLoyaltyComponent.vue:344` `registerPhone: ''` — aucune affectation depuis `code` dans tout le fichier (grep `registerPhone` : v-model/focus/validation uniquement).

### D-A10 (OBSERVATION — confidentialité fidélité, possiblement déjà arbitrée GOAL loyalty) : le check tel affiche nom complet + solde sans 2e facteur
- d4c-01 : taper un numéro → affiche NOM COMPLET (« Victim Secret »), solde (165 pts = 1,65 €) et permet « Utiliser mes points » sur la commande courante. Vecteur : brûler les points d'autrui en connaissant/devinant son tel. ATTÉNUÉ par le rate-limit observé (14 s) qui freine l'énumération. Si le GOAL loyalty (audit abuse 18/18) a déjà arbitré ce design, à classer gate connu ; sinon à coter.
- Connexe D-A3 : toast « Session rafraîchiе automatiquement » (warning) apparu PENDANT le parcours fidélité client (d4c-01 run 1) — artefact de l'auto-relogin 401 visible par le client en pleine session.

### Scénario 5 — Multi-tab borne, même context (script `_d1-D-5-multitab.mjs`)
| Tag | État | Verdict factuel |
|---|---|---|
| d5-01 | Tab A : flux entamé, Glace au panier | Token `2782|mu0iAAso…` en localStorage. |
| d5-02 | Tab B ouverte (même context) | **Token IDENTIQUE réutilisé** — pas de re-login, pas de révocation : la 2e tab NE casse PAS la 1re (pas de single-session enforcement constaté). |
| d5-03 | Tab B → /kiosk/cart | Panier B VIDE (0 ligne) alors que localStorage contenait la Glace de A au moment du boot B. |
| d5-04 | Tab B ajoute Tarte Daim | localStorage = [Tarte Daim] — **la Glace de A a disparu du storage** (write-through de l'état in-memory B). |
| d5-05 | Tab A (full reload) ajoute Tiramisu | A se réhydrate du storage [Tarte Daim] + ajoute → [Tarte Daim, Tiramisu]. L'item original de A (Glace) est définitivement perdu, silencieusement. |
| d5-06/07 | Tab B relue | In-memory B = [Tarte Daim] mais après reload panier rendu = 2 lignes, total 7,60 € (3,80+3,80 ✓). |

### D-A11 (OBSERVATION — robustesse multi-tab) : pas de synchro cross-tab, localStorage last-writer-wins → perte silencieuse d'articles
- Pas de listener `storage` entre tabs : chaque tab écrase l'état persisté avec son in-memory à chaque mutation ; un panier peut perdre des articles sans aucun message (Glace perdue ci-dessus). Sur une borne physique mono-écran le risque réel est faible (1 seule page) — pertinent surtout si un kiosk redémarre son navigateur avec session restore multi-onglets ou en maintenance staff. Sévérité → adversaire.
- Côté POSITIF : aucun crash, aucun token kick, montants restent justes après réhydratation (7,60 € = 3,80 ×2 ✓).

### Gates/P3 connus revus dans ce scénario (NON recomptés)
- Images cassées « Boisson Seule »/« Frites Seules » + descriptions « Upsell item » (DATA DB) — visibles d1-05/d1-07.
- Spam log wizard composer (`step skipped viande_2`) ×8 dans console.txt — connu (wizard frozen).
- 401 one-shot boot broadcasting — la part « boot » du bruit 401 (mais voir D-A3 pour la part récurrente).
