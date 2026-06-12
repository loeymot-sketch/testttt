# MÉTA-CONVERGENCE — Clôture du Round 2 (dispute-2026-06-12)
— Superviseur en chef · 2026-06-12 · branche `release/v1-2026-06-10`, app :8768 (foodking_e2e jetable, bundles 12/06 13:07 post ~33 heals)
— Sources : 6 ADVERSARIAL_VERDICT R2 + 6 R1 + META_DISPUTE R1 + HEAL_H1/H2/H3 + wire-up `956933ec5`.

> **VERDICT DE BOUCLE : NEEDS_ROUND3 — P0+P1 ouverts = 1 P0 + 5 P1 (dédupliqués).**
> 10/11 P0 du Round 1 sont FERMÉS et re-prouvés live/DB par des adversaires indépendants ;
> ~30 heals jugés : 0 REFUTED sec, 2 PARTIAL (dont 1 régression collatérale). Le solde ouvert
> est concentré sur 3 clusters bien bornés (fidélité status=5 · borne rupture/upsell · caisse-gestion
> copy/overview/widget), tous avec fix scope-minimal identifié, 0 frozen.

## 0. Vérifications en propre du méta (verify-before-report, ce jour)

| Claim décisif | Re-vérif méta | Résultat |
|---|---|---|
| P0 survivant fidélité : gate `status=1` | `OrderQuoteService.php:270-273` `->where('status', 1)` + `FrontendOrderService.php:935-937` idem `lockForUpdate` ; `Status::ACTIVE=5` (`app/Enums/Status.php:7`) ; `LoyaltyController.php:100-106` commente EXPLICITEMENT le précédent 2026-06-06 (« the prior == 1 gate 404'd caisse-created customers ») avec helper `isCustomerActive()` | ✅ EXACT — le bug ré-introduit contre précédent documenté |
| B-R1-16 widget Z → transactions | `LastZReportWidget.vue:28` `:to="{ name: 'admin.transactions.list' }"` + commentaire W3.5 « Router target (transactions list) unchanged » | ✅ EXACT — survivant |
| C-R2-NEW-1 pool upsell mort | SQL read-only : `items status=5, non supprimés, is_upsell=5 OU is_featured=5` → **COUNT 0** (tous les is_featured=5 restants sont soft-deleted 2026-05-28 ; les 3 véhicules défeaturés is_featured=10) | ✅ EXACT — écran upsell borne auto-skip permanent |

Statuts agents : A/C/D/F R2 = TERMINÉ ; B R2 = TERMINÉ (GStack coupé état 6, couvert par l'adversaire) ;
E R2 = agent capture MORT sans WAVE_REPORT, verdict adversaire reconstruit depuis 151 artefacts + probes live
propres — la consigne « rapport incrémental » a une fois de plus sauvé le round.

---

## 1. TABLEAU DE CONVERGENCE — chaque P0/P1 du Round 1 → état Round 2

Dédup inter-vagues notée (le même fait compté par 2 vagues = 1 ligne).

### P0 Round 1 (8 uniques après dédup)

| # | Finding R1 (vagues) | Sév R1 | État R2 | Preuve R2 (adversaire indépendant) |
|---|---|---|---|---|
| 1 | A-RED-1 remise caisse → 401 « intent mismatch » → logout → panier perdu | P0 (A) | **FERMÉ** | Repro exacte live : remise 10 % → **201** order 4534, discount 0,78 facturée, NF525 2179, 0 logout, 0 faux toast ; anti-tamper intact (signature forgée → 409 propre, panier conservé) — A R2 §1 ; re-prouvé E P3b (items altérés → 409) |
| 2 | C-RED-01 ≡ E-ADV-1 promo borne affichée, jamais facturée | P0 (C,E) | **FERMÉ** | C : commande 4536 écran=API=DB 1,50 €, discount 5.0, `uses_count` 4→5 ; E : 4516 cohérent 5,00 € partout + quote P4 `discount:5` |
| 3 | C-RED-02 fidélité borne affichée, jamais facturée | P0 (C) | **SURVIVANT-TRANSFORMÉ → C-RED-02-R2 ≡ E2-P1-1** | Root cause RELOCALISÉE : le wire-up frontend (`kioskCart.js:183`) traverse quote+order (trace c11c, E11) ; c'est le lookup backend `status=1`-only (×2) qui droppe la remise pour TOUT client réel status=5. Repro C (4537 : UI 4,85/DB 6,50, points intacts) + E (4520 : promis 3,35/facturé 5,00) + probe quote isolée (`discount:5` au lieu de 6,65) |
| 4 | B-R1-15 ≡ E-ADV-5 refund espèces affiché « Carte bancaire » | P0 (B) / P1 (E) | **FERMÉ** | DB cash_back 762/763/764 = `counter_cash`/`cash` ; UI grand livre « Espèces −… » ; wallet admin 2,00 € INCHANGÉ post-refund (cascade E-ADV-3 morte aussi) |
| 5 | B-R1-04+04b ≡ E-ADV-4 file sans purge + N° A dupliqués → mauvaise commande encaissée | P0 (B) / P1 (E) | **FERMÉ-MITIGÉ** | Live : 48 badges « 10/06 » visibles, tri jour-récent-d'abord, chip modal « ⚠ Commande du 10/06/2026 » avant confirmation. **Résiduel assumé** : purge backend + collisions A = gate owner (disclose, non re-compté) |
| 6 | B-R1-19 403 silencieux payment-gateway + PAGEERROR /admin/transactions (BM) | P0 (B) | **FERMÉ** | Session BM neuve : gateway **200/75 bytes `options:[]`**, 0 secret (scan 6 patterns sur body réseau), 0 console error, 0 ≥400 |
| 7 | ADV-B-07 ≡ E-ADV-2 vue caisse unifiée + transactions aveugles aux ventes POS directes (CA −55 %) | P0 (B,E) | **FERMÉ** | Mutation neuve adversaire B : vente 4538 → 201 → **1 SEULE row POS-4538 cash +1,50** dans transactions ET carte CAISSE 42,26/10 tx exacte ; zéro double-compte counter-collect ; arithmétique overview exacte ×2 (59,54 puis 68,06) |
| 8 | ADV-F-P0-1 ≡ ADV-B-02 footer sticky modal encaissement intercepte 6 touches (taper « 9 » = encaisser) | P0 (F) / P1 (B) | **FERMÉ** | Run F indépendant 2 résolutions (1440×900 + 1366×768) : body sans scroll, footer static, **14/14 touches dont «,»**, `blocked:[]`, **0 POST confirm** sur 14 frappes ; hit-test B sur bundle réel concordant |

### P1 Round 1 (uniques après dédup, hors P0 ci-dessus)

| # | Finding R1 (vagues) | État R2 | Preuve |
|---|---|---|---|
| 9 | A-RED-2 sémantique 401 gardes quote + intercepteur logout-sur-tout-401 | **FERMÉ** | 422/409/410 backend (`OrderQuoteService.php:120/430/434/439/443/467`, plus aucun 401 d'intégrité) ; `pos-app.js:54-68` logout auth-only ; tamper live → 409 + toast + panier conservé. Résidu : toast EN tamper-only → **A-R2-1 P3** |
| 10 | A-RED-4 ≡ ADV-F-P1-4 DOM quartet vides (evidence) | **FERMÉ** (process) | R2 : `#app` outerHTML, DOMs uniques exploitables, scan i18n cat-1 enfin exécuté (0 clé brute) |
| 11 | A-RED-5 mandat A incomplet (carte/annulation) | **FERMÉ** | R2 couvre carte→receipt NF525 2169 (terminal #1), annulation mi-paiement, parquée→rappelée→encaissée, TR, split |
| 12 | A-RED-9 wizard caisse rejet silencieux (frozen) | **SURVIVANT-GATÉ** | `pos-wizard.js` frozen — liste « ne pas re-compter », gate owner |
| 13 | ADV-B-03 ≡ C-ADV-06 ≡ E-ADV-6 « espèces uniquement à la caisse » FAUX | **FERMÉ** | « Réglez à la caisse — espèces, carte ou ticket restaurant. » visuel ×5 (C, D, E, F) + fr.json re-greppé |
| 14 | B-R1-06 modal refund promet un miroir NF525 inexistant en pre-Z | **SURVIVANT P1** | b4-01 : warning toujours présent ; DB : aucun order miroir pour 4531/4334 — AUCUN heal ne l'a visé |
| 15 | B-R1-07 cash-overview brut vs net post-refund | **SURVIVANT P1** | Refunds du jour −13,40 € invisibles sur la page, aucune mention « hors remboursements » (b6-01 + red2-b-13) |
| 16 | B-R1-16 « Voir les clôtures Z » → page Transactions | **SURVIVANT P1** | `LastZReportWidget.vue:28` inchangé (re-greppé méta) |
| 17 | B-R1-17 ≡ E-ADV-3 client borne = « Admin Le Cayenne » + cascade wallet refund | **FERMÉ** | Live historique « Client borne » (4536/4537) ; cross-surface encaissement+show+tracker+historique unifiés ; wallet admin inchangé post-refund |
| 18 | ADV-B-08 `source` canal client-controlled → EOD faussé | **FERMÉ** | DB 6/6 + mutation neuve 4538 : `source=15` forcé serveur (`OrderService.php:736` + canonical `OrderQuoteService.php:504-506`) |
| 19 | C-ADV-01 « Too Many Attempts. » EN inline promo | **FERMÉ** | 429 forcé ×2 → inline FR « Trop de tentatives… » (capture d2r-d2) ; clefs fr+en présentes |
| 20 | C-RED-03 /kiosk/payment sans timeout Plan B (borne bloquée + fuite de commande) | **FERMÉ** | Overlay « Toujours là ? » à ~6 s, sortie → idle ~10 s, panier purgé — timer porté par composant NON-frozen (`KioskPaymentComponent:518+`), frozen intouché |
| 21 | COV-1 3 missions C sans artefact | **FERMÉ → résidu COV-2 P2** | R2 re-coupé (C30 sans section GStack) mais adversaire a tout couvert lui-même |
| 22 | D-001 webpackPrefetch ineffectif — écran réseau jamais atteignable offline | **FERMÉ** | Imports eager : probe offline indépendante → écran « Connexion perdue » REND, **0 ChunkLoadError / 0 pageerror** (R1 : ×4 + pageerror 2 surfaces) |
| 23 | D-002 « Network Error » EN au checkout panier | **FERMÉ** | Toast FR « Connexion perdue. Votre panier est conservé… », 0 EN |
| 24 | D-003 ≡ ADV-F-P1-1 panier jamais vidé Plan B → 409 dead-end EN + cul-de-sac paiement | **FERMÉ** | Probe B : POST 201 → cash-instruction → **items=0, idemKey=null, promo=null** ; « Retour au panier » visible ; CTA « RETOUR À L'ACCUEIL » → idle ; **0×409 sur tout le R2** |
| 25 | D-005 « Animations réduites » placebo total (watcher/mount/persistance) | **FERMÉ** | Toggle LIVE sans F5 : `varFast 140ms→0ms`, classe `html.ks-reduced-motion`, persiste au F5 (localStorage), reset propre — valeurs CSS computed, pas un placebo |
| 26 | ADV-F-P1-2 SKU techniques en tête de grille client | **FERMÉ** (avec régression collatérale) | DB : items 1/2/3 → cat 27 `technique-interne-upsell` channels=admin ; grille propre (F2-02). MAIS le seeder a défeaturé les 3 seuls items featured vivants → **C-R2-NEW-1 P1** |
| 27 | ADV-F-P1-3 idle borne sombre vs mandat light 100 % | **FERMÉ** | Computed `linear-gradient(#FFF→#FFE8DD→#F4501E)`, encre sombre, variante sombre gatée `.kiosk-idle--has-video` ; précision de cascade (tokens-bold.css:259 pré-existant) versée à la traçabilité |
| 28 | ADV-F-P1-5 couverture convergence surestimée (redirects MD5) | **FERMÉ** (process) | R2 a réellement capturé grille/overlay/modal aux 2 résolutions ; écran confirmation = inatteignable par config (constat maintenu, pas un état testable) |

### Nouveaux findings Round 2 (P0/P1 uniquement)

| ID | Sév | Détail | Origine |
|---|---|---|---|
| **C-RED-02-R2 ≡ E2-P1-1** | **P0** (méta — voir arbitrage infra) | Rachat fidélité borne silencieusement droppé pour TOUT client `status=5` (seed + créés caisse = population majoritaire de prod) : promis 4,85/3,35 €, facturé 6,50/5,00 € ; toast post-commit au motif **FAUX** (« points insuffisants » alors que 165 pts dispo) qui chevauche le CTA ; points jamais débités, ledger vide. Lookup `->where('status',1)` ×2 ré-introduit CONTRE le précédent documenté `LoyaltyController:100-106`. Les tests H1 passent car factories status=1. **Fix 1 ligne ×2** (`whereIn('status',[1,5])` ou helper `isCustomerActive`) + corriger le motif du toast. | Survivant transformé de C-RED-02 (heal PARTIAL) |
| **C-R2-NEW-1** | **P1** | Écran upsell borne MORT (auto-skip `no_suggestions` permanent) — pool upsell = 0 en DB (re-vérifié méta) : régression collatérale du seeder ADV-F-P1-2 qui a défeaturé les 3 seuls items featured vivants. Surface merchandising disparue EN SILENCE. **Fix DATA-only** (flagger de vrais add-ons `is_upsell=5`), zéro frozen. | Nouveau R2 (induit par heal) |
| **C-R2-NEW-2** | **P1** | Rupture produit en session → checkout rejeté en boucle « **Article 34** indisponible dans le catalogue. Commande rejetée. » — ID interne DB exposé au client, ligne jamais marquée/retirée du panier, 2e checkout = même boucle. Cul-de-sac sur le chemin d'achat. Source `AvailabilityService.php:247` rendu verbatim. Fix copy + marquage ligne, non-frozen. | Nouveau R2 (chemin jamais exercé avant) |

**Arbitrage de sévérité méta (C dit P0, E dit P1 sur la fidélité status=5)** : je retiens **P0**. La catégorie
protocole #11 (montant affiché panier+paiement ≠ montant exigé) est constituée sur le chemin n°1 de la borne,
pour la population majoritaire, avec un diagnostic FAUX exposé au client — le fait que l'écran cash-instruction
finisse par montrer le vrai montant (argument E pour P1) est une divulgation tardive, pas une intégrité. Le
dissent E est noté et n'affecte pas la boucle (P0+P1>0 dans les deux lectures).

### Décompte de convergence

| | R1 (dédup) | FERMÉS R2 | SURVIVANTS/TRANSFORMÉS | NOUVEAUX R2 |
|---|---|---|---|---|
| **P0** | 8 | **7** | 1 (fidélité status=5, transformé : root cause relocalisée frontend→backend) | 0 |
| **P1** | 20 | **15** | 4 (B-R1-06, B-R1-07, B-R1-16 jamais visés par un heal ; A-RED-9 gaté frozen non re-compté) | 2 (C-R2-NEW-1, C-R2-NEW-2) |

**OUVERT POST-R2 (dédupliqué, hors gates « ne pas re-compter ») : 1 P0 + 5 P1**
1. **P0** C-RED-02-R2/E2-P1-1 — fidélité borne status=5 (fix 1 ligne ×2 + toast)
2. **P1** C-R2-NEW-1 — upsell borne mort (DATA reseed)
3. **P1** C-R2-NEW-2 — rupture « Article 34 » cul-de-sac (copy + marquage ligne)
4. **P1** B-R1-06 — copy miroir NF525 mensongère pre-Z (copy conditionnelle `mode`)
5. **P1** B-R1-07 — cash-overview aveugle aux refunds (ligne refunds ou mention périmètre)
6. **P1** B-R1-16 — widget « Voir les clôtures Z » → Transactions (cible router)

P2 ouverts (disclose, non bloquants) : ~22 dédupliqués — B ×12 (B-R1-01/02/03/05/08/09/10/11/18,
ADV-B-01, ADV-B-09, ADV-B-R2-01 filtre mode inopérant), C ×3 (COV-2, C-ADV-07 prune silencieux, C-RED-04 gate DATA),
D ×4 (D-004, D-006-résiduel, D-008, D-R2-A1 boucle RÉESSAYER online), E ×1 (E2-P2-1 receipt bases HT mixtes),
F ×14 survivants design (= dette priorisée DESIGN_GAP_ANALYSIS_V2, recoupe partiellement B). P3 : ~13.

---

## 2. BILAN DES HEALS (H1/H2/H3 + wire-up) — consolidé inter-vagues

~30 fixes jugés par 6 adversaires indépendants, chacun re-prouvé par au moins une voie hors-GStack :

| Verdict | n | Détail |
|---|---|---|
| **CONFIRMED** | 27 | H1 : Fix1 A-RED-1/2 (A+E), Fix2 promo (C+E), Fix4 ledger POS (B mutation neuve + E), Fix5 refund mode réel (B+E), Fix7 source (A+B), Fix8 identité+wallet (B+E), Fix10 order_payments (E), B-R1-19 backend (B) ; H2 : D-001, D-002, D-003, C-RED-03, C-ADV-01, C-ADV-06, idle light, D-005, D-007, D-009, C-ADV-02, C-ADV-08 (code+tests) ; H3 : ADV-F-P0-1 (2 résolutions), B-R1-04 UI, B-R1-19 front, 4 quick-wins F (couleur/outline/séparateur/casse), E-ADV-8 toast FR, A-RED-6 HT, A-RED-7 virgule, A-RED-11 gating delivery-boy ; wire-up `loyalty_redeem_discount` (code+bundle+payload tracé — le drop est backend) |
| **PARTIAL** | 2 | H1 Fix3 fidélité (marche status=1 uniquement → P0 survivant) ; H1 Fix9 seeder SKU (grille propre MAIS upsell tué → C-R2-NEW-1) |
| **REFUTED** | 0 | — |
| **SKIPPED-GATE** | 1 | H3 A-RED-12 (PaymentComponent frozen — blueprint LOCK prêt, refus motivé d'un override CSS cavalier : exactement la classe de change qui avait créé ADV-F-P0-1) |

Leçons systémiques confirmées par ce round :
1. **2 heals sur ~30 ont causé du collatéral** (W2-G6 → P0 keypad au R1 ; seeder → upsell mort au R2) →
   tout heal doit re-tester son VOISINAGE fonctionnel, pas seulement son diff (déjà la leçon C-04 du méta R1).
2. **Le P0 survivant est un bug ré-introduit contre un précédent documenté dans le code même**
   (`LoyaltyController` commente le piège status=1 depuis le 06-06). Discipline : grep-précédent avant
   d'écrire un lookup users/status ; factories de test alignées sur la population RÉELLE (status=5).
3. La consigne **rapport incrémental** a encore payé : 3 agents coupés (B état 6, C état C30, E entier)
   — les verdicts ont été reconstruits depuis le disque sans perte de couverture.
4. Process evidence : quartet DOM réparé partout (A-RED-4/ADV-F-P1-4 fermés) ; récidive MD5 « -bas » en B
   (ADV-B-R2-03) root-causée = inner-scroll `main.db-main` → remède documenté (`_d2red-B-caisse-gestion-03.mjs`)
   pour tous les rounds futurs.

---

## 3. VERDICT DE BOUCLE ET PROPORTIONNALITÉ DU ROUND 3

**P0+P1 ouverts ≠ 0 → la boucle ne peut PAS être fermée.** La règle de convergence (2 cycles propres
consécutifs) n'est satisfaite par aucun cycle complet : ni R1 (11 P0) ni R2 (1 P0 + 5 P1) n'est « propre ».

**MAIS un Round 3 set-identique complet (6 vagues) serait disproportionné**, pour trois raisons :
1. **3 vagues sur 6 sont GREEN au sens strict du protocole** (A : 0 P0/0 P1 ; D : 0/0 ; F : 0/0), et leurs
   heals ont déjà été exercés **2 fois par des populations indépendantes** (capture GStack R2 + probes live
   adversariales R2 distinctes des scripts GStack — précisément le contre-poison du « même script, même seed »
   dénoncé par le méta R1 §C-02). Re-rejouer ces vagues à l'identique reproduirait l'erreur méthodologique
   inverse : confirmer le script, pas le système.
2. **Le solde ouvert est topologiquement concentré** : 1 fix backend 2-lignes + 1 toast (fidélité), 1 reseed
   DATA (upsell), 1 copy+marquage (rupture), 3 fixes front/copy bornés en B — tous 0 frozen, tous avec
   root cause re-greppée par 2 adversaires + le méta.
3. La confirmation par « double-vérif live des adversaires R2 » **suffit pour les findings FERMÉS** (chaque
   fermeture ci-dessus cite une preuve indépendante du healer ET du GStack), mais **ne suffit PAS pour les
   6 ouverts** ni pour les fixes du futur lot H4 — qui n'auront alors été exercés qu'une fois.

**Forme proportionnée du Round 3 :**
- **Lot H4 (6 fixes, 0 frozen)** : fidélité `whereIn status [1,5]` ×2 + motif toast ; upsell DATA reseed
  (choix d'items = nod owner) ; rupture message FR + nom produit + marquage ligne ; B-R1-06 copy
  conditionnelle au mode pre-Z/post-Z ; B-R1-07 ligne « Remboursements » ou mention périmètre ;
  B-R1-16 cible router (page Z à défaut → arbitrage avec B-R1-18).
- **Round 3 FOCALISÉ (1 vague fusionnée C/E/B, adversaire ≠ healer)** : (a) rachat fidélité live client
  status=5 RÉEL avec trace DB obligatoire (orders.discount/total + loyalty_transactions + points) ; (b) écran
  upsell rendu + commande upsellée facturée ; (c) flux rupture en session ; (d) les 3 fixes B en live ;
  (e) smoke non-régression : 1 vente caisse remisée → receipt, 1 promo borne → DB, 1 refund → ledger.
- **Vagues A/D/F : sentinelle légère uniquement** (re-run des probes adversariales existantes `_d2red-*`,
  pas de nouvelle campagne).
- Critère de sortie : Round 3 propre (0 P0/0 P1 nouveaux ou survivants sur le périmètre focalisé) →
  CONVERGED, en comptant R2-GREEN (A/D/F) + R3-propre comme les « 2 cycles propres » exigés — chaque heal
  ayant alors été exercé ≥2× par ≥2 populations indépendantes avec trace DB.

---

## 4. ANGLES MORTS RESTANTS (sur les 15 du méta R1) — matière planification, pas loop-blocking

| BS | État post-R2 | Reste à couvrir |
|---|---|---|
| BS-1 ticket/receipt | LARGEMENT COUVERT (HT markers healés, facture NF525 champs vérifiés live) | Rendu papier 80 mm réel, reprint, ticket cuisine, ticket remboursement ; E2-P2-1 (bases HT mixtes sur commande remisée) à cadrer |
| BS-2 vente caisse e2e | **COUVERT** (carte, TR, split, annulation, parquée→rappelée, 6 commandes tracées DB) | « MFS »/« Autre » = gate frozen |
| BS-3 refunds | PARTIEL (mode réel healé, wallet cascade morte) | Refund post-Z (miroir réel — lié B-R1-06), refund partiel, refund TR/carte, P0 connu changePaymentStatus (W1-W3) |
| BS-4 clôture + Z | PARTIEL (clôture exercée, écart signé form) | UI admin Z inexistante (B-R1-18), PDF Z rendu, X-report clés numériques, B-R1-16 |
| BS-5 KDS boundary | NON COUVERT | Bump/recall, OSS, clic Démarrer non-payée ; **arbitrage politique KDS inter-branches (E-ADV-11) AVANT tout merge** |
| BS-6 offline/chaos | LARGEMENT COUVERT (D probes offline systématiques) | Coupure MID-POST (timeout en vol), soketi down→up, mode dégradé caisse ; D-R2-A1 (boucle RÉESSAYER online, P2) |
| BS-7 cycle de vie panier | **COUVERT** (reset, idempotence, persistance promo re-validée serveur, multi-tab borné) | Restauration session Electron |
| BS-8 file borne purge | MITIGÉ UI (badges/tri/chip) | Purge backend + garde anti-collision N° A = gate owner |
| BS-9 multi-session/multi-caissier | NON COUVERT — AGGRAVÉ (preuve write-side F §5.1 : mouvements du jour absorbés par session zombie → `expected_closing` corrompu) | 2 caissiers simultanés, race double-clic « Encaisser » même commande, single-session enforcement |
| BS-10 viewports caisse | PARTIEL (counter-collect OK 1440+1366) | PaymentComponent frozen 1366 (gate A-RED-12, blueprint prêt), 1280×800, zoom 125 % |
| BS-11 stress/concurrence | NON COUVERT | Rafale borne pendant encaissements, throttle UX, file 200+ |
| BS-12 RGPD borne | NON COUVERT | Mentions légales inscription fidélité, rétention, affichage minimal du nom |
| BS-13 impression réelle | NON COUVERT (bypass harnais) | Gate PRINT-1 + B-R1-05 deux voies tiroir |
| BS-14 identité cross-surface | LARGEMENT COUVERT (« Client borne » unifié 4 surfaces, wallet) | « Passager »/« Client passage » (ADV-B-R2-02 P3), exports XLSX |
| BS-15 a11y opérante | PARTIEL (reduced-motion EFFECTIF + persisté) | Watcher audioDescription, parcours clavier caisse complet, lecteur d'écran |

---

## 5. GATES OWNER — LISTE CONSOLIDÉE EXHAUSTIVE (à date du R2)

### A. Frozen-zone (LOCK + contreseing requis)
1. **A-RED-9** — wizard caisse : rejet silencieux « Ajouter au panier » si section obligatoire vide (`pos-wizard.js`).
2. **A-RED-12** — modal paiement POS : CTA sous le pli à 1366×768 (`PaymentComponent.vue`) — **blueprint H3 prêt** (pattern sibling validé hit-test 2 résolutions).
3. **A-RED-10** — options « MFS »/« Autre » dans les tranches multi-paiement (`PosV5TrancheRow.vue` render ; le LIBELLÉ fr.json est fixable sans gate ; question NF525 traçabilité « Autre »).
4. **ADV-F-P2-16** — asymétrie accepter/refuser upsell (`KioskUpsellComponent.vue`, REF #16 littéral).
5. **D-006 résiduel** — émetteurs hardware legacy fire-and-forget `frontend/kiosk-event` (`KioskAppComponent.vue:1010/1025` frozen) → events perdus en fenêtre stale-token (queue ou gate).
6. Cosmétiques frozen connus : spam log wizard · aria-pressed upsell · Title Case PaymentComponent (îlot) · prix-étapes wizard (policy NF525 SSOT).

### B. DATA (owner data-ops)
7. « **VAT (10%)** » libellé EN sur receipts.
8. **Allergènes** : 44/45 items sans données + badge grille invisible avant ajout (C-RED-04, INCO UE 1169/2011).
9. **Pool upsell** : re-flagger de vrais add-ons `is_upsell=5` (C-R2-NEW-1 — healable R3, choix d'items = owner).
10. Seed SUP-LOY-1 sans articles.

### C. Policy / produit (arbitrage owner)
11. **Sessions tiroir multi/zombies** (E-ADV-7 + ADV-B-09, **dossier ENRICHI R2 par la preuve write-side F §5.1** : les mouvements espèces du jour s'attachent à une session OPEN du 10/06 → `expected_closing_amount`/variance de TOUTES les sessions corrompus tant que des zombies absorbent les mouvements) → clôture/purge des zombies + résolution de session UNIFIÉE writer/reader + arbitrage N-sessions-par-branche.
12. **Purge/expiration PENDING_COUNTER** + garde anti-collision N° A inter-jours (résiduel B-R1-04, mitigé UI).
13. **Politique KDS** « cuisine avant encaissement » (codée ici) vs « release-guard » (branche `heal/ultra-audit-w4`) — politiques OPPOSÉES, ping-pong garanti au merge (E-ADV-11, anti-drift §12).
14. B-R1-01 — aucune UI apport/retrait espèces (choix V1 à confirmer).
15. B-R1-09 — imputation du refund au tiroir du caissier-cliqueur (policy).
16. B-R1-18 — aucune UI admin de consultation/téléchargement des Z (produit ; lié au fix B-R1-16).
17. D-008 — multi-tab last-writer-wins / single-session borne (décision produit, risque faible borne physique).
18. D-004 — indicateur offline global catalogue (décision produit).
19. ADV-B-01 — « Ticket Moyen » sans qualificatif de base (disclose « par commande payée »).
20. Design backlog priorisé (DESIGN_GAP_ANALYSIS_V2) : contraste orange AA petits textes · emoji-icônes (1 surface gagnée 💳) · Title Case systémique · fuites internes (« Filiale #1 », « (simulation) », « (à venir) ») · composition portrait · cibles <48 px borne · ADV-F-P2-15 (4 règles normatives POLICY à ÉCRIRE — additif sans gate, prérequis pour héaler ces familles).

### D. Infra / env (hors produit)
21. **PRINT-1** — E2E impression réelle + drawer-kick (tout le harnais en bypass).
22. SYNC-WS-01 — ws:6001 down sur le harnais (warnings console allowlistés) — re-tester env nominal.
23. Drift config serveur :8768 vs .env (A-RED-3, artefact process serveur — toute conclusion « rate-limit OK » d'une autre session reste suspecte tant que le serve n'est pas relancé).

---

## 6. DÉCOMPTES FINAUX R2

| Sév | Ouverts post-R2 (dédup, hors gates « ne pas re-compter ») |
|---|---|
| **P0** | **1** — fidélité borne status=5 (C-RED-02-R2 ≡ E2-P1-1) |
| **P1** | **5** — C-R2-NEW-1 · C-R2-NEW-2 · B-R1-06 · B-R1-07 · B-R1-16 |
| P2 | ~22 (disclose/backlog, dont dette design F) |
| P3 | ~13 |

Vagues : A **GREEN** · B **JAUNE** (3 P1 survivants hors périmètre heals) · C **RED** (1 P0 + 2 P1) ·
D **GREEN** · E **YELLOW** (1 P1 = le P0 C vu côté cross-surface) · F **GREEN**.

**VERDICT : NEEDS_ROUND3** — lot H4 (6 fixes, 0 frozen, root causes re-greppées ×3) puis Round 3 FOCALISÉ
C/E/B + sentinelles A/D/F, avec trace DB obligatoire par commande. Un R3 propre sur ce périmètre = CONVERGED.

— Fin META_CONVERGENCE — superviseur en chef, 2026-06-12.
