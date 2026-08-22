# Vagues 1 à 4 — rapport (GOAL CAISSE PARFAITE, 2026-08-22)

Branche `goal/caisse-parfaite-2026-08-22` depuis `fbe13a48a`. Aucun push.

## Vague 1 — pré-vol : PORTE FRANCHIE
Les 4 mesures publiées se reproduisent **à l'identique** (53/55/48/97 %, en-tête caché
214/207/214/43, grille à y=792, bloc de suivi 432 px). Instantané SQL pris. NF525 : 7281 lignes,
dernier hash `ffe782b9f42f`.

## Vague 2 — audit en éventail : 4 spécialistes en parallèle, lecture seule
Architecte · UX/A11y · RED-team · Architecte de données. Chaque constat regreppé avant d'être
retenu. **Deux tâches du GOAL sont mortes, et une erreur factuelle du GOAL a été corrigée.**

| Constat | Verdict | Preuve |
|---|---|---|
| Le GOAL citait `PosComponent.vue:1117` comme champ nom client | **FAUX** | c'est `deliveryInline.name` (livraison), qui écrit `orders.token` avec le PREMIER MOT (:5634). Le vrai champ est **:1063** |
| Replier les 4 panneaux de suivi (option A) | **RÉFUTÉ** | mandat Q10 21/05 (`:392-399`) : un panneau vide est la balise de vie du sondage · P0 10/08 (`:647-653`) : « Web payées » n'a aucun bouton, son seul rôle est d'ÊTRE VU |
| Déplacer le champ nom vers le corps du panier | **RÉFUTÉ** | `:1048-1051` — déjà indécouvrable une fois le 05/08 ; le corps DÉFILE avec les lignes de commande |
| Le rognage de l'en-tête est le correctif du 19/08 | **TIENT** | `pos-v5.css:766-836`, et ma mesure (152 px = 20 vh) le confirme appliqué comme écrit |
| F1–F12 évitent de défiler | **TIENT, mais invisible** | `:3115-3119`, `:5066-5074`, testé `posBarcode.spec.js:67-76` — aucune légende à l'écran |

## Vague 3 — correctif : C2 ATTEINT
**Cause trouvée en mesurant** : le plafond `max-height: 20vh` du 19/08 visait un pied
« ≈ 394 px ». Mesuré le 22/08 : le pied fait **122 px**. Relâché au-dessus de 760 px de fenêtre.

Seuil cherché empiriquement (1366 × hauteur décroissante, corps contre son plancher de 20 vh) :
`1080→460 · 900→298 · 800→208 · 768→179 · 740→155 · 720→149 · 700→144 · 680→138` = tient ;
`640→127 < 128` et `600→116 < 120` = rompt. Arrêt à 760 (marge 25 px à 768, 7 px à 740).

| à 1366×768 et 1024×768 | avant | après |
|---|---|---|
| en-tête du panier | 152 px sur 366 (**214 cachés**) | **331 px, 0 caché** |
| champ « Nom du client » | hors de la zone visible | **visible sans aucun geste** |
| corps du panier (plancher 154) | 372 px | 179 px — toujours au-dessus |
| pied / bouton payer | entier (690 < 768) | inchangé |
| à 1024×600 (sous le seuil) | 108 px, 247 cachés | **inchangé** — 19/08 intact |

Porte de mesure `tests/e2e/goal-caisse-portes-de-mesure.spec.js`, écrite AVANT le correctif :
**7 échecs → 3**. Les 3 restants sont C1 sur les petits gabarits — l'arbitrage G1, non tranché.

## Vague 4 — chaîne du nom client : trou fermé
`tests/Feature/Kitchen/KitchenTicketCustomerNameTest.php` (4 cas) couvre le tronçon que rien
ne couvrait : DB → `printOnce` (claim + hydrate) → rendu → **octets envoyés au transport**.
Éprouvé par mutation : rendu qui oublie le nom → 2 rouges ; `hydrate()` qui perd la colonne →
2 rouges ; restauré → 4 verts. Répertoire `tests/Feature/Kitchen` complet : **126 verts**.

## État des critères
| | état | note |
|---|---|---|
| C1 | **OUVERT** | bloqué sur **G1** ; l'option A est réfutée, le coût de B et C est désormais connu |
| C2 | **ATTEINT** | 4 gabarits, 0 contrôle coupé |
| C3 | **TENU** | vert avant et après — le gain du 19/08 n'a pas été repris |
| C4 | **ATTEINT** | 4 cas, éprouvés par mutation |
| C5 | suite complète en cours | Vitest déjà : **432 fichiers, 3517 verts, 0 rouge** |
| C6 | **0 ligne** | aucun fichier gelé touché |

## Trois trous consignés, non traités ici
1. **F1–F12 sans légende**, et `PosV5SearchInput` prévoit un indice `<kbd>` que
   `PosComponent.vue:717-727` ne passe jamais. ⚠️ Avant d'étiqueter : `onFKeyShortcut` indexe
   `categories` (brute) alors que la grille rend `browseCategoryTiles()` qui **filtre `id > 0`**
   (`posBrowseView.js:58`) — **F1 vise la sentinelle « toutes catégories », pas la 1re tuile**.
2. **Marge sous 600 px** : le calcul du 19/08 annonce 600 px comme pire cas ; le seuil réel
   mesuré est vers 640-660. Risque PRÉEXISTANT, non introduit par ce GOAL.
3. **Deux champs « nom client »** cohabitent dans `PosComponent.vue` (`pos_customer_name` :1063
   et `deliveryInline.name` :1117 → `orders.token`, premier mot). Les fusionner en casserait un.
