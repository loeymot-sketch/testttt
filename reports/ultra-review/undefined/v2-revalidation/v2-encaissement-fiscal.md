# V2 Révalidation adversariale — ENCAISSEMENT + chaîne NF525

Cible : `POST /api/admin/pos/counter-collect/{id}/confirm` + `POST /api/admin/pos/collect-kiosk-cash/{id}`
HEAD `61e9ea7b7` + working-tree · DB `foodking_e2e` · serveur LIVE `127.0.0.1:8766`
Posture : « 11/11 GREEN v1 » = hypothèse à réfuter. J'ai attaqué pour CASSER.

## Verdict : GREEN_HELD — 0 nouveau P0/P1

Un vrai encaissement live a été exécuté (commande #5402, CASH 10 €), chaîne NF525
vérifiée AVANT et APRÈS. Zero-doubling et monotonie tenus sous double-POST parallèle.

## Attaques exécutées

### 1. NF525 — chaîne HMAC avant/après encaissement réel (angle 5)
- AVANT : `php artisan fiscal:verify-chain --all` → `SWEEP COMPLETE — CHAIN OK` (4 branches), EXIT=0. branch1 max_seq=2591.
- Encaissement réel #5402 (COUNTER_DEFERRED, TAKEAWAY, pos, total 9,90) via curl POS token → **seq 2592** alloué, `pstatus=5 (PAID)`, `pm=1 (CASH)`, `received=10`.
- APRÈS : `fiscal:verify-chain --all` → `CHAIN OK` (4 branches). Chaîne intacte.
- Séquence branch 1 : 2590 numéros, **dupes=NONE**, MAX+1 (2591→2592). Monotone.

### 2. Concurrency / idempotence (angles 4, 10)
- Double-POST parallèle SANS header idempotency → **2× 422 `MISSING_IDEMPOTENCY_KEY`** (middleware fail-closed, aucun encaissement).
- Double-POST parallèle avec DEUX clés idempotency différentes (simule race 2 caissiers), même commande #5402 :
  - req1 → **200**, seq **2592**
  - req2 → **409** `payment_already_collected`
  - Résultat DB : **seq=2592 (unique), tx_count=1, audit=1**. `lockForUpdate` sérialise → **1 encaissement = 1 seq = 1 transaction = 1 audit**. Zero-doubling TENU.

### 3. Failure-path (angle 2) — aucune 500
- already-PAID même caissier (clé fraîche) → **200 no-op** (aucun 2ᵉ seq, seq reste 2592).
- mode=99 invalide → **422** « Mode de paiement comptoir invalide. »
- commande inexistante 999999 → **404** `ORDER_NOT_FOUND`.
- `{}` (mode manquant) → **422** validation.
- received=-5 → **422** `received must be at least 0`.

### 4. Robustesse séquence (angle 5)
- `FiscalSequenceService::next` = `SELECT MAX(fiscal_sequence_no)+1` sous `Cache::lock` + transaction (file:57-103) → allocation **rollback-safe** (un rollback ne brûle pas de numéro, le MAX est inchangé).
- `collectKioskCash` (OrderService:2511) délègue à `confirmCounterPayment` → hérite des mêmes gardes (lock, garde PAID, seq unique).

## Held-green attesté (attaques qui ont ÉCHOUÉ)
- Double-seq via race : ÉCHEC (lockForUpdate + garde `payment_status===PAID` → 409, seq unique).
- Bypass idempotency : ÉCHEC (header requis, fail-closed 422).
- Encaissement commande annulée/déjà payée : ÉCHEC (gardes CANCELED/REJECTED/RETURNED:323 + garde PAID:278).
- Corruption chaîne HMAC par un encaissement : ÉCHEC (chain OK après).

## Observations NON retenues comme findings (par garde-fous / preuve live)
1. **Gap seq 2505→2508 (branch 1)** : numéros 2506-2508 ABSENTS même `withTrashed()` → **suppression HARD** d'anciennes commandes de test (churn stress/soak e2e), PAS le chemin d'encaissement (MAX+1 est rollback-safe, mon encaissement était propre 2591→2592). Résidu e2e connu, non-prod. Non reproductible via le endpoint.
2. **`fiscal:verify-z-membership` « TROU »** sur nombreuses commandes fiscalisées : détecteur P0 #1 detect-only connu, attendu en DB test où aucun Z quotidien n'est clôturé. Non nouveau.
3. **Race-window message** : deux confirms VRAIMENT parallèles du MÊME caissier (2 clés idem distinctes) → le perdant reçoit 409 « encaissée par un **autre** caissier » avec `collected_by_user_id:null` (req2 lit l'audit avant que la ligne de req1 soit committée). **Intégrité intacte (seq unique)** ; message trompeur uniquement. C'est le comportement DOCUMENTÉ K2-HEAL (« prefer surfacing the conflict », PaymentService.php:300-309). Un double-tap normal réutilise la MÊME clé idem → intercepté par le middleware. Cosmétique, non P0/P1.

## Preuve DB finale
```
5402 seq=2592 pstatus=5 tx=1 audits=1
branch1_max_seq=2593  (2593 alloué par une autre session concurrente, pas la mienne)
fiscal:verify-chain --all → CHAIN OK (4 branches)
```
