# RUN — P11_INVARIANT_DOC_REFRESH — 2026-04-20

**TASK_ID:** P11_INVARIANT_DOC_REFRESH  
**Plan:** `tasks/execute-2026-04-20/V8_02_P11_INVARIANT_DOC_REFRESH.md`  
**Statut:** SUCCESS

## Fichier modifié

- `tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md`

## Vérification commande shell (doc §3 — bloc dispatch)

La commande documentée  
`bash scripts/check-invariants.sh -v 2>&1 | sed -n '/4\/6 App/,/^==>/p'`  
a été exécutée sur **macOS (darwin 25.2)** : le pipeline **BSD sed** produit bien la section invariant 4/6 jusqu’à la ligne `==> ...`. Aucun recours au fallback `grep -A 30 "4/6 App"` nécessaire (1 tentative suffisante).

## Diff complet — `tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md`

```diff
diff --git a/tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md b/tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md
index 0c3490a30..ed74a1175 100644
--- a/tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md
+++ b/tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md
@@ -1,6 +1,6 @@
 # POS INVARIANTS & GATES
 
-**Version.** 2026-04-18
+**Version.** 2026-04-18 (rév. 2026-04-20 — V8 #2 alignement avec `scripts/check-invariants.sh` post V5 #2 + V8 #1)
 **Scope.** S'applique à toutes les vagues POS-A, POS-B, POS-9.1 → POS-9.10.
 
 ---
@@ -77,6 +77,8 @@
 - [ ] `CROSS_TRACK_STATUS.md` 100 % items status=closed.
 - [ ] Handoff `tasks/handoff/POS_PHASE_9_HANDOFF.md` pour Track C E2E.
 
+> **2026-04-20 — Migration progressive vers `scripts/check-invariants.sh`** : les invariants 1, 2, 3, 4, 5, 6 ci-dessous (et leur évolution) sont maintenus dans le script unique. Les `grep` listés dans cette §3 restent valides comme cheat-sheet rapide, mais en cas de divergence, **le script fait foi**. Mises à jour majeures : V5 #2 (durcissement 4/6), V7 #1 (analyse Item/Category), V8 #1 (pattern event() helper).
+
 ## 3. Grep de vérification à lancer avant chaque merge
 
 ```bash
@@ -89,8 +91,10 @@ grep -rn "->input('branch_id')\|\$request->branch_id" app/Http/Controllers/Admin
 # écriture directe status ?
 grep -rn "->update(\[\s*'status'" app/ --include="*.php" | grep -v OrderStateMachine
 
-# dispatch avant commit ?
-grep -rn "Event::dispatch\|::dispatch(" app/Services/OrderService.php app/Services/FrontendOrderService.php | grep -v "afterCommit\|shouldDispatchAfterCommit"
+# dispatch avant commit ? (SSOT: scripts/check-invariants.sh invariant 4/6)
+# Couvre 3 patterns (FQN + short-name + event() helper) sur 6+ fichiers.
+# Mis à jour V5 #2 (FQN + short-name), V8 #1 (event() helper).
+bash scripts/check-invariants.sh -v 2>&1 | sed -n '/4\/6 App/,/^==>/p'
 
 # EventContract bypass ?
 grep -rn "broadcast(" app/Events/ | grep -v "buildEnvelope\|assertEnvelopeValid"
```

## Risque résiduel / suivi

- Aucun : documentation uniquement ; `scripts/check-invariants.sh` non modifié (V8 #1).

---

## AUDIT (Claude orchestrateur) — 2026-04-20
**Verdict : CLOSED — PASSED — 0 remediation**

| # | Check | Résultat |
|---|---|---|
| 1 | Diff `tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md` | +7/-3 (1 fichier, conforme à ~10-15 lignes attendues) |
| 2 | Bloc dispatch §3 ligne 92-93 | remplacé par `bash scripts/check-invariants.sh -v 2>&1 \| sed -n ...` (SSOT) |
| 3 | Commande shell testée | exécution OK, 13 lignes de sortie (BSD sed sur macOS, pas besoin de fallback grep) |
| 4 | Note pédagogique ajoutée avant §3 | présente, mentionne migration progressive + V5 #2 + V7 #1 + V8 #1 |
| 5 | Version mise à jour ligne 3 | `2026-04-18 (rév. 2026-04-20 — V8 #2 ...)` |
| 6 | Autres invariants §3 (pricing, branch_id, status, EventContract, audit log) inchangés | confirmé via diff |
| 7 | §1, §2, §4 inchangés | confirmé via diff |
| 8 | `scripts/check-invariants.sh` non modifié par ce cycle | confirmé (les modifs sont V8 #1 en parallèle) |

**Valeur produite** :
- Doc gouvernance désormais alignée avec la réalité opérationnelle (script SSOT)
- Évite la divergence doc/script (cause #1 d'invariants violés silencieusement)
- Pédagogie : devs/agents futurs qui lisent §3 sont redirigés vers le script unique
- Préparation au cycle futur de retrait progressif des autres greps §3 (à terme : tous remplacés par invariants 1-6 du script)
