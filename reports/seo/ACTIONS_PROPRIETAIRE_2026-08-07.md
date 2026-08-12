# Référencement Le Cayenne — ce que le code ne peut pas faire

**Date : 7 août 2026** · Destinataire : propriétaire · Établissement : Le Cayenne,
437 Rue Élie Gruyelle, 62110 Hénin-Beaumont

---

## L'essentiel en cinq lignes

J'ai refait tout ce qui relevait du site : il était **invisible pour Google** (une seule
page indexable, aucune description, aucune donnée structurée, pas de `robots.txt`). C'est
corrigé, avec sept pages de contenu réelles.

**Mais le site n'est pas le premier levier.** Pour un restaurant local, la hiérarchie
mesurée place la **fiche Google Business Profile** loin devant tout le reste. Les trois
actions ci-dessous valent, à elles seules, plus que l'ensemble de mon travail sur le code.
Elles ne peuvent être faites que par vous : elles demandent un accès propriétaire.

---

## 🔴🔴 PRIORITÉ ZÉRO — Vous êtes en concurrence avec vous-même, à votre propre adresse

*(ajouté le 8 août 2026 — c'est la découverte la plus importante de tout ce dossier,
et elle change l'ordre de tout le reste)*

Ce n'est pas seulement un point OpenStreetMap. **L'ancienne enseigne est encore
pleinement vivante, sur trois plans à la fois.**

**Vérifié par moi, au registre officiel des entreprises (API de l'État) :**

| Société | SIRET | Créée | État |
|---|---|---|---|
| **E.MAX FOOD — « LE GRILL HOUSE »** | 90199256000010 | 02/08/2021 | **ACTIVE, aucune date de fermeture** |
| **E.DELICE — « LE CAYENNE »** | 10417050100019 | 27/04/2026 | ACTIVE |

Les deux sont **immatriculées au 437 rue Élie Gruyelle**.

**Et son site est en ligne.** Vérifié par requête directe :

- `https://www.legrillhouse.fr/` → **HTTP 200**, 60 696 octets
- Titre de la page : « **Fast-food à Hénin-Beaumont** »
- Adresse affichée : **437 rue Élie Gruyelle – 62110 Hénin-Beaumont**
- Téléphone affiché : **09 81 41 06 79** — *ni le 03 65 67 82 91, ni le 03 21 74 81 10*
- Horaires affichés : 11h30–14h et 18h–23h — *différents des vôtres*
- Douze pages indexables : `/nos-tacos`, `/nos-burgers`, `/nos-sandwichs-paninis`…
- `robots.txt` tout ouvert, `sitemap.xml` présent

### Pourquoi c'est le problème n°1, avant tout le reste

1. **Votre concurrent le mieux placé sur vos requêtes, c'est vous.** Le Grill House
   ressort encore en 9ᵉ position sur « burger hénin-beaumont » et 6ᵉ sur « fast food
   hénin-beaumont ». Son titre cible littéralement votre marché. Il a **cinq ans
   d'ancienneté de domaine ; vous en avez six semaines.**
2. **Ça explique le mauvais numéro dans les IA.** On a mesuré que 30 % des numéros
   donnés par ChatGPT pour un commerce local sont faux. Ici, il y a littéralement
   **trois numéros publiés pour une seule adresse** — le vôtre, celui du Grill House
   en ligne, et celui des annuaires. Un moteur ne peut pas deviner.
3. **Votre page Facebook porte encore l'ancienne identité** : elle affiche « Le Cayenne
   | Hénin-Beaumont » mais son adresse est
   `facebook.com/p/Le-Grill-House-61558444376048/`. La page a été **renommée, pas
   recréée** — tout l'historique d'entité pointe vers l'ancienne enseigne.

### Ce qu'il faut faire, dans cet ordre

1. **Rediriger `legrillhouse.fr` en 301 vers `lecayenne.fr`.** C'est l'action la plus
   rentable de tout ce document : elle transfère cinq ans d'ancienneté au lieu de la
   laisser vous concurrencer. Si vous ne contrôlez plus ce domaine, dites-le-moi —
   la stratégie change.
2. **Faire radier le SIRET E.MAX FOOD** s'il ne sert plus.
3. **Fermer ou fusionner l'ancienne fiche Google**, surtout ne pas en créer une
   troisième.
4. **Corriger le point OpenStreetMap** (nœud `12194119215`).

> **Tant que ce nœud n'est pas défait, chaque page écrite sert à arriver deuxième
> derrière vous-même.** Le site est aujourd'hui meilleur que tous ceux de la ville —
> il ne perd pas par manque de contenu, il perd parce qu'un fantôme de cinq ans occupe
> son adresse.

---

## 🔴 PRIORITÉ 1 — Un problème d'identité, confirmé par trois sources

**Ce que j'ai vérifié moi-même** : OpenStreetMap contient un point « restauration rapide »
nommé **« Le Grill House »** à **3 mètres** des coordonnées exactes du restaurant
(nœud `12194119215`, sans téléphone, sans site, sans horaires).

C'est la troisième source indépendante — après le registre national des entreprises et
l'index Google — qui rattache encore le 437 Rue Élie Gruyelle à l'**ancienne enseigne**.

**Pourquoi c'est grave.** OpenStreetMap alimente **Apple Plans, Bing, Facebook, Uber,
Komoot** et la plupart des applications qui ne sont pas Google. Aujourd'hui, un client
qui cherche votre adresse sur un iPhone ne trouve pas « Le Cayenne ».

**Ce qu'il faut faire, dans cet ordre :**

1. **Répondre à une question d'abord** : Le Cayenne occupe-t-il bien le local de l'ancien
   « Le Grill House » ? La réponse change tout le reste.
2. **Si oui** : il faut **renommer la fiche Google existante**, surtout pas en créer une
   seconde. Deux fiches sur une même adresse déclenchent une suspension.
3. **Corriger OpenStreetMap** : créer un compte sur openstreetmap.org, ouvrir le nœud
   `12194119215`, remplacer le nom, ajouter téléphone, site, horaires et cuisine.
   ⚠️ *Je ne l'ai pas fait moi-même : modifier une base publique au nom de votre
   établissement est une action qui vous engage, pas une action technique.*
4. **Vérifier Apple Plans** séparément, via *Apple Business Connect* (gratuit).

---

## 🔴 PRIORITÉ 1 bis — Être cité par ChatGPT AVEC LE BON NUMÉRO

*(ajouté le 8 août 2026, après recherche sur les moteurs de réponse)*

J'ai fait tout ce que le code permet pour que ChatGPT, Perplexity et Gemini
puissent lire votre site : leurs robots sont nommés et autorisés, la page d'accueil
leur parle enfin (elle était vide pour eux), et vos URL ont été soumises à l'index
de Bing — que **OpenAI désigne officiellement comme partenaire de recherche de
ChatGPT**.

**Mais voici le chiffre qui doit vous décider.** Sur 10 000 commerces mesurés,
**30,1 % des numéros de téléphone que ChatGPT donne sont faux** — 38,8 % chez
Perplexity. Et sur plus de 13 000 requêtes visant des entreprises européennes,
**93 % avaient au moins un fait de base halluciné ou manquant.**

Autrement dit : le danger n'est pas d'être invisible dans l'IA. **C'est d'y être
avec le numéro de l'ancienne enseigne.** Et c'est exactement votre situation —
voir la priorité 2 ci-dessous, deux numéros circulent pour votre adresse.

**Ce qui compte, dans l'ordre :**

1. **Un seul numéro, partout, à l'identique.** C'est devenu la priorité la plus
   haute de tout ce dossier, devant le contenu.
2. **La fiche PagesJaunes.** Depuis le **22 juin 2026**, l'application PagesJaunes
   est accessible **directement dans ChatGPT** — c'est aujourd'hui le seul canal
   français identifié qui y aboutit. Si votre fiche y est fausse ou absente,
   c'est cette information-là qui remontera. *(Nuance honnête : l'utilisateur doit
   sélectionner l'application ; ce n'est pas une source ambiante de ChatGPT.)*
3. **Ne perdez pas de temps sur Yelp** : `yelp.fr` bloque tous les robots
   (`Disallow: /`) et l'antenne France a été fermée. Rayé du plan.

**Un point qui rassure, et qui évite de mal investir** : une recherche à intention
purement locale (« snack Hénin-Beaumont », « restaurant près de moi ») ne déclenche
un aperçu IA que **15 % du temps**, contre 92 % pour une question informationnelle.
Sur ces requêtes-là, **c'est le pack local Google qui décide** — pas l'IA. Votre
exposition à l'IA se joue sur les formulations de découverte (« où manger un tacos
ce soir à Hénin-Beaumont »), que le site couvre déjà avec ses 36 questions-réponses.

**Conclusion : la fiche Google reste le levier n°1, et l'exactitude du téléphone
vient juste derrière.** Le site, lui, a fait sa part.

---

## 🔴 PRIORITÉ 2 — Deux numéros de téléphone circulent

Le site affiche **03 65 67 82 91**. Les annuaires rattachent encore le **03 21 74 81 10**
à cette adresse.

Un numéro incohérent d'une source à l'autre nuit deux fois : Google y perd en confiance,
et **68 % des clients cessent d'utiliser un commerce après être tombés sur une information
fausse** (BrightLocal). Il faut **un seul numéro**, identique partout : fiche Google, site,
Uber Eats, Pages Jaunes, Facebook, tickets de caisse, vitrine.

**À décider par vous** : lequel des deux est le bon, et faire corriger l'autre partout.

---

## 🔴 PRIORITÉ 3 — La fiche Google, dans l'ordre d'impact

L'étude de référence 2026 (Whitespark, 47 experts, 187 facteurs classés) donne cet ordre.
Les cinq premiers points se règlent en une soirée.

| Rang | Facteur | Ce qu'il faut faire |
|---:|---|---|
| **1** | **Catégorie principale** | Le facteur le plus lourd de tous, et celui qui prend dix secondes. Une seule catégorie principale, la plus précise possible. Les catégories secondaires viennent après, sans diluer. |
| 2 | Proximité | Non actionnable — mais c'est pour ça que l'adresse exacte doit être parfaite. |
| 3 | Mots-clés dans le titre | ⚠️ **Piège** : le nom de la fiche doit être **l'enseigne réelle**, « Le Cayenne ». Y ajouter « tacos burger Hénin-Beaumont » expose à une suspension dure, qui retire la fiche de Google et de Maps pendant **1 à 6 semaines**. Pour un établissement récent, c'est un risque inacceptable. |
| 4 | Adresse dans la ville recherchée | Déjà bon. |
| **5** | **Ouvert au moment de la recherche** | **C'est votre avantage structurel.** Beaucoup d'établissements du secteur ferment entre 22h et 23h ; vous servez jusqu'à minuit, sept soirs sur sept. Google privilégie ce qui est ouvert quand on cherche — à condition que les horaires de la fiche soient **exacts**. |
| 6 | Note Google | Voir priorité 4. |
| 9 | Nombre d'avis | Voir priorité 4. |

**Deux détails qui coûtent cher si on les oublie :**

- **Les jours fériés.** Si vous ne saisissez pas d'horaire pour un jour férié référencé,
  Google affiche **automatiquement un avertissement** sur votre fiche indiquant que les
  horaires « peuvent être différents ». Autrement dit : Google dégrade lui-même la
  confiance dans votre fiche les jours de plus gros chiffre d'affaires. À anticiper.
- **Le délai de propagation** d'une modification va de dix minutes à trente jours.
  Ne modifiez pas la veille d'un pic.

**À compléter aussi** : photos (extérieur de nuit, intérieur, chaque produit signature),
attributs (à emporter, commande en ligne, accessibilité, moyens de paiement), lien vers
`https://www.lecayenne.fr/carte.html` dans le champ menu, et un lien de commande.

---

## 🟠 PRIORITÉ 4 — Les avis

Rang 6 et 9 dans le classement des facteurs. Le levier est simple : **demander**, à chaque
retrait, une fois la commande donnée. Un QR code sur le ticket ou au comptoir suffit.

⚠️ **Ligne rouge absolue.** En France, publier ou faire publier de faux avis relève des
**pratiques commerciales trompeuses** — sanctions jusqu'à cinq ans et 750 000 €, portées
à 10 % du chiffre d'affaires moyen, **sans qu'il soit nécessaire de prouver l'intention**.
La DGCCRF a contrôlé 397 établissements en 2024, près d'un tiers en anomalie. On ne
touche pas à ça. On demande, simplement, aux vrais clients.

Répondre à tous les avis, y compris les mauvais, compte aussi.

---

## 🔴 HORS SUJET MAIS URGENT — un allergène réglementé n'est pas déclaré

Trouvé en vérifiant les textes des nouvelles pages. **Ce n'est pas du référencement, c'est
de la sécurité alimentaire** — je le signale sans y toucher, parce que les données
allergènes engagent le restaurant, pas moi.

**Le Fish Burger ne déclare pas le poisson.**

- `data/menu.js:505-507` : le produit est décrit « Poisson pané · Cheddar · Salade · Tomate
  · Oignon · Sauce », mais il ne porte **aucune** liste d'allergènes propre. Il retombe donc
  sur la valeur par défaut de sa catégorie (`defaultAllergensFor`, ligne 347), qui vaut
  **`['gluten']`** — et rien d'autre.
- Le mot `poisson` n'apparaît **nulle part** dans les allergènes du catalogue (0 occurrence).
- Pourtant `legal/allergens.html:79` liste bien « **Poissons et produits dérivés** » parmi
  les 14 allergènes réglementaires du règlement INCO (UE) n° 1169/2011.

**Conséquence concrète** : un client allergique qui ouvre la fiche du Fish Burger sur le
site lit « Gluten » et rien de plus.

**Le correctif** tient en une ligne — ajouter `allergens: ['gluten', 'poisson']` à
l'item 404 — mais il doit être **décidé et vérifié par vous**, et probablement répercuté
côté caisse et borne pour que les trois surfaces disent la même chose. Je ne modifie pas
seul une donnée d'allergène.

*(Mes pages, elles, annoncent correctement que le Fish Burger contient du poisson.)*

---

## 🟠 UNE QUESTION QUI VOUS ATTEND DEPUIS UN MOMENT — l'heure de fermeture

Le code porte une note explicite, laissée par une session précédente
(`screens.jsx:105-106`) : *« L'heure réelle de dernière commande est un FAIT OWNER :
handoff émis pour trancher entre "publier 18h — 01h" et "fermer à minuit". »*

En l'état, il y a une contradiction :
- le site **publie** partout « 18h — 00h » ;
- mais le code **accepte les commandes jusqu'à 00h59** (`screens.jsx:94`).

J'ai aligné les nouvelles pages sur ce qui est **publié** (minuit) — je ne pouvais pas
trancher à votre place. Mais tant que ce n'est pas décidé, Google affichera « Fermé » dès
minuit alors que la cuisine prend encore des commandes. Et rappelez-vous que « établissement
ouvert au moment de la recherche » est le **5ᵉ facteur de classement local** : c'est
exactement l'heure où vous êtes le plus seul ouvert dans le secteur.

**Deux réponses possibles, une seule à choisir** : soit on publie 18h — 01h partout (site,
fiche Google, vitrine), soit on ferme réellement la prise de commande à minuit.

---

## 🟡 PRIORITÉ 5 — Ce que je peux faire ensuite dans le code

Par ordre de rendement, une fois les priorités 1 à 4 lancées :

1. **Déployer** ce qui vient d'être fait (voir le rapport technique).
2. **Déclarer le site à la Google Search Console** et y soumettre le plan du site.
   C'est le seul moyen de savoir ce que Google fait réellement de vos pages — et
   la seule source honnête de volumes de recherche pour votre zone.
3. **Corriger le « faux 200 »** : aujourd'hui, n'importe quelle adresse inventée sur le
   site répond « page trouvée » et renvoie l'application. Google peut indexer des URL
   parasites à l'infini.
4. **Photos** : les vôtres sont bonnes ; il en manque une de la **devanture de nuit**,
   qui sert à la fois la fiche Google et le partage social.

---

## Ce que j'ai vérifié, et ce que je n'ai pas pu vérifier

**Vérifié par moi, avec preuve :**
- Coordonnées GPS de l'adresse (API officielle Base Adresse Nationale, score 0,97)
- Le nœud OpenStreetMap « Le Grill House » à 3 m
- L'arrêt de bus **« Gruyelle » à 15 m** de la porte, « Prévert » à 152 m, « Rimbaud » à 245 m
- La gare d'Hénin-Beaumont à 541 m à vol d'oiseau (≈ 9 min à pied)
- Le Cinéville à 2,2 km

**Pas vérifiable sans vos accès :**
- L'état réel de la fiche Google Business Profile du 437
- Le classement actuel dans le pack local depuis une adresse IP française
- Les volumes de recherche réels (seule la Search Console les donnera)

**Une remarque hors sujet mais qui me semble importante à signaler.** Hénin-Beaumont
compte plus d'emplois que d'actifs résidents (indice 127,9, INSEE) : il entre chaque jour
plus de travailleurs qu'il n'en sort. Avec un service qui commence à 18h, **toute cette
clientèle du midi est hors de portée**. Ce n'est pas une question de référencement, c'est
une question de vous — mais aucun travail sur Google ne compensera une fermeture au
moment où la demande existe.
