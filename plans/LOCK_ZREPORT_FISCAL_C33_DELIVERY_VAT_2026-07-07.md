# LOCK_ZREPORT_FISCAL_C33_DELIVERY_VAT — partition Z continue + TVA livraison

> Override frozen NF525. Status **APPROVED** — l'owner a explicitement demandé (2026-07-07) « prends les bonnes décisions, applique tva » → gate §10 satisfait par instruction owner directe.

## §1 Identification
- LOCK ID : `LOCK_ZREPORT_FISCAL_C33_DELIVERY_VAT`
- Créé/approuvé : 2026-07-07 (owner instruction directe)
- Fichier frozen : `app/Services/Fiscal/ZReportService.php` (§7 + §8)
- Remplace/complète : LOCK_ZREPORT_C33_DEAD_WINDOW (DRAFT) — même fichier, appliqués ensemble.

## §2 Deux changements
### A. C33 — trou fenêtre morte entre 2 Z
Borne basse de l'agrégation = `closed_at` du Z CLOSED précédent (au lieu de `opened_at` du Z courant) → partition continue `(closed_{n-1}, closed_n]`, chaque euro dans exactement un Z. `close()` passe `previousClosedZ?->closed_at` à `aggregate()`. Refund-mirror (byTaxRate/total_tva) préservé cohérent avec la nouvelle borne.

### B. TVA livraison
`delivery_charge` est ajouté au total APRÈS `totalTax` → porte 0 % TVA aujourd'hui. Décision owner : **la livraison porte la TVA au taux nourriture (10 %, config/menu.php:73), traitée en TTC** (le client paie le même montant). Dans l'agrégation Z : la part TVA livraison `= delivery_charge × 10/110` s'ajoute au bucket `total_by_tax_rate[10.0]` (SSOT), la part HT `= delivery_charge × 100/110` au HT. Ainsi `total_ttc` INCHANGÉ (client paie pareil), mais l'identité NF525 `total_tva == Σ total_by_tax_rate` inclut désormais la TVA livraison. Miroir même pattern que LOCK_ZREPORT_F1 (netting discount → byTaxRate).

## §3 Acceptance (binaire, NON négociable)
- [ ] `tests/Feature/Fiscal/ZReportContinuityTest.php` : commande entre close(Zn) et open(Zn+1) → dans Zn+1, 0 trou (verify-z-membership).
- [ ] `tests/Feature/Fiscal/ZReportDeliveryVatTest.php` : commande livraison delivery_charge>0 → total_by_tax_rate[10] inclut delivery×10/110 ; total_ttc INCHANGÉ ; identité total_tva == Σ byTaxRate EXACTE ; total_ttc == total_ht + total_tva.
- [ ] Non-régression : aucune double-comptée, aucun euro dans 0 ou 2 Z, refund-mirror préservé, discount netting (LOCK_F1) préservé.
- [ ] `php artisan fiscal:verify-chain --all` = CHAIN OK ×4 AVANT et APRÈS.
- [ ] `php artisan fiscal:verify-z-membership` = 0 TROU.
- [ ] Suite fiscale complète verte (`--filter 'ZReport|Fiscal|Z'`).

## §4 Rollback
`git revert <sha>` (commit frozen isolé). Branche filet `backup/pre-convergence-golive-2026-07-07`. Les Z DÉJÀ signés ne sont PAS ré-écrits (fix s'applique aux Z FUTURS). Si un Z historique diverge → détecté par verify-z-membership, pas de ré-signature rétroactive.

## §5 Sub-agent
Implémenteur fiscal UNIQUE (jamais parallèle sur ZReportService). Vérification post-patch : orchestrateur + re-chain + verify-z-membership.

## §6 Sign-off
Owner : APPROVED par instruction directe 2026-07-07. Si l'identité NF525 casse ou la chaîne ne re-vérifie pas → REVERT immédiat (correctness > feature).
