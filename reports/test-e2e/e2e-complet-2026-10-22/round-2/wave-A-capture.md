# VAGUE A — SITE WEB — TOUR 2 (rejeu + constat, aucune correction de code)

Exécution : 2026-10-23 02:04–02:20 (Europe/Paris, = 00:04–00:20 UTC). Backend HEAD 64c4a4f81, :8766 base `foodking_e2e`, miroir :8011 = copie de l'état de travail du dépôt du site (`cmp` identique pour `api.js`, `funnel.jsx`, `data/menu.js`, `compiled/funnel.js` ; `index.html` diffère par la seule meta `api-key`). Aucun fichier du dépôt du site écrit. Aucun redémarrage.

Specs (toutes dans `tests/Playwright/e2e-complet-2026-10-22/`) : `A-base-M1-r2`, `A-base-M2-r2`, `A-etats-r2` = copies du tour 1 avec seulement le dossier de sortie (`tests/e2e/__screenshots__/e2e-complet-A-r2/`) et un enregistreur à budget de PNG (`A-r2-lib.js` : quatuor DOM/console/réseau toujours écrit, PNG seulement si liste blanche + plafond). Nouvelle : `A-r2-ferme-422.spec.js` (service fermé + 422 Cayenne). Assertions du tour 1 intactes.

PNG nouveaux : **9** (plafond 15) — 1 `base-M1/12-wizard-viandes`, 2 `base-M2` (09, 16), 1 `D-confirmation`, 5 `ferme-422/R2-*`. Dossier 29 Mo. Disque restant : 1,2 Go.

## 1. Comparaison au tour 1

| Spec | Tour 1 | Tour 2 | Cause du changement |
|---|---|---|---|
| `A-base-M1` tests 1-5 | 5/5 | 5/5 | identique |
| M1 test 6 (commande Cayenne → caisse → KDS → suivi) | ÉCHEC 422 | ÉCHEC 422, même cause produit (site impose 2 viandes, serveur en accepte 1) | **cause inchangée**. Seul le texte serveur change : il est maintenant en français, « Sélectionnez au maximum 1 Viande 1 (actuel : 2). **(et 2 autres erreurs)** » (C-101 corrigé). Le 422 porte en fait 3 erreurs (voir R2-N2/N4). |
| M1 test 7 | non exécuté (serial) | non exécuté (serial) | identique |
| M1 test 8 (mobile) | passé, relancé seul | passé, relancé seul (`--grep "8\. mobile"`) | identique |
| `A-base-M2` | 1re passe 5/7 (429 `/verify`), 3e passe 7/7 | **7/7 dès la 1re passe** | le 429 du tour 1 = instrument (IP partagée). Défauts consignés par M2 : tour 1 = M2-001, **M2-004**, M2-005 ; tour 2 = M2-001, M2-005. **M2-004 disparaît : c'était un faux positif d'instrument** (voir R2-N5). |
| `A-etats` | 6/6 | 6/6 | identique. Registre `A-ledger.json` : 203 contrôles / 8 KO → 204 / 7 KO. Le KO qui disparaît = « CUISINE : après acceptation, la commande est dans admin/kds-order » (C-4 du tour 1) : `meta.overflow` vaut `false` au tour 2 (`true` au tour 1). **Cause non élucidée** : l'état de la base a changé entre les deux tours (statut 4 : 91 commandes ; autres vagues actives), je n'ai pas investigué. Un état d'environnement, pas une correction constatée. |
| `A-etats` — axe-core, i18n, débordement, images cassées | 61 états | 61 états, **0 état dont la liste de violations axe diffère** | identique : contrastes (3,12:1 / 2,73:1 / 4,15:1 / 2,65:1), heading-order inchangés |
| Autres KO du registre | roue (SEO, faux positif), « 30 cartes / 40 produits » annoncé, numéro de retrait absent (`queue A0156`), suivi API sans total (par conception), « Mes commandes » (regex) ×2 | **mêmes 7** | identique |
| C-6 (« 02:13 AM, 23-10-2026 » dans Mes commandes) | « 12:16 AM » | « 02:13 AM » | `.env:69 TIME_FORMAT="h:i A"` toujours en place (environnement local) |

Commandes créées au tour 2 (préfixe `E2E-SITE-`) : #8714 (desktop Cheese Burger 6,00 €, acceptée par la caisse par API), #8717 (mobile Chicken Burger 4,90 €, en attente), + celles de M2 (#8681, #8682, #8684, #8685, …, comptes `E2E-SITE-M2`, compte supprimé par la spec), 0 commande pour `A-r2-ferme-422` (422 attendu). Je n'ai rien supprimé. J'ai aussi ouvert une session `admin@lecayenne.fr` par `POST /api/auth/login` (jeton Sanctum de test sur la base `foodking_e2e`) pour les essais API de R2-N6.

## 2. Chaîne de totaux (point 3) — OK, aucun écart

- Desktop Cheese Burger (#8714) : wizard récap 6,00 = panier 6,00 = récap commande 6,00 = bouton 6,00 = `expected_total` 6 = réponse POST 6 = confirmation 6,00 = `orders.total` 6,000000 = Σ lignes 6,00 ; `total_tax` 0,55 ; caisse (API `web-orders/pending`) total 6 et nom client/produit visibles ; suivi « COMMANDE ACCEPTÉE » ; clés du POST : `branch_id, order_type, source, is_advance_order, payment_method, items, expected_total` (aucun prix unitaire).
- Mobile Chicken Burger (#8717) : 4,90 à chaque niveau, y compris `orders.total`.
- Cayenne + Cheddar + Menu complet (M1 tests 3/4, passés) : récap = panier = récap commande = bouton = 10,80 € ; le POST de ce panier est refusé (R2-N2).

## 3. États nouveaux

### (a) Site pendant le SERVICE FERMÉ — réel, pas simulé
À 02:04, 02:08, 02:08:49 : `GET /api/frontend/order/wait-estimate?branch_id=1` → `{"service_ouvert":false,"ouverture":"18:00","wait_low":10,"wait_high":15,"closing_time":null,...}` (sortie `M1-mesures.json` de `ferme-422`, notes « wait-estimate »). Aucune simulation `page.route`.

- `tests/e2e/__screenshots__/e2e-complet-A-r2/ferme-422/R2-01-accueil-service-ferme.png` : « SERVICE FERMÉ — OUVRE À 18H CE SOIR », mais le bouton « COMMANDER » (3 occurrences) n'est pas désactivé (`dis: null`).
- `.../R2-02-etape-heure-service-ferme.png` : page de commande « HEURE DE RETRAIT — AUJOURD'HUI », « **Dès que prêt ~10-15 min** » présélectionné, « Dans 30 min », « Dans 40 min » ; bouton « Confirmer ma commande 10,80 € » actif ; aucun mot « fermé / ouvre / demain » sur la page (regex `mentionFerme: false`).
- `.../R2-03-recapitulatif-service-ferme.png` : récap sans mention de fermeture (Cayenne, 10,80 €).
- La commande est ensuite acceptée par le serveur : #8714, `POST /api/frontend/order` HTTP 201 à 02:13:13 pendant `service_ouvert:false` ; confirmation « PRÊT DANS ~10–15 min · ✓ ENVOYÉE EN CUISINE ». PNG `e2e-complet-A-r2/D-confirmation.png`.

### (b) 422 sur la Cayenne à 2 viandes : ce que voit le client
- `R2-04-cayenne-422-ce-que-voit-le-client.png` et `R2-05-…-defile-a-lecran.png`. Le client voit le texte du serveur **non remplacé** : « ✕ Sélectionnez au maximum 1 Viande 1 (actuel : 2). (et 2 autres erreurs) » (DOM `.lcf-field-error[role=alert]`, réponse HTTP brute identique). **Ce n'est pas** « Vérifie tes coordonnées et ton panier » (voir R2-N2).
- Corps de la réponse 422 (3 erreurs) : `items.0.item_variations` ×2 (« Sélectionnez au maximum 1 Viande 1 (actuel : 2). » et « « Cayenne » comprend 1 viande : choisis-en exactement 1 (reçu 2). Une viande en plus se commande en supplément. ») ; `items.0.instruction` : « La boisson du menu « Coca-Cola 33cl » n'est plus disponible — choisis-en un(e) autre ou retire la formule. »

### (c) 422 à plusieurs erreurs : plus de « (and »
- Site : `contient_and:false` sur le DOM + la réponse brute (M1 test 6 et `ferme-422` test 6).
- API directe (jeton admin local, `X-API-KEY` = `MIX_API_KEY`, `X-Idempotency-Key` unique, 5 `POST /api/frontend/order` invalides, tous refusés en 422 de validation de formulaire avant création ; je n'ai pas compté les lignes `orders` avant/après, donc « aucune commande créée » repose sur le 422, pas sur un comptage) : « Le champ type de commande est obligatoire. (et 6 autres erreurs) », « …doit être un nombre. (et 9 autres erreurs) », « … (et 4 autres erreurs) », « … (et 3 autres erreurs) », « … (et 7 autres erreurs) ». Aucune occurrence de « (and ». Clés : `lang/fr.json:2-3`. Le nombre = messages − 1 (ex. 5 messages → « 4 autres »). La forme singulière « (et 1 autre erreur) » n'a pas été déclenchée (non couvert).

## 4. Constats candidats (tour 2)

**R2-N1 — Service fermé : le tunnel et le serveur laissent commander « dès que prêt ~10-15 min »** — P1, reproduit A-001/A-006.
- Preuves : PNG R2-01/R2-02/R2-03 + DOM (créneaux, CTA actif, aucun mot de fermeture) ; API `wait-estimate` `service_ouvert:false` ; `orders` #8714 créée à 02:13:13 (SQL : status 1→4, source web). 2e moyen indépendant : code. `service_ouvert` n'est calculé que par `app/Services/WaitEstimateService.php:91` et **aucun client** ne le lit (`grep -rl service_ouvert` dans le dépôt du site hors `app/` : 0 fichier ; seul `api.js:1246` appelle `waitEstimate()`). Le serveur ne refuse pas les commandes non programmées hors fenêtre (la validation de fenêtre ne porte que sur `scheduled_at`, `WaitEstimateService.php:98-100`, commentaire). Que le restaurant accepte les commandes « hors service » est peut-être voulu (précommande de la nuit) : c'est le message au client qui est contradictoire, pas la règle serveur.

**R2-N2 — A-003 reformulé : le client ne voit pas « Vérifie tes coordonnées », il voit un 422 partiel et inactionnable** — P1 (inchangé, produit/autre session).
- La revue adverse du tour 1 disait que `api.js:327-330` remplace le message. Sur l'état servi (identique au dépôt de travail), la substitution (`api.js:454`) ne s'applique qu'aux messages **ASCII purs** contenant un mot anglais ; le message FR du serveur (accents) passe donc tel quel. Preuve : DOM R2-04/R2-05 + réponse HTTP brute.
- Ce que voit le client : la première erreur seulement, avec le nom interne « Viande 1 », puis « (et 2 autres erreurs) ». Il ne peut pas la corriger : le wizard (`CHOISIS 2 VIANDES`) **impose** 2 viandes (`data/menu.js:501`, `viandes: 2`, alors que `git diff HEAD -- data/menu.js` montre `viandes: 0` au HEAD du dépôt du site : c'est le travail non commité de l'autre session). Les deux autres erreurs (dont la boisson indisponible) ne sont jamais montrées.

**R2-N3 — Le message d'erreur de commande est sous la ligne de flottaison, recouvert par la barre « Confirmer », sans défilement automatique** — P2, `non connu`.
- DOM : `.lcf-field-error[role=alert]` : `top 1095 px`, `bottom 1110`, viewport 900, `scrollY 817`, `inViewport:false`, `recouvertPar: lcf-cta-bar` (barre à `top 839`). PNG R2-04 : après le clic sur « Confirmer ma commande », l'écran ne montre aucune erreur ; PNG R2-05 (après `scrollIntoView` par la spec) : le message est dans la carte « Compte (recommandé) ».
- 2e moyen (code) : `funnel.jsx:2196-2200` affiche `apiError` ; les seuls `scrollIntoView` du fichier sont `funnel.jsx:1059, 1629, 1664` (identité, champs carte Mollie), aucun pour `apiError`. `role="alert" aria-live="assertive"` : un lecteur d'écran l'annonce, pas un client voyant. Mesuré à 1280×900 seulement, mobile non mesuré.

**R2-N4 — Le wizard « Faire un menu » propose une boisson en rupture ; le serveur ne la refuse qu'à la fin** — P2.
- DOM (étape « CHOIX DE LA BOISSON », `M1-mesures.json` ferme-422) : « Coca-Cola 33cl » en tête, `dis: null` (cliquable) ; `GET /api/frontend/item?branch_id=1` : id 52 « Coca-Cola 33cl » `is_available: False` ; le récap et le panier le portent (« Coca-Cola 33cl », R2-03) ; 422 serveur `items.0.instruction` (ci-dessus). Code : `wizard-v2.jsx` ne contient aucune référence à `unavail`/`available` (la carte, le tiroir et l'upsell les utilisent : `screens.jsx:1573`, `flows.jsx:12`, `upsell.jsx:17`). Note : l'instrument a choisi le premier choix libre ; un client qui choisit une autre boisson n'est pas touché, mais rien ne lui dit que celle-ci est épuisée.

**R2-N5 — RÉTRACTATION : M2-004 / C-11 du tour 1 (« livraison ASAP → lendemain 20:00 ») est un faux positif d'instrument** — retire le P1 du tour 1.
- La spec compare le jour du créneau à `new Date().toISOString().slice(0,10)` (**UTC**, `A-base-M2*.spec.js` ligne ~580). À 00:03 Paris il était 22:03 UTC (le 22) alors que le créneau `2026-10-23 20:00` est le jour même à Paris. `date -u` à 02:14 Paris = 00:14 UTC (le 23) : M2-004 ne se déclenche plus et le créneau est `2026-10-23 20:30` (M2 : « Au plus tôt vers 20:30 »). À ne pas reporter au propriétaire. Reste : au tour 2, la confirmation de livraison affiche-t-elle l'heure ? non rejoué en détail (PNG `base-M2/16` seulement).

**R2-N6 — Libellés de champs anglais dans les 422 de `/api/frontend/order` côté jeton admin** — observation, `non reproduit` côté client du site.
- API directe : « Le champ expected total doit être un nombre. », « Le champ quote token est obligatoire. », « Le champ items doit être une chaîne JSON. » (noms de champs non traduits dans `attributes`). Le client du site (jeton invité, sans `quote_token`) n'a pas déclenché ces libellés : un seul moyen, hors parcours client.

Inchangés par rapport au tour 1 (non rejoués plus loin) : C-2 numéro de retrait absent de la confirmation (queue `A0156` en base, absent à l'écran), C-3 horaires/créneaux, C-5 pages SEO « ne livre pas » et 39/40 produits, C-7 contrastes AA, C-8 heading-order, C-10 filtres noirs mobile (M2-006), M2-001 (pas de complément d'adresse), M2-005 (libellés « Hénin-Beaumont » en dur).

## 5. Non couvert, et pourquoi
- Paiement carte Mollie (`MOLLIE_API_KEY` locale = `live_…`), Apple/Google Pay : jamais exercés.
- Commande programmée (M1 test 7), temps réel caisse/KDS du test 6 de M1 (bloqués par le 422 Cayenne, mode serial) ; latence non mesurée.
- Service fermé : seule la page de commande desktop 1280 a été capturée ; mobile fermé, livraison fermée et `roue.html` non rejoués. Les créneaux 00:30–00:59 : l'heure du test (02:xx) ne les couvre pas.
- Position du message d'erreur (R2-N3) mesurée à 1280×900 seulement.
- « (et 1 autre erreur) » singulier non déclenché ; Safari/tablettes/PWA/hors-ligne non testés ; site réellement déployé non testé (miroir de l'état de travail, ~118 fichiers non commités d'une autre session).
- Environnement : `roue.html` porte `api-base-url` de PRODUCTION : `A-etats-r2` l'a ouverte en desktop et mobile, soit 2 `GET …/api/frontend/wheel/config` publics en lecture vers la production (404 « jeu fermé »), comme au tour 1. Rien d'autre n'a touché la production.
- Instrument : `/verify` (`throttle:3,5,otp-verify`) a renvoyé 429 à trois passes de `ferme-422` ; le nettoyage de la spec visait `sha1("|127.0.0.1")` sans le préfixe `otp-verify` du limiteur nommé (clé réelle `otp-verify` + sha1). La 4e passe est passée sans modification utile du nettoyage (le limiteur s'est vidé avec le temps). Même famille que M1-002 : instrument, pas produit.
