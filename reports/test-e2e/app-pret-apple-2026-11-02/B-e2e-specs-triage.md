# B — Triage des specs E2E du candidat « app-pret-apple-2026-11-02 »

- **Candidat** : worktree `/Users/1millnonstop/Downloads/lecayenne-web-deploy/app-pret-apple-2026-11-02`, HEAD `bc645b3`, servi sur `http://127.0.0.1:8931`.
- **Date** : 2026-11-02. Node v22.23.2, Playwright 1.58.2 (`app/node_modules`). Au plus 2 navigateurs en même temps.
- **Code produit** : aucune modification. Les specs ont été **copiées** dans un miroir (`/private/tmp/claude-501/app-e2e-triage/rc/`, liens symboliques vers le worktree), pour que leurs captures n'écrivent pas dans le dépôt.
- **Journaux, captures et scripts de reproduction** : `/private/tmp/claude-501/app-e2e-triage/` (`logs/`, `cap/`, `repro-*.js`, `diag-*.js`).

## 0. Sécurité production

Toutes les exécutions ont été lancées avec un filet chargé par `node -r` (`garde-prod.js`). Il pose une route au niveau du contexte : toute requête **non-GET** vers `vps-418872ac.vps.ovh.net`, `lecayenne.fr` ou `*.vercel.app` qu'un banc n'a pas simulée est **avortée**.

- **Preuve que le filet fonctionne** : `preuve-garde.js`. Un POST vers `/api/frontend/order` donne `Failed to fetch`, et le journal note `BLOCKED POST`.
- **Bilan** : 0 requête bloquée. 310 GET publics en lecture seule sont passés (catalogue, statut de livraison, configuration fidélité ; un `order/show/4243` fictif, voir abandon-paiement).
- **Non lancés** : `*.REAL-ORDER.*` (3) et `prod-toutes-pages.regression.js`.
- **Écritures en base locale de dev (`:8766`)** : seuls `compte-email-dabord` et `compte-serveur-en-retard` y ont écrit. Elles ont créé des demandes de code de test (`@lecayenne-test.fr`).
- **Serveurs annexes** : deux serveurs temporaires, que j'ai arrêtés à la fin.
  - `:8899` servait le candidat, pour les specs dont le port est codé en dur.
  - `:8000` était un mandataire **GET seulement** vers `:8766`, pour l'instrument de la vague C.

## 1. Totaux

| | Nombre |
|---|---|
| Specs lancées | **59** |
| Vertes | **40** |
| Rouges | **19** |
| Non lancées (risque prod) | 4 (`order-live.REAL-ORDER.js`, `order-live-onepage-2026-08-03.REAL-ORDER.js`, `wait-slots-2026-07-28.REAL-ORDER.spec.js`, `prod-toutes-pages.regression.js`) |
| Non lancées (prérequis absent) | 2 (`e2e-stores-vague-A`, `e2e-stores-vague-B` — voir §4) |
| Exclues par consigne | `.local.js` ×7, `.mesure.js` ×2, `.PENDING-BANC.js` ×1 (`audit-surfaces-s4` et `verif-seo.mjs` ne sont pas des specs) |

Classement des 19 rouges (cause principale ; certaines specs relèvent de deux causes) :

- **(c) défaut produit réel** : 7. Ce sont app-natif-honnete, hors-service-et-suppression, numero-appele, onglet-perime-et-compte, collants-et-pied-de-page, e2e-stores-vague-C et residus-honnetete. Pour residus, la spec est aussi périmée.
- **(b) environnement ou instrument** : 4. Ce sont compte-email-dabord, compte-serveur-en-retard, bol-boisson-qty et la partie 2c d'abandon-paiement.
- **(a) banc périmé** : 9. Ce sont abandon-paiement (contrôles de source), carrousel-cartouche, crudites-tacos, fiches-comparatif, filtres-balayage, garde-audit, parite-borne, wizard-goal et roue-experience.

## 2. Défauts produit réels (c) — tous reproduits par un second moyen indépendant

### Cause racine commune R1 : le commit `68442b0` (site, 24/10, « livraison de bout en bout ») a ÉCRASÉ `funnel.jsx`, `components.jsx` et `screens.jsx` avec une version antérieure à la ligne « app »

On a mesuré la part des lignes ajoutées par chaque commit de la ligne app qui existe encore dans le HEAD :

| Commit | funnel.jsx | components.jsx | screens.jsx |
|---|---|---|---|
| cac473a | 1/19 | 1/14 | 0/2 |
| 4050155 | 3/25 | | |
| de1ff84 | 0/9 | | |
| d0acc91 | 1/8 | | |
| affe0ab | 2/3 | | |
| fe3d108 | | 0/5 | |
| 47473d8 | 0/3 | | |

En revanche, `app-native.js`, `loyalty-v2.jsx`, `racine.jsx` et `account-v2.jsx` sont intacts. `git log -S` désigne `68442b0` comme le commit qui retire `EN_APPLICATION`, `RESEAUX_AFFICHES`, `rappelerCommande` et `signalerCommandePrete`. Ce commit est un ancêtre de HEAD (fusion `19b4531`).

### D1 — P0 — « Mon compte » = écran blanc (site ET application), donc la suppression de compte est inatteignable

- **Où** :
  - `loyalty-v2.jsx:59`, `:101` et `:134` lisent `EN_APPLICATION`.
  - Plus aucun fichier ne la définit. La définition a été retirée de `components.jsx` par `68442b0`.
  - Le défaut est présent tel quel dans `compiled/loyalty-v2.js:111,167,243`, et dans `app/www/compiled/*`, qui est identique octet pour octet.
- **Effet** : à l'ouverture de l'onglet « Mon compte », React lève `ReferenceError: EN_APPLICATION is not defined` et `#root` est vidé (0 enfant, 0 caractère). « Se déconnecter » (`loyalty-v2.jsx:212`) et « Supprimer mon compte » (`:215`) deviennent inatteignables. Dans l'application, c'est un rejet Apple 5.1.1(v).
- **Repro 1 (bancs)** :
  - `app-natif-honnete` §9 et §10 : « EN_APPLICATION is not defined ».
  - `hors-service-et-suppression` : `:152`, le bouton « Supprimer mon compte » est introuvable et la spec s'interrompt.
  - `onglet-perime-et-compte` §3 : « Se déconnecter » n'est pas atteint.
  - `e2e-stores-vague-C` : A-PARCOURS et M-PARCOURS échouent sur `[role=tab] Mon compte`, avec l'instrument réparé.
  - `residus-honnetete` : page vide.
- **Repro 2 (indépendante)** : `repro-moncompte.js`, navigateur bureau, sans helper de banc.
  - WEB : `texteApres: 16`, `root.enfants: 0`, `erreurs: ["EN_APPLICATION is not defined"]`.
  - APP (android) : même résultat.
  - Capture : `repro-moncompte-web.png`, écran entièrement blanc.
- **Production** : un GET public de `https://www.lecayenne.fr/compiled/loyalty-v2.js` montre 3 occurrences de `EN_APPLICATION`, contre 0 dans `components.js`. Les jetons `?v=20261029appstore2` sont les mêmes. **Le site en ligne a le même écran blanc.**

### D2 — P1 (app) — aucun rappel local, aucune alerte « prête », aucun retrait du rappel à l'annulation

- **Où** : `app-native.js:459` (`rappelerCommande`), `:487` (`annulerRappel`) et `:521` (`signalerCommandePrete`) existent. Aucun fichier `.jsx` ne les appelle (`grep` = 0). Les appels avaient été ajoutés dans `funnel.jsx` par cac473a et 4050155, puis effacés par R1.
- **Repro 1** :
  - `app-natif-honnete` §2, §5 et §6 : `écart=null`, `id=undefined`, `[]`.
  - `numero-appele` §2 : aucun rappel natif n'est programmé.
  - `vague-C` A-suivi-8-natif : « appels natifs : » vide.
- **Repro 2** : statique. `grep rappelerCommande|signalerCommandePrete|annulerRappel *.jsx compiled/*.js` ne trouve rien.
- **Impact** : la fiche du store promet « prévenu quand elle est prête », ce qui est faux (Apple 2.3 / 4.2). S'y ajoutent les régressions affe0ab : « Ta commande est prête ! » coupée avant le « ! », et un QR de retrait encore proposé sur une commande annulée.

### D3 — P1 (Android) — RETOUR matériel avec le tiroir ouvert : l'application se ferme

- **Où** : `app-native.js:285` émet `lc:retour`, mais aucun écouteur n'existe. `components.jsx:161` ne gère plus que `lc-nav-open` ; l'écouteur de cac473a a été effacé.
- **Repro 1** : `app-natif-honnete` 0c, « le tiroir se FERME » ✗ et « l'app ne se ferme PAS » ✗.
- **Repro 2** : `repro-app-regressions.js` donne `{"ouvertAvant":true,"ouvertApresRetour":true,"exitAppAppele":true}`.

### D4 — P1 (app) — l'application annonce encore « bientôt » et montre l'onglet « Avis » (page d'attente)

- **Où** : `components.jsx:420` (« Instagram, TikTok et Snapchat arrivent bientôt. ») et `components.jsx:204`. Le filtre `EN_APPLICATION` sur les réseaux et l'onglet `avis` a été effacé par R1. Risque Apple 2.1 (contenu provisoire).
- **Repro 1** :
  - `app-natif-honnete` §7 : `{"tuiles":4,"bientot":3,"note":true}`.
  - `app-natif-honnete` §9 : « bientôt » sur home, menu, réseaux, commandes et fidélité.
  - `vague-C` : constat « bientôt » sur chaque écran des modes A, SE1, SE3 et AND.
- **Repro 2** : `repro-app-regressions.js` donne `pied.note = "Instagram, TikTok et Snapchat arrivent bientôt."`.
- **Note** : le §9 « pas d'onglet Avis » ressort ✓ uniquement parce que la page s'est vidée après D1. Ce ✓ ne prouve rien.

### D5 — P2 — le ticket ne montre pas le numéro appelé au comptoir, et dit « Envoyée en cuisine »

- **Où** :
  - `funnel.jsx:2768` et `:2809` affichent `{orderId}`, c'est-à-dire la série. `ctx.orderQueue` est bien rempli (`funnel.jsx:1326`) mais n'est jamais rendu.
  - `funnel.jsx:2848` affiche « ✓ Envoyée en cuisine ».
  - Le correctif d0acc91 / de1ff84 a été effacé par R1.
- **Repro 1** : `numero-appele`, 7/14. Le ticket affiche `"0110269301"` au lieu de `"N°A0051"`, et le titre reste « Ta commande #0110269301 est envoyée ».
- **Repro 2** : statique. `grep orderQueue funnel.jsx` ne trouve que les lignes 1326 et 1416, toutes deux des écritures, aucun rendu.

### D6 — P2 (mobile) — les catégories collantes passent 13 px sous l'en-tête

- **Où** : `styles-mobile.css:351` utilise `var(--entete-h, calc(var(--nav-h-sm) + var(--navx-h)))`. Le code qui mesurait et publiait `--entete-h` (fe3d108, `components.jsx`) a été effacé par R1. Le repli vaut donc 116 px, alors que l'en-tête réel mesure 129 px.
- **Repro 1** : `collants-et-pied-de-page` §1 donne `{"hautCategories":116,"basEntete":129}`.
- **Repro 2** : `repro-app-regressions.js` donne `varEnteteH: "(non définie)"`, `.lc-cat-sticky top 116`, `basEntete 129`.

**Correctif recommandé (hors de mon périmètre)** : ré-appliquer les hunks app de cac473a, 4050155, de1ff84, d0acc91, affe0ab, fe3d108 et 47473d8 dans `funnel.jsx`, `components.jsx` et `screens.jsx`, puis recompiler et bumper les `?v=`. Rejouer ensuite `app-natif-honnete`, `numero-appele`, `hors-service-et-suppression`, `onglet-perime-et-compte`, `collants-et-pied-de-page` et `vague-C`. **D1 touche aussi la production actuelle.**

## 3. Tableau par spec

Les comptes ✓/✗ sont relevés dans la sortie de chaque banc. Quand un banc s'interrompt, il n'imprime pas toujours son bilan ; c'est noté « interrompu ».

| Spec | ✓ / ✗ | Classement | Détail |
|---|---|---|---|
| app-natif-honnete-2026-09-30 | 42 / 25 | **(c)** D1, D2, D3, D4 | 0c retour tiroir ; §2 rappel ; §5 et §6 alerte, annulation, « ! » et QR ; §7 et §9 « bientôt » ; §9 et §10 ReferenceError. Le pont simulé a la forme exacte du vrai (sans `registerPlugin`), ce que confirme le témoin §0. |
| finitions-store-2026-10-02 | 21 / 0 | vert | |
| hors-service-et-suppression-2026-10-01 | interrompu | **(c)** D1 | `:152`, « Supprimer mon compte » introuvable : l'écran Mon compte s'est vidé. Les sections 1 à 4 ne sont pas imprimées. |
| compte-memoire-appareil-2026-09-02 | 14 / 0 | vert | |
| compte-email-dabord-2026-09-02 | interrompu | **(b)** | Le backend local `:8766` a `MAIL_MAILER=smtp` vers `smtp.example.com`. `laravel-2026-11-02.log:361` contient `guest_signup.email_login_failed … smtp.example.com:587`, et l'écran affiche « Le code n'a pas pu être envoyé ». Le prérequis est `MAIL_MAILER=log` (en-tête de la spec, ligne 15). |
| compte-serveur-en-retard-2026-09-02 | 13 / 3 | **(b)** | Même cause (`laravel…log:362`, `code_send_failed`). La bascule 405 vers l'ancienne route est bien prouvée ✓. |
| version-minimale-2026-10-01 | 43 / 0 | vert | |
| numero-appele-2026-10-01 | 7 / 7 | **(c)** D5, D2 | |
| onglet-perime-et-compte-2026-10-01 | 20 / 2 | **(c)** D1 | §1 et §2 sont verts. §3 : « Se déconnecter » se trouve dans Mon compte, qui plante. |
| collants-et-pied-de-page-2026-10-02 | 10 / 1 | **(c)** D6 | |
| suivi-reglement-livraison-2026-11-01 | ✓ / 0 | vert | |
| e2e-stores-vague-C-2026-10-01 | 34 gardes ✗, puis 1 (M) et 2 (A) après réparation de l'instrument | **(c)** D1, D2, D4, plus (b) au 1er passage | Passage 1 : `:8000` absent, le catalogue factice ne contient pas Tacos L, donc Tacos L n'est jamais ajouté et aucune commande n'est émise (34 gardes, instrument). Passages avec un mandataire GET `:8000→:8766` : panier, commande et confirmation sont ✓ ; il reste A/M-PARCOURS (`:811`, Mon compte = D1) et A-suivi-8-natif (D2). Constats « bientôt » en mode app (D4). |
| abandon-paiement | 20 / 5 (22 / 3 au 3ᵉ passage) | **(a)** + **(b)** | (a) 3 contrôles de source périmés : la logique de démarrage est passée de `index.html` à `racine.jsx:405-435` et `:714` (`onRepay`), et la garde du badge est devenue `['unpaid','canceled','unknown','checking'].indexOf(ctx.mollieReturn) === -1` (`funnel.jsx:2846`). (b) 2c intermittent : la spec remplace `LC.api.getOrder` par un `setInterval` de 20 ms, ce qui crée une course. Au passage rouge, le journal du filet montre un vrai `GET …/order/show/4243` parti en production. Le produit est correct : `diag-paid.js` et `diag-paid-reel.js` donnent `#confirm` et « Paiement confirmé ✓ », 3 fois sur 3. |
| allergie-survit-au-refus | vert | vert | |
| applepay-feuille-native | 17 / 0 | vert | |
| attente-429-selon-la-route | vert | vert | |
| bol-boisson-qty-s8 | 0 / 1 | **(b)** | Dumps de production absents (`/tmp/fk-s8/vps-items.json`, ligne 22). Je ne les ai pas régénérés. |
| carrousel-cartouche-suit-la-photo | 2 / 4 | **(a)** | Ligne 42 : la regex `src:'…'[\s\S]{0,80}?name:` ne franchit plus le nouveau champ `set:` (srcset WebP, dc12d8d). REEL est donc lu vide, la durée de mesure tombe à 1,5 s et 0 rotation est vue. Le vrai contrôle (cartouche ≥ 40 %) est ✓. |
| compteurs-articles, contraste-cta, coordonnees-erreurs, creneaux-non-recouverts, css-regles-avalees, filtres-menu, ghost-ticket-s4, h1-prerendu, heals-red, horaire-fuseau-paris, one-page-checkout, option-caisse-seule, pages-seo-navigateur, panier-modifier-abus, panier-ttl-s8, polices-sous-repertoire, produit-epuise-inerte, promo-message-precis, rebours-ouverture, robots-groupes-complets, roue-2026-08-09, roue-fond-carrousel-redirection, roue-journey, roue-lots-bandeau, roue-mouvement, suivi-temps-restant-s4, titre-hero, upsell-choix-obligatoire, upsell-epuise, ux-continuity, vue-moteurs-reponse, wallet-bouton-officiel, wizard-cartes-immobiles | tous ✗ = 0 | vert (33) | |
| crudites-tacos-legumes | 23 / 1 | **(a)** | Ligne 105 : `grab('galette-normale')`. Ce slug n'existe plus dans `data/menu.js` (`galette-classique` id 201, `:544`). |
| fiches-comparatif-coherent | 105 / 1 | **(a)** (donnée d'outil) | `tools/seo/catalogue-extrait.json` porte encore `galette-normale`, alors que `plat/galette-classique.html` existe et figure au sitemap. Il faut régénérer l'extrait SEO. Ce n'est pas visible par le client ; à noter, la page orpheline `plat/galette-normale.html` est toujours servie. |
| filtres-balayage | 3 / 1 | **(a)** sous réserve | Ligne 51 : `nbFiltres === 3`. Il y en a désormais 4, dont « ✨ Nouveautés », ajouté par 68442b0. Aucune combinaison ne vide la carte ✓. **À confirmer par le propriétaire** : l'ajout de « Nouveautés » est-il voulu ? |
| garde-audit | 33 / 2 | **(a)** | (1) Lignes 72-77 : `s-poivre` et `s-burger` n'existent plus au menu. J'ai vérifié que **toutes** les images de `data/menu.js` existent. (2) Lignes 321-329 : `.lcf-tabs` n'existe plus depuis l'écran « e-mail d'abord » sans onglets (02/09). |
| parite-borne-s8 | interrompu (420 s) | **(a)** | Ligne 166 : `button:has-text("Commander")` résout sur `.lc-annonce-cta` (annonce livraison, 24/10), masquée par la fiche produit. Le clic échoue et le wizard ne s'ouvre jamais. La boucle de la ligne 175 empile ensuite 16 × 30 s d'attente sur `.lc-wiz-title` (trace `DEBUG=pw:api`). |
| wizard-goal-2026-07-28 | interrompu (420 s) | **(a)** | Même sélecteur, ligne 27 (`button:has-text("Personnaliser"), button:has-text("Commander")`), même blocage. |
| residus-honnetete | 10 / 1 | **(a)** + **(c)** D1 | Ligne 16 : il attend « Notifications », bloc volontairement masqué dans un navigateur (piège connu). Aujourd'hui la page est de toute façon vide (D1), si bien que « plus aucun SMS » est ✓ à vide. |
| roue-experience | interrompu | **(a)** | Ligne 87 : `focus('#son')` puis Tab doit atteindre `#retenter`. Un `<summary>Comment récupérer mon cadeau ?</summary>` a été inséré entre les deux (`roue.html`). Diagnostic indépendant (`diag-roue.js`) : un clic sur `#retenter` relit la configuration une fois et active « DÉCOUVRIR MON TOUR ». La reprise fonctionne. |

## 4. Specs non lancées

- **`e2e-stores-vague-A` et `e2e-stores-vague-B`** : il leur faut le backend `qa-corrige-2026-09-28` servi sur `:8000` avec `MAIL_MAILER=log`. Ces bancs créent des comptes et des commandes, et passent une commande en PREPARING. Or `:8000` n'est pas servi, et le backend disponible (`:8766`) envoie ses e-mails à `smtp.example.com`, ce qui fait échouer toute création de compte (voir compte-email-dabord). Les relancer aurait seulement reproduit le constat (b). Classement **(b)**. Leur pont simulé n'expose pas `registerPlugin` (témoins `:645` et `:301`).
- **REAL-ORDER ×3 et `prod-toutes-pages`** : risque production, non lancés par consigne.

## 5. Ponts Capacitor simulés

Les 7 specs qui simulent le pont (`app-natif-honnete`, vague A, B et C, `hors-service`, `numero-appele`, `version-minimale`, `onglet-perime`) ont la forme du vrai pont : `nativePromise`, `nativeCallback`, `addListener`, sans `registerPlugin`. **Aucune spec ne teste un chemin que l'application réelle ne prend pas.** La vague C le vérifie même au démarrage (ligne 92).

## 6. Bancs périmés : correctif d'une ligne

| Spec | Correctif |
|---|---|
| abandon-paiement:58-60 | Lire `racine.jsx` au lieu de `index.html`. |
| abandon-paiement:55 | Accepter la forme `['unpaid',…].indexOf(ctx.mollieReturn) === -1`. |
| abandon-paiement (2b et 2c) | `page.route('**/api/frontend/order/show/*')` au lieu de remplacer `LC.api.getOrder` par un `setInterval`. |
| carrousel-cartouche:42 | Élargir la fenêtre de la regex (`{0,400}`) ou lire `name:` sans borne. |
| crudites-tacos-legumes:105 | `grab('galette-classique')`. |
| fiches-comparatif | Régénérer `tools/seo/catalogue-extrait.json` (et retirer `plat/galette-normale.html`). |
| filtres-balayage:51 | `nbFiltres === 4`, si « Nouveautés » est validé par le propriétaire. |
| garde-audit:72-77 | Vérifier l'existence de **toutes** les images de sauces au lieu de `s-poivre` / `s-burger`. |
| garde-audit:321-329 | Supprimer ce contrôle, qui porte sur l'ancienne modale à onglets (`.lcf-tabs`). |
| parite-borne-s8:166 | Cibler `.lcx-detail-cta:has-text("Personnaliser")` et donner un `timeout` court au `textContent` de la ligne 176. |
| wizard-goal:27 | Même ciblage `.lcx-detail-cta`. |
| residus-honnetete:16 | Prendre pour garde un élément réellement affiché dans un navigateur (« Zone dangereuse » ou « Se déconnecter »), pas « Notifications ». |
| roue-experience:87 | `focus('#retenter')` directement, ou deux Tab. |
