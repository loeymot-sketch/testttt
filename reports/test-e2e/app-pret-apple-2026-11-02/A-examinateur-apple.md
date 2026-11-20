# A — Examinateur Apple adverse · « Le Cayenne » iOS (fr.lecayenne.app)

- Candidat : worktree `/Users/1millnonstop/Downloads/lecayenne-web-deploy/app-pret-apple-2026-11-02`, branche `app/pret-apple-2026-11-02`, HEAD `bc645b3`.
- Date : 2026-11-02. Lecture seule (seuls ce rapport et 3 captures ont été écrits).
- Règles : texte officiel récupéré par WebFetch sur https://developer.apple.com/app-store/review/guidelines/ (2.1(a), 2.3.3, 4.2, 4.8, 5.1.1(i), 5.1.1(v) cités ci-dessous d'après ce texte).
- Banc : Playwright (`app/node_modules/playwright`), pont Capacitor iOS de la forme exacte de `tools/verify-app-behaviour.mjs:35-54`, page servie par `127.0.0.1:8931` (cwd = ce worktree, vérifié avec `lsof`). Toute requête non-GET sortant de localhost était bloquée par `ctx.route`. Script : `/private/tmp/claude-501/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/62fb8233-57cf-4748-9360-e97d19514308/scratchpad/apple/probe.mjs`.
- `app/www` (le paquet embarqué) est identique octet pour octet à la racine pour `index.html`, `app-native.js`, `api.js`, `compiled/funnel.js`, `compiled/account-v2.js` et `compiled/loyalty-v2.js` (vérifié avec `cmp`). Tous les défauts ci-dessous sont donc dans le binaire.

## Verdict : REFUS CERTAIN en l'état (1 P0, 6 P1)

La cause racine principale est un seul commit côté site, **`68442b0` (24/10, « livraison de bout en bout »)**. En réécrivant `components.jsx` et `funnel.jsx`, il a supprimé :
- la constante `EN_APPLICATION` ;
- les filtres « pas d'Avis / pas de “bientôt” dans l'app » ;
- les deux appels qui déclenchent les notifications natives.

Les portes de sortie actuelles ne le voient pas : `verify-app-behaviour.mjs` ne contrôle que l'accueil sans session.

---

## P0 — refus certain

### P0-1 · « Mon compte » fait planter toute l'application : écran blanc, suppression de compte inaccessible (2.1(a) plantage + 5.1.1(v))

- `loyalty-v2.jsx:59`, `:101` et `:134` lisent `EN_APPLICATION`. Cet identifiant n'est **défini nulle part** : `grep -rn EN_APPLICATION *.jsx index.html` ne renvoie que ces 3 lignes. La définition (`const EN_APPLICATION = (() => …)`) a été retirée de `components.jsx` par `68442b0` (`git show 68442b0`, hunk `-const EN_APPLICATION`).
- Reproduction (pont iOS, jeton fictif dans `lecayenne.authToken`, clic sur « Mon compte ») :
  - `ReferenceError: EN_APPLICATION is not defined at ProfileEditor (compiled/loyalty-v2.js:111)`, puis dans `NotificationSettings` (`:167`) et `SavedCards` (`:243`) ;
  - le texte de la page passe à 16 caractères ;
  - « Supprimer mon compte » est absent ;
  - capture : `3-mon-compte.png` (écran entièrement blanc).
- Le chemin de suppression annoncé à Apple (« Fidélité › Mon compte › Supprimer mon compte », `app/PUBLICATION.md:492` et `:531`) mène donc à un plantage. C'est un refus 2.1(a) (« binaries that crash ») **et** 5.1.1(v) (« must also offer account deletion within the app »).
- ⚠ **Le site en production est touché aussi** : `https://www.lecayenne.fr/compiled/loyalty-v2.js` contient 3 références à `EN_APPLICATION`, et `compiled/components.js` en contient 0 (vérifié par `curl … | grep -c`). Tout client connecté qui ouvre « Mon compte » sur lecayenne.fr obtient un écran blanc.
- Correctif de code : redéclarer `EN_APPLICATION` (dans `loyalty-v2.jsx` lui-même, sans dépendre d'un autre fichier). Ajouter ensuite au banc un contrôle « connecté → Mon compte → bouton Supprimer visible, 0 erreur ».

## P1 — refus probable

### P1-1 · Notifications promises mais jamais déclenchées : fausse fiche, fausses notes d'examen, interrupteur sans effet (2.1, 2.3.1, et poids 4.2)

- `app-native.js:459` (`rappelerCommande`) et `:521` (`signalerCommandePrete`) n'ont **aucun appelant**. Un grep sur tout le site (`*.jsx`, `compiled/*.js`, `app/www/compiled/*.js`) donne 0. Les appels ont été supprimés de `funnel.jsx` par `68442b0` (lignes `-nat.rappelerCommande(…)` et `-nat.signalerCommandePrete(…)` du diff).
- Conséquences :
  - l'app ne demande **jamais** l'autorisation de notifier et n'en envoie aucune ;
  - fiche : « un rappel au moment où elle devrait être prête, et une alerte dès qu'elle l'est » (`PUBLICATION.md:283-284`) → faux ;
  - notes d'examen : « After an order, the app asks permission to send notifications » (`PUBLICATION.md:525-528`, FR `:486-489`) → l'examinateur ne verra jamais cette demande ;
  - interrupteur « Statut de commande — Un rappel… puis une alerte » (`loyalty-v2.jsx:93`) → réglage sans effet.
- Le banc `numero-appele-2026-10-01.spec.js:83` et le banc vague B, qui exigeaient `LocalNotifications.schedule`, étaient censés l'attraper. Ils n'ont manifestement pas été rejoués après le 24/10.
- Correctif de code : rétablir les deux appels dans `funnel.jsx`, à l'endroit d'où `68442b0` les a retirés (confirmation de commande et passage à « prête » dans le suivi).

### P1-2 · Contenu provisoire visible dans l'app : onglet « Avis » = « Bientôt, vos mots ici. », réseaux « bientôt » (2.1(a) « placeholder text… temporary content »)

- La navigation de l'app contient « Avis » et « Réseaux » (`components.jsx:204-205`). Mesure : `NAV ["Commandes","Fidélité","Panier","Se connecter","Accueil","Menu","Avis","Réseaux","Itinéraire"]`.
- Page Avis : titre « Bientôt, vos mots ici. », étoiles vides et « Pas encore de note publiée » (`screens.jsx:2270-2277`). Capture : `2-avis.png`.
- Pied de page : « Instagram, TikTok et Snapchat arrivent bientôt. » (`components.jsx:420`). Tuiles « Bientôt » dans la page Réseaux (`screens.jsx:2203`, `:2223-2224`).
- `68442b0` a retiré précisément les gardes `.filter(d => !(EN_APPLICATION && d.id === 'avis'))` et `RESEAUX_AFFICHES` qui masquaient tout cela dans l'app. Le commit `cac473a` (« zéro bientôt ») est ainsi annulé.
- Correctif de code : remettre ces filtres, à partir de la même constante que P0-1.

### P1-3 · La fiche et les notes d'examen disent « pas de livraison, Uber Eats seulement », alors que l'app livre (2.3.1 exactitude)

- `PUBLICATION.md:292-293` : « La livraison se fait uniquement via Uber Eats ».
- `PUBLICATION.md:496-498` et `:534-535` : « The restaurant does not deliver itself ».
- Réalité :
  - `api.js:79` : `deliveryEnabled` vaut `'1'` par défaut, et aucune balise `feature-delivery` n'existe dans `index.html` ;
  - le tunnel de livraison est rendu dans l'app (`funnel.jsx:606`, `:625`) ;
  - la production répond `GET /api/frontend/delivery/status` → `{"status":true,"enabled":true,…"home_fee":3.9,…"max_km":10}` (curl en lecture) ;
  - l'accueil de l'app affiche « NOUVEAU — La livraison est lancée ! … jusqu'à 10 km, dès 3,90 € » (`1-accueil.png`).
- L'examinateur voit l'inverse de ce que dit la fiche.
- Action du propriétaire : réécrire la description et les notes. La correction du texte source se fait dans `PUBLICATION.md`.

### P1-4 · Captures de la fiche périmées et fausses : bouton Google, ancien écran de connexion (2.3.3 / 2.3.1)

- `app/store-assets/fiches/app-store-iphone-6.9/5-compte.png` (dernier commit `2a0e1bb`, 19/08) montre :
  - les onglets « J'ai un compte / Créer un compte » ;
  - le bouton **« Se connecter avec Google »** ;
  - un formulaire Prénom/Nom/Email/Téléphone ;
  - la légende « Apple, Google ou e-mail ».
- Réalité :
  - Google est masqué, car `google-ios-client-id` et `google-web-client-id` sont vides (`index.html:133-134`, `app-native.js:653`) ;
  - l'écran est « un seul champ : l'e-mail » (`account-v2.jsx:597-608`).
- `PUBLICATION.md:298-302` a retiré « avec Google » du texte pour cette même raison, mais pas de l'image. `1-accueil.png` est aussi antérieure à la refonte : autre accroche, pas de bandeau livraison.
- Correctif : régénérer les captures (`tools/build-store-screenshots.mjs` puis `build-store-frames.mjs`) **après** les correctifs P0/P1, sans légende « Google ».

### P1-5 · Étiquettes de confidentialité et manifeste incomplets : adresse de livraison absente, SDK Facebook déclarant du pistage (5.1.1(i), 5.1.2, exactitude des App Privacy)

**a) Adresse de livraison**
- L'app collecte une adresse : `api.js:215` sauvegarde l'adresse (POST `/frontend/address`), `api.js:178` fait des suggestions d'adresse, et la suppression efface `addresses()` (`DeactivateController.php:69`).
- Absente du tableau App Privacy (`PUBLICATION.md:365-375`), de `PrivacyInfo.xcprivacy:37-104` et de la politique de confidentialité. Celle-ci ne liste que « créneau de retrait » (privacy.html, catégorie « Commande »).

**b) SDK Facebook embarqué**
- `@capgo/capacitor-social-login@8.4.3` (`app/package.json:34`) tire **facebook-ios-sdk ≥ 18.0.3** et GoogleSignIn dans le binaire iOS (`node_modules/@capgo/capacitor-social-login/Package.swift`, produits `FacebookCore` et `FacebookLogin`). Le code n'utilise jamais Facebook.
- Le manifeste du SDK (`curl https://raw.githubusercontent.com/facebook/facebook-ios-sdk/main/FBSDKCoreKit/FBSDKCoreKit/PrivacyInfo.xcprivacy`) déclare `NSPrivacyTracking = true`, un domaine de pistage `ep1.facebook.com` et `DeviceID` lié.
- Le rapport de confidentialité agrégé que génère Xcode contredira donc « Suivi : non » (`PUBLICATION.md:377`) et le manifeste de l'app (`PrivacyInfo.xcprivacy:26-29`).
- C'est le même piège que les permissions publicitaires Android retirées (`PUBLICATION.md:425-432`), cette fois non traité côté iOS.

**c) Politique de confidentialité inexacte sur les flux réels**
- Elle ne nomme qu'un hébergeur, **Vercel**, et dit « Aucun autre transfert » (privacy.html §4-§5).
- Or toutes les données de compte vivent sur l'API **OVH** (`index.html:63`, `https://vps-418872ac.vps.ovh.net`), qui n'est citée nulle part.
- Elle contient aussi une contradiction interne : ligne « Paiement : aucune donnée bancaire… paiement au comptoir », puis un paragraphe Mollie.
- Date « Dernière mise à jour : 18 mai 2026 », alors que la section app/Apple est plus récente.

**Actions**
- Code : retirer Facebook (et Google tant qu'il est masqué) du binaire iOS. Options : fork ou patch du `Package.swift` du greffon (le code compile sans, grâce à `#if canImport(FBSDKLoginKit)`, `SocialLoginPlugin.swift:5`), ou greffon Apple-seul. Ensuite, vérifier le rapport de confidentialité Xcode.
- Code : ajouter l'adresse (Coordonnées → Adresse physique, finalité « fonctionnement ») à `PrivacyInfo.xcprivacy`, et l'adresse + OVH à `legal/privacy.html`.
- Propriétaire : corriger App Privacy dans App Store Connect.

### P1-6 · Valeur native mince face à 4.2 (« elevate it beyond a repackaged website »)

- `PUBLICATION.md:327-330` prépare l'argument 4.2 avec : notifications locales, haptique, bandeau hors connexion, partage natif, connexion Apple, commande programmée. Ce qui est réellement appelé aujourd'hui, mesuré par `__appelsNatifs` au lancement et confirmé par grep des appelants :

| Fonction | État réel |
|---|---|
| `StatusBar.setStyle`, `Keyboard.setAccessoryBarVisible`, `SplashScreen.hide` | Appelés (habillage) |
| Réseau (`Network.getStatus` + écouteur), retour, `App.getInfo` | Appelés |
| Haptique | Seulement à l'ajout au panier (`racine.jsx:492`) |
| Liens externes via `Browser` | Câblés |
| Connexion Apple | Câblée côté client |
| **Partage** (`partager`, `app-native.js:423`) | **0 appelant** |
| **Notifications** | **0 appelant** (P1-1) |
| « Commande programmée » | Fonction du site, pas native |

- L'app est par ailleurs littéralement le site, avec ses pages vitrine (Avis, Réseaux, Itinéraire, bandeau marketing). Sans P1-1, l'argumentaire 4.2 repose sur barre d'état + bandeau hors ligne + vibration.
- Code : P1-1 rétabli suffit à rendre l'argument vrai. Ajouter un vrai appelant de `partager` ou le retirer de l'argumentaire. Masquer dans l'app les pages purement vitrine (Avis, Réseaux).

## P2 — risque / finition

- **P2-1 · Liens `target="_blank"` relatifs → Safari sur `https://localhost` (lien mort probable).**
  - Où : `account-v2.jsx:693` (CGV · Confidentialité, sur l'écran de connexion), `wizard-v2.jsx:1117` et `:1157`, `screens-v3.jsx:202` (« Infos allergènes »).
  - Mécanisme : `app-native.js:121-127` n'intercepte que les liens absolus d'une autre origine. Capacitor iOS traite alors le nouvel onglet par `UIApplication.shared.open(url)` (`node_modules/@capacitor/ios/Capacitor/Capacitor/WebViewDelegationHandler.swift:328-333`), c'est-à-dire Safari vers `https://localhost/legal/…`, qui n'existe pas hors de l'app.
  - Non vérifiable ici (pas d'iPhone ni de simulateur) : à confirmer sur TestFlight.
  - La politique reste joignable dans l'app par le pied de page (`components.jsx:393`, même cadre), ce qui suffit au 5.1.1(i). Le lien de l'écran de connexion serait en revanche un lien mort visible par l'examinateur.
  - Correctif : retirer `target="_blank"` des liens relatifs.
- **P2-2 · Révocation du jeton Apple à la suppression.** `DeactivateController.php:107` efface `apple_sub`, mais aucun appel à `https://appleid.apple.com/auth/revoke` n'existe : grep « revoke », seule occurrence Apple = `SocialIdentityVerifier.php:54`, qui sert à vérifier les jetons. Apple le demande dans sa documentation sur la suppression de compte ; le texte 5.1.1(v) récupéré ne le cite pas. Rarement testé en revue. Il faut une clé Sign in with Apple (.p8) côté serveur pour le faire (propriétaire).
- **P2-3 · Numéro de téléphone exigé après « Se connecter avec Apple »** (`account-v2.jsx:517-520`, `require_customer_phone` sur `POST /api/frontend/order`, `routes/api.php:2339`). Défendable au titre du 5.1.1(v) (« directly relevant to the core functionality ») : à justifier explicitement dans les notes, ce que `PUBLICATION.md:478` et `:517` font déjà.
- **P2-4 · Incohérences mineures de texte.**
  - Horaires : « de 18h à minuit » (`PUBLICATION.md:295`), « 18 h 00 à 00 h 30 » (`:469` et `:508`), et « closes 01:00 » dans le JSON-LD (`index.html:208`).
  - Chemin de suppression : politique « Fidélité → Mon profil → Zone dangereuse », app « Mon compte » (`screens.jsx:1979`).
  - `SavedCards` dit « tu règles au comptoir au retrait » (`loyalty-v2.jsx:135`), alors que la livraison se règle à la porte.
- **P2-5 · Commentaire contradictoire au backend.** `GuestSignupController.php:168-174` affirme « PAS de code fixe pour la revue », alors que `:188-193` et `:320-342` l'implémentent. Sans effet sur Apple, mais trompeur pour le prochain lecteur.

## Ce qui tient (contrôlé, pas de constat)

- **Compte de démonstration sans e-mail réel** : `GuestSignupController::emailLogin` remplace le jeton par le code fixe et n'envoie pas d'e-mail (`:188-193`). Garde-fous : e-mail exact, code à 4 chiffres, date de fin, invité uniquement (`:320-342`) ; config `config/auth.php:168-172`.
  - La commande `foodking:review-account` crée un compte invité avec téléphone (`ReviewAccountCommand.php:74-86`).
  - Le verify pose `email_verified_at` quand l'e-mail transmis correspond (`GuestSignupController.php:586-598`), et `guestVerify` envoie `email` (`api.js:636`). La suppression, protégée par `verify.api` (`routes/api.php:300-304`, `VerifyEmail.php:21`), passera donc.
  - **Déploiement en production non vérifiable ici** (pas de SSH) : le commit `5a466a5a3` n'est présent que sur `origin/pos/category-first-caisse-2026-06-23`.
- **Paiement (3.1)** : carte en ligne coupée dans l'app (`api.js:66`). Biens physiques, fidélité non achetable. Pas d'IAP requis.
- **ATS** : aucune clé `NSAppTransportSecurity` (`Info.plist` complet lu) ; API en https ; contrôle CI `.github/workflows/ios-build-check.yml:43`.
- **iPhone seulement** : `TARGETED_DEVICE_FAMILY = "1"` (`project.pbxproj:321`, `:343`) ; portrait (`Info.plist:73-76`).
- **Chaînes d'usage** : aucune permission caméra, localisation ou contacts utilisée, donc aucune chaîne requise.
- **Manifeste et chiffrement** : `PrivacyInfo.xcprivacy` inscrit dans Resources (`project.pbxproj:154`) ; `ITSAppUsesNonExemptEncryption=false` (`Info.plist:42-43`).
- **Sign in with Apple, partie client** : droit présent (`App.entitlements:18-21`) ; bouton affiché sur iOS sans meta (`app-native.js:651`) ; jeton envoyé à `POST /api/auth/social/apple` (`api.js:662`).
  - Audience serveur par défaut `fr.lecayenne.app` (`config/services.php:114`).
  - **Route et `APPLE_AUDIENCES` en production : non vérifiables** en GET. `curl -H 'Origin: https://localhost' https://vps-418872ac.vps.ovh.net/api/auth/social/apple` renvoie `200 text/html` : c'est la page attrape-tout de l'application, ce qui ne prouve ni l'existence ni l'absence de la route POST.
- **Politique et assistance joignables** : `curl -L` donne 200 pour `https://www.lecayenne.fr/legal/privacy.html` (identique au fichier local), `/legal/cgv.html` et `/`. La page d'accueil (URL d'assistance) porte `contact@lecayenne.fr` et `03 65 67 82 91` (1.5 OK).
- **CORS et version** : `GET /api/frontend/app/config` avec `Origin: https://localhost` renvoie `Access-Control-Allow-Origin: https://localhost` et `min_version 1.0.0` = `MARKETING_VERSION 1.0.0` (`project.pbxproj:315`). Pas de blocage.
- **Lancement** : aucune `pageerror` au démarrage sans session. Les erreurs n'apparaissent qu'à « Mon compte » (P0-1).

## Actions réservées au propriétaire

1. **Compiler ce candidat dans la CI.** Les seuls runs `ios-build-check` datent du 15/10, sur `main` (`gh run list --workflow ios-build-check.yml`) ; `app/pret-apple-2026-11-02` n'existe pas sur le dépôt distant (`git ls-remote` vide). Aucune preuve de compilation iOS pour ce HEAD.
2. **Serveur de production** : `foodking:review-account` + `APP_REVIEW_EMAIL`, `APP_REVIEW_OTP`, `APP_REVIEW_UNTIL` (date couvrant les éventuelles re-soumissions) ; vérifier que `5a466a5a3` et `SocialAuthController` sont bien déployés ; `APPLE_AUDIENCES` contient `fr.lecayenne.app`.
3. **App Store Connect** :
   - description et notes sans « pas de livraison » ;
   - App Privacy avec l'adresse physique ;
   - nouvelles captures ;
   - questionnaire d'âge ;
   - statut professionnel DSA ;
   - `APP_MOBILE_IOS_URL` une fois l'app créée.
4. **Portail Apple Developer** : capacité Sign in with Apple sur `fr.lecayenne.app` ; clé .p8 si la révocation (P2-2) est faite.
5. **TestFlight sur iPhone réel** : confirmer P2-1 (liens `_blank`), Sign in with Apple de bout en bout, demande de notification après commande, et suppression de compte.

## Correctifs de code (ordre conseillé)

1. Redéfinir `EN_APPLICATION` et remettre les filtres app (Avis, réseaux « bientôt », lien footer) → P0-1 et P1-2. Correctif urgent aussi pour **le site en production**.
2. Rétablir `rappelerCommande` et `signalerCommandePrete` dans `funnel.jsx` → P1-1 et P1-6.
3. Retirer le SDK Facebook (et Google tant qu'il est masqué) du binaire iOS ; compléter `PrivacyInfo.xcprivacy` (adresse) et `legal/privacy.html` (adresse de livraison, OVH, contradiction paiement, date) → P1-5.
4. Retirer `target="_blank"` des liens relatifs → P2-1.
5. Étendre `tools/verify-app-behaviour.mjs` : session connectée, « Mon compte » sans erreur avec bouton Supprimer, absence de « bientôt » et de « Avis » dans l'app, appel `LocalNotifications.requestPermissions` après une commande simulée.
6. Puis régénérer les captures (P1-4) et réécrire les textes `PUBLICATION.md` §6 et §8 (P1-3).

Captures de ce rapport, dans ce dossier : `1-accueil.png` (accueil dans l'app, avec bandeau livraison et onglets Avis/Réseaux), `2-avis.png`, `3-mon-compte.png` (écran blanc).
