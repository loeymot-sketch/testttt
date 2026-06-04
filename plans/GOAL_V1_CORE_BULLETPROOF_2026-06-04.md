# GOAL — V1 LOCAL « Le Cayenne » : CŒUR bulletproof + zéro crash (version fonctionnelle, pré-cloud)

> Type : ultra-architect-planify (production-readiness multi-systèmes). **PLAN — exécution seulement après validation owner.**
> Date : 2026-06-04 · Branche : `heal/cms-pr1-quickwins-2026-05-18` · Ancré sur le code réel (anchors vérifiés ci-dessous).
> Doctrine : CLAUDE.md §5 LOOP · CONSTITUTION.md · SYSTEM_MAP.md · SYNC_CONTRACT.md.

---

## §0 — PRÉAMBULE

### 0.1 Mandat owner (verbatim reframé)
- **CŒUR INTOUCHABLE — doit être fonctionnel + ZÉRO crash / zéro problème grave** :
  `prise de commande → validation de commande → transfert de commande entre TOUS les systèmes → synchronisation`.
- **Tout le reste = SECONDAIRE**, amélioré au fil du temps (fonction par fonction, à la demande owner).
- **Horizon cloud** : le passage cloud (déploiement de TEST avec domaine + hébergement ~1 an) vient **APRÈS** validation de cette version locale fonctionnelle. Donc : Tier-0 d'abord, cloud documenté-mais-non-exécuté.
- **Contrainte absolue** : *ne pas affecter le fonctionnement actuel du projet — éviter toute cause de mal.*

### 0.2 Principe de sûreté (ce qui rend « corriger sans casser » structurellement vrai)
Tous les fixes Tier-0 de ce GOAL sont **ADDITIFS et HORS frozen-zone** :
- nouveau script de démarrage/healthcheck (ne touche aucun fichier existant),
- flip d'un flag d'affichage KDS (fichier KDS auditable, non-frozen),
- planification d'une commande artisan **déjà existante**,
- **vérification** (exécuter des tests existants + drills), pas de réécriture de logique.
**Aucune** logique commande/prix/fiscal/sync n'est réécrite. **Frozen-zone diff = 0** obligatoire à chaque wave. **NF525 CHAIN OK** à chaque wave.

### 0.3 Décision working-tree (Wave 0)
Non commité actuellement : fix staff-only (`config/features.php`, `resources/views/master.blade.php`) + `PROJECT_BRAIN.md` + `reports/diagrams/FOODKING_STRUCTURE_V1_2026-06-04.html` + `.env` (gitignoré). → **Wave 0 propose un commit checkpoint** de ces changements (déjà live + vérifiés) AVANT toute nouvelle action, pour repartir d'un état propre. Owner OK requis (gate light, pas de push).

### 0.4 Pipeline & convergence
- Pipeline par tâche : `ultra-audit-profond` (réf — non re-décrit ici).
- **Convergence** = 2 cycles consécutifs avec **P0+P1 = 0 et set de findings identique** (règle test-e2e).
- Gate par wave : tests verts OU baseline-fail documenté · frozen-diff 0 · NF525 `count + last_hash` append-only · visuel analysé si frontend · RED dispute · BRAIN §2/§3 maj.

### 0.5 Anti-machinerie (leçon mémoire 51-agent backfire)
Pas de Workflow billed (owner n'a pas dit « ultracode »). Le GOAL est écrit à la main ; subagents **ciblés** seulement à l'exécution (audit read-only 3-5 spécialistes par tâche risquée). Doc volontairement tight (exécutable > exhaustif).

---

## §1 — CARTE & MATURITÉ (anchors vérifiés 2026-06-04)

| Système | Tier | Maturité | Anchor réel | État live |
|---|---|---|---|---|
| CAISSE (POS) | **0** | haute | `app/Services/OrderService.php`, `PaymentService.php`, wizard Vanilla JS (FROZEN) | charge ✅ |
| BORNE (kiosk) | **0** | haute | `app/Services/FrontendOrderService.php`, `app/Services/Kiosk/**`, wizard Vue (FROZEN) | cycle borne→KDS validé (récent) |
| TRANSFERT/SYNC | **0** | moyenne-haute | bus Outbox : `app/Events/{OrderCreated,OrderStatusChanged,KdsOrderRecalled}.php` → `domain_events` → `DispatchDomainEventsJob` → soketi `:6001` → `private-branch.1` | ⚠ soketi non démarré → repli polling |
| KDS + OSS | **0** | haute | `KitchenDisplaySystemComponent.vue`, `OrderStatusScreenController.php`, `KitchenDisplaySystemOrderService.php` | chargent ✅ (board vide) |
| FISCAL/NF525 | **0 (gardien)** | haute | `app/Services/Fiscal/*` (FROZEN), `FiscalSequenceService` | CHAIN OK |
| PRIX (SSOT) | **0 (gardien)** | haute | `app/Services/Pricing/PricingService.php` (FROZEN), `composition_snapshot` figé | code vérifié |
| STOCK | 1 | haute | `app/Services/Stock/StockService.php`, `ItemBranchAvailability`, `StockLevel` | décrément/commande + release |
| CENTRAL (gérance) | 1-2 | haute | `app/Http/Controllers/Admin/**` (100 ctrl), 23 boutons sidebar | nav rendue FR ✅ |
| WEB + APP | 2 (standalone) | n/a V1 | `/Users/1millnonstop/Downloads/web`, `mobile/` (PAS branché API V1) | hors flux |

**Cœur (Tier-0) = la colonne vertébrale CAISSE+BORNE → ORDER → FISCAL/PRIX → SYNC/TRANSFERT → KDS/OSS.** C'est le seul périmètre exécuté maintenant.

**État daemons live 2026-06-04** : `serve :8000` ✅ · `queue:work redis` ✅ · `redis :6379` ✅ (PONG) · `soketi :6001` ❌ (installé `~/.nvm/.../bin/soketi`, non démarré). Transport configuré : `BROADCAST_DRIVER=pusher` → `PUSHER_HOST=127.0.0.1:6001`.

---

## §2 — REGISTRE DES PROBLÉMATIQUES (liste exhaustive des défauts trouvés)

| ID | Sévérité (mandat) | Problématique | Cause racine (file:line) | Impact CŒUR | Fix (additif) | Risque-de-fixer | Rollback |
|---|---|---|---|---|---|---|---|
| **PR-01** | P1 (dégradé, pas cassé) | soketi non démarré → pas de push temps-réel | daemon non lancé ; transport OK | transfert marche en **polling** (~30-60s, **0 perte**) | script démarrage + healthcheck (Wave 1.2) | nul (additif) | ne pas lancer le script |
| **PR-02** | **P0 (silencieux=grave)** | dégradation sync **invisible** en local : la cuisine ne sait pas qu'elle est en retard | `KitchenDisplaySystemComponent.vue:40,60` flag `kdsHideFallbackBannerInLocalDev` | masque un retard cuisine = risque service | flip flag → bandeau visible (Wave 1.1) | très faible (1 flag, KDS non-frozen) | revert 1 ligne |
| **PR-03** | P1 | `artisan serve` mono-process crashe sous charge concurrente | dev server 1-req-à-la-fois (mémoire 2026-06-01) | crash serveur = arrêt service | garde opérationnel + soak SOLO ; vrai fix = php-fpm (cloud) | nul (doc + garde) | n/a |
| **PR-04** | P2 | staleness outbox = `Log::error` seul, « no alert surfaced » | `MonitorOutboxStaleness.php:20,129` | pipeline dégradé non remonté | planifier `foodking:outbox:monitor` + surface visible (Wave 1.3) | faible (planif + lecture) | déprogrammer |
| **PR-05** | P3 (cosmétique) | `/menu` → 404 serveur | dossier assets `public/menu/le-cayenne-v2/` (86 images) masque la route SPA | nul (vitrine éteinte voulue) | décision owner : laisser vs renommer assets (Wave 3) | moyen si rename (chemins DB) | ne rien faire |
| **PR-06** | P2-P3 (différé) | backlog hardening owner-gated | ZRPT refund countersign (LOCK pending), `COUPON-CAP-01`, brute-force lockout boot-guard, FormRequest authz ratchet | hors cœur | **DIFFÉRÉ** (Wave 4 doc, non exécuté) | n/a | n/a |
| **PR-07** | P2 (cloud-prep) | `config:cache` casse `env()` en Blade — **classe de bug** | sibling connu `master.blade.php:184` `kioskUsePosWizard` (même pattern) + autres à balayer | nul en local (config non cachée) | sweep `env()` en blade/runtime → `config()` (Wave 5, cloud) | faible (additif config) | revert par fichier |
| ~~PR-00~~ | ~~fait~~ | ~~vitrine /home /offers abandonnée~~ | ~~staff-only OFF~~ | ~~—~~ | **CORRIGÉ cette session** (staff_only_mode=true) | — | — |

---

## §3 — ⭐ MATRICE DES CIRCONSTANCES DE PANNE DU CŒUR (la pièce maîtresse)

> *« la liste de toutes les circonstances »* = pour `capture → validation → transfert → sync`, qu'arrive-t-il dans chaque circonstance ? Invariant absolu : **AUCUNE perte de commande**. La latence est acceptable ; la perte/corruption ne l'est pas.

| ID | Circonstance (déclencheur) | Dégradation attendue | Perte donnée ? | Chemin de récupération | Test/preuve qui le prouve |
|---|---|---|---|---|---|
| **C-01** | `queue:work` meurt en plein ordre | broadcasts stoppés ; écrans passent en polling | **NON** | `domain_events` persistés `dispatched_at=NULL` ; rejoués au redémarrage worker | `tests/Feature/Outbox/OutboxDeliveryTest.php` · `OutboxRescueStaleClaimedRowsTest.php` |
| **C-02** | `soketi` meurt | `WebSocketService` → UNAVAILABLE ; repli polling (KDS ~30s, OSS ~60s) | **NON** | outbox rejoue à la reprise soketi | `tests/Feature/Outbox/OutboxBroadcastSwallowedListenerTest.php` · drill manuel (Wave 2) |
| **C-03** | `redis` down | cache/queue/locks indispo | NON (commande en DB) mais alloc fiscale peut différer | `Cache::lock` échoue → retry ; cron `fiscal:retry-alloc` | `tests/Feature/Fiscal/*` (alloc retry) · drill |
| **C-04** | réseau coupé (borne/caisse offline) | borne : file offline locale ; POS : bannière connexion | **NON** | `kioskOfflineQueue.js` rejoue ; POS poll fallback | specs abuse `test-e2e-abuse-M-kiosk-offline` · `O-network-errors` |
| **C-05** | charge concurrente / rush | latence ↑ ; **monotonie fiscale préservée** | **NON** | `Cache::lock 5s` + `FOR UPDATE` triple défense séquence | `foodking:e2e:stress` · `OutboxConcurrentWorkerDedupeTest.php` |
| **C-06** | crash mid-transaction (écriture partielle) | rollback DB | **NON** | transaction atomique (order+snapshot+stock+fiscal même TX) | `tests/Feature/Order/*` · `tests/Feature/Sentinels/*AfterCommit*` |
| **C-07** | POST dupliqué (double-clic / retry) | 2e requête rejetée/replay | **NON (pas de double)** | `IdempotencyKeyMiddleware` (FROZEN) cache + UNIQUE DB ; 409 si payload diff | `tests/Feature/Orders/IdempotencyBranchScopedTest.php` · abuse `P-idempotency` |
| **C-08** | redémarrage app mid-flight | requêtes en vol perdues côté client | **NON (côté serveur)** | client retry idempotent ; outbox rejoue | `OutboxProductionLikeSimulationTest.php` |
| **C-09** | alloc fiscale échoue (kiosk payé) | order flag `fiscal_alloc_error_at`, **pas de crash ni de trou** | NON | cron `foodking:fiscal:retry-alloc` | `tests/Feature/Fiscal/*` (retry-alloc) |
| **C-10** | Plan-B non encaissé (kiosk → comptoir jamais collecté) | reste `PENDING_COUNTER`, fiscal-NULL (correct) | NON | n'est PAS une vente → ne consomme pas plafond/fiscal | `tests/Feature/Pos/*` counter-collect |
| **C-11** | soketi ET queue down simultanés | tout en polling | **NON** | DB = source de vérité ; rejeu à la reprise | drill combiné (Wave 2) |

**Sortie attendue Wave 2** : chaque ligne → **drill reproduit + vert** OU baseline documenté. Toute ligne avec « perte donnée = OUI » non prouvée-impossible ⇒ **P0 blocage**.

---

## §4 — CŒUR Tier-0 : décomposition (les seules sub-systèmes exécutés maintenant)

### Sub 4.1 — Visibilité : aucune dégradation silencieuse  *(la chose la plus haute valeur)*
**Anchors** : `KitchenDisplaySystemComponent.vue:40,60` · `app/Console/Commands/MonitorOutboxStaleness.php:129` · `resources/js/components/common/ConnectionStatusBanner.vue`
- **T-4.1.1** Flip `kdsHideFallbackBannerInLocalDev` → la cuisine VOIT le mode polling (même en local). *Accept :* visuel KDS avec soketi down montre bandeau « mode polling » ; `tests/Feature/Kds/*` toujours verts + capture analysée. (PR-02)
- **T-4.1.2** Vérifier bannière connexion POS/borne s'affiche quand soketi down. *Accept :* capture POS + borne montrent l'état dégradé ; pas de faux « connecté ».
- **T-4.1.3** Surfacer l'alerte outbox (planifier `foodking:outbox:monitor` toutes 1-5 min + lecture). *Accept :* `tests/Feature/Outbox/OutboxPipelineHealthSentinelTest.php` vert ; alerte visible (log + widget/notif). (PR-04)

### Sub 4.2 — Fiabilité du transport (la boîte démarre proprement)
**Anchors** : `soketi.json` · `soketi` global · `.env:17 BROADCAST_DRIVER=pusher` · aucun Procfile (à créer)
- **T-4.2.1** Script additif `scripts/start-foodking.sh` : démarre + healthcheck les 4 daemons (serve, queue:work, soketi, redis), idempotent, ne tue rien. *Accept :* `(test TO BE CREATED at tests/Feature/Ops/DaemonHealthcheckTest.php)` OU script `--check` retourne 4/4 UP. (PR-01)
- **T-4.2.2** Commande `foodking:health` (ou `--check` du script) : statut des 4 daemons + soketi `:6001` + redis PONG + dernière migration. *Accept :* sortie listant 4/4 ; rouge si un down. Distinguer explicitement « non démarré » (T-4.2.1) vs « crash sous charge » (T-4.3.3).

### Sub 4.3 — Preuve « ne perd JAMAIS une commande » (drills + soak SOLO)
**Anchors** : `foodking:e2e:soak` · `foodking:e2e:stress` · matrice §3
- **T-4.3.1** Drills §3 C-01..C-11 : reproduire chaque circonstance, prouver 0 perte + récupération. *Accept :* chaque drill vert OU baseline doc ; tests cités §3 verts.
- **T-4.3.2** Cross-surface E2E réel borne→KDS→OSS + caisse→KDS→encaissement (au clic Playwright). *Accept :* commande traverse les 3 surfaces, fiscal alloué au bon moment, snapshot figé, 0 dup.
- **T-4.3.3** **Soak SOLO** `foodking:e2e:soak` (4h+, **rien d'autre ne frappe la boîte** — leçon mémoire 2026-06-01) : RSS flat, séquence fiscale gap-free, CHAIN OK, outbox ~0. *Accept :* run SOLO sans faute = preuve no-crash. (PR-03)

---

## §X — VAGUES DE CONVERGENCE

| Wave | Scope | Parallélisme | Checkpoint | Interrupt-resume |
|---|---|---|---|---|
| **W0 Pre-flight** | backup branche `backup/pre-core-bulletproof-2026-06-04` + DB dump ; baselines (`phpunit` count, `audit_logs count+last_hash`) ; commit checkpoint §0.3 | séquentiel | backup créé + baselines capturées | n/a (court) |
| **W1 Tier-0 VISIBILITÉ + FIABILITÉ** | Sub 4.1 (T-4.1.1/2/3) + Sub 4.2 (T-4.2.1/2) — **tous additifs/non-frozen** | séquentiel ; audit read-only 3 spécialistes (Architect/SRE/UX) en // | frozen-diff 0 · KDS/POS/borne captures analysées · tests Kds+Outbox verts · BRAIN maj | commit `wip(w1): …` + manifest INTERRUPT |
| **W2 Tier-0 PREUVE CŒUR** | Sub 4.3 — matrice §3 drills + cross-surface E2E + soak SOLO | drills séquentiels ; **soak SOLO = isolé, rien en //** | C-01..C-11 prouvés/baseline · NF525 CHAIN OK · 0 perte · RED dispute | manifest + dernier SHA vert |
| **W3 Secondaire** | PR-05 `/menu` décision (gate G2) · résidus cosmétiques bas | séquentiel | owner a tranché /menu | — |
| **W4 DIFFÉRÉ (doc only)** | PR-06 backlog hardening — **listé, NON exécuté** | — | n/a | — |
| **W5 CLOUD-READINESS (doc only, gate G-CLOUD)** | php-fpm+nginx (≠ artisan serve) · sweep `env()`→`config()` PR-07 · boot guards prod (`POS_SIMULATION_HARDWARE=false`, `APP_DEBUG=false`, `CACHE_DRIVER`) · supervisor daemons (systemd/pm2) · secrets/.env · backups · soketi/redis managés · domaine | — | **owner décide le moment** | — |

**Règle parallélisme** : Wave par défaut séquentielle (le cœur partage `OrderService`/sync = état partagé). Seuls les **audits read-only** fan-out en // dans une wave. Jamais 2 implementers en //.

---

## §A — ARMÉE D'AGENTS (léger : ce GOAL est verif-heavy, pas rewrite-heavy)

| Rôle | Type | Tools | Quand |
|---|---|---|---|
| SRE/Sync | general-purpose | Read | W1/W2 — audit bus outbox + daemons + dégradation |
| Architect | Plan | Read | W1 — cohérence des fixes additifs |
| QA Visual | general-purpose | Read+Playwright | W1/W2 — captures KDS/POS/borne dégradé + cross-surface |
| RED-team | general-purpose | Read | après chaque fix — disputer (perte cachée ? faux « connecté » ?) |
| Implementer | (main thread) | Edit/Bash | fixes additifs scope-minimal, jamais 2 en // |

Fan-out : 3 read-only (SRE+Architect+QA) en 1 message au début de W1/W2. Reports persistés disque (`reports/test-e2e/core-bulletproof-2026-06-04/`).

---

## §G — GATES OWNER (WHO / WHAT / WHERE)

| Gate | Description | WHO | WHAT | WHERE | Statut |
|---|---|---|---|---|---|
| **G0** | OK commit checkpoint du fix staff-only + docs (W0) | Owner (sign-off) | « go commit » | ce chat | PENDING |
| **G1** | Approuver l'approche supervision daemons (script additif) | Owner | « go W1 » | ce chat | PENDING |
| **G2** | Décision `/menu` : laisser le 404 vs renommer `public/menu` (+maj chemins) | Owner | choix A/B | ce chat / BRAIN | PENDING |
| **G3** | Countersign LOCK refund-Z (PR-06) | Owner physique | signature `plans/LOCK_ZREPORT_*` §10 | LOCK doc | PENDING (hors V1) |
| **G-CLOUD** | Lancer le jalon cloud test-deploy (domaine + hébergement) | Owner | « go cloud » | ce chat | FUTUR (après validation locale) |

**Protocole** : tant qu'un gate est PENDING, ne pas exécuter la wave qui en dépend ; exécuter en // les waves qui n'en dépendent pas. W1/W2 ne dépendent que de G0+G1.

---

## §R — RÉFÉRENCES
CONSTITUTION.md · SYSTEM_MAP.md · SYNC_CONTRACT.md · CLAUDE.md §§4-13 · `reports/diagrams/FOODKING_STRUCTURE_V1_2026-06-04.html` · `memory/feedback_soak_vs_concurrent_load_single_process.md` · `memory/feedback_adversarial_audit_pattern.md` · skills `ultra-audit-profond`/`test-e2e`/`lock-plan`.

---

## §F — RÈGLE FINALE (DONE)
**DONE = CŒUR prouvé bulletproof** :
1. Transfert inter-systèmes fiable **OU** dégradation **visible + sans perte** (matrice §3 : C-01..C-11 prouvés, 0 perte).
2. **No-crash** prouvé par soak SOLO sans faute.
3. Tous les tests cœur verts (Pos/Order/Orders/Outbox/Fiscal/Kds/Sync) + cross-surface E2E vert.
4. **Frozen-zone diff = 0** · **NF525 CHAIN OK** sur tout le range.
5. Dégradation jamais silencieuse (bandeaux visibles).
Secondaire (W3) bas ; backlog (W4) + cloud (W5) **documentés, non exécutés**. Production-fonctionnel pour Le Cayenne — pas « presque ». Cloud seulement après validation owner de ce DONE.
