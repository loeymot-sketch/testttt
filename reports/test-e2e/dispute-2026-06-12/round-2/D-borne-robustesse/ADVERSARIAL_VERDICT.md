# ADVERSARIAL VERDICT — Round 2, Vague D : borne robustesse (dispute-2026-06-12)
Superviseur adversarial · 2026-06-12 · App :8768 (foodking_e2e, bundle 12/06 13:07) · ÉCRIT INCRÉMENTALEMENT

## Statut : TERMINÉ — verdict GREEN vague D (0 P0 / 0 P1 ouverts ; 4 P2 survivants/nouveau + 1 P3 routé C) · 14 PNG R2 lus + 3 captures propres + quartets clés + 2 probes live indépendantes

## 0. Verify-before-report — re-greps source + bundle (TOUS exacts)
| Citation WAVE_REPORT/HEAL_H2 | Re-grep | Verdict |
|---|---|---|
| `kioskRoutes.js:22-25` imports eager 4 écrans erreur (D-001) | imports statiques présents lignes 23-26, commentaire dispute-r1, guard `hydrateKioskMotionPrefs` ligne 61 | ✅ EXACT |
| `KioskCashInstructionComponent.vue:107` `dispatch('kioskCart/reset')` au mount + `:54` `cta_back_home` (D-003/ADV-F-P1-1) | reset ligne 107 try/catch, CTA `$t('kiosk.cash_instruction.cta_back_home')` ligne 54 | ✅ EXACT |
| `KioskCartComponent.vue:735,739` mapping FR réseau/429 (D-002) | `kiosk.network_lost_cart` :735, `error.kiosk_rate_limited` :739, détection identique payment | ✅ EXACT |
| `helpers/kioskMotionPrefs.js` + `tokens.css:193-209` (D-005) | fichier existe ; `html.ks-reduced-motion` vars 0ms + kill global animation/transition `!important` :193-209 | ✅ EXACT |
| `KioskLoyaltyComponent.vue:498-506` goToRegister prefill (D-007) | regex phone 6-15 chiffres, pas d'écrasement (`!this.registerPhone`) :497-505 | ✅ EXACT |
| `kioskCart.js:29` KIOSK_PROMO_STORAGE_KEY + `:675` restorePersistedPromo (C-ADV-02) | clé :29, action restore re-validation SERVEUR :675-682, purge clearPromo | ✅ EXACT |
| `kioskAuthInterceptor.js:84` SILENCED_EVENTS (D-006) | `Set(['kiosk-auth-retried'])` :84, stopImmediatePropagation + console.debug | ✅ EXACT |
| Idle light `KioskIdleScreenComponent.vue` (ADV-F-P1-3) | fallback `linear-gradient(180deg,#FFFFFF 0%,#FFF4EE 100%)` :377, variantes sombres TOUTES gatées `.kiosk-idle--has-video` (12 hits) | ✅ EXACT |
| D-009 libellés fr.json | regex jargon `WCAG\|EAA\|AAA\|7:1\|FR/EN\|2.3.3\|2.2` sur `kiosk.a11y` = **0 hit** ; libellés sobres confirmés | ✅ EXACT |
| **Wire-up orchestrateur `loyalty_redeem_discount`** | `kioskCart.js:183` dans `buildKioskQuotePayload` (`state.loyaltyDiscount \|\| 0`, commentaire C-RED-02) + **présent dans le bundle servi** `public/js/app.js` (grep=1) | ✅ EXACT |
| Bundle servi 12/06 13:07 | markers `ks-reduced-motion`/`cta_back_home`/`foodking:kiosk-promo-code`/`network_lost_cart`/`kiosk-auth-retried` chacun ≥1 dans public/js/app.js | ✅ POST-HEAL |

## 1. Qualité des artefacts R2 (vs P1 process round 1)
- 40 états × quartet complet. `.dom.html` = #app outerHTML, tailles VARIABLES (81 o transitoire → 14,9 Ko), fins de fichier propres (toast-container fermé) → **plus de troncature fixe 81923 o du round 1 : DOM utilisable. Pas de P1 process.**
- console/network .txt lisibles. 5 doublons byte-identiques déclarés par le GStack — vérifiés par sha1 ci-dessous.

## 2. Revue visuelle + quartets (au fil de l'eau)
- `d2D1-02` (PNG lu) : écran « Connexion perdue » COMPLET offline — 📡, titre, sous-titre, hint, CTA RÉESSAYER orange + PRÉVENIR UN MEMBRE DE L'ÉQUIPE. Console : 2× ERR_INTERNET_DISCONNECTED ressources (attendu offline), **0 ChunkLoadError, 0 pageerror**. Network : 0 requête `kiosk-errors`. DOM : section `role=alert aria-live=assertive` complète. → D-001 rend offline = VRAI.
- `d2D1-04` (PNG lu) : toast FR « Connexion perdue. Votre panier est conservé — réessayez dans un instant. » + panier intact Glace ×1 3,80 €, totaux cohérents, AUCUN « Network Error » EN. Note mineure : le toast chevauche le bord bas du CTA « Valider ma commande » (transitoire, dismissible ✕) — non compté, même classe que le pattern toast connu.

- `d2D2-02` (PNG lu) : « Rendez-vous en caisse / #A0006 / 3,80 € » + copy unifiée « Réglez à la caisse — espèces, carte ou ticket restaurant. » + countdown 42 s + CTA « RETOUR À L'ACCUEIL » pleine largeur. `d2D2-03` (PNG lu) : panier 0 article, empty-state propre (illustration + copy + CTA « Ajouter des articles »), bouton checkout ABSENT.
- `d2D3-01` (PNG lu) : idle dominante CLAIRE (blanc→pêche→orange bas), « Bienvenue ! » encre noire, logo Le Cayenne. `_d2D-3-a11y-log.txt` : computed `linear-gradient(rgb(255,255,255), rgb(255,232,221) 55%, rgb(244,80,30))`, titleColor rgb(15,15,15), hasVideoClass=false.
- `d2D3-03` (PNG lu) : drawer Accessibilité libellés sobres (Standard/Renforcé/Mode PMR/Assistance audio/Description audio détaillée/Animations réduites), 0 jargon — log `jargon hits=[]` sur innerText 326c.
- D-005 log computed : AVANT `attr=false varFast=140ms ctaTransition=0.14s` → APRÈS toggle LIVE sans F5 `attr=true hasClass=true varFast=0ms ctaTransition=1e-06s` + localStorage `{"reducedMotion":true}` → APRÈS F5 identique → reset + reset-F5 restaurent 140ms/0.14s. Triple trou du round 1 (watcher/mount/persistance) fermé par le chemin helper+guard, vérifié par valeurs CSS computed — pas un placebo.
- `d2D4-03` (PNG lu) : TÉLÉPHONE* pré-rempli « 0788123456 », NOM/E-MAIL vides, submit disabled tant que NOM vide (état correct).
- `d2D5-02` (PNG lu) : post-F5 ligne « Code promo BORNEAUDIT5 −3,80 € », Total 0,00 €, bandeau vert « ✓ … appliqué (−3,80 €) » + « Retirer le code ». Network : `401 POST promo/validate` au mount (re-validation serveur — 401 rejoué par l'intercepteur, le bandeau ne peut s'afficher que sur réponse succès car `validatePromo` ne commit que sur 2xx). Le recorder ne loggue que ≥400 — le 200 de replay n'y figure pas, cohérent avec la méthodo.
- `d2D7-02` (PNG lu) : confirm OFFLINE depuis payment → écran « Connexion perdue » rendu + toast FR « Connexion perdue. Votre commande n'a pas été envoyée. » + chip « 1 · Mon panier · 3,80 € ». Console : 0 ChunkLoadError, 0 pageerror (ws:6001 + ERR_INTERNET_DISCONNECTED = allowlist/attendu offline).
- `d2D7-03` (PNG lu) : +9 s ONLINE après RÉESSAYER → écran « Connexion perdue » RE-RENDU sur /kiosk/error/network (base du nouveau D-R2-A1).

## 3. Probes live adversariales indépendantes (scripts `tests/e2e/_d2red-D-borne-robustesse-{1,2}.mjs`, captures `d2red-*`)

### Probe A — D-001 offline render (indépendante du harnais GStack)
- `context.setOffline(true)` → route SPA `/kiosk/error/network` réseau COUPÉ → **écran rend** (« Connexion perdue » + RÉESSAYER + PRÉVENIR…), PNG `d2red-A-error-network-offline` lu = écran complet stylé.
- **Console : 0 ChunkLoadError, 0 pageerror** (vs round 1 : ChunkLoadError ×4 + pageerror sur 2 surfaces). → **D-001 CONFIRMED**.

### Probe B — Plan B bout-en-bout (D-003 + ADV-F-P1-1)
- Glace 3,80 € → payment : **« Retour au panier » visible=true** → confirm : **ORDER-POST 201** → `/kiosk/cash-instruction?number=A0020&total=3.8`.
- **PANIER POST-COMMANDE : items=0, idemKey=null, promo=null** (localStorage lu) — le 409 dead-end du round 1 est structurellement impossible (clé nullée + panier vide).
- CTA « RETOUR À L'ACCUEIL » présent → clic → `/kiosk/idle`. PNG `d2red-B-cash-instruction` lu : #A0020 / 3,80 € = exactement l'ORDER-POST. → **D-003 + ADV-F-P1-1 CONFIRMED**.

### Probe C — D-R2-A1 : boucle RÉESSAYER online (anomalie GStack VÉRIFIÉE + échappatoire exercée)
- confirm offline → `/kiosk/error/network` → online rétabli → **RÉESSAYER ×2 : re-land à chaque fois sur `/kiosk/error/network`, écran « Connexion perdue » re-rendu** (`window.location.reload()` sur la route erreur, aucun health-redirect au boot — `KioskErrorNetworkComponent.vue:62-72` re-greppé, non frozen).
- **Échappatoire réelle exercée** : chip « Mon panier » visible → clic → `/kiosk/cart`, panier intact (1 item). PNG `d2red-C-retry-online-still-error` + `d2red-C-escape-via-cart-chip`.
- **Cotation : P2** (pas P1 : aucune perte de données, échappatoire visible et fonctionnelle prouvée, reset idle en dernier recours ; mais le CTA primaire de l'écran de récupération est une boucle aveugle avec un titre mensonger « Connexion perdue » une fois la connexion revenue). NOUVEAU — invisible au round 1 car l'écran ne rendait jamais (conséquence directe du heal D-001, pas une régression du heal).

## 4. Vérifs complémentaires
- **Wire-up orchestrateur `loyalty_redeem_discount`** : `kioskCart.js:183` dans `buildKioskQuotePayload` ; `buildKioskOrderPayload` (kioskCart.js:193-200) **réutilise le payload quote** → le champ se propage mécaniquement au POST `frontend/order` (:799-806). Présent dans le bundle servi. Le comportement financier bout-en-bout (remise réellement facturée) relève des vagues C/E ; au périmètre D le câblage est réel et livré. → CONFIRMED (code-level).
- **Reset cash-instruction purge AUSSI la promo persistée** : `kioskCart.js:774` `writePersistedPromoCode(null)` AVANT `commit('RESET')` — pas de fuite de code promo du client précédent via C-ADV-02. Vérifié probe B (promo=null) et d2D8-04.
- **Tripwire frozen** : `git diff --stat` (lecture seule) sur les **12 commits H2** × 13 chemins frozen §7 → **0 ligne partout** (9960df426, 36d71fbc2, 43c5f2d76, 1eeb3b2ed, 3538e1a04, dcf675617, ab84dd6ac, 8db1ebf1a, 41708df10, 16d869911, 0438406eb, 4a96f8009).
- **Doublons PNG déclarés** : sha1 vérifié — d2D1-07≡d2D1-08 et d2D7-01≡d2D2-01 (2 paires contrôlées) : déclaration GStack honnête.
- **D-006 résiduel re-greppé** : `frontend/kiosk-event` legacy fire-and-forget `.catch(() => {})` TOUJOURS présent (KioskPaymentComponent.vue:1027 cash_drawer_failure, KioskAppComponent.vue:1010/1025 hardware_health/hardware_event — ce dernier FROZEN, KsA11ySettings, KsConsentModal) → events hardware partis dans la fenêtre stale-token toujours perdus ; bruit 401 récurrent confirmé (agrégat R2 : 401 menu ×64, kiosk-event ×52, login ×47). Le toast client est silencé (D-006 healé côté client).

### Probe 2 — D-004 (non healé, vérif honnête du statut SURVIVANT)
- OFFLINE → navigation catalogue + ajout produit : `indicateur=null`, `toast=""`, Glace ajoutée SILENCIEUSEMENT au panier (cart items=1). Capture `d2red-D004-offline-catalogue-no-signal`. Aucun signal offline avant le checkout (dont le toast est désormais FR — la sévérité réelle baisse, le défaut demeure). → **SURVIVANT P2**.

## 5. VERDICTS HEALS du périmètre D (9/9 jugés)

| Heal | Verdict | Preuve décisive |
|---|---|---|
| **D-001** écran erreur réseau offline (imports eager, `9960df426`) | **CONFIRMED** | Ma probe A indépendante : écran rend offline, **0 ChunkLoadError / 0 pageerror** (round 1 : ×4 + pageerror 2 surfaces) ; flux réel payment-confirm-offline route + rend (probe C, d2D7-02) ; DOM `role=alert` complet dans d2D1-02 |
| **D-002** toast checkout panier offline FR (`36d71fbc2`) | **CONFIRMED** | d2D1-04 PNG : « Connexion perdue. Votre panier est conservé — réessayez dans un instant. », 0 « Network Error » EN ; mapping `KioskCartComponent.vue:730-746` re-greppé identique payment |
| **D-003 + ADV-F-P1-1** panier vidé + retour accueil (`43c5f2d76`) | **CONFIRMED** | Ma probe B : ORDER-POST **201** → cash-instruction → **items=0, idemKey=null, promo=null** (localStorage), CTA « RETOUR À L'ACCUEIL » → idle, « Retour au panier » visible sur payment ; 0×409 sur tous les runs R2 |
| **D-005** animations réduites EFFECTIVES (`ab84dd6ac`) | **CONFIRMED** | Log computed : toggle LIVE sans F5 `varFast 140ms→0ms`, `ctaTransition 0.14s→1e-06s`, classe `html.ks-reduced-motion` ; **persiste au F5** (localStorage `foodking:kiosk-a11y-motion`) ; reset propre + persiste ; le triple trou R1 (watcher/mount/persistance) est fermé par helper+guard hors frozen |
| **D-007** téléphone pré-rempli inscription (`41708df10`) | **CONFIRMED** | d2D4-03 PNG : TÉLÉPHONE\*=0788123456, NOM/E-MAIL vides ; garde anti-écrasement re-greppée :497-505 |
| **D-009** libellés a11y sobres (`4a96f8009`) | **CONFIRMED** | fr.json regex jargon = 0 hit (mon grep indépendant) ; d2D3-03 PNG libellés sobres ; log `jargon hits=[]` |
| **C-ADV-02** promo persistée au reload (`16d869911`) | **CONFIRMED** | d2D5-01/02 : −3,80 € identique post-F5, **re-validation SERVEUR au mount** (POST promo/validate émis, montant jamais rejoué localement — code :675-682) ; purge sur reset/clearPromo vérifiée (`:774` avant RESET → pas de fuite au client suivant, probe B promo=null) |
| **ADV-F-P1-3** idle light (`dcf675617`) | **CONFIRMED** | Computed `linear-gradient(rgb(255,255,255), rgb(255,232,221) 55%, rgb(244,80,30))`, titre encre rgb(15,15,15), hasVideoClass=false ; d2D3-01 PNG dominante claire ; variante sombre gatée `.kiosk-idle--has-video` (12 hits re-greppés) |
| **Wire-up orchestrateur `loyalty_redeem_discount`** (kioskCart.js) | **CONFIRMED** (code-level) | `kioskCart.js:183` dans `buildKioskQuotePayload` + propagation mécanique à `buildKioskOrderPayload` (:196-200) + POST `frontend/order` (:799-806) + **présent dans le bundle servi** ; l'exercice financier bout-en-bout (remise facturée) relève des vagues C/E |

## 6. CONVERGENCE round 1 → round 2 (10 findings R1)

| R1 | Sév R1 | Statut R2 | Preuve |
|---|---|---|---|
| D-001 prefetch ineffectif | P1 | **FERMÉ** | Heal CONFIRMED (probe A + C) |
| D-002 « Network Error » EN panier | P1 | **FERMÉ** | Heal CONFIRMED (d2D1-04) |
| D-003 Plan B 409 dead-end | P1 | **FERMÉ** | Heal CONFIRMED (probe B, 0×409, re-validation impossible) |
| D-004 zéro indicateur offline catalogue | P2 | **SURVIVANT P2** | Non assigné au heal ; ma probe 2 : indicateur=null, toast vide, ajout silencieux. Atténué (1er signal checkout désormais FR) |
| D-005 « Animations réduites » placebo | P1 | **FERMÉ** | Heal CONFIRMED (computed live + F5) |
| D-006 vague 401 + toast session + perte events hardware | P2 | **SURVIVANT P2 (résiduel)** | Toast client SILENCÉ ✓ (d2D3-02 + 0 occurrence sur 36 DOM). RESTE : bruit 401 récurrent en ligne (agrégat 401 menu ×64 / kiosk-event ×52 / login ×47 — au-delà du gate « one-shot boot ») + télémétrie hardware legacy `frontend/kiosk-event` fire-and-forget `.catch(()=>{})` toujours perdue en fenêtre stale (KioskPaymentComponent.vue:1027 ; KioskAppComponent.vue:1010/1025 = **FROZEN**, fix complet exigerait gate ou queue côté intercepteur). Impact client : nul ; impact observabilité : réel |
| D-007 téléphone non reporté | P2 | **FERMÉ** | Heal CONFIRMED (d2D4-03) |
| D-008 multi-tab last-writer-wins | P2 | **SURVIVANT P2 (borné)** | Aucun code change ; scénario 6 R2 borne le risque réel : fusion de quantité visible en B, **commande A facture exactement ce que A affichait** (201 total=3.8), convergence au reset (B vide après commande A), zéro double commande. Perte silencieuse de l'incrément B documentée — impossible sur borne physique mono-écran |
| D-009 jargon a11y | P3 | **FERMÉ** | Heal CONFIRMED |
| D-010 observation (loyalty 404 / idle 3 min) | — | **RAS confirmé** | Scénario 8 R2 : overlay « Toujours là ? » (PNG d2D8-02 : countdown 24 s, JE SUIS LÀ focus-ring, ABANDONNER LA COMMANDE), T+195 retour idle, reset propre, borne réutilisable |

### NOUVEAUX (round 2)
| ID | Sév | Détail |
|---|---|---|
| **D-R2-A1** | **P2** (cotation adversariale = celle du GStack, vérifiée live ×2) | « RÉESSAYER » de /kiosk/error/network re-land en boucle sur l'écran d'erreur MÊME connexion rétablie (`KioskErrorNetworkComponent.vue:62-72` : `window.location.reload()` sur la route erreur, $emit('retry') non câblé par le parent frozen, aucun health-redirect au boot). Ma probe C : 2 clics RÉESSAYER online → 2 re-land `/kiosk/error/network`. PAS P1 : aucune perte (panier intact), échappatoire chip « Mon panier » **exercée avec succès** (→ /kiosk/cart, 1 item), reset idle en dernier recours. Titre « Connexion perdue » mensonger une fois online. Invisible au round 1 (l'écran ne rendait jamais) — conséquence du heal D-001, pas une régression. Fix suggéré : health-check au retry → `router.replace` vers cart si non vide, sinon idle |
| **D-R2-A3** | **P3 — ROUTÉ VAGUE C (ne pas double-compter ici)** | Promo `amount` (5,00 €) > total (3,80 €) → clamp −3,80 € correct, jamais négatif, mais commande Plan B à **0,00 €** restable commandable (CTA « Valider ma commande 0,00 € » actif). Commit 0 € non exercé (préservation uses_count). Compétence métier promo = vague C |

Note non cotée : le toast erreur du panier (d2D1-04) chevauche le bord bas du CTA « Valider ma commande » — transitoire 6 s, dismissible ✕, même classe de pattern toast déjà connue ; cosmétique.

## 7. Intégrité chiffre-par-chiffre (recalcul adversarial)
- Glace 3,80 € ×1 : cart = payment = cash-instruction = ORDER-POST (4517/4527/mon A0020 tous total=3.8) ✓
- Promo : 3,80 − min(5,00 ; 3,80) = 0,00 € — clamp correct, CTA cohérent « 0,00 € » ✓
- Multi-tab : 3,80 ×2 = 7,60 € (fusion qty B) ; commande A = 3,80 € = état mémoire affiché de A ✓
- Format FR `X,XX €` partout, aucun NaN/undefined sur les DOM échantillonnés ✓
- Aucun 4xx/5xx hors allowlist + pattern 401-replay connu ; **un seul ORDER-POST 201 par commande, zéro 409 sur tout le round 2** ✓

## 8. SYNTHÈSE
| Sév | Ouverts post-R2 | IDs |
|---|---|---|
| P0 | 0 | — |
| P1 | 0 | — |
| P2 | 4 | D-004 (survivant), D-006-résiduel (survivant, partie frozen), D-008 (survivant borné), D-R2-A1 (nouveau) |
| P3 | 1 | D-R2-A3 (routé vague C) |

**VERDICT VAGUE D : GREEN** (0 P0 + 0 P1 ouverts — critère loop-blocking du protocole satisfait). Les **9 heals du périmètre sont CONFIRMED**, chacun re-prouvé par au moins une voie indépendante de l'équipe GStack (probes live `_d2red-*`, re-greps source + bundle servi, recalculs). Le WAVE_REPORT R2 est honnête, complet (8 scénarios TOUS analysés — contrairement au R1), citations file:line toutes exactes ; ses 3 anomalies auto-déclarées sont correctement cotées (A1 vérifiée live et maintenue P2, A2 = résiduel D-006, A3 routée C). Tripwire frozen : 12 commits H2 × 13 chemins = 0 ligne. Les 4 P2 restants sont non-bloquants au sens du protocole ; D-006-résiduel et D-008 nécessiteraient soit un gate frozen (KioskAppComponent émetteurs hardware), soit une décision produit (indicateur offline global, sync cross-tab) — à arbitrer owner, pas healables en scope strict vague D.

## 9. Inventaire adversarial
- Scripts : `tests/e2e/_d2red-D-borne-robustesse-1.mjs` (probes A/B/C), `_d2red-D-borne-robustesse-2.mjs` (D-004).
- Captures : `d2red-A-error-network-offline.*`, `d2red-B-cash-instruction.*`, `d2red-B-after-cta.*`, `d2red-C-retry-online-still-error.*`, `d2red-C-escape-via-cart-chip.*`, `d2red-D004-offline-catalogue-no-signal.*` + logs `_d2red-D-probe1-log.txt` / `_d2red-D-probe2-log.txt`.
- PNG R2 GStack lus : d2D1-01/02/04, d2D2-02/03, d2D3-01/03, d2D4-03, d2D5-02, d2D6-04, d2D7-02/03, d2D8-02 (+ d2red ×3) — échantillonnage par état unique, doublons sha1 contrôlés.
- Aucun git write / artisan / npm ; DB lue en SELECT uniquement via les artefacts (aucune requête directe nécessaire) ; frozen observés seulement ; mutations DB = mes 2 commandes Plan B de probe (A0020 + 1 panier idle-reset) sur foodking_e2e jetable, autorisées.
