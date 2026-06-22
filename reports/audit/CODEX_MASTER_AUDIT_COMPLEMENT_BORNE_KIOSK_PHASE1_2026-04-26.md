# Codex Master Audit Complement — Borne / Kiosk Phase 1

Date : 2026-04-26  
Auteur : Codex extension session Cursor  
Mode : audit + plan uniquement, aucun patch produit  
Reference auditee : Ultra audit + Ultra plan Claude — Borne / Kiosk Phase 1

---

## 0. Perimetre et methode

Objectif : completer l'audit Claude, verifier les points critiques reproductibles dans le worktree courant, et corriger le plan d'execution pour eviter les faux P0, les missions obsoletes, et les collisions avec les travaux Codex deja en cours.

Contraintes appliquees :

- Lecture P0/P1 FoodKing : `AGENTS.md`, `.cursor/ACTIVE_CYCLE.md`, `.cursor/rules/global.mdc`, `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`, `.cursor/commands/run-cycle.md`, `docs/orchestration/MEMORY_MATRIX.md`, `.cursor/routing.md`.
- Masterplay actif et gele : `MASTERPLAY_FROZEN=1` dans `plans/masterplay/MASTERPLAY_QUEUE.md`.
- Worktree tres sale avec activite Codex parallele : aucune modification produit effectuee.
- Graphiti : disponible dans cette session (`status: ok`, Neo4j connected). Les faits memoire consultes ne remplacent pas le code courant.

Fichiers produits lus ou verifies :

- `app/Services/FrontendOrderService.php`
- `app/Services/Order/OrderQuoteService.php`
- `app/Http/Requests/OrderRequest.php`
- `app/Http/Controllers/Frontend/OrderController.php`
- `app/Http/Controllers/Frontend/LoyaltyController.php`
- `routes/api.php`
- `routes/channels.php`
- `resources/js/helpers/kioskOfflineQueue.js`
- `resources/views/master.blade.php`
- `resources/js/router/modules/kioskRoutes.js`
- `database/migrations/2025_02_21_110459_create_kiosk_machines_table.php`
- `database/migrations/2026_04_25_190000_create_order_quotes_table.php` (present mais untracked)

---

## 1. Verifications reproduites

| Commande | Resultat | Lecture |
| --- | --- | --- |
| `php artisan test --filter='KioskSecurityTest::test_kiosk_branch_id_is_forced_from_machine'` | FAIL, attendu 201 recu 403 | Le rouge signale un conflit de contrat : commit force la branche machine, mais le flux quote/branch rejette le payload forge. |
| `npx vitest run tests/js/kioskOfflineQueue.spec.js tests/js/kioskOfflineQueueMigration.spec.js tests/js/kioskOfflineQueueV2.spec.js` | FAIL, 3 fichiers, 6 tests rouges | Bug confirme : les cles d'idempotence/localKey originales sont regenerees en `offline_*`, ce qui casse replay, migration, telemetry, cancel/force-retry. |
| `php artisan test --filter='KioskQuoteIntegrityTest|QuoteExpirationTest|QuoteReplayIdempotencyTest'` | PASS, 7 tests | Le bug "quote expiree recreee silencieusement" n'est pas present dans le worktree courant. |
| `php artisan test --filter='QueueNumberUniquenessSentinelTest'` | FAIL | Pas d'index unique DB contenant `branch_id` + `queue_number`. |
| `php artisan test --filter='PaymentConfirmAbilityTest|PaymentConfirmCrossBranchTest|KioskRealtimeBroadcastTest|EventContractTest|AfterCommitDispatchTest'` | PASS, 28 tests | Scoping paiement, contrat evenement K-09B et dispatch apres commit sont verts en cible. |

---

## 2. Corrections du diagnostic Claude

### C-01 — Quote expiry : P0 obsolete dans le worktree courant

Claude classe G5 comme P0 actif : `OrderQuoteService::sealForCommit()` recreerait silencieusement une quote expiree.

Constat courant :

- `OrderQuoteService::resolveReplay()` rejette explicitement une quote expiree par `HttpException(410, 'Order quote expired.')`.
- Les suites ciblees `KioskQuoteIntegrityTest`, `QuoteExpirationTest`, `QuoteReplayIdempotencyTest` passent.

Plan corrige : conserver une sentinelle regression, mais ne pas bloquer Phase B sur ce point. Le blocage P0 doit rester sur offline queue, loyalty, queue number et contrat branch.

### C-02 — `paymentConfirm` : diagnostic partiellement perime

Claude indique que `paymentConfirm` marque `payment_status=PAID` sans transition statut.

Constat courant :

- `OrderController::paymentConfirm()` appelle `FrontendOrderService::finalizePaidKioskOrder()`.
- `finalizePaidKioskOrder()` promeut la commande payee vers `OrderStatus::ACCEPT`, enregistre la transition puis dispatch `OrderCreated` et `OrderStatusChanged`.
- Les tests ciblees paiement/event passent.

Plan corrige : ne pas traiter comme bug P0/P1 de statut. Le reste eventuel est un besoin UX/telemetrie : event paiement distinct ou websocket POS dedie, bloque par frozen gate si les fichiers outbox/listeners sont hors allowlist.

### C-03 — `POS_WIZARD_CONFIG` : pas seulement hardcode, mais encore dangereux

Claude indique des prix hardcodes dans `master.blade.php`.

Constat courant :

- `master.blade.php` lit deja des settings `Settings::group('order_setup')->get(...)`, avec fallbacks litteraux.
- `public/js/pos-wizard.js` reste injecte globalement.
- `KIOSK_USE_POS_WIZARD` choisit runtime entre le wizard kiosk et le wrapper POS.

Plan corrige : verifier d'abord si `window.POS_WIZARD_CONFIG` est consomme pour une decision de prix. Si oui, P0 pricing SSOT. Si display-only, P1 gouvernance : supprimer les fallbacks prix litteraux ou les rendre purement decoratifs, et decider le flag POS wizard.

### C-04 — Branch forced test : le rouge est reel, mais le contrat n'est pas tranche

Le test attend que le payload forge soit ignore et que la commande soit creee en branch machine. Le code courant rejette le payload contradictoire plus tot dans le flux quote/branch.

Deux contrats defensables :

- Contrat A : "machine wins" — le payload `branch_id` client est ignore, commande 201 sur la branch machine, et log securite optionnel.
- Contrat B : "strict reject" — tout payload `branch_id` contradictoire produit 403, y compris quote/order, et la sentinelle doit attendre 403.

Plan corrige : ne pas patcher ce test aveuglement vers 201. Choisir explicitement A ou B, puis aligner `OrderQuoteService`, `FrontendOrderService`, `OrderRequest`, tests et logs. En securite, B est plus strict ; A est plus tolerant UX mais doit prouver qu'aucune fuite inter-branches n'est possible.

### C-05 — Graphiti : indisponible chez Claude, disponible ici

Claude a travaille avec fallback `memory/INDEX.md`. Dans cette session Graphiti est disponible. Aucun fait Graphiti consulte ne contredit le code courant, mais le rapport final doit noter l'ecart pour eviter de figer des conclusions "memoire indisponible" comme etat permanent.

---

## 3. Findings complementaires

### P0 — Offline queue : idempotency/localKey rewrite confirme

Le helper `kioskOfflineQueue.js` regenere les cles qui ne commencent pas par `offline_`. Cela casse :

- replay avec cle idempotence originale ;
- migration legacy `localKey` ;
- telemetry backoff ;
- cancel / force retry ;
- stale invalidation.

Impact : double-charge ou commande dupliquee si le serveur ne dedupe pas selon la cle attendue.

Mission recommandee : fusionner le correctif offline queue avec une sentinelle serveur dedup, mais garder les write sets separes si plusieurs agents travaillent.

### P0 — Loyalty : le risque est pire que formule par Claude

Claude a bien identifie la double deduction. Le code courant ajoute un risque supplementaire :

- la deduction inline kiosk est dans un `try/catch(Throwable)` qui loggue puis continue ;
- une erreur de `LoyaltyTransaction::create()` ou de decrement peut donc etre avalee dans la transaction de commande ;
- l'ordre peut etre cree avec discount sans ledger fiable, ou avec ledger incomplet selon le point d'echec.

Plan corrige : B.3 et B.4 doivent etre une seule mission atomique. Ne pas les paralleliser. Le bon critere est : pas de remise loyalty sans ledger coherent, pas de double redeem, rollback complet en cas d'erreur ledger.

### P0 — Queue number : le garde DB manque

Le sentinel confirme l'absence d'index unique. Le fallback microtime en cas de timeout de lock ne garantit pas l'unicite.

Blocage : schema gate humain requis avant migration. Sans gate, ne pas modifier les migrations.

### P0/P1 — Branch contract kiosk incoherent

Le service de creation force la branch machine, mais le flux quote peut rejeter un payload forge. Le test existant attend "ignore and create".

Risque :

- si on garde le reject strict, le test doit changer et la documentation doit l'assumer ;
- si on force "machine wins", il faut prouver quote + commit + pricing utilisent toujours la branch machine et jamais la branch payload.

Critere binaire corrige : une seule politique documentee, un seul comportement attendu, tests quote + order + invalid token verts.

### P1 — Middleware kiosk non uniforme

Plusieurs routes kiosk sensibles sont seulement sous `auth:sanctum` et s'appuient sur des checks controller/FormRequest :

- `/api/frontend/menu`
- `/api/frontend/pricing/preview`
- `/api/frontend/promo/validate`
- `/api/frontend/upsell`
- `/api/frontend/loyalty/scan`

Ce n'est pas une fuite immediate si les checks restent corrects, mais ce n'est pas fail-closed. Un middleware `EnsureKioskMachine` ou une policy route-level uniforme reduirait la dette et la variance 401/403.

### P1 — `OrderRequest` whitelist incomplete

Claude cible `total/subtotal/discount`. Le probleme est plus large pour le kiosk :

- `delivery_charge` est aussi un champ financier accepte ;
- `source` peut etre fourni par payload avant normalisation de `source_surface` ;
- `payment_method` et `order_type` doivent rester controles par les enums et le contexte machine.

Plan corrige : `validated()` doit retourner une projection par surface. Pour kiosk, strip explicite des champs financiers client (`subtotal`, `discount`, `total`, `delivery_charge`) et normalisation server-side des surfaces.

### P1 — Idempotency lock error taxonomy

Le lock idempotency est acquis avant le `try` principal de `FrontendOrderService::myOrderStore()`. Une `LockTimeoutException` peut donc echapper a la taxonomie d'erreur attendue et produire un comportement non normalise.

Plan corrige : inclure ce cas dans la mission error taxonomy, avec test de contention ou mock lock timeout.

### P1 — Legacy pricing fallback toujours present

Le chemin legacy de `FrontendOrderService` existe encore derriere `config('pricing.use_ssot_service', true)`. Tant qu'il existe, un flip config peut contourner les garanties SSOT pricing.

Plan corrige : suppression commune POS/Kiosk, avec note de symetrie `OrderService` / `FrontendOrderService`.

### P1 — `KioskMachine` schema identity incomplet

La migration initiale ne declare pas d'unique `(branch_id, machine_id)`. En plus, le login lookup se fait par `username`, donc la politique d'unicite doit etre clarifiee :

- `username` globalement unique si c'est l'identifiant login ;
- `(branch_id, machine_id)` unique si c'est l'identite physique ;
- `device_token` avec rotation/revocation controlee.

Plan corrige : traiter avec schema gate, pas en patch opportuniste.

### P1 — Bundles publics et legacy cutover

Le probleme n'est pas seulement "shim signe vs purge". Il faut aussi decider si `public/js/kiosk.js` et `public/js/kiosk-wizard.js` sont des artefacts generes trackes, des shims signes, ou des restes a purger. Les missions produit ne doivent pas patcher manuellement ces bundles.

### P2 — Loyalty scan privacy

`LoyaltyController::scan()` accepte un fallback telephone et retourne des infos client sur une machine publique authentifiee kiosk. Meme si la borne est machine-trustee, c'est une surface privacy/enumeration.

Plan corrige : decision produit RGPD : QR/loyalty token opaque only, ou telephone autorise avec rate limit, masking et audit event.

---

## 4. Plan corrige

### Phase 0 — Gouvernance read-only avant execution

1. Unifier l'etat `.cursor/ACTIVE_CYCLE.md` : un seul cycle primaire executable.
2. Lever ou confirmer `MASTERPLAY_FROZEN=1` avant toute nouvelle mission produit.
3. Decider les gates schema : queue number unique, kiosk machine unique, order quotes migration untracked.
4. Persister les rapports/missions deja revendiques CLOSED mais untracked.
5. Declarer explicitement que ce rapport est un complement d'audit, pas une autorisation de patch.

Critere : aucune mission produit demarree sans gate ou cycle actif propre.

### Phase 1 — Reproduction et contrats

Ordre recommande :

1. Rejouer les trois preuves P0 : offline Vitest, branch test, queue number sentinel.
2. Rejouer les preuves non-P0 pour eviter les rework inutiles : quote expiry PASS, payment confirm/status PASS.
3. Trancher le contrat branch kiosk : "machine wins" ou "strict reject".
4. Trancher le contrat loyalty : redeem endpoint et order inline ne peuvent pas tous deux consommer les memes points sans ledger idempotent.

Critere : un tableau PASS/FAIL et une decision contractuelle par point.

### Phase 2 — Correctifs P0 executables apres gate

| Priorite | Mission corrigee | Allowlist logique | Critere |
| --- | --- | --- | --- |
| P0-1 | `CV1-FIX-R4-KIOSK-OFFLINE-QUEUE-IDEMPOTENCY` | `resources/js/helpers/kioskOfflineQueue*.js`, tests offline queue | 3 suites Vitest offline PASS, cles originales preservees. |
| P0-2 | `CV1-FIX-KIOSK-LOYALTY-ATOMIC-REDEEM` | bloc loyalty de `FrontendOrderService`, `LoyaltyController` si necessaire, tests loyalty | double redeem impossible, rollback complet si ledger fail, pas de discount sans ledger. |
| P0-3 | `CV1-FIX-KIOSK-BRANCH-CONTRACT` | `OrderQuoteService`, `FrontendOrderService`, requests/tests kiosk | Contrat A ou B applique partout ; tests quote/order/invalid token verts. |
| P0-4 | `CV1-FIX-R5-QUEUE-NUMBER-UNIQUE-MIGRATION` | migration nouvelle + sentinel | Apres schema gate : unique DB effectif, sentinel PASS. |

Note : ne pas separer loyalty double-deduction et ledger atomic en missions paralleles. C'est le meme invariant argent/audit.

### Phase 3 — Hardening backend P1

1. `OrderRequest::validated()` par surface, strip des champs financiers kiosk incluant `delivery_charge`.
2. Suppression du legacy pricing fallback, avec symetrie POS/Kiosk documentee.
3. Middleware `EnsureKioskMachine` ou route group equivalent pour uniformiser token ability + branch machine.
4. Migration de la closure `collect-kiosk-cash` vers controller.
5. Taxonomie d'erreur `FrontendOrderService`, incluant lock timeout idempotency.
6. Schema identity kiosk machine apres gate.

### Phase 4 — Frontend/runtime P1

1. Verifier par grep/test si `window.POS_WIZARD_CONFIG` influence un calcul de prix.
2. Decider `KIOSK_USE_POS_WIZARD` : retirer le flag ou le documenter avec sentinelle.
3. Refactorer les gros composants seulement apres les P0, pas avant.
4. Ajouter countdown quote cote UI uniquement comme coherence UX, pas comme source de verite.
5. Clarifier le cache menu : invalidation prix/structure future dashboard, pas seulement availability.

### Phase 5 — Release evidence

1. `php artisan test --filter='Kiosk'`
2. `npx vitest run tests/js/kiosk*`
3. `npx playwright test --grep kiosk`
4. Strict legacy bundle scan apres decision shim/purge.
5. UAT hardware borne physique signe humain : TPE, cash drawer, imprimante ticket, scanner.
6. Rapport final Claude puis audit final GPT seulement apres preuves vertes.

---

## 5. Verdict complementaire

`BORNE_V1 = BLOQUEE`, mais le blocage doit etre reformule :

- P0 confirmes : offline queue idempotency/localKey, loyalty atomic/double-redeem, queue number unique DB, contrat branch kiosk non tranche.
- P0 retire ou degrade : quote expiry, car les tests ciblees passent dans le worktree courant.
- P1 corrige : payment confirm status, car la transition post-paiement existe deja ; le reste est un besoin de realtime/UX et de gouvernance event.
- Gouvernance : aucun correctif produit ne doit partir tant que le masterplay est gele et que les gates schema/untracked ne sont pas resolus.

Ce rapport complete l'audit Claude en separant les bugs reproduits, les constats obsoletes, et les decisions contractuelles manquantes.
