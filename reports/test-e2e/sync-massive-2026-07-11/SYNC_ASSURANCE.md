# SYNCHRONISATION MASSIVE — ASSURANCE (2026-07-11)
> /goal « synchronisation massive assure ça ». Contrat ancré SYNC_CONTRACT.md :
> soketi(:6001) + queue:work redis outbox pattern, canal private-branch.1,
> events OrderCreated/OrderStatusChanged/KdsOrderRecalled, OSS mur public = poll 5s.

## 1. INFRA — état trouvé puis assuré
- **Trouvé** : soketi DOWN + 0 worker sur la box → sync dégradée en POLL silencieusement (redis UP).
  Outbox : 59 pending (att=0, jamais dispatchés).
- **Assuré** : soketi démarré (Node v18 — uWS.js incompatible Node≥20) + `queue:work --queue=high`.
  Outbox drainé, worker traite les broadcasts ~6ms/job.

## 2. PROPAGATION TEMPS-RÉEL — PROUVÉE bout-en-bout
- Commande borne **5638 (A0032)** créée via UI → `OrderCreated`#10498 + `OrderStatusChanged`#10499
  (Plan-B auto-accept) **dispatchés @12:34:21** sur `private-branch.1`, att=1 (succès 1er coup).
- **KDS abonné WS** : `Echo.connector.pusher.connection.state === "connected"` (PAS poll) +
  **A0032 présent sur le board** (badge BORNE, EN ATTENTE ENCAISSEMENT). Capture `sync-kds-ws-connected-5638.png`.
- Chaîne complète : borne → outbox → worker(high) → soketi → KDS abonné. ✓

## 3. ADVERSAIRES (3 agents, preuves réelles) — tous CONFIRMÉS
| Axe | Verdict | Preuve |
|---|---|---|
| **Isolation canal** | CONFIRMÉ | kiosk-token non-spoofable restreint à sa branche (`channels.php:44-61`), staff `branch_id===branchId`, 0 canal public, `branch_id` = aggregate serveur (jamais client). Sentinel 2/2. Écart : test source-statique, pas runtime (backlog QA) |
| **Immutabilité snapshot** | CONFIRMÉ | prix+compo figés, rendus DU snapshot sur KDS/historique/encaissement/ticket ; PricingService jamais en read ; HIST-10 pass + attaque live 999→rollback. Réserve : libellé produit top-level suit menu live (non-fiscal, affichage) |
| **Dégradation no-loss** | CONFIRMÉ | poll KDS 5s(WS-down)/OSS 5s lit SSOT `orders` ; outbox 4/4 tests ; fiscal gap-free Cache::lock+FOR UPDATE+UNIQUE. 0 perte, seuls cues éphémères non ré-hydratés (état sous-jacent persisté) |

## 4. FINDING RÉEL healé — readiness probe false-503
`HealthController::checkQueueWorker` comptait les orphelins `attempts=0/last_error=NULL` d'un
worker-down PASSÉ (20 de juin) SANS plancher de récence → `/api/health/ready` = **503 permanent**
même worker sain (le LB sortirait le nœud + masquerait un vrai down). Même classe que le fix
contract_violation du 2026-07-07, mais 2ᵉ classe oubliée. **Fix** : plancher 24h (= `retry-failed
--since=24h`). Live : 503→**200** `stale_count:0`. +2 tests (orphelin ancien ignoré, backlog récent
détecté). Suite 74/74. 0 frozen, NF525 clean.

## 5. GAPS OPS à signaler owner (non-code / infra)
- **soketi ⚠️ Node 14/16/18 requis** (uWS.js) — box par défaut Node≥20 → crash boot. Reproduit ici
  (fixé en invoquant Node v18). Le `supervisor.conf.template` doit garantir Node 18 pour soketi.
- **`MonitorOutboxStaleness` = Log::error seulement** — aucun canal d'alerte externe (mail/SMS).
  Staleness détectée mais non escaladée. Choisir un canal.
- **Worker prod DOIT `--queue=high,default`** — ✅ garanti par `supervisor.conf.template:42` (OK).
- **Débris outbox** : 37 lignes juin (20 orphelins OrderCreated + 16 LoyaltyBalanceChanged
  contract-violation d'un émetteur supprimé + 1 malformé) — prunables via `foodking:outbox:prune`
  (seuil défaut 90j ; les miennes 24-29j → `--older-than-days=20`). N'affectent PLUS le health probe.
- **Réserve snapshot** : `order_items.name` NULL → libellé produit top-level lu du menu live (un
  renommage change l'affichage des commandes passées). Non-fiscal. Fix futur = figer `name` à création.

## VERDICT : SYNCHRO ASSURÉE
Temps-réel prouvé bout-en-bout · 3 axes adversaires CONFIRMÉS · 1 défaut probe healé+testé ·
74 tests sync verts · 0 frozen · NF525 clean · gaps ops documentés pour l'owner.
