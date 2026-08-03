# ADVERSAIRE — Trous de vision & ambiguïtés (stock ingrédients BOM)

Red-team du plan `plans/ARCH_STOCK_INTELLIGENT_BOM_2026-07-23.md`. 2026-07-23.

## 1. Points OUBLIÉS par l'owner (priorisés)

| # | Réalité absente | Impact si ignoré | Quand |
|---|---|---|---|
| 1 | **Repas staff, offerts, commandes ratées/refaites** (l'ancienne part à la poubelle = double conso) | Écart théorique-réel dès la semaine 1, food cost faussé, le système perd la confiance | P2 : bouton « staff/offert/raté » = conso sans vente |
| 2 | **Pertes, casse, invendus, DLC** (haché = DLC 24-48 h, pain jeté) | « Il reste 2 kg »… périmés → fausse sécurité | P2 bouton « perte » ; dates par lot P3 |
| 3 | **Doublon avec l'existant** : `stock_levels` compte DÉJÀ les canettes/items ; B1 recrée « Canette 33cl » en ingrédient | Deux vérités de stock pour le même objet → 86 borne et alertes achat divergent | **P1** : une seule source par objet |
| 4 | **HT vs TTC + TVA achats (5,5/10/20)** mélangées sur factures | Prix d'achat moyen faux de 5-20 % → marge fausse | P3 impératif |
| 5 | **Huile friteuse + consommables** (emballages, sacs, serviettes) | Grosse charge invisible, pas liée à une recette (huile = par jour) | P3 « charges non-recette » ; par commande P4 |
| 6 | **Inventaire physique : qui, quand, traitement de l'écart** — cité par le plan, personne n'est désigné | Sans comptage hebdo, le théorique = bruit en 1 mois | P2 léger (10 matières) ; formel P4 |
| 7 | **Coulage/vol + grammage réel ≠ fiche** (44 pièces/3 kg théorique, le cuisinier fait 40-47) | Pertes silencieuses ; l'inventaire doit recaler AUSSI le ratio, pas juste le stock | P4 (rapport d'écart par matière) |
| 8 | **Multi-fournisseurs, prix variables, avoirs/retours** (lignes négatives) | Coût moyen à trancher ; l'IA compte un avoir comme un achat | P3 |
| 9 | **Unités mixtes** (facture kg/carton, recette tranche/pièce) | Schéma mal posé = re-migration | P1 (unité de base + conversion) |
| 10 | **Prévisionnel** (samedi ≠ mardi) | Liste d'achats « intelligente » = marketing sans historique par jour | P4/P5 |

## 2. Ambiguïtés à clarifier AVANT de coder

- « 3 kg ≈ 44 pièces » : steaks **façonnés maison** (ratio variable) ou **achetés formés** (pièce exacte) ? Change le schéma P1.
- Cheddar/jambon : **en tranches** ou **en bloc/kg** ? Unité de base P1.
- Sauces : **seau acheté** (dose ml) ou **maison** (= sous-recette) ? Le plan dit « dose » sans trancher.
- « Comptabilité » : **food cost** seulement, ou vraie compta (loyer, salaires, TVA déclarative, export comptable) ? Scope ×5.
- « app » : la mobile RN est **standalone sans API** (mandat V1) → ses commandes ne seront PAS déduites. « Toutes les commandes » = caisse/borne/web//m. À confirmer.
- Frites : combien de grammes la portion ?

## 3. Verdict clarté : **OUI, de justesse — sous 5 réponses**

Vision et chiffres exploitables, MAIS les questions 1-3 conditionnent le **schéma de données** P1. Les poser = 5 minutes ; les ignorer = migration.

1. Tes steaks : façonnés par toi ou achetés déjà formés ? *(Défaut : maison → stock en grammes, ratio pièces/kg ajustable.)*
2. Cheddar/jambon : en tranches (paquets) ou en blocs à trancher ? *(Défaut : tranches → unité = tranche, conversion au paquet.)*
3. Tes sauces : achetées en seau ou faites maison ? *(Défaut : achetées → 1 dose moyenne en ml.)*
4. « Charges » : juste coût matière vs CA, ou aussi loyer/élec/salaires ? *(Défaut : food cost d'abord ; le reste = saisie mensuelle P4 ; la vraie compta reste chez l'expert-comptable.)*
5. Qui compte le stock réel, à quel rythme ? *(Défaut : toi ou le manager, 1×/semaine, 10 min sur /m, les 10 matières chères — sans ça le système ment au bout d'un mois.)*

## 4. Risques vision agence

1. **Zéro constante métier en dur** : 68 g/pièce, doses, unités = 100 % en DB par resto. Un ratio hardcodé = redéploiement par client.
2. **Stock ingrédients branch-scopé dès P1** (BranchScope) même mono-branche, sinon multi-restos = refonte du cœur.
3. **Parseur factures : stocker le brut** (photo + lignes lues + corrections) par fournisseur dès P3 — sinon chaque resto recalibre de zéro et l'onboarding IA (P5) n'a aucune donnée d'apprentissage.
