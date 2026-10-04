# Bilan — GOAL caisse / cuisine / encaissement (2026-10-03)

**Branche :** `qa/corrige-rapports-2026-09-28`, de `b0144f788` à HEAD.
**GOAL :** `plans/GOAL_CAISSE_CUISINE_REMARQUES_2026-10-03.md`.
**Inventaire :** 79 remarques du propriétaire (`REMARQUES_PROPRIETAIRE.md`) et 3 statuts de départ
(`STATUT_A/B/C`).

**État de livraison au 2026-10-04 :** le code est **poussé sur GitHub** (branche `qa/corrige-rapports-2026-09-28`
et branche de release `pos/category-first-caisse-2026-06-23`, en avancement simple, sans force). Il n'est
**pas déployé sur le serveur** : l'accès ssh à la production est refusé par le classificateur de permissions
(porte G3, voir §2). Le navigateur et GitHub, eux, fonctionnent de nouveau.

## 1. Ce qui a changé, remarque par remarque

Les 24 remarques ouvertes au départ, plus 2 défauts d'argent trouvés en route, sont traités. Chacune a son
test, rouge avant le correctif et vert après.

### Cuisine (écran KDS et ticket cuisine)

| Remarque | Avant | Maintenant |
|---|---|---|
| R-071 Suppléments en grand | KDS en 15 px, plus petit que le produit ; ticket inversé mais pas plus grand | **KDS** : 22 px, gras, blanc sur noir, au moins la taille du produit, jamais coupé dans un mot. **Ticket** : double taille (2×2) ; un libellé trop long reste en double hauteur, sur un seul bandeau |
| R-072 « # » | Seulement si une ligne supplément s'affichait | Dès qu'un extra est payant ou offert, même replié (sauce en plus, 2ᵉ sauce frites, option de formule). Écran, ticket et tiroir Historique |
| R-070 Nom des sauces | Une sauce vendue seule sortait « SAU » | « SAUCE KETCHUP » en entier (les 13 sauces), écran et ticket |
| R-052 Numéro géant | Double taille (environ 6 mm) | Hauteur maximale de l'ESC/POS (×8, environ 2,4 cm). Pour 3 à 4 cm, il faut une image tramée (G4) |
| R-053 Frites et menu encadrés | Non encadrés | Badge MENU / FRITES, formule seule, frites vendues comme produit et Menu Enfant : blanc sur noir |
| R-054 « 2 x » | Rien ne le distinguait | Fond noir sur le ticket et sur l'écran |
| R-069 Supplément sous son produit | Le tiroir Historique laissait des sauces anonymes | Chaque sauce est nommée avec sa destination (produit ou frites). Une option de formule s'écrit « Frites : X » partout |
| R-075 Ligne Uber non reconnue | « ART » | Le titre Uber en entier (repli « ARTICLE UBER » si le titre n'est pas imprimable). La note client reste ; l'aperçu caisse est identique au ticket |

Défauts trouvés en route :
- un extra offert nommé comme une crudité imprimait un faux « O » (oignons crus) ;
- une option de formule repliée sous un sandwich sans extra n'était pas imprimée ;
- « Grande Portion » apparaissait en double (cadre et note) ;
- la note client d'une formule commandée seule n'était pas imprimée.

### Annulation, commandes téléphone, impression

| Remarque | Avant | Maintenant |
|---|---|---|
| R-060 Annuler sans justificatif | Le panneau de la caisse exigeait un motif tapé | Motif pré-rempli « Client non venu » : deux gestes, rien à taper |
| R-060 « Tout supprimer » | N'existait que pour les jours précédents | Bouton « Supprimer les commandes téléphone (N) ». Il ne touche jamais la borne, le site, une commande programmée pour plus tard, une commande payée ou fiscalisée, ni une autre branche. Seules les commandes affichées partent. Audit écrit |
| R-059 « Commandes ratées » 24 h | Absent | Onglet « Ratées (24 h) » en lecture seule : client, téléphone, produits, heure d'annulation. Rien n'est effacé (NF525) |
| R-048 Imprimer ou non | Aucune question sur la page Encaissement ni dans le Suivi ; le bouton promettait « Imprimer » | « Imprimer le ticket client ? » partout après un encaissement. Le bouton dit « Confirmer l'encaissement ». Un pont d'impression éteint n'est plus annoncé comme un succès |

### Caisse

| Remarque | Maintenant |
|---|---|
| R-012 Commandes web en rouge | Panneau teinté et boutons rouges (contraste AA) |
| R-015 Retirer le client fidélité | ✕ sur la pastille. Le client, son adresse et sa fidélité partent ; le panier reste |
| R-017 Temps de préparation | Réglable aussi depuis « Accepter » sur l'écran principal, borné à 5-120 min (bornes du serveur) |
| R-009 Contenu en mots techniques | Cartes du Suivi et du tiroir en symboles cuisine ; file « À encaisser » avec aperçu, suppléments, boisson et heure |
| R-016 Valider le retrait | Les commandes du site prêtes restent dans « Web payées » avec « Valider le retrait » (une seule fois, pas aussi dans « Prêt ») |
| R-038 Supplément libre | Refusé tant que le panier n'a aucun produit (ajout, vente, commande téléphone) |
| R-041 Offert | Aussi sur les options de formule (Grande Portion, Cheddar Fondu). Survit au rechargement ; le montant affiché égale le montant facturé |
| R-078 Galette Normale | La réinitialisation du catalogue ne la recrée plus |
| R-005 Bande blanche en bas | Mesurée le 2026-10-04 (le navigateur est revenu) : **72 px (1,9 cm) à 1280×720, 77 px à 1366×768, 108 px à 1920×1080**, soit 10 % de l'écran. Cause : la caisse tourne sous `zoom: 0.9`, mais `h-screen` et `100dvh` ne suivent pas le zoom. Après : **11 px aux trois tailles** (la marge basse voulue, égale à la marge droite). Au passage, les tuiles « Boissons / Menu enfant / Sauces supplémentaires » n'étaient plus coupées à 1080 |
| En-tête de la caisse (trouvaille) | De **1440 à 1920 px de large**, la colonne du titre tombait à 0 px et « Commande rapide » se repliait **par-dessus** les boutons. Après : colonne de 193 px, titre sur une ligne, boutons passés à la ligne |

Défauts d'argent corrigés en route : le panier affichait parfois autre chose que le montant facturé.

| Cas | Écart corrigé |
|---|---|
| Quantité supérieure à 1 avec une formule | Le panier affichait plus que le facturé |
| 2ᵉ et 3ᵉ sauces frites | Le panier affichait moins que le facturé |
| « Modifier » puis « Sans formule » ou « Boisson Seule » | La formule ou les sauces frites restaient facturées |
| « Modifier → Valider » sans rien changer | Une « Sauce supplémentaire » de plus à chaque fois |
| « Modifier » avec un supplément sur la même ligne que les sauces | La 2ᵉ sauce disparaissait (même cause racine que R-070) |

## 2. Ce qui attend le propriétaire

| Porte | Objet | Ce qu'il faut |
|---|---|---|
| **G1** | R-020, R-023, R-025, R-033, R-035 (assistant de la caisse, zone gelée) | Contresigner `docs/locks/LOCK_POS_WIZARD_REMARQUES_2026-10-03.md`, point par point |
| **G1 bis** | R-041 « Offert » sur une sauce vendue seule | C'est un article entier : il faut une remise de ligne dans `PricingService` (zone gelée) |
| **G2** | R-034 (Américaine), R-036 (portion 1,90 €), R-028 (cornichon), R-006 (barre du haut), R-059 (bouton « Reprendre » une commande ratée) | Décisions |
| **G3** | **Levée en grande partie le 2026-10-04** : le navigateur et GitHub fonctionnent de nouveau (captures faites, branche poussée). Reste l'accès ssh à la production, **refusé par le classificateur de permissions** de Claude Code | Lancer le déploiement toi-même avec `!` (commande dans le compte rendu). R-065 reste à vérifier |
| **G4** | R-052 au-delà de 2,4 cm | « ×8 suffit » ou « image tramée » |
| **G5** | R-047 (tiroir), R-074 (afficheur SAGA), R-007 (vitesse) | Essais au comptoir |
| **G6** | R-024 à R-032, R-040, R-042, R-078 (photos), R-079, R-068 | Vérification des données en production (lecture ssh, après G3) |

## 3. Preuves

- La relecture adverse a été faite par vague (code et « œil du propriétaire »), puis trois tours de
  convergence. Chaque P0 et P1 trouvé a été corrigé et testé.
- Zones gelées : **0 ligne modifiée par ce GOAL** (de `b0144f788` à HEAD). ⚠ Précision du 2026-10-04 : par
  rapport à la branche de release `96aa53a42`, le lot complet contient **un** changement de zone gelée,
  `PaymentComponent.vue` (+104 / −5), livré le 2026-10-02 par une autre session sous
  `LOCK_PAYMENT_COMPONENT_TITRES_RESTO_CB_PARTIELLE_2026-10-02.md`, **contresigné par le propriétaire**
  (« Oui, je valide »). Il était déjà couvert par les suites vertes.
- **Contrôle visuel du 2026-10-04**, sur un banc local (`php artisan serve :8790`, base de test, courriel en
  journal), captures lues une à une dans `tests/captures/goal-remarques-2026-10-03/` :
  - KDS : « # » devant le produit à supplément, « Œuf » en 22 px gras blanc sur noir, **témoin sans supplément
    sans « # »** (la mesure sait dire non) ;
  - caisse : commandes web en rouge, champ « 15 min » à côté d'« Accepter », aucune clé de traduction brute ;
  - page Encaissement : onglet « Ratées (24 h) », aucune clé brute ;
  - R-005 et en-tête : mesures avant/après aux mêmes tailles (`r005-avant-*`, `r005-apres-*`, `entete-*`) ;
  - le wizard (zone gelée) occupe bien toute la hauteur : il n'a pas la perte de 10 %.
- Résidus de test : 8 commandes `E2E-VISU-REMARQUES` payées par carte restent dans la base de **test** locale.
  Elles sont fiscalisées, donc le nettoyage ne les supprime pas, à dessein (NF525 : aucune suppression dure).
  La chaîne fiscale du banc reste `CHAIN OK` sur les 7 branches.
- Les chiffres des suites complètes, de la chaîne fiscale et du dernier tour de convergence sont au §4.

## 4. Suites complètes et convergence

**Suites complètes au commit `0e91f3e3f`**

| Contrôle | Résultat |
|---|---|
| Compilation (`npm run production`) | OK |
| PHPUnit | **6307 réussis, 0 échec** (6 incomplets, 36 ignorés, préexistants) |
| Vitest | **594 fichiers, 4833 réussis, 0 échec** (3 ignorés) |
| Chaîne fiscale `fiscal:verify-chain --all` | OK sur les 7 branches |
| Zones gelées, de `b0144f788` à HEAD | **0 ligne** |

Le correctif suivant, `03dc23ec1`, ne touche que du JavaScript. Après lui, la compilation et Vitest complet
ont été rejoués.

**Après `03dc23ec1`**

| Contrôle | Résultat |
|---|---|
| Compilation (`npm run production`) | OK |
| Vitest | **594 fichiers, 4834 réussis, 0 échec** (3 ignorés). Le test ajouté s'y trouve |
| Zones gelées | **0 ligne** |

PHPUnit et la chaîne fiscale n'ont pas été rejoués : aucun fichier PHP n'a changé depuis `0e91f3e3f`.

**Revues adverses, dans l'ordre**

| Tour | Résultat |
|---|---|
| Vague 1 (2 relecteurs) | 3 P1 corrigés |
| Vague 1, tour 2 | 0 P0/P1 |
| Vague 2 | 2 P1 corrigés |
| Vague 3 | 1 P1 corrigé |
| Convergence 1 | 3 P0/P1 corrigés (1 P0 d'argent, ancien et élargi) |
| Convergence 2 | **0 P0/P1** dans le code du GOAL (2 P1 d'argent dans du code plus ancien, corrigés) |
| Convergence 3 | **0 P0/P1** dans le code du GOAL (3 P1 plus anciens : 2 corrigés, 1 en porte W-6) |
| Convergence 4 | **0 P0/P1**. Panier = facturé dans 15 scénarios rejoués de bout en bout, de l'assistant gelé jusqu'au devis serveur |

Résultat : convergence atteinte sur le code du GOAL, avec 3 tours consécutifs sans P0 ni P1. Les écarts
restants relèvent de la zone gelée (W-6) ou de décisions du propriétaire.
