# VAGUE A — SITE WEB (lecayenne.fr) — TOUR 1, capture + constat (aucune correction de code)

Date d'exécution : 2026-10-23 00:0x–00:4x (Europe/Paris). Équipe GStack, vague A.

## (a) État de l'environnement

- Backend local `http://127.0.0.1:8766` (base `foodking_e2e`), soketi :6001 et un `queue:work` actifs. Mail = `smtp.example.com`.
- Miroir du site `http://127.0.0.1:8011` = copie de `.../lecayenne-web-deploy/Site lecayenne` SANS `app/`, servie depuis le dossier du scratchpad (`.../scratchpad/site-local`). **C'est l'ÉTAT DE TRAVAIL du dépôt du site, qui porte 118 fichiers non commités d'une autre session** (`git status --short | wc -l` = 118 ; HEAD `681577d`, 2026-10-21). Tout constat « site » vaut pour cet état de travail, pas forcément pour ce qui est déployé.
- Instrument : meta `api-key` du miroir réécrite à la volée vers `MIX_API_KEY` (seule interception, `context.route` sur `/` et `/index.html`). Drapeau Chromium `--disable-features=LocalNetworkAccessChecks,PrivateNetworkAccessChecks` (Local Network Access, comme M1/M2).
- Données de base au moment des tests : 71 items `status=5` (45 « carte » + techniques/suppléments/sauces), 582 commandes en statut 4/7 en base (pollution des vagues précédentes), `order_setup_delivery` = ENABLED.
- ⚠️ `.env` local : `TIME_FORMAT="h:i A"` (ligne 69) — formate l'heure des commandes en 12 h (voir C-6).
- ⚠️ `roue.html` porte en dur `api-base-url = https://vps-418872ac.vps.ovh.net` (PRODUCTION) : chaque visite de `roue.html` par mes specs a émis **un GET public en lecture** `…/api/frontend/wheel/config?branch_id=1` vers la production (404 « jeu fermé »). Rien d'autre n'a touché la production.
- Node v22.23.2. Toutes les specs lancées avec `--workers=1 --retries=0`, un seul navigateur à la fois de mon côté.

Specs écrites / utilisées (`tests/Playwright/e2e-complet-2026-10-22/`) :
- `A-base-M1.spec.js` et `A-base-M2.spec.js` : copies de M1/M2 (verif-globale-2026-10-21) avec seulement le dossier de sortie (`e2e-complet-A/base-M1|M2`) et le préfixe de données changés en `E2E-SITE-M1` / `E2E-SITE-M2`. Assertions intactes.
- `A-etats.spec.js` : nouveaux états (accueil, carte, pages SEO/légales, wizard, panier, commande, confirmation, suivi, espace client), desktop 1440×900 + mobile 390×844, quatuor PNG+DOM+console+réseau via `attachMegaAuditRecorder`, audit DOM déterministe + axe-core (contraste, noms accessibles, titres) consignés dans `tests/e2e/__screenshots__/e2e-complet-A/A-ledger.json` (203 contrôles, 8 KO — tous classés ci-dessous).

## (b) États capturés (dossier `tests/e2e/__screenshots__/e2e-complet-A/`, quatuor `.png/.dom.html/.console.json/.network.json`)

Desktop (D-) et mobile (M-), `A-etats.spec.js` :
1. `D-accueil.png` / 2. `M-accueil.png`
3. `D-carte-tout.png` / 4. `M-carte-tout.png` ; 5. `D-carte-burgers.png`
6. `D-fiche-cheese-burger.png` / 7. `M-fiche-chicken-burger.png`
8. `D-wizard-cheese-burger-etape-1.png`, 9. `D-wizard-cheese-burger-etape-3.png`, 10. `D-wizard-recap.png` / 11. `M-wizard-chicken-burger-etape-1.png`, 12. `M-wizard-recap.png`
13. `D-panier.png` / 14. `M-panier.png`
15. `D-upsell.png`
16. `D-commande-retrait-paiement.png` / 17. `M-commande-haut.png`
18. `D-confirmation.png` / 19. `M-confirmation.png`
20. `D-suivi.png` / 21. `M-suivi.png`
22. `D-mes-commandes.png` / 23. `M-mes-commandes.png`
24. `D-fidelite.png` / 25. `M-fidelite.png`
26. `D-page-carte.png` (carte.html), 27. `D-page-horaires.png` / 28. `M-page-horaires.png`, 29. `D-page-livraison-henin-beaumont.png` / 30. `M-page-livraison-henin-beaumont.png`, 31. `D-page-legal-mentions.png` / 32. `M-page-legal-mentions.png`, 33. `D-page-legal-allergens.png`, 34. `D-page-404.png`, 35. `D-page-roue.png` / 36. `M-page-roue.png`
37. Les 14 autres pages HTML (a-propos, bols-et-frites, burgers, commander, manger-pas-cher, manger-tard, sandwichs-galettes, tacos, cgv, cookies, privacy, + mobile des pages déjà citées) : **audit DOM + axe + SEO sans PNG** (registre `A-ledger.json`, entrées `…-AUDIT-SANS-PNG`) — disque.

Specs de base : `base-M1/` (15 PNG : accueil, carte.html, 404, carte SPA, fiche Cayenne, wizard viandes/suppléments/récap, panier, page de commande, échec d'envoi e-mail, mobile…) et `base-M2/` (25 PNG : tunnel livraison, devis, créneaux, compte, mobile/desktop). Total dossier : 42 Mo.

Chaîne de commandes réelles créées (préfixe `E2E-SITE-`) : #8657 (desktop, Cheese Burger, 6,00 €), #8659 (mobile, Chicken Burger, 4,90 €), plus celles de M2 (compte supprimé par la spec).

## (c) Résultats des specs

| Spec | Résultat | Cause des échecs |
|---|---|---|
| M1 (`A-base-M1`) tests 1-5 | **5/5 passés** | — |
| M1 test 6 (commande Cayenne → caisse → KDS → suivi) | **ÉCHEC** | **produit, connu** : le wizard du site impose « CHOISIS 2 VIANDES / 2 viandes incluses » sur la Cayenne ; le serveur refuse `POST /api/frontend/order` en 422 : « « Cayenne » comprend 1 viande : choisis-en exactement 1 (reçu 2) » (`M1-004 = M5-001`, patch `SITE_PATCH_viandes_menu-js.diff` non appliqué au site, listé dans « Reste ouvert »). Le message affiché au client à ce moment n'a PAS été capturé (la spec s'arrête sur l'assertion). |
| M1 test 7 (commande programmée) | non exécuté | dépend de l'état du test 6 (mode serial). Non couvert par M1 ; la programmation n'est pas rejouée ailleurs dans ma vague. |
| M1 test 8 (mobile 390) | **passé** (relancé seul avec `--grep "8\. mobile"`) | — |
| M2 (`A-base-M2`) | 1re passe : 5/7 ; **2e passe : 7/7** | Passe 1 : test 6 en échec `guestVerify` HTTP 429 « Trop de tentatives » = **instrument** (limiteur `throttle:3,5` de `/verify` partagé par IP 127.0.0.1 avec les autres vagues ; c'est la famille M1-002 déjà connue) ; test 7 non exécuté (serial). Une passe intermédiaire a expiré dans `beforeAll` (600 s) = contention du serveur partagé, aucun défaut produit. Passe 3 : 7/7. |
| `A-etats.spec.js` | **6/6 passés** (1,8 min) | Une 1re passe a échoué par **instrument** (mon sélecteur « Passer commande » ciblait le bouton du panier hors écran) ; corrigé, relancé. |

Chaîne de totaux (`A-etats`, preuves chiffrées, tous OK) :
- Desktop Cheese Burger : wizard récap 6,00 = panier 6,00 = récap de commande 6,00 = bouton 6,00 = `expected_total` envoyé 6 = réponse POST 6 = confirmation 6,00 = `orders.total` 6,000000 (id 8657) = Σ `order_items.total_price` 6,00 (+ livraison 0 − remise 0) ; `total_tax` 0,55 (10 % sur 6,00 = 0,545). Suivi affiche 6,00 €.
- Mobile Chicken Burger : 4,90 partout, y compris `orders.total` (id 8659).
- Corps du POST : clés `branch_id, order_type, source, is_advance_order, payment_method, items, expected_total` — aucun prix unitaire envoyé.
- Composition scellée : 1 viande (Mexicanos) + sauce (Mayonnaise) + crudités, `item_variation_total=0`, cohérent avec « 1 viande incluse » affichée.
- Carte : 30 cartes affichées en vue « Tout » ; prix identiques à `items` (SQL) ET à l'API publique `/api/frontend/item?branch_id=1` (2 moyens) ; 0 produit inventé ; produit en rupture (Coca-Cola 33cl, `is_available=false`) = seul bouton désactivé ; produit `E2E_PLAYWRIGHT_STUDIO_ITEM` (status 5 en base) NON exposé ; onglets (Sandwichs, Galette, Burgers, Tacos, Bols, Frites, Desserts, Boissons, Menu enfant) tous présents en base ; filtre Burgers = catégorie Burgers de la base (6/6). La vue « Tout » affiche 25 plats + 5 boissons puis une section « Boissons · 15 au frais » avec bouton « voir tout » : voulu par le code (`screens.jsx` : `drinkItems.slice(0, 5)`), donc le « 30 cartes pour 40 produits » est une conception, pas un défaut (mon contrôle initial était faux).
- Caisse (lecture API `GET /api/admin/pos/web-orders/pending`, HTTP 200) : la commande 8657 est présente, nom client `E2E-SITE-A-…` et produit visibles, `total` = 6 = `orders.total`.
- Cuisine : avant acceptation la commande web n'est pas dans `admin/kds-order` (règle produit) ; après acceptation (`online-order/change-status` 200, statut 4 en base) elle n'y est **pas** non plus (voir C-4, connu).
- Suivi : page du site « COMMANDE ACCEPTÉE · Prête dans ~17–22 min », total 6,00 € ; API `order/track/{token}` HTTP 200, statut 4 « Commande acceptée ».
- Aucune clé i18n brute : 0 occurrence sur ~70 états audités (DOM texte visible, regex protocole + `undefined/NaN/[object Object]/Label.x`). Console propre hors les cas listés en (d).

## (d) Constats candidats

Légende : `connu` = déjà dans `RAPPORT_FINAL.md` « Reste ouvert » / `FINDINGS_LEDGER.md`. 2e moyen = deuxième instrument indépendant.

**C-1 — Cayenne : le site impose 2 viandes, le serveur en accepte 1 (commande impossible)** — P1, **`connu`** (M1-004 = M5-001 ; patch site en attente).
- Preuve 1 (DOM) : étape wizard « CHOISIS 2 VIANDES / 2 viandes incluses · viande en plus +2,50 € chacune » (note de spec M1, `base-M1/12-wizard-viandes.png`).
- 2e moyen (API) : `POST /api/frontend/order` → 422 `items.0.item_variations` « « Cayenne » comprend 1 viande : choisis-en exactement 1 (reçu 2) » (sortie de M1 test 6). Preuve de cause : `SITE_PATCH_viandes_menu-js.diff` change `viandes: 2 → 1` sur la Cayenne et Sandwich Classique, `3` sur Tacos XL. À l'inverse, Cheese Burger/Chicken Burger (1 viande affichée) passent (commandes 8657/8659). Reste à vérifier : le message d'erreur visible par le client.

**C-2 — Pas de numéro de retrait pour le client web** — P1, **`connu`** (M1-104).
- Preuve : `orders.queue_number = A0101` (id 8657, SQL) ; la confirmation (`D-confirmation.png`) et le suivi affichent seulement `#2310268657` (numéro de série) ; contrôle `CLIENT voit son numéro de retrait` KO dans `A-ledger.json`. La caisse et la cuisine utilisent `A0101` (`web-orders/pending`).

**C-3 — Créneaux et heure de fermeture contradictoires** — P2, **`connu`** (M1-102).
- Site : « Ouvert 7j/7 de 18h à minuit » (`D-page-horaires.png`, `M-page-horaires.png`, accueil). Serveur : fenêtre de service `kds.scheduled_window_close` = 00:30 (`app/Services/WaitEstimateService.php:105`), `GET /api/frontend/order/wait-estimate` → `service_ouvert: true` à 00:04, `closing_time: null` (table `time_slots` vide). Écran de commande à ~00:17 propose « Dès que prêt », « Dans 30 min », « Dans 40 min » (`D-commande-retrait-paiement.png`), c'est-à-dire des heures après la fermeture annoncée.

**C-4 — Commande web acceptée absente de la cuisine (KDS)** — P2, **`connu`** (M1-001/M6-005, plafond de cartes — décision propriétaire) + **environnement**.
- Preuve : `GET /api/admin/kds-order` renvoie `data` = 50 commandes (ids 8092..8577), `meta.overflow = true, limit = 50` ; 8657 (statut 4) absente ; 582 commandes en statut 4/7 en base (SQL). La liste est triée par ancienneté : la plus récente est hors fenêtre. Pollution de la base locale en cause ; le plafond est déjà décidé.

**C-5 — Pages SEO « pas de livraison en propre » vs livraison active** — P2, **`connu`** (M1-006) ; chiffres **M1-005** connu aussi.
- `livraison-henin-beaumont.html` : « Le Cayenne ne livre pas lui-même. La livraison passe par Uber Eats » (6 occurrences, `grep -c`) alors que `GET /api/frontend/delivery/status` → `enabled: true`, grille dès 3,90 € et que la page de commande propose « Livraison — Dès 3,90 € ». Le texte compare « au 8 août 2026 ». `carte.html` annonce « 39 produits » (meta description) quand la carte du site dit « 40 produits » (`D-carte-tout.png`) (nombre écrit en dur).

**C-6 — Date des commandes : « 12:16 AM, 23-10-2026 » sur un site français** — P3, **environnement local + dette de code** (non reproduit comme défaut de production).
- Cause 1 (locale) : `.env:69 TIME_FORMAT="h:i A"` ; le code par défaut est `H:i` (`app/Libraries/AppLibrary.php:48`, test `MoneyFormatConfigCacheTest` « 14:21, 26-06-2026 »). Je n'ai PAS lu le `.env` de production : à vérifier par le propriétaire.
- Cause 2 (indépendante de l'env) : `orders.jsx` `fmtOrderDate` (« 8 juil. · 19h47 ») reçoit la chaîne `order_datetime` formatée par l'API (`OrderResource.php:37`), que `new Date()` ne sait pas lire (testé en Node : `00:16, 23-10-2026` et `12:16 AM, 23-10-2026` → Invalid Date) : le formatage FR du site retombe toujours sur la chaîne brute (code mort). Preuve visuelle : `D-mes-commandes.png`.

**C-7 — Contraste WCAG AA (axe-core + calcul indépendant)** — P2, `non connu` dans le ledger relu (non cherché exhaustivement).
- Bouton principal « Continuer / Ajouter au panier » du wizard : blanc sur `#ff5a1f` = 3,12:1 (calcul Python = 3,118 ; seuil 4,5 pour du 14 px), total du bouton blanc sur `#ff713e` = 2,73:1 ; libellé de navigation « Accueil » actif `#c2410c` sur `#e7e6e3` = 4,15:1 ; paragraphe « C'est du click & collect… » `#5c584f` sur `#151110` = 2,65:1 (accueil) ; toast « Ajouté ✓ » blanc sur `#ff5a1f` 3,11:1 (mobile). États : `D-accueil`, `M-accueil`, `D-wizard-recap`, `M-wizard-recap`, `M-panier`. Le brand `#F4501E` est imposé par le mandat propriétaire sur la caisse/borne ; sur le site c'est une décision de design à trancher.

**C-8 — Heading-order (axe, moderate)** : 1 occurrence sur confirmation, suivi/commandes/fidélité desktop et mobile — P3, non investigué plus avant (axe seul, `non reproduit` par un 2e moyen).

**C-9 — `roue.html` : 404 console `wheel/config`** — P3, **conception documentée** : le jeu est fermé tant que `wheel.enabled` est faux (`routes/api.php` ~l. 2428-2433, commentaire « reste fermée au public »). Le visiteur voit « Le jeu n'est pas encore ouvert. Reviens très vite ! » (`D-page-roue.png`), donc pas d'erreur silencieuse ; seul le journal console porte une 404. La page est `noindex` : l'absence de description/canonical n'est pas un défaut (mon contrôle SEO KO sur `roue` = faux positif instrument).

**C-10 — Mes commandes mobile : filtres en gros blocs noirs** — P2, **`connu`** (M2-006). `M-mes-commandes.png`.

**C-11 — Livraison ASAP → lendemain 20:00 sans l'annoncer** — P1, **`connu`** (M2-002 / M2-004, famille « heure réelle ») ; vu par M2 : créneaux « Au plus tôt vers 20:00 » à 00:03, `delivery_slot_at = 2026-10-23 20:00:00`, confirmation sans heure (`heure=false`). Autres obs. M2 : M2-001 (pas de complément d'adresse sur le site), M2-005 (libellés « Hénin-Beaumont / Points fidélité en attente du retrait » codés en dur sur chaque carte, y compris pour une livraison : `orders.jsx:179,181`).

Contrôles qui n'ont rien révélé : numeric integrity (0 écart), i18n (0 fuite), image cassée (0), défilement horizontal (0 sur ~35 états mobiles), 4xx/5xx sans message (0 réel ; voir « instruments » ci-dessous), liens internes des pages SEO (0 cassé hors redirections `vercel.json` absentes du miroir python), sitemap : 41 URLs, toutes < 400, JSON-LD valides.

Faux positifs d'instrument écartés (preuve que mon outil mentait, pas le produit) :
- « boutons/liens sans nom » (8 sur `carte.html`) : `axe` (`link-name`) n'en trouve aucun → artefact de `innerText`. Rejeté.
- « chevauchements de cibles cliquables » : éléments derrière une modale ou barres fixes (CTA collant). Rejeté (non filtré par `elementFromPoint` sur les éléments fixes).
- `silent_error` sur `D-confirmation` (422 `guest-signup/email-login`) : la requête 422 date de l'étape d'identification (SMTP local `smtp.example.com`), mais le buffer du recorder est « depuis le dernier snap » ; l'écran affichait bien « Le code n'a pas pu être envoyé. Réessaie dans un instant. ». Connu (M1-003 corrigé côté message, M1 ligne « SMTP »).
- `SUIVI API total` KO : `order/track/{token}` ne renvoie pas de total par conception ; le total est affiché par la page de suivi (OK).
- `MES COMMANDES` KO : la liste affiche « 1 article » sans nom de produit (le détail est sous « Voir tout le détail ») ; ma regex attendait « Cheese ». Le total 6,00 € est bien affiché.

## (e) Non couvert, et pourquoi

- **Paiement par carte en ligne (Mollie)** : `MOLLIE_API_KEY` locale = `live_…` et meta `mollie-testmode=0` → jamais exercé (clé réelle). Apple Pay / Google Pay idem.
- **Commande programmée (M1 test 7)** et **test 6 M1 en entier** (arrivée temps réel tracker, KDS) : bloqués par C-1 (mode serial). La latence temps réel n'a pas été mesurée (aucune des deux specs ne l'a atteinte). Je n'ai testé qu'une arrivée par lecture API.
- **Commande de livraison complète côté site** (calcul de barème, créneaux) : couverte par M2 7/7 seulement ; je n'ai pas ajouté d'états livraison propres (devis par adresse déjà en base-M2).
- **PNG par page SEO** : seulement 8 pages sur 18 ont un quatuor PNG ; les autres ont l'audit DOM/axe/SEO (disque ≈ 10 Go libres).
- **Connexion réelle par code e-mail** : l'envoi SMTP échoue (`smtp.example.com`), je termine l'authentification par `POST /api/auth/guest-signup/verify` avec le code lu en base (voie officielle du produit), comme M1/M2. L'écran du code n'est donc jamais atteint dans ce banc.
- **Hors-ligne, service worker, installation PWA, réseau lent** : non testés.
- **Contraste/axe sur les états du wizard intermédiaires** (sauce, crudités, suppléments, menu) : les 2 étapes capturées (1 et 3 sur desktop, 1 sur mobile) sont auditées ; les autres sont parcourues sans PNG.
- **Navigateurs autres que Chromium**, Safari iOS, tablettes (768/1024) : non testés.
- **Site réellement déployé** : non testé (miroir local de l'état de travail du dépôt, 118 fichiers non commités d'une autre session).
- **Prix produit « boisson de menu en rupture »** : le site propose bien Coca-Cola Zero etc. en upsell ; Coca-Cola 33cl absente de l'upsell. Non rejoué en commande (corrigé côté serveur par M1-101).

## Résumé (15 lignes)

1. Env OK : :8766 base `foodking_e2e`, miroir :8011 = état de travail du dépôt du site (118 fichiers non commités d'une autre session, HEAD 681577d).
2. M1 : 5/8 (tests 1-5 OK ; 8 OK relancé seul) ; test 6 échoue et bloque le 7 = **connu** (Cayenne 2 viandes côté site vs 1 serveur, M1-004).
3. M2 : 7/7 à la 3e passe ; 1re passe 429 `/verify` = instrument (IP partagée entre vagues).
4. `A-etats` : 6/6 ; 36 états PNG+DOM+console+réseau (D+M) + 25 PNG M2 + 15 PNG M1 ; 18 pages SEO/légales auditées (axe + SEO + liens + sitemap).
5. Chaîne de totaux prouvée à 8 niveaux, desktop (8657, 6,00 €) et mobile (8659, 4,90 €) : panier = récap = bouton = POST = confirmation = `orders.total` = Σ lignes = caisse (API). Aucun écart.
6. Prix carte = `items` (SQL) = API publique ; 0 produit inventé ; 0 produit de test exposé ; rupture Coca 33cl cohérente.
7. 0 clé i18n brute, 0 débordement horizontal mobile, 0 image cassée, console propre (hors 404 `roue`, conception).
8. Aucun P0 nouveau. Constats tous **connus** : numéro de retrait absent (M1-104), heures de fermeture contradictoires (M1-102), « pas de livraison » vs livraison active et 39/40 produits (M1-006/005), KDS plafonné à 50 (M1-001/M6-005), filtres noirs mobile (M2-006), livraison ASAP → J+1 (M2-002/004).
9. Candidat nouveau : contraste AA du bouton orange (3,12:1) et du texte accueil (2,65:1), P2, à trancher par le propriétaire (design).
10. Environnement : `.env` local `TIME_FORMAT="h:i A"` → « 12:16 AM » sur « Mes commandes » ; de plus `fmtOrderDate` du site est du code mort (P3). Prod non vérifiée.
11. `roue.html` interroge la PRODUCTION (`vps-418872ac…`) : 1 GET public lu par visite de mes specs (404, jeu fermé).
12. Non couvert : paiement Mollie (clé live), commande programmée, temps réel, PWA/hors-ligne, Safari/tablettes.
13. Données de test : `E2E-SITE-` (users 912/913/918, commandes 8657/8659 + M2/M1) ; rien supprimé hors compte M2 (suppression par la spec).
14. Faux positifs d'instrument documentés et rejetés (liens sans nom, chevauchements, silent_error de buffer).
15. Aucune modification de code produit ; seuls fichiers créés : 3 specs + captures + ce rapport.
