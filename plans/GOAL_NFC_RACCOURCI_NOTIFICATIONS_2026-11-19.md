# GOAL — Carte NFC, raccourci sur l'écran d'accueil, notifications et suivi en direct

> Version 2 (détaillée et déterministe) — 2026-11-19. **EXÉCUTÉ localement le 2026-11-19/20 (voir §E en fin de document) ; NON déployé.**
> Demande du propriétaire : une carte NFC qui pose le site comme une application sur le téléphone du client ; des
> notifications une fois installé et autorisé ; les points de fidélité et le statut de commande qui se mettent à jour
> seuls (« actuellement c'est statique, il doit rafraîchir lui-même ») ; le tout qui tient aux mises à jour.
> Chaque tâche ci-dessous dit : quel fichier, quoi exactement, quel test la prouve. Aucun choix n'est laissé à l'exécutant
> hors des portes §G.

---

## §0 — Préambule

### 0.1 Arbres de travail (décidé)
| Dépôt | Ligne à utiliser | Preuve |
|---|---|---|
| Serveur `testttt` | `pos/category-first-caisse-2026-06-23` (prod = `6999ddd8`) | `ssh lecayenne git rev-parse` du 2026-11-18 |
| Site | `origin/app/catalogue-distant-2026-11-05` @ `3eb0ae2` — c'est la ligne EN LIGNE | les `?v=` de `https://www.lecayenne.fr/` = ceux de `lecayenne-web-deploy/site-copie-3eb0ae2/index.html` |

- Le dossier `Site lecayenne` est sur `fix/paiement-visible-et-encoche-2026-09-28` (7 commits d'avance, dont `189d0fe`
  « suivi 5 s / fidélité 20 s » qui **n'a jamais atteint la ligne en ligne**). Ne pas y travailler, ne pas le fusionner en bloc.
- Créer : `git -C "Site lecayenne" worktree add ../site-nfc-2026-11-19 -b feat/nfc-notifications-2026-11-19 origin/app/catalogue-distant-2026-11-05`.
- Serveur : commits par chemins explicites (`git commit -- <chemins>`), `PROJECT_BRAIN.md` reste non commité.

### 0.2 Ordre de déploiement (invariable)
Serveur d'abord (`bash tools/deploy-lecayenne.sh <sha>`), site ensuite (Vercel depuis une copie sans `.git`,
`--archive=tgz`). Le site ne doit jamais appeler une route absente : chaque appel nouveau tolère un 404.

### 0.3 Interdits
- Aucun banc de paiement sur `:8000` / `:8766` (clé Mollie réelle dans `.env` local).
- Aucune zone gelée : `OrderStateMachine`, `PricingService`, services fiscaux ne sont PAS modifiés (le plan écoute
  l'événement `OrderStatusChanged`, il ne touche pas à qui le déclenche).
- Jamais de montant dépensé dans un texte (décision 2026-11-06). Points seulement.
- Aucune écriture dans la base de production hors migrations du déploiement.
- Jamais `composer dump-autoload` sur un serveur de dev en marche.

### 0.4 Exécution et convergence
Chaque tâche passe par `ultra-audit-profond`. Une vague est close quand deux tours adverses consécutifs donnent
P0+P1 = 0 avec le même ensemble de constats. Trois échecs de correction sur le même sujet → arrêt et remontée.

---

## §1 — État réel ancré le 2026-11-19

### 1.1 Site (ligne en ligne `3eb0ae2`)
| Brique | Constat | Ancre |
|---|---|---|
| Manifeste | présent : `id "/"`, `start_url "/"`, `display standalone`, icônes 192/512 + maskable | `manifest.webmanifest` |
| Balises iPhone | `apple-mobile-web-app-capable`, `apple-touch-icon` | `index.html:126-131` |
| Service worker | minimal, `VERSION = 'lc-v2-2026-09-29'`, `skipWaiting` + `clients.claim`, ne touche jamais une autre origine | `sw.js:38`, `sw.js:60-131` |
| Cache de `sw.js` chez l'hébergeur | déjà `max-age=0, must-revalidate` | `vercel.json:91-98` |
| Enregistrement | navigateur seulement, HTTPS | `app-native.js:179-181` |
| Réception de notification | **absente** | `grep "push\|notificationclick" sw.js` → vide |
| Bouton d'installation | **absent** | `grep beforeinstallprompt *.jsx *.js` → vide |
| Écran de confirmation | suit la commande par `api.getOrder`, reprogrammé, lit `loyalty_points_awarded` | `funnel.jsx:3728-3760` |
| Historique | relu 15 s, seulement connecté + commande active + page visible | `orders.jsx:120-129` |
| Page fidélité | relue 20 s (site) / 8 s (app), page visible | `screens.jsx:1965-1976` |
| Autres lecteurs du solde | panier/paiement (`funnel.jsx:773`, `:3849`) relus sur `loyaltyNonce` seulement ; compte (`account-v2.jsx:395`, `:534`) à la connexion seulement | lignes citées |
| Fonctions d'API | `getOrder` `:1962`, `history` `:2083`, `profile` `:2145` ; **aucune** fonction pour `loyalty/balance` | `api.js` |
| Tests existants | 100 fichiers dans `tests-e2e/`, dont `points-en-direct-2026-11.spec.js`, `suivi-fluide-2026-11.spec.js`, `fidelite-hors-ligne-2026-11.spec.js` | `ls tests-e2e` |
| Outils | `tools/compile-jsx.mjs`, `tools/check-asset-versions.mjs`, `tools/release-gates.mjs` | `ls tools` |

### 1.2 Serveur (`testttt`)
| Brique | Constat | Ancre |
|---|---|---|
| Statuts | 1 attente · 4 validée · 7 préparation · 8 prête · 10 en livraison · 13 remise · 16 annulée · 19 refusée · 22 retournée | `app/Enums/OrderStatus.php:7-15` |
| Événement de statut | `OrderStatusChanged` émis par `OrderService.php:2644`, `PaymentService.php:581/1003`, `UberWebhookController.php:280`, `DispatchKdsTicket.php:17`, `CleanupStalePendingKioskOrders.php:441` | grep |
| Écouteurs en place | `AwardLoyaltyPointsOnDelivery`, `SendFcmOnOrderStatusChange` | `EventServiceProvider.php:160-168` |
| Ancien canal 1 | `OrderPushNotificationBuilder` : lit `users.web_token` / `device_token` | `OrderPushNotificationBuilder.php:38-56` |
| Ancien canal 2 | `FcmNotificationService` vers `https://fcm.googleapis.com/fcm/send` (API Firebase historique, arrêtée par Google) | `FcmNotificationService.php:24` |
| Clé Firebase en prod | **vide** (`grep -c "^FCM_SERVER_KEY=." .env` → 0) : rien n'est jamais parti | ssh du 2026-11-19 |
| Enregistrement d'appareil | `POST device-token/web` → un seul jeton par client (`users.web_token`) | `routes/api.php:2522-2526`, `TokenStoreService.php:18-23` |
| Crédit des points | à la validation, sentinelle atomique `orders.loyalty_points_awarded` | `AwardLoyaltyPointsOnDelivery.php:28-50`, `:71` |
| Solde | `GET /api/frontend/loyalty/balance` (connecté) existe, non utilisé par le site | `routes/api.php:2587` |
| Suivi public | `GET order/track/{jeton 48 car.}` limité à 300/min | `routes/api.php:2349-2352` |
| File d'attente prod | `QUEUE_CONNECTION=redis` | ssh |
| PHP prod | 8.1.2, Composer 2.10.1 | ssh |
| Bibliothèque Web Push | absente de `composer.json` | grep |
| Tests voisins | `tests/Feature/LoyaltyApiTest.php`, `tests/Feature/Loyalty/*` (12+), `tests/Feature/KdsNotificationFailureTest.php` | `ls` |

### 1.3 Conclusions
1. Le site est installable mais ne le propose jamais.
2. Aucune notification n'est jamais partie vers un client : pas de clé, canal fermé, rien pour recevoir.
3. Le « je dois rafraîchir » : quatre endroits lisent le solde, un seul se relit seul. Cause exacte chez les clients
   concernés = à mesurer (T-0.2) avant toute correction.

---

## §2 — Limites techniques (à accepter avant de lancer)

| Sujet | Android (Chrome) | iPhone |
|---|---|---|
| La carte NFC | ouvre une adresse | ouvre une adresse (Safari) |
| Création du raccourci | bouton « Installer » → 1 appui + 1 confirmation | **manuelle** : Partager → « Sur l'écran d'accueil ». On guide, on ne peut pas le faire |
| Notifications | possibles dès l'accord, installé ou non | **seulement depuis le raccourci installé** (iOS 16.4+), accord demandé après un appui |
| Connexion | conservée | le raccourci a sa propre mémoire : le client se reconnecte une fois |

Une notification peut arriver en retard ou pas du tout (économie d'énergie, accord retiré). **Règle d'architecture :
l'écran se met à jour seul par relecture ; la notification accélère et prévient, elle n'est jamais la seule voie.**

---

## §3 — Système 1 : serveur — notifications Web Push

**Contrat.** À chaque étape utile d'une commande en ligne, le client reçoit UNE notification sur chacun de ses appareils
abonnés. L'envoi ne ralentit ni ne fait échouer la caisse, la cuisine ou le paiement.
**Zones gelées concernées** : aucune modifiée. **Fiscal** : rien ; `php artisan fiscal:verify-chain --all` à chaque clôture de vague.

### S1.1 — Abonnements des appareils
- **T-1.1.1 Migration** `database/migrations/2026_11_20_100000_create_web_push_subscriptions_table.php` (À CRÉER) :
  `id`, `user_id` (index), `endpoint_hash` char(64) **unique** (SHA-256 du point d'accès), `endpoint` text, `p256dh` varchar(255),
  `auth` varchar(255), `content_encoding` varchar(20) défaut `aes128gcm`, `device_label` varchar(60) nul, `origin` varchar(20)
  (`nfc`/`site`), `last_success_at`, `last_failure_at`, `failure_count` tinyint défaut 0, `created_at`, `updated_at`.
  Pas de suppression douce. Aucune donnée existante touchée.
- **T-1.1.2 Modèle** `app/Models/WebPushSubscription.php` (À CRÉER), `endpoint`/`p256dh`/`auth` en `$hidden`.
  Isolation par branche : un client n'appartient pas à une branche → entrée dans `EXEMPTED_MODELS` de
  `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php:48` avec le motif écrit ; mise à jour de `CLAUDE.md §9`.
- **T-1.1.3 Routes** dans `routes/api.php` (groupe `frontend`, `auth:sanctum`) :
  - `GET  /api/frontend/push/key` (public, limité) → `{ "key": "<clé publique>" , "enabled": true|false }` ;
  - `POST /api/frontend/push/subscription` → corps `{endpoint, keys:{p256dh,auth}, encoding?, origin?}` ; même
    `endpoint_hash` = mise à jour (jamais de doublon) ; rattache au client connecté même si la ligne appartenait à un autre ;
  - `DELETE /api/frontend/push/subscription` → corps `{endpoint}`.
  Limite : `throttle:30,1,push-subscription`. Contrôleur `app/Http/Controllers/Frontend/WebPushController.php` (À CRÉER),
  requête `app/Http/Requests/WebPushSubscriptionRequest.php` (À CRÉER) : `endpoint` = URL `https`, 2 048 caractères max ;
  `authorize()` exige un client connecté (ne fait PAS monter `RETURN_TRUE_BASELINE`).
- **T-1.1.4 Plafond** : 5 appareils par client ; le 6ᵉ évince celui dont `last_success_at` est le plus ancien.
- **T-1.1.5 Suppression de compte** : les abonnements partent avec le compte (route `delete-account` existante).
- **Acceptation** : (test À CRÉER `tests/Feature/Push/WebPushSubscriptionTest.php`) — non connecté 401 ; doublon = 1 ligne ;
  changement de propriétaire ; plafond ; point d'accès `http://` refusé ; suppression de compte.
  + `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php` et `tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php` verts.

### S1.2 — Envoi
- **T-1.2.1 Bibliothèque** : `composer require minishlink/web-push:^9.0` (v9 = PHP 8.1 ; la v10 exige PHP 8.2, **interdite**
  tant que la prod est en 8.1.2). Vérifier `php -m | grep -i gmp` en prod ; absent = plus lent mais fonctionnel, le noter.
- **T-1.2.2 Configuration** `config/webpush.php` (À CRÉER) : `enabled` (`WEBPUSH_ENABLED`, défaut `false`), `public_key`,
  `private_key`, `subject` (`mailto:` du restaurant), `ttl` 900 s, `urgency` `high`. `.env.example` reçoit les NOMS seulement.
  `enabled=false` ou clé absente → aucun envoi, aucune erreur, `GET push/key` répond `enabled:false`.
- **T-1.2.3 Service** `app/Services/Push/WebPushSender.php` (À CRÉER) : `sendToUser(int $userId, array $payload): int`
  (nombre d'envois acceptés). Réponse 404/410 du service de notification → ligne supprimée. Autre échec → `failure_count+1`,
  `last_failure_at` ; à 5 échecs consécutifs → ligne supprimée. Succès → `failure_count=0`, `last_success_at`.
- **T-1.2.4 Tâche** `app/Jobs/SendWebPushJob.php` (À CRÉER) : `ShouldQueue`, file `default`, `tries=2`, `backoff=[20]`,
  `timeout=20`. Toute exception est journalisée (`Log::warning('[webpush]')`) et avalée : jamais de remontée.
- **T-1.2.5 Ancien code** : `FcmNotificationService`, `OrderPushNotificationBuilder`, `device-token/*` restent en place
  et inertes ; un commentaire d'en-tête dit pourquoi et renvoie ici. Aucune suppression (la borne les référence).
- **Acceptation** : (test À CRÉER `tests/Feature/Push/SendWebPushJobTest.php`) avec un faux service HTTP — 201 → succès noté ;
  410 → ligne supprimée ; 500 ×5 → supprimée ; `enabled=false` → zéro requête ; exception → la tâche ne plante pas.

### S1.3 — Déclencheurs et textes
- **T-1.3.1 Écouteur** `app/Listeners/SendWebPushOnOrderStatusChange.php` (À CRÉER), branché dans
  `EventServiceProvider.php` à la suite de `SendFcmOnOrderStatusChange` (ligne ~168). Il ne fait que : filtrer, puis
  `SendWebPushJob::dispatch(...)->afterCommit()`, le tout dans un `try/catch` (modèle : `SendFcmOnOrderStatusChange.php:50-70`).
  Filtres : commande en ligne avec un client (pas la caisse, pas la borne) ; client ayant au moins un abonnement.
- **T-1.3.2 Table des textes** (figée ; titre toujours « Le Cayenne ») :

  | Statut | Texte | Retard d'envoi |
  |---|---|---|
  | 4 validée | « Commande n° X validée, on la prépare. » + « +N points · solde M » si crédités | 5 s (laisse le crédit de points se poser) |
  | 7 préparation | aucun envoi (trop bavard) | — |
  | 8 prête | à emporter : « Votre commande n° X est prête, venez la chercher. » · livraison : aucun envoi | 0 |
  | 10 en livraison | « Votre commande n° X est en route. » | 0 |
  | 13 remise | « Bon appétit ! Merci pour votre commande. » | 0 |
  | 16 / 19 annulée, refusée | « Votre commande n° X a été annulée. » + « Vos points ont été repris. » si c'était le cas | 0 |
  | 22 retournée | aucun envoi | — |

- **T-1.3.3 Charge utile** (JSON, 1 Ko max) : `{ v:1, type:"order", order_id, status, title, body, tag:"order-<id>",
  url:"/?suivi=<id>", points_awarded?, points_balance? }`. Même `tag` par commande : la nouvelle remplace l'ancienne.
- **T-1.3.4 Anti-doublon** : `Cache::add("webpush:order:<id>:<statut>", 1, 6 h)` ; déjà présent → pas d'envoi (plusieurs
  chemins émettent le même événement, cf. §1.2).
- **T-1.3.5 Points** : lus au moment de l'envoi dans `orders.loyalty_points_awarded` (> 0) et dans le solde du client ;
  jamais recalculés ; `AwardLoyaltyPointsOnDelivery` n'est PAS modifié.
- **Acceptation** : (test À CRÉER `tests/Feature/Push/OrderStatusPushTest.php`) — un cas par ligne de la table ; même
  statut émis deux fois = un envoi ; commande de caisse = zéro ; échec d'envoi = changement de statut quand même réussi.
  `tests/Feature/LoyaltyApiTest.php`, `tests/Feature/Loyalty/*`, `tests/Feature/KdsNotificationFailureTest.php` restent verts.

### S1.4 — Exploitation
- **T-1.4.1 Clés** : `php artisan webpush:keys` (commande À CRÉER, `app/Console/Commands/WebPushKeysCommand.php`) affiche
  une paire ; posée dans le `.env` de prod par le propriétaire (porte G5). **Les clés ne changent plus jamais** : les
  changer désabonne tous les clients.
- **T-1.4.2 Essai** : `php artisan webpush:test {user_id}` (À CRÉER) envoie « Essai » à un client nommé.
- **T-1.4.3 Ménage** : tâche planifiée quotidienne supprimant les abonnements sans succès depuis 90 jours.
- **T-1.4.4 Santé** : compteurs envoyés/refusés du jour dans le journal ; pas d'écran d'admin dans ce GOAL.
- **Acceptation** : (test À CRÉER `tests/Feature/Push/WebPushCommandsTest.php`).

---

## §4 — Système 2 : site — installation, accord, réception

**Contrat.** Scanner la carte mène à une page qui installe (Android) ou guide (iPhone) ; l'accord est demandé au bon
moment ; une mise à jour du site ne casse ni le raccourci ni les notifications.
Tout fichier `.jsx` modifié est recompilé (`node tools/compile-jsx.mjs`) et re-versionné dans `index.html`
(`node tools/check-asset-versions.mjs` doit passer) — sinon le correctif est invisible.

### S2.1 — Arrivée par la carte et installation
- **T-2.1.1 Adresse des cartes** : `https://www.lecayenne.fr/?src=nfc` (porte G3). `start_url` du manifeste reste `/`.
  Le paramètre est lu une fois, mémorisé (`localStorage lc.src`), puis retiré de la barre d'adresse.
- **T-2.1.2 Module** `installation.js` (À CRÉER, script simple, sans JSX) chargé par `index.html` :
  capture `beforeinstallprompt` ; expose `LC.install = { etat(), proposer(), plateforme }` ;
  `etat()` ∈ `installe` (mode `standalone`) / `possible` (Android) / `guide` (iPhone Safari) / `autre`.
- **T-2.1.3 Bandeau** (dans `racine.jsx`, composant À CRÉER `BandeauInstallation`) :
  - Android : « Ajouter Le Cayenne à l'écran d'accueil » → bouton **Installer** ;
  - iPhone : 2 étapes illustrées (icône Partager, « Sur l'écran d'accueil ») ; hors Safari : « Ouvrez cette page dans Safari » ;
  - affiché tout de suite si `src=nfc`, sinon à la 2ᵉ visite ; jamais pendant le tunnel de commande ni le paiement ;
  - refermable ; pas reproposé avant 14 jours ; jamais si `etat() === 'installe'`.
- **T-2.1.4 Premier lancement du raccourci** : si non connecté → écran de connexion direct, avec la phrase
  « Reconnectez-vous une fois : le raccourci garde ensuite votre compte. »
- **T-2.1.5 Mesure** : `src=nfc` et l'événement `appinstalled` envoyés en un appel anonyme (sans identité) à la route de
  mesure existante si elle existe ; sinon tâche abandonnée, pas de nouvelle route.
- **Acceptation** : (test À CRÉER `tests-e2e/installation-nfc-2026-11.spec.js`) — 4 états, règle des 14 jours, absence
  pendant le paiement ; captures 360×800 et 390×844 lues.

### S2.2 — Accord et abonnement
- **T-2.2.1 Fonctions d'API** dans `api.js` (après `profile`, `:2145`) : `pushKey()`, `pushSubscribe(sub, origine)`,
  `pushUnsubscribe(endpoint)`. Un 404 = « fonction pas encore disponible », silencieux.
- **T-2.2.2 Module** `notifications.js` (À CRÉER) : `LC.notifs = { etat(), activer(), couper(), synchroniser() }`.
  `etat()` ∈ `indisponible` / `a-installer` (iPhone hors raccourci) / `a-demander` / `actif` / `refuse`.
- **T-2.2.3 Moment de la demande** : sur l'écran de confirmation (`funnel.jsx`, bloc du suivi `:3728`), carte
  « Être prévenu quand elle est prête ? » avec **Oui, me prévenir** / **Non merci**. La demande du système n'apparaît
  qu'après l'appui sur « Oui ». Jamais à l'arrivée sur le site.
- **T-2.2.4 Réglage** : ligne « Notifications » dans le profil (`loyalty-v2.jsx`, à côté de `useEtatNotifs` `:404`) :
  état, activer, couper ; si `refuse` → explication pour réactiver dans les réglages du téléphone.
- **T-2.2.5 Déconnexion** : désabonne l'appareil (`pushUnsubscribe`) ; reconnexion → `synchroniser()` réabonne si l'accord existe.
- **Acceptation** : (test À CRÉER `tests-e2e/notifications-abonnement-2026-11.spec.js`) — refus respecté, jamais de
  demande sans appui, réglage du profil, déconnexion.

### S2.3 — Service worker : réception et tenue aux mises à jour
- **T-2.3.1 `sw.js`** : `VERSION = 'lc-v3-2026-11-xx'` ; ajout de `push` (affiche titre/texte/`tag`, icône
  `/assets/brand/app-icons/icon-192.png`) et `notificationclick` (ramène l'onglet ouvert au premier plan, sinon ouvre `url`).
  Charge illisible → notification générique « Votre commande avance ». Les règles de cache existantes ne changent pas.
- **T-2.3.2 Passage aux pages** : à chaque `push`, `clients.matchAll()` + `postMessage({type:'lc:push', ...})` ;
  `notifications.js` le transforme en événement `lc:push` sur `window`.
- **T-2.3.3 Abonnement perdu** : `pushsubscriptionchange` → réabonnement + envoi au serveur ; à chaque lancement,
  `LC.notifs.synchroniser()` compare l'abonnement du navigateur à celui envoyé (mémorisé) et répare.
- **T-2.3.4 Mise à jour** : l'abonnement survit au changement de `sw.js` (même portée `/`) — à PROUVER, pas à supposer.
- **T-2.3.5 Arrêt d'urgence** : la procédure de `sw.js:25-32` est conservée et testée ; `WEBPUSH_ENABLED=false` côté
  serveur coupe tout sans redéployer le site.
- **Acceptation** : (test À CRÉER `tests-e2e/sw-push-et-mise-a-jour-2026-11.spec.js`) — site servi en `v2` puis `v3` sur le
  même profil de navigateur : abonnement conservé, message reçu par la page, clic qui ramène au suivi.

---

## §5 — Système 3 : suivi en direct sans rafraîchir

**Contrat.** Statut et points se mettent à jour seuls sur TOUTES les pages qui les montrent, avec ou sans notification.

### S3.1 — Mesure (avant tout code)
- **T-0.2** Miroir du site en ligne rebranché sur le serveur local (méthode du 2026-10-30, miroir `:8014`, API `:8766`),
  en mode raccourci (`display-mode: standalone` émulé). Scénario : client connecté, commande, validation en caisse, puis
  relevé sur 5 pages (confirmation, historique, fidélité, panier, compte) : le solde bouge-t-il seul, en combien de temps ?
  Résultat écrit dans `reports/test-e2e/nfc-notifications/MESURE_T0.md`. **Aucune tâche S3.2/S3.3 avant ce fichier.**

### S3.2 — Une seule source pour le solde
- **T-3.2.1** `api.js` : `loyaltyBalance()` → `GET /api/frontend/loyalty/balance` (route existante `routes/api.php:2587`).
- **T-3.2.2** Module `solde.js` (À CRÉER) : `LC.solde = { lire(), valeur(), abonner(fn) }` ; une seule requête à la fois ;
  diffuse `lc:solde` à chaque changement.
- **T-3.2.3** Branchements : `screens.jsx:1942` (fidélité), `funnel.jsx:773` et `:3849` (panier, confirmation),
  `account-v2.jsx` (compte) lisent `LC.solde` au lieu de leur copie locale.
- **T-3.2.4** Relectures : retour au premier plan (`visibilitychange`, `pageshow`, `focus`, `online`), événement `lc:push`,
  fin de commande ; toutes les 20 s **seulement** tant qu'une commande est active.
- **Acceptation** : `tests-e2e/points-en-direct-2026-11.spec.js` (existant) reste vert +
  (test À CRÉER `tests-e2e/solde-une-seule-source-2026-11.spec.js`) : les 4 pages affichent le même nombre en moins de 25 s
  après le crédit, sans recharger.

### S3.3 — Statut de la commande
- **T-3.3.1** Historique (`orders.jsx:120-129`) : 15 s → 5 s tant qu'une commande est active ; relecture immédiate sur `lc:push`.
- **T-3.3.2** Confirmation (`funnel.jsx:3728`) : relecture immédiate sur `lc:push` (en plus de sa boucle).
- **T-3.3.3** Raccourci rouvert après des heures : relecture dans la seconde (`pageshow` avec `persisted`).
- **T-3.3.4** Charge serveur : au plus 12 lectures/min par client actif, page visible seulement ; vérifier que les limites
  par IP ne refusent pas (leçon du 2026-10-29 : mesurer sur le vrai serveur, pas sur un faux plus gentil).
- **Acceptation** : `tests-e2e/suivi-fluide-2026-11.spec.js` (existant) reste vert +
  (test À CRÉER `tests-e2e/suivi-sans-rafraichir-2026-11.spec.js`) : validée → prête → remise visibles en moins de 8 s chacune.

---

## §A — Agents

| Type de tâche | Lecture seule avant | Écriture | Après |
|---|---|---|---|
| Serveur (S1) | Architecte, Sécurité, DBA, SRE | 1 implémenteur, test d'abord | Rouge |
| Site visuel (S2.1, S2.2) | Architecte, Sécurité, UX/a11y | 1 implémenteur | Rouge + QA visuel + Rouge visuel |
| Service worker (S2.3) | Architecte, Sécurité, SRE | 1 implémenteur | Rouge |
| Suivi (S3) | Architecte, SRE | 1 implémenteur | Rouge + QA visuel |

Jamais deux implémenteurs en même temps sur le même dépôt. Constats écrits dans
`reports/test-e2e/nfc-notifications/<tour>/vague-<N>-<rôle>.json`. Tout P0/P1 sans `fichier:ligne` + reproduction est rejeté.

## §X — Vagues

| N° | Contenu | Dépôt | Parallèle | Clôture |
|---|---|---|---|---|
| 0 | Portes §G, worktree du site, T-0.2 (mesure) | les deux | — | `MESURE_T0.md` écrit |
| 1 | S3 (solde unique + statut) | site | non | tests S3 verts, captures lues, **déploiement site** |
| 2 | S1.1 + S1.2 + S1.4 | serveur | avec 1 (dépôts disjoints) | PHPUnit complet + sentinelles + chaîne fiscale, **déploiement serveur, `WEBPUSH_ENABLED=false`** |
| 3 | S1.3 (déclencheurs) | serveur | non | idem, toujours désactivé |
| 4 | S2.2 + S2.3 | site | non | tests verts ; clés posées (G5) ; activation ; **essai sur vrais téléphones (G4)** |
| 5 | S2.1 (carte, bandeau) | site | non | carte de test scannée sur Android et iPhone |
| 6 | Tour final adverse : mise à jour du site pendant un abonnement actif ; coupure d'urgence ; déconnexion | les deux | non | 2 tours propres consécutifs |

- La vague 1 est livrable seule et répond déjà à la plainte des clients.
- Les vagues 2 et 3 partent en production **désactivées** : aucun effet tant que la vague 4 n'est pas prouvée.
- Clôture de chaque vague : tâches vertes · diff des zones gelées = 0 ligne · `fiscal:verify-chain --all` OK · captures
  lues · tour Rouge soldé · `PROJECT_BRAIN.md §2/§3` mis à jour.
- Interruption : commit `wip(vague-N)`, manifeste `reports/test-e2e/nfc-notifications/INTERRUPT_vague-N.md`
  (dernier commit vert, tâche en cours, suivante), ligne dans `PROJECT_BRAIN.md §2`.
- Retour arrière : serveur = `WEBPUSH_ENABLED=false` (immédiat) ou script de déploiement vers le SHA précédent ;
  site = redéployer l'archive précédente ; service worker = procédure `sw.js:25-32`.

## §G — Portes du propriétaire

| Porte | Décision | QUI | QUOI | OÙ | Bloque |
|---|---|---|---|---|---|
| G1 | Le raccourci remplace la publication sur les stores, ou s'y ajoute | Propriétaire | réponse écrite | `PROJECT_BRAIN.md §6` | rien (information) |
| G2 | Suivi seulement, ou aussi promotions (accord séparé du client obligatoire, hors de ce GOAL) | Propriétaire | réponse écrite | idem | textes de T-2.2.3 |
| G3 | Adresse écrite sur les cartes (`…/?src=nfc` proposé) | Propriétaire | validation | T-2.1.1 | vague 5 et la commande des cartes |
| G4 | Un iPhone (iOS 16.4+) et un Android réels, 20 minutes | Propriétaire | présence | rapport vague 4 | clôture vague 4 |
| G5 | Pose des clés dans le `.env` de production | Propriétaire lance la commande | journal de déploiement | vague 4 | activation |
| G6 | Textes des notifications (table T-1.3.2) | Propriétaire | validation ou corrections | ce document | vague 3 |

Sans réponse : vagues 0, 1 et 2 avancent (aucune ne dépend d'une porte).

## §R — Références
`CLAUDE.md` §7 (zones gelées), §8 (fiscal), §9 (branches, cliquet des requêtes) · mémoires : site en ligne ≠ dépôt,
cache-buster du site, Vercel bloque selon l'auteur, mock plus gentil que la prod, jamais afficher la dépense,
deux déploiements simultanés · `docs/PLAYWRIGHT_MCP_OPS.md §7` (instrument avant produit).

## §F — Fini quand
Sur un iPhone et un Android réels : le client scanne la carte, installe, se connecte, commande ; le statut avance à
l'écran sans qu'il touche rien ; téléphone verrouillé, il reçoit « validée + points », « prête », « bon appétit » ;
son solde est le même sur toutes les pages ; et tout cela marche encore après une mise à jour du site faite entre-temps.
Tout écart = pas fini.


---

## §E — Exécution (2026-11-19 → 2026-11-20) : fait, prouvé, écarts, reste à faire

**Livré, NON déployé, NON poussé.** Site : worktree `lecayenne-web-deploy/site-nfc-2026-11-19`, branche locale `feat/nfc-notifications-2026-11-19`
(6 commits sur `3eb0ae2`). Serveur : branche locale `feat/web-push-clients-2026-11-19` (un commit, créé sans toucher à la branche de travail partagée).

| Vague | Résultat |
|---|---|
| 0 mesure | `reports/test-e2e/nfc-notifications/MESURE_T0.md` : le site se mettait déjà à jour seul (9-13 s) écran visible ; le défaut est le RÉVEIL d'un raccourci suspendu |
| 1 suivi | `reveil.js` (`lc:reveil` : pageshow restauré, gel détecté, notification reçue) ; « Mes commandes » relue toutes les 10 s, commandes de < 12 h (heure de Paris) ou retrait programmé proche |
| 2-3 serveur | table `web_push_subscriptions`, routes `push/key|subscription`, `WebPushSender`, `SendWebPushJob`, écouteur, commandes `webpush:keys|test|prune` ; **désactivé par défaut** (`WEBPUSH_ENABLED=false`) |
| 4-5 site | `installation.js`, `installation-ui.jsx`, `notifications.js`, `sw.js` v3 ; un seul bouton « Installer » |
| 6 test-e2e | 4 tours adverses (serveur et site), chaîne réelle commande → file → requête chiffrée déchiffrée : 20/20 ; PHPUnit complet 7 878 tests, 0 échec ; chaîne fiscale OK ; zones gelées 0 ligne |

**Écarts assumés par rapport au plan** : (1) « source unique du solde » (S3.2) remplacée par un signal de réveil unique : les pages étant montées une à la fois, le défaut
n'était pas deux valeurs divergentes mais des relectures manquantes ; (2) texte « annulée » sans la phrase « points repris » (non déterminable de façon fiable) ;
(3) relecture des commandes 10 s et non 5 s (3× moins de charge) ; (4) notification « validée » abandonnée si la commande a déjà atteint « prête » (sinon, même `tag`, elle la remplacerait) ;
(5) filtre « client en ligne » : ni caisse ni borne, ni équipe (même règle d'exclusion que les points).

**Ce que l'audit adverse a trouvé et qui est corrigé** : faille de requête serveur (adresse d'appareil fournie par le client → liste fermée des services de notification, redirections coupées) ;
« validée + points » perdue pour les commandes payées en ligne (passent directement en préparation) ; une clé d'appareil invalide empoisonnait l'envoi des autres ;
une faute dans la clé VAPID effaçait tous les abonnements ; consentement par appareil au lieu de par compte ; origine d'abonnement inconnue qui faisait échouer en silence ;
bandeau (contraste, ordinateur, petit écran, barre du panier) ; bouton « Installer » mort si le système refuse la feuille de partage.

**Limite d'iOS (à connaître, non contournable)** : Apple n'autorise aucun site à ajouter son icône lui-même. Le bouton ouvre la feuille de partage du téléphone ; le client y touche
« Sur l'écran d'accueil ». **NON PROUVÉ ici** : que cette feuille (ouverte par le site) propose bien « Sur l'écran d'accueil », et ce que renvoie `navigator.share` quand on le choisit → porte G4
(vrai iPhone). Si l'option manque, revenir aux deux étapes écrites (repli déjà codé, `etat()==='guide'`). Android : un vrai bouton, une confirmation.

**Pour mettre en ligne (rien de ceci n'est fait)** :
1. Serveur d'abord : `composer install` sur le VPS (7 paquets ajoutés ; `symfony/http-client` exige PHP ≥ 8.2 mais n'est pas chargé par l'envoi ; le worker de file tourne en PHP 8.1) puis le script de déploiement
   (migration comprise). L'envoi reste coupé tant que `WEBPUSH_ENABLED=false`.
2. Clés : `php artisan webpush:keys` UNE fois, poser dans le `.env` de production (porte G5), ne plus jamais les changer.
3. Site : `node tools/compile-jsx.mjs --check` et `node tools/check-asset-versions.mjs --check`, puis déploiement Vercel depuis une copie sans `.git` ; la carte NFC doit pointer sur `https://www.lecayenne.fr/?src=nfc` (pas une page SEO).
4. Activation : `WEBPUSH_ENABLED=true` après l'essai sur vrais téléphones (G4) avec `php artisan webpush:test <id client>` ; coupure d'urgence = remettre `false` (sans redéployer le site).
5. Application des stores : `app/www` est un artefact à reconstruire (`node tools/build-app-www.mjs`) avant tout build ; les nouvelles pièces y sont inertes (application native = « installé », notifications « indisponibles »).
