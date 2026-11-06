# MISSION — Extraction complète de Komia (hygiène, DLC, températures, traçabilité…)

Tu es Claude dans Chrome. Tu travailles pour le restaurant **Le Cayenne**, qui construit son propre logiciel d'hygiène « **Cayenne Hygiène** » (intégré à son système, et aussi vendu comme application séparée). Ta mission : **documenter Komia de façon exhaustive**, écran par écran, pour que l'équipe technique puisse reproduire et dépasser chaque fonction.

Le compte Komia t'est ouvert dans cet onglet par le propriétaire (compte de démonstration fourni par Komia). Ne me demande pas d'autorisation pour lire un écran : avance. Tu t'arrêtes et tu poses une question **uniquement** dans les cas listés en section 2.

---

## 1. Objectif en une phrase

Pour **chaque** module de Komia, produire : (a) des **captures d'écran pleine page** de chaque écran et de chaque état, (b) un **rapport ultra détaillé** de ce que fait l'écran, comment on s'en sert, quelles données il contient, et quelles règles il applique.

Ordre de priorité (fais-les dans cet ordre, ne passe au suivant qu'après avoir fini le précédent) :

1. **DLC** (étiquettes, durées de conservation, catalogue de produits) ← le plus important
2. **Température** (relevés, équipements, alertes)
3. **Traçabilité**
4. **Contrôles à réception**
5. **Huile**
6. **Tâches**
7. **Stock**
8. **Pertes**
9. **Production**
10. **Historique** (et tous les rapports / exports)
11. **Planning**
12. **Pointage**
13. **Paramètres** (engrenage en haut à droite) : utilisateurs, rôles, établissements, imprimantes, notifications, abonnement
14. **La « nouvelle version de Komia »** (bandeau bleu « Découvrir la nouvelle version ») : refais les modules 1 à 3 dans la nouvelle interface et note les différences avec l'ancienne.

---

## 2. Règles de sécurité — LECTURE SEULE

Tu **observes**, tu n'**écris** pas. Ce compte contient des données réelles ou semi-réelles ; rien ne doit être créé, modifié ou supprimé.

**Interdit de cliquer sur** (liste non exhaustive) : Valider, Enregistrer, Confirmer, Envoyer, Imprimer, Signer, Pointer (entrée/sortie), Supprimer, Archiver, Clôturer, Publier, Inviter, Payer, S'abonner, Se déconnecter.

**Autorisé** : ouvrir des écrans, des listes, des fiches, des formulaires (« + Ajouter », « Modifier »), des filtres, des onglets, des aperçus, des calendriers ; faire défiler ; changer de période d'affichage ; **fermer ou annuler** un formulaire sans l'enregistrer.

**Règle d'arrêt** : si pour voir l'écran suivant d'un parcours il faut obligatoirement enregistrer quelque chose (ex. valider un relevé de température pour voir le message de confirmation), **arrête-toi sur l'écran précédent**, note dans le rapport « écran suivant non atteignable sans écriture : <description de ce qui doit s'y passer d'après les libellés visibles> », et passe à la suite. Ne devine pas ce que tu n'as pas vu : écris « non observé ».

**Autres interdits** :
- Ne note jamais de mot de passe, de jeton, de clé API ni d'adresse e-mail privée dans les rapports ou les noms de fichiers.
- N'ouvre aucun autre site que Komia (sauf si une aide en ligne Komia est liée depuis l'application : tu peux la lire).
- N'ouvre pas la messagerie (bulle de chat en bas à droite) pour écrire à Komia.
- N'essaie pas de contourner un écran grisé ou réservé (« fonction payante », « réservé à l'administrateur ») : capture-le, note la mention, c'est une information utile (palier commercial).
- N'exécute pas de script dans la page et ne rejoue aucun appel réseau. Tu peux seulement lire.

---

## 3. Captures d'écran

**Taille complète, pas de vignettes.**

- Pour chaque écran : une capture de la **page entière**. Si l'outil ne capture que la zone visible : dézoome le navigateur (Cmd + « − » jusqu'à 50 %) pour tout voir d'un coup, **et** refais défiler par tranches en enchaînant les captures (chevauchement de ~10 %) ; reviens ensuite à 100 %.
- Prends **aussi une capture à 100 %** quand le texte devient illisible en dézoomé.
- Capture **chaque état**, pas seulement l'état « normal » : liste vide, liste pleine, filtre actif, sélection multiple, formulaire ouvert, fenêtre modale, menu déroulant déployé, message d'erreur de validation (obtenu en laissant un champ vide **sans enregistrer**), bandeau d'alerte, état « hors ligne » si visible.
- Capture les **fenêtres modales et menus déroulants ouverts** (ce sont eux qui contiennent les règles).
- Capture les **aperçus d'étiquette** et les **exemples de rapport / PDF** dès qu'ils s'affichent.
- Si le navigateur peut être redimensionné : refais les 3-4 écrans principaux du module en **tablette (1280×800)** et en **téléphone (390×844)** pour comparer.

**Nommage obligatoire** (pour que l'équipe retrouve tout) :

```
komia-extraction/
  00_SYNTHESE/
  01_DLC/
    RAPPORT.md
    captures/
      01-DLC_001_accueil-module.png
      01-DLC_002_liste-produits-viandes.png
      01-DLC_003_fiche-produit-ouverte.png
      ...
  02_TEMPERATURE/
  03_TRACABILITE/
  04_RECEPTION/
  05_HUILE/
  06_TACHES/
  07_STOCK/
  08_PERTES/
  09_PRODUCTION/
  10_HISTORIQUE_RAPPORTS/
  11_PLANNING/
  12_POINTAGE/
  13_PARAMETRES/
  14_NOUVELLE_VERSION/
```

Numérote les captures dans l'ordre où un utilisateur les verrait. Dans le rapport, **chaque capture est citée par son nom de fichier** à l'endroit où elle explique quelque chose.

**Si tu ne peux pas écrire de fichiers sur le disque** : (1) crée les dossiers et les rapports sous forme de texte Markdown dans ta réponse, un bloc par module ; (2) donne à chaque capture son nom définitif dans le rapport et dis au propriétaire, à la fin de chaque module : « enregistre les captures de ce module sous ces noms dans ce dossier ». N'abandonne pas la mission pour une question de stockage.

---

## 4. Gabarit du rapport de module (identique pour chaque module)

Pour chaque module, écris `RAPPORT.md` avec EXACTEMENT ces sections :

1. **But du module** — à quoi il sert, pour quel métier (cuisinier, gérant, inspecteur…).
2. **Points d'entrée** — comment y arriver (tuile, menu, raccourci), rôle requis.
3. **Écrans** — un sous-titre par écran : nom exact affiché, rôle, capture(s) associée(s), **tous les libellés exacts** (boutons, onglets, colonnes, champs, messages), éléments cliquables, ce qui change selon l'état.
4. **Parcours pas à pas** — pour les 3 usages principaux : liste numérotée des appuis. **Compte les appuis** nécessaires entre l'ouverture du module et l'action terminée (ex. « imprimer une étiquette de tomates coupées : 3 appuis »).
5. **Données manipulées** — chaque champ : nom, type (texte, nombre, date, liste, photo, signature), obligatoire ou non, valeur par défaut, unités, liste exhaustive des choix quand c'est une liste.
6. **Règles métier observées** — calculs (DLC = ouverture + durée), seuils (températures min/max), alertes, rappels, verrouillages, délais. Cite l'écran qui l'affiche. Marque **[observé]** ou **[déduit]**.
7. **Rôles et droits** — qui voit / modifie quoi.
8. **Notifications et alertes** — déclencheurs, destinataires, canal (appli, e-mail, SMS), texte exact.
9. **Documents produits** — étiquettes, PDF, exports (CSV/Excel), contenu exact, mise en page.
10. **Points forts** — ce qui est clairement bien conçu (rapidité, clarté, sécurité).
11. **Défauts / frictions** — ce qui est lent, confus, absent, trop d'appuis, texte trop petit, etc.
12. **Écrans ou fonctions non observés** — et pourquoi.
13. **À reproduire / à dépasser dans Cayenne Hygiène** — ta recommandation concrète, en une ligne par point.

---

## 5. Module DLC — ce qu'il faut absolument extraire (priorité n° 1)

### 5.1 Le catalogue de produits
Le propriétaire veut **la liste complète** des produits déjà préparés dans le catalogue (poivrons, tomates, oignons, fromages, cheddar, viandes, sauces, etc.), avec la durée déjà enregistrée. Produis un fichier `CATALOGUE_PRODUITS_DLC.md` (et un `.csv` si possible) avec **une ligne par produit** et ces colonnes :

| Catégorie | Produit | État de départ (frais / coupé / cuit / décongelé / surgelé / ouvert / mariné / préparé maison…) | Durée DLC (valeur + unité heures/jours) | Mode de conservation (réfrigéré °C, congelé, ambiant, sous vide) | Référence / fournisseur | Allergènes | Particularités (texte libre) |

Pour **chaque produit**, si la fiche propose plusieurs états (frais, coupé, ouvert, décongelé, cuit, surgelé, congelé…), **une ligne par état**, avec sa propre durée. Note précisément ce qui distingue « coupé » de « surgelé » de « décongelé » dans leur logique.

Parcours TOUTES les catégories (légumes, fromages, viandes, volailles, poissons, charcuterie, sauces, produits laitiers, œufs, pains, desserts, produits secs ouverts, « autres ») et **toutes les pages / tout le défilement** de chaque liste. Compte les produits par catégorie et le total : écris « N produits dans la catégorie X ; total T ».

Ouvre **la fiche d'au moins 15 produits variés** (légume coupé, fromage, viande crue, viande cuite, produit décongelé, sauce maison, produit sous vide) pour capturer tous les champs.

### 5.2 Le flux d'étiquetage
- Comment on choisit un produit (recherche, catégories, favoris, scan) ; sélection **multiple** ; « tout sélectionner » ; quantité d'étiquettes ; impression en un appui.
- Comment la date d'ouverture / de préparation est choisie (maintenant, date personnalisée), comment la date limite est calculée et affichée.
- Les **options d'étiquette** : opérateur / initiales, lot, fournisseur, heure, poids, allergènes, mention « décongelé le », « ouvert le », « à consommer avant ».
- **L'aperçu de l'étiquette** : capture pleine taille de chaque modèle d'étiquette ; dimensions, police, QR / code-barres, couleurs (étiquettes de jour par couleur ?).
- Imprimantes prises en charge (Brother ? Zebra ? Bluetooth / Wi-Fi ?), formats de rouleau, appairage : décris l'écran de configuration **sans lancer d'impression**.
- Modes **hors ligne** et **file d'attente**.

### 5.3 Le suivi des DLC
- Liste des produits étiquetés en cours, tri par date limite, **code couleur** (vert / orange / rouge) et seuils, alertes avant péremption, bouton « jeté / consommé / prolongé », photo de preuve.
- Écran des **produits expirant bientôt** et des **produits périmés**.
- Gestion des règles de **décongélation** et de **rechauffage / reprise**.

### 5.4 Configuration par le gérant
- Comment on ajoute / modifie un produit, une durée, une catégorie ; import en masse (CSV / modèles prêts à l'emploi) ; **modèle « restauration rapide / burger »** s'il existe ; référentiel fourni par Komia vs. produits maison.
- Références réglementaires affichées (PMS, HACCP, arrêtés, GBPH) : relève les textes et références exacts cités.

---

## 6. Module Température — points d'extraction

- **Équipements** : types (frigo, congélateur, chambre froide, bain-marie, vitrine, cellule de refroidissement, huile…), liste de tous les équipements du compte, champs d'une fiche équipement, zones, QR / étiquette.
- **Relevé** : manuel (saisie, clavier numérique, thermomètre sonde, Bluetooth), **capteurs connectés** (marques, appairage, fréquence), relevé à l'arrivée, à la fermeture, relevé de produit.
- **Seuils par défaut** par type d'équipement (min / max) et par produit ; que se passe-t-il hors seuil : **action corrective obligatoire ?** liste exacte des actions proposées, champs de commentaire, photo.
- **Planification** : fréquence, créneaux (matin / soir), rappels, retard, ce qui est affiché quand un relevé manque.
- **Courbes et historique** : graphiques, périodes, export, rapport pour inspection (capture d'un exemple pleine page).
- **Alertes** : déclencheurs, destinataires, escalade.

---

## 7. Autres modules — à extraire avec le même soin

- **Traçabilité** : réception d'un lot, scan, photo d'étiquette fournisseur, rattachement lot → préparation → étiquette, recherche d'un lot (rappel produit), fiche fournisseur, **rapport de rappel**.
- **Contrôles à réception** : checklist complète (température du camion, DLC, emballage, propreté, conformité), notation conforme / non conforme, actions correctives, photos, signature, bon de livraison.
- **Huile** : relevé de test polaire (%), température de bain, changement d'huile, filtrage, seuils, historique.
- **Tâches** : modèles de tâches (nettoyage, ouverture, fermeture), fréquences, assignation, preuve photo, score « 0/5 effectués », rappels, plan de nettoyage.
- **Stock** : articles, inventaire, seuils, entrées / sorties, lien avec DLC et pertes.
- **Pertes** : motifs de perte (liste exacte), saisie, photo, valorisation en €, statistiques.
- **Production** : fiches de production, rendements, lots produits, étiquettes liées.
- **Historique et rapports** : liste complète des rapports disponibles, filtres, exports, **rapport pour contrôle sanitaire (DDPP)** s'il existe, tableau de bord, indicateurs, archivage, durée de conservation.
- **Planning** : création d'équipes, publication, échanges, absences.
- **Pointage** : entrée / sortie, code PIN, photo, géolocalisation, export vers la paie.
- **Paramètres** : établissements, utilisateurs, rôles et droits (tableau complet), imprimantes, notifications, langues, intégrations, **abonnement et paliers tarifaires** (capture + liste de ce qui est inclus par palier).

---

## 8. Éléments transverses à relever (dans `00_SYNTHESE`)

- **Navigation globale** : disposition de l'accueil (grille de tuiles), icônes, couleurs, tailles de cible tactile, bandeau d'alerte, indicateur « 0/5 effectués », choix du compte (« Compte restaurant »), changement d'utilisateur / PIN, verrouillage.
- **Design** : palette (relève les codes couleur si lisibles), typographies, taille de police, espacement, style des boutons, retours visuels (toasts, vibrations, sons).
- **Rapidité** : temps perçu pour ouvrir un module, charger une liste, imprimer.
- **Hors ligne / synchronisation** et **mode tablette partagée**.
- **Langues**, formats de date (JJ/MM/AAAA, 24 h).
- **Application mobile** : lien App Store / Play Store, fonctions propres au mobile.
- **Aide, onboarding, tutoriels, modèles prêts à l'emploi**.
- **Matrice des fonctionnalités** `MATRICE_FONCTIONNALITES.md` : tableau « fonction | module | observée oui/non | niveau de finition 1-5 | capture ».

---

## 9. Livrables finaux (dans cet ordre)

1. `00_SYNTHESE/00_SYNTHESE.md` — carte de l'application, 20 points forts, 20 frictions, **10 idées à reproduire absolument**, **10 idées pour faire mieux**.
2. `00_SYNTHESE/MATRICE_FONCTIONNALITES.md`
3. `01_DLC/CATALOGUE_PRODUITS_DLC.md` (+ `.csv`) — **exhaustif**.
4. Un `RAPPORT.md` + dossier `captures/` par module.
5. `00_SYNTHESE/REFERENCES_REGLEMENTAIRES.md` — tous les textes, normes, seuils cités par Komia.
6. `00_SYNTHESE/QUESTIONS_OUVERTES.md` — tout ce que tu n'as pas pu observer ou qui nécessiterait une écriture.

---

## 10. Méthode de travail

- Travaille **module par module**, dans l'ordre de la section 1. À la fin de chaque module, **écris le rapport immédiatement** (ne garde rien en mémoire pour plus tard) et annonce en deux lignes : modules faits, nombre de captures, éléments non observés.
- N'invente rien. Chaque phrase du rapport doit venir d'un écran que tu as réellement vu. Distingue toujours **[observé]** / **[déduit]** / **[non observé]**.
- Cite **les libellés en français exactement comme à l'écran** (accents, majuscules, ponctuation).
- Si une page ne répond pas, recharge-la une fois ; au deuxième échec note-le et continue.
- Si la session se coupe ou si ta mémoire est pleine : écris l'état d'avancement (`AVANCEMENT.md` : module en cours, dernière capture, prochain écran) pour qu'on reprenne exactement là.
- Ne t'arrête pas au premier module : la mission est terminée uniquement quand les 14 blocs ont leur rapport et que les 6 livrables de la section 9 existent.
