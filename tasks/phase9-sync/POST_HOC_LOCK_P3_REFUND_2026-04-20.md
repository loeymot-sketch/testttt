---
id: POST_HOC_LOCK_P3_REFUND_2026-04-20
scope: P3 refund / retour DELIVERED → RETURNED (audit NF525)
date: 2026-04-20
statut: CLOSED post-hoc
sha: b007c6344cd2cf960abc6ce74212db150db1c688
---

# POST-HOC LOCK — P3 refund / returned

## Référence commit

| Champ | Valeur |
| --- | --- |
| **SHA** | `b007c6344` (`b007c6344cd2cf960abc6ce74212db150db1c688`) |
| **Sujet** | `feat(P3): retour DELIVERED→RETURNED audit NF525 + motif obligatoire` |
| **Date auteur** | 2026-04-19 |

## Plan référent

- `plans/PLAN_P3_REFUND_HANDOFF.md` (ajouté dans ce commit)

## Périmètre — fichiers touchés (`git show --stat b007c6344`)

| Fichier | Rôle |
| --- | --- |
| `app/Services/OrderService.php` | `changeStatus` : validation `reason` pour `RETURNED` ; piste audit NF525 étendue |
| `plans/PLAN_P3_REFUND_HANDOFF.md` | Documentation handoff |
| `tests/Feature/Fiscal/PosOrderBL2AuditCallSitesTest.php` | Couverture appels audit |

## Justification post-hoc

Commit **déjà mergé sur `main`** avant cartographie LOCK dans `tasks/phase9-sync/` ; document de **régularisation gouvernance Phase 9.5** (audit T19).

## Rapports liés

- [REPORT_TASK19 — Locks P9 + Frozen Zones](../reports/audit-orchestration/REPORT_TASK19_LOCKS_FROZEN_ZONES_2026-04-20.md)
- [REPORT_TASK20 — Gate prod final](../reports/audit-orchestration/REPORT_TASK20_GATE_PROD_FINAL_2026-04-20.md)

## Synthèse diff (`git show b007c6344 -- app/Services/OrderService.php`)

1. **Validation motif**  
   - Ancien filtre : `REJECTED` ou `CANCELED` uniquement.  
   - Nouveau : `$toStatus = (int) $request->status` puis `in_array(..., [REJECTED, CANCELED, RETURNED], true)` pour exiger `reason` (`required|max:700`) sur **RETURNED** comme sur annulation / rejet.

2. **Audit NF525 (`AuditLogService::write`)**  
   - Ancien : écriture seulement pour `CANCELED` et `REJECTED` (`order.cancelled` / `order.rejected`).  
   - Nouveau : inclusion de `RETURNED` avec action dédiée **`order.returned`** (commentaire commit : chaîne HMAC inchangée côté mécanisme).  
   - Commentaire de bloc mis à jour pour refléter cancel / reject / return comme transitions « fiscalement sensibles ».

3. **Portée**  
   - Pas de modification des calculs de prix ou de paiement dans l’extrait diff ; impact **workflow statut + conformité trace**.  
   - Le message commit annonce aussi alignement cashBack / loyalty sur le chemin annulation — **non visible** dans l’extrait `OrderService.php` seul ; pour une revue complète, exécuter `git show b007c6344` en entier.

## Évaluation risque (doc)

| Axe | Commentaire |
| --- | --- |
| Fiscal / audit | **Moyen** — nouvelle action `order.returned` et élargissement des transitions auditées ; à valider côté métier / NF525. |
| Pricing SSOT | **Faible** dans le diff `OrderService` montré — pas de toucher `PricingService`. |

**REQUIRES_HUMAN_REVIEW** (changement direct TVA / total / discount dans ce diff) : **non** pour le fichier montré ; **revue fiscalité / NF525 recommandée** pour la nouvelle action d’audit et les chemins retour.
