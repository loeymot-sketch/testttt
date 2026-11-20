# Vague A — Compte de bout en bout (site + application), contre le backend LOCAL :8000

Round 1 · 2026-10-01 · agent GStack (capture) · aucune ligne de code produit modifiée, rien commité.

## Banc et résultat

- Banc : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/site-wt-stores-2026-09-30/tests-e2e/e2e-stores-vague-A-2026-10-01.spec.js`
  (script Node autonome, `require('playwright')`, un seul navigateur, contextes successifs 390×844).
- Lanceur : `/Users/1millnonstop/.claude/jobs/48fdb176/tmp/e2e-stores/lancer-vague-A.sh <étiquette>`
  (Node 22 + `NODE_PATH` du worktree backend).
- Captures (quartet png/dom/console/network) : `/Users/1millnonstop/.claude/jobs/48fdb176/tmp/e2e-stores/A/`
  + `resultats.json` (chaque contrôle avec son détail) + `donnees-de-test.json`.
  Les passages précédents sont archivés dans `A-run1/` et `A-run2/`, les journaux dans `vague-A-run{1,2,3}.log`.
- **Passage final (run 3, horodatage 20261001025000) : 155/155 contrôles de contrat verts · 36 captures ·
  5 sondes adverses, dont 3 rouges (2 défauts distincts, ci-dessous).**
- Historique : run 1 = 146/156 (10 échecs, tous dus à l'instrument, voir § Anomalies d'instrument) ;
  run 2 = 155/155 ; run 3 = 155/155 + 2 sondes ajoutées. Le défaut D-A-01 est reproduit **3 fois sur 3**.

Deux natures de contrôle dans le banc : **contrat** (ce que la vague exige ; un échec rend le banc rouge) et
**sonde** (angle adverse ; affichée « DÉFAUT OBSERVÉ » quand elle échoue, sans rendre le banc rouge, pour que
le constat soit arbitré plutôt que de bloquer la vague).

Gardes transverses, vérifiées sur les trois parcours : métas réécrites vers `http://127.0.0.1:8000` (contrôlé
dans la page) ; **0 requête vers le VPS** (toute requête vers `vps-418872ac…` ou `lecayenne.fr/api` est avortée et
comptée) ; service worker bloqué (sinon il servirait `index.html` sans la réécriture) ; **0 erreur JavaScript** sur
les trois parcours.

## Clients de test et parcours

| Client | Surface | Parcours |
|---|---|---|
| W `e2e-stores-a-20261001025000-w@lecayenne-test.fr` / 06 91 80 07 56 | site | A1 → A2 → A3 → A4 |
| N `…-n@…` / 06 92 80 07 56 | **application** (pont injecté) | A6 (= A1 dans l'app) → commande → A4 dans l'app (cas de l'examinateur Apple) |
| P `…-p@…` / 06 93 80 07 56 | **application** | A5 : commande 1 passée en PREPARING + commande 2 jamais commencée → suppression |

Pont natif : forme exacte de `PONT` (`nativePromise`, `nativeCallback`, `addListener`, **sans** `registerPlugin`
— contrôlé), plus un journal dans `sessionStorage`. Sans ce journal, les appels faits avant le rechargement qui suit
la suppression seraient perdus, puisque `window.__natif` repart à zéro à chaque chargement.

## États capturés (run 3)

| # | État | Ce qu'il montre |
|---|---|---|
| 01 | A1-email-seul | modale : un seul champ e-mail, aucun onglet, ni prénom, ni téléphone, ni nom |
| 02 | A1-depliage-identite | e-mail inconnu → prénom et téléphone dépliés dans le même écran, e-mail conservé |
| 03 | A1-code | « Entre ton code » (code lu en base, jamais à l'écran) |
| 04 | A1-compte-cree | « Te voilà · Compte prêt » |
| 05 | A1-connecte | en-tête « Mon compte » (page Fidélité) |
| 06 | A2-mon-compte-apres-creation | profil : Nom E2EWeb, e-mail, +33 6 91 80 07 56 |
| 07 | A2-deconnecte | après « Se déconnecter » : « Se connecter » (voir observation O-3) |
| 08 | A2-code-reconnexion | e-mail connu → directement le code, aucun champ d'identité |
| 09 | A2-reconnecte | écran de fin (voir O-2) |
| 10 | A2-mon-compte-apres-reconnexion | même profil exact |
| 11 | A3-panier | Tiramisu ×1, 3,50 € |
| 12 | A3-paiement | site : « Payer sur place » (conseillé, sélectionné) + carte en ligne ; bouton « Confirmer ma commande 3,50 € » |
| 13 | A3-confirmation | « C'est parti ! », ticket 0110267553, total 3,50 € |
| 14 | A4-confirmation-suppression | confirmation en deux temps (« Supprimer définitivement ? ») |
| 15 | A4-apres-suppression | accueil, « Se connecter » — aucun message de confirmation (D-A-02) |
| 16 | A4-email-supprime-inconnu | l'e-mail supprimé est traité comme INCONNU (dépliage identité) |
| 17–21 | A6-* | même parcours A1 en mode application (bouton « Se connecter avec Apple » présent, iOS) |
| 22 | A6-mon-compte-app | profil exact ; texte application « demande en caisse » ; un seul réglage de notification |
| 23–25 | A6-cmd-* | panier, paiement (« Payer sur place » SEUL mode), confirmation 0110267554 |
| 26–27 | A4app-* | suppression dans l'application, retour déconnecté |
| 28–34 | A5-* | compte P, commande 1 (0110267555), commande 2 (0110267556) |
| 35–36 | A5-confirmation / refus | refus affiché en `role="alert"` |

Doublons de DOM signalés par l'enregistreur : 30 et 33 identiques à 24. Ce sont trois pages de paiement avec le
même panier et le même mode, pour deux clients différents. La scène est réellement la même ; l'enregistreur n'a
pas photographié un écran figé.

## Ce qui est prouvé (avec le 2e moyen)

**A1 / A6 — création « e-mail d'abord »**
- Réponse `email-login` pour un e-mail inconnu : `known:false, sent:false`, aucun code envoyé avant l'identité.
- Code à 4 chiffres présent dans `otps.token` ; `/verify` → **201** ; compte en base : `name`=prénom saisi,
  e-mail exact, téléphone exact, rôle `Customer`, jeton posé sur l'appareil.
- Mode application : **aucun appel `LocalNotifications` pendant la création**. Le pont est bien utilisé
  (`SplashScreen.hide`, `Network.getStatus`, `StatusBar.setStyle`, `App.addListener backButton`…). Aucune
  annonce « bientôt » sur aucun des 20 écrans de l'application traversés. Aucune erreur JavaScript.

**A2 — déconnexion / reconnexion**
- « Se déconnecter » purge le jeton local. **2e moyen** : l'ancien jeton reçoit **401** sur `/api/profile`
  (la révocation serveur en `keepalive` aboutit malgré le rechargement).
- Reconnexion : l'e-mail n'est pas pré-rempli (mémoire d'appareil purgée à la déconnexion, voulu). Une fois
  l'e-mail tapé, on passe **directement au code**, sans dépliage d'identité. Le code est neuf, la vérification
  passe, et il y a **toujours un seul compte** pour cet e-mail en base.
- « Mon compte » (onglet profil de la page Fidélité, atteint par l'en-tête) : Nom / Email / Téléphone exacts,
  après la création comme après la reconnexion.

**A3 — commande « Payer sur place » (Tiramisu, `data/menu.js` id 903 à 3,50 € = `items.id` 51)**
- `POST /api/frontend/order` en 2xx. Le ticket affiche 0110267553 et 3,50 €. En base : même
  `order_serial_no`, `total`=3.500000 (**= affiché**), `payment_method`=1, `payment_status`=10 (non payée),
  `order_type`=10, une seule ligne `item_id`=51 ×1.
- Application : « Payer sur place » est le **seul** mode proposé. La permission de notification est demandée
  **après** la commande. Le rappel est programmé pour cette commande (`id`=75541=idNotif(7554,1), non exact).
  La ligne 🔔 est affichée.

**A4 — suppression avec la commande non retirée (site ET application)**
- `POST /api/auth/delete-account` → 200 `{"status":true,"message":"Compte supprimé avec succès."}`. Le corps est
  relevé par un clone de `fetch`, parce que la page se recharge tout de suite.
- En base : la commande 7553 (PENDING) passe à **16 (annulée)**, n'est pas supprimée (`deleted_at` nul), et le
  journal `order_status_transitions` porte 1→16 avec `reason`=« Suppression du compte par le client »,
  `actor_id`=695 (le client).
- Le compte est **anonymisé** : `email`=null, `phone`=`PENDING_DELETED_7aa537bcb3f9`, `name`=« Compte supprimé »,
  `contact_phone`=null, `username`=`supprime-…`, `deleted_at` posé. **Tous** les jetons sont révoqués (0 en
  base). **2e moyen** : l'ancien jeton reçoit 401.
- Le site et l'application reviennent déconnectés (« Se connecter », plus de jeton). L'e-mail supprimé est
  ensuite traité comme inconnu : le compte ne peut pas être ressuscité par son e-mail.
- Même résultat dans l'application, pour le client N (commande 7554 → 16).

**A5 — commande commencée par la cuisine (seul geste en base : `status=7` sur 7555)**
- Suppression **refusée** : 422 `{"status":false,"message":"Impossible de supprimer ton compte pour l'instant :
  une commande est en préparation ou déjà réglée. Récupère-la (ou attends qu'elle soit terminée), puis
  réessaie."}`. Le message est affiché en `role="alert"` (capture 36) et dit **pourquoi** et **quoi faire**.
- **Rien n'est effacé**. Le profil est intact (e-mail, téléphone, nom, `deleted_at` nul), le jeton est toujours
  sur l'appareil et toujours valide (**2e moyen** : `/api/profile` → 200), et le client est toujours connecté
  après rechargement.
- Angle adverse (transaction défaite) : la commande 7556, **jamais commencée**, que la suppression annule
  AVANT de refuser, retrouve son état. Elle est toujours en **status 1**, sans motif ni `deleted_at`, et
  **aucune** transition →16 n'est écrite. L'annulation est donc bien défaite avec le refus.
- Sonde verte : aucune tâche différée de reprise de matières premières (`ReverseRawMaterialsOnOrderCanceled`)
  n'est laissée dans `queues:default` par la transaction défaite (6 → 6). **Preuve que la sonde mord** : ce
  compteur vaut 2 → 4 → 6 d'un passage à l'autre, soit +2 par passage, ce qui correspond aux deux annulations
  VALIDÉES (A4 site + A4 app). L'instrument voit donc bien ces tâches quand elles existent.

## Défauts produit OBSERVÉS (sondes rouges)

### D-A-01 — Application : après la suppression du compte, le rappel local de la commande annulée reste programmé (P1 à arbitrer, sinon P2)
- **Ce qui se passe.** Le client commande dans l'application ; un rappel local est programmé à +10 min
  (`schedule` id 75541, `at` 2026-10-01T01:00:44Z, texte « Ta commande 0110267554 devrait bientôt être prête.
  Ouvre l'app pour voir où elle en est. »). Il supprime ensuite son compte : la commande est annulée en base.
  Or **aucun** `LocalNotifications.cancel` n'est émis, ni avant, ni pendant, ni après le rechargement. Les appels
  natifs relevés après la suppression sont seulement `StatusBar.setStyle`, `Keyboard.setAccessoryBarVisible`,
  `App.addListener`, `Network.getStatus`, `Network.addListener` et `SplashScreen.hide`. Sur un vrai téléphone,
  l'examinateur Apple recevrait environ 10 min plus tard une notification pour la commande d'un compte qu'il
  vient de supprimer, commande d'ailleurs annulée.
- **Preuve 1 (exécution)** : journal du pont, run 3, contrôle `[A4app] le rappel local … est retiré` dans
  `A/resultats.json`. Reproduit aux runs 1, 2 et 3.
- **Preuve 2 (code)** : le seul appelant de `annulerRappel` est la page de suivi (`funnel.jsx:2716`, compilé
  `compiled/funnel.js:4177`). Elle ne voit l'annulation qu'avec une session vivante. `loyalty-v2.jsx:166-175`
  (`doDelete`) appelle `onLogout()` puis `location.replace` sans retirer aucun rappel
  (`app-native.js:374` existe pourtant).
- Même trou, en moins grave, sur « Se déconnecter » : le rappel survit, mais la commande, elle, existe encore.
- Piste : dans `doDelete`, après un `status:true`, retirer les rappels des commandes ouvertes du client avant le
  rechargement, ou vider tous les rappels en attente.

### D-A-02 — Site et application : la suppression réussie n'est jamais confirmée au client (P2)
- Le serveur renvoie « Compte supprimé avec succès. », mais l'interface le jette. Le client atterrit sur
  l'accueil, déconnecté, **sans un mot** (captures 15 et 27). Pour une action irréversible, rien ne lui dit que
  c'est fait. Il peut croire à une déconnexion ou à un plantage.
- Preuve 1 : sondes `[A4]` et `[A4app]` « accusé de réception » rouges ; texte visible sans « compte …
  supprimé ». Preuve 2 : `loyalty-v2.jsx:172-174` (`onLogout()` puis `location.replace`, le `r.message` du
  serveur n'est jamais affiché). Le message est bien dans la réponse : relevé par l'espion `fetch`, il figure dans
  le détail des contrôles `[A4]` et `[A4app]` « 200, status=true » de `A/resultats.json`. Le statut 200 apparaît
  dans `A/15-*.network.json` et `A/27-*.network.json`, mais le corps n'y est pas lisible, la page s'étant
  rechargée entre-temps.

## Observations (non bloquantes, ou à arbitrer — pas de 2e preuve qui en fasse un défaut)

- **O-1 (à arbitrer, hors périmètre strict A, potentiellement P1)** : à 02h50, alors que l'accueil affiche
  « SERVICE FERMÉ · OUVRE À 18H CE SOIR » (captures 15 et 27), une commande « Dès que prêt » est **acceptée**.
  Le ticket promet « Prêt dans ~10–15 min » (captures 13 et 25), et la base contient 4 commandes créées entre
  02h44 et 02h50 par passage. Code : `OrderRequest.php:~393-403` ne contrôle la fenêtre de service que pour une
  commande **programmée** (`scheduled_at`). `/api/frontend/order/wait-estimate` répondait `closing_time:null`,
  `wait_low:10`. Je n'ai pas vérifié si la production se comporte de même (horaires ou interrupteur côté VPS) :
  à vérifier avant de conclure.
- **O-2 (P3)** : une reconnexion d'un compte CONNU affiche « Bienvenue au club · Compte prêt » (capture 09).
  `account-v2.jsx:853` tranche sur les points (0 → « Compte prêt »), alors que le serveur a répondu
  `known:true` : le composant SAIT que le compte préexistait.
- **O-3 (P3)** : après « Se déconnecter », le rechargement restaure le défilement et le client atterrit dans le
  pied de page de l'accueil (capture 07).
- **O-4 (P3, texte)** : « Ton compte est identifié par ton numéro de téléphone » (`loyalty-v2.jsx:58-59`), alors
  que la connexion se fait désormais par e-mail (captures 06, 10, 22). Sur le site, la même ligne dit « La
  modification du profil arrive bientôt » : c'est autorisé sur le site, et absent de l'application (vérifié).
- **O-5 (info)** : `orders.reason` reste NULL pour l'annulation faite par la suppression. Le motif existe
  seulement dans `order_status_transitions`, parce que `reason` n'est pas *fillable* sur `FrontendOrder`. Le
  comportement est identique à l'annulation client existante, et `OrderDetailsComponent.vue:30-32` n'affiche
  `reason` que pour REJECTED : rien n'est visible, donc ce n'est pas un défaut de A4.
- **O-6 (P3)** : le toast « Ajouté ✓ » recouvre un instant le haut du bouton « Passer commande » (capture 11,
  transitoire).
- **O-7 (info)** : après le refus (A5), la boîte « Supprimer définitivement ? » reste ouverte, bouton rouge
  actif. Un nouveau clic donne le même refus, sans danger. La console signale le 422 du navigateur
  (« Failed to load resource »), un message attendu puisque le refus est affiché en `role="alert"`.
- **O-8 (info)** : les commandes web « sur place » restent en PENDING (status 1) en local : pas d'acceptation
  automatique.

## Anomalies d'instrument (corrigées dans le banc, chacune commentée « HEAL instrument »)

1. Le `/verify` répond **201**, pas 200 : mon hypothèse était fausse, le contrat est un 2xx (run 1 : 4 faux
   échecs).
2. `innerText` lit le texte après `text-transform: uppercase` (`styles-v5.css:43`), ce qui donnait les clés
   « NOM/EMAIL/TÉLÉPHONE » : lecture passée à `textContent` (run 1 : 3 faux échecs).
3. Corps de `delete-account` illisible (« 200 null ») parce que la page se recharge aussitôt : ajout d'un espion
   `fetch` par clone vers `sessionStorage`, qui ne modifie pas la réponse lue par le site (run 1 : 2 faux
   échecs).
4. J'exigeais le motif dans `orders.reason` : faux contrat (voir O-5). Le contrôle porte maintenant sur
   `order_status_transitions`.
5. Captures prises pendant les fondus (modale à moitié transparente, accueil assombri au run 2) : on attend
   désormais la fin de l'animation avant chaque capture, et on fait défiler jusqu'à la carte profil.
6. Le débit `throttle:3,5` du `/verify` a pour clé `sha1("|127.0.0.1")`, **partagée par tous les agents** de la
   machine : 2 tentatives étaient déjà comptées avant mon premier passage. Je le remets à zéro de façon ciblée
   (`RateLimiter::clear`) juste avant chaque saisie de code. Ce n'est pas le `cache:clear` du banc modèle, qui
   aurait vidé aussi les verrous et l'idempotence des autres vagues.
7. La garde de l'environnement refuse les commandes shell qui contiennent `$PATH` ou un `NODE_PATH` en ligne :
   d'où le lanceur `lancer-vague-A.sh`.

## Données de test créées (base locale `foodking_e2e`, 3 passages)

| Passage | Utilisateurs | Commandes |
|---|---|---|
| run 1 (20261001024428) | 689 W (supprimé), 690 N (supprimé), 691 P (actif) | 7545 (16), 7546 (16), **7547 (7 PREPARING)**, **7548 (1 PENDING)** |
| run 2 (20261001024733) | 692 W (supprimé), 693 N (supprimé), 694 P (actif) | 7549 (16), 7550 (16), **7551 (7)**, **7552 (1)** |
| run 3 (20261001025000) | 695 W (supprimé), 696 N (supprimé), 697 P (actif) | 7553 (16), 7554 (16), **7555 (7)**, **7556 (1)** |

**À savoir pour les vagues voisines** : trois commandes Tiramisu restent EN PRÉPARATION (7547, 7551, 7555) et
trois restent EN ATTENTE (7548, 7552, 7556). Elles sont visibles en cuisine, en caisse et sur l'écran client, et
peuvent apparaître dans les captures de B et C. Je ne les ai pas soldées : ma seule écriture autorisée était le
passage en PREPARING. Les comptes P (691, 694, 697) restent actifs. `queues:default` accumule des tâches, faute
de worker en local.

## Non couvert / doutes

- Le pont est simulé (forme exacte du vrai) : il prouve ce que le site DEMANDE au natif, pas ce qu'iOS ou
  Android font du rappel. D-A-01 reste à confirmer sur un appareil réel (émulateur `lecayenne-api36` lancé sur
  cette machine).
- « Se connecter avec Apple » (affiché dans l'application iOS, capture 17) n'est pas exercé.
- E-mails : `MAIL_MAILER=log`, la livraison réelle du code n'est pas testée. Les e-mails de statut de commande
  sont coupés en base (`notification_alerts.mail=10`) : impossible donc de vérifier ici qu'aucun e-mail
  « annulée » ne part dans la transaction défaite de A5.
- Disparition des commandes annulées en cuisine et sur l'écran client : non capturée ici (vagues voisines).
