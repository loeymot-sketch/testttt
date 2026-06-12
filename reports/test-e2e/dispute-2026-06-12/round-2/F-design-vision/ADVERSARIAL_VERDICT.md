# ADVERSARIAL VERDICT — Vague F-design-vision — Round 2 post-heal (dispute 2026-06-12)

> Superviseur adversarial R2. Je ne corrige rien. Run indépendant — le red:F précédent est mort
> après la capture 1440×900 (`RED2-F-keypad-1440x900.png` 13:42, pas de 1366×768) ; tout ce qui
> suit est MON run (`tests/e2e/_d2red-F-design-vision-2.mjs`, log `/tmp/d2red-F-keypad-run.log`).
> Écrit incrémentalement. file:line re-greppés ce jour.

## ÉTAT D'AVANCEMENT (incrémental)
- [x] §1 P0 live re-vérifié (2 résolutions, séquence 14 touches dont «,»)
- [x] §2 Verdicts heals périmètre F
- [x] §3 Revue visuelle PNG R2
- [x] §4 Quartet technique (DOM/console/network)
- [x] §5 Audit DESIGN_GAP_ANALYSIS_V2.md
- [x] §6 Convergence R1→R2
- [x] §7 Verdict global

---

## §1 — P0 ADV-F-P0-1 : RE-VÉRIFIÉ LIVE PAR MOI (run indépendant, 2 résolutions) → **FERMÉ**

Compte `bm.t2admin@lecayenne.fr`, modal « Encaisser la commande borne » ouvert depuis le drawer
borne, bundle servi 12/06 13:07 (postérieur aux heals, vérifié `stat public/js/app.js`).

**1440×900** :
- Structure : `.cc-modal-body` scrollHeight **735 = clientHeight 735** (AUCUN scroll),
  `.cc-modal-footer` `position: static` (plus de sticky), CTA Confirmer entièrement dans le viewport.
- Hit-test elementFromPoint : **14/14 touches atteignables, `blocked: []`**
  (R1 : 7/8/9/00/0/«,»/C interceptées — la touche 9 tombait sur « ✓ Confirmer & Imprimer ticket »).
- Hauteurs touches : chiffres/00/«,» = **48px** (plancher tactile), ⌫/C = 104px.
- **Clics réels séquence C,1,2,3,4,5,6,7,8,9,0,00,«,»,C** (14 frappes — j'ai AJOUTÉ la touche «,»
  que la séquence GStack F2-05 ne tapait pas alors qu'elle était dans le blocked-list R1) :
  chaque frappe s'affiche (`1→12→…→123456789000→123456789000,→""`), modal jamais fermé.
- **0 POST `counter-collect/*/confirm`** sur l'ensemble des frappes (listener réseau dédié).

**1366×768** : identique — body 663/663 sans scroll, footer static, 14/14 `blocked: []`,
14 frappes intègres, **0 POST confirm**. Captures : `RED2b-F-keypad-1440x900.png`,
`RED2b-F-keypad-1366x768.png`, `RED2b-F-modal-full-1366x768.png`.

**Taper « 9 » ne peut plus encaisser.** Le fix structurel (footer sibling hors du scroller,
`PosCounterCollectModal.vue:50/:192/:704` re-greppé) tient géométriquement aux deux résolutions.
Concordance totale avec le run GStack F2-05/F2-05b ET avec le fragment du red mort (1440×900).

---

## §2 — VERDICTS HEALS (périmètre vague F)

| Heal | Commits | Verdict | Evidence (la mienne, pas celle du GStack) |
|---|---|---|---|
| **ADV-F-P0-1** pavé modal encaissement (H3) | `34d1e0769`+`bbb79630d` | ✅ **CONFIRMED** | §1 ci-dessus — run indépendant 2 résolutions, 14/14 + «,», 0 POST confirm, body sans scroll, footer static. Source re-greppée : plus aucun `position: sticky` dans le composant ; `PosV5Numpad` atome intact. |
| **ADV-F-P1-1** échappatoire paiement Plan B (H2, part du cluster D-003) | `43c5f2d76` | ✅ **CONFIRMED** | Source `KioskPaymentComponent.vue:89` `data-testid="kiosk-payment-counter-back"` re-greppé ; PNG `F2-03b` : « Retour au panier » rendu sous le CTA (j'ai relu le PNG, bouton 640×64 visible) ; DOM artefact F2-03b contient le testid. Cul-de-sac R1 (prouvé live `backBtnPresent:false`) fermé. |
| **ADV-F-P1-2** SKU techniques hors grille client (H1 seeder) | `bbeecd437` | ✅ **CONFIRMED** | MA requête DB : items 1/2/3 → `item_category_id=27` (`technique-interne-upsell`, `channels=["admin"]`, status 5, `is_featured=10`=off). PNG `F2-02` relu : grille ouvre sur SANDWICH CAYENNE 7,00 €, 0 tuile blanche, 0 « Upsell item » EN, sidebar sans catégorie interne. |
| **ADV-F-P1-3** idle borne light (H2) | `dcf675617` | ✅ **CONFIRMED** | Cascade complète re-tracée par moi : composant `:377` fallback light `#FFF→#FFF4EE`, MAIS le computed servi vient de `tokens-bold.css:259` `--kiosk-idle-bg: linear-gradient(#FFF, #FFE8DD 55%, #F4501E) !important` appliqué via `:277-278` `.kiosk-app:not(.kiosk-theme--dark) .kiosk-idle-fallback` — la probe WAVE_REPORT est EXACTE (je l'ai d'abord soupçonnée à tort en ne lisant que le composant : auto-dispute, la cascade tokens-bold gagne). Overlay `background:none`, variante sombre gatée `.kiosk-idle--has-video` (`:14`). PNG `F2-01` relu : fond blanc→pêche→orange marque, encre sombre, 0 dominante sombre. |
| **ADV-F-P2-1** « Encaisser » vert→brand + libellé hardcodé (H3 QW) | `9c93920c0` | ✅ **CONFIRMED** | Source : `.kiosk-cash-collect-btn` `background: var(--pos-v5-brand-red,#cf3a3a)` (`PosComponent.vue:5142`) = même var que `.pos-shortcuts__cta--cash` (`:4875-4877`) ; token `--pos-v5-brand-red:#F4501E` (`pos-v5-tokens.css:41` + `public/css/app.css` servi) → rgb(244,80,30) les deux. Libellé `$t('label.pos_shortcut_cash_cta')` aux 2 call-sites (`:372`/`:1289`), plus de `'✓ Encaisser'` hardcodé. |
| **ADV-F-P2-2** « Clôturer la caisse » outline (H3 QW) | `7fccbab3b` | ✅ **CONFIRMED** | PNG `F2-07` relu : « Clôturer la caisse » = outline rouge (fond transparent), « Voir les mouvements » bouton blanc bordé au-dessus — le destructif n'est plus le seul bouton plein du modal de routine. |
| **ADV-F-P2-7 (part séparateur)** « 2· N° » collé (H3 QW) | `09e0a09ac` | ✅ **CONFIRMED** (part) | PNG `F2-08` relu : « #1206264526 · N°A0013 » espacé. La hiérarchie inversée (ID interne en titre) reste = GAP-09 partiel, conforme au scope déclaré du QW. |
| **ADV-F-P2-8 (part show)** « Imprimer La Facture » casse (H3 QW) | `00cc81a16` | ✅ **CONFIRMED** (part) | PNG `F2-08` relu : « Imprimer la facture » en casse FR. Le Title Case systémique sidebar/breadcrumb/badges reste = GAP-10 ouvert, conforme au scope déclaré. |
| **Wire-up `loyalty_redeem_discount`** (orchestrateur) | `956933ec5` | ✅ **CONFIRMED** (code+bundle ; comportement bout-en-bout = vague E) | Source `kioskCart.js:183` `loyalty_redeem_discount: state.loyaltyDiscount || 0` dans `buildKioskQuotePayload` ; `buildKioskOrderPayload` (`:193+`) construit SUR le payload quote → propagation mécanique au POST order ; **bundle servi le contient** (`grep public/js/app.js` → `loyalty_redeem_discount:e.loyaltyDiscount||0`, 1 hit). Backend H1 `c0518cf50`/`00dcbffda` testé TDD (KioskLoyaltyBillingTest 5/5 dont payload frontend réel). Je n'ai pas rejoué un rachat fidélité live borne (flux vague E) — verdict code+bundle+TDD, pas sur-certifié. |

**Aucun heal de mon périmètre REFUTED ni PARTIAL.** Les « partiels » (P2-7/P2-8) sont partiels
PAR SCOPE DÉCLARÉ (quick-wins) et leurs restes sont correctement re-comptés GAP-09/GAP-10 — je les
confirme tels quels.

---

## §3 — REVUE VISUELLE PNG R2 (12 états + 2 crops GStack + 2 captures à moi, lus en multimodal)

- **F2-01 idle** : light confirmé (blanc→pêche→orange marque, encre sombre). Précision de cascade
  établie par moi : le gradient SERVI vient de `tokens-bold.css:259` (`--kiosk-idle-bg … !important`,
  pré-existant, commit `04a3a9b3d` du 21/05) — le R1 était sombre à cause de l'OVERLAY 0.85 + scrim,
  que le heal `dcf675617` gate désormais sous `.kiosk-idle--has-video`. Les deux lectures se
  réconcilient ; le heal était bien nécessaire et bien là. Résiduels : CTA rond icône-seule,
  micro-texte gris, emojis flottants (GAP-01 ambition + GAP-05).
- **F2-02 grille Sandwich** : ouvre sur SANDWICH CAYENNE 7,00 € / BIG CAYENNE 9,50 €, vraies photos,
  badge « Personnaliser » ; 0 tuile cassée, 0 « Upsell item ». Survivants : « + crud… » mi-mot,
  « NOS Sandwich Cayenne », rail catégories minuscule (GAP-14/15).
- **F2-03 panier** : 1,50+1,50=3,00=CTA ✓ ; crayon/poubelle petits (probe GStack 34/36px, cohérent
  au PNG), zone morte centrale ~45 %, placeholder ALL-CAPS, 🥡 (GAP-05/06/14, P3-2).
- **F2-03b paiement Plan B** : « Retour au panier » PRÉSENT sous le CTA (cul-de-sac fermé) ;
  bloc info orange identique au CTA + libellé CTA calé à gauche + cluster flottant ~55 % (P2-6 survit).
- **F2-03c cash-instruction** : copy unifiée « espèces, carte ou ticket restaurant » ✓, A0007 3,00 €
  = panier ✓, CTA « RETOUR À L'ACCUEIL » ancré bas + countdown ✓ ; 💶 (GAP-05).
- **F2-03d panier post-commande** : vide + CTA « Ajouter des articles » ✓ (reset D-003 OK) ; 🛒.
- **F2-04a Ouvrir la caisse** : afficheur « 50,00 € » + chips ET input brut « 50 » = GAP-08 intact.
- **F2-04b drawer borne** : Encaisser ORANGE partout (plus de vert), commandes du jour SANS badge,
  zombies badgés. Nombre « BORNE (58) » vs « 68 » de F2-06 = VÉRIFIÉ DB par moi : périmètres
  différents légitimes (drawer = kiosk-only ; page = tous PENDING_COUNTER) — pas un défaut.
- **F2-05/F2-05b modal encaissement** : pavé complet + footer aux 2 résolutions, A0002 3,00 € = total
  borne ✓, day-badge absent (jour J) ✓. Crop « avant » : CTA plein orange quand montant valide —
  je CONFIRME la résolution GStack de la suspicion « CTA pâle » (l'habillage suit l'état).
  « Ouvre le tiroir (simulation) » visible à 900px (GAP-12), masqué à 768px (compaction).
- **F2-06 encaissement** : jour J en tête sans badge, zombies « 10/06 » badgés ✓ ; breadcrumb
  « Tableau De Bord / Encaissement » Title Case (GAP-10).
- **F2-07 session active** : « Clôturer la caisse » outline rouge, « Voir les mouvements » au-dessus ✓.
- **F2-08 show** : « #1206264526 · N°A0013 » espacé ✓, « Imprimer la facture » casse FR ✓ ;
  3,80=3,80=3,80 ✓ ; survivants : hiérarchie ID-en-titre, « Référence interne: 2 »,
  « Instruction: TIRAMISU », « Passager », sidebar Title Case, 💸.
- **F2-09 cash-overview** : GRAND TOTAL 27,94 €/6tx — **intégrité recalculée par moi** :
  CAISSE 22,94 (3,80+3,80+8,50+3,42+3,42) + BORNE 5,00 = 27,94 ✓ ; répartition modes
  Espèces 4·14,44 + Carte 2·13,50 = 27,94 ✓. MAIS voir §5 GAP-13 : la lecture GStack
  « réconciliation cohérente » est DISPUTÉE (cohérence de façade — preuve DB infra).

**0 incohérence numérique sur 13 états** — je confirme le GStack sur l'intégrité.

## §4 — QUARTET TECHNIQUE → ADV-F-P1-4 (process) FERMÉ

- **DOM** : 15 fichiers, tailles variées 1,8KB→123KB, 14 MD5 uniques. Seule paire identique :
  F2-05 = F2-05b (103 061 octets) — **bénin** : même page/état aux 2 viewports, les media queries
  sont CSS-only, le DOM est identique par construction. Contenu vérifié : modal testid présent,
  back-btn présent, `grep Boisson Seule|Upsell item` F2-02 = 0 ✓.
- **Scan i18n protocole cat-1 exécuté par MOI sur les 15 DOM** : 0 clé brute visible.
- **Console** : borne = exactement 1× 401 boot (gate connu) par état ; caisse = uniquement
  warnings ws://6001 (allowlist SYNC-WS-01). PAGEERROR AxiosError 401 du take-2 (expiration token
  réelle) honnêtement documentée par le GStack → compté **ADV2-F-P3-NEW-1** (hygiène console,
  promesse non catchée au logout auto, voie non-frozen).
- **Network** : borne = le même 401 one-shot ; caisse = 0 réponse ≥400 sur 9 états.

## §5 — AUDIT DESIGN_GAP_ANALYSIS_V2.md (existe, COMPLET — le red:F mort n'a pas empêché le doc)

Le doc couvre 15/15 gaps (4 fermés / 3 partiels / 8 ouverts), mapping V1→V2 cohérent avec mes
constats PNG/DOM/DB ci-dessus. **Je le contresigne avec 3 corrections** :

1. **GAP-13 « MITIGÉ par les data … session/filtre regardent le même jour ici » + WAVE_REPORT
   « Réconciliation : … attendues 50,00 € — cohérent » → DISPUTÉ.** La cohérence est de FAÇADE.
   Preuve DB (la mienne) : sessions **19** (Admin, ouverte 10/06 19:53) et **20** (Caissier,
   10/06 22:46) sont des **zombies encore OPEN** ; les ventes espèces du jour A0012/A0013
   (orders 4525/4526, 13:24-13:25, 3,80 € chacune) ont leurs `cash_movements` (ids 222/223)
   attachés à la **session 20 du 10/06**, PAS à la session 21 (13:20) que le panneau affiche.
   D'où « espèces (session en cours) 0,00 € / attendues 50,00 € » alors que 2 ventes espèces
   venaient d'être encaissées pendant la fenêtre de la session affichée. **Le côté WRITE est
   touché, pas seulement le READ** : `expected_closing_amount`/variance de TOUTES les sessions
   sont corrompus tant que des zombies OPEN absorbent les mouvements. Même racine que le gate
   **E-ADV-7** (multi-sessions ouvertes + résolutions divergentes writer/reader) → NON re-compté
   comme finding F (gate connu, périmètre E), mais le dossier de gate doit être ENRICHI de cette
   preuve write-side — la décision owner ne peut pas se limiter au panneau d'affichage.
   Recommandation jointe : clôture/purge des sessions zombies + résolution de session UNIFIÉE
   writer/reader (session du caissier courant), arbitrage N-sessions = owner.
2. **GAP-01 précision de cause** : le doc attribue le light au heal composant ; en réalité le
   gradient servi vient du pré-existant `tokens-bold.css:259` et le heal a « seulement » retiré
   overlay/scrim sombres (gatés vidéo). Sans impact sur le verdict FERMÉ — impact sur la
   traçabilité (un futur refactor de tokens-bold peut ré-assombrir l'idle sans toucher au composant
   ni casser son sentinel `kioskIdleLightMode.spec.js` qui ne teste que le composant).
3. **GAP-05 « l'emoji a GAGNÉ une surface » (💳 drawer)** : exact et bien assumé par le doc — je
   confirme au PNG F2-04b. À prioriser tel que proposé (marqueur produit n°1).

Le « 0 nouveau gap design » du doc : **CONFIRMÉ** après ma propre passe — je n'ai trouvé aucun
nouveau gap de la couche design au-delà des observations process déjà listées.

## §6 — CONVERGENCE R1→R2 (26 findings R1 jugés un par un)

| R1 | Verdict R2 | Preuve |
|---|---|---|
| ADV-F-P0-1 keypad | **FERMÉ** | §1, mon run, 2 résolutions |
| ADV-F-P1-1 cul-de-sac paiement | **FERMÉ** | F2-03b + DOM + source :89 |
| ADV-F-P1-2 SKU techniques | **FERMÉ** | MA requête DB (cat 27 admin) + F2-02 |
| ADV-F-P1-3 idle sombre | **FERMÉ** | F2-01 + cascade re-tracée |
| ADV-F-P1-4 DOM quartet vide | **FERMÉ** (process) | §4 — 15 DOM exploitables, scan i18n exécuté |
| ADV-F-P1-5 couverture surestimée | **FERMÉ** (process) | R2 a capturé grille/overlay/modal 2 rés. ; écran confirmation reste inatteignable par config (constat R1 maintenu, pas un état testable) |
| ADV-F-P2-1 vert/orange + hardcodé | **FERMÉ** | heal confirmé §2 |
| ADV-F-P2-2 Clôturer primaire | **FERMÉ** | F2-07 |
| ADV-F-P2-3 double champ fond | **SURVIVANT** | F2-04a (=GAP-08) |
| ADV-F-P2-4 emoji-icônes | **SURVIVANT** (aggravé d'une surface : 💳 drawer) | F2-01/03/03c/03d/04b/05/08 |
| ADV-F-P2-5 portrait vide | **SURVIVANT** | F2-03 ~45 %, F2-03b ~60 % |
| ADV-F-P2-6 info déguisée + texte gauche | **SURVIVANT** | F2-03b |
| ADV-F-P2-7 hiérarchie numéros | **PARTIEL** (séparateur fermé, hiérarchie survit) | F2-08 |
| ADV-F-P2-8 Title Case FR | **SURVIVANT** (1 occurrence fermée / ~30) | F2-08 sidebar, F2-06 breadcrumb |
| ADV-F-P2-9 terminologie | **SURVIVANT** (copy caisse borne fermée ; « Passager »/ticket-facture restent) | F2-03c fermé / F2-08+F2-05 restent |
| ADV-F-P2-10 fuites internes | **SURVIVANT** | « Filiale #1 », « Référence interne: 2 », « Instruction: TIRAMISU », « (à venir) », « (simulation) » |
| ADV-F-P2-11 double période KPI | **SURVIVANT structurel + enrichi** | F2-09 sans sous-titre période ; preuve write-side §5.1 (→ gate E-ADV-7) |
| ADV-F-P2-12 loyalty 2 erreurs | **SURVIVANT non-rejugé** (état non re-capturé R2, aucun heal au lot) | — |
| ADV-F-P2-13 troncatures/grammaire | **SURVIVANT** | F2-02/F2-04 |
| ADV-F-P2-14 cibles <48px borne | **SURVIVANT** | probe GStack 34/36px (PNG cohérent) ; le pavé caisse respecte désormais 48px — le standard existe |
| ADV-F-P2-15 trous normatifs POLICY | **SURVIVANT** (DOC à écrire, reconnu par le V2) | — |
| ADV-F-P2-16 asymétrie upsell | **SURVIVANT** (frozen → gate owner) | non re-capturé (frozen) |
| ADV-F-P3-1 MONTANT aligné gauche (historique) | **SURVIVANT non-rejugé** (f12 non re-capturé ; F2-09 = à droite ✓) | — |
| ADV-F-P3-2 placeholder ALL-CAPS | **SURVIVANT** | F2-03 |
| ADV-F-P3-3 PU sous nom qté>1 | **SURVIVANT non-rejugé** (A0013 = qté 1) | — |
| ADV-F-P3-4 ws 6001 warnings | **SURVIVANT env** (allowlist) | console caisse R2 |
| ADV-F-P3-5 upsell non-contextuel | **SURVIVANT discovery** | — |

**NOUVEAU R2** : ADV2-F-P3-NEW-1 — PAGEERROR `AxiosError 401` non catchée à l'expiration de session
admin (logout auto OK par ailleurs) — vu take-2 GStack, voie non-frozen, hygiène console.

## §7 — VERDICT GLOBAL VAGUE F ROUND 2

**P0 : 0 · P1 : 0 · P2 : 14 survivants (0 nouveau) · P3 : 6 (5 survivants + 1 nouveau).**

- Le **P0 est FERMÉ**, prouvé par MON run indépendant aux 2 résolutions, 14 touches dont «,»,
  0 POST confirm. Les 4 P1 design R1 sont FERMÉS. Aucun heal REFUTED.
- Ce qui reste = exactement la couche « sweeps transverses » du DESIGN_GAP_ANALYSIS_V2
  (emoji/casse/terminologie/fuites/cibles/composition/périodes) — AUCUN n'est bloquant
  loop au sens protocole (P2/P3), TOUS sont nommés avec règle à écrire (P2-15).
- 1 dispute de fond contre le WAVE_REPORT : la lecture « réconciliation cohérente » de F2-09
  (façade — mouvements espèces du jour dans une session zombie du 10/06, preuve DB §5.1)
  → à verser au dossier de gate E-ADV-7, périmètre E.
- Process GStack R2 : quartet DOM réparé, incident throttle kiosk documenté, suspicion CTA
  auto-résolue par evidence — discipline en net progrès vs R1.

**Vague F : GREEN au sens protocole (0 P0, 0 P1).** Le solde est de la dette design priorisée,
pas du défaut bloquant. Statut : TERMINÉ — 2026-06-12, superviseur adversarial R2.
