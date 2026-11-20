# C — Parcours compte client de l'application Le Cayenne (E2E local) — 2026-11-02

**Code testé :** worktree `/Users/1millnonstop/Downloads/lecayenne-web-deploy/app-pret-apple-2026-11-02` @ `bc645b3` (branche `app/pret-apple-2026-11-02`). Les 11 `compiled/*.js` correspondent à leurs `.jsx` (empreintes `.source-sha256` vérifiées). Le worktree n'a pas été modifié (`git status` : seul le lien `app/node_modules`, déjà présent).

**Verdict : BLOQUÉ pour la soumission Apple.** Inscription, connexion par code, persistance, fidélité, commande et « Mes commandes » fonctionnent. En revanche, l'écran **« Mon compte » plante et laisse une page blanche** (P0). C'est le seul endroit où l'on peut **se déconnecter** et **supprimer son compte** : la règle App Store 5.1.1(v) n'est donc pas satisfaite. Le même commit de fusion a aussi fait disparaître deux autres corrections destinées à l'application (P1, P2).

---

## 1. Banc

| Élément | Valeur |
|---|---|
| Miroir du site | `/private/tmp/claude-501/app-login-e2e/site/` (copie `rsync`, sans `.git`, `app/node_modules`, `app/ios`, `app/android`, `app/www`). Seules modifications dans la COPIE : la méta `api-base-url` (→ `http://127.0.0.1:8767`) et la méta `api-key` (→ clé locale publique, lue dans le bundle public `public/js/app.js`, pas dans `.env`). |
| Service | `python3 -m http.server 8932 --bind 127.0.0.1` |
| Coque iOS simulée | pont `PONT_CAPACITOR('ios')` copié à l'identique de `tools/verify-app-behaviour.mjs` (sans `registerPlugin`), viewport 430×932, `isMobile`, `hasTouch`, `fr-FR`. Contrôlé : `LC.native.actif=true`, 8 à 9 appels natifs partent (StatusBar, Keyboard, App, Network, SplashScreen), `onlineCardEnabled=false`. |
| Garde production | toute requête vers `vps-418872ac…` ou `lecayenne.fr` est interceptée et annulée. **0 requête vers la production** dans tous les passages. |
| CORS | `:8766` et `:8767` répondent `Access-Control-Allow-Origin: http://127.0.0.1:8932` (motif `#^http://(localhost|127\.0\.0\.1):\d{2,5}$#` de `config/cors.php`). |
| Playwright | `app/node_modules/playwright` du worktree, Chromium 145, Node v22.23.2, un seul navigateur à la fois. |
| Scripts | `/private/tmp/claude-501/app-login-e2e/scripts/*.mjs` (common, s1…s67, repro-en-application, encoche, divers) |
| Captures | `/private/tmp/claude-501/app-login-e2e/captures/` (40 PNG, non versionnées) |

### ⚠ Écart de banc : le backend `:8766` ne peut pas envoyer d'e-mail

Sur `:8766`, la première inscription renvoie `422 « Le code n'a pas pu être envoyé. Réessaie dans un instant. »`. Le journal `storage/logs/laravel-2026-11-02.log:360` montre `guest_signup.email_login_failed … Connection could not be established with host "smtp.example.com:587"`. Le SMTP local est un hôte factice, et `SignupOtpMail` s'envoie de façon synchrone. C'est un **problème d'environnement**, pas un défaut produit : l'écran affiche bien un message en français, sans perdre la saisie (`captures/01…`).

**Contournement, sans toucher au code ni à la configuration :** j'ai lancé une **seconde instance du même arbre `testttt`, sur la même base `foodking_e2e`**, avec la seule variable de processus `MAIL_MAILER=log` :
`cd testttt/public && MAIL_MAILER=log php -S 127.0.0.1:8767 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`.
Tout le parcours ci-dessous passe par `:8767`.

**Code reçu, deux sources indépendantes :** `dev_code` dans la réponse de `email-login` (`app()->environment('local')`, `GuestSignupController::ajouterCodeDev`) **et** une lecture seule de `otps.token` via `php artisan tinker`. Elles concordaient à chaque fois (9176=9176, 2607=2607, 4748=4748). Aucun code fixe n'a été utilisé.
Remarque : le `MAIL_MAILER=log` n'écrit rien dans `storage/logs`, probablement filtré par le niveau de journalisation.

---

## 2. Scénarios — résultats

Identité de test : `E2E-APP-3411845`, `e2e.app.3411845@lecayenne.test`, `0693411845`, user id **1073**.

### S1 — Nouveau client : VERT
1. « Se connecter » ouvre l'écran unique, l'e-mail d'abord (`01-compte-email-vide.png`). Le bouton « Se connecter avec Apple » s'affiche bien sur iOS.
2. Pour un e-mail inconnu, le serveur répond `known:false` et l'écran déplie Prénom + Téléphone sur place (`02-…`).
3. `email-login` (inscription) répond 200 avec `sent:true`. L'écran du code affiche le recours « Si tu ne reçois rien… passe au comptoir » (`03-compte-code.png`).
4. Le code est saisi, `verify` répond **201** et le jeton est stocké dans `localStorage['lecayenne.authToken']`. Le téléphone est mémorisé. L'écran « Bienvenue au club · Compte prêt » s'affiche (`04-compte-succes.png`).
5. La fidélité affiche **0 pts**, le niveau Novice, l'identifiant `BC84611B` et un QR vivant (`06-fidelite.png`). `/api/profile` renvoie le bon nom, téléphone et e-mail.
6. En base (lecture seule) : `users.id=1073`, `is_guest=5`, `email_verified_at` posé, `loyalty_points=0`.
7. Le profil (nom/téléphone) n'est visible dans l'application réelle qu'après correction du P0 (voir S6/S7). Avec le contournement, il est correct : « E2E-APP-3411845 / +33 6 93 41 18 45 / e2e.app… » (`34-profil-nom-tel.png`).

### S2 — Persistance : VERT, avec une réserve iOS
- Après rechargement, la session est conservée (`authed:true`, bouton « Mon compte » visible, `07-apres-rechargement.png`).
- Après `localStorage.clear()` (simulation d'une purge iOS de WKWebView), le client est **déconnecté proprement** : « Se connecter » revient, sans erreur JS (`10-apres-purge-localstorage.png`). Il doit redemander un code ; sa mémoire d'appareil (e-mail prérempli) est perdue aussi.
- **Aucun appel à `Preferences`** : 0 appel natif `Preferences.*`, et `grep Preferences` ne trouve rien dans `app-native.js` ni `api.js`. La mise en miroir de la session dans les Preferences natives n'existe pas encore, alors que `@capacitor/preferences 8.0.1` figure dans `app/package.json`. → R-1.
- Le jeton orphelin reste **valide côté serveur** après la purge (`/api/profile` → 200). Il sera révoqué à la prochaine connexion du même appareil, car le `device_id` est déduit de user-agent + IP, ou à son expiration.

### S3 — Reconnexion d'un client existant : VERT
- Sur un contexte vierge : saisie de l'e-mail, `known:true`, puis l'écran du code (« Je me connecte »).
- La réponse de `verify` porte **le même `user.id` = 1073**, et les points sont intacts (0 → 0). L'écran dit « Bon retour · Compte retrouvé » (`15-reconnexion-succes.png`).
- La reconnexion après déconnexion (dans S6/S7) renvoie le même identifiant, 1073.

### S4 — Commande à emporter, règlement au comptoir : FONCTIONNEL, deux défauts
- Parcours : Menu → Fanta Orange 33cl → « Ajouter au panier » → « Voir le panier » → « Passer commande » → suggestion de dessert (« Non merci, régler ») → paiement. Seul « Payer sur place » est proposé : la carte est bien coupée dans l'application. → « Confirmer ma commande ».
- La requête envoie uniquement `items:[{item_id:54,quantity:1}]` et `expected_total:1.9`, conformément à la règle « le prix vient du serveur ». La réponse est **201** : commande `#0211269295`, id 9295, file `A0032`.
- L'écran de confirmation affiche « C'est parti ! », le QR, le total **1,90 €** et « Tu paies sur place » (`22-confirmation.png`).
- « Mes commandes » affiche `#0211269295 · EN ATTENTE · 1,90 €` et « Points fidélité en attente du retrait » (`23-mes-commandes.png`).
- La ligne `orders` en base (lecture seule) contient `total=1.900000`, `user_id=1073`, `status=1`, `payment_status=10`, `payment_method=1`, `order_type=10`. **Le total affiché est identique à celui de la base.**
- **Nettoyage :** l'annulation via `POST /api/admin/pos/counter-collect/9295/cancel` (compte pos) est refusée, à juste titre : « This order is not a pending counter payment. » Ce chemin concerne la borne. J'ai donc annulé par la route admin réelle `POST /api/admin/online-order/change-status/9295`, avec `{status:16, reason:"Banc E2E app…"}`, en me connectant comme `admin@lecayenne.fr` avec un en-tête `X-Idempotency-Key`. Résultat : `status=16 « Annulée »`, `payment_status=10` (non payée), `fiscal_sequence_no=null`. Je me suis déconnecté du jeton admin ensuite. Aucune écriture tinker.
- Défauts constatés : **D-2** (commande « dans 10-15 min » passée hors service) et **D-3** (date fausse), décrits ci-dessous.

### S5 — Codes faux, verrouillage, renvoi : VERT
- 1er code faux : « Code incorrect. Vérifie les 4 chiffres de l'e-mail, puis resaisis-les. » Du 2e au 5e : « Code toujours refusé. Demande un nouveau code avec le bouton « Renvoyer » ci-dessous. » (`13-trois-codes-faux.png`). Tous les messages sont en français, sans anglais ni clé brute.
- Après 5 échecs, **le bon code est refusé** (422) et la ligne `otps` est supprimée en base : le code est brûlé (`OtpManagerService::verify`, max 5) (`24-verrou-code-brule.png`).
- « Renvoyer » est désactivé avec un compte à rebours de 29 s. Une fois actif, il produit un nouveau code (nouvelle ligne `otps`) et affiche « Nouveau code envoyé à … C'est celui-là qu'il faut saisir. ». Ce nouveau code passe en **201** sur le même compte, 1073.
- Le limiteur par IP `throttle:10,5,otp-verify` se déclenche après une rafale : `429` avec `Retry-After: 268`. L'écran affiche « Trop de tentatives. Patiente quelques minutes avant de réessayer. » (`26-verify-429.png`), même avec le bon code.

### S6 — Déconnexion : BLOQUÉE dans l'application (P0) ; correcte derrière le plantage
- **Chemin réel :** « Mon compte » (en-tête ou ☰) donne une **page blanche** (D-1). Le menu ☰ ne propose aucune autre entrée de déconnexion (`27-menu-burger.png`). **Le client ne peut pas se déconnecter.**
- **Derrière le plantage** (contournement de banc, voir D-1) : « Se déconnecter » vide le jeton, le téléphone et la mémoire d'appareil, et le bouton « Se connecter » revient (`35-apres-deconnexion.png`). L'**ancien jeton reçoit 401** sur `/api/profile`. `#loyalty` et `#orders` n'affichent plus ni prénom ni solde (`36-deconnecte-*.png`). Aucune requête 4xx/5xx.

### S7 — Suppression du compte : BLOQUÉE dans l'application (P0) ; correcte derrière le plantage
- **Chemin réel :** « Zone dangereuse → Supprimer mon compte » est dans le même onglet qui plante (D-1). **La suppression est inaccessible.**
- **Derrière le plantage** (contournement) : la confirmation en deux temps s'affiche (`37-…`). `POST /api/auth/delete-account` répond **200**. L'accueil affiche ensuite « Ton compte a été supprimé. Tes informations personnelles sont effacées. » (`38-apres-suppression.png`).
- En base (lecture seule) : `name="Compte supprimé"`, `email=null`, `phone="PENDING_DELETED_…"`, `loyalty_code=null`, `deleted_at` posé, **0 jeton restant**. La commande 9295 est conservée (annulée), comme l'exige la règle NF525.
- L'ancien jeton reçoit 401. `email-login` avec l'ancien e-mail répond `known:false` : le compte n'est plus retrouvable et ne peut pas être ressuscité.

### S8 — Hygiène générale
- Aucune réponse 4xx/5xx muette dans le parcours. Chaque 422 ou 429 produit un message affiché en français.
- Aucune clé i18n brute ni texte anglais visible dans l'application. Seul le message de l'API admin « This order is not a pending counter payment. » est en anglais, et il est hors application.
- Une seule erreur JS : `ReferenceError: EN_APPLICATION is not defined` (D-1).

---

## 3. Défauts (chacun reproduit par deux moyens indépendants)

### D-1 — P0 — « Mon compte » plante : page blanche, déconnexion et suppression du compte inaccessibles
- **Où :** `loyalty-v2.jsx:59`, `:101`, `:134` lisent la constante globale `EN_APPLICATION`, qui **n'est plus définie nulle part** (`grep -rn EN_APPLICATION` ne trouve que ces trois lectures). Elle était déclarée dans `components.jsx` par `cac473a` (2026-09-30) ; **`68442b0` (2026-10-24, « feat(site): livraison de bout en bout ») l'a retirée** avec le filtre de l'onglet Avis.
- **Effet :** `ProfileEditor`, `NotificationSettings` et `SavedCards` lèvent une `ReferenceError`. Sans limite d'erreur, React démonte toute la racine et `#root` est vidé.
- **Preuve 1 (application, coque iOS) :** `s2-persistance.mjs` → `pageerror: EN_APPLICATION is not defined`, `08-profil.png` entièrement blanche.
- **Preuve 2 (site web sans pont, 1280 px, arrivée directe par `#loyalty`, onglet « Mon compte ») :** `repro-en-application.mjs` → `typeof EN_APPLICATION = undefined`, `#root.innerHTML.length = 0` après le clic (`11-site-web-mon-compte-plante.png`). **Le site lecayenne.fr est donc touché aussi**, s'il sert ce code (non vérifié : pas de requête vers la production).
- **Conséquence :** règle App Store 5.1.1(v) non satisfaite, aucune déconnexion possible. Rétablir la définition, par exemple dans `components.jsx`, suffit : avec `window.EN_APPLICATION` défini avant les scripts, S6 et S7 passent entièrement.

### D-2 — P1 — Hors service, l'application promet « ~10-15 min » et le serveur accepte (régression)
- **Où :** `funnel.jsx:371` et `:412`. Le créneau « Dès que prêt » est toujours proposé et ignore `service_ouvert`. La correction `4050155` (2026-10-01 : « hors service, la commande se programme à l'ouverture » — créneau « À l'ouverture », phrase « Le restaurant est fermé… ») est **absente du code actuel** (`grep service_ouvert funnel.jsx` ne trouve rien). Elle a été écrasée par `68442b0`, que `git log -S service_ouvert` désigne.
- **Preuve 1 :** à 16 h 37, `GET /api/frontend/order/wait-estimate` répond `"service_ouvert":false,"ouverture":"18:00"`, et l'accueil affiche « SERVICE FERMÉ · OUVRE DANS 1 H 29 ». Le paiement propose pourtant « Dès que prêt ~10-15 min » (`19-checkout.png`), puis la confirmation dit « PRÊT DANS ~10-15 min · ✓ ENVOYÉE EN CUISINE » (`22-confirmation.png`).
- **Preuve 2 :** la ligne en base `orders.id=9295` a `scheduled_at=null` : la commande est immédiate, acceptée alors que la boutique est fermée.
- **Effet :** le client se présente devant une porte close. Le serveur accepte aussi une commande immédiate hors service ; c'est à trancher côté backend (voir le message de `4050155`).

### D-3 — P2 — « Mes commandes » affiche une date fausse (jour et mois inversés)
- **Où :** `orders.jsx:25` passe `o.order_datetime`, au format `"04:37 PM, 02-11-2026"`, à `fmtOrderDate` (`orders.jsx:36-44`), qui fait `new Date(raw)`.
- **Preuve 1 :** une commande du 2 novembre 2026 s'affiche « **11 févr. 2026** · 16h37 » (`23-mes-commandes.png`).
- **Preuve 2 :** sous Node/V8, `new Date("04:37 PM, 02-11-2026")` donne `Wed Feb 11 2026 16:37`.
- **Effet :** sur Android/Chromium, la date affichée est fausse. Sur iOS (JavaScriptCore), il est probable que `new Date` échoue et que la chaîne brute anglaise « 04:37 PM, 02-11-2026 » s'affiche ; c'est à confirmer sur appareil. La réponse fournit aussi `order_date`/`created_at` en ISO : il faut parser l'ISO.

### D-4 — P2 — L'onglet « Avis » renvoie à une page « bientôt » dans l'application (régression Apple 2.1)
- **Où :** `components.jsx:204` (`{ id: 'avis' … }`). Le filtre `.filter(d => !(EN_APPLICATION && d.id === 'avis'))` de `cac473a` a été retiré par `68442b0`, de même que le lien du pied de page (`components.jsx:366`).
- **Preuve 1 :** la barre de navigation de l'application affiche « Avis » (liste des boutons visibles, toutes captures).
- **Preuve 2 :** `#avis` dans la coque iOS affiche « AVIS CLIENTS / BIENTÔT, VOS MOTS ICI… PAS ENCORE DE NOTE PUBLIÉE » (`32-onglet-avis-app.png`). C'est exactement le « contenu provisoire » que le commentaire d'origine voulait cacher à Apple.

### D-5 — P3 — Les filtres de « Mes commandes » (et les onglets Fidélité) prennent le style des grandes tuiles du menu
- **Où :** `orders.jsx:131-133` et `screens.jsx:1978-1980` utilisent la classe `.lc-cat-tab`. Or `styles-v8-vitrine.css:650-665` redéfinit cette classe sous 700 px en tuile photo de 132×92 px, fond encre, `padding:0`.
- **Preuve 1 :** `23-mes-commandes.png` montre « Tout / En cours / Terminées » sous forme de pavés noirs de 92 px de haut, texte collé au bord gauche, « Annulées » hors écran.
- **Preuve 2 :** la règle CSS ci-dessus s'applique à tout `.lc-cat-tab` sans restriction à la page menu.

---

## 4. Réserves / à vérifier sur appareil (non comptées comme défauts)
- **R-1 — Session non mise en miroir dans les Preferences.** Une purge de WKWebView déconnecte le client (comportement sûr, mais pénible). La fonction prévue n'est pas écrite : 0 appel `Preferences.*`.
- **R-2 — Encoche.** Avec un retrait de 59 px émulé en CDP (`Emulation.setSafeAreaInsetsOverride`), l'annonce « La livraison est lancée » (y 0–98) n'a pas de marge de zone sûre : son texte passe sous la barre d'état. La barre `.lc-nav` reçoit 59 px de marge inutile sous l'annonce. Le titre de la modale de compte (« ÉTAPE 1 SUR 2 », y=24) passe aussi sous la barre d'état ; seule la croix est décalée (`20-encoche-accueil.png`, `21-encoche-modale-compte.png`). Mais `capacitor.config.json` déclare `ios.contentInset: "always"`, qui peut ramener `env(safe-area-inset-top)` à 0 dans WKWebView. **Ce point ne se tranche que sur un iPhone réel.**
- **R-3 — Identification de l'appareil.** Le site n'envoie pas `X-Device-Id`. Le serveur déduit l'appareil de user-agent + IP (`DeviceTokenService::resolveDeviceId`). Une reconnexion depuis un client au même user-agent et à la même IP révoque donc la session précédente : vu sur le banc, où la connexion de S5 a révoqué le jeton de S3. C'est sans gravité pour un vrai téléphone, mais à garder en tête pour tout banc multi-contexte.
- **R-4 — Commentaire contradictoire côté backend.** `GuestSignupController::emailLogin` affirme « PAS de code fixe pour la revue App Store », alors que `codeDeRevuePour()` (décision du propriétaire du 2026-10-15) en implémente un, borné par une date. C'est une incohérence de documentation, sans effet sur ce parcours.

## 5. Données de test laissées
- Utilisateur 1073 : anonymisé et supprimé par S7 (parcours produit). Commande 9295 : annulée (`status=16`), non payée, sans numéro fiscal.
- Tentative d'inscription échouée sur `:8766` (`0693337769`, SMTP) : aucun compte créé, une éventuelle ligne `otps` expire d'elle-même.
- Les serveurs de banc (`http.server :8932`, `php -S :8767`) ont été arrêtés en fin de session. Pour rejouer : relancer ces deux commandes, puis `node <script>.mjs` avec le PATH de Node v22.
