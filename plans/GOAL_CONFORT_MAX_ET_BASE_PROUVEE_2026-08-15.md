# GOAL — CONFORT MAXIMAL & BASE PROUVÉE EN RÉEL
**FoodKing V1 LOCAL « Le Cayenne »** · rédigé 2026-08-15 · HEAD `e2d2ca3b4` · branche `pos/category-first-caisse-2026-06-23`

> Demande owner (verbatim) : « créer le goal max décipliné qui cible tout les amélioration avec max
> logique pour une max utilisation comfortable de tout les accèes et surtout de caisse et gestion de
> tout ! et tout la structure et fonctionalité de base validé en test réel et les fonctionalité secondaire »

Lecture obligatoire avant exécution : `CONSTITUTION.md` → `SYSTEM_MAP.md` → `SYNC_CONTRACT.md` (si la voie
touche la synchro) → `PARALLEL_PROTOCOL.md` → `PROJECT_BRAIN.md §2`.

---

## §0 — PRÉAMBULE

### 0.1 Décision arbre de travail
Travail **dans l'arbre principal**, branche courante. Deux faits mesurés l'imposent :
- 20 worktrees existent, jusqu'à **2364 commits de retard** (`agent-a4f605517bf912efe`, `clever-hypatia-1e4f84`,
  `s8-parcours-web`). Y dispatcher un agent = auditer un code qui n'existe plus. **T-1.1 s'en occupe.**
- Une autre session écrit dans ce dépôt (6 `reports/**.json` + `.claude/scheduled_tasks.lock` modifiés à
  05:20 le 2026-08-15, hors de cette session). ⇒ `git add <chemins explicites>` **uniquement**, jamais `-A`,
  et relire `git log origin/<branche>..HEAD` juste avant tout push.

### 0.2 Périmètre
**DANS** : les 5 voies de `SYSTEM_MAP.md` (BORNE · CAISSE · KDS+OSS · CENTRAL · storefront backend) + la
chaîne d'impression + le canal Uber (rattaché CAISSE, `SYSTEM_MAP.md:42`).

**DEHORS, et pourquoi** :
- Site client Vercel (`lecayenne-web-deploy`) et `mobile/` — dépôts séparés. Le canal `web` est **réel**
  (31 commandes/30 j) et entre par `FrontendOrderService.php:670`, mais **son interface n'est pas ici** :
  ce GOAL le prouve au niveau API, jamais au niveau écran.
- Storefront de CE backend : **supprimé volontairement** le 2026-06-25 (`frontendRoutes.js:19-25`
  `[STOREFRONT-DELETE]`, `config/features.php:50` `staff_only_mode=true`). Ne pas le « réparer ».
- Cloud / multi-tenant / scale — `CONSTITUTION.md §1`, jamais un blocker V1.
- Service à table (`components/table/**`) — dormant V1 (`pos.dine_in_enabled=false`).

### 0.3 L'ÉCHELLE DE PREUVE (le cœur logique de ce GOAL)
Le 2026-08-14, l'impression cuisine avait ses tests verts **et** son `/health` à `UP` — et pourtant : zéro
papier d'abord, puis papier en double. Deux niveaux de preuve verts, démentis par le réel. D'où :

| Niv | Nom | Ce que ça prouve | Qui l'obtient | Coût |
|---|---|---|---|---|
| **N0** | **ALLUMÉ & UTILISÉ** | la fonction est active en prod **et réellement employée** (requête de comptage) | Claude | minutes |
| **N1** | LOGIQUE | la règle de calcul est juste (PHPUnit / Vitest) | Claude | minutes |
| **N2** | ÉCRAN | l'écran affiche et réagit vraiment (Playwright) | Claude | 10-40 min |
| **N3a** | MATÉRIEL RÉPOND | le papier sort, le tiroir s'ouvre, le TPE répond | **owner sur place** | 30 min |
| **N3b** | TIENT EN SERVICE | rush, 2 commandes simultanées, une erreur au milieu | **owner sur place** | 1 service |
| **N4** | FENÊTRE | 5 compteurs relevés sur 7 jours de service réel | owner + Claude | 7 j |

**Règle qui tranche les cas ambigus** : *toute fonction dont le succès est rapporté par un composant qu'on
ne contrôle pas (pont d'impression, spouleur Windows, TPE, Uber) ne peut pas être close au-dessus de N2 par
une assertion logicielle.* Un `202 queued` n'est pas du papier.

**N3a ≠ N3b** : le double-ticket du 14/08 était **N3b**. Le premier ticket est sorti — N3a aurait été VERT.

**N3 ne bloque JAMAIS une vague** (`docs/gates/GATE_LOG.md` porte 8 `PENDING_HUMAN_GATE` non résolus depuis
avril/mai — 3,5 mois). Protocole : rendez-vous minuté, ≤10 gestes scriptés, une feuille. Sans réponse sous
**72 h**, la tâche part marquée `N2-ONLY` au §2 et la vague continue. Dette assumée > vague arrêtée.

### 0.4 BASE = 9 maillons (définition non arbitraire)
Ancrée sur la phrase de l'owner, `CONSTITUTION.md:12` — « ouvrir la caisse → prendre commandes (borne +
comptoir) → cuisine prépare (KDS) → client voit le statut → encaisser → clôture Z → lire les chiffres ».
Deux maillons ajoutés parce que le réel les impose (justifiés ci-dessous) :

| # | Maillon | Note |
|---|---|---|
| **L0** | Le système est debout ce matin (ponts, services, fraîcheur des bundles) | **ajouté** : la 1ʳᵉ panne du 14/08 (service Windows session 0) n'est couverte par aucun des 7 |
| **L1** | Ouvrir la caisse | |
| **L2** | Prendre une commande — **5 canaux réels** | mesuré : borne 108 · **téléphone 101** · comptoir 81 · web 31 · Uber 26 (30 j). Le téléphone est le **2ᵉ canal** et n'est pas dans la phrase d'origine. Rupture/86 est un **critère d'acceptation de L2**, pas du secondaire : vendre l'invendable casse L3 et force L5bis |
| **L3** | La commande arrive en cuisine — écran **et papier** | |
| **L4** | Le client voit son statut | |
| **L5** | Encaisser (espèces / carte TPE Plan B / TR / mixte) + tiroir + ticket | |
| **L5bis** | **Corriger** — annuler une ligne, rembourser, rouvrir, réimprimer | **ajouté** : geste le plus fréquent après encaisser ; couvert N1 (`CancelReasonEnforceTest`, `RefundDrawerSymmetryTest`) mais hors boucle donc jamais confortable |
| **L6** | Clôture Z (NF525) | |
| **L7** | Lire les chiffres du jour | |

**Tout le reste = SECONDAIRE** (fidélité, roue, Uber photo, promo, marketing, notifications, observabilité…).
L'authentification n'est pas un maillon : c'est un **prérequis** — mais le personnel bloqué hors de l'outil
est traité en V2 (T-2.4).

### 0.5 Pipeline & protocoles — référencés, non recopiés
- Pipeline par tâche : `~/.claude/skills/ultra-audit-profond/SKILL.md`
- Frozen-zone override : `~/.claude/skills/lock-plan/SKILL.md`
- Protocoles vague-checkpoint / interruption-reprise / non-convergence :
  `plans/GOAL_OPS_RELIABILITY_SWAP_MULTIMARQUE_2026-08-12.md §X` — **appliquer tel quel**.
- Zones gelées : `CONSTITUTION.md §3.1` + `CLAUDE.md §7` (source canonique ; `memory/reference_frozen_zones.md`
  **n'existe pas** malgré les références qui y pointent).

### 0.6 Convergence & plafond dur
- **Convergé** = deux cycles consécutifs avec P0+P1 = 0 **et jeux de findings identiques**.
- **Rejet immédiat** : label brut visible · erreur console · ligne de diff en zone gelée sans LOCK ·
  critère d'acceptation sans chemin de test nommé · « ça marche presque ».
- **Plafond : 20 tâches.** Justification mesurée — `GOAL_OWNER_8AXES` (47,8 Ko) : vagues 3 et 4 jamais
  atterries · `GOAL_OPS_RELIABILITY_SWAP` (45,4 Ko) : arrêté à W3/W8 · `GOAL_CAYENNE_FINITION` (**12,8 Ko**) :
  convergé et déployé en une journée. Le volume tue l'exécution.
- **Les inventaires détaillés ne sont PAS recopiés ici.** Ils vivent, vérifiés file:line, dans
  `reports/test-e2e/goal-max-confort-2026-08-15/discovery/{caisse,central,kds-impression,borne-web}.md`.
  Chaque tâche y renvoie. C'est ainsi que « toutes les améliorations » sont ciblées en 20 tâches.

---

## §1 — ÉTAT MESURÉ N0 (production, 2026-08-15)

| Mesure | Valeur | Lecture |
|---|---|---|
| Commandes / 30 j | **347** | les 5 canaux sont vivants, aucun théorique |
| Sessions de caisse | **2 ouvertes, 0 close — jamais** | ouvertes le 25/06 et le 08/07, **encore ouvertes depuis 50 et 37 jours** |
| Z reports | 4 (25/06, puis **49 j de trou**, puis 13-14-15/08 à 00:01) | Z réparé et désormais nocturne ; le trou = incident connu |
| `APP_ENV` | `staging` | **tous** les boot-guards NF525 sont derrière `if (app()->environment('production'))` (`AppServiceProvider.php:190`) ⇒ filet inerte |
| `BROADCAST_DRIVER` | `log`, pas de soketi | aucun temps réel : **tout est en sondage 5 s**. État accepté, pas une régression |
| **Playwright** | **`--list` → `Total: 0 tests in 0 files`** (mesuré ce jour) | **la suite complète ne tourne plus depuis le 2026-05-29** — cf. D9 |
| **PHPUnit** | 4690 tests, dernier run complet connu 4686 verts / 2 rouges | tourne, mais **jamais sur MySQL** — cf. D11 |
| **Vitest** | 2887/2891 au dernier run | `kdsBundleFreshnessSentinel` **ROUGE mesuré ce jour** |

**Conclusion N0 la plus lourde** : L1 et L6 — le premier et l'avant-dernier maillon de la boucle de l'owner —
**ne sont pas utilisés**. Le restaurant tourne à 347 commandes/30 j sans cycle d'ouverture/clôture de caisse.
Ce n'est pas un défaut de confort, c'est une fonction abandonnée. **T-2.1 tranche : réparer ou acter.**

---

## §2 — REGISTRE DES DANGERS CONNUS NON TRAITÉS
> **À relire à l'ouverture de CHAQUE vague.** Raison d'être : le double-ticket du 14/08 était écrit noir sur
> blanc **deux jours avant**, dans `reports/hardware/GLOBAL_OPS_HARDWARE_PROTOCOL_GAP_ANALYSIS_2026-08-12.md`
> (« POS/KDS dual-consume → double ticket → une seule autorité active »). L'analyse n'a pas manqué : elle
> n'a **pas été consommée**. Ajouter un niveau de preuve ne corrige pas un défaut de lecture — ce registre, si.

> **Relu et mis à jour à la clôture de la Vague 7 (2026-08-15, convergence finale).** Chaque
> statut ci-dessous porte la preuve (commit ou vérification) qui le justifie — un « FERMÉ »
> sans preuve n'est pas un « FERMÉ ».

| # | Danger | Source | Statut |
|---|---|---|---|
| D1 | `202 enqueue` pris pour un succès → ticket perdu marqué imprimé | gap-analysis 12/08 | **OUVERT** — hors périmètre des 20 tâches (§4) ; non traité |
| D2 | crash après papier avant ACK → doublon au retry | gap-analysis 12/08 | **OUVERT** — hors périmètre des 20 tâches (§4) ; non traité |
| D3 | snapshot mutable → ticket rejoué avec un contenu différent | gap-analysis 12/08 | **OUVERT** — hors périmètre des 20 tâches (§4) ; non traité |
| D4 | POS/KDS dual-consume → double ticket | gap-analysis 12/08 | **FERMÉ** — corrigé `e2d2ca3b4` (avant ce GOAL) |
| D5 | délai client 3 s sur le pont cuisine qui répond en 15 s réels | ce GOAL, T-2.2 | **FERMÉ** — corrigé Vague 2 T-2.2 (`kitchenRawTimeoutMs`, 20s), preuve RED→GREEN `tests/js/posLocalPrinter.spec.js` |
| D6 | négation Uber (« sans poulet ») → portion fantôme au bandeau cuisson | ce GOAL, T-2.3 | **FERMÉ** — corrigé Vague 2 T-2.3 (garde négation `UberOrderMapper.php`), preuve `tests/Feature/Uber/UberOrderMapperMeatLinesTest.php` + re-testé en Vague 3 (`BoucleQuotidienneTest` canal Uber) |
| D7 | aucune alarme sur `kitchen_ticket_claims` orphelin (`grep app/Console app/Jobs` = 0) | kds-impression.md | **OUVERT** — hors périmètre des 20 tâches (§4) ; non traité |
| D8 | 8 `PENDING_HUMAN_GATE` non résolus depuis 2026-04-20 / 04-26 / 05-02 | `docs/gates/GATE_LOG.md` | **OUVERT** — décision owner exclusivement, non résolvable par agent |
| **D9** | Suite E2E morte depuis 2,5 mois (`fs.readFileSync` module-level cassait toute la collecte : 0/1580 tests) | mesuré `npx playwright test --list` | **FERMÉ** — corrigé Vague 1 T-1.1a, collecte restaurée 1590 tests/428 fichiers (puis 1259/340 après purge preuve-vacante Vague 3) |
| **D10** | 87 méthodes de test ne s'exécutent jamais (14 fichiers sans suffixe `Test.php`) | `discovery/dette-et-infra.md` | **FERMÉ** — corrigé Vague 1 T-1.1b, 14 `git mv` + `composer dump-autoload` |
| **D11** | Zéro CI depuis le 2026-06-23 (workflows sur `main`/`develop` uniquement) | `discovery/dette-et-infra.md` | **FERMÉ (déclenchement)** — corrigé Vague 1 T-1.1c, 3 workflows étendus à `pos/category-first-caisse-2026-06-23` + 1 vrai bug MySQL/SQLite trouvé et corrigé (`OrderStateMachineLockForUpdateTest`). Le comptage exact des ~44 skips MySQL-only n'a pas été ré-audité poste-fix — à vérifier au premier run CI réel sur la branche |
| **D12** | Ventilation fausse du paiement mixte au Z : le piège est armé mais **le test est `markTestSkipped`** | `tests/Feature/Fiscal/ZReportSplitBucketingLockTest.php` | **OUVERT — vérifié Vague 7** : `ZReportService.php` ne lit toujours pas `order_payments`/`->payments` (0 occurrence, grep 2026-08-15), le test s'auto-arme donc correctement en skip. `ZReportService.php` est FROZEN §7 — la correction exige le LOCK `plans/LOCK_ZREPORT_SPLIT_BUCKETING_M6-002` contresigné owner. P1 NF525 réel, non résolvable par agent |
| **D13** | `payment-gateway` index expose la **valeur secrète** à tout utilisateur authentifié | `GOAL_ADMIN_NAV_...2026-08-13.md:87` (SET-T02) | **FAUX POSITIF — fermé Vague 5 T-5.3** : `PaymentGatewayController` porte déjà `middleware(['permission:settings'])->only('index','update')` depuis un heal antérieur au 2026-06-01 (donc AVANT l'audit source du 2026-08-13, qui a vérifié un état périmé). Verrouillé par `tests/Feature/Settings/PaymentGatewaySecretExposureTest.php` |
| **D14** | `PUT/PATCH /admin/message/{message}` → méthode contrôleur inexistante, **500 latent** | idem `:159` (NC-09) | **FAUX POSITIF — fermé Vague 5 T-5.3** : aucune route PUT/PATCH n'existe sur cette URI (retirée en 2026-06-01, commentaire adjacent `[NC-MSG-UPDATE-DEAD heal 2026-06-01]`) — même audit source périmé que D13. Verrouillé par `tests/Feature/Settings/MessageControllerNoDeadUpdateRouteTest.php` |

---

## §3 — CARTE DES VOIES
**Source unique : `SYSTEM_MAP.md`** — non recopiée ici (règle : ne pas dupliquer le contexte, le pointer).
Ancres re-vérifiées au HEAD `e2d2ca3b4` : CAISSE 16 ctrl + 6 dirs front + 74 tests · CENTRAL 97 ctrl + 42 dirs ·
KDS+OSS 3 ctrl + 11 composants · BORNE 24 `.vue` + 4 services · IMPRESSION transverse (`KitchenTicketPrintListener.vue`,
`{kitchen,pos}LocalPrinter.js`, `KitchenTicketQueueController.php`, `Kitchen/**`, `Hardware/**`, `tools/*-bridge/**`).

**4 dérives mesurées vs la carte, à corriger en T-1.3** : `SplitPaymentService` → `Payments/` · `CashDrawerService`
→ `Cash/` · **97** ctrl et non 100 · 6 dirs admin absents (`demo`,`kitchen`,`promo`,`purchasing`,`shared`,`uber`).

---

## §4 — LES 20 TÂCHES

### VAGUE 1 — PRÉ-VOL : RENDRE LA PREUVE POSSIBLE (bloque tout le reste) · séquentiel
> ⚠️ **Cette vague passe AVANT tout le reste, et sa raison est mesurée** : aujourd'hui la suite E2E ne
> collecte **rien** (D9), 87 méthodes PHPUnit ne s'exécutent **jamais** (D10) et aucune CI ne tourne sur la
> branche de travail (D11). Toute vague de tests lancée avant ces trois correctifs hériterait d'une **preuve
> creuse**. Coût cumulé mesuré : ~1 h. Ne jamais inverser cet ordre.

**T-1.1** — 🔴 **Ressusciter la preuve** (3 sous-items, une seule acceptance).
• (a) D9 — sortir le `fs.readFileSync` du niveau module dans `tests/e2e/goal-functional-livreur-2026-05-28.spec.js:28`
  (le lire dans un `beforeAll`, ou `test.skip` si le jeton est absent). **1 ligne.**
• (b) D10 — `git mv` des 14 fichiers de test sans suffixe `Test.php` (87 méthodes ressuscitées).
• (c) D11 — ajouter la branche de travail aux déclencheurs des 3 workflows, **et** faire tourner PHPUnit sur
  **MySQL** au moins une fois : c'est le seul mode où les triggers NF525 `BEFORE DELETE` et la concurrence
  `lockForUpdate` sont réellement exercés.
• acceptance: `npx playwright test --list` annonce **~1580 tests** (et non 0) · `phpunit --filter=InnodbLockWaitTimeoutSentinel`
  exécute au moins 1 test · un run CI vert existe sur la branche · les 3 nombres sont consignés dans `preflight/baseline.md`

**T-1.2** — Baselines gelées **après** T-1.1 (avant, elles mesureraient le vide).
• faire: PHPUnit verts/rouges, Vitest (dont `kdsBundleFreshnessSentinel` **rouge ce jour** — le réparer ou le
  documenter), Playwright collectés, `audit_logs` count + last_hash, `git rev-parse HEAD`
• acceptance: `preflight/baseline.md` porte les 5 valeurs **et** la commande unique qui les rejoue

**T-1.3** — Hygiène de la carte et des documents (ce qui trompe le prochain agent).
• worktrees : inventorier les 20 (retard jusqu'à **2364**, deux portent 1986 et 20 fichiers non commités) ;
  **ne rien supprimer** (G1) ; inscrire l'interdiction d'y dispatcher un agent dans `PARALLEL_PROTOCOL.md`
• `SYSTEM_MAP.md` : corriger les 3 dérives du §3 · `CLAUDE.md §9` annonce une baseline FormRequest de **69**,
  le code est à **64** et passe · `CLAUDE.md §7` liste **13** fichiers gelés, la sentinelle en empreinte **15**
• acceptance: `preflight/worktrees.md` liste les 20 · `SYSTEM_MAP.md` et `CLAUDE.md` re-grep-vérifiés, datés au HEAD

### VAGUE 2 — SOLDER LE CHAUD · séquentiel · **relire §2 d'abord**
**T-2.1** — 🔴 **P0 ARGENT — la clôture de caisse est infinissable.**
• anchor: `CashDrawerService.js:132`+`:142` (deux POST sans compensation) · `CashDrawerService.php:477-481`
  (relecture `status=OPEN` seulement) · `RolePermissionTableSeeder.php:81` (override = Branch Manager ; le
  commentaire dit lui-même « POS Operator must escalate ») · **0 appel UI à `reconcile()`** (grep components = ∅)
• scénario: caissier clôture avec >2 € d'écart → `/close` OK → `/reconcile` 403 → session CLOSED-non-réconciliée,
  invisible (l'écran ne lit que OPEN), **terminable par personne**. Famille « Z bloqué 17 jours ».
• faire aussi: trancher le N0 §1 — 2 sessions ouvertes depuis 50/37 j, 0 close jamais. Réparer **ou** acter
  l'abandon avec l'owner (G2). Ne pas « améliorer » une fonction inutilisée sans cette décision.
• test: (À CRÉER) `tests/Feature/Cash/CashCloseCompensationTest.php` — doit ROUGIR avant le fix
• acceptance: le test rougit puis passe · une session CLOSED-non-réconciliée est visible et terminable en N2

**T-2.2** — 🔴 **D5 — délai 3 s sur le seul chemin d'auto-impression survivant.**
• anchor: `KitchenTicketPrintListener.vue:38-44` importe depuis **`posLocalPrinter`** · `posLocalPrinter.js:86-93`
  → **3000 ms** (et lit `caisseBridgeRawTimeoutMs`) · `kitchenLocalPrinter.js:73` → **20000 ms** ·
  `kitchen-bridge.js:54` → le pont cuisine répond le **résultat réel** à 15 s
• conséquence: toute impression physique > 3 s = faux échec → `ack(false)` → re-réclamation à 5 s → **boucle**
• ⚠️ régression introduite par `e2d2ca3b4` (ce chemin est devenu unique ce soir-là)
• test: (À CRÉER) `tests/js/kitchenBridgeTimeoutParity.spec.js` — le délai client doit dépasser celui du pont
• acceptance: parité prouvée · N3a rendez-vous ticket long (G3)

**T-2.3** — 🔴 **D6 — « sans poulet » fait cuire du poulet.**
• anchor: `UberOrderMapper.php:81` — `preg_match('/viande|meat/i')` sur le titre de groupe puis le libellé du
  modificateur pris tel quel, **sans garde de négation** · la garde existe à 3 m pour les crudités
  (`kdsSymbolic.js:115`, écrite après exactement cet incident) · `MEAT_TABLE` n'en a aucune
• mémoire déjà écrite et non appliquée : `uber_photo_deploye_refus_devient_ajout_2026-08-12`
• ⚠️ régression introduite par `c377d959f`, **déployée**
• test: étendre `tests/Feature/Uber/UberOrderMapperMeatLinesTest.php` (cas « Sans poulet », « Choix de la garniture »)
• acceptance: négation ignorée des deux côtés (PHP + JS jumeau) · heuristique confrontée à un vrai payload (G4)

**T-2.4** — Le personnel ne peut pas réinitialiser son mot de passe.
• anchor: `router/index.js:65-73` — l'allowlist cite `auth.signup` et `auth.guest` qui **n'existent pas**
  (réels : `auth.signupPhone`, `auth.guestLogin`) ; `auth.verifyEmail` existe mais **n'y est pas**, or
  `ForgetPasswordComponent.vue:52` y pousse ⇒ renvoi en boucle sur `/login`
• test: (À CRÉER) `tests/js/staffOnlyAllowlistNamesExist.spec.js` — sentinelle : tout nom de l'allowlist doit
  correspondre à une route déclarée
• acceptance: sentinelle verte · parcours mot-de-passe-oublié prouvé en N2

### VAGUE 3 — HARNAIS DE LA BOUCLE (livre un OUTIL, pas des preuves) · séquentiel
> Pourquoi un harnais et pas des preuves : `tests/e2e` contient **369 specs dont 68 préfixés `_`** (datés,
> jamais rejoués), **360 des 370 sont à plat** (nommés par date/vague, pas par surface) et **aucun ne couvre
> L0→L7 d'un bout à l'autre**. Le plus proche, `_teste2e-massive-E4-caisse-kds-2026-07-24.spec.js`, **s'arrête
> avant le ticket** ; `mega-parcours-e2e-2026-05-08.spec.js` prétend au parcours complet mais **désactive
> paiement et impression**. Produire des preuves ici en ferait un 370ᵉ orphelin. Un harnais rejoué gratuitement
> à chaque vague suivante n'est payé qu'une fois. **Prérequis dur : T-1.1 fait** — sinon rien ne s'exécute.

**T-3.1** — Écrire `tests/e2e/boucle-quotidienne.spec.js` — 9 étapes taguées L0…L7, une par maillon §0.4.
• acceptance: le spec tourne en < 8 min (contrainte : `playwright.config.js` `workers:1`, `timeout:600_000`) ·
  chaque étape porte ≥1 assertion réelle · **aucun `expect(true).toBe(true)`**

**T-3.2** — Jumeau serveur `tests/Feature/BoucleQuotidienneTest.php` (les maillons prouvables sans navigateur,
dont le canal `web` par API — son interface est hors dépôt, cf. §0.2).

**T-3.3** — Purger la preuve vacante qui ment.
• anchor mesuré: `max-test-t2-pos-2026-05-28.spec.js` **23 `test(` / 0 `expect(`** · `goal-functional-pos-2026-05-28.spec.js`
  **8 / 0** · 12 fichiers `tests/Playwright/zz-audit-caissier-s*` à 0 expect · `final-borne-deep.spec.js`
  **1356 lignes / 1 expect** · `03-kiosk-wizard.spec.js:94` `test.fixme` **alors que c'est le smoke officiel**
  (`package.json:27`) · `05-pos-card.spec.js:138` `test.fixme` (paiement carte) · CI Playwright **opt-in**
  (`playwright.yml:20-40`) : une PR ordinaire n'exécute rien
• faire: supprimer ou réparer ; **ne jamais laisser un test vert qui n'assertit rien**
• acceptance: (À CRÉER) `tests/js/noVacuousSpecSentinel.spec.js` — échoue si un fichier de spec a 0 `expect`

### VAGUE 4 — CONFORT CAISSE (priorité owner) · séquentiel · voie CAISSE
> ⚠️ **Limite structurelle à dire d'avance** : l'écran de prise de commande EST `pos-wizard.js` +
> `admin-pos-v4.blade.php`, en **no-touch strict**. Le 14/08 il a fallu un LOCK pour un simple badge. Cette
> vague est donc **« confort caisse hors wizard »** (encaissement, tiroir, clôture, file, erreurs). Tout LOCK
> souhaité sur le wizard est listé en G5 et arbitré **en une seule fois**, jamais au fil de l'eau.
> Inventaire complet : `discovery/caisse.md` (23 surfaces, 10 frictions).

**T-4.1** — File d'encaissement en panne = « ✅ tout est encaissé ».
• anchor: `EncaissementComponent.vue:135-137` avale l'erreur, `:27-30` affiche l'état vide vert · le POS fait
  déjà mieux sur le même endpoint (`PosComponent.vue:4156`, « pas de faux vide ») · désaccord de permission :
  route front `pos-orders` (`encaissementRoutes.js:16`) vs endpoint `pos` (`routes/api.php:945`)
• test: (À CRÉER) `tests/js/encaissementNoFalseEmpty.spec.js`

**T-4.2** — « Ticket envoyé ✓ » s'affiche avant de savoir si l'imprimante répond.
• anchor: `ReceiptComponent.vue:629` toast succès → `:703` « pont hors ligne » 1-2 s plus tard · même motif
  `PosCounterCollectModal.vue:555`→`:568` · `ReceiptComponent.vue:769`→`:800`
• test: (À CRÉER) `tests/js/receiptToastAfterVerdict.spec.js`

**T-4.3** — Erreurs de caisse en anglais technique + 1 centime impose une justification écrite.
• anchor: `PosCashDrawerSessionDialog.vue:38` affiche `data.message` brut · sources `CashDrawerService.php:129/169/173/240/244`
  et `:279-283` (« Cash variance -40.00€ exceeds threshold… ») · `PaymentService.php:1021` ·
  seuil client `PosCashDrawerSessionDialog.vue:402-404` = **0,005 €** vs serveur **2,00 €** (`config/cash.php:31`)
• viole ADR-007 (locale FR). test: (À CRÉER) `tests/Feature/Cash/CashErrorMessagesAreFrenchTest.php`

**T-4.4** — Confort tactile de l'encaissement : boutons billets (5/10/20/50 — le motif existe déjà
`PosCashDrawerSessionDialog.vue:370`, absent du numpad `PosV5Numpad.vue:49-66`) · types de mouvement en clair
(`:292` affiche `order_payment`/`drawer_open`) · scanner code-barres qui confisque Entrée dans les champs
(`posBarcode.js:42` sans test de `event.target`, contrairement à `:63-71`).
• test: (À CRÉER) `tests/js/posNumpadDenominations.spec.js`, `tests/js/posBarcodeIgnoresInputs.spec.js`

### VAGUE 5 — CONFORT GESTION (« piloter sans développeur ») · voie CENTRAL · ∥ V6 possible
> Inventaire complet : `discovery/central.md` — **45 réglages métier exigent aujourd'hui un développeur**.
> Cause racine **unique** : `InterrupteurService.php:43-56`, la liste blanche des réglages pilotables depuis
> l'écran ne contient que **2 entrées** (`split_payment.enabled`, `wheel.enabled`).

**T-5.1** — Élargir la liste blanche aux réglages **du quotidien** et leur donner un écran.
• priorité mesurée (les 8 qui coûtent le plus) : heures de service (`config/kds.php:99`) · tolérance d'écart
  de caisse (`config/cash.php:31`) · barème frais de livraison (`branches.delivery_fee_*`, dernier changement
  fait **par migration**) · **SIRET / TVA intra / mention légale imprimés sur le ticket** (`Branch.php:18` →
  `OrderReceiptEscPosRenderer.php:88-89`, absents de `BranchRequest.php:31-46`) · seuil d'alerte stock bas ·
  remise manuelle · codes promo · PIN carnet
• ⛔ **NE PAS** exposer `idempotency.enabled` ni les réglages fiscaux (NF525, décision figée)
• test: (À CRÉER) `tests/Feature/Pilotage/InterrupteurCatalogueTest.php` + sentinelle « tout réglage exposé a
  un écran ET un test »

**T-5.2** — Les tuiles d'accueil mentent par omission.
• anchor: `DashboardService.php:19-29,374-398` — les 4 tuiles sont des **cumuls depuis toujours**
  (`orderQuery()` sans filtre de date), sans période ni comparaison, alors que les graphiques dessous ont un
  sélecteur · alerte stock : seuil affiché 3× mais réglable nulle part ⇒ vaut 0 ⇒ **ne se déclenche jamais**
  (`NotifyStockLowOnStockLevelChanged.php:20-23`) · `StockLowAlertsWidget.vue:93-95` affiche une panne comme
  « aucune alerte »
• test: (À CRÉER) `tests/Feature/Dashboard/DashboardTilesArePeriodScopedTest.php`

**T-5.3** — Écrans vivants mais invisibles, coupon accepté puis refusé, **et la dette déjà diagnostiquée**.
• anchor: `v1-hidden-modules.js:12-54` — **23 entrées masquées** dont TVA, catégories, rôles, permissions,
  coupons, passerelle de paiement ; réafficher exige d'éditer un fichier source **et de recompiler** ·
  `FrontendOrderService.php:1069-1078` refuse à l'encaissement un coupon créé en admin, **sans aucun
  avertissement sur l'écran Coupons**
• **absorber (déjà diagnostiqué en août, jamais corrigé — ne pas re-chercher)** : **D13** fuite de la valeur
  secrète sur `payment-gateway` index (sécurité) · **D14** `PUT/PATCH /admin/message/{message}` → 500 latent ·
  compte écran ≠ compte export sur le rapport articles · filtre date = date de **création article**, pas date
  de commande · sujet mail abonnés codé en dur en anglais (ADR-007)
• décision owner requise (G6) : quelles entrées réafficher
• acceptance: D13 et D14 fermés avec test nommé · aucune fonction offerte en admin qui échoue en caisse sans
  avertissement

### VAGUE 6 — CONFORT CUISINE & BORNE · voies KDS+OSS et BORNE (disjointes, ∥ possible)
> Inventaires : `discovery/kds-impression.md`, `discovery/borne-web.md`.

**T-6.1** — 🔴 **Le carillon « nouvelle commande » ne sonne JAMAIS en production.**
• anchor: `KitchenDisplaySystemComponent.vue:339` — l'`<audio>` est dans le bloc **legacy V1**, or **V2 est le
  défaut** (`:1507`) ; `playKdsNewOrderSound` sort en silence si la ref manque (`:2124-2127`) ; le repli
  vibration est dans le `.catch()` de `play()` (`:2135`), jamais atteint. Le watcher appelle pourtant la
  fonction (`:1677`) — c'est la seule ligne conservée par `e2d2ca3b4`.
• c'est la friction qui coûte le plus de secondes par commande sur toute la voie
• test: (À CRÉER) `tests/js/kdsChimeReachableInV2.spec.js` · + N3b (G3)
• même tâche: cible « rouvrir » à **21 px** (`KdsV2Grid.vue:809`) contre 44 px voisin · sauce tronquée en 8
  colonnes (`KdsOrderLine.vue:174`, 22 px fixes, zéro `cqw`) · filtre poste inopérant mais persisté (`:294`)

**T-6.2** — Borne : le total affiché n'est gaté que sur 2 écrans sur 4, et la commande hors-ligne affiche `#—`.
• anchor: gaté `KioskCartComponent.vue:495-519` + `KioskPaymentComponent.vue:331-342` ; **non gaté**
  `KioskCategoriesComponent.vue:366` et `KioskAppComponent.vue:246-248` **[FROZEN → G5]** ·
  `KioskCashInstructionComponent.vue:19-21` affiche `#—` hors-ligne (`kioskCart.js:800`) et Plan B force
  `cash` (`:437`) ⇒ **toute** commande hors-ligne passe là ; le client n'a aucun numéro, la caisse ne voit rien
• test: (À CRÉER) `tests/js/kioskOfflineOrderHasReference.spec.js`
• ✅ **réfuté après vérification, ne pas re-signaler** : la sauce à +0,50 € est corrigée (affichage et charge
  partagent le prédicat `kioskPricing.js:39-42`) ; borne 100 % FR, 641 clés vérifiées, aucune clé brute

### VAGUE 7 — CONVERGENCE FINALE · séquentiel
**T-7.1** — Gate complet + attestation.
• **D12 d'abord** : `tests/Feature/Fiscal/ZReportSplitBucketingLockTest.php` est `markTestSkipped` alors qu'il
  garde un piège réel (ventilation fausse du paiement mixte au Z). Le réactiver **ou** documenter pourquoi il
  ne peut pas l'être — un test fiscal désactivé en silence est exactement ce que ce GOAL refuse.
• `boucle-quotidienne.spec.js` VERT (T-3.1) · suites Feature + Vitest au niveau des baselines T-1.2 ·
  `git diff --stat` sur la liste gelée = **0 ligne** (hors LOCK signés G5) · `php artisan fiscal:verify-chain --all`
  CHAIN OK · §2 relu : chaque danger est traité ou explicitement daté · rendez-vous N3a+N3b tenus **ou**
  tâches marquées `N2-ONLY` · BRAIN §2/§3/§4 à jour
• **aucun push, aucun déploiement sans GO owner explicite** (`CLAUDE.md §10`)

---

## §5 — ARMÉE D'AGENTS
Fan-out par type de tâche — les 5 lecteurs partent **en un seul message** (parallèle), l'implémenteur **jamais**
en parallèle d'un autre implémenteur, RED **toujours** après le commit et avant de déclarer fini.

| Type de tâche | Architect | Security | UX/A11y | DBA | Implementer | RED | QA visuel |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Confort écran (V4/V5/V6) | × | · | × | · | × | × | × |
| Argent / fiscal (T-2.1) | × | × | · | × | × | × | · |
| Impression (T-2.2) | × | · | · | · | × | × | · |
| Harnais (V3) | × | · | · | · | × | × | × |

Rapports persistés sur disque (`reports/test-e2e/goal-max-confort-2026-08-15/<vague>/<role>.md`) — la synthèse
se fait **depuis le disque**, jamais depuis le contexte : c'est ce qui survit à une interruption.

---

## §6 — PORTES OWNER

| # | Porte | QUI | QUOI (artefact) | OÙ | Bloque |
|---|---|---|---|---|---|
| **G1** | Supprimer les 20 worktrees périmés (2 portent 1986 et 20 fichiers non commités) | owner | oui/non par worktree | `preflight/worktrees.md` | rien (T-1.1 se contente d'inventorier) |
| **G2** | Cycle ouverture/clôture de caisse : **réparer ou acter l'abandon** (2 sessions ouvertes depuis 50/37 j, 0 close jamais) | owner | décision écrite | `PROJECT_BRAIN.md §6` | T-2.1 |
| **G3** | Rendez-vous matériel N3a+N3b : ticket long (>3 s), 2 commandes simultanées, carillon audible, tiroir | **owner sur place** | feuille ≤10 gestes | `reports/hardware/` | rien — 72 h puis `N2-ONLY` |
| **G4** | Un vrai payload Uber de production (aucun n'existe dans le dépôt ; l'heuristique n'a vu que son propre test) | owner | 1 JSON anonymisé | `tests/fixtures/uber/` | validation finale T-2.3 |
| **G5** | LOCKs zone gelée demandés **en une seule fois** : badge/total wizard POS, total barre panier borne | owner | LOCK doc contresigné | `docs/gates/` | V4 et T-6.2 partiellement |
| **G6** | Quelles entrées de `v1-hidden-modules.js` réafficher (23 masquées) | owner | liste | `PROJECT_BRAIN.md §6` | T-5.3 |
| **G7** | `APP_ENV=staging` — **pas un P0, un couple cohérent** : TPE simulé (`CONSTITUTION §2`) ⇒ `POS_SIMULATION_HARDWARE=true` ⇒ refus de boot en `production` (`AppServiceProvider.php:198`). Vérifié : `BROADCAST_DRIVER=log` **passe** le guard (seul `null` est refusé, `:344`) — le choix « pas de temps réel » n'empêche pas la bascule. **Le travail n'est pas de basculer**, c'est de balayer ce que `staging` désactive *ailleurs* (`grep -rn "environment('production')" app/`) et d'inscrire `staging` comme conséquence datée et signée dans `CONSTITUTION §2` | owner | signature §2 | `CONSTITUTION.md §2` | rien |

---

## §7 — RÉFÉRENCES
Inventaires vérifiés file:line : `reports/test-e2e/goal-max-confort-2026-08-15/discovery/{caisse,central,kds-impression,borne-web,dette-et-infra}.md`
· Cold-start : `CONSTITUTION.md` `SYSTEM_MAP.md` `SYNC_CONTRACT.md` `PARALLEL_PROTOCOL.md` `PROJECT_BRAIN.md §2`
· Protocoles vague : `plans/GOAL_OPS_RELIABILITY_SWAP_MULTIMARQUE_2026-08-12.md §X`
· Skills : `ultra-audit-profond` (pipeline par tâche) · `lock-plan` (zone gelée) · `test-e2e` (règle de convergence)

---

## §8 — RÈGLE FINALE
Ce GOAL est **fini** quand la boucle quotidienne L0→L7 est prouvée aux niveaux N0, N1, N2, et que chaque
maillon dépendant du matériel porte **soit** une attestation N3a+N3b datée et signée, **soit** la mention
`N2-ONLY` assumée au §2 — jamais une case vide, jamais un « ça devrait marcher ».

Et une règle qui prime sur toutes les autres, écrite parce que ce projet l'a payée deux fois en une soirée :
**une analyse qui n'est pas relue ne protège de rien.** Le §2 se relit à l'ouverture de chaque vague. Si une
vague s'ouvre sans que le §2 ait été relu, la vague n'est pas ouverte.
