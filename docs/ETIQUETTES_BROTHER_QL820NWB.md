# Étiquettes — Brother QL‑820NWB (DLC / hygiène + étiquettes par produit de commande)

> Mis en service 2026‑10‑16. Domaine neuf, additif, **hors NF525**. Plan et décisions : `plans/PLAN_LABELS-BROTHER-QL820NWB_2026-10-16.md`.

## Ce que ça fait

| Écran (menu **Étiquettes**) | URL | Usage |
|---|---|---|
| **Étiquettes commandes** | `/admin/labels/orders` | Les commandes en cours (mêmes que l'écran cuisine). Pour chaque **produit** : une étiquette avec **N° de commande**, **nom**, personnalisation **en clair** (pain, viande, sauces, crudités, menu ou seul, sauce frites, suppléments, notes, allergènes) et **k/N**. Boutons *Imprimer*, *Réimprimer*, *Tout imprimer*, *Aperçu*, et l'interrupteur **Impression automatique**. |
| **Étiquettes DLC / hygiène** | `/admin/labels/dlc` | Vos fiches produits (oignons, tomates, olives, champignons…), configurées comme dans les logiciels HACCP du marché (voir *Étiquettes DLC* ci‑dessous). **Un appui = une étiquette** ; **⋯** pour choisir l'événement ; **Sélection multiple / Tout sélectionner** pour tout imprimer d'un coup. Dates calculées par le serveur, journalisées. |
| **Imprimante d'étiquettes** | `/admin/labels/printer` | Connexion Bluetooth, état en direct, choix du rouleau, étiquette test, diagnostic de la tablette, mode d'emploi. |

## Étiquettes DLC (hygiène) — au niveau des logiciels du marché

Références : GBPH restauration § 2.6 *Décongélation* et § 2.7 *Gestion des entamés* (guide PMS), pratiques Octopus HACCP / ePack / Traqfood.

**Fiche produit** (bouton *＋ Nouveau produit* ou *Modifier*) :
- **Famille** (onglets et sélection par famille), **mention** libre, **14 allergènes** imprimés.
- **Événements**, chacun avec **sa** durée : *Ouverture*, *Décongélation*, *Préparation*, *Cuisson*, *Mise sous vide*. L'un est **« Par défaut »** : c'est lui qu'imprime un appui. Les durées et températures sont celles **de votre PMS** (ou du fabricant), jamais les nôtres : l'application signale seulement les valeurs inhabituelles.
- **Conservation obligatoire** (imprimée : « À conserver entre 0 et +4 °C »…).
- **Conservation après décongélation** : obligatoire dès que la fiche prévoit la décongélation, choisie produit par produit (viande hachée et poisson **0 à +2 °C**, autres 0 à +4 °C, pain **ambiante** — arrêté du 21/12/2009, annexe I). **Aucune valeur de repli** : une fiche qui ne la dit pas ne s'imprime pas en décongélation (« à compléter »). Le congélateur y est refusé ; le froid positif est reconnu par une liste blanche stricte (aucune valeur négative, ≤ +8 °C).
- **Durée inhabituelle** (viande/poisson décongelés > 24 h, décongelé > 3 jours, froid positif > 7 jours, « 96 heures » = erreur d'unité probable) : signalée, à confirmer (« Durées vérifiées » — nom et heure gardés sur la fiche) avant d'enregistrer.
- **Fait à partir d'un produit déjà étiqueté** (marinade, sauce…) : chaque impression demande le produit d'origine ou « produit neuf » (déclaration journalisée).
- **Demander la DLC du fabricant** (cochée par défaut pour un produit *ouvert / sous vide*) : la DLC imprimée ne la dépasse **jamais** ; une DLC fabricant dépassée est **refusée**. Le **lot du fabricant** peut être recopié. La date n'est **jamais pré-remplie** (un nouvel emballage se lit) : « Même emballage » reprend la dernière sur un choix explicite.

**Imprimer** : un appui = l'étiquette tout de suite (les appuis rapides sur plusieurs produits **attendent leur tour**, rien n'est perdu ; un rebond du doigt < 0,45 s ne double pas). **⋯** : choisir l'événement, le nombre, la DLC/lot du fabricant, ou **« Fait à partir d'un produit déjà étiqueté ? »** (ex. poulet décongelé → poulet mariné) : la DLC **suit celle du produit d'origine** (sauf *Cuisson*, traitement assainissant — le produit d'origine doit encore être bon ; **une seule remise en température** : recuire un produit déjà cuit garde sa DLC). **Sélection multiple** : seuls les produits imprimables en lot y entrent ; ceux qui exigent la DLC du fabricant ou n'ont pas l'événement choisi sont marqués **« À part »** — un événement n'est **jamais remplacé en silence** par un autre (le serveur refuse, `unknown_action`).

**L'étiquette** (62 mm) : NOM · famille/mention · **NE PAS RECONGELER** (décongélation) · *Entamé le / Mis en décongélation le / Préparé le / Cuit le / Mis sous vide le* + date · **pastille du jour** + **À CONSOMMER JUSQU'AU** date heure (pastille omise au‑delà de 6 jours) · *Limitée à la DLC du fabricant / du produit d'origine* · **ALLERGÈNES** · conservation · DLC/lot fabricant, *Issu de …* · **N° L…** (numéro d'étiquette, journal) · *Par Prénom*.

**À surveiller** : tous les lots non sortis, à échoir sous 72 h ou échus depuis moins de 30 jours, plus les étiquettes hors ligne « à vérifier » quelle que soit leur DLC (requête dédiée `GET admin/labels/watch`, sans limite de 50 lignes) ; l'écran laissé ouvert se met à jour seul (30 s / 3 min). Chaque lot se sort par **Jeté ✓** ou **Utilisé** (raison et auteur journalisés, *Annuler* 8 s) ; une étiquette sortie par erreur : **Annuler cette impression** (la ligne reste, marquée *annulée*).

**Réseau coupé** : l'étiquette sort quand même (heure du serveur connue, mention *Hors ligne · réf. XXXXXX*) et sa ligne de journal part **dès le retour du réseau** (file locale gardée 7 jours, rejouée aussi après un jeton expiré ou une limite de débit ; `client_ref` : jamais de doublon). Le serveur l'inscrit **toujours** ; ce qu'il n'aurait pas imprimé (DLC plus longue que la fiche, événement retiré…) est signalé **« à vérifier »** tout de suite. Un bandeau dit combien d'étiquettes attendent le journal ; elles figurent dans « À surveiller » dès l'approche de leur DLC (72 h). Une étiquette réservée dont le verdict n'est jamais arrivé y figure aussi, « impression non confirmée », dès que 10 minutes sont passées ET que sa DLC tombe dans la fenêtre surveillée (72 h). Impossible hors ligne : DLC du fabricant à vérifier, produit d'origine à vérifier.

## Application Android native (recommandée) — « comme Komia »

Chrome n'arrive pas toujours à voir la Brother en Bluetooth. L'application **Le Cayenne Étiquettes** (`native/labels-android/`,
voir son README) ouvre une vraie prise **Bluetooth (profil série)** ou **Wi‑Fi (adresse IP, port 9100)** vers l'imprimante et
charge le même écran *Étiquettes*. Le choix de l'imprimante est une fenêtre native, mémorisée (reconnexion automatique). Le
protocole d'impression est identique à celui de Chrome. Installation : copier `LeCayenne-Etiquettes.apk` sur la tablette.

## Bluetooth ou Wi‑Fi ?

**Bluetooth** (recommandé, c'est ce que fait l'application) : Chrome de la tablette ↔ imprimante en direct (Web Serial). Impression **silencieuse**, **automatique** (commandes), **état lu** (capot, rouleau, confirmation par page).
**Wi‑Fi** : une page web ne peut pas ouvrir le port réseau de l'imprimante (TCP 9100) et le serveur, dans le cloud, ne voit pas le réseau du restaurant. Le Wi‑Fi ne sert donc que par la **boîte d'impression Android** (*Imprimer via Android*, plugin *Brother Print Service*) : utile en **secours**, mais un appui de plus à chaque fois, **pas d'automatique**, **pas d'état**. Laisser le Wi‑Fi de l'imprimante activé ne gêne pas.

## Mise en service (une seule fois)

1. **Imprimante** (écran tactile) : `Menu › Bluetooth › Bluetooth (On/Off) › On`, puis `Reconnexion automatique › On`.
   `Menu › Réglages › Arrêt automatique › Adaptateur › Off` — **indispensable** : sinon l'imprimante s'éteint seule et la tablette la perd.
2. **Tablette Android** : `Réglages › Bluetooth › QL‑820NWB…`. Si un code s'affiche, il doit être identique sur l'imprimante.
3. **Chrome** (version **138 ou plus**, pas Samsung Internet / Edge) : ouvrir **Étiquettes › Imprimante d'étiquettes**, appuyer sur **Connecter l'imprimante**, la choisir. Elle reste connectée ; la tablette la retrouve seule après une coupure.
4. Choisir le **rouleau** posé (62 mm continu recommandé), puis **Imprimer une étiquette test**.
5. **Contrôler le sens** sur le papier : le **G** à gauche, le **D** à droite, les accents lisibles. Si le texte est inversé comme dans un miroir : *Réglages avancés › décocher « Sens miroir »*. Si l'étiquette sort à l'envers : *cocher « Retourner de 180° »*.

> Une imprimante Bluetooth n'accepte qu'**un seul appareil à la fois** : déconnectez l'application Brother d'un téléphone avant de connecter la tablette.

## Comment ça marche (pour qui maintient le code)

- **Le serveur n'imprime jamais.** Laravel est dans le cloud, l'imprimante est en Bluetooth à côté de la tablette : c'est le **Chrome de la tablette** qui écrit sur le port série Bluetooth (Web Serial, profil SPP/RFCOMM — Chrome Android ≥ 138). Même technique que l'afficheur client (`posCustomerDisplay.js`).
- **Protocole** : raster Brother QL‑800/810W/820NWB, sans compression (`resources/js/helpers/brotherQl.js`, chaque octet référencé au *Raster Command Reference v1.01*). 90 octets par ligne, rouleau 62 mm = 12 / 696 / 12 broches. L'état (32 octets) est lu avant l'envoi (capot, rouleau, mauvais format) et après (confirmation par page).
- **Rendu** : canvas navigateur → bitmap 1 bit (`labelLayout.js` mise en page pure, `labelRender.js` dessin). **L'aperçu affiché EST l'image envoyée.** Jamais de troncature silencieuse : une étiquette qui ne tient pas dans un rouleau de hauteur fixe est refusée.
- **Composition en clair** (`orderLabelBuilder.js`) : réutilise les helpers de l'écran cuisine (`collapseBundledAddonItems`, lecture des sauces/viandes en plus, sauce frites, boissons de formule…) — aucune règle n'est réécrite. Boissons vendues seules : **sans étiquette par défaut** (réglable) ; la boisson d'un menu est écrite sur l'étiquette du menu.
- **Serveur** (`Admin\LabelController`, routes `admin/labels/*`, permission `pos-orders|pos|kitchen-display-system`) : catalogue DLC, **horloge unique** (dates d'ouverture/DLC), **journal d'hygiène** (`label_print_logs`), **verrou d'impression automatique** (`claim_key` unique : deux tablettes n'impriment jamais deux fois la même commande).
- **Automatique** : sondage de la liste toutes les 5 s ; n'imprime que les commandes **arrivées après l'activation**, seulement si l'imprimante est prête ; après un échec *sans* étiquette sortie, retente au plus 3 fois (≥ 30 s d'écart) ; après des étiquettes sorties, **jamais seul** (pas de doublon) — il faut *Réimprimer*.
- **Secours** : *Imprimer via Android* ouvre la boîte d'impression du système (plugin Brother) si Web Serial n'est pas disponible.

## Dépannage

| Symptôme | Cause probable | Action |
|---|---|---|
| « Impression Bluetooth indisponible sur ce navigateur » | Pas Chrome, Chrome < 138, ou page non HTTPS | Voir le *Diagnostic* de la page Imprimante ; mettre Chrome à jour. |
| « Connecter » ne liste aucune imprimante | Non appairée dans Android, ou connectée à un autre appareil | Appairer dans Réglages Android › Bluetooth ; déconnecter le téléphone. |
| « Imprimante injoignable… » | Éteinte, hors de portée, Arrêt automatique actif | Rallumer ; régler *Arrêt automatique › Off*. |
| « Capot ouvert » / « Aucun rouleau » / « Fin de rouleau » | Matériel | Corriger ; l'état se met à jour seul (≤ 8 s). |
| « Rouleau chargé : … — format réglé : … » | Format réglé ≠ rouleau posé | Changer le format dans *Imprimante*. |
| « Envoyé, non confirmé » | L'imprimante n'a pas confirmé la fin | Vérifier que les étiquettes sont sorties **avant** de relancer. |
| Texte en miroir / à l'envers | Sens des données | *Réglages avancés* (voir mise en service, point 5). |

## Vérification automatisée

```
npx vitest run tests/js/brotherQl.spec.js tests/js/brotherQlRoundTrip.spec.js tests/js/labelLayout.spec.js \
  tests/js/labelPrinter.spec.js tests/js/orderLabelBuilder.spec.js tests/js/labelPrinterMixin.spec.js tests/js/labelDlcScreen.spec.js
php vendor/bin/phpunit tests/Feature/Labels/LabelControllerTest.php
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
  npx playwright test tests/Playwright/etiquettes-brother-2026-10-16.spec.js --workers=1
```

Le test Playwright joue une **fausse Brother** (Web Serial simulé) qui enregistre les octets, répond à l'état et confirme les pages ; un **décodeur indépendant** écrit d'après le manuel reconstruit l'image et la compare, point par point, à l'aperçu. Il prouve la chaîne complète **hors papier** : le sens physique et l'appairage Android se vérifient sur la machine (étiquette test).

## Limites connues

- Hors ligne, une étiquette DLC s'imprime avec l'heure du serveur connue au dernier échange ; sa ligne de journal part au retour du réseau (file locale gardée 7 jours). Le papier porte « Hors ligne · réf. … » au lieu du N°.
- Une préparation ne nomme qu'**un** produit d'origine (le plus périssable est à choisir) ; les produits « fait à partir de… » s'impriment un par un (pas en sélection multiple).
- *Par Prénom* imprime le prénom du **compte connecté** : sur un compte partagé, il ne désigne personne (prévoir un compte par personne, ou ne pas cocher l'option).
- Une fiche sans allergène coché n'imprime **aucune** ligne d'allergènes (« non renseigné » et « aucun » ne se distinguent pas sur le papier).
- Tablette fermée en pleine impression automatique : la réclamation est rendue à la file après 120 s ; en théorie un doublon est possible si l'impression avait en fait abouti.
- Le chef (permission `kitchen-display-system` seule) est admis par l'API mais pas par la route de l'écran (`pos-orders`), comme l'écran Uber photo.
