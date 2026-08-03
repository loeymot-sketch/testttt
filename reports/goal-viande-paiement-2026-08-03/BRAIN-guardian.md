# BRAIN Guardian — audit READ-ONLY FROZEN + NF525 du range deploy
**Range** : `7ba7bd620..HEAD` (14 commits, HEAD=`1b405eba1`) · **Date** : 2026-08-03 · **Auditeur** : guardian read-only

## 1. Frozen zones touchées — [OK]
`git diff --stat` sur les 13 chemins frozen §7 :

| Fichier frozen | Diff | LOCK |
|---|---|---|
| `public/js/pos-wizard.js` | **+14 / -0** (seul frozen touché) | `plans/LOCK_POS_WIZARD_TICKET_VIANDE_EN_PLUS_2026-08-03.md` — cité dans 3 commits du range (`34969acaf`, `f4c0538db`, `c125cf3ff`) |
| Tous les autres (Kiosk*, PaymentComponent, PosV5TrancheRow, pos-wizard.css, admin-pos-v4.blade, Fiscal/, BranchScope, Idempotency, PricingService, OrderStateMachine) | **0 ligne** | n/a |

Gate owner explicite consignée dans le LOCK (ligne 3 : /goal 2026-08-03).

## 2. Conformité au scope du LOCK — [OK]
- Scope LOCK (§Frozen ligne 9) : `buildTicketInstruction()` uniquement, pousser la ligne `Viandes en plus : <noms>`, aucun autre changement, money-path intouché.
- Diff réel : entièrement à `public/js/pos-wizard.js:3719-3790`, **à l'intérieur de `buildTicketInstruction()`** (déclarée ligne 3700). Purement additif : collecte `viandeSupplTicketNames` + push `extraLines('Viandes en plus : …')` dans les 2 branches (avec/sans viande principale). Zéro touche à prix, extras @2,50, `data-wizard-qty`.
- Volet non-frozen du même LOCK (ligne 12) : `KitchenTicketSymbolicFormatter.php` (+12, strip du SEGMENT « en plus » borné `.`/`|`, note allergie co-localisée préservée) + `kdsCustomization.js` — ces fichiers ne sont PAS frozen §7, parité PHP↔JS refixturée (`tests/fixtures/parity_php.json`). **Aucun dépassement de scope détecté.**

## 3. NF525 — [OK]
- `git diff --name-only | grep -iE "fiscal|pricing|audit_log|z_report"` → **1 seul hit** : `tests/js/posCounterCompositionLabels.spec.js` (test JS, aucun code fiscal).
- Grep du contenu du diff sur `composition_snapshot|fiscal_sequence|audit_logs|z_reports` : uniquement des rapports/PROJECT_BRAIN/commentaires — **aucune écriture de code** sur ces surfaces.
- `PricingService`, `FiscalSequenceService`, `ZReportService`, `AuditLogService`, migrations triggers : 0 ligne.
- **Loyalty refund per-porteur (`d2ab26c48`)** : `app/Services/LoyaltyService.php` — ne manipule QUE des **points** (`loyalty_transactions`, `users.loyalty_points`). Aucun montant d'order, aucune séquence fiscale, aucun snapshot. `refundPointsToOwner()` identifie le porteur par `user_id` du grand-livre (`withoutGlobalScope(BranchScope)` justifié : client `branch_id=0`, lecture ciblée par id, pas un affaiblissement d'isolation générique). `FrontendOrderService:954-961` / `OrderQuoteService:354-360` : élargissement `status=1` → `[1, ACTIVE]` sur la recherche du porteur — affecte l'éligibilité de la remise fidélité côté quote (backend reste SSOT, remise recalculée backend). Mollie in-page (`b6dfdfcf5`) : `cardToken` optionnel + flag `inline`, montants toujours issus de l'order backend. **Invariant fiscal : intact.**

## 4. Migration `2026_08_01_190000_activate_legacy_loyalty_customers` — [OK, 1 note P2]
- **Idempotente** : oui — filtre `WHERE status=1`, un re-run ne matche plus rien après le premier passage.
- **Réversible** : `down()` = no-op **volontaire et documenté** (re-désactiver renverrait les clients dans la boucle 401). Acceptable : l'état ACTIVE est l'état cible correct ; rollback mécanique n'aurait pas de sens métier.
- **Périmètre prod** : `status=1` + `loyalty_code NOT NULL` + `branch_id=0` + **aucun rôle** (`whereNotExists model_has_roles`). Les 13 comptes staff status=1 ont rôle et/ou branche → exclus, promesse tenue (commit : « 5 comptes réels débloqués ; 13 comptes staff INTACTS »).
- **[P2 résiduel]** un hypothétique compte staff SANS rôle, `branch_id=0`, porteur d'un `loyalty_code` serait activé — combinaison qu'aucun chemin de création staff ne produit ; risque théorique, non bloquant.

## 5. `php artisan fiscal:verify-chain --all` — [OK]
```
  + branch=1 CHAIN OK
  + branch=7 CHAIN OK
  + branch=8 CHAIN OK
  + branch=9 CHAIN OK
SWEEP COMPLETE — CHAIN OK on every active branch (4 total)
```

## Verdict guardian
| Point | Statut |
|---|---|
| 1. Frozen touchés couverts par LOCK | **OK** |
| 2. Scope LOCK respecté | **OK** |
| 3. NF525 (fiscal/pricing/snapshot) | **OK** |
| 4. Migration loyalty | **OK** (1 P2 théorique) |
| 5. Chaîne fiscale | **OK** |

## **GO deploy** ✅
1 fichier frozen touché, sous LOCK owner-gaté, patch additif display-only conforme au scope. Zéro touche NF525-code. Chaîne fiscale verte sur les 4 branches. Migration à périmètre strict et idempotente.
