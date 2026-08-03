# Handoff S2 → S6 / CENTRAL — « VAT » imprimé sur TOUS les tickets clients (2026-07-29)

## Le fait (VU sur un ticket réel, capture `tests/captures/goal-s2-v4-recheck-2026-07-29/v4-3-reimpression.png`)
Le ticket client imprime **« VAT (10.00 %) »** au lieu de « TVA (10,00 %) », sur chaque
ligne et dans le récapitulatif de taxes.

## Racine — DATA, pas code
`taxes.name` vaut littéralement **`VAT`** pour la taxe id=3 (10 %), et **`No-VAT`** pour id=1 (0 %).
Vérifié en DB (`foodking_e2e`) : **78 items du menu réel** pointent sur la taxe id=3, 20 sur id=1.
Le rendu est fidèle à la donnée — `ReceiptComponent.vue:174` et `:205` affichent `item.tax_name`
/ `line.tax_name` tels quels. Aucun correctif de code n'est justifié (le corriger côté affichage
créerait une seconde source de vérité du nom de taxe = doublon interdit, DISCIPLINE §9).

## Pourquoi S2 ne l'a PAS fait
1. `taxes` relève des réglages/catalogue = voie CENTRAL, pas CAISSE.
2. Le nom de taxe apparaît sur un **document client fiscal** et dans les états de TVA :
   renommer met à jour le rendu des tickets **passés** (le nom est joint au rendu, pas figé
   dans `composition_snapshot`). Ce n'est pas un changement de taux ni de montant — donc pas
   une atteinte NF525 — mais ça mérite une décision explicite, pas un UPDATE silencieux d'une
   session parallèle.

## Diff proposé (1 UPDATE, aucun code)
```sql
UPDATE taxes SET name = 'TVA'    WHERE id = 3 AND name = 'VAT';
UPDATE taxes SET name = 'TVA 0%' WHERE id = 1 AND name = 'No-VAT';
```
Sentinelles à vérifier après : `MenuVat10PercentSentinelTest`, `PosReceiptTaxLinesTest`,
`Vat10ZReconciliationTest` (elles assertent des **taux**, pas des noms — a priori vertes,
à confirmer). Contrôler aussi que le seeder de taxes ne réintroduit pas « VAT ».

## Déjà corrigé par S2 (voie i18n, sans risque)
`resources/js/languages/fr.json` → `label.item_description` : « Article Description » (anglais)
→ « **Désignation** ». Affecte les 5 composants de ticket (caisse, commandes POS, en ligne,
table, compte client).

## Bruit de données constaté au passage (non bloquant, dev seulement)
~20 taxes factices « TVA 46% / TVA 59% … » à 20 % ou 5 %, rattachées à 1-2 items latins de
factory (« quia recusandae »). Elles ne touchent pas le menu réel (tout à 10 %), mais elles
polluent l'écran des taxes. Purge = décision owner.
