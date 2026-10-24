# VAGUE A — SUIVI DES COMMANDES · phase CAPTURE (round 1)

- Surface : `/admin/pos-orders-tracker` (`resources/js/components/admin/pos/PosOrdersTrackerComponent.vue`)
- Spec : `tests/e2e/audit-supervisor-waveA.spec.js` — **1 passed (26,5 s)**, 10 états, 10 quartets
- Artefacts : `tests/e2e/__screenshots__/test-e2e-waveA/` (`.png` + `.dom.html` + `.console.json` + `.network.json`)
- Données : semées via `php artisan tinker`, préfixe exclusif `AUDA-`, **nettoyées** en fin de course
  (vérifié en base : `0` commande et `0` ligne `AUDA-%` restantes)
- Aucun code de production touché.

## Garde d'environnement

Au premier `goto`, la spec refuse de capturer si le HTML contient `Warning: require`,
`Fatal error` ou `Failed to open stream`. Elle a été ajoutée parce que le worktree servait,
au moment où j'ai commencé, un `vendor/` amputé : le serveur répondait **HTTP 200** en ne
rendant qu'un avertissement PHP. Aucune capture n'a été prise pendant cette panne. L'exécution
ci-dessous est postérieure à la réparation (`/admin/pos-orders-tracker` = 69 470 octets de HTML).

## Les dix états

| # | Fichier | Ce que j'attendais | Ce que j'ai réellement vu |
|---|---------|--------------------|---------------------------|
| 1 | `01-tableau-1366x768` | le kanban complet, sans débordement horizontal | 5 couloirs (À encaisser 0 · En préparation 3 · Prêts à servir 1 · En livraison 0 · Livrés 8), 12 cartes, `scrollWidth == clientWidth` → aucun débordement. **Mais l'en-tête consomme ~437 px des 768 : la première carte commence sous la moitié de l'écran et aucune carte n'est lisible en entier sans défiler.** |
| 2 | `02-tableau-1024x600` | le même tableau lisible sur l'écran contraint du comptoir | 0 débordement, 0 élément hors carte — **et pas une seule carte visible** : l'en-tête mesure 267 px, la première rangée de couloirs commence à ~437 px sur 600, seuls les TITRES de 3 couloirs apparaissent ; les 2 autres couloirs sont repliés sur une seconde rangée, encore plus bas. |
| 3 | `03-carte-composition-riche` | produits + sauces + extras + suppléments + instruction lisibles sur la carte | 4 `<li>`, 3 résumés de composition, instruction « Sans oignons - allergie arachide » en bandeau. **La ligne de composition est coupée à l'écran** : on lit « Galette · Algerienne · Bien cuit ·… » — `+2 Cheddar` et `+Salade` sont dans le DOM et dans `title=` mais **invisibles** (`white-space: nowrap; text-overflow: ellipsis`). Le repli « + 1 autres » est aussi un faux pluriel (`pos.tracker.more_items` = « autres »). |
| 4 | `04-voir-tout-ouvert` | les 4 lignes et TOUTES les personnalisations, sans appel réseau | Panneau ouvert, 4 lignes, tout présent et étiqueté (« Pain : Galette », « Sauce : Algerienne », « Cuisson : Bien cuit », « Extras : 2× Cheddar, Salade », « Suppléments : Frites »), en-tête « 🛒 Caisse · 03:57 · 4 articles · 7 au total · 19,40 € ». **0 libellé brut, 0 appel `/api/admin/`.** Seule dissonance : la carte écrit `+2 Cheddar`, le panneau écrit `2× Cheddar` — deux notations pour la même donnée. |
| 5 | `05-voir-tout-ligne-simple` | le panneau « Voir tout » sur une commande à une seule ligne sans personnalisation | **ÉTAT NON ATTEIGNABLE, et je ne l'ai pas contourné.** Sur `AUDA-SIMPLE` (1× Petite Frites, 6,50 €) le bouton `tracker-voir-tout-*` **n'existe pas** : `aDuContenuAVoir()` le supprime quand il n'y a ni 4ᵉ ligne, ni option, ni extra, ni instruction. J'ai capturé la carte à la place. C'est un choix assumé du composant, pas un plantage — mais il signifie qu'il n'y a **aucun geste uniforme** : le caissier doit deviner, carte par carte, si « Voir tout » sera là. |
| 6 | `06-carte-telephone` | une puce 📞 et de quoi rappeler un client absent | Puce `📞`, `title="Téléphone"`, nom « Karim Bensalah », instruction « Rappeler avant de preparer ». **Aucun numéro affiché, aucun lien `tel:` (`tracker-customer-phone-*` = 0 occurrence)** — alors que `pos_customer_phone = 0612345678` était scellé sur la commande. Cause lue dans le code : `SimpleOrderResource::displayCustomerPhone()` n'autorise le numéro que si `order_type == DELIVERY` **ou** `source_surface == 'web'` ; `phone` n'est dans aucun des deux. Le canal dont la raison d'être est « le client n'est pas là » est le seul qu'on ne peut pas rappeler. |
| 7 | `07-carte-plateforme` | une puce 🛵 plateforme, distincte de la caisse, avec son ticket promo | Puce `🛵`, `title="Plateforme"`, « Sofia », « 2× Big Tacos / Harissa · +Emmental », 24,90 €. **Bouton « Ticket promo » absent** (`canPrintFlyer` = false : la permission `pos-flyer-print` n'est pas dans le jeu de l'admin connecté) — donc l'action censée être LE geste plateforme n'était pas atteignable pour ce compte. Par ailleurs **le même 🛵 sert à trois choses** : puce canal plateforme, badge `tracker-delivery-*`, et icône du couloir « EN LIVRAISON ». |
| 8 | `08-filtre-telephone` | l'onglet Téléphone actif ne laisse que les commandes téléphone | Onglets réellement présents : `🧾 Toutes · 🛒 Caisse · 🖥️ Borne · 🌐 En ligne · 📞 Téléphone · 🛵 Plateforme` (les onglets canal n'apparaissent que si le canal est présent — comportement conforme). `aria-selected=true`, classe `is-active`, 1 seule carte restante (`#AUDA-TEL`). **Incohérence de compteurs sous filtre** : l'en-tête affiche « **1** actives » (filtré) juste à côté de « **41** service en cours » (NON filtré) — deux nombres calculés sur deux populations, collés l'un à l'autre. |
| 9 | `09-panneau-souffrance` | le panneau des non-terminées antérieures au service, statuts en français | Pilule « 581 en souffrance » → panneau ouvert, **50 rangs sur 581**, troncature annoncée honnêtement (« 50 affichées sur 581 »), statuts en clair (« En préparation », « Prêts à servir »), 0 libellé brut, `#AUDA-SOUFFRANCE` bien présent en tête. **Deux constats** : (a) le panneau est rendu **tout en bas de page, sous les cinq couloirs** — cliquer la pilule (en haut) ne l'amène pas dans le champ de vision, ma capture a exigé un défilement programmé ; (b) **aucune pagination** : les 531 commandes restantes ne sont atteignables par aucun geste de cet écran. |
| 10 | `10-couloir-vide` | un couloir sans carte affiche une icône + une phrase d'état vide | 2 couloirs vides au chargement, sans aucun filtre : « À encaisser » → `✓` + « Aucune commande à encaisser. » ; « En livraison » → `—` + « Aucune commande en livraison. » Français correct, ponctuation propre. L'icône vide de « En livraison » est un simple tiret `—`, qui se lit comme un gabarit non fini à côté du `✓` de la voie voisine. |

## Console / réseau (quartets)

- `01-*.console.json` : **4 × 404 sur `/storage/1/english.png`** (le drapeau de langue de l'en-tête,
  cassé à l'écran), puis `ERR_CONNECTION_REFUSED` sur `127.0.0.1:9100/health` et `:9101/health`
  (pont d'impression absent en dev — attendu).
- Une violation **CSP** consignée : `connect-src` autorise `http://127.0.0.1:9100` mais **pas
  `:9101`**, que le code sonde quand même (`public/js/pos-wizard.js:203`). La politique est en
  `report-only`, donc rien n'est bloqué aujourd'hui ; en mode appliqué, cette sonde tomberait.
- Snaps 02 → 10 : **console vide, réseau vide** (aucune erreur, aucune mutation) — y compris pendant
  l'ouverture de « Voir tout », ce qui confirme le « zéro appel réseau » revendiqué.
- Aucune `pageerror` sur les 10 états.

## Réserves d'honnêteté

- Les cartes `#AUDA-…` portent un `order_serial_no` long qui **passe à la ligne** dans l'en-tête de
  carte. Les vraies commandes affichent un `queue_number` court (`N°A0032`) et ne se cassent pas :
  ce retour à la ligne est un artefact de mon semis, **pas** un défaut du produit.
- Le nom client des commandes semées est « Admin Le Cayenne » (`user_id = 1`) : artefact de semis.
- La barre Debugbar occupe ~40 px en bas de chaque capture — outil de dev, absent en production.
- L'absence du bouton « Ticket promo » (état 7) tient au **jeu de permissions du compte admin
  utilisé** ; elle ne prouve pas que le bouton est cassé pour un caissier disposant de
  `pos-flyer-print`. À rejouer avec `pos@lecayenne.fr` si la vague suivante veut trancher.
- L'état 5 est le seul état **non atteint**, pour la raison exposée ci-dessus. Il n'a été ni simulé,
  ni contourné.
