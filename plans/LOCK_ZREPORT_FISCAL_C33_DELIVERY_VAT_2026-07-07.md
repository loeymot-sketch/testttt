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

## §7 Extension — P1 appartenance-Z différée (fiscal_dated_at) + P2 détecteur honnête + P3 tie-break (2026-07-07, owner APPROVED)

> Étend ce LOCK au même fichier frozen `ZReportService.php` (+ non-frozen
> `VerifyZMembershipCommand`, `PaymentService`, `OrderService`, migration
> additive). 3 findings d'audit adversaire sur le travail C33/téléphone.

### P1 — Commande différée × fenêtre Z = reçu dans AUCUN Z signé (le plus grave)
Une commande à paiement DIFFÉRÉ (COUNTER_DEFERRED : borne Plan B, walk-in,
téléphone) obtient son `fiscal_sequence_no` à l'ENCAISSEMENT, pas à la création.
`aggregate()` fenêtrait par `created_at` → une vente créée dans Z_n mais encaissée
après l'ouverture de Z_{n+1} tombait hors de TOUT Z signé (fiscal NULL à la
clôture Z_n → exclue ; `created_at <= from` à Z_{n+1} → exclue) = violation NF525
gap-free silencieuse, pour TOUTES les différées.

**Fix** : colonne additive nullable `orders.fiscal_dated_at` (instant d'allocation
fiscale), posée dans `PaymentService::confirmCounterPayment` + les 2 arêtes
UNPAID→PAID différées d'`OrderService` (livraison COD@doorstep, « marquer payé »).
`aggregate()` + `warnOnOrphanedPaidOrders` clé la fenêtre sur
`COALESCE(fiscal_dated_at, created_at)` (helper `fiscalDateExpr()` mémoïsé, dégrade
en `created_at` si colonne absente). Borne basse post-Z symétrisée sur la même date
fiscale. Différées scellées au Z d'encaissement (exactement une fois) ;
non-différées inchangées (fallback `created_at`, rows historiques byte-identiques).
Préservés : `total_ttc`, `total_tva == Σ byTaxRate`, C33, TVA livraison,
refund-mirror, discount-netting F1.

### P2 — Détecteur `verify-z-membership` masquait les orphelins HISTORIQUES
Le détecteur reconstruisait toutes les fenêtres en sémantique C33 → une vente en
fenêtre-morte d'un Z HISTORIQUE (signé sous l'ancienne sémantique `opened_at`)
était déclarée couverte = faux négatif sur la classe même de bug détectée.

**Fix** : bornage pré/post-C33 via config `fiscal.c33_cutover_at`. Z pré-C33 =
`(opened_at, closed_at]` sur `created_at` (sémantique d'alors) ; Z post-C33 =
`(closed_prev, closed]` sur `COALESCE(fiscal_dated_at, created_at)`. Compte HONNÊTE
(base e2e : 2444 orphelins réels remontés — 2442 fenêtre-morte pré-C33 branche 1 +
2 branches sans Z ; l'ancien détecteur n'en montrait que 2).

### P3 — Sélection du Z précédent `closed_at < $closedAt` STRICT
Deux clôtures au même instant (closed_at égal) → le strict `<` droppait le vrai
prédécesseur → le Z suivant ré-agrégeait sa fenêtre = double-comptage.
**Fix** : tie-break déterministe `closed_at <= $closedAt` + `orderByDesc('id')`.

### Preuves
- CHAIN OK ×4 AVANT et APRÈS (branches 1,7,8,9).
- Régression fiscale : 480 (`ZReport|Fiscal|Membership|Delivery|Deferred`) / 580
  (élargi refund+vat) / 455 (payment+order) verts, 0 fail.
- TDD : `ZReportDeferredMembershipTest` (différée Z_n→Z_{n+1}, non-différée
  inchangée, tie-break même-instant) + `VerifyZMembershipCommandTest` (orphelin
  pré-C33 flaggé, fenêtre-morte post-C33 couverte).
- Commits (non poussés) : `b05ba62c5` (non-frozen) + `48eacd970` (ZReportService
  frozen). Le stamp `PaymentService` a été absorbé par le commit parallèle
  `0fcad5985` (agent concurrent, même working tree).
