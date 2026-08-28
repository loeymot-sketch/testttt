# Document principal de reprise — CAISSE-SUPERVISOR-CONTROL-20260823

Dernière mise à jour : 2026-08-24, pendant `REMEDIATION_AUDIT_CYCLE: 1/5`.

Ce fichier est le point d'entrée autonome à remettre à Claude Code si la session Codex s'interrompt. Il décrit l'état réellement observé du dépôt, les décisions déjà approuvées, les preuves acquises et la suite exacte. Il ne remplace pas `AGENTS.md`, `.cursor/ACTIVE_CYCLE.md` ni le plan actif : en cas de divergence, le code et ces SSOT gagnent.

## ÉTAT AU 2026-08-24 — reprise superviseur Claude Code effectuée

Ce bloc prime sur tout ce qui suit : le reste du document décrit l'état du 2026-08-24 à 00 h,
avant la reprise. Ce qui a changé depuis :

**La session Codex n'a jamais rien produit.** `output_codex.json` contient un HTTP 400 :
« The 'gpt-5.5-pro' model is not supported when using Codex with a ChatGPT account ». Aucune
sortie Codex n'existe pour ce cycle.

**VALIDATE est terminée**, avec sortie réelle pour chaque commande : 41 tests backend sur
8 suites séparées, 3 609 tests Vitest sur 440 fichiers (0 échec), build production avec
`pos-wizard.js` intact au md5 près, Wave E + multi-produit + les deux parcours obligatoires
verts, postcondition DB sans aucune suppression, diff des 13 chemins gelés vide.

**Cinq défauts que la validation précédente n'avait pas vus** ont été trouvés et corrigés —
voir REPLAN_6 et REPLAN_7 du plan. Le plus important : `kiosk_machines.machine_id` valait
encore `AUDIT-KIOSK-MULTI` en base au lieu de `KIOSK-LC-001`. Le P1 de l'audit GPT n'était
remédié qu'à moitié : le code était devenu lecture seule, la donnée non — et le
« fingerprint inchangé » de ce document comparait donc un état corrompu à lui-même.
Restauré, puis parcours rejoué trois fois pour prouver le contrat sur une identité saine.

**Trois audits adverses indépendants ont rendu REWORK.** 11 findings remédiés, 2 écartés
après vérification, dont deux P1 de sûreté du harnais : `globalSetup` écrivait sans garde de
base (réécriture de mot de passe admin, résurrection d'un compte supprimé), et la garde
vérifiait la base du CLI alors que toutes les écritures partent du serveur HTTP. Voir
`reports/audit/CLAUDE_AUDIT_CAISSE-SUPERVISOR-CONTROL-20260823_2026-08-24.md`.

**Nouvel `AUDIT_VERDICT: PASS`.** Le `GPT_FINAL_AUDIT` reste **impossible** : le binaire natif
Codex est absent (`spawn .../vendor/aarch64-apple-darwin/codex/codex ENOENT`) et
`npm run codex:final-audit` sort en erreur sans produire de verdict. **Aucun verdict GPT
n'est fabriqué à partir d'un canal qui n'a pas tourné.** La clôture reste donc suspendue à
une décision humaine sur ce canal manquant.

**Mission Roue** : deux manques trouvés et corrigés — la branche « refus serveur » n'était
plus couverte par aucun test, et une suite modifiée avait été laissée rouge à 78/84 sans
figurer dans aucune preuve d'audit. Désormais 34/34 et 87/87. Le gate UX humain reste
`PENDING_HUMAN_GATE`.

**Escalades ouvertes, hors périmètre, pour le propriétaire** : garde de production absente
sur `foodking:ensure-admin` ; `BROADCAST_DRIVER` non-`pusher` renvoyant `ok` en dur dans
`HealthzController` ; requête SLA sans borne basse dans `DashboardService` ; et la
disposition de la page caisse, dont la grille de vente est sous la ligne de flottaison sur
un écran 1366×768 (`reports/audit/CAISSE_ERGONOMIE_CAISSIER_2026-08-24.md`).

### Suite du 2026-08-25 — sécurité composer et remise en état de la suite E2E

**Sécurité des dépendances** : `composer audit` donnait **56 avis** (2 critiques, 15 élevés),
non 3 comme l'annonce `PROJECT_BRAIN §5`. Mise à jour chirurgicale par lots — une mise à jour
globale était exclue, elle proposait `laravel/framework → 9.x-dev`. Résultat **56 → 7 avis,
0 critique**, `composer.json` inchangé, Laravel figé en v9.52.21. Les 7 restants ne se ferment
qu'en montant Laravel (chantier séparé). Aucune régression : les échecs backend observés après
la montée ont été rejoués sur le lock d'AVANT, comptes identiques.

**Deux défauts PRODUIT trouvés au passage**, tous deux cassant un déploiement réel :
`PermissionTableSeeder` n'était pas rejouable (`db:seed` plantait sur toute base migrée) et
`RolePermissionTableSeeder` ne filtrait pas le guard. Plus trois routes qui portaient
l'intergiciel d'idempotence sans l'exiger — crédit/débit de points fidélité et ajustement
d'inventaire. `tests/Feature` passe de **10 échecs à 0** (4 862 tests).

**La suite E2E avait pourri en silence** : dix specs sur onze rouges, dix causes de dérive de
fixtures. Le cycle précédent avait consigné « Collecte Playwright : PASS » — collecte, pas
exécution. **Neuf sur onze sont remises au vert** via un résolveur partagé qui décrit le besoin
du banc au lieu de coder un identifiant. `wave-D` et `wave-F` restent partielles, avec preuve
que le backend est correct dans les deux cas — non forcées.

**Escalades ajoutées** : huit specs partagent le préfixe `AUDIT-KIOSK-WAVE-E` et ne sont pas
sûres en parallèle ; le worker `queue:work` était absent de l'environnement (la pastille de
santé l'avait correctement signalé) ; `test.use({ reducedMotion })` est sans effet dans ce
dépôt.

Détail : `reports/audit/COMPOSER_SECURITE_2026-08-25.md`,
`reports/audit/E2E_DERIVE_FIXTURES_2026-08-25.md`, plan REPLAN_9.

---

## Reprise en une phrase

Reprendre le cycle `CAISSE-SUPERVISOR-CONTROL-20260823` en phase de validation de la remédiation REPLAN5, terminer la matrice de tests, relancer un nouvel audit Claude puis un nouvel audit final GPT, et ne fermer qu'en cas de double PASS. La roue est un cycle parent distinct, toujours garé à un gate UX humain et strictement hors périmètre.

## Ordre de lecture obligatoire

1. `AGENTS.md` en entier, puis les fichiers P0/P1 qu'il impose.
2. `.cursor/ACTIVE_CYCLE.md` — vérifier que le `TASK_ID` est toujours celui-ci et ne pas ouvrir de cycle fantôme.
3. `plans/PLAN_CAISSE-SUPERVISOR-CONTROL-20260823_2026-08-23.md` — surtout REPLAN5, qui a reçu `REPLAN_5_REVIEW_VERDICT: PASS`.
4. Le présent fichier.
5. `missions/CAISSE-SUPERVISOR-CONTROL-20260823/input.json`, `execute_brief.md`, `plan_excerpt.md`, `cycle_snapshot.md` et `graphiti_context.md`.
6. `reports/execution/RUN_CAISSE-SUPERVISOR-CONTROL-20260823_2026-08-23.md`.
7. Les audits :
   - `reports/audit/CLAUDE_AUDIT_CAISSE-SUPERVISOR-CONTROL-20260823_2026-08-23.md` ;
   - `reports/audit/GPT_FINAL_AUDIT_CAISSE-SUPERVISOR-CONTROL-20260823.md` ;
   - `reports/audit/GPT_SELF_AUDIT_CAISSE-SUPERVISOR-CONTROL-20260823.md`.
8. `reports/AGENT_ACTIVITY_LOG.md` et `bash scripts/agent-activity-log.sh tail 50` avant toute nouvelle édition.

Graphiti n'était pas chargé dans la session Codex. Le secours utilisé est `memory/INDEX.md` avec les fichiers de mission et le code comme sources de vérité.

## Autorisation utilisateur et objectif

L'utilisateur a demandé d'agir comme superviseur/auditeur de caisse, de trouver puis corriger les problèmes avec une précision et des tests maximums. Il a ensuite demandé que toutes les phases et le reste du plan soient documentés pour permettre une reprise Claude Code avant une éventuelle limite de quota.

Le mandat comprend l'amélioration de la supervision POS, de l'honnêteté des états de santé, de la file hors ligne, du cockpit SLA, de l'accessibilité opérateur/borne et de la sûreté des preuves E2E. Il n'autorise pas une expansion vers les prix, paiements, migrations, services de cycle de commande ou zones gelées.

## État du cycle au moment de cette mise à jour

| Élément | État |
| --- | --- |
| `TASK_ID` | `CAISSE-SUPERVISOR-CONTROL-20260823` |
| Phase SSOT | `VALIDATE` ; les parcours principaux sont verts, la matrice finale puis les deux audits restent obligatoires |
| Plan | REPLAN1 à REPLAN5 revus ; REPLAN5 = PASS |
| Premier audit Claude | PASS, mais invalidé comme verdict de clôture par des modifications ultérieures |
| Premier audit final GPT | REWORK, cycle de remédiation 1/5 |
| Correctifs REPLAN5 | Implémentés et tests ciblés/E2E principaux verts |
| Double audit après remédiation | Pas encore relancé |
| Clôture | Interdite tant que le nouvel audit Claude et le nouvel audit final GPT ne sont pas tous deux PASS |
| Cycle Wheel | Hors scope, gate UX humain toujours pending |

## Phases déjà réalisées

### Phase 0 — Bootstrap et gouvernance

- Parcours FoodKing, cycle actif, plan, mission et mémoire de secours lus.
- Cycle borné créé avec fichiers de plan, mission et rapport.
- Réservation d'activité effectuée pour les fichiers produit ; le worktree était déjà très sale et appartient en partie à l'utilisateur/d'autres cycles. Ne jamais nettoyer, reset ou écraser les changements hors scope.
- La roue a été séparée et garée dans `GOAL-WHEEL-EXPERIENCE-20260823` avec son gate UX ; ne pas la réintégrer ici.

### Phase 1 — Audit superviseur et planification

- Audit fonctionnel, visuel, accessibilité, offline, santé POS, SLA et E2E.
- Plan complexe révisé jusqu'au PASS avec garde exacte `branch_id`, pricing backend SSOT, enum `OrderStatus`, dispatch après commit, zones gelées et conservation fiscale.
- Plusieurs replanifications documentent les découvertes successives sans élargir silencieusement le scope.

### Phase 2 — Première implémentation

- Santé POS fail-closed et exactement branch-scopée.
- Pastille de santé accessible avec état frais/périmé/inconnu.
- File offline héritée quarantainée sans replay aveugle.
- Cockpit SLA compact, borné et sans rafraîchissements concurrents.
- Accessibilité clavier des presets, champs/actions POS, cartes produit borne et départ borne.
- Configuration Playwright alignée sur l'URL effective.
- Parcours Wave E et multi-produit renforcés.
- Cleanup E2E partagé rendu canonique et non destructif.

### Phase 3 — Première validation

- 39 tests backend ciblés passés.
- 89 tests Vitest ciblés passés.
- Build frontend passé.
- `pos:lint:status` passé.
- Wave E et multi-produit passés lors de la première passe.
- Audit navigateur finalisé : clavier borne, carte produit, cockpit SLA et champs POS vérifiés.
- Diff des fichiers gelés vide.

### Phase 4 — Double audit initial

- Audit Claude terminal : PASS.
- Audit final GPT primaire indisponible : le binaire natif Codex attendu sous `node_modules/@openai/codex-darwin-arm64/.../codex` manque ; l'appel direct `codex --version` s'est bloqué.
- Fallback officiel via `foodking-complex-implementer` : REWORK.
- Findings reproduits : double garde E2E insuffisante, panne de queue exclue de la sévérité, commande synthétique active, mutation d'identité borne/utilisateur, messages de checks inconnus peu actionnables. Le finding « mission drift » était stale et n'a pas été reproduit dans `input.json` courant.

### Phase 5 — REPLAN5 et remédiation

- REPLAN5 a été revu en plusieurs tours puis déclaré PASS.
- La panne de queue est maintenant incluse dans `sync` et `overall` avant construction du payload.
- Toute sonde `unknown` affiche son message exact et propose une relance.
- Les écritures E2E exigent simultanément `FOODKING_E2E_DEDICATED_DB=1` et un nom de base contenant `test`, `e2e` ou `playwright`. `APP_ENV=testing` est volontairement ignoré.
- `getKioskApiToken`, `placeKioskOrder` et `cleanupKioskAuditOrders` gardent les écritures avant toute mutation.
- Le cleanup partagé utilise `OrderService::changeStatus`, la branche exacte de la machine existante, conserve les traces et échoue s'il reste une commande active.
- Wave E transforme toute annulation ratée en échec et effectue un sweep canonique final.
- Le parcours multi-produit traite machine/utilisateur comme configuration read-only, compare leurs fingerprints exacts avant/après, annule les commandes via l'API POS, désactive seulement le catalogue synthétique de la branche et invalide seulement `kiosk.menu.branch.{branchId}`.
- Le cache menu d'une autre branche est comparé strictement avant/après dans le test.

## Correctifs REPLAN5 actuels par fichier

- `app/Http/Controllers/Admin/PosSystemHealthController.php`
  - `queuePendingCount()` est résolu avant les blocs `sync` et `overall`.
  - Une erreur de queue donne `queue_pending=null`, `sync.status=unknown`, `overall=degraded` et un message actionnable.
- `tests/Feature/Pos/PosSystemHealthTest.php`
  - Vérifie les quatre postconditions précédentes, pas uniquement la valeur `null`.
- `resources/js/components/admin/pos/PosSystemHealthPill.vue`
  - Rend visibles tous les messages non-sync inconnus et le bouton `Réessayer` pour tout check inconnu.
- `tests/js/posSystemHealthPill.spec.js`
  - Couvre les messages inconnus et leur actionnabilité.
- `tests/e2e/helpers/kiosk-order.js`
  - Double garde réutilisable.
  - Garde courante avant émission de token, commande et cleanup.
  - Sweep canonique branch-scopé avec `remaining_active_order_ids` obligatoire à zéro.
- `tests/e2e/test-e2e-pos-kds-sync-2026-05-10-wave-E.spec.js`
  - Teardown API + sweep canonique, zéro `console.warn` qui masquerait un cleanup raté.
  - Fiabilité : `reloadPosTracker()` lit maintenant le JSON immédiatement après la réponse, avant que Chromium puisse libérer la ressource CDP.
- `tests/e2e/audit-kiosk-multiproduct-kds-journey.spec.js`
  - Identités persistantes read-only, fingerprints avant/après, clé cache autre branche, annulation API, désactivation branch-scopée, deux `Cache::forget($cacheKey)` exacts et postconditions à zéro.
  - Aucun secret brut n'est sérialisé : les mots de passe sont remplacés par une empreinte secondaire. Le snapshot est limité aux champs exacts de REPLAN5 ; `is_login` et les timestamps restent exclus.
  - Les logs n'impriment jamais les hashes de mot de passe ; seulement l'id machine et la branche.
- `tests/js/kioskAuditCleanupSafety.spec.js`
  - Garde les trois fonctions mutatrices, matrice de double opt-in, absence de suppression/reset fiscal/cache global, onze appelants réels et invalidations de cache ciblées.

## Validation de remédiation déjà obtenue

| Commande/preuve | Résultat actuel |
| --- | --- |
| `node --check` sur helper partagé, Wave E et multi-produit | PASS |
| `php artisan test tests/Feature/Pos/PosSystemHealthTest.php` | PASS — 17 tests |
| `npx vitest run tests/js/posSystemHealthPill.spec.js tests/js/kioskAuditCleanupSafety.spec.js` | PASS — 29 tests |
| Collecte Playwright des 11 consommateurs du helper | PASS — 49 tests listés dans 11 fichiers |
| Multi-produit réel avec double opt-in | PASS — 1 test, 1,1 min |
| Wave E réel avec double opt-in, dernier run autoritaire | PASS — 1 test, 93,5 s ; run 103,9 s |
| Postcondition DB read-only | PASS — zéro commande Wave active, zéro commande multi active, zéro item/catégorie/taxe multi actif |
| Conservation de l'historique multi | PASS — 14 commandes historiques toujours présentes, non supprimées |
| Fingerprint machine avant/après | Inchangé : `fd8f4d1c6f5e6311584d91630b4cef918d4c723ee77148b62dc0860ff7130b44` |
| Fingerprint utilisateur avant/après | Inchangé : `a59e356cbe370f064170fe25071a93d6f9ad01a961b6bfcd5a55166df2b9827c` |
| Cache autre branche | Assertion stricte avant/après passée dans le test multi-produit |

Les fingerprints ci-dessus sont des SHA-256 de sous-ensembles de configuration et ne contiennent aucun secret en clair.

Preuve Wave E autoritaire après tous les incidents intermédiaires : `reports/antigravity/playwright-latest.json`, mtime locale `2026-08-24 00:36:04`, `expected=1`, `unexpected=0`, `errors=[]`. Le run couvre les commandes 6639–6641, la queue `A0042`, le total backend 2,50 €, la séquence fiscale 2742, les transitions KDS/POS, l'encaissement/service, l'annulation canonique, zéro erreur réseau silencieuse et 16 captures. La requête DB exécutée après la fin du processus retourne zéro commande Wave E dans les statuts actifs canoniques.

## Incidents de validation à ne pas confondre avec des régressions produit

1. Première relance multi-produit : échec avant toute écriture, car `Illuminate\\Support\\Facades\\Cache` était mal échappé dans le code PHP injecté par le test. Corrigé ; relance verte.
2. Première relance Wave E : le poste a suspendu le réseau pendant 5 min 47 s (`ERR_NETWORK_IO_SUSPENDED`, puis `ERR_NETWORK_CHANGED`). Le timeout global a expiré mais le hook de teardown a quand même annulé la commande. Aucun ordre actif n'est resté.
3. Deuxième relance Wave E : Chromium avait libéré le corps d'une réponse valide avant `response.json()`, causant `No resource with given identifier found`. La lecture JSON a été déplacée immédiatement après `waitForResponse`, sans affaiblissement d'assertion.
4. Troisième relance Wave E : PASS complet, 16 captures, trois commandes suivies, transitions KDS/POS et teardown canonique passés.

## Invariants non négociables

- Prix : backend seul SSOT ; aucune logique métier de prix frontend.
- `OrderStatus` : enums et transitions canoniques uniquement ; aucune chaîne magique ni update direct.
- `branch_id` : exact, obligatoire et sans fuite inter-branches.
- Dispatch : seulement après commit DB ; chemins produit de dispatch inchangés.
- Fiscal/NF525 : conserver commandes, lignes, transitions, événements, audits et séquences ; jamais de delete/reset.
- Zones gelées : ne pas toucher sans gate humain enregistré.
- `OrderService` / `FrontendOrderService` : symétrie obligatoire si l'un change. Ici aucun des deux n'est modifié ; `SYMMETRY_NOTE: N/A`.
- La double garde E2E doit refuser toute écriture si l'un des deux signaux manque.

## Périmètre explicitement interdit

- Tous les fichiers produit de la roue, son plan, son gate et ses services.
- Kiosk wizard gelé, paiement, pricing, remises métier, migrations, routes, schéma, configuration de production.
- `OrderService`, `FrontendOrderService` et `AuditLogService` côté produit.
- `_teste2e-heal-audit-2026-07-18.spec.js` : ce fichier ne fait que mentionner le helper partagé dans des commentaires et possède son propre cleanup destructif. Ne pas l'exécuter ni le modifier dans ce cycle ; ouvrir un cycle/gate séparé.
- Toute commande de nettoyage Git ou DB destructive. Le worktree contient des changements appartenant à l'utilisateur et à d'autres travaux.

## État connu des baselines externes

- `npm run pos:lint:pricing` remonte cinq findings historiques/signoff : un bloc expiré dans `PosComponent.vue`, un calcul dans `PosCounterCollectModal.vue` et trois allow blocks de wizard gelé. La diff scoped de ce cycle n'ajoute aucune logique de prix. Toute correction exige autorisation pricing/frozen dédiée.
- Le test historique d'idempotence bloque sur le produit fixé `Coca-Cola 33cl`, indisponible et renvoyé HTTP 422 avant son sujet. Son cleanup partagé est désormais canonique et idempotent ; ne pas modifier un produit métier pour forcer le test dans ce cycle.
- Les rapports DOM/PNG générés contiennent du whitespace historique. Utiliser un `git diff --check` scoped qui exclut `reports/test-e2e/**` pour le contrôle source ; conserver les artefacts de preuve.

## Travail restant exact

### A. Finir VALIDATE

1. Rejouer la matrice backend complète du plan, en commandes séparées :
   - `php artisan test tests/Feature/Branch/OrderBranchIsolationTest.php`
   - `php artisan test tests/Feature/Outbox/OutboxDeliveryTest.php`
   - `php artisan test tests/Feature/KdsExpectedStatusConflictTest.php`
   - `php artisan test tests/Feature/PosPricingSsotProofTest.php`
   - `php artisan test tests/Feature/OrderStatusNoopSideEffectsTest.php`
   - `php artisan test tests/Feature/Order/ChangeStatusRaceGuardTest.php`
   - `php artisan test tests/Feature/Order/TerminalOrderResurrectionGuardTest.php`
2. Rejouer la suite Vitest ciblée complète du rapport/plan, pas seulement les deux fichiers REPLAN5.
3. Rejouer `npm run pos:lint:status`, le build frontend et les sentinelles Playwright config/accessibilité.
4. Exécuter les deux parcours obligatoires encore à confirmer dans la passe finale : `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 npx playwright test tests/e2e/kds-caisse-smoke.spec.js tests/Playwright/multi-device-appareils-2026-08-07.spec.js --retries=0`.
5. Refaire la requête DB read-only de postcondition après le dernier E2E et consigner zéro actif + fingerprints inchangés.
6. Vérifier la diff gelée vide et le `git diff --check` scoped hors artefacts DOM générés.
7. Mettre à jour le rapport d'exécution avec les nouvelles preuves et les incidents Wave E classés correctement.
8. Passer `.cursor/ACTIVE_CYCLE.md` de `VALIDATE` à `AUDIT` uniquement lorsque tout est vert.

### B. Relancer le double audit après remédiation

1. Audit Claude neuf — l'ancien PASS ne clôt plus le cycle car du code a changé depuis :
   - `bash scripts/foodking-claude-orchestrate.sh context`
   - lancer l'audit terminal ciblé sur REPLAN5 et écrire/actualiser le rapport Claude.
2. Si Claude demande REWORK : repasser par replan, review, execute, validate ; incrémenter le cycle de remédiation sans dépasser 5.
3. Si Claude donne PASS : tenter `npm run codex:final-audit -- CAISSE-SUPERVISOR-CONTROL-20260823`.
4. Le CLI Codex natif était cassé dans la session précédente. Après une tentative documentée, utiliser le fallback officiel `foodking-complex-implementer` avec `GPT_FINAL_AUDIT_CHANNEL` et `FALLBACK_REASON` si nécessaire.
5. Après tout audit fallback, recontrôler immédiatement `git status` et les diffs : un reviewer précédent a édité des fichiers malgré une consigne read-only.

### C. Clôturer uniquement sur double PASS

1. Exiger un nouveau `AUDIT_VERDICT: PASS` Claude et un nouveau `GPT_FINAL_AUDIT_VERDICT: PASS` GPT sur la même version du code.
2. Mettre à jour plan, rapport, checklist d'audit et `.cursor/ACTIVE_CYCLE.md` conformément à `run-cycle`.
3. Tracer `GRAPHITI_WRITE: skipped — unavailable` si Graphiti reste absent, puis appliquer la procédure mémoire locale requise par le cycle.
4. Fermer la réservation avec `bash scripts/agent-activity-log.sh done ...` en utilisant les mêmes identifiants/chemins que la réservation active.
5. Arrêter le serveur local de validation s'il tourne encore sur `127.0.0.1:8766`.
6. Ne pas committer, pousser, déployer ou approuver un gate sans demande humaine explicite.

## Commandes de validation déjà utilisées

```bash
php artisan test tests/Feature/Pos/PosSystemHealthTest.php
npx vitest run tests/js/posSystemHealthPill.spec.js tests/js/kioskAuditCleanupSafety.spec.js

FOODKING_E2E_DEDICATED_DB=1 \
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
npx playwright test tests/e2e/audit-kiosk-multiproduct-kds-journey.spec.js --retries=0

FOODKING_E2E_DEDICATED_DB=1 \
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
npx playwright test tests/e2e/test-e2e-pos-kds-sync-2026-05-10-wave-E.spec.js --retries=0
```

Pour la collecte des 11 appelants, reprendre la liste autoritaire de `tests/js/kioskAuditCleanupSafety.spec.js`. Elle doit produire 49 tests dans 11 fichiers. Ne jamais ajouter `_teste2e-heal-audit-2026-07-18.spec.js` à cette commande.

## Contrôle DB post-E2E attendu

Sur `foodking_e2e`, branche 1 :

- aucune commande active dont le token commence par `AUDIT-KIOSK-WAVE-E` ;
- aucune commande active liée à un item `AUDIT-KIOSK-MULTI` ;
- zéro item, catégorie et taxe `AUDIT-KIOSK-MULTI` actifs ;
- les commandes historiques restent physiquement présentes ;
- la machine `kiosk-lecayenne` et son utilisateur conservent exactement leurs fingerprints ;
- aucune clé cache d'une autre branche n'est modifiée.

Utiliser exclusivement des requêtes read-only pour cette preuve. Les teardowns de tests sont les seuls chemins autorisés de neutralisation et passent par les API/services canoniques.

## Prompt prêt à remettre à Claude Code

> Reprends la mission FoodKing `CAISSE-SUPERVISOR-CONTROL-20260823` depuis `missions/CAISSE-SUPERVISOR-CONTROL-20260823/CLAUDE_CODE_HANDOFF.md`. Lis d'abord intégralement `AGENTS.md`, `.cursor/ACTIVE_CYCLE.md`, le plan actif et tous les fichiers de mission obligatoires. Ne crée pas de nouveau cycle. La remédiation REPLAN5 a été implémentée ; les tests ciblés, le multi-produit réel et Wave E réel sont verts, mais la validation complète et les deux audits post-remédiation restent à faire. Respecte le workflow PLAN/REVIEW/EXECUTE/VALIDATE/AUDIT/GPT_FINAL_AUDIT : Claude orchestre et audite, il ne modifie pas directement les fichiers produit. N'élargis pas le scope, ne touche pas la roue, aux zones gelées, au pricing, au paiement, aux migrations, à `OrderService`/`FrontendOrderService`, ni à `_teste2e-heal-audit-2026-07-18.spec.js`. Préserve le worktree sale. Ferme uniquement sur un nouveau double PASS documenté.

## Règle de mise à jour de ce fichier

Après chaque bloc significatif, actualiser au minimum : l'état du cycle, la matrice de validation, les incidents, le travail restant et les verdicts d'audit. Ne jamais annoncer PASS/CLOSED ici avant que les artefacts autoritaires portent les mêmes verdicts.
