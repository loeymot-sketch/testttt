# AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19 — RESTORED

> **STATUT RESTAURATION : ÉCHEC PARTIEL — fichier original jamais commité**
>
> - Fichier vidé observé : `reports/review/AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md` (0 bytes, statut git `??` = untracked).
> - `git log --all -- reports/review/AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md` → **aucun commit**.
> - `git log --all --diff-filter=A --oneline -- '...'` → **aucun ajout**.
> - `git log --all --follow --oneline -- '...'` → vide.
> - Recherche dans toutes les worktrees (`/Users/1millnonstop/.cursor/worktrees/testttt/{ixe,nkt,pit,qdg,vmo}`, `testttt-kiosk`, `testttt-kiosk-p93`) → **0 hit**.
> - Le fichier n'a donc **jamais été versionné**. Aucune SHA n'a pu être extraite.
>
> **Source de référence utilisée pour reconstituer le contexte :**
>
> - Brief d'audit : `tasks/audits/AUDIT_POS_BRANCH_ISOLATION_004.md` (axes, grep patterns, grille verdict).
> - Audit antérieur connexe : `reports/review/AUDIT_POS_SECTION_3_FIN_JOURNEE_PERMS_2026-04-18.md` (notamment §5 Permissions Spatie + §6 audit_logs + matrice rôles × actions POS).
>
> Le rapport de vérification complet basé sur l'inspection actuelle du code est disponible dans :
> `reports/review/VERIFY_10_BRANCH_ISOLATION_2026-04-20.md`.

---

## Résumé contextuel reconstruit (pour mémoire — non normatif)

Le rapport originel devait couvrir 3 axes (axes 5-7 du POS 110) :

1. **Isolation branche `branch_id`** — global scope `BranchScope`, force scope dans `OrderService::list/changeStatus/changePaymentStatus/destroy` + `KitchenDisplaySystemOrderService`, gestion admin `branch_id=0`, autorisation Pusher channel `branch.{branchId}`.
2. **Permissions Spatie** — matrice rôle × permission (Admin, Branch Manager, POS Operator, Chef, Waiter, Stuff), permissions `pos`, `pos-orders`, `pos-discount-up-to-10`, `pos-discount-over-10-requires-manager`, `pos-manage-fiscal`, `pos-reopen-z`, `pos-destroy-paid`, `items_edit`, `items-report`, `kitchen-display-system`, `order-status-screen`, etc., et défense-en-profondeur (route × controller × service).
3. **State Machine commandes** — `OrderStateMachine::allows/apply/recordTransition`, exception `IllegalTransitionException`, motif obligatoire pour CANCELED/REJECTED/RETURNED, exemption legacy `$order->status =` documentée pour les call-sites historiques (`OrderService`, `FrontendOrderService`, `KitchenDisplaySystemOrderService`).

Pour le détail d'inspection actuel (file:line, verdicts V0–V8, GLOBAL), se référer à :

`reports/review/VERIFY_10_BRANCH_ISOLATION_2026-04-20.md`.

---

## Décision utilisateur requise

Le fichier vidé d'origine (`AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md`, 0 octet, untracked) n'a **pas** été modifié par ce cycle. Trois options :

1. **Supprimer** le fichier vide (`rm reports/review/AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md`).
2. **Renommer** ce fichier `.RESTORED.md` vers le nom canonique (`mv ...RESTORED.md ...AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md`).
3. **Régénérer un audit complet équivalent** : ouvrir un nouveau cycle `tasks/audit-orchestration/` consacré à axes 5-7 sur la base du rapport `VERIFY_10_BRANCH_ISOLATION_2026-04-20.md`.

Aucune des 3 actions n'est exécutée automatiquement par ce cycle (mode AUDIT-ONLY, escalation utilisateur).
