# Architecture — Stock intelligent par ingrédients (vision owner 2026-07-23)

> Vision owner (verbatim résumé) : photos de factures → stock ; chaque produit paramétré en
> matières (3 kg haché ≈ 44 pièces ; 1 kg poulet ≈ 5 sandwichs ; mixte = 1 steak 75 g + ~120 g
> poulet ; cheddar/jambon comptés ; crudités approximatives) ; consommation + charges déduites
> des commandes ; relié caisse/borne/web/app ; structure produit vendable à d'autres restos
> (agence) ; onboarding d'un menu par IA (règles → catalogue complet structuré).

## Verdict faisabilité : OUI, SANS refaire — couche ADDITIVE sur 3 fondations déjà en prod
1. **`composition_snapshot` (NF525, immuable)** : chaque vente enregistre déjà EXACTEMENT ce qui
   est parti (variations viandes, extras, sauces, menus). = la source de consommation parfaite,
   rejouable rétroactivement (historique complet depuis toujours).
2. **SSOT catalogue data-driven** : composer profiles DB (wizard borne/caisse/web), items/extras/
   variations, availability SSOT (`AvailabilityService`), `stock_levels` polymorphe + mouvements.
3. **Surfaces branchées** : caisse/borne/web/`/m` passent déjà par les mêmes endpoints/events.

## Les 6 briques (ordre de construction)
### B1 — Matières premières (`ingredients`)
Table : nom, unité de base (g | pièce | tranche | cl), poids/volume par pièce si applicable,
prix d'achat moyen, seuil d'alerte. Ex : Viande hachée (g, pièce=68 g dérivé de 3 kg→44),
Steak (pièce 75 g), Poulet (g), Cheddar (tranche), Jambon (tranche), Canette 33cl (pièce).
### B2 — Recettes (`recipes` : produit/variation/extra → ingrédients + quantités)
Ex : Cayenne = 1 pain + 120 g poulet + 2 cheddar + 1 jambon + crudités(approx) + 1 dose sauce.
Mixte = 1 steak (75 g) + 120 g poulet. Extra « Cheddar » = 1 tranche. Menu complet = 1 portion
frites + 1 canette. Approximations ASSUMÉES (crudités/sauces en « doses » moyennes).
### B3 — Moteur de consommation (théorique)
À chaque commande scellée : snapshot → recettes → décrément stock théorique + coût matière de la
commande. Rejouable sur l'historique (les snapshots existent). Écart théorique vs réel corrigé par
**inventaire périodique** (comptage rapide sur /m) — c'est le standard du métier, l'approximation
ne casse rien.
### B4 — Entrées de stock : factures en photo
/m (ou admin) : photo de facture → IA lit lignes (ingrédient, quantité, prix) → propose → owner
valide en 1 tap → stock entre + charge comptabilisée + prix d'achat moyen mis à jour.
### B5 — Comptabilité & pilotage
Food cost par produit (coût recette vs prix de vente), marge, consommation/jour, liste d'achats
intelligente (déjà « 🛒 À acheter » sur /m — s'enrichit du prévisionnel), charges entrantes
(factures) vs CA (déjà NF525). Alertes : stock théorique bas → suggestion 86 ou achat.
### B6 — Onboarding IA (vision agence)
Entrée : menu d'un resto (texte/photo/PDF) + règles maison → sortie STRUCTURÉE : catégories,
produits, prix, TVA, profils composer (steps wizard), recettes squelettes → insérés via les
seeders/commandes `menu:*` existants (précédent : `generate-menu-from-api.mjs`). L'humain valide,
l'IA ne pousse jamais seule (garde-fou NF525/prix).

## Phases (chaque phase = utilisable seule, testée chez nous d'abord)
- **P1** : B1+B2 (tables + écran paramétrage simple + saisie des recettes de NOS produits).
- **P2** : B3 (conso auto + food cost + rejouer l'historique) + enrichir /m (théorique + à acheter).
- **P3** : B4 (photo facture IA → entrée stock + charge).
- **P4** : B5 complet (tableaux marge/charges) + inventaire périodique /m.
- **P5** : B6 (onboarding IA menu → catalogue) = produit agence.

## Ce qu'il faut de l'owner (par phase, jamais tout d'un coup)
- P1 : la FICHE PARAMÈTRES (liste matières + poids/pièce + recette par produit — je fournis un
  tableau pré-rempli à corriger, pas une page blanche).
- P3 : 3-4 photos de factures réelles (échantillon pour calibrer la lecture IA).
- Décisions produit au fil de l'eau (format 1 décision + options + reco).

## Risques assumés & garde-fous
- Approximation crudités/sauces → doses moyennes + inventaire correcteur (standard métier).
- IA facture/menu : TOUJOURS validation humaine avant écriture (aucun auto-commit).
- NF525 : la couche stock/compta ne touche JAMAIS la chaîne fiscale (lecture des snapshots only).
- V1 = notre resto d'abord (CONSTITUTION) ; la généralisation agence = après preuve chez nous.

---
## AMENDEMENTS post-adversaires (2026-07-23 — 2 agents, rapports dans reports/goal-mega-2026-07-22/)
**Verdict conjoint : EXÉCUTABLE PAR PHASES SANS REFONTE — sous conditions écrites ci-dessous.**
1. **Snapshot « source parfaite » sur-vendu → corrigé** : les suppléments viande/sauce sont
   génériques (« Viande supplémentaire ×2 » sans dire laquelle ; 0 parfum de canette de menu dans
   3 305 snapshots). P1-P2 assument des **approximations écrites** (supplément viande = mix moyen
   pondéré ; canette de menu = « canette générique »). Précision exacte plus tard = snapshot
   schema_version=2 (builder NON frozen, additif) + intake borne/caisse = **LOCK owner** (seul
   point qui flirte avec la refonte — repoussé tant que le mix moyen suffit).
2. **Doublon canettes tranché** : les boissons vendues À L'UNITÉ restent comptées par l'existant
   (stock_levels item) ; la couche ingrédient NE recrée PAS « Canette 33cl » — elle référence.
   Une seule vérité par objet physique.
3. **Nouvelles tables dédiées** `ingredient_*` (signées, décimales, raisons ouvertes) en MIROIR du
   pattern StockService (idempotent, mouvements append-only) — pas de réutilisation forcée de
   stock_levels (unsigned, CHECK>=0, enum fermé).
4. **Recettes mappent des GROUPES logiques** (43 noms ≈ 535 rows ItemExtra) pas des ids uniques.
5. **Rejouer l'historique sur la DB PROD (VPS)** — la locale est polluée de tests e2e sans flag.
6. **Achats/fournisseurs/factures = domaine NEUF complet** (rien n'existe — seul le carnet plat).
   Chiffré comme tel en P3-P4. **B6 (IA onboarding) = produit à part entière** (les commandes
   menu:* sont des one-shots Le Cayenne, pas un importeur générique).
7. **Périmètre « toutes les commandes » = caisse + borne + web + /m** (l'app mobile RN est
   standalone sans API par mandat V1 — ses commandes n'existent pas côté serveur).
8. Vision agence : 0 constante métier en dur (ratios en DB), ingrédients branch-scopés dès P1,
   stocker le BRUT des factures (photo+lignes+corrections) dès P3 (données d'apprentissage B6).
