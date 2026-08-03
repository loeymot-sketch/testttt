# Master Play — rapport fusionné **V1** (POS · Borne · KDS)

**Nature** : simulation uniquement — **ne remplace pas** un cycle `TASK_ID` produit ni un gate.  
**Méthode** : fusion **V0** + **Round 2 GPT Pro** (challenge) + **Round 3** (pont) + **Graphiti** (faits lus 2026-04-25).  
**Verdict global** : `MERGE_V0_WITH_REVISIONS` — garder la carte V0, durcir avec preuves fichier/test et backlog P0–P2 ci-dessous.

---

## 1. Synthèse exécutive (pour la boucle BX)

| Livrable | Fichier | Rôle |
|----------|---------|------|
| Carte initiale | `SIM_MASTERPLAY_BREAKDOWN_SYNTH_V0_2026-04-25.md` | Architecture + candidats |
| Concurrence GPT | `SIM_MASTERPLAY_GPT_CHALLENGE_ROUND2_2026-04-25.md` | Attaques, validations, matrice, métriques |
| Arbitrage session | `SIM_MASTERPLAY_SYNTH_BRIDGE_ROUND3_2026-04-25.md` | Table V0 vs GPT |
| **Référence unique** | **ce fichier (V1)** | Découvertes + validations consolidées |

**Ce que tu « fais tourner » dans la boucle** : `run-cycle` / `verify:boucle` inchangés ; ce rapport est **entrée** pour PLAN futur (nouveau `TASK_ID` si code) ou pour `audit-brief` terminal après `context` (voir `docs/orchestration/SIM_MASTERPLAY_POS_BORNE_KDS_CHALLENGE.md`).

---

## 2. Vue d’ensemble métier (inchangée depuis V0)

Le flux **Borne → `FrontendOrderService` → DB**, **POS → `OrderService` → DB**, **KDS** lit via services + **Echo** + fallback **sync HTTP** reste le squelette. La **SSOT métier** est backend (enums, transitions, `branch_id`).

---

## 3. Surface POS (caisse)

### 3.1 Pivots

`OrderService.php`, `PosComponent.vue`, `PaymentComponent.vue` (si présent), `Order.php`.

### 3.2 Découvertes consolidées

| ID | Source | Contenu | Statut V1 |
|----|--------|---------|-----------|
| POS-DOC-01 | V0 + GPT §B.1 | `docs/DEVICE_FLOW.md` cite Firebase pour entrées kiosk côté POS ; le code moderne utilise **Echo** (`PosComponent.vue` ~1173). | **À corriger doc** (cycle doc dédié) |
| POS-GUARD-01 | V0 | Admin `branch_id=0` vs staff — cohérence liste POS (audit KDS 2026-04-24 en amont). | **À re-greper** POS |
| Symétrie | Invariant projet | Toute évolution `OrderService` ⇒ revue `FrontendOrderService`. | **Toujours actif** |

---

## 4. Surface Borne (kiosk)

### 4.1 Pivots

`routes/api.php` (groupe frontend), `OrderController`, `FrontendOrderService.php`, `kioskCart.js`, wizard `Kiosk*`, `KioskPaymentComponent.vue`, `config/kiosk.php`.

### 4.2 Découvertes consolidées

| ID | Source | Contenu | Statut V1 |
|----|--------|---------|-----------|
| **P0 idempotence** | GPT §A.1 | Lookup `FrontendOrder::where('idempotency_key', …)->first()` **sans** scope `branch_id` explicite alors que contrainte DB `(branch_id, idempotency_key)`. Fichiers : `FrontendOrderService.php` ~126, ~610 ; tests `KioskFullFlowE2ETest`, `IdempotencyBranchScopedTest`. | **À prouver / corriger** en cycle produit |
| **P0 pricing terminal** | GPT §A.2 | SSOT backend à la persistance OK ; **parcours paiement** : prouver que le montant terminal vient du **backend post-order**, pas seulement fallback UI (`kioskPricingPreview.js`, `KioskWizardComponent.vue`, `kioskCart.js`, grep `total_cents` dans paiement). | **UNVERIFIED** jusqu’à lecture `KioskPaymentComponent.vue` |
| KIOSK-UI-01 | V0 | Logique dispersée sur nombreux `Kiosk*Component.vue`. | Dette lisibilité |
| Idempotency header | GPT §B.7 | Store génère / réutilise `X-Idempotency-Key` (`kioskCart.js` ~447). | **Validé** |

Pricing défense persistance : GPT §B.6 — `FrontendOrderService` retire totaux client ; `PricingPreviewController` — **validé**.

---

## 5. Surface KDS

### 5.1 Pivots

`KitchenDisplaySystemOrderService.php`, `KitchenDisplaySystemController.php`, `KdsSyncController.php`, `KitchenDisplaySystemComponent.vue`, `KdsSyncService.js`.

### 5.2 Découvertes consolidées

| ID | Source | Contenu | Statut V1 |
|----|--------|---------|-----------|
| Quatre événements Echo | GPT §B.2 | `OrderStatusChanged`, `OrderCreated`, `ItemAvailabilityChanged`, `OrderTableChanged` — `KitchenDisplaySystemComponent.vue` ~1142. | **Validé** |
| Filtre `branch_id` | GPT §B.3 | Filtre exact + tests `KdsBranchFilterExactTest`. | **Validé** |
| **P1 admin branch 0** | GPT §A.3 | Backend peut souscrire canaux branche ; **UI KDS** refuse Echo si `branchId <= 0` → refresh/sync global. Documenter écart capacité API vs UX. | **Doc + runbook** |
| **P1 after-commit test** | GPT §A.4 | `OrderTableChanged` a `DispatchableAfterCommit` mais **absent** de `DispatchAfterCommitTest` (couvre les 3 autres). | **Ajouter test** |
| **P1 version sync** | GPT §A.5 | Risque perte si version sur `updated_at` (secondes) ; TODO `status_changed_at` dans `KdsSyncService.php`. | **Cycle sync** |
| **P0 bump localStorage** | GPT §A.6 | Store KDS persiste ; `isReadyOrder` → `PREPARED` ; **deux écrans** peuvent diverger. Décision : serveur ou **un seul poste actif**. | **ADR humain** |
| KDS-ECHO-01 / KDS-WS-01 | V0 | Unsubscribe / double voie WS+polling. | Garder en **risque** |

---

## 6. Glue — matrice événement → surface (**canon V1**, remplace §5.2 V0)

| Event | KDS | Borne | POS | OSS | Verdict |
|-------|-----|-------|-----|-----|---------|
| `OrderStatusChanged` | Oui (refresh debounced) | Oui (`KioskWaitingComponent`, commande courante) | Oui (recharge kiosk cash) | Oui (liste + chime PREPARED) | Confirmé, branch-scoped |
| `OrderCreated` | Oui | Oui (queue number) | Oui (notif + reload) | Oui (reload liste) | Confirmé |
| `ItemAvailabilityChanged` | Oui | Oui (`KioskAppComponent`, prune) | Oui (grise + prune cart) | **Non** (GPT §A.7) | OSS explicite N/A ou futur |
| `OrderTableChanged` | Oui | Non | Non consommateur direct (producteur floor/POS) | Non | KDS consumer ; clarifier producteur |

**OrderStatus** : enum backend + enum JS — GPT §B.5 — **validé** sur flux principal.

**After-commit** sur événements majeurs : GPT §B.4 + invariant — **conçu** ; trou de test sur `OrderTableChanged` — **à combler**.

---

## 7. OSS (hors détail V0, intégré V1)

GPT §A.7 : OSS consomme surtout `OrderCreated` / `OrderStatusChanged` ; pas `ItemAvailabilityChanged` / `OrderTableChanged` — **documenter** comme choix ou lacune selon produit.

---

## 8. Métriques mesurables (SECTION_D GPT — cibles V1+)

1. **Latence** commit → surface p95 par event et branche (WS vs mode dégradé).  
2. **Couverture** : chaque cellule critique de la matrice = test producteur + consumer + isolation `branch_id` + after-commit (incl. `OrderTableChanged`).  
3. **Anti-duplication** listeners : remounts sans double chime / double handler par `(branch, event)`.

---

## 9. Backlog priorisé (exécution = **nouveaux** cycles)

**P0** — Idempotency `(branch_id, idempotency_key)` ; preuve paiement = total backend ; gouvernance bump KDS ; test `OrderTableChanged` after-commit.  
**P1** — Matrice avec owners + tests ; doc `branch_id=0` ; TODO `status_changed_at` ; scénario 2 onglets KDS + double remount ; aligner `DEVICE_FLOW.md`.  
**P2** — OSS hors certains events ; cap KDS 50 + alerte saturation.

---

## 10. Graphiti (lecture session)

Faits utiles retrouvés (groupe `foodking`) : émissions `OrderCreated` depuis `OrderService` / `FrontendOrderService` ; KDS consomme statuts / événements ; Echo sur surfaces (certaines entrées historiques **expired** dans le graphe — **ne pas** traiter le graphe comme preuve code seule ; recouper fichier).

---

## 11. Concurrence Claude terminal vs GPT Pro (cadre demandé)

| Rôle | Canal | Intelligence max |
|------|--------|------------------|
| **GPT Pro** | `codex-extension` / mission `SIM-MASTERPLAY-*` | Round 2 — attaques + preuves attendues |
| **Claude** | Terminal `foodking-claude-orchestrate.sh` (**défaut modèle** : `claude-opus-4-7`, **`--effort high`**) — surcharge : `FOODKING_CLAUDE_TERMINAL_MODEL`, `FOODKING_CLAUDE_TERMINAL_EFFORT` | Passer **ce V1** + demander `AUDIT_VERDICT` / lacunes dans `audit` ou prompt custom après `context` |
| **Sub-agent Cursor** | `foodking-planner-orchestrator` | Fallback quota terminal (`AUDIT_TERMINAL_QUOTA_FALLBACK.md`) |

**Prochain pas boucle** : `bash scripts/foodking-claude-orchestrate.sh context` puis `audit` avec prompt du type : *« Audite les incohérences entre `SIM_MASTERPLAY_BREAKDOWN_SYNTH_V1_2026-04-25.md` et le code cité ; liste UNVERIFIED et PASS. »*

---

## 12. Verdict documentaire V1

| Critère | État |
|---------|------|
| Une seule référence lecture | **Oui** (ce fichier) |
| Découvertes + validations croisées | **Oui** (V0 + GPT + pont + Graphiti) |
| Prêt implémentation sans nouveau cycle | **Non** — P0 exigent code + tests |

---

*Généré 2026-04-25 — Master Play simulation.*
