# FoodKing — Plan maître de la mission de remédiation en boucle

> Blueprint ultra-détaillé de la mission `audit → cause racine → correction → re-audit → tests → e2e → test visuel (captures) → validation adversariale stricte → boucle`, jusqu'à validation **1000 %**.
> Conçu par orchestration adversariale (7 piliers en parallèle + 3 red-team du plan + synthèse). **⚠️ Ceci est un PLAN — aucune modification de code. L'exécution est gated sur ton feu vert.**

---

## 0. Vision & définition de « validé à 1000 % »

VISION. FoodKing est jugé block (~3.5/10, 7/8 invariants violés, 40 findings critiques). Le plan n'est pas un backlog de patchs mais une USINE DE PREUVE en boucle : chaque défaut devient une « cellule » (1 finding × 1 flux × 1 système, oracle chiffré, fiche cells/C-XXXX.yml) que l'on ne déclare corrigée que si le code ET les données ET l'invariant sont prouvés dans un environnement ré-exécuté par un juge indépendant.

POURQUOI UNE BOUCLE. Sur un code à 3.5/10 la suite produit est LÉGITIMEMENT rouge : elle teste des invariants cassés. On distingue donc deux verts (correction red-team) : (1) INFRA-GREEN = harnais opérationnel (vendor/node_modules, MySQL de test, Redis+worker, soketi:6001, Playwright→/opt/pw-browsers, fixtures qui chargent) ; (2) PRODUCT-GREEN = invariants prouvés, atteint cellule par cellule. La Phase 0 sort en INFRA-GREEN + une BASELINE PRODUCT-RED enregistrée (guards rouges = oracle de départ) ; exiger 11/11 vert produit pour démarrer serait une contradiction logique.

« VALIDÉ À 1000% » CONCRÈTEMENT. Une cellule n'ouvre sa porte que si 7 verrous sont verts sur un HEAD ré-exécuté : audit propre ; FAMILLE de gardes d'invariant (property-based, ≥3 vecteurs, mutation-testée contre la tautologie) rouge-sans-fix/verte-avec-fix ; unitaire ; intégration à assertion d'ÉTAT DB ; e2e nominal+échec ; visuel diffé contre baseline SIGNÉE (masques versionnés et diffés) ; 3/3 adversarial rejouant l'attaque du finding + intersections voisines. Preuve = triade capture + réseau + re-query DB/PSP par le juge. La mission est « à 1000% » non par min(cellules)=1000 au même commit_sha (livelock O(N²)), mais quand la suite complète passe VERTE N runs pleins consécutifs, zéro cellule ré-ouverte, gardes en CI bloquante permanente, 5 invariants prouvés par familles + remédiations de DONNÉES (backfill, re-scellement forward-only) validées.

PÉRIMÈTRE (red-team) : WBS piloté par la SURFACE d'attaque, pas par les 40 findings ; domaines ajoutés WBS-PAY-WEBHOOK (réconciliation serveur = seule source de PAID), WBS-CASH, WBS-PRINT, WBS-TAX-MODE, WBS-TENDER ; concurrence/chaos SYNC ; GCP traité COMPROMISE (révocation + rotation de tous secrets joignables).


### La boucle, par cellule

```
CYCLE DE VIE D'UNE CELLULE C-XXXX (1 finding x 1 flux x 1 systeme)

   FILE (surface-driven, DAG d'impact)
              v
 [1 AUDIT]->[2 BREAKDOWN 5-why + matrice x5 invariants]
              v
 [3 FIX code+DATA] -(commit: 1 fix + 1 garde)-> done-local
              |
   +------+------+------+-------+
   v      v      v      v       v
 [4 UNIT][5 INTEG DB][6 E2E][7 VISUEL][8 GARDE inv.
                     nom+ech  diff+masques  famille>=3
                                          mutation]
   |      |      |      |       |
   +--> tout vert ? --NON--> ROLLBACK cause racine (pas patch aval)
              | heal_count++ (max 3 -> ESCALADE)
              v OUI
   PORTE ADVERSARIALE : rejeu de l'attaque + bords
   (branch_id=0, token borne, hors-transition, double
   outbox) + voisins  -> 3/3 CONFIRMED
              v
   JUGE INDEP. : re-execute env ephemere, re-query
   DB/PSP ; artefacts = corroborants seulement
              v
   merge -> done-integrated (rejoue deps via DAG)
              |
   3 runs pleins verts + 0 re-ouverture ? --NON--> reboucle
              | OUI
              v
   6 PORTES PRODUIT vertes -> VALIDE 1000%
```

### Règles de convergence (pourquoi la boucle TERMINE)

TERMINAISON GARANTIE (corrige le livelock red-team).

1. Deux « done ». done-local = commit worktree vert ; done-integrated = vert APRÈS merge. Seul l'intégré compte ; chaque merge ne rejoue QUE les cellules dépendantes via un DAG d'impact → tue la règle « même commit_sha » globale.

2. Point fixe mesurable. Sortie ssi N=3 suites PLEINES consécutives 100% vertes sur HEAD de release + zéro ré-ouverture + burn-down à plat. Une paire A↔B qui se ré-ouvre = hotspot couplé fusionné en UNE cellule co-conçue.

3. Critère d'ÉCHEC explicite (absent au départ). Budget global itérations+temps/domaine ; dépassement → ESCALADE HUMAINE tracée (état gelé, artefacts, décision reprise/redesign/abandon). Local : 3 heals max → escalade. Escalades triées par un dispatcher, jamais en masse.

4. Anti-thrash. Merge sérialisé sur hotspots (Vuex/routes/config/migrations) ; bisection auto de blame ; mutation testing sur les gardes interdit d'ajuster un test pour passer.

DoD MISSION : suite complète verte N runs + 5 invariants par familles + cellules DATA + 6 portes produit CI bloquante + human-gates NF525/GCP/purge-git signées.

### Portes de validation produit (opposables)

- G1 PRICING : recompute backend == total sur POS/borne/KDS/OSS/online avec paniers fuzzés (remise+promo+fidélité+86 cumulés) ; tout écart ou total négatif = rouge. Le client ne fixe jamais le prix.
- G2 ISOLATION : deny-by-default HTTP + anti-fuite BROADCAST/CACHE (canal soketi, rapport agrégé, clé CDN portent branch_id) ; cellules miroir 'cross-branche autorisé' (super-admin/reporting) allow-listées, vertes ensemble.
- G3 TRANSITIONS+TENDER : triangle paiement×annulation×remboursement sur 6 ordres + refund PARTIEL, split, pourboire, arrondi ; verrous FOR UPDATE, aucune double-restitution ni PAID effacé.
- G4 NF525/FISCAL : chaîne HMAC append-only vérifiée, numérotation continue sans trou, Z/X-report, TVA sur-place/emporter, export FEC ; corrections par écriture de compensation, JAMAIS re-scellement (human-gate légal).
- G5 OUTBOX/SYNC : atomicité + idempotence au rejeu/hors-ordre/offline→reco sous N workers, latence et partition injectées ; horloge DÉRIVÉE (jamais gelée) sur les sync ; exactly-once prouvé.
- G6 PAY-WEBHOOK : statut PAID = réconciliation serveur par webhook signé+idempotent (Stripe/PayPal sandbox), races webhook↔redirect↔outbox couvertes ; paiement déclaré par le client rejeté.
- G7 SÉCURITÉ POST-INCIDENT : matrice authz générée rôle×route×branche complète ; Installer ré-authentifié ; GCP révoquée + rotation de tous secrets joignables ; tokens borne super-admin révoqués, rejeu/expiration Sanctum testés.
- G8 PARITÉ TEST↔PROD & DÉPLOIEMENT : money en cents, TZ et arrondi espèces figés ; ordre migrate→drain worker→backend→bundle ; smoke rejouant les gardes d'invariant en prod-like avant d'ouvrir la porte 1000%.

### Les 8 premiers gestes (démarrage)

1. PHASE 0 INFRA : installer vendor/ (composer) et node_modules/ (npm), builder laravel-mix ; provisionner MySQL de test + Redis + worker + soketi:6001 (creds non vides) ; router Playwright vers /opt/pw-browsers. Sortie = INFRA-GREEN.
2. Séparer trois .env stricts (dev / phpunit-unit / integration-outbox) ; lever les masques sqlite:memory et QUEUE=sync ; jamais de secrets prod (GCP/Stripe) hors sandbox ; figer money=cents, TZ, arrondi espèces.
3. Enregistrer la BASELINE PRODUCT-RED : écrire les familles de gardes d'invariant (pricing, isolation, transitions, NF525, outbox) et CONSTATER qu'elles sont rouges = oracle de départ. Ne pas exiger le vert pour sortir de Phase 0.
4. INVENTAIRE branch_id==0 AVANT tout fix : grep exhaustif seeds/jobs/rapports/E2ESeeder/workers ; classer chaque site (système vs admin) ; introduire un flag is_admin/system explicite ; shadow-mode log-only pour mesurer l'impact.
5. Piloter le WBS par la SURFACE : générer l'inventaire endpoints×inputs×broadcasts×channels ; une cellule 'négative' par surface non couverte ; ouvrir WBS-PAY-WEBHOOK, WBS-CASH, WBS-PRINT, WBS-TAX-MODE, WBS-TENDER.
6. Bâtir le harnais du JUGE INDÉPENDANT : env éphémère from-scratch qui ré-exécute, re-query DB/PSP, hache les captures (runner out-of-band) ; publier le DAG d'impact et la CI bloquante build→unit→integ→e2e→visuel→adversarial.
7. Poser 4 baselines visuelles SIGNÉES POS/borne/KDS/OSS marquées 'provisoires' ; séparer la suite VISUELLE déterministe (async stubé, pixel-diff ≤0.01) de la suite ASYNC réelle (redis/soketi, waits d'événements) ; masques versionnés+diffés.
8. Ouvrir les HUMAN-GATES irréversibles : révocation+rotation GCP (compromise) + secrets joignables ; contreseing légal NF525 ; purge git EN DERNIER (ré-ancrage trailers) ; créer les cellules DATA backfill/reconciliation.


---

# Piliers du plan

# Phase 0 — Mise en exécution & testabilité (prérequis absolu)

> **Doctrine.** L'audit est **100 % statique** (`vendor/` et `node_modules/` absents). Aucun build/test/e2e/preuve visuelle n'existe **tant que la Phase 0 n'est pas verte**. Un finding « corrigé » sans Phase 0 verte reste **non prouvé = non corrigé**.
>
> **Gate bloquante :** `Phase 0 == GREEN` est précondition dure de la boucle `audit → correction → tests → e2e → visuel → adversarial`. Un seul feu rouge = boucle **gelée**, retour Phase 0.

## 0.1 Definition of Ready (11 feux)

| # | Feu | Critère | Preuve |
|---|-----|---------|--------|
| G1 | Deps PHP | `composer install` exit 0 | `vendor/autoload.php` |
| G2 | Deps JS | `npm ci` exit 0 | `.bin/vitest` |
| G3 | DB test | `migrate:fresh --seed` sur **MySQL** (pas sqlite) | ≥ 2 branches non-zéro |
| G4 | Redis | `PING→PONG`, queue=redis pour outbox | `redis-cli ping` |
| G5 | Soketi | up `127.0.0.1:6001`, handshake WS | `curl :6001/app/app-key` |
| G6 | Build | `npm run production` exit 0 | `public/mix-manifest.json` |
| G7 | Surfaces | POS/borne/KDS/OSS 200/302 | `curl -sI :8000` |
| G8 | Harnais | Chromium `/opt/pw-browsers`, baseline | `playwright test --list` |
| G9 | Comptes | 4 identités authentifient | login helper |
| G10 | Fiscal | secrets ≥32c hors sentinelles, HMAC init | Z-report test signé |
| G11 | Visuel | 4 captures baseline (une/surface) | `reports/antigravity/screens/` |

**Sortie exigée : 11/11.** 10/11 = Phase 0 rouge.

## 0.2 Séquence ordonnée & idempotente

1. **Composer** : `composer install --no-interaction` (depuis le lock, **jamais** `update`). Vérifier extensions de `config/installer.php` (openssl, pdo, mbstring, bcmath, zip…).
2. **npm** : `npm ci` (respecte package-lock). **Ne pas** relancer `playwright install` — Chromium déjà sous `/opt/pw-browsers`, router via `PLAYWRIGHT_BROWSERS_PATH`.
3. **DB MySQL de test** (décision non négociable) : `phpunit.xml` force `sqlite :memory:` → **masque** FK, verrous (`lockForUpdate`), types (`DECIMAL`/`ENUM`/`JSON`). Migrer vers connexion `mysql_testing`. Seed multi-branches via `BranchTableSeeder`, `KioskMachineTableSeeder`, `CompleteFrenchMenuSeeder`, `UserTableSeeder`, `TaxTableSeeder`, `RolePermissionTableSeeder`. **≥ 2 branches non-zéro** pour rendre l'isolation testable. `migrate:fresh --seed` à chaque cycle (pas d'état résiduel).
4. **Redis + fin de `QUEUE=sync`** : `sync` (phpunit **et** .env.example) exécute les jobs dans la transaction → outbox jamais async → invariant **non prouvé**. Profil intégration : `QUEUE=redis` + `php artisan queue:work redis`.
5. **Soketi** : `npx soketi start --config=soketi.json`. Piège : `phpunit.xml` vide **volontairement** les creds Pusher (court-circuit unit voulu) ; les e2e temps réel (KDS reçoit l'événement POS) exigent des creds **non vides** → vérifier le handshake WS **avant** de conclure à un bug applicatif.
6. **Build** : `npm run production` (mix). Rebuild **obligatoire** avant capture, sinon `mix-manifest.json` périmé → faux négatifs visuels.
7. **Surfaces** : `php artisan serve --port=8000`. Backend commun, distinction par route+rôle. Borne : `KIOSK_REQUIRE_MACHINE_LOGIN=false` cache l'auto-login → forcer `true` en test pour rendre le token machine **observable** (finding token borne=super-admin).
8. **Playwright** : `PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers PLAYWRIGHT_BASE_URL=http://localhost:8000 npx playwright test`. 6 specs existent (auth-refresh, pos-cash, kiosk-wizard, kds-status, pos-card, staff-only). **Changer** `screenshot: 'only-on-failure'` → `'on'` : « only-on-failure » ne prouve **rien** en succès.
9. **Comptes** : `caissier@` / `chef@` / `customer@` (payloads présents) + machine `kiosk-lecayenne`/`kiosk123`. ≥ 1 compte branche A, 1 branche B (fuites cross-branche).
10. **Fiscal NF525** : secrets ≥32c hors `dev_sentinels` (fournis par phpunit, 48c). Vérifier chaînage HMAC + signature Z-report de test.

## 0.3 Trois `.env` strictement séparés

| Fichier | DB | QUEUE | Pusher |
|---------|----|-------|--------|
| `.env` dev/surfaces/e2e | mysql `foodking` | redis | soketi **non vides** |
| `phpunit.xml` unit/Feature | **sqlite→`mysql_testing`** | sync toléré | **vides** (voulu) |
| profil intégration outbox | `mysql_testing` | redis+worker | soketi non vides |

**Pièges :** (1) drift sqlite↔MySQL (FK/verrous/types) ; (2) `QUEUE=sync` masque l'outbox ; (3) secrets — jamais committer un `.env` réel, Stripe/PayPal en **sandbox**, jamais clé prod (finding « clé GCP servie »), creds machine hors prod.

## 0.4 Checklist reproductible (`scripts/phase0-up.sh` à créer)

`composer install → npm ci → MySQL test up → migrate:fresh --seed (≥2 branches) → redis ping → soketi :6001 → npm run production → serve :8000 → 4 logins → Z-report signé → 4 captures baseline`
→ **11/11 = GREEN (boucle autorisée)** ; sinon **RED (boucle gelée)**.

## 0.5 Garde-fous : invariants rendus testables

1. **Pricing backend seule vérité** → prix client injecté **ignoré** par l'API.
2. **Isolation branche** → token A ne lit/écrit rien de B ; `branch_id==0` ≠ god-mode.
3. **Transitions statut** → transitions interdites **rejetées** (KDS).
4. **Atomicité outbox** → redis+worker : échec ne laisse ni orphelin ni PAID rejeté.
5. **NF525** → altérer une ligne casse le HMAC **de façon détectable**.

> Sans Phase 0 verte, ces cinq garde-fous sont des affirmations **non prouvées**. Toute la discipline de la mission repose sur ce socle.


---

# Pilier 1 — Architecture de la boucle de remédiation

## 1. L'unité de travail : la « cellule »

La boucle ne s'exécute jamais sur « le monorepo » en bloc, mais sur une **cellule** : le plus petit invariant vérifiable de bout en bout. Une cellule = *(1 finding critique) × (1 flux utilisateur) × (1 système)*.

Exemple : `C-PRICING-01 = « le backend recalcule le prix, le client ne peut pas l'imposer » sur le flux commande-borne`. Chaque cellule possède une fiche `cells/C-XXXX.yml` :

| Champ | Contenu |
|---|---|
| `id` | `C-PRICING-01` |
| `invariant` | backend = seule vérité pricing |
| `systems` | kiosk, backend, KDS |
| `finding_refs` | F-03, F-11 (reports/audit) |
| `intersections` | C-LOYALTY-02, C-OUTBOX-04 |
| `oracle` | assertion chiffrée attendue |
| `state` | todo / doing / validating / done / blocked |

## 2. Machine à états de la cellule

```
 todo ──pris──▶ doing ──fix+preuve──▶ validating
   ▲                                     │
   │                              gate adversariale
   │                             ┌───────┴────────┐
   └──── rollback (test cassé)   │                │
   ▲                            PASS            FAIL
   └──────────── blocked ◀──3 heals / risque──── │
                                 │                │
                               done ◀────────────┘(re-boucle)
```

**Conditions de passage (dures) :**
- `todo→doing` : cellule non bloquée par une dépendance amont (`intersections` en `done` ou neutralisées).
- `doing→validating` : correctif écrit **et** les 8 preuves de la §3 produites et horodatées.
- `validating→done` : **toutes** les portes vertes (unit+intégration+e2e+visuel+adversariale) ET invariant re-prouvé par un test dédié.
- `*→blocked` : 3 cycles de heal consécutifs, ou contradiction avec un invariant, ou preuve trop faible → escalade humaine (règle CLAUDE.md §8).
- `validating→doing` (**rollback**) : un test rouge => `git revert` du patch de cellule, retour cause racine.

## 3. Anatomie d'UNE itération (8 étapes)

1. **Audit ciblé** — rejouer l'audit statique *sur le périmètre de la cellule seulement* (`rg`, PHPStan ciblé, lecture des routes/controllers concernés). Sortie : symptômes confirmés + fichiers exacts.
2. **Breakdown / cause racine** — construire l'**arbre des causes** (5-why), pas les symptômes. Distinguer : cause de code / cause de contrat / cause d'architecture / cause de données. Livrable : `cells/C-XXXX/rootcause.md` avec nœud racine unique.
3. **Conception du correctif + impact invariants** — matrice `correctif × 5 invariants` (pricing, isolation branche, transitions statut, NF525, atomicité outbox). Interdit de coder si un invariant passe au rouge sans mitigation.
4. **Implémentation** — patch minimal, atomique, une branche `fix/C-XXXX`. Exécuté par Cursor (rôle exécuteur), périmètre gelé hors cellule.
5. **Re-audit** — relancer l'étape 1 : le symptôme doit avoir disparu ET aucun nouveau finding statique introduit (diff PHPStan/ESLint = 0 régression).
6. **Tests unitaires + intégration** — `php artisan test --filter=CXXXX` + tests d'invariant dédiés (ex. `PricingSsotTest`, `BranchIsolationTest` déjà présents dans `tests/Feature/`). Vert obligatoire.
7. **E2E Playwright** — scénario du flux réel dans `tests/e2e/` (`playwright.config.js`, baseURL `http://localhost:8000`). Le test **rejoue l'attaque** décrite par le finding (ex. POST prix falsifié → 422/prix recalculé).
8. **Test visuel + captures avant/après** — screenshot forcé (`screenshot:'on'`, pas `only-on-failure`) stocké `reports/antigravity/shots/C-XXXX/{before,after}.png` ; comparaison + assertion d'état visible (badge, total affiché == total backend).

## 4. Porte de validation adversariale (gate final)

La cellule n'est **validée à 1000%** que si un « avocat du diable » automatisé + Claude tentent de la **casser** :
- rejouer le finding original → doit échouer côté attaquant ;
- tester les **bords** : `branch_id=0`, token borne, montant négatif, statut hors transition, double outbox ;
- vérifier les **intersections** listées (n'a-t-on rien cassé chez les voisines ? re-run de leur suite) ;
- exiger la **preuve** (règle CLAUDE.md §11) : logs, captures, sortie de test — jamais la confiance seule.

Verdict : `continue` / `heal` / `block` / `escalate` / `human`. Un seul `BLOCKED` empêche `done`.

## 5. Garde-fous et pièges

- **Piège « tests verts = fini »** : un vert peut masquer logique incomplète → l'oracle chiffré et le rejeu d'attaque sont obligatoires (CLAUDE.md §7).
- **Piège des intersections** : corriger A casse B → toute cellule `done` re-passe un smoke des voisines avant clôture.
- **Piège de portée** : Cursor ne doit pas élargir le scope ; diff hors `cells/C-XXXX/files` = rejet.
- **Rollback discipliné** : un test rouge ne se « patche » pas en aval, il revient à l'arbre des causes (étape 2).
- **Traçabilité** : chaque transition d'état écrit un événement dans `cells/C-XXXX/journal.jsonl` (audit rejouable).
- **Pré-requis dur** : sans Phase 0 (deps, DB test, redis, soketi, harnais captures), les étapes 6-8 n'existent pas → la cellule reste `blocked`.

> Règle d'or : la boucle tourne **sans limite d'itérations** sur une cellule jusqu'à validation adversariale, mais **jamais plus de 3 heals** sans escalade humaine.


---

# Pilier WBS — Décomposition exhaustive en cellules de travail

## 0. Convention de cellule (contrat unique)
Chaque cellule = `WBS-<SYS>-<NN>`. Format obligatoire dans le plan maître :

| Champ | Contenu attendu |
|---|---|
| ID | `WBS-PRC-03` |
| Fichiers cibles | chemins réels (ex. `app/Services/Fiscal/*`, `app/Domain/Order/OrderStateMachine.php`) |
| Invariant gardé | Pricing-backend / Isolation-branche / Transitions / NF525 / Outbox-atomique |
| Finding(s) liés | n° du lot des 40 findings |
| Preuve exigée | unit + intégration + e2e `tests/e2e/NN-*.spec.js` + capture `reports/antigravity/shots/` |
| Critère chiffré | ex. « 0 champ prix accepté du client », « couverture ≥ 90% du service » |

> Garde-fou : aucune cellule n'est « done » sans les **4 preuves** (unit, intégration, e2e, capture) + passage adversarial.

## 1. Cellules par système (13 + caisse + borne)

- **CMD/Fiscal (WBS-FIS)** : cycle de vie `Order`/`OrderItem`, `OrderStatusTransition`, `ZReport`, chaîne HMAC NF525 (`app/Services/Fiscal/*`, `app/Models/ZReport.php`). Cellules : intégrité signature, clôture Z, inaltérabilité, chaînage jeton précédent.
- **Pricing (WBS-PRC)** : SSOT backend (`docs/PRICING_SSOT.md`). Cellules : recalcul serveur total/TVA/remise, rejet de tout prix client, addons/variations/extras (`ItemAddon`,`ItemVariation`,`ItemExtra`), arrondi.
- **AuthZ/Branches (WBS-AUZ)** : Sanctum+spatie, `AUTHZ_MATRIX.md`, `Scopes/`. Cellules : bug `branch_id==0=admin`, token borne ≠ super-admin, scope global de branche sur chaque modèle.
- **API (WBS-API)** : `routes/api.php`, `app/Http/Controllers/{Admin,Frontend,Installer,Table}`. Cellules : Installer non authentifié, rate-limits (`RATE_LIMITS_MATRIX.md`), validation d'entrée par FormRequest.
- **Sync/Événements (WBS-SYN)** : `DomainEvent`, `OUTBOX_PATTERN.md`, `EVENT_CONTRACT.md`, soketi. Cellules : outbox atomique, echo, reconnexion, offline borne.
- **POS-admin/Caisse (WBS-POS)** : `MANUEL_CAISSIER.md`, `tests/e2e/02-pos-cash.spec.js`,`05-pos-card.spec.js`. Cellules : encaissement espèces/carte, rendu monnaie, cleanup rejetant des PAID, session caisse.
- **Borne/Kiosk (WBS-KSK)** : `borne (Remix)/`, `kiosk_implementation/`, `app/Services/Kiosk`, `KioskMachine`,`KioskPromo`. Cellules : paiement déclaré-client, `03-kiosk-wizard.spec.js`, promo affichée non appliquée, reconnexion.
- **KDS/OSS/Tables (WBS-KDS)** : `KitchenDisplaySystemOrderService`, `DiningTable`,`Table/`. Cellules : filtre branche exact, 86 non chargé, transitions cuisine, `04-kds-status.spec.js`.
- **DB (WBS-DB)** : 105 migrations, index, FK, soft-delete (`SOFT_DELETE_POLICY.md`).
- **Sécurité (WBS-SEC)** : clé GCP servie, secrets (`FISCAL_SECRETS.md`), fidélité/consentement (`LoyaltyConsent`).
- **Tests (WBS-TST)** / **Structure (WBS-STR)** / **Docs (WBS-DOC)** : harnais, dette structurelle, contrats docs↔code.

## 2. Matrice d'intersections à auditer (feature × feature)
Chaque case cochée = **1 cellule d'intersection dédiée** avec test d'intégration propre.

| ↓ vs → | Fiscal | Paiement | Annul/Rembours | 86/Dispo | KDS | Sync/Reco |
|---|---|---|---|---|---|---|
| **Remise/Promo** | remise×NF525 (signature recalculée) | remise×capture | remise×remboursement partiel | — | — | promo×echo |
| **Paiement** | ticket×Z | — | paiement×annulation×remboursement (triangle) | — | statut×KDS | capture×reconnexion |
| **Fidélité** | points×TVA | gain/débit×paiement | reprise points au rembours | — | — | solde×offline |
| **86 (rupture)** | — | blocage encaissement | annule ligne 86 | 86×panier×KDS | retrait ticket cuisine | 86×disponibilité×reco |
| **Branche** | Z par branche | gateway par branche | — | dispo par branche | filtre KDS exact | scope×événement |
| **Offline borne** | file fiscale différée | paiement en attente | — | dispo périmée | — | rejeu outbox idempotent |

> Triangle critique **paiement × annulation × remboursement** : à tester dans les 6 ordres possibles (états `OrderStateMachine`) pour prouver l'absence de double-remboursement / PAID effacé.

## 3. Synchronisation, DB & Historique (transverses obligatoires)
- **SYNC** : WBS-SYN-outbox (écriture état+événement dans **une** transaction, sinon rollback), -echo (déduplication par event_id), -reco (rejeu ordonné idempotent), -offline (file borne + réconciliation au retour).
- **DB** : WBS-DB-migr (105 migrations rejouables `migrate:fresh` en base de test), -idx (index sur `branch_id`,`order_id`,`status`), -fk (intégrité + ON DELETE), -soft (soft-delete cohérent avec scopes & unicité).
- **HISTORIQUE** : WBS-HIS-hmac (chaîne `AuditLog`/`ZReport` vérifiable), -zreport (totaux == somme transactions), -actionlog (`ActionLog` isolé par branche — cf. `ActionLogBranchIsolationTest`), -deletion (`DeletionLog` trace toute suppression).

## 4. Pièges & garde-fous
1. **Faux « done » sur tests verts** : exiger la capture visuelle + case adversariale (CLAUDE.md §7).
2. **Intersections oubliées** : la matrice §2 est la checklist ; aucune feature ne se valide seule.
3. **Scope de branche contourné** par requête directe : tester via 2 branches réelles, pas via mock.
4. **Outbox non-atomique** masqué en local mono-thread : test avec Redis + soketi réels (Phase 0).
5. **NF525** : toute correction de pricing/remise **doit** re-signer et re-vérifier la chaîne.
6. **Idempotence reconnexion** : rejouer le même event 2× ne doit jamais dupliquer une transaction.

## 5. Traçabilité vers les invariants
Chaque cellule pointe vers ≥1 invariant et le rend **vérifiable** par un test nommé : Pricing→`FrontendDiscountIntegrityTest`, Isolation→`BranchIsolationTest`/`KdsBranchFilterExactTest`, Transitions→`OrderStateMachine` + `IllegalTransitionException`, NF525→`tests/Feature/Fiscal`, Outbox→`EventContractTest`. Toute cellule sans test correspondant crée d'abord ce test (WBS-TST).


---

# Pilier — Portes de validation « 1000% » : définition stricte & mesurable

> Sans définition **mesurable** de « validé à 1000% », la boucle ne termine jamais (ou termine par complaisance). Ce pilier fige les critères de sortie, les agents adversaires et le score objectif. Il s'ancre dans la doctrine existante (`docs/GATES_DOCTRINE.md`, `docs/AI_CHANGE_GATES.md`) sans la remplacer.

## 1. Unité de validation : la « cellule »
Une **cellule** = un couple `(fonctionnalité × système)` ou une **intersection** (ex. `Panier×Borne`, `Sync commande Borne→KDS`, `NF525×Clôture caisse`). Chaque cellule a un ID stable `CELL-<sys>-<feat>` et sa propre porte. Une cellule ne peut passer que si **TOUS** les critères ci-dessous sont verts simultanément dans **la même** exécution CI (pas de verts glanés sur des runs différents).

## 2. Critères de sortie PAR CELLULE (les 7 verrous)
| # | Verrou | Preuve exigée (artefact) | Seuil |
|---|--------|--------------------------|-------|
| 1 | Audit propre | re-audit statique de la cellule, 0 finding sévérité ≥ high | 0 critique / 0 high |
| 2 | Unitaire | `tests/Unit/**` liés | 100% verts, ≥1 test/nouveau chemin |
| 3 | Intégration | `tests/Feature/**` (MySQL, pas SQLite) | 100% verts + assertion d'**état DB** |
| 4 | E2E | `tests/e2e/**` via `playwright.config.js` | flux nominal + ≥1 flux d'échec |
| 5 | Visuel | capture horodatée `reports/antigravity/<CELL>/*.png` + diff pixel vs baseline | diff ≤ 0.1% zone métier |
| 6 | Invariant prouvé | 1 test dédié par invariant touché (voir §5) | rouge SANS le fix, vert AVEC |
| 7 | Confirmations adversariales | N=2 sceptiques indépendants + 1 juge, verdicts signés en JSON | 3/3 concordants |

**Règle de fraîcheur** : les 7 preuves doivent porter le **même `commit_sha`**. Toute preuve d'un SHA antérieur est invalide.

## 3. Portes de niveau PRODUIT (gate global de release)
La mission n'est « validée à 1000% » que si, sur `main` :
1. **Zéro critique/high ouvert** sur l'ensemble des cellules (agrégé).
2. **Couverture des flux critiques** : les 4 systèmes (POS, Borne, KDS, OSS) ont chacun leur parcours de bout en bout vert (commande→paiement→KDS→clôture).
3. **Non-régression visuelle** : suite de baselines complète, 0 diff non justifié.
4. **Invariants sous test** : les 8 invariants ont un test « garde » actif et rouge-si-cassé.
5. **CI verte bloquante** : `.github/workflows/phpunit.yml` + `playwright.yml` verts, `required` sur la branche, merge interdit sinon.
6. **Aucune cellule en healing** depuis > 3 cycles (sinon escalade humaine, cf. CLAUDE.md §8).

## 4. Rôle des agents adversaires à chaque porte
- **Sceptique (Red-Team)** : son but est de **casser**, pas de confirmer. Il produit un `break_attempt.json` : rejoue le flux avec entrées hostiles (prix négatif, `branch_id=0`, token borne réutilisé, montant payé falsifié, double-submit outbox). Une cellule ne passe que si **toutes** ses tentatives échouent à casser l'invariant.
- **Juge (Arbiter)** : indépendant du correcteur et du sceptique. Il tranche `PASS/HEAL/BLOCK/ESCALATE` en relisant **les artefacts uniquement** (pas la parole du correcteur). Il vérifie fraîcheur SHA, présence des 7 verrous, et cohérence capture↔état DB.
- **Indépendance** : le correcteur ne peut être ni sceptique ni juge de sa propre cellule. Les verdicts sont horodatés et versionnés dans `reports/review/<CELL>/`.

## 5. Invariants → tests « garde » (mapping)
| Invariant | Test garde (rouge si cassé) |
|-----------|-----------------------------|
| Backend = seule vérité prix | `Feature/Pricing/BackendReprice` : ignore prix client, recalcule, compare |
| Isolation branche | `Feature/Tenancy/BranchScope` : `branch_id=0` ≠ admin, cross-branch = 403 |
| Transitions statut | `Unit/Order/StatusMachine` : transitions illégales rejetées |
| Intégrité NF525 | `Feature/Fiscal/Nf525Chain` : hash chaîné + refus mutation PAID |
| Atomicité outbox | `Feature/Outbox/AtomicEmit` : rollback ⇒ 0 event émis |

## 6. Anti-complaisance — INTERDITS (échec automatique de la porte)
- ❌ Asserter `200`/`assertOk` **sans** vérifier l'état persistant (DB/event/fiscal).
- ❌ Test « tolérant » qui passe en succès **ET** en échec (`assertTrue(true)`, `try/catch` avalant l'erreur, absence d'assertion).
- ❌ Capture d'écran non diffée contre baseline, ou sur page d'erreur maquillée.
- ❌ `screenshot: only-on-failure` seul comme preuve visuelle (exiger capture **du succès**).
- ❌ Skip conditionnel masquant une régression (ex. auto-skip hors MySQL sur un test d'invariant).
- ❌ Mock du service dont on prétend prouver la correction.
- ❌ Preuves de SHA différents recomposées en « faux vert ».

## 7. Score de validation objectif (0–1000)
Par cellule : `Score = Σ poids(verrou)` avec plafond conditionnel.
| Verrou | Poids |
|--------|------:|
| Audit propre | 150 |
| Unitaire | 100 |
| Intégration+état | 200 |
| E2E (nominal+échec) | 150 |
| Visuel diffé | 100 |
| Invariant prouvé | 200 |
| 3/3 adversarial | 100 |

**Règle de plafond dur** : si **un** verrou critique (Intégration-état, Invariant, ou 1 INTERDIT §6 détecté) manque, `Score = 0` (pas de moyenne indulgente). Une cellule est **validée** ssi `Score = 1000`. La mission est **validée à 1000%** ssi `min(Score cellules) = 1000` **ET** les 6 portes produit §3 sont vertes.

## 8. Pré-requis bloquant (dépendance Phase 0)
Les verrous 3/4/5 sont **inexécutables** tant que `vendor/` et `node_modules/` sont absents. Tant que Phase 0 (deps, MySQL test, redis, soketi, harnais Playwright `/opt/pw-browsers`) n'est pas verte, toute cellule reste **BLOCK**, jamais « validée par défaut ».


---

# PILIER — Flux d'agents parallèles & orchestration (sans conflit)

> **Usine à corrections en boucle** : plusieurs agents en parallèle sans se marcher dessus, jusqu'à **file de cellules vide ET tous les gates verts**. L'isolation prime sur la vitesse.

## 1. La « cellule » = unité atomique
Rien ne circule hors d'une **cellule** (`reports/planning/cells/CELL-<id>.json`), champs : `id` stable ; `finding_refs`/`invariant` (`pricing_backend`, `branch_isolation`, `status_transitions`, `nf525`, `outbox_atomicity`) ; `stream` (A/B/C/D) ; `owns_paths` (globs possédés, verrou exclusif) ; `depends_on`/`barrier` ; `state` (`queued→claimed→in_progress→in_review→gated→merged`\|`failed`\|`blocked`) ; `heal_count` (max 3→escalade) ; `evidence`.

**Garde-fou #1 :** pas d'attribution si `owns_paths` intersecte une cellule active (collision détectée *avant* le travail).

## 2. Les 4 STREAMS parallèles
Chaque stream = un implémenteur + un **agent adversaire** qui essaie de le casser ; convergence aux barrières (§4).

- **A — Raisonnement.** 40 findings → cellules atomiques (5-why), oracles chiffrés, mapping invariant→test. **Ne code pas.** In: `reports/audit/*`, `docs/BUSINESS_RULES.md`, `ORDER_FLOW.md`, `AUTHZ_MATRIX.md`. Out: cellules + `dep-graph.json`. Adversaire `root-cause-adversary` : rejette tout symptôme, 1 invariant/cellule.
- **B — Backend.** Pricing recalculé serveur, authz (branch_id≠0, token borne≠super-admin), NF525, cleanup PAID, Installer auth, clé GCP. Paths: `app/Services`, `app/Http`, `app/Domain/Order`, `routes`, `app/Rules`, `app/Enums`. Adversaire `security-authz-adversary` : rejoue `price=0`, `branch_id=0`, token rejoué → **CONFIRMED** si backend écrase le client et refuse le cross-branch.
- **C — UI/UX.** Vue3/Vuex POS/borne/KDS/OSS : fidélité/promo *affichées ET appliquées*, 86 chargé, états visibles corrects. Paths: `resources/js/**`, `borne (Remix)/**`, `kiosk_implementation/**`. Adversaire `visual-ux-adversary` : affiché == vérité backend, diff de captures.
- **D — Sync/DB.** Atomicité outbox, temps réel soketi/Pusher, transitions contrôlées, migrations, audit trail, idempotence jobs. Paths: `app/Jobs`, `app/Events`, `app/Listeners`, `app/Observers`, `database/migrations`, `soketi.json`. Adversaire `sync-race-adversary` (`.agents/skills/sync-risk-review`) : crash DB↔event, double livraison, transition interdite → **CONFIRMED** si exactly-once.

**Garde-fou #2 :** un stream ne touche jamais hors de son domaine ; besoin cross-domaine = **cellule dépendante**.

## 3. Isolation worktree & propriété des fichiers
Un **worktree par cellule active**, jamais deux agents dans le même arbre.
```bash
git worktree add ../fk-wt/CELL-0007 -b fix/CELL-0007 integration   # travail dans owns_paths seulement
git -C ../fk-wt/CELL-0007 rebase integration                       # rebase, pas merge commit
git checkout integration && git merge --ff-only fix/CELL-0007 && git worktree remove ../fk-wt/CELL-0007
```
Règles : (1) verrou exclusif/glob dans `ownership.lock.json` ; (2) **hotspots sérialisés** (`routes/api.php`, `config/*`, store Vuex racine, migrations) jamais parallèles ; (3) migrations à timestamp → sérialisées via `depends_on` ; (4) rebase + `--ff-only` → conflits tôt, `git blame` par cellule.

**Garde-fou #3 :** Vuex/routes/config = cellule **sérialisée** + B1 immédiate. **Garde-fou #4 :** seule `integration` est cible de merge ; `main` intouchée ; re-audit sur `integration` assemblée.

## 4. Barrières (rendez-vous ordonnés)
- **B0 cause-racine :** 0 symptôme, 1 invariant/cellule, oracle défini.
- **B1 build/lint :** `composer install`, `npm run production`, `php -l`, ESLint : 0 erreur.
- **B2 sécurité :** adversaires B+D **CONFIRMED** (pricing, isolation, transitions, NF525, outbox).
- **B3 e2e/visuel :** Playwright vert + captures + diff accepté, **sur `integration` assemblée**.
- **B4 re-audit :** 8/8 invariants VERTS, 0 finding critique rouvert.

**Garde-fou #5 :** B3 sur `integration` assemblée → teste chaque fonctionnalité **et ses intersections**. **Garde-fou #6 :** B1/B3 impossibles avant la **Phase 0** (vendor/, node_modules/, DB test, redis, soketi, Playwright `/opt/pw-browsers`) ; interdit de simuler du vert.

## 5. Gates (build→unit→integration→e2e→visuel→adversarial)
1. **Build** `composer install && npm run production`. 2. **Unit** `phpunit --testsuite=Unit` + `vitest run`, ≥80 % lignes modifiées. 3. **Integration** `phpunit --testsuite=Feature`. 4. **E2E** `npx playwright test`. 5. **Visuel** captures `before/after` → `reports/antigravity/screens/` (*preuve = capture*). 6. **Adversarial** tous CONFIRMED ; un `PLAUSIBLE` non levé bloque.
> Merge seulement si tout vert **ET** adversaires CONFIRMED **ET** preuves attachées. Les tests verts ne suffisent jamais (CLAUDE.md §3.10).

## 6. La BOUCLE
```
tant que (queue non vide) OU (cellules != merged) :
  DISPATCH : 'queued' dont depends_on='merged' et sans collision -> claimed
  FAN-OUT  : streams A/B/C/D parallèles (worktrees isolés, ≤3/stream)
  BARRIÈRES B1->B2->B3 ; GATES build..adversarial
  DÉCISION : vert+CONFIRMED -> merge ff-only 'merged'
             rouge -> 'failed', heal_count++, RE-INJECTION (même id)
             heal>3 -> ESCALADE (human) ; contradiction -> surface + human
  RE-AUDIT : lot fusionné -> audit sur integration -> findings = nouvelles cellules
# sortie SI queue vide ET 0 cellule non-merged ET B4 : 8/8 verts
```
Rôles : orchestrateur = Claude (`app-planner-orchestrator`) ; implémenteurs = Cursor ; validateur = `app-validator` + `.agents/skills/qa-loop`. État : `queue.json`, `cells/*`, `ownership.lock.json`, `dep-graph.json`. Pièges : collision → lock+rebase ; état non assemblé → e2e sur `integration` ; livelock → cap 3 healings ; contradiction règle stable → block/escalate/human.


---

# Pilier — Stratégie e2e Playwright + régression visuelle (captures = preuve)

## 1. Principe directeur
La capture **avant/après** est la preuve opposable qu'une correction est réelle et visible. Aucun finding UI n'est « corrigé » sans : (a) test e2e qui **échoue avant** le fix, (b) même test **vert après**, (c) baseline + diff sous seuil. Passer les tests ≠ preuve (CLAUDE.md §7).

## 2. Socle technique (durcir l'existant)
`playwright.config.js` est trop permissif (`screenshot: only-on-failure`, `video: off`, smoke-only). Durcissement :

| Réglage | Valeur | Raison |
|---|---|---|
| `use.trace` | `on` (retain en CI) | rejouer chaque étape |
| `use.video` | `retain-on-failure` | preuve comportementale |
| `use.screenshot` | manuel aux étapes clés | captures ciblées |
| `maxDiffPixelRatio` | `0.01` | tolérance antialiasing |
| `threshold` pixel | `0.2` | bruit sub-pixel |
| `projects` | `chromium` POS paysage + borne `1080x1920` | 2 formats |
| `PLAYWRIGHT_BROWSERS_PATH` | `/opt/pw-browsers` | navigateur pré-installé |
| `locale/timezone/scale` | `fr-FR`/`Europe/Paris`/`1` | captures déterministes |

Masquer via `mask:` horloge, n° commande, QR pour éviter les faux diffs.

## 3. Données déterministes (pré-requis Phase 0)
- `E2ESeeder` : 2 branches (A/B) pour l'isolation, caissier **avec** droit remise + caissier **sans**, chef KDS, catalogue à prix TTC connus, 1 article **86** (rupture), 1 client fidélité à solde connu, 1 promo active.
- Base isolée `foodking_e2e` + re-seed avant chaque suite ; horloge gelée (`Carbon::setTestNow` via route de test).
- `global-setup` : migrate:fresh + seed + démarrage `php artisan serve`, redis, soketi (`soketi.json`) ; `storageState` par rôle (login réutilisé).
- **Garde-fou** : refuser si `APP_ENV!=testing`.

## 4. Parcours e2e critiques

### POS (caisse)
| # | Parcours | Assertions clés | Capture |
|---|---|---|---|
| P1 | Ouverture session | surface `/admin/pos` montée, fond caisse | `pos-open` |
| P2 | Panier + article | total **TTC recalculé backend** | `pos-cart` |
| P3 | Remise **autorisée** | remise + ligne visible | `pos-discount-ok` |
| P4 | Remise **refusée** | bouton grisé/403, aucune remise | `pos-discount-denied` |
| P5 | Espèces + rendu | rendu correct, ticket | `pos-cash-change` |
| P6 | Carte | statut payé après retour PSP | `pos-card-paid` |
| P7 | Annulation + remboursement | `refunded`, fidélité/stock restaurés | `pos-refund` |
| P8 | Z-report | totaux = somme ventes, **NF525** intègre | `pos-zreport` |

### Borne (kiosk)
| # | Parcours | Assertions | Capture |
|---|---|---|---|
| B1 | Menu | catégories chargées | `kiosk-menu` |
| B2 | Article **86** | tuile **grisée + non cliquable** | `kiosk-86-disabled` |
| B3 | Panier | total TTC serveur | `kiosk-cart` |
| B4 | Fidélité/promo | **prix réduit réellement appliqué** (pas juste affiché) | `kiosk-loyalty-applied` |
| B5 | Paiement | montant = total serveur (jamais déclaré client) | `kiosk-pay` |
| B6 | Abandon + cleanup | non-payé nettoyé, **PAID jamais supprimé** | `kiosk-cleanup` |
| B7 | Reset client suivant | panier/session vidés, pas de fuite | `kiosk-reset` |

### KDS / OSS
K1 réception **temps réel** (soketi) < 3 s ; K2 transitions contrôlées (pending→preparing→ready) ; K3 OSS **lecture seule** (aucune mutation).

### Cross-système (synchronisation)
- X1 commande **borne → visible KDS** temps réel (preuve outbox/broadcast).
- X2 reconnexion soketi (coupure simulée) → rattrapage d'état, pas de doublon.
- X3 isolation : caissier A ne voit **jamais** commandes B (capture liste vide).

## 5. Régression visuelle : baseline, diff, seuils
1. Baseline validée : `npx playwright test --update-snapshots`, **revue humaine** des PNG avant commit dans `tests/e2e/__screenshots__/`.
2. Itérations : `expect(page).toHaveScreenshot('kiosk-86-disabled.png')` compare pixel-à-pixel.
3. Seuil `maxDiffPixelRatio ≤ 0.01` sinon échec → `-diff.png` attaché.
4. Trace + vidéo archivées dans `reports/antigravity/` pour audit adversarial.

## 6. Preuve visuelle par invariant (finding → assertion)
| Invariant / finding | Preuve exigée |
|---|---|
| Pricing = backend | capture panier + **interception réseau** : réponse serveur = total affiché |
| 86 non chargé | tuile grisée `[disabled]` + snapshot `kiosk-86-disabled` |
| Promo affichée non appliquée | prix réduit **au paiement**, pas seulement au libellé |
| Isolation branch | liste KDS branche B = vide pour user A |
| Cleanup PAID | après abandon, PAID toujours présent (capture + assert DB) |
| Total TTC NF525 | ticket/Z-report = somme lignes taxées |

## 7. Pièges & garde-fous
- **Faux verts** : le smoke actuel (`body.length>100`) ne prouve rien → assertions **fonctionnelles + réseau** obligatoires.
- **Flakiness soketi** : attendre l'événement (`waitForResponse`/locator), jamais `waitForTimeout` fixe.
- **Screenshots non déterministes** : masquer IDs/horaires, figer timezone/locale/fonts.
- **Trust client** : croiser **capture ⇄ payload réseau ⇄ état DB** (triple preuve).
- **Baseline empoisonnée** : jamais `--update-snapshots` automatique en boucle ; revue humaine (human gate §8).

## 8. Critères de sortie (gate visuel)
Validé **1000 %** seulement si : 100 % des parcours P/B/K/X verts ; 0 diff visuel > seuil non justifié ; trace+vidéo+captures présentes par finding ; chaque invariant prouvé par la triade capture/réseau/DB. Sinon → `heal` ou `block`.


---

# Pilier — Séquencement, dépendances, garde-fous & git

## 1. Principe d'ordre
On ne traite pas les 216 findings un par un : on tue les **5 causes racines** (rapport 03 §9), dans un ordre où chaque étape **arrête une hémorragie active** (argent, fuite, prise de contrôle) sans ouvrir une brèche que la suivante refermera. **Sécurité/fiscal d'abord, cache/perf en dernier.** Rien de la couche `needs_env` n'est « clos » sans build+test+PSP réels (Phase 0).

## 2. Ordre d'attaque des cellules (load-bearing)

| # | Cellule | Neutralise | Contrainte |
|:-:|---|---|---|
| 1 | **`sec-branchscope`** | C07,C12 | **FONDATION.** `branch_id==0 -> hasRole(ADMIN)`, deny-by-default (BranchScope+DefaultAccessModelTrait+channels.php). **Précède TOUTE relâche de cache** — sinon fuite branche via CDN. |
| 2 | `sec-installer` | C28,C29 | Alias `notInstalled` dans Kernel.php **avant** référence web.php/api.php. |
| 3 | `sec-stripe-trunc` | C24 | `(int) round($total*100)` + zéro-décimale + test 12,99→1299. Isolé. |
| 4 | `sec-psp-verify` | C11 | Effort **L**. Jamais `PAID` depuis `transaction_id` client. Exige **sandbox PSP + webhook signé HORS groupe admin**. |
| 5 | `sec-payload-strip` | C01,C05-06,C10,C20-21,C26 | Retrait prix/remise/identité payloads table+kiosk, recalcul serveur `min:0`. Dépend du pricing SSOT. |
| 6 | `sec-gcp-key` | C31 | deny `<FilesMatch>` **avant** bloc cache + `.gitignore` + **rotation ops + purge git** (§4). |
| 7 | `emergency-purge-out` | C17 | Sortir la migration de `migrations/`. Isolé. |
| 8+ | P1 (`token-borne`,`abilities`,`idor`,`broadcast-authz`,`sceau-modèle`,`fenêtre-Z`,`atomicité-argent`) | C02-04,C08,C15-16,C19,C22-23,C25,C27,C30 | Après P0 prouvé. |

### Cellules REJETÉES — re-designer, ne pas appliquer
- **`sec-admin-guard`** : `prefix('admin')` **n'est pas** admin-only (pos, table-order, dashboard, kds, oss). `role:Admin` 403-erait POS/KDS/OSS en prod. → webhook PSP et QR table **hors** groupe gardé ; affiner par permission fine.
- **`perf-htaccess`** : assets non fingerprintés (mix sans `.version()`). `immutable 1 an` fige `app.js/kiosk.js` → clients bloqués sur l'ancien bundle. → re-proposer avec `.version()`/Vite ou politique revalidante.

## 3. Carte de dépendances (arêtes bloquantes)
```
sec-branchscope  ─► débloque tous les perf-* / relâche cache
sec-installer    ─► sec-admin-guard (alias Kernel partagés)
sec-payload-strip─► pricing SSOT (recalcul) ─► remise-POS
sec-psp-verify   ─► atomicité-argent (UNIQUE order_id,type)
sec-gcp-key deny ─► AVANT tout mod_expires
sceau-modèle     ─► fenêtre-Z (immutabilité avant numérotation)
```
**Fichiers-collision** (merger, pas empiler) :
- `public/.htaccess` : (1) deny secrets **en premier** (2) rewrite (3) headers (4) cache **assets uniquement**, jamais `application/json`.
- `routes/api.php` : 3 groupes non imbriqués (admin durci / webhook PSP signé / QR table signed).
- `app/Http/Kernel.php` : `$routeMiddleware`, fusion sans clé dupliquée.

## 4. Garde-fous & décisions humaines (HUMAN GATE)

| Geste | Pourquoi humain | Garde-fou |
|---|---|---|
| **Rotation clé GCP** | `.htaccess`/`.gitignore` **ne rotent pas** le secret exposé | Rotation Google AVANT merge ; clé hors docroot dans `storage/` |
| **Purge historique git** (clé, `kiosk123`, `123456`, payloads) | Réécriture irréversible, casse forks/PR | `git-filter-repo` sur clone dédié, backup bundle, créneau annoncé, force-push coordonné |
| Migration socle PHP/Laravel (P3) | Rupture large | Préprod + CI durcie |
| Verdict `block`→`heal` | Invariant en jeu | 4 tests d'invariant passants (§6) |

**Invariants INTOUCHABLES** (aucune correction ne les affaiblit) : pricing backend, isolation branche, transitions contrôlées, sceau NF525, atomicité outbox. Chacun doit devenir **testable** (§6), pas seulement corrigé.

## 5. Stratégie git
- **1 branche/cellule** depuis `main` : `fix/sec-branchscope`… (jamais depuis `claude/voice-feature-*`).
- **Commits atomiques `1 fix + 1 test`** dans le même commit ; interdit de committer un fix sans son test.
- **PR par lot** : PR P0-sécu (1-3,6,7), PR paiement (4-5), PR P1. Chaque PR liste les `Cxx` + preuves (build/unit/e2e/captures).
- **Ordre de merge = ordre §2** : `sec-branchscope` vert **avant** toute PR cache.
- Trailer `Neutralise: C07,C12` pour la traçabilité findings→commit.

## 6. Non-régression continue
La boucle ne se relance que sur **baseline verte**. Filets :
1. **CI bloquante** : Vitest + PHPUnit exécutés (aujourd'hui non lancés) ; assertions tolérantes (succès ET échec) corrigées.
2. **4 tests d'invariant réels** (sortie de `block`) : (a) backend recalcule tout prix, (b) zéro lecture cross-branch pour un client, (c) commande scellée immuable, (d) `PAID` exige preuve PSP.
3. **Suite e2e/visuelle de référence** (captures Phase 0) rejouée à chaque itération : **diff de captures = détecteur de régression UX** (POS/kiosk/KDS/OSS).
4. **`perf-queue`** : garde `QUEUE!=sync` extraite en méthode testable, listeners intacts + test de non-régression avant relâche.

## 7. Registre de risques & rollback

| Risque | P | Impact | Rollback |
|---|:-:|:-:|---|
| `sec-admin-guard` mal scopé → 403 POS/KDS | H | Critique | Ne pas appliquer ; revert ; permission fine |
| Cache relâché avant branchscope → fuite CDN | M | Critique | `no-store` menu/API ; purge CDN |
| Purge git casse PR/forks | M | Élevé | Backup bundle ; créneau annoncé |
| `perf-htaccess` fige bundles | H | Élevé | `.version()` d'abord ; sinon revert |
| PSP-verify casse checkout | M | Élevé | Feature-flag ; sandbox avant prod |


---

# Durcissement (red-team du plan)

> Faiblesses, angles morts et durcissements identifiés par 3 critiques adversariaux du plan lui-même, à intégrer.

## Angle : CONVERGENCE & DISCIPLINE : le plan definit un critere de SUCCES (« 1000% ») mais aucun critere d'ECHEC/arret ; la regle 
**Faiblesses du plan :**
- Livelock de fraicheur : exiger min(cellules)=1000 au MEME commit_sha signifie que merger la cellule N change le sha et re-invalide 1..N-1. Une regression tardive fait osciller sans jamais atteindre min=1000 simultanement ; re-run O(N^2), terminaison non garantie.
- Pas de critere d'ECHEC : '1000%' est un critere de succes, pas de terminaison. '3 heals max' n'agit qu'en local ; sans budget global temps/iterations la mission reste 'block' indefiniment. L'escalade humaine (seul terminateur) n'est ni definie ni son mecanisme de reprise.
- Gate 'rouge-sans-fix/vert-avec-fix' satisfaisable par un test-garde tautologique assertant exactement le chemin patche : rouge-avant/vert-apres OK mais l'invariant n'est couvert que sur ce vecteur. Prouve le fix, pas l'invariant.
- Juge 'sur artefacts seuls' : captures/logs produits par le stream du correcteur. Sans re-execution independante en env neuf, le juge valide des artefacts stales ou fabriques ; le sha frais ne prouve pas que la capture correspond au code.
- Diff visuel : le masquage des zones non-deterministes est decide par le correcteur, qui peut masquer la zone qui regresse. L'anti-faux-vert impose une revue humaine des baselines mais PAS des masques ni de leur elargissement.
- sec-branchscope (branch_id==0->ADMIN, deny-by-default) pose en 'fondation step 1' sans inventaire des usages actuels de branch_id==0 (seeds, jobs, rapports). Flipper la sentinelle casse en masse des flux non-admin — rupture presentee comme fondation.
- Parallelisme illusoire : quasi tous les 40 findings touchent pricing/authz/outbox = hotspots SERIALISES (Vuex/routes/config/migrations). Le chemin critique est donc sequentiel ; les 4 streams sur-promettent le debit reel.
- Conflit direct : la purge/force-push de l'historique git (cle GCP) re-ecrit tous les SHA et invalide la fraicheur commit_sha + les trailers 'Neutralise:Cxx' deja valides. Et 'mock interdit du service corrige' est inapplicable a Stripe/PayPal sandbox.

**Angles morts / manques :**
- DATA-REMEDIATION absente : corriger le code ne repare pas les lignes deja corrompues (pricing passe, PAID effaces, sceaux NF525 invalides sur l'historique). Aucun backfill/reconciliation/re-sceau ni gate pour les donnees existantes.
- Concurrence outbox non testee : rien sur N workers paralleles, retry, exactly-once vs at-least-once, idempotency keys sur broadcast. 'Double outbox' cite comme cas adversarial sans test de course sous charge.
- Sync offline/reco superficielle : pas de partition reseau reelle, clock-skew borne/backend, delivery soketi out-of-order/dupliquee, ni replay des events perdus. Le gel d'horloge e2e MASQUE justement les bugs de clock-skew.
- Isolation branch au niveau BROADCAST/CACHE non couverte : fuite via canaux soketi (borne B recoit l'echo de A ?), rapports agreges, cle de cache CDN sans branch_id. Le plan ne teste que l'authz par requete HTTP.
- Cumul pricing manquant : remise+promo+fidelite+article 86 SIMULTANES sur meme panier (ordre d'application, base taxable, total negatif). La matrice ne liste que des paires, pas les combinaisons ou le backend explose.
- Triangle remboursement incomplet : pas de remboursement PARTIEL, pourboire, split especes+carte, arrondis/devises — sources classiques de double-remboursement et desync fiscal non incluses dans les 6 ordres d'etats.
- Isolation d'etat e2e : aucun snapshot/restore ni teardown deterministe entre cellules sur foodking_e2e partagee -> contamination d'etat entre scenarios sequentiels, faux verts/rouges.
- Post-incident securite : pas de revocation des tokens borne super-admin deja emis, pas de test rejeu/expiration Sanctum, pas de rotation des secrets des 3 .env. L'Installer est reauth mais le 'deja-exploite' non traite.

**Durcissements :**
- Remplacer 'min(cellules)=1000 au meme sha' par un critere monotone : un run VERT de la suite complete sur le HEAD de release + attestation que chaque test-garde est en CI permanente. Ajouter un budget global declenchant escalade, distinct des 3 heals.
- Juge independant DOIT re-executer en env ephemere from-scratch (seed frais), pas lire des artefacts. Artefacts = sha+hash d'image ; le juge regenere et compare. Interdire tout artefact non reproductible par le juge.
- Test-garde d'invariant = suite de vecteurs (property-based/table-driven, >=3 distincts) + mutation testing sur le code de garde pour prouver qu'il n'est pas tautologique. Un seul cas rouge/vert ne vaut jamais preuve d'invariant.
- Masques visuels et zones ignorees = artefacts versionnes, diffés eux-memes et revus par le juge ; tout elargissement de masque leve un flag. Baselines signees.
- Avant sec-branchscope : inventaire obligatoire (grep exhaustif branch_id==0 dans seeds/jobs/rapports) + shadow-mode log-only pour mesurer l'impact + non-regression sur flux non-admin AVANT enforcement deny-by-default.
- Ajouter des cellules DATA : backfill des lignes corrompues, re-sceau ou marquage legal des ecarts NF525 historiques, reconciliation paiement/DB, avec leur propre gate. Corriger le flux ET les donnees.
- Tests concurrence/chaos : N workers outbox paralleles, injection latence/partition soketi, delivery out-of-order+dupliquee, clock-skew. Idempotency prouvee par rejeu. NE PAS geler l'horloge sur les scenarios sync — la faire deriver.
- Publier un DAG des hotspots, mesurer le debit parallele reel, ajouter une bisection auto d'attribution de blame pour les echecs d'intersection en B3. Ordonner la purge-git EN DERNIER, apres tous les merges, avec re-ancrage des trailers.


## Angle : Complétude — trous de couverture du WBS et de la matrice d'intersections (paiement serveur, caisse, impression, TVA, ten
**Faiblesses du plan :**
- WBS piloté par les 40 findings, pas par la surface d'attaque. Le test garde rouge-sans-fix prouve UN vecteur, pas l'exhaustivité: pricing client-trusted corrigé sur 1 endpoint laisse les autres non-cellulés. min(cellules)=1000 sur un ensemble incomplet = fausse convergence.
- Baselines visuelles capturées sur une app à 3.5/10: la régression visuelle fige l'UI buggée comme vérité, maxDiffPixelRatio valide la conformité au bug. Aucun oracle visuel spec-driven indépendant.
- Fix isolation branch_id==0 en deny-by-default casse les accès cross-branche LÉGITIMES (config super-admin, agrégation reporting, channels soketi). Aucune cellule ne possède l'accès cross-branche autorisé: régression non détectée par la boucle.
- branch_id==0 est aussi une remédiation de DONNÉES (lignes existantes à 0), pas que du code. Le plan ne teste ni la migration de backfill (up/down) ni la ré-étanchéité de la chaîne HMAC/NF525 sur ces lignes legacy.
- Sérialisation des hotspots (Vuex/routes/config/migrations) collapse le parallélisme 4-streams: temps de convergence non borné. Sans limite d'itérations + escalade à 3 heals = tempête d'escalades humaines non triée = deadlock organisationnel.
- Gate adversariale sur artefacts seuls + masquage des zones non-déterministes: le masque peut cacher une vraie régression, et une capture non reproductible (ordre KDS, echo) rend le juge instable malgré l'horloge gelée.
- Phase 0 prouve les invariants dans un env test (MySQL/soketi test) divergent de la prod: vert en test, cassé en prod (creds Pusher, money float, TZ, arrondi especes). Aucune gate de parité test↔prod avant d'ouvrir la porte 1000%.
- Corriger 'paiement déclaré par le client' EXIGE une confirmation serveur par webhook signé et idempotent. Aucune cellule ne possède cette réconciliation: l'invariant paiement reste cassable malgré tous les tests verts.

**Angles morts / manques :**
- Webhooks fournisseurs paiement (Stripe/PayPal/IPN): vérification de signature, idempotence au rejeu, race webhook↔redirect client↔outbox↔statut commande. Absent du WBS — c'est pourtant le pivot du fix 'paiement borne'.
- Gestion de caisse: ouverture/fermeture, fond de caisse, tiroir, X-report vs Z-report, écart et comptage. Intersection caisse×NF525×remboursement especes×arrondi non couverte.
- Impression fiscale/ticket (ESC/POS, window.borne.*): panne imprimante × état commande (done sans ticket), duplicata, mentions TVA légales. Intersection impression×reco×outbox absente.
- Mode TVA sur-place/à-emporter (10/5.5/20): un flip du toggle borne recalcule assiette TVA + sceau NF525 + routage KDS. Intersection toggle×pricing×fiscal×KDS non testée.
- Multi-tender: split payment (2 moyens)×remboursement partiel (quel tender crédité?), pourboire×NF525×refund, paiement partiel×annulation. Le triangle paiement/annulation/remboursement ne couvre qu'un seul tender.
- Cycle complet fidélité/promo: accumulation×remboursement (reprise des points), expiration, stacking promo×fidélité, promo par branche, remboursement partiel→recalcul de l'assiette. Seul 'affichée non appliquée' est cité.
- DB: réversibilité des 105 migrations (down), intégrité séquence NF525 (numérotation continue, détection de trou), verrous FOR UPDATE sur transitions+stock, money float vs cents, soft-delete×unique (86 recréé).
- Historique/légal: rotation clé HMAC + re-vérif de chaîne, immuabilité/archivage Z-report (6 ans), export fiscal (FEC), complétude ActionLog/DeletionLog sur TOUS les chemins de mutation, inaltérabilité du log.

**Durcissements :**
- Piloter le WBS par la SURFACE (inventaire exhaustif endpoints, inputs client, broadcasts, channels) avec métrique de couverture par invariant. Une cellule 'négative' par endpoint non couvert; prouver l'exhaustivité de l'ensemble avant tout min(cellules)=1000.
- Introduire des oracles visuels spec-driven (maquettes/contrats) distincts des baselines. Toute baseline capturée sur l'app buggée est marquée 'provisoire' jusqu'à validation humaine explicite du correctif visuel.
- Créer des cellules 'accès cross-branche autorisé' (allow-list explicite: super-admin, reporting, channels) en miroir du deny-by-default, avec test garde rouge-sans-fix ET test anti-fuite, les deux verts ensemble.
- Rendre chaque fix d'invariant un couple code+DATA: migration backfill testée up/down, re-scellement et vérif de chaîne HMAC/NF525 sur données legacy, détection de trou de séquence en test.
- Ajouter le domaine WBS-PAY-WEBHOOK: signature vérifiée, idempotence au rejeu, réconciliation serveur = seule source du statut PAID; e2e avec webhook hors-ordre et double-livraison simulés.
- Ajouter les domaines manquants: WBS-CASH (Z/X, tiroir, écart), WBS-PRINT (panne×état, duplicata), WBS-TAX-MODE (sur-place/emporter), WBS-TENDER (split/tip/partiel×refund), chacun avec sa propre matrice d'intersection dédiée.
- Gate de parité test↔prod: figer money en entiers-cents, TZ et arrondi especes; smoke post-déploiement rejouant les tests garde d'invariant en prod-like avant d'ouvrir la porte 1000%.
- Durcir SYNC: idempotence outbox au rejeu/hors-ordre/offline→reco, collision de numéro de commande, dédup echo; découper les hotspots plus finement + barrière dédiée. Rééquilibrer KDS/OSS/commande-en-ligne, sous-pondérés vs POS/Kiosk.


## Angle : FAISABILITE & SECURITE. Phase 0 est realiste sur l'infra (deps/DB/redis/soketi/Playwright installables, Chromium present
**Faiblesses du plan :**
- Contradiction de la gate Phase 0: exiger 'tous les feux verts' sur un code a 3.5/10 est impossible car la suite existante est legitimement ROUGE (elle teste les invariants casses). La boucle ne peut jamais demarrer. Il faut distinguer harnais operationnel de suite produit verte.
- Non-terminaison: 'pas de limite d'iterations' + 'min(cellules)=1000' sans metrique de convergence. Fixer A re-ouvre B (adversaire re-teste les voisins) qui re-ouvre A: livelock. Aucune garantie de monotonie ni de point fixe global.
- Le guard unique 'rouge-sans-fix/vert-avec' est gameable: sous pression d'atteindre 1000, l'agent ajuste le test a ce qui passe. Un seul guard prouve que le fix touche l'assertion, pas que l'invariant tient sur les chemins voisins (pricing OK POS, borne encore client-trusted).
- sec-branchscope (branch_id==0->deny) est traite comme fondation SURE mais a le meme rayon d'explosion que sec-admin-guard rejete: si branch_id==0 sert de sentinelle systeme (seeds, worker, jobs, E2ESeeder), le fix 403 tout et rend la baseline e2e rouge.
- NF525: 'prouver par guard rouge/vert' ignore que le scellement HMAC est chaine append-only. Recalculer les sceaux existants casse la chaine retroactivement. Correction non code-only, ABSENTE de la liste des human-gates (legalement porteur).
- Regle de fraicheur 'meme commit_sha' incompatible avec worktrees paralleles: les gates e2e/visuel tournent sur 'integration assemblee' (B3), donc un merge ulterieur invalide la fraicheur de toute cellule deja verte -> re-run combinatoire, non-convergence.
- Preuve visuelle forgeable: 'rouge-avant/vert-apres' est trivialement produit en committant la nouvelle baseline (c'est le fonctionnement meme du visual-regression). Le 'juge sur artefacts seuls' est contournable car captures/logs sont generes par le meme pipeline correcteur.
- QUEUE=sync leve (async reel redis/soketi) vs visuel deterministe <=0.01 se contredisent: le meme run doit etre a la fois non-deterministe (echo/reco/timing) et pixel-stable. Resultat: gates flaky jamais vertes, ou 'stabilisees' par timeouts fixes que le plan interdit lui-meme.

**Angles morts / manques :**
- Remediation des donnees historiques corrompues: fixer vers l'avant ne repare pas les lignes deja ecrites (branch_id==0, prix client-trusted, sceaux casses). Aucun plan de backfill/reconciliation de l'historique porteur.
- Ordonnancement de deploiement: aucun plan migrate->drain worker->backend->bundle. Messages outbox in-flight produits par l'ancien code (buggy) consommes par le nouveau: intersection version-boundary non couverte.
- Reconciliation PSP externe: le triangle refund teste 6 ordres INTERNES mais la verite est chez Stripe/PayPal (retries webhook). Double-remboursement possible cote PSP. Manque idempotency-key et harnais webhook sandbox en Phase 0.
- Concurrence acteur x meme entite: 2 caissiers meme commande, borne+POS meme table, 86 pendant checkout. Matrice feature x feature seulement; manque verrous, colonnes version, niveau d'isolation transactionnel.
- Politique de resolution de conflit offline/resync non specifiee (last-write-wins vs merge vs reject): c'est une decision d'invariant metier, pas un simple test, et elle manque.
- Matrice d'autorisation exhaustive: token borne=super-admin + Installer non-authentifie revelent une absence systemique d'authz, pas des bugs ponctuels. Manque un test genere role x route x branche complet.
- GCP servie = cle a considerer COMPROMISE: manque revocation + evaluation de la fenetre d'exposition + rotation de TOUS les secrets joignables via la meme fuite (Stripe/PayPal/DB/Sanctum). Traitee comme une simple rotation.
- Dimension temporelle fiscale: Z-report exige continuite multi-jours, monotonie de compteur, DST/timezone. Un seed 2 branches a horloge gelee ne peut pas exercer le chainage jour-a-jour ni le rollover.

**Durcissements :**
- Scinder la gate Phase 0: INFRA-GREEN (harnais/DB/redis/soketi/Playwright operationnels + fixtures chargent) = vraie sortie; et BASELINE PRODUCT-RED enregistree (guards rouges) comme oracle de depart. Ne jamais exiger le vert produit pour sortir de Phase 0.
- Rendre chaque invariant prouvable par une FAMILLE de guards generes (property-based, sur systeme x route x branche), pas un guard unique. Pricing = recompute backend==client POS/borne/KDS/OSS/online avec paniers fuzzes. Defait le gaming du guard unique.
- Ajouter un mecanisme de convergence: full-suite rejouee a CHAQUE merge; point fixe = N runs pleins verts consecutifs, zero cellule re-ouverte, avec burn-down des re-ouvertures. Une paire qui se re-ouvre = hotspot couple a re-designer conjointement.
- NF525 = human-gate + signature legale/expert, migration forward-only, entrees d'annulation/correction par-dessus (jamais re-sceller l'historique). Ajouter le contreseing fiscal a la liste des gestes irreversibles a cote de GCP et purge git.
- Le Juge doit re-interroger DB/PSP lui-meme (sonde independante), les artefacts ne sont que corroborants, la re-query DB est dispositive. Captures produites par un runner out-of-band et hachees pour empecher la forgerie.
- Fraicheur a deux niveaux: done-local (commit worktree) puis done-integrated (commit post-merge). Seul l'integre compte pour la sortie; chaque merge ne re-joue QUE les cellules dependantes via un graphe d'impact explicite, bornant l'explosion.
- Separer la suite VISUELLE deterministe (async stube, horloge gelee, pixel-diff) de la suite ASYNC reelle (redis/soketi, asserts events+DB, waits soketi, sans pixel-diff). Jamais les deux proprietes dans un meme test; interdire les timeouts fixes.
- Auditer toute lecture de branch_id==0 AVANT le fix (seeds, worker, jobs, E2ESeeder), decider par site, puis introduire un flag is_admin/system explicite plutot que surcharger 0. Ajouter cellules concurrence (verrous, version) + idempotency PSP en Phase 0.


---

*Plan maître assemblé à partir de 7 piliers + 3 red-team. Aucune correction n'a été appliquée. La Phase 0 (mise en exécution) est le prérequis bloquant de toute la boucle.*