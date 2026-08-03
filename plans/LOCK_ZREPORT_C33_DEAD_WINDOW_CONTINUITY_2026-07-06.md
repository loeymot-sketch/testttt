# LOCK_ZREPORT_C33_DEAD_WINDOW — partition Z continue (fin du trou entre deux Z)

> Frozen-zone override — NF525-critical. Ce LOCK est en **DRAFT** : il exige la signature owner (§10) AVANT toute modification, car il touche la chaîne fiscale signée.

## §1. Identification
- **LOCK ID** : `LOCK_ZREPORT_C33_DEAD_WINDOW`
- **Créé** : 2026-07-06
- **Origine** : finding C33/C04 de l'audit externe, CONFIRMÉ par triage adversaire + lecture directe (reports/audit-externe-triage-2026-07-06/VERDICT.md)
- **Status** : `DRAFT` — nécessite gate owner §10 (fiscal chain, human gate CLAUDE.md §10)

## §2. Fichier frozen ciblé
| Path | Pourquoi frozen | Lignes |
|---|---|---|
| `app/Services/Fiscal/ZReportService.php` | CLAUDE.md §7 — close logic + chain HMAC NF525 | close():231 (borne basse aggregate), aggregate():344-346 + bloc refund-mirror 370-406 |

## §3. Problème (confirmé code réel HEAD b0f7b7285)
`close()` appelle `aggregate($branchId, $open->opened_at, $closedAt)` (ZReportService.php:231) → borne basse = `opened_at` du Z **courant**. `aggregate` filtre `created_at > $from` (:346). Donc une commande créée APRÈS le `closed_at` du Z précédent mais AVANT le `opened_at` du Z suivant tombe dans **aucun** Z signé (Z_n excluait `created_at <= closed_at`, Z_{n+1} exclut `created_at <= opened_at`). Recette encaissée hors de tout Z = **sous-déclaration CA/TVA NF525**.

**Pertinence V1 réelle** : Le Cayenne opère tard/après minuit (cf. fix minuit-straddle 2026-07-04). Une commande cash/COD créée entre le close de fin de journée et l'open du lendemain, encaissée au comptoir (fiscal_sequence_no alloué par PaymentService), disparaît des deux Z. `php artisan fiscal:verify-z-membership` (VerifyZMembershipCommand.php:99-106) la remonte déjà en « TROU » = contrôle détectif existant, mais pas préventif.

## §4. Scope — surgical
Rendre la partition **continue** : borne basse du Z = `closed_at` du Z CLOSED précédent (ou epoch/première vente si aucun Z précédent), au lieu de `opened_at` du Z courant. Chaque euro appartient à exactement un Z, sans trou ni chevauchement.
- Option retenue (à valider owner) : dans `close()`, calculer `$from = previousClosedZ?->closed_at ?? null` et le passer à `aggregate()` au lieu de `$open->opened_at`. `aggregate` garde `created_at > $from` (borne basse exclusive) et `created_at <= $to` (haute inclusive) → partition `(closed_{n-1}, closed_n]` sans trou.
- ⚠ Blast radius à traiter dans le même patch : le bloc **refund-mirror** (:370-406) utilise `$from` pour éviter le double-comptage post-Z — il doit rester cohérent avec la nouvelle borne (le miroir négatif d'un remboursement post-Z ne doit ni disparaître ni doubler). La re-signature HMAC de la chaîne doit rester valide.

## §5. Files
Modifié : `app/Services/Fiscal/ZReportService.php` uniquement. Lus (non modifiés) : PaymentService (alloc fiscal_seq), VerifyZMembershipCommand (contrôle détectif), FiscalSequenceService.

## §6. Acceptance (binaire)
- [ ] Test TDD `tests/Feature/Fiscal/ZReportContinuityTest.php` (TO BE CREATED) dérivé du VRAI `close()` (pas de `$from` posé à la main) : commande créée entre close(Z_n) et open(Z_{n+1}) → apparaît dans Z_{n+1} ; 0 trou remonté par la logique verify-z-membership.
- [ ] Non-régression : aucun euro compté DEUX fois (test partition : somme des Z = somme des ventes, aucune vente dans 0 ou 2 Z).
- [ ] Refund-mirror préservé (test post-Z refund : le miroir négatif reste dans exactement un Z).
- [ ] `php artisan fiscal:verify-chain --all` = CHAIN OK ×4 avant ET après.
- [ ] Suite fiscale complète verte (`php artisan test --filter 'ZReport|Fiscal|Z'`).
- [ ] `php artisan fiscal:verify-z-membership` = 0 TROU sur données de test post-fix.

## §7. Rollback
`git revert <sha>` (commit frozen isolé). Branche filet `backup/pre-owner8-2026-07-06`. Aucune donnée mutée (recalcul de fenêtre uniquement ; les Z déjà signés ne sont pas ré-écrits — le fix s'applique aux Z FUTURS). Si un Z historique a un trou, il reste détecté par verify-z-membership (pas de ré-signature rétroactive sans gate séparé).

## §8. Sub-agent
Implémenteur unique fiscal (aucun parallélisme sur ZReportService). Vérification post-patch : orchestrateur + re-run chain + verify-z-membership.

## §9. Guard
Commit frozen citera `LOCK: LOCK_ZREPORT_C33_DEAD_WINDOW`. Pas de --no-verify.

## §10. Sign-off — EN ATTENTE OWNER
- **Recommandation Claude** : APPLIQUER — c'est un vrai trou NF525 pertinent pour un restaurant opérant tard ; le fix est structurellement propre (partition continue) et le contrôle détectif confirme le diagnostic. MAIS c'est frozen fiscal à blast radius (refund-mirror + chaîne HMAC) → **je ne l'applique pas sans ton OK explicite**.
- **Décision owner** : ☐ APPLIQUER sous ce LOCK · ☐ DIFFÉRER (le contrôle détectif verify-z-membership suffit en V1) · ☐ autre.
