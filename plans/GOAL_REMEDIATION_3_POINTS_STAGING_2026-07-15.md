# GOAL — Remédiation SÛRE des 3 points post-déploiement staging

**Date** : 2026-07-15 · **Branche** : `pos/category-first-caisse-2026-06-23` · **Cible** : VPS `lecayenne` (`APP_ENV=staging`, `https://vps-418872ac.vps.ovh.net`, IP `51.210.111.124`, dir `/var/www/lecayenne`)
**Statut** : PLAN — **ne pas exécuter sans gate owner** (Workstream A = human gate NF525 §10 ; B touche la sécurité d'une machine exposée Internet ; C touche le chemin prod).
**Méthode** : anchor-first (tous les `file:line` vérifiés par lecture directe), chaque remédiation **attaquée par un agent adversaire**, durcie sur les attaques survivantes. 3 agents d'ancrage + 3 agents adversaires + vérification manuelle des 5 claims porteuses.

---

## §0 — Préambule

### 0.1 Ce que l'audit adversaire a révélé
Les « 3 points » relevés post-deploy sont chacun **plus profonds** qu'un simple réglage :

| # | Point initial | Réalité découverte (vérifiée) |
|---|---|---|
| A | Chaîne NF525 « TAMPER » sur staging (secret mismatch) | Restaurer le secret **naïvement** créerait une **cible mouvante** (les writers actifs signent sous le mauvais secret pendant la fenêtre) et **appâterait un reset destructif**. Le gate go-live prod (`PreflightProductionCommand`) ne vérifie **que la longueur** du secret, jamais la chaîne → un secret **fort-mais-faux** passe le preflight. |
| B | Borne « machine non configurée » | La creds borne par défaut **`kiosk123` est PUBLIQUE** (repo) et **semée sur staging** ; l'API `/api/auth/kiosk-login` est **joignable depuis n'importe quelle IP** avec l'`apiKey` **public** → staging est potentiellement un **robinet à tokens `kiosk:order`** ouvert. Configurer la borne = **fermer un trou de sécurité**, pas juste un confort. |
| C | Smoke deploy mauvais port | Le smoke par **code HTTP** est **aveugle à une app cassée** (200 = « PHP a répondu », pas « l'app marche » — le bug `missing_env` lui-même rend 200). Le port `8766` est un port de **serveur de dev (laptop)**, pas un fait VPS ; s'il sert la prod c'est un `artisan serve` = **P0 dev-server-in-prod** à confirmer. |

### 0.2 Working tree
Aucune modification de code produite par cette planification. Ce document est le seul livrable de ce tour.

### 0.3 Critère de convergence global
Chaque workstream a (a) un **diagnostic read-only décisif**, (b) une **remédiation phasée durcie**, (c) un **gate vert défini**, (d) un **owner gate WHO/WHAT/WHERE**. « Absolument sûr » = **toute action destructive est derrière un gate explicite** + **tout diagnostic est non-destructif prouvé**.

### 0.4 Frozen zones touchées
- **Workstream A** : `AuditLogService.php`, `ZReportService.php`, config triggers = **FROZEN §7 + NF525 §8**. Le plan ne modifie **aucun** de ces fichiers. Les seules écritures sont `.env` (secret) et une **lecture** de la chaîne. Le durcissement go-live (§A3) touche `PreflightProductionCommand` et `AppServiceProvider` (**non-frozen**) — mais reste **owner-gated** car NF525-adjacent.
- **Workstream B** : provisioning = commande artisan + `.env` + config. `KioskLoginComponent.vue` **n'est pas** frozen (§7 liste Wizard/App/Upsell). Le durcissement throttle touche `RouteServiceProvider` (non-frozen).
- **Workstream C** : `tools/deploy-now-2026-07-15.sh` (non-frozen).

---

## §A — WORKSTREAM A : Chaîne NF525 « TAMPER » (crown jewel)

### A.1 Anchors (vérifiés par lecture directe)

| Fait | Preuve |
|---|---|
| Secret audit = `env('FISCAL_AUDIT_SECRET','')`, override `FISCAL_AUDIT_SECRET_BRANCH_N`, **pas de fallback**, vide → `RuntimeException` | `config/fiscal.php:31` ; `AuditLogService.php:269-292` |
| `computeHash` = HMAC-SHA256 pur sur `(prev_hash ?? '').'|'.canonical(action,payload)` — **aucune écriture** | `AuditLogService.php:237-243` |
| `verifyChain` : par branche, marche croissante, **s'arrête au 1er mismatch**, teste (linkage prev_hash) puis (recompute HMAC avec le secret **courant**) | `AuditLogService.php:199-231` |
| Genesis écrit `prev_hash = null` → linkage id=1 passe → **l'échec id=1 est le recompute HMAC** → secret courant ≠ secret signataire | `AuditLogService.php:245-254, 206-213` |
| Exit codes : `1`=TAMPER, `3`=secret manquant/faible (throw attrapé), `2`=args | `FiscalVerifyChainCommand` (exits) ; **staging a vu exit 1 ⇒ un secret EST chargé mais faux** |
| Rotation casse la vérif des vieux rows **volontairement** ; **« Ne jamais re-signer l'historique »** ; ne jamais détruire l'ancien secret (obligation 6 ans) | `docs/FISCAL_SECRETS.md:44-48, 72-74` |
| Guards prod (sentinelle + longueur ≥32) **désactivés** en `staging`/`local`/`testing` | `docs/FISCAL_SECRETS.md:27-30` ; `AuditLogService.php:303-327` |
| `fiscal:audit-verify --secret-history` (vérif multi-secret) = **PAS IMPLÉMENTÉ** | `docs/FISCAL_SECRETS.md:141` |
| Triggers immutabilité bloquent UPDATE (`audit_logs_no_update`) + DELETE ; **TRUNCATE/DROP contournent** (mitigé par Ansible `REVOKE DROP` **si le playbook a tourné sur CE VPS**) | `FiscalVerifyImmutabilityTriggersCommand` ; `deploy/ansible/site.yml:65` |
| **Writers actifs** : `fiscal:close-all-active-branches` `dailyAt('23:59')` + chaque `user.login` appendent un row sous le secret **courant** | `app/Console/Kernel.php` (schedule) ; `LoginController.php:118` |

### A.2 Attaques adversaires qui ONT abouti → ce que le plan doit intégrer
- **[P0] Cible mouvante + appât au reset.** Toucher le secret sur une box **vivante** sans geler les writers : après restauration du secret original, rows 1..K vérifient mais chaque row post-reseed **échoue** → `verifyChain` renvoie un **id différent** → l'opérateur croit à un 2ᵉ tamper → tentation `migrate:fresh`/TRUNCATE/DROP (qui **contournent** les triggers). **Une seule SSH sur la mauvaise box = 6 ans de preuve détruits.**
- **[P1] Recompute mono-row NON décisif sous historique multi-clés.** Si la chaîne a été légitimement tournée (1..100 secret_A, 101..327 secret_B), échantillonner 1 row induit en erreur ; `--secret-history` n'existe pas → **multi-clés = invérifiable aujourd'hui**.
- **[P1] Jumeau Z-report.** `FISCAL_Z_REPORT_SECRET` est séparé ; s'il a aussi été reseedé, chaîne Z rouge sans branche de remédiation.
- **[P1] Preflight go-live insuffisant.** `PreflightProductionCommand` ne teste **que `strlen>=32`**, jamais `verify-chain`, jamais « genesis propre ». Un secret **fort-mais-faux** passe. Si la DB staging est promue/dumpée en prod, **prod hérite du breach id=1 et le preflight le laisse partir**.
- **[P1] Un fix « vert » sur staging ne prouve rien** (guards prod OFF en staging).
- **Attaques réfutées (donc sûres)** : les commandes de diagnostic (`verify-chain`, `verify-immutability-triggers`, `tinker computeHash`) **n'écrivent rien** (fonctions pures / SELECT `information_schema`) ; le swap de secret **ne réécrit aucun row** (`.env` + `config:clear` seulement ; UPDATE bloqué par trigger) ; exit 1 vs 3 **distingue** bien tamper de secret-manquant.

### A.3 Remédiation phasée (durcie)

> **Règle d'or** : sur staging on NE re-signe JAMAIS, on NE truncate JAMAIS l'historique fiscal. On **diagnostique**, on **restaure le secret** si récupérable, sinon on **documente + on garantit la prod**.

**Phase A0 — GELER + DUMP immuable (OBLIGATOIRE avant toute action)**
1. `php artisan down` **OU** stop php-fpm + désactiver le scheduler (`fiscal:close-all-active-branches`) — empêcher tout write pendant la fenêtre.
2. Confirmer qu'aucun Z ouvert ne va se clôturer automatiquement pendant la fenêtre.
3. `mysqldump --single-transaction --triggers --routines` de `audit_logs` + `z_reports` → **stockage froid** horodaté (preuve avant intervention).

**Phase A1 — Diagnostic décisif (100 % read-only, non destructif)**
4. `php artisan fiscal:verify-chain --all` → **noter l'exit code** (1 attendu = tamper ; 3 = secret vide → autre problème).
5. `SELECT id, prev_hash FROM audit_logs WHERE id=1` → `NULL` attendu (genesis) ; si 64 zéros → l'échec est linkage, pas HMAC.
6. **Multi-row** (pas 1 seul) : en `tinker`, pour un échantillon de rows répartis sur toute la plage d'id (ex. id=2, 50, 150, 327) :
   `app(App\Services\Fiscal\AuditLogService::class)->computeHash($r->branch_id,$r->prev_hash,$r->action,(array)$r->payload) === $r->current_hash`
   - **Tous** mismatch → **wrong-secret mono-clé** (hypothèse principale) → A2 restauration éligible.
   - **Certaines plages** matchent, d'autres non → **multi-clés** → **STOP**, `--secret-history` requis (non livré) → branche « accepter + documenter » (A2c).
   - id>1 **matchent** mais id=1 non → **tamper genesis isolé RÉEL** → **STOP + escalade** (ne PAS masquer par un secret).
7. `grep`/SQL : existe-t-il un row `action='audit.key_rotation'` ? (trace une rotation légitime → conforte le cas multi-clés).
8. `php artisan fiscal:verify-immutability-triggers` → **8/8 (ou 9/9)** ? (les triggers ont-ils survécu à un éventuel import mysqldump ?).
9. **Jumeau Z** : vérifier la chaîne Z (`fiscal:verify-z-membership` + le verifier Z de `ZReportService`) — même logique reseed possible sur `FISCAL_Z_REPORT_SECRET`.

**Phase A2 — Brancher sur le diagnostic**
- **A2a — secret original RÉCUPÉRABLE** (secret manager `fiscal-audit-secret-v{N}` / ancien `.env` / notes ops) : poser `FISCAL_AUDIT_SECRET=<original>` dans `.env` VPS → `php artisan config:clear` → re-`verify-chain --all` → **vert attendu, ZÉRO changement de données**. Re-`verify-immutability-triggers` **après**.
- **A2b — original NON récupérable, historique MONO-clé** : ne rien réécrire ; **documenter** « chaîne staging invérifiable sous secret courant (reseed/rotation), historique non-légal (staging) » ; laisser les rows intacts.
- **A2c — MULTI-clés / tamper genesis réel** : **STOP**, escalade owner. Aucune restauration (elle masquerait ou déplacerait le problème). Décision humaine requise.
- **Interdit (hard-fence, même staging)** : `UPDATE current_hash/prev_hash` (re-signer — NF525-illégal `FISCAL_SECRETS.md:48` **et** bloqué par trigger) ; `TRUNCATE`/`migrate:fresh`/`DROP` sur la chaîne ; downgrade `APP_ENV=local` pour contourner un guard.

**Phase A3 — Durcissement PROD go-live (le vrai prix — code, owner-gated)**
- **A3.1** : définir le **secret prod permanent** (`openssl rand -hex 32`, non-sentinelle, ≥32) dans un **secret manager**, posé **dès le 1er boot** de la vraie box prod, **jamais tourné** hors runbook `FISCAL_SECRETS.md`.
- **A3.2** : **upgrade `PreflightProductionCommand`** → lancer `fiscal:verify-chain --all` (exit 0 requis) **ET** asserter que `audit_logs`/`z_reports` prod sont un **genesis propre** (ou une chaîne 100 % verte) avant go-live. Empêche une DB staging contaminée d'être promue en silence.
- **A3.3** : ajouter un **guard boot prod** (`AppServiceProvider`) : présence + force du secret fiscal (aujourd'hui l'enforcement est lazy au 1er `write()`).
- **A3.4** : garantir que la **vraie prod = DB fraîche** (pas un dump staging promu) — ou, si promotion, re-genesis propre documenté.

### A.4 Acceptance + tests
- `fiscal:verify-chain --all` **exit 0** sur la cible retenue (ou décision A2b/A2c documentée + signée).
- `fiscal:verify-immutability-triggers` **8/8** après toute opération.
- Chaîne Z vérifiée (verte ou discontinuité documentée).
- Tests : `tests/Feature/Fiscal/FiscalSecretProductionGuardTest.php` (existant, réf. `FISCAL_SECRETS.md:163`) reste vert ; **(À CRÉER)** `tests/Feature/Fiscal/PreflightChainGateTest.php` — le preflight ÉCHOUE si `verify-chain` rouge ou genesis non-propre.

### A.5 Owner gate
| Gate | Description | WHO | WHAT | WHERE |
|---|---|---|---|---|
| GA-1 | Secret original staging récupérable ? | Owner (accès secret manager / `.env` VPS) | valeur `fiscal-audit-secret-v{N}` ou décision « non récupérable » | secret manager / ops notes |
| GA-2 | Toucher la chaîne fiscale (NF525 §10) | Owner sign-off explicite | approbation phase A2 + fenêtre de gel | ce doc §A.3 + commit tag |
| GA-3 | Secret prod permanent défini | Owner | `openssl rand -hex 32` stocké secret manager | secret manager `fiscal-audit-secret-v1` |

---

## §B — WORKSTREAM B : Borne + le trou de sécurité exposé

### B.1 Anchors (vérifiés)

| Fait | Preuve |
|---|---|
| `status_missing_env` = 100 % client, affiché quand `window.foodkingConfig.kioskAutoLogin` est null | `KioskLoginComponent.vue:16, 75-100` ; `master.blade.php:136-144, 168` |
| `apiKey` **rendu public** dans chaque page ; seule barrière du groupe auth | `master.blade.php:149` ; `ApiKeyMiddleware.php:21-27` |
| `/api/auth/kiosk-login` dans le groupe public, **seulement `throttle:kiosk-login`** — **pas** de gate IP sur la route (le gate ne contrôle QUE l'injection SPA) | `routes/api.php` (kiosk-login) ; `KioskAutoLoginGate.php:47-73` |
| Seeder plante `kiosk-lecayenne`/`bcrypt('kiosk123')`, **bloqué seulement en production** → **tourne sur staging** ; `kiosk123` **public dans le repo** | `KioskMachineTableSeeder.php:21, 44-53` |
| Login mine un token `createToken('kiosk-token', ['kiosk:order'], 480min)`, révoque les anciens **au relogin seulement** | `KioskMachineLoginController.php:102-108` |
| Tokens kiosk **bloqués des routes admin** | `app/Http/Kernel.php:146` |
| Gate release = REMOTE_ADDR (non-spoofable) OU `?machine_key`==`KIOSK_AUTO_LOGIN_SECRET` (timing-safe) OU `APP_ENV=local` | `KioskAutoLoginGate.php:51-71` ; `master.blade.php:131-135, 141` |
| Provisioning : `php artisan foodking:ensure-kiosk-machine` (`EnsureKioskMachineCommand`) ; CRUD admin `admin/kiosk-machine` `permission:settings` | `routes/api.php:531-538` |

### B.2 Attaques adversaires qui ONT abouti
- **[CRITIQUE] Le gate n'est PAS une barrière de token.** `apiKey` public + `kiosk123` public (semé staging) → `curl` l'apiKey depuis n'importe quelle page, puis `POST /api/auth/kiosk-login` avec `kiosk-lecayenne`/`kiosk123` **depuis n'importe quelle IP** → token `kiosk:order`. Le gate IP/secret ne protège **que** l'auto-fill SPA, **pas** l'API. **Staging est un robinet à tokens ouvert** (si le row existe — à confirmer sur la box).
- **[HIGH] La rotation de mot de passe ne révoque PAS les tokens vivants** (purge au relogin uniquement ; `--force` ne touche pas les tokens) → fenêtre 480 min.
- **[HIGH] `?machine_key` dans l'URL fuit** (logs nginx, historique navigateur borne) ; secret fuité = creds depuis n'importe quelle IP.
- **[MEDIUM] Throttle `kiosk-login` keyé sur `request()->ip()` (spoofable via XFF sous TrustProxies `*`)** → bypass brute-force/DoS (à vérifier `RouteServiceProvider`).
- **[MEDIUM]** IDOR fidélité latent (token volé → redeem points d'autrui si `pos.manual_discount_enabled` flip) ; flood de fausses commandes (KDS/caisse) ; foot-gun `config:cache` qui peut **downer la borne** en service.
- **Réfuté (sûr)** : escalade admin avec token kiosk **bloquée** (`Kernel.php:146`) ; XFF ne casse pas le gate (REMOTE_ADDR) ; **zéro** frozen-zone touché.

### B.3 Remédiation phasée (durcie)

**Phase B0 — Fermer l'incident (priorité, staging ET prep prod)**
1. **Vérifier sur la box** : le row `kiosk-lecayenne` existe-t-il ? (`SELECT id,username,status FROM kiosk_machines`). Si oui → `kiosk123` est **live et public**.
2. **Rotation mot de passe unique** : `php artisan foodking:ensure-kiosk-machine --username=kiosk-lecayenne --password=<FORT-UNIQUE> --branch-id=1 --force`.
3. **Révoquer les tokens vivants** après rotation : `admin/kiosk-machine/logout/{id}` **ou** `$user->tokens()->where('name','kiosk-token')->delete()` (sinon fenêtre 8 h).

**Phase B1 — Provisionner l'auto-login sûrement**
4. `.env` VPS : `KIOSK_MACHINE_USERNAME=kiosk-lecayenne`, `KIOSK_MACHINE_PASSWORD=<même FORT>`, puis **choisir le chemin de release** :
   - **Préféré (sans secret en URL)** : provisionner via **admin UI** (`admin/kiosk-machine`) OU `KIOSK_AUTO_LOGIN_TRUSTED_IPS=<IP/CIDR réelle de la borne>` — **uniquement si** `REMOTE_ADDR` = vrai pair (pas un LB, cf. B.4).
   - **Repli réseau-indépendant** : `KIOSK_AUTO_LOGIN_SECRET=<long aléatoire>` + ouvrir `…/kiosk/idle?machine_key=<secret>` — **et strip le query `machine_key` des access logs nginx** pour `/kiosk*`.
5. `php artisan config:clear` **puis** `config:cache` (sinon changement silencieusement ignoré).
6. **Smoke pré-service** : `/kiosk/idle` doit renvoyer un payload non-null **avant** les heures d'ouverture.

**Phase B2 — Durcissement**
7. Re-keyer le throttle `kiosk-login` sur `REMOTE_ADDR` + abaisser ~10/min (vérifier `RouteServiceProvider`).
8. **Jamais** mettre une IP de LB/edge dans `KIOSK_AUTO_LOGIN_TRUSTED_IPS` (release creds à tout Internet).

### B.4 Must-verify sur le VPS (dépendant du déploiement)
- **Y a-t-il un edge/LB OVH devant le VPS ?** Si oui, `REMOTE_ADDR` = IP du proxy → chemin TRUSTED_IPS **catastrophique** → `machine_key` (log-strippé) devient le seul chemin viable. Si nginx+fpm même hôte → REMOTE_ADDR = vrai client.

### B.5 Acceptance + tests
- Row `kiosk-lecayenne` a un **mot de passe unique** (≠ `kiosk123`) ; anciens tokens révoqués.
- `/kiosk/idle` auto-login **fonctionne** via le chemin choisi (payload non-null), depuis la borne réelle.
- Tests existants (réf. docblock `KioskAutoLoginGate.php:23-24`) verts : `tests/Unit/KioskAutoLoginGateResolverTest.php`, `tests/Feature/Kiosk/KioskAutoLoginGateTest.php`.
- **(À CRÉER)** `tests/Feature/Kiosk/KioskLoginPublicCredsRejectedTest.php` — `POST /api/auth/kiosk-login` avec `kiosk123` → **rejeté** après rotation.

### B.6 Owner gate
| Gate | Description | WHO | WHAT | WHERE |
|---|---|---|---|---|
| GB-1 | Choix du chemin de release (admin-UI / IP / machine_key) | Owner (connaît la topologie borne↔réseau) | edge/LB oui-non + IP/CIDR borne | ce doc §B.4 |
| GB-2 | Mot de passe borne unique | Owner | valeur `<FORT-UNIQUE>` (jamais commitée) | `.env` VPS + secret manager |

---

## §C — WORKSTREAM C : Smoke deploy aveugle + hygiène

### C.1 Anchors (vérifiés)

| Fait | Preuve |
|---|---|
| Smoke ligne 48 = `curl -sk … -H 'Host: …' https://127.0.0.1/$p` → **000×4** (rien ne sert TLS sur loopback:443) | `tools/deploy-now-2026-07-15.sh:48` |
| La boucle `for` finit par `echo` → **exit 0 toujours** ; pas de `set -e` ; le smoke **ne gate jamais** | `:23, :48` ; trace exit `:49-51` |
| Check HEAD ligne 31 = **echo seulement** ; `EXPECTED="cef7b7a12"` (:25) ≠ commentaire `8399ba5c1` (:21) ≠ origin réel `2c5aeb0ab` | `:21, :25, :31` |
| PIN `$(( (RANDOM%900000)+100000 ))` → bash `$RANDOM`≤32767 → PIN **toujours 100000–132767** (~15 bits) ; **echo en clair** stdout | `:33-35` |
| `grep -q '^DAILY_BOOK_PIN='` matche une valeur **vide** → skip génération ; guard compare `=== '2468'` → PIN vide **boote** | `:32` ; `AppServiceProvider.php:197` |
| `8766` = port **dev laptop** (`.env` local, `deploy/local/*.serve.plist`, plan e2e « clone jetable ») ; nginx committé sert **php-fpm 80/443**, **zéro** `8766` | `.env:7` ; `scripts/deploy/nginx.conf.template:74,198` ; `deploy/ansible/templates/nginx-foodking.conf.j2` |
| `migrate --force` (:39) **sans snapshot DB** ; rollback = git code-only (n'annule pas une migration) | `:39` |

### C.2 Attaques adversaires qui ONT abouti
- **[P0] Le smoke « corrigé » par code HTTP reste aveugle.** 200 = « PHP a répondu », pas « l'app marche » (le bug `missing_env` rend 200 ; un chunk lazy 404 / white-screen SPA rend 200 ; accepter 302/419 est pire).
- **[P0] `8766` = port dev laptop, pas fait VPS.** Le hardcoder parie la prod sur un serveur non-versionné. Si le VPS s'aligne sur le nginx committé (fpm 80/443), le smoke « corrigé » 000 pour toujours. **Résiduel à confirmer** : si `8766` est un `artisan serve` en prod = **P0 dev-server-in-prod** (mono-thread, meurt sans supervisor).
- **[P1] `migrate --force` sans snapshot** ; PREV/git n'annule pas une migration non-additive.
- **[P1] Check mix-manifest** (porté de `deploy-vps.sh`) rate la vraie classe white-screen (**chunk lazy stale/404** — l'incident réel « page blanche paiement »).
- **[P1] Smoke externe https loopback** = rouge perpétuel → **fatigue d'alarme**.
- **[P2]** PIN faible entropie ; PIN vide boote ; assertion HEAD tautologique (`reset --hard` rend HEAD==origin toujours vrai) ; `curl || exit` gate le transport pas le HTTP (curl renvoie 0 sur 500).
- **Réfuté (sûr)** : `exit 7` en **dernière** commande remote **propage** bien à `rc` (si gaté sur `$code`) ; `origin/$BRANCH` ne bouge pas entre fetch et assertion.

### C.3 Remédiation phasée (durcie)

**Phase C0 — Réconcilier la topologie VPS (avant de coder le smoke)**
1. Sur la box : `ss -tlnp | grep -E ':(80|443|8766)'` + `nginx -T 2>/dev/null | grep -nE 'listen|proxy_pass|fastcgi_pass|8766'` + `systemctl/supervisorctl status` / `pgrep -af 'artisan serve|php-fpm'`.
2. **Décider** : nginx+fpm (80/443) OU `artisan serve:8766`. Si `artisan serve` → **escalade P0** (dev server en prod).

**Phase C1 — Smoke qui prouve du CONTENU (pas un code)**
3. **Auto-découvrir le port** (jamais hardcoder) : `PORT=$(php -r "echo parse_url(config('app.url'),PHP_URL_PORT)?:'';")` ou grep `.env` APP_URL ; smoke `127.0.0.1:${PORT:-80}`.
4. **Asserter du contenu réel** : fetch `/kiosk/idle` → exiger 200 **ET** un marqueur DOM d'app bootée **ET** extraire le hash `app.js` de `public/mix-manifest.json`, asserter que ce `?id=<hash>` apparaît dans le HTML servi, puis `curl` ce bundle → 200 (tue white-screen/stale/chunk-404).
5. **Ajouter une route santé** JSON (touche DB+config) et asserter un champ attendu (aucune route `/up` aujourd'hui — vérifier `routes/web.php`, en créer une).

**Phase C2 — Gating réel + hygiène**
6. Gate sur la **variable `$code`** (200/301/302/401 OK ; 419/000/5xx/autre = fail), `exit 7` explicite, `--max-time 8`.
7. **Pin le SHA revu** : `[ "$AFTER" = "$EXPECTED" ] || exit 3` + rafraîchir `EXPECTED` au vrai head + supprimer le commentaire contradictoire.
8. **Snapshot DB avant `migrate --force`** (`mysqldump`→gz) + documenter `migrate:rollback`/restore ; bloquer le rollback git-seul quand une migration a tourné.
9. **PIN** : `openssl rand`/`/dev/urandom` (6 chiffres, vraie entropie) ; traiter `DAILY_BOOK_PIN=` vide comme **non défini** (régénérer) ; post-`config:cache` asserter `config('daily_book.pin')` = 6 chiffres ≠ 2468. **Ne plus echo le PIN** — écrire dans `.env`, dire à l'opérateur de `ssh … grep`.
10. Porter la fraîcheur mix-manifest **ET** sonder les chunks lazy connus.

### C.4 Acceptance + tests
- Deploy sur une app volontairement cassée (chunk stale simulé) → le smoke **échoue** (exit non-zero).
- HEAD mismatch → deploy **échoue**.
- PIN généré = 6 chiffres, entropie réelle, non-echo.
- **(À CRÉER)** `tests/Feature/Deploy/SmokeContentAssertionTest.php` (ou un self-test du script) — le smoke détecte un bundle absent.

### C.5 Owner gate / must-verify
| Gate | Description | WHO | WHAT | WHERE |
|---|---|---|---|---|
| GC-1 | Que sert réellement `:8766` sur le VPS ? | Owner/ops (accès ssh) | sortie `ss -tlnp` + `nginx -T` | ce doc §C.3 phase C0 |
| GC-2 | `artisan serve` en prod ? (si oui, P0) | Owner | décision : passer à php-fpm ou assumer | §C.2 résiduel |

---

## §D — Ordre d'exécution (ce qui est sûr vs owner-gated)

| Vague | Contenu | Sûr à lancer sur approbation ? |
|---|---|---|
| **W0 — Diagnostic read-only** | A1 (verify-chain multi-row, triggers, Z), B0.1 (row kiosk existe ?), C0 (`ss`/`nginx -T`) | **OUI** — 100 % lecture, non destructif prouvé. Aucune écriture DB/fichier. |
| **W1 — Fermer l'incident borne** | B0.2 rotation mdp + B0.3 révocation tokens | Owner gate GB-2 (mot de passe) — mais **urgent** si le row public existe |
| **W2 — Provisionner borne** | B1 + B2 | Owner gate GB-1 (topologie) |
| **W3 — Durcir le deploy** | C1 + C2 (édite `tools/deploy-now-*.sh`, non-frozen) | **OUI** (script non-frozen) — après GC-1 pour le port |
| **W4 — Chaîne NF525** | A0 gel + A2 restauration/documentation | **NON** — human gate GA-1/GA-2 (NF525 §10) |
| **W5 — Durcissement go-live prod** | A3 (Preflight + boot guard + secret permanent) | Owner gate GA-3 |

**W0 est intégralement sûr** : je peux le lancer immédiatement sur simple « go » pour transformer les hypothèses en faits (secret mono/multi-clés, row kiosk public oui/non, port VPS réel), ce qui **déverrouille les décisions** GA/GB/GC sans aucun risque.

---

## §R — Références
- `docs/FISCAL_SECRETS.md` (runbook rotation — autorité NF525) ; `config/fiscal.php` ; `app/Services/Fiscal/AuditLogService.php`.
- `app/Support/KioskAutoLoginGate.php` ; `resources/views/master.blade.php` ; `database/seeders/KioskMachineTableSeeder.php` ; `app/Console/Commands/EnsureKioskMachineCommand.php`.
- `tools/deploy-now-2026-07-15.sh` ; `tools/deploy-vps.sh` ; `scripts/deploy/nginx.conf.template`.
- CLAUDE.md §7 (frozen zones) · §8 (NF525) · §10 (human gates). Mémoire : `supervisor_audit_integrated_2026-07-13.md`.

## §F — Done criteria
Convergé quand : (A) `verify-chain` vert **ou** décision A2b/A2c signée + prod garantie clean-genesis ; (B) mdp borne unique + tokens révoqués + auto-login prouvé sur la borne réelle ; (C) smoke prouve du **contenu** (bundle frais servi), gate réellement, PIN entropique. **Aucune** action destructive lancée hors gate. **Zéro** frozen-zone modifié.
