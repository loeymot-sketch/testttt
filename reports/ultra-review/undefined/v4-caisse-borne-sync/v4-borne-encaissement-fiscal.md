# V4 — BORNE : encaissement (à-encaisser) + allocation fiscale — VERDICT: GREEN_HELD

Cible : `POST /api/admin/pos/counter-collect/{id}/confirm`, `POST /collect-kiosk-cash/{id}`,
`GET /counter-collect/pending`, `POST /counter-collect/{id}/cancel`.
Posture : « GREEN = hypothèse à réfuter ». J'ai tenté de casser l'encaissement borne. **Je n'y suis pas parvenu.**

HEAD 61e9ea7b7 + working-tree. LIVE 127.0.0.1:8766 (foodking_e2e). POS operator user 3 (branch 1), token Sanctum + x-api-key.

## Protocole de repro (réel, LIVE)
Fabrication d'ordres BORNE Plan B réels via le vrai flux : quote signé (`/api/frontend/order/quote`, HMAC intent) → `POST /api/frontend/order` avec `payment_method=1 (CASH_ON_DELIVERY)`, `order_type=10`, kiosk token `kiosk:order`. Résultat DB : `payment_status=15 (PENDING_COUNTER)`, `pos_payment_method=6 (COUNTER_DEFERRED)`, `status=4 (ACCEPT)`, `source_surface=kiosk`, `fiscal_sequence_no=NULL`. Ordres créés : 5447, 5452, 5453, 5454, 5455, 5456.

## Attaques exécutées et résultat

### 1. NF525 — fiscal alloué À L'ENCAISSEMENT SEUL, gap-free, sans doublon (angle 5)
- Avant encaissement : `fiscal_sequence_no = NULL` sur tous les PENDING_COUNTER. **Confirmé : aucun numéro consommé tant que non encaissé.**
- `fiscal:verify-chain --all` **AVANT** = CHAIN OK (4 branches). **APRÈS** mes 5 encaissements = CHAIN OK.
- Séquence branche 1 : max 2606 → 2610 après mes encaissements, allocation **contiguë** (2607, 2608, 2609, 2610, 2611), **0 nouveau gap, 0 doublon** (`array_count_values` → duplicates NONE).
- Rollback-safe prouvé par lecture code : `FiscalSequenceService::next()` (frozen) renvoie `MAX(fiscal_sequence_no)+1` sous `Cache::lock` + `lockForUpdate`, mais **ne persiste rien** ; le numéro n'est gravé qu'au `save()` du `$locked` à l'intérieur de la transaction. Un rollback (échec audit/chain-lock) ne consomme donc **aucun** numéro → pas de gap silencieux. Contrainte `UNIQUE(branch_id, fiscal_sequence_no)` (`2026_04_22_000001_add_fiscal_sequence_no_to_orders.php`) ferme la fenêtre concurrente (un doublon éventuel = QueryException → 422, jamais un doublon persistant).

### 2. Concurrence / idempotence — 1 encaissement = 1 seq = 1 mouvement (angles 4, 6, 10)
- **4 confirms simultanés** (clés X-Idempotency-Key **différentes**, donc contournant le cache middleware) sur ordre 5455 → **exactement req1=200, req2/3/4=409** (`error_code: payment_already_collected`). État final : `fiscal=2610`, **tx=1, audit.counter_payment_confirmed=1, cash_movements=1**. Zéro double-collecte, zéro double-seq. Reproduit 2× (5447 puis 5455) → déterministe.
- Le 409 race-loser porte `collected_by_user_id=null` : c'est le chemin « unknown collector → safe default 409 » documenté (K2-HEAL-01) — dû au snapshot MySQL REPEATABLE-READ (la ligne audit du gagnant n'est pas encore dans la vue cohérente du perdant). **Comportement conçu**, intégrité intacte.
- **Replay séquentiel même caissier** (ordre déjà PAID, clé fraîche) → **200 no-op** (aucune 2ᵉ collecte, seq inchangée) — le garde « same cashier → no-op » fonctionne.
- **Même clé idempotency 2×** (5456) → call1=200, call2=200 (replay caché), **seq=2611, tx=1** — pas de double encaissement.

### 3. Failure-path — jamais de 500, codes corrects (angles 1, 2)
| Attaque | Résultat |
|---|---|
| confirm ordre inexistant (999999) | **404** `ORDER_NOT_FOUND` |
| mode=6 (COUNTER_DEFERRED, hors allowedModes) | **422** « Mode de paiement comptoir invalide » |
| mode=99 | **422** idem |
| CASH received=0.5 < total 1.9 | **422** « montant reçu inférieur au total » |
| mode absent | **422** validation |
| received=-5 | **422** validation (`min:0`) |
| CASH received=null | **200**, défaut = total (1,90 €, change 0) — pas de crash |
| confirm ordre ANNULÉ (5453, cancel préalable → ps=20) | **422** « Invalid payment_status transition 20 -> 5 » (PaymentStateMachine) |
| confirm ordre UNPAID non-deferred (5434, posm=NULL) | **422** « This order is not a pending counter payment » (`assertCounterDeferredOrder`) |
| collect-kiosk-cash (5454) | **200**, seq=2609, ps=PAID |

### 4. Filet source_surface NULL / queue pending (angle 1, 9)
`GET /counter-collect/pending` : la requête exclut `status=CANCELED`, matche kiosk+KIOSK/TAKEAWAY, pos+COUNTER_DEFERRED, ET le filet `source_surface IS NULL + type kiosk/emporter` (routes/api.php:807-853). Un ordre annulé via `/cancel` (ps=20) sort de la file (filtre `payment_status=15`). Cohérent.

## Conclusion cible
**Impossible de réfuter « l'encaissement borne est correct ».** Fiscal alloué à l'encaissement seul, gap-free, sans doublon ; concurrence sérialisée (lockForUpdate + PAID-guard + UNIQUE) ; idempotence at-most-once ; tous les chemins d'échec en 4xx typés ; zéro doublage tx/mouvement/audit. **GREEN_HELD.**

## Observation HORS-CIBLE (pas un défaut de l'endpoint encaissement — à router vers la cible caisse/fiscal-verify)
`orders.fiscal_sequence_no` branche 1 présente un gap **préexistant** 2506–2508 : ces 3 numéros n'existent dans **aucune** ligne (même `withTrashed()`, toutes branches) — orders porteurs **hard-deleted** entre 2026-06-19 et 2026-06-20 (avant ma session), ce que NF525 interdit (rétention 6 ans, soft-delete one-way). **`fiscal:verify-chain --all` répond CHAIN OK** car il vérifie la chaîne HMAC `audit_logs`/`z_reports`, **pas** la contiguïté de `orders.fiscal_sequence_no`. J'ai prouvé que le chemin counter-collect **n'en est pas la cause** (mes 5 encaissements = allocation contiguë). Artefact de nettoyage/reseed de la DB de test ; à confirmer sur la DB prod réelle. Non attribuable à la borne-encaissement → non compté comme finding de ma cible.
