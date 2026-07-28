# GOAL — WEB COMMANDE CLIENT COMPLET (retrait only, compte email, estimation temps, wizard parité borne)
2026-07-28 · ultra-architect-planify · exécution en boucle test-e2e jusqu'à validation totale

---

## §0 Preamble

### §0.1 Working-tree decision
- **Web repo** (`/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne`, origin `loeymot-sketch/Site-lecayenne`, **push = auto-deploy Vercel**) : propre (1 dossier screenshots untracked, hors scope). HEAD `1b8a067` = déjà poussé.
- **Backend** (`testttt`, branche `pos/category-first-caisse-2026-06-23`) : 3 composants Vue modifiés PRÉ-EXISTANTS non commités (`EncaissementComponent.vue`, `HistoriqueListComponent.vue`, `PosOrdersTrackerComponent.vue`) + reports. **Décision : NE PAS les inclure dans les commits de ce goal** — `git add` explicite fichier par fichier uniquement (CLAUDE.md §3quater).

### §0.2 Scope owner (7 demandes)
1. Livraison → renvoi Uber Eats (retrait uniquement, notre livraison = futur).
2. Parcours commande web validé bout-en-bout (paiement déjà OK).
3. Compte client : création + stockage + **vérification EMAIL** (pas de SMS — coût).
4. Estimation temps de retrait synchro caisse/KDS : base ~15 min, +5-10 min par tranche de 3 commandes devant, **plafond 30-35 min** ; commande programmée possible avant fermeture.
5. Wizard : 1ʳᵉ viande INCLUSE gratuite (actuellement facturée dès la 1ʳᵉ) ; sauce frites multi (1ʳᵉ gratuite puis +0,50 comme sandwich) ; supprimer mentions allergènes/gluten anxiogènes ; recap avec PHOTO produit (plus de « Étape 1/2/3 »).
6. Choix du créneau de retrait (dès que prêt / +30 / +40 min / heure programmée), pas un choix unique.
7. Fidélité liée au numéro de téléphone (structure pérenne web/app/borne).

### §0.3 Convergence criteria
DONE = 2 cycles e2e consécutifs avec P0+P1=0 ET findings identiques (règle test-e2e), money-path au centime (wizard=panier=checkout=scellé backend), PHPUnit+vitest suites vertes, frozen diff 0, chaîne NF525 OK.

### §0.4 Pipeline & skills
Par tâche → `ultra-audit-profond` (pipeline non re-décrit ici). Boucles visuelles → `test-e2e`. Frozen override → `lock-plan` (aucun prévu : ce goal ne touche PAS pos-wizard.js ni KioskWizardComponent).

### §0.5 Corrections advisor (Plan agent, 2026-07-28 — INTÉGRÉES)
1. **A1** : commentaire `WEB-EXTRAMEAT-N0` (heal 2026-07-16) affirme que la borne facture dès la 1ʳᵉ viande sur viande_count=0 — VÉRIFIER le comportement borne réel AVANT de coder ; le fix vrai = parité data backend (attributs item viande_count) + menu.js, PAS logique wizard seule (sinon 422 expected_total ou viande invisible cuisine).
2. **A2** : la sauce frites transite en INSTRUCTION texte (api.js:506-510), pas d'extra → pas de 422 par le multi ; le vrai blocker = ItemExtra « Sauce supplémentaire » attaché à la cascade frites pour pricing serveur. Contrainte : regex ticket `Sauce frites : [^\n]+` doit rester le DERNIER menuNotePart (`tests/Feature/Hardware/MultiSauceTicketNamesTest.php`, `KitchenTicketTacosSauceTest.php` verts).
3. **C** : email-OTP DOIT poser la clé cache `phone_verified:<phone>` exacte (SignupController:72 pull) ; nouvelle colonne nullable sur `otps` = NOUVELLE migration (jamais éditer une migration appliquée). Tests à garder verts : `tests/Feature/Sentinels/PreflightPhoneVerificationGateTest.php`, `Auth/GuestOtpVerifyHardeningTest.php`, `Auth/DevOtpExposureTest.php`, `Security/OtpBruteForceLockoutTest.php`, `Security/SignupGuestHijackGuardTest.php`. + throttle strict endpoint email-otp (abuse vector public).
4. **D** : guards business scheduled_at dans `OrderRequest::withValidator` (:216+ : lead cuisine, fenêtre service, horizon 7j) — les slots +30/+40 doivent les satisfaire ; `is_advance_order` toujours requis (0/1) ; wait-estimate EXCLUT les programmées non-released (sinon estimation gonflée).
5. **Parallélisme corrigé** : A2 et D touchent api.js ; C et E touchent account-v2.jsx → ordre strict A→B→D puis C→E puis F.
6. **data/menu.js est GÉNÉRÉ** (tools/ generator, `--check` 0 dérive) → modifier le GÉNÉRATEUR/la source, jamais la sortie à la main.
7. **Allergènes** : garder un lien discret accessible pré-achat sur fiche produit (INCO), retrait = copy anxiogène du flux seulement.
8. **B** : CTA Uber Eats derrière meta, placeholder masqué tant que G1 non fourni → NON : owner veut le renvoi visible ; placeholder = lien recherche Uber Eats du restaurant, remplaçable par meta.

---

## §1 Map principal — systèmes touchés (anchors VÉRIFIÉS 2026-07-28)

| Système | Maturité | Anchors vérifiés |
|---|---|---|
| S1 Web wizard | mûr, 4 défauts owner | `wizard-v2.jsx` (48,7 KB), `data/menu.js`, `screens-v3.jsx` |
| S2 Web checkout/funnel | mûr, slot unique | `funnel.jsx:168-175,269-281,324-327`, `api.js:576-606` |
| S3 Compte/auth backend | OTP SMS non câblé | `SignupController.php:55-116`, `OtpManagerService.php:44-123`, `routes/api.php:196-215` |
| S4 Estimation temps | **N'EXISTE PAS** | building blocks : `KitchenReleaseRule.php:106-233`, `OrderService.php:405` (prep_time 15), `TimeSlot.php:10` |
| S5 Fidélité | mûr, déjà phone-keyed | `Frontend/LoyaltyController.php:76-203`, `AwardLoyaltyPointsOnDelivery.php:84-102`, `routes/api.php:1521-1551` |
| S6 Ordre web | mûr | `routes/api.php:1425` → `Frontend/OrderController.php:47-54` → `FrontendOrderService.php:132` (myOrderStore, source_surface=web) |

## §2 Map separated
- Web standalone = SEUL frontend touché. Mobile RN : NON touché (mandat owner no-wireup). Borne/caisse : lecture seule (parité), AUCUNE modification frozen.

---

## §3 Système S1 — Web Wizard (Wave A)

### Contract
Wizard produit web = même logique que la borne (mandat owner) : inclus gratuits, suppléments payants au-delà, prix affiché == prix scellé backend.

### Frozen zones
Aucune (repo web non-frozen). ⚠️ Ne PAS toucher `KioskWizardComponent.vue` ni `pos-wizard.js` pour la parité — lecture seule.

### Sub 1.1 — Viande incluse (bug facturation 1ʳᵉ viande)
**Anchors** : `wizard-v2.jsx:117,121-127` (viande_count>0 → n incluses OK), `wizard-v2.jsx:131-140` (**viande_count=0 + has_extra_meat → extraFrom:0 → TOUTE sélection payante @2,50 dès la 1ʳᵉ — LE bug owner**), `wizard-v2.jsx:343-372` (computeWizardTotal), `data/menu.js` (SSOT mirror).
**Tasks** :
- T-A.1.1 Cartographier par item la règle borne (composer profile backend `ItemWizardProfile` / DB items) : combien de viandes incluses pour chaque produit à viande_count=0 (Cayenne, Suprême, 6 burgers…). ⛔ ZÉRO invention produit — grep DB/menu.php.
- T-A.1.2 Corriger `data/menu.js` viande_count (ou logique extraFrom si le choix est « 1ʳᵉ incluse » uniforme) pour parité borne exacte.
- T-A.1.3 Vérifier badge « Incluse » (`wizard-v2.jsx:608-620`) + total wizard == quote backend (expected_total witness `api.js:576-590`).
**Acceptance** : e2e réel : produit à viande → 1ʳᵉ viande = +0,00 affiché ET scellé ; 2ᵉ/3ᵉ au tarif supplément ; `tests-e2e/order-live.REAL-ORDER.js` étendu (test TO BE CREATED at `tests-e2e/wizard-viande-incluse.spec.js`) + parité vs borne screenshot.

### Sub 1.2 — Sauce frites multi
**Anchors** : sandwich multi OK `wizard-v2.jsx:149-157` (extraFrom:1, +0,50) ; frites LOCK single `wizard-v2.jsx:324-332` (min:1,max:1,price:0, commentaire « multi-select fuiterait un 422 backend ») ; backend ItemExtra « Sauce supplémentaire » (memory fix 2026-07-16 `menu:ensure-sauce-supplement-extras`).
**Tasks** :
- T-A.2.1 PROUVER backend d'abord : quote + order avec 2 sauces sur la cascade frites → scellé 422 ou OK ? (curl PricingService, jamais supposer).
- T-A.2.2 Si extra manquant côté frites → étendre le mapping web (extra « Sauce supplémentaire » N×0,50 + instruction noms, miroir sandwich `api.js` pattern « Sauces en plus : X »).
- T-A.2.3 UI : frites sauce → multi, `extraFrom:1, extraPrice:0,50, max:4` (= sandwich).
**Acceptance** : commande réelle scellée 2 sauces frites = base + 0,50, noms sur ticket cuisine (parité `KitchenTicketSymbolicFormatter`) ; (test TO BE CREATED at `tests-e2e/sauce-frites-multi.spec.js`) + backend `tests/Feature/Frontend/` quote test si mapping étendu (TO BE CREATED at `tests/Feature/Frontend/FritesSauceMultiQuoteTest.php`).

### Sub 1.3 — Allergènes/gluten : retrait copy anxiogène
**Anchors** : `screens-v3.jsx:113-114,152-154` (⚠ Allergènes item-detail), `wizard-v2.jsx:641-648,741-744,782-784` (recap/preview/card), placeholders `funnel.jsx:357` + `flows.jsx:125` (« Allergie au gluten… »), footer `components.jsx:215` (legal/allergens.html — À GARDER, obligation légale).
**Tasks** :
- T-A.3.1 Supprimer les blocs « Allergènes détectés / Contient … » du flux wizard/preview/cards.
- T-A.3.2 Neutraliser les placeholders (« Une précision ? Sauce à part, bien cuit… » sans allergie/gluten).
- T-A.3.3 Garder le lien légal footer + page allergens.html (conformité INCO) — le retrait ne s'applique qu'au flux de commande.
**Acceptance** : grep `gluten|allerg` dans jsx = uniquement footer/legal ; captures wizard sans mention ; (test TO BE CREATED at `tests-e2e/wizard-copy-clean.spec.js`).

### Sub 1.4 — Recap avec photo produit
**Anchors** : recap `wizard-v2.jsx:282,629-712` (« Étape {i+1} · {title} » à :686) ; image dispo `wizard-v2.jsx:719-722` (`item.image`), map images `data/menu.js:44-74`.
**Tasks** :
- T-A.4.1 Recap redesign : photo produit en tête + nom, puis « Ta personnalisation » (inclus) / « Suppléments (+X €) » (payants) / total / CTA « Ajouter au panier ». Plus de préfixe « Étape N · ».
- T-A.4.2 Repli emoji si image absente (pattern existant :719-722).
- T-A.4.3 Visual gate mobile (100dvh, pas de débordement — leçon `.lc-modal--full`).
**Acceptance** : captures desktop+mobile analysées (Read) ; recap montre photo + personnalisation + suppléments payants + total ; (test TO BE CREATED at `tests-e2e/recap-photo.spec.js`).

---

## §4 Système S2 — Livraison → Uber Eats (Wave B)

**Anchors** : gate `api.js:27-31` (deliveryEnabled meta, OFF) ; tuile désactivée « Ça arrive bientôt 🚀 » `funnel.jsx:269-281`.
**Tasks** :
- T-B.1 Remplacer la tuile par un CTA « Livraison → commande sur Uber Eats » (lien externe `<meta name="uber-eats-url">`, target _blank) ; copie honnête « En attendant notre propre livraison ».
- T-B.2 Garder le gate `feature-delivery` intact (futur système propre = flip meta, zéro code).
- T-B.3 Vérifier cohérence copie site (les mentions « pas de livraison » ×4 dans la copie — aligner).
**Acceptance** : capture tuile Uber Eats ; clic → URL owner ; `feature-delivery=1` re-affiche notre livraison (test TO BE CREATED at `tests-e2e/delivery-ubereats.spec.js`). **Gate G1 : URL Uber Eats owner** (placeholder = page recherche Uber Eats Hénin-Beaumont tant que non fournie).

---

## §5 Système S3 — Compte client + vérification EMAIL (Wave C)

### Contract
Signup web SANS SMS (coût) : nom + email + téléphone → code par EMAIL → compte vérifié + token. Téléphone reste la CLÉ fidélité. Paiement inchangé.

### Racine du « je ne peux pas créer le compte »
`OtpManagerService.php:44-58` dispatch `SendSmsCode` (event) — **aucun provider SMS** → le client ne reçoit jamais le code (dev echo only, `account-v2.jsx:26`). Le flux est structurellement mort en prod.

**Anchors** : `routes/api.php:196-215` (signup/otp|verify|register + guest-signup), `SignupController.php:55-116` (register, `email_verified_at=now()` auto :110), `Otp.php:10` (table otps : phone, code, token), `OtpManagerService.php:97-123` (verify + `Cache phone_verified:`), `account-v2.jsx:21-118`, `api.js:200-205`, mail infra `config/mail.php:16` + `.env.example:219-226` (smtp/mailpit placeholder).
**Tasks** :
- T-C.1 Backend : canal EMAIL-OTP — endpoint `POST /auth/signup/email-otp` (email+phone) : réutilise table `otps` (colonne phone = clé, code envoyé par Mailable `SignupOtpMail` TO BE CREATED `app/Mail/SignupOtpMail.php`) ; throttle identique (5/min) ; verify réutilise `OtpManagerService::verify` + cache `phone_verified:`.
- T-C.2 Backend : register inchangé (User email+phone) ; `email_verified_at` posé À LA vérification du code email (plus auto). Ne PAS casser le flux borne/guest-signup existant (SMS event conservé pour futur).
- T-C.3 Frontend `account-v2.jsx` : formulaire nom/email/téléphone → « code envoyé par email » → saisie code → succès ; erreurs lisibles (email pris, code faux, expiré) ; suppression de la mention SMS.
- T-C.4 Staging/dev : mail driver `log`/mailpit → doc « lire le code » (table otps col `token`, piège connu : `code`=indicatif +33) ; prod = **Gate G2 SMTP owner**.
- T-C.5 Diagnostic préalable e2e : reproduire l'échec actuel de création (preuve avant/après).
**Acceptance** : e2e : création compte complète avec code lu (mail log/DB) → token → commande passée connecté ; PHPUnit (TO BE CREATED at `tests/Feature/Auth/EmailOtpSignupTest.php` : envoi, verify, expiry, throttle, email_verified_at) ; `tests/Feature/AuthComprehensiveTest.php` toujours vert.

---

## §6 Système S4 — Estimation temps retrait + créneaux (Wave D)

### Contract
Le web affiche une attente estimée depuis la file RÉELLE caisse/KDS. Formule owner : base 15 min ; +5 min par tranche de 3 commandes actives devant ; rendu en fourchette (ex. « 20-25 min ») ; **plafond 30-35 min** ; créneaux : dès que prêt / +30 / +40 / heure programmée ≤ fermeture.

**Anchors** : file cuisine `KitchenReleaseRule.php:106-233` (releasedScope, PENDING_COUNTER, scheduled horizon), prep time `OrderService.php:405` (`order_setup_food_preparation_time`=15), fermeture `TimeSlot.php:10` (opening/closing par jour), scheduled intake `OrderRequest.php:179,349-396` + slot duration `AppLibrary.php:442` ; front slot unique `funnel.jsx:168-175,324-327` (**historique : slots programmés RETIRÉS par owner — réinstauration explicitement demandée 2026-07-28**) ; placeOrder sans scheduled_at `api.js:592-606`.
**Tasks** :
- T-D.1 Backend : `GET /frontend/order/wait-estimate` (public, throttled, branch 1) → `{queue_count, wait_low, wait_high, closing_time, slots[]}` ; service `WaitEstimateService` (TO BE CREATED `app/Services/WaitEstimateService.php`) : count commandes actives cuisine (released, non terminées) ; `low = min(15 + 5*ceil(n/3), 30)`, `high = low + 5` (cap 35) — **ceil, pas floor** : les exemples owner font foi (3→20-25, 7→30-35), jamais de sous-promesse ; constantes en Settings (surchageables owner).
- T-D.2 Front funnel : slots dynamiques = « Dès que prêt (~X-Y min) » + « Dans 30 min » + « Dans 40 min » + « Choisir une heure » (picker pas de 15 min, borné [now+wait_high, closing_time−15]).
- T-D.3 Front → backend : envoyer `scheduled_at` + `is_advance_order:1` quand créneau ≠ ASAP (`api.js` placeOrder body) ; vérifier witness `expected_total` inchangé (l'heure ne change pas le prix).
- T-D.4 Chaîne KDS : commande programmée web → bannière/release T-20 déjà en place — e2e de non-régression.
- T-D.5 Affichage tracking (`orders.jsx` / ticket `funnel.jsx:802`) : afficher l'estimation/l'heure choisie au lieu de « ~12 min » codé en dur.
**Acceptance** : PHPUnit (TO BE CREATED at `tests/Feature/Order/WaitEstimateEndpointTest.php` : 0 cmd→15-20, 3→20-25, 7→30-35, 12→30-35 cap, fermeture) ; existants VERTS : `tests/Feature/Order/ScheduledOrderIntakeValidationTest.php`, `tests/Feature/KitchenReleaseRuleTest.php`, `tests/Feature/KDS/KdsScheduledOrderGateTest.php` ; e2e : 2 cmds injectées → web affiche la fourchette attendue ; commande programmée réelle visible KDS à l'heure.

---

## §7 Système S5 — Fidélité par téléphone (Wave E)

**Anchors** : DÉJÀ phone-keyed : `Frontend/LoyaltyController.php:76-82` (check par loyalty_code puis phone), `:143,203` (register par phone), earn 10 pts/€ `AwardLoyaltyPointsOnDelivery.php:84-102`, POS `PosLoyaltyController.php` + `PosRedemptionService.php`, routes `routes/api.php:1521-1551` ; web `loyalty-v2.jsx:19,56` (« compte identifié par ton téléphone »), `api.js:654-676`.
**Tasks** :
- T-E.1 Signup email (Wave C) → auto-register fidélité par phone au 1er login (ou lazy au 1er ordre) — pas de double compte (PHONE_EXISTS déjà géré `api.js:652`).
- T-E.2 e2e cross-surface : commande web payée → points crédités → visibles caisse (écran Fidélité par téléphone) — preuve DB + capture.
- T-E.3 Documenter la structure pérenne (1 § dans `docs/BUSINESS_RULES.md` : téléphone = clé fidélité web/borne/app future ; email = login/vérif).
**Acceptance** : `tests/Feature/LoyaltyApiTest.php` + `tests/Feature/OrderCancellationLoyaltyTest.php` verts ; e2e points web→caisse capturé ; (test TO BE CREATED at `tests/Feature/Loyalty/EmailSignupLoyaltyLinkTest.php`).

---

## §A Agent army (fan-out par tâche — cf. matrice skill)
- Frontend visual (A, B, D-front) : Architect + UX + Implementer + RED + QA Visual + RED Visual.
- Backend logic (C, D-back) : Architect + Security + DBA + Implementer + RED.
- Cross-surface e2e (E, F) : full row.
Reports → `reports/test-e2e/web-commande-2026-07-28/<wave>-<role>.json` (schéma P0-P3 + repro + evidence). Implementer JAMAIS en parallèle d'un autre implementer.

## §X Waves — ordre, parallélisme, checkpoints

| Wave | Scope | Parallélisme | Dépend |
|---|---|---|---|
| W1 Pré-flight | baselines (PHPUnit key filters, vitest, chaîne NF525, serveur local 8000/8766), backup branch web | séquentiel | — |
| W2 = A | Wizard web (4 fixes) | audits // , implémentation séquentielle | W1 |
| W3 = B | Uber Eats CTA | séquentiel (petit) | W1 (parallèle possible avec W2 fin — repo disjoint funnel vs wizard : NON, même repo/fichiers proches → séquentiel) |
| W4 = C | Compte email (backend+front) | backend puis front | W1 |
| W5 = D | Estimation + créneaux | backend puis front | W4 non requis — mais séquentiel après W4 (partage funnel.jsx avec W3) |
| W6 = E | Fidélité liens + cross-surface | séquentiel | W4 |
| W7 = F | Convergence finale 2 cycles + deploy | boucle test-e2e | tout |

**Checkpoint par wave (6 points, Axis 3)** : tasks PASS · frozen diff 0 · NF525 si fiscal touché · visual gate captures lues · RED dispute · BRAIN §2/§3 + commit (web repo : commit par wave ; ⚠️ **push web = déploiement Vercel immédiat → push seulement à W7** après convergence).
**Interrupt-resume** : WIP commit + manifest `reports/test-e2e/web-commande-2026-07-28/INTERRUPT_<wave>.md` + BRAIN §2.
**Convergence-failure** : 3 heals même cluster → STOP + STUCK doc + choix owner.

## §G Owner gates

| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G1 | URL page Uber Eats du restaurant | Owner | l'URL exacte | meta `uber-eats-url` + commit | PENDING (placeholder actif) |
| G2 | Credentials SMTP production (envoi codes email) | Owner | host/user/pass (Brevo/Gmail/OVH…) | `.env` VPS + registre secrets | PENDING (staging=log driver) |
| G3 | Deploy backend VPS (migrations + endpoints C/D) | Owner sign-off, Claude exécute deploy-lecayenne.sh | go explicite | BRAIN §2 + deploy log | PENDING |
| G4 | Push web → Vercel (= prod) | Owner a demandé « update the website » → push à W7 APRÈS convergence, annoncé avant | commit list annoncée | BRAIN §2 | PENDING W7 |

G1/G2 ne bloquent PAS W2-W6 (placeholder + driver log). G3 bloque la mise en prod de C/D uniquement.

## §R References
- `ultra-audit-profond`, `test-e2e`, `superpower-gstack` skills ; CLAUDE.md §5-§13 ; PROJECT_BRAIN §2 (2026-07-27) ; memories : [[fix_wizard_sauce_top_3viandes_mollie_gate_2026-07-20]], [[fix_sauce_en_plus_data_gap_2026-07-16]], [[deploy_total_web_vps_valide_2026-07-19]], [[goal_web_complet_livraison_viandes_nav_2026-07-27]].
- Pièges connus : `otps.token`=le code (`code`=indicatif) ; expected_total = GRAND total ; Babel ES5 (pas de hoisting moderne) ; bundle/chunk périmé ; catch-all-200 masqueur.

## §F Final rule
DONE = les 7 demandes owner démontrées par preuve (captures analysées + commandes réelles scellées au centime + tests nommés verts), 2 cycles e2e propres identiques, frozen 0, chaîne NF525 OK, BRAIN à jour. Pas de « presque ». Push Vercel à W7 uniquement ; VPS gate owner.
