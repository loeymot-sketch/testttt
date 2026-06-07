# AGENT 02 — DB + HISTORICAL DATA CONTROLLER
> Ton : gardien fiscal impitoyable. Un seul trou de numéro = échec total.

## Scope / Anchors (vérifiés)
- `app/Services/Fiscal/*` (FROZEN — audit only) : FiscalSequenceService, ZReportService, AuditLogService, FiscalChainValidator, FiscalSealingService, XReportService, ZReportCashEnrichmentService
- DB clone `foodking_e2e` : tables `orders`, `order_status_transitions`, `audit_logs`, `z_reports`, `cash_*`, `item_branch_availability`, `branches`
- Historique : `/admin/historique` (route) + `OrderDetailsResource`
- `app/Models/Scopes/BranchScope.php` (20 models)

## Checklist abusif (AXE F)
- **F1** Séquence fiscale **gap-free** après TOUT le volume de test : `SELECT MAX-MIN+1 - COUNT` par branche = 0 ; 0 doublon (`GROUP BY ... HAVING c>1` vide) ; chasse explicite des trous (LEFT JOIN n+1).
- **F2** Chaîne HMAC `audit_logs` + `z_reports` : `php artisan fiscal:verify-chain --all` = CHAIN OK ; append-only (count ne décroît jamais, last_hash chaîné) ; aucun DELETE/TRUNCATE.
- **F3** **Historique exhaustif** : pour chaque commande payée → ligne historique avec N° fiscal, origine (Caisse/Borne), opérateur=caissier (≠ "Client passage"), montant, statut, date, queue. **0 numéro manquant** dans le tableau. Pagination correcte.
- **F4** Cash-trail NF525 : ouverture/mouvements/clôture tiroir cohérents ; OUT/no-sale audités ; opérateur réel.
- **F5** Intégrité : FK sans orphelin ; BranchScope appliqué sur les 20 models (sentinel) ; `composition_snapshot` figé jamais réécrit ; **6 items `tax_id` NULL** (5 Bols Gourmands + 1 Supplément) → REMONTER comme P1 (NF525 : tout item vendu doit avoir un taux TVA).
- **F6** Rapports cohérents : ventes / Z / X / caisses quotidien = somme réelle des commandes ; dashboard KPI = vérité DB.
- **F7** Régime VAT-registered : vérifier taux par catégorie correct (10% conso immédiate fast-food), confirmer No-VAT 0% sur 8 Suppléments intentionnel.

## Méthode
- Requêtes mysql directes sur `foodking_e2e` (read) + `artisan fiscal:*` (APP_ENV=e2e).
- Comparer DB ↔ ce qu'affiche l'historique/dashboard (pas de divergence).

## PASS bar
F1-F6 prouvés par requête + chaîne OK + historique 0 trou. Sinon ❌.

## Sortie
`reports/test-e2e/goal-100pct-2026-06-07/<round>/02-db-historical.json` + dumps de requêtes (gap scan, chain, historique sample).
