# Étiquettes — Brother QL‑820NWB (DLC / hygiène + étiquettes par produit de commande)

> Mis en service 2026‑10‑16. Domaine neuf, additif, **hors NF525**. Plan et décisions : `plans/PLAN_LABELS-BROTHER-QL820NWB_2026-10-16.md`.

## Ce que ça fait

| Écran (menu **Étiquettes**) | URL | Usage |
|---|---|---|
| **Étiquettes commandes** | `/admin/labels/orders` | Les commandes en cours (mêmes que l'écran cuisine). Pour chaque **produit** : une étiquette avec **N° de commande**, **nom**, personnalisation **en clair** (pain, viande, sauces, crudités, menu ou seul, sauce frites, suppléments, notes, allergènes) et **k/N**. Boutons *Imprimer*, *Réimprimer*, *Tout imprimer*, *Aperçu*, et l'interrupteur **Impression automatique**. |
| **Étiquettes DLC / hygiène** | `/admin/labels/dlc` | La liste de vos produits (oignons, tomates, olives, champignons…) avec leur durée de conservation. **Un appui = une étiquette** : produit, *Ouvert le*, **À consommer avant** (calculés par le serveur, journalisés). |
| **Imprimante d'étiquettes** | `/admin/labels/printer` | Connexion Bluetooth, état en direct, choix du rouleau, étiquette test, diagnostic de la tablette, mode d'emploi. |

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
  tests/js/labelPrinter.spec.js tests/js/orderLabelBuilder.spec.js tests/js/labelPrinterMixin.spec.js
php vendor/bin/phpunit tests/Feature/Labels/LabelControllerTest.php
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
  npx playwright test tests/Playwright/etiquettes-brother-2026-10-16.spec.js --workers=1
```

Le test Playwright joue une **fausse Brother** (Web Serial simulé) qui enregistre les octets, répond à l'état et confirme les pages ; un **décodeur indépendant** écrit d'après le manuel reconstruit l'image et la compare, point par point, à l'aperçu. Il prouve la chaîne complète **hors papier** : le sens physique et l'appairage Android se vérifient sur la machine (étiquette test).

## Limites connues

- Hors ligne, une étiquette DLC s'imprime avec l'heure du serveur connue au dernier échange mais **sans entrée de journal**.
- Tablette fermée en pleine impression automatique : la réclamation est rendue à la file après 120 s ; en théorie un doublon est possible si l'impression avait en fait abouti.
- Le chef (permission `kitchen-display-system` seule) est admis par l'API mais pas par la route de l'écran (`pos-orders`), comme l'écran Uber photo.
