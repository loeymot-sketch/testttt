# A2 — Examinateur Apple adverse, passe 2 · « Le Cayenne » iOS (fr.lecayenne.app)

- **Candidat** : worktree `/Users/1millnonstop/Downloads/lecayenne-web-deploy/app-pret-apple-fix-2026-11-02`, branche `app/pret-apple-fix-2026-11-02`, HEAD `df753a8`. Il contient 11 commits depuis `bc645b3` (`git log --oneline bc645b3..HEAD`).
- **Serveur** : `127.0.0.1:8933`, processus Python 82829, dossier courant = ce worktree (vérifié avec `lsof`).
- **Paquet embarqué** : `app/www` est un artefact ignoré par git (`.gitignore:17`). Il est identique octet pour octet à la racine (`cmp`) pour `index.html`, `app-native.js`, `api.js`, `compiled/{funnel,components,racine,screens,orders,loyalty-v2,account-v2}.js`, `styles-v8-vitrine.css` et `legal/privacy.html`.
- **Mes sondes** (dans le scratchpad, sous `/private/tmp/claude-501/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/62fb8233-57cf-4748-9360-e97d19514308/scratchpad/a2/`) : `probe2.mjs`, `probe3.mjs`, `probe4.mjs` et `probe5.mjs`. Les résultats sont dans `out/resultats.json` et les captures dans `out/*.png`.
  - Pont iOS de la forme exacte de `PONT_CAPACITOR` (`tools/verify-app-behaviour.mjs:35-54`).
  - Variante « accordé », de même forme : seules les réponses de `requestPermissions`/`checkPermissions` (`{display:'granted'}`) et de `schedule` changent.
  - Tout appel au VPS est simulé (`tests-e2e/helpers-mock-backend.js`). `ctx.route` bloque tout non-GET et toute requête avec en-tête `Authorization` vers une autre cible que 127.0.0.1. Requêtes bloquées pendant mes sondes : 0. Aucune écriture en production.

## Verdict : le binaire est soumissible côté code, mais pas tant que le site n'est pas déployé en production

Les 6 corrections de code majeures sont réelles, et je les ai observées dans le navigateur. Restent :
- un blocage côté propriétaire, devenu bloquant **à cause d'un correctif** : la production `www.lecayenne.fr` n'a pas reçu ces commits (N1) ;
- une hypothèse fausse dans le dossier, sur « Se connecter avec Apple » (N2) ;
- 6 points P2.

---

## 1. Portes de sortie

**`node tools/verify-app-behaviour.mjs --port 8933`** → `✓ 26 contrôles — comportement correct dans l'application ET dans le navigateur.` Les 26 contrôles sont verts, dont :
- « Mon compte » s'ouvre pour un client connecté ;
- « Supprimer mon compte » est visible ;
- aucun onglet « Avis » ;
- `#avis` ne rouvre pas la page « bientôt » ;
- aucun « bientôt » dans l'app ;
- le site garde sa note « bientôt » et son onglet Avis ;
- un lien légal s'ouvre en `Browser.open:https://www.lecayenne.fr/legal/cgv.html`.

**`LC_BABEL_ROOT=…/scratchpad/br/node_modules node tools/release-gates.mjs`** → `✓ 6 portes vertes — publication possible.`
- Sources compilées à jour ;
- jetons `?v=` à jour ;
- `app/www` à jour ;
- iOS 1.0.0 = Android 1.0.0 ;
- aucune clé de signature suivie ;
- `SCHEDULE_EXACT_ALARM` retirée.

**Même commande avec `--prod`** (un GET, plus un OPTIONS de pré-vol CORS : lecture seule) → `✓ 9 portes vertes`.
- `min_version` 1.0.0 en ligne ;
- 1.0.0 n'est pas plus ancienne ;
- CORS : `https://localhost` + `x-lc-app-version` acceptés.

⚠ Ces portes ne contrôlent ni la production du **site** (N1), ni l'absence du SDK Facebook dans le binaire compilé (N5).

---

## 2. Constats de la passe 1 : corrigés ou non

| # | Constat passe 1 | État à `df753a8` | Preuve |
|---|---|---|---|
| P0-1 | « Mon compte » en écran blanc (`EN_APPLICATION` non défini) | **CORRIGÉ dans le binaire et dans le code du site.** **NON corrigé en production du site** (voir N1) | `components.jsx:59-65` redéclare `EN_APPLICATION`. Sonde B : app connectée → « Mon compte » rendu (1 969 car.), « Supprimer mon compte » → « Supprimer définitivement ? » + « Oui, supprimer mon compte », 0 `pageerror` (`out/B-mon-compte.png`). Sonde 4 : site connecté → bouton Supprimer visible, 0 erreur |
| P1-1 | Notifications jamais déclenchées | **CORRIGÉ** | `funnel.jsx:1418-1428` appelle `rappelerCommande` (retrait non payé en ligne, `orderType !== 5`) ; `funnel.jsx:3178-3195` appelle `signalerCommandePrete`. Sonde C, détaillée sous le tableau |
| P1-2 | Contenu « bientôt » dans l'app (Avis, pied de page, réseaux) | **CORRIGÉ dans l'app, préservé sur le site** | Filtres : `components.jsx:256` (onglet), `:415` (lien de pied de page), `:469-471` (note), `RESEAUX_AFFICHES` `:65`. Redirection `racine.jsx:642-647` (`#avis` → accueil). Balayage détaillé sous le tableau |
| P1-3 | Fiche « livraison uniquement via Uber Eats » | **CORRIGÉ (texte source)** | `app/PUBLICATION.md:292-294` (« livraison par le restaurant… jusqu'à 10 km… Au-delà, Uber Eats »), `:498-501` (FR) et `:537-540` (EN). Production en lecture : `GET /api/frontend/delivery/status` → `enabled:true, max_km:10`, ce qui est cohérent. À recopier dans App Store Connect (propriétaire) |
| P1-4 | Captures périmées (Google, ancien écran) | **CORRIGÉ** (résidus P2, voir N6) | Les 5 images `app-store-iphone-6.9/*.png` sont relues avec Read : 1290×2796, sans alpha (`sips`), format accepté pour l'emplacement 6,9″. `5-compte.png` : « Se connecter avec Apple » + e-mail seul, légende « Avec Apple ou ton e-mail ». Aucun « Google », aucun onglet « Avis » ; navigation = Accueil/Menu/Réseaux/Itinéraire |
| P1-5a | Adresse postale non déclarée | **PARTIEL** | Tableau App Privacy `PUBLICATION.md:370` : OK. Politique `legal/privacy.html` (ligne « Livraison ») : OK. **`app/ios/App/App/PrivacyInfo.xcprivacy` non modifié** : aucun `NSPrivacyCollectedDataTypePhysicalAddress`, 5 types seulement (Name, Email, Phone, PurchaseHistory, UserID ; dernier commit `61980be`). `PUBLICATION.md:397` dit encore « cinq données » |
| P1-5b | SDK Facebook (pistage) embarqué | **CORRIGÉ par configuration** | `app/capacitor.config.json:44-51` : `SocialLogin.providers {apple:true, google:false, facebook:false, twitter:false}`. Mécanisme et simulation détaillés sous le tableau |
| P1-5c | Politique inexacte (OVH absent, Vercel seul, date) | **CORRIGÉ dans le dépôt, NON en ligne** | `legal/privacy.html` : OVH SAS Roubaix, BAN/OSRM, Mollie site vs comptoir/porte dans l'app, Apple seul, date du 2 novembre 2026. En ligne : `curl -s https://www.lecayenne.fr/legal/privacy.html \| grep -c "OVH SAS"` → `0`, date « 18 mai 2026 ». Voir N1 |
| P1-6 | Valeur native mince (4.2) | **AMÉLIORÉ** | Notifications locales réelles (P1-1), haptique « prête », `Browser` intégré pour les pages légales. « Partage natif » retiré de l'argumentaire (`PUBLICATION.md:328-331`). Le risque 4.2 subsiste, comme pour toute app de commande de restaurant faite à partir de son site, mais l'argumentaire est désormais vrai |
| P2-1 | Liens relatifs `target=_blank` → Safari sur `https://localhost` | **CORRIGÉ** | `app-native.js:129-158` (`lienInterneNouvelOnglet`). Sonde 5, vrais liens de l'écran de connexion : `Browser.open:https://www.lecayenne.fr/legal/privacy.html` puis `…/cgv.html`, 0 popup, la vue web reste sur place. Dépend de N1 |
| P2-2 | Pas de révocation du jeton Apple | **NON corrigé** | `grep -rn "auth/revoke\|appleid.apple.com" app config` (testttt) → seulement `SocialIdentityVerifier.php:54-55` (JWKS). `DeactivateController.php:107` efface `apple_sub` seulement |
| P2-3 | Téléphone exigé après Apple | Inchangé, justifié dans les notes | `PUBLICATION.md` « SE CONNECTER EN UN INSTANT » |
| P2-4 | Incohérences de texte | **PARTIEL** | Horaires alignés : JSON-LD `index.html:263-264`/`284-285` `18:00–23:59`, notes `:471` « 18 h à minuit », production `ouverture "18:00"`. Encore faux : politique `legal/privacy.html:281` « Fidélité → **Mon profil** → Zone dangereuse » (l'app dit « Mon compte ») ; `loyalty-v2.jsx:135` « tu règles au comptoir au retrait » (la livraison se règle à la porte) |
| P2-5 | Commentaire contradictoire au backend | **NON corrigé** | `testttt/app/Http/Controllers/Auth/GuestSignupController.php:168` « PAS de code fixe pour la revue App Store » |

**Détail de la sonde C (P1-1)** : commande retrait simulée, `POST /api/frontend/order` simulé avec `{order_type:10, payment_method:1}` et `tracking.wait_low = 10`.
- **Pont exact (réponses `null`)** : `LocalNotifications.requestPermissions` est appelé juste après la commande. C'est la demande que verra l'examinateur. Aucun `schedule`, puisque l'autorisation n'est pas accordée.
- **Variante accordée** :
  - `schedule` est appelé avec `id 94111`, `schedule.at = t0 + 10 min` et le texte « Ta commande N°A0077 devrait bientôt être prête… » ;
  - la confirmation affiche « Tu recevras une notification… » ;
  - au passage à « prête » (statut 8) : `cancel` de `94111`, `Haptics.notification SUCCESS`, `checkPermissions`, puis `schedule` de `94112` avec « Ta commande N°A0077 est prête ! » ;
  - 0 `pageerror`.
- Captures : `out/C-*.png`.

**Détail du balayage (P1-2)** : dans l'app, sur les routes home, menu, orders, loyalty, reseaux, avis, payment, track et confirm, et sur « Mon compte » connecté, on trouve 0 occurrence de `/bient[ôo]t/i` dans le texte rendu.
- Navigation et tiroir : `["Commandes","Fidélité","Panier","Se connecter","Accueil","Menu","Réseaux","Itinéraire"]`, sans « Avis ».
- Pied de page : ni « Avis clients », ni note « bientôt ».
- Sur le site sans pont (sonde D), le choix du propriétaire est bien conservé : note « Instagram, TikTok et Snapchat arrivent bientôt. », lien « Avis clients », page `#avis` « vos mots ici », tuiles « BIENTÔT ». 0 erreur.

**Détail de P1-5b (SDK Facebook)** :
- Le greffon déclare `"capacitor:sync:before": "node scripts/configure-dependencies.js"` (`node_modules/@capgo/capacitor-social-login/package.json:47`).
- Capacitor CLI 8.5.0 exécute ce crochet pour chaque greffon (`node_modules/@capacitor/cli/dist/tasks/sync.js:45`, puis `common.js:124-130`).
- La CI exécute `npm run sync:ios` (`.github/workflows/ios-build-check.yml:28`).
- **Simulation** du script sur une copie (`CAPACITOR_PLATFORM_NAME=ios CAPACITOR_CONFIG="$(cat app/capacitor.config.json)" node cfg.cjs`, sortie 0) :
  - `Package.swift` : `.package(…facebook-ios-sdk…)`, `FacebookCore`, `FacebookLogin`, `GoogleSignIn-iOS` et `GoogleSignIn` sont **commentés** ;
  - Alamofire est conservé, ce qui est nécessaire pour Apple.
- Le Swift compile sans ces dépendances grâce aux gardes `#if canImport(FBSDKLoginKit)` (`FacebookProvider.swift:4,19`) et `#if canImport(GoogleSignIn)` (`GoogleProvider.swift:3,12`).
- Leurs talons ne contiennent aucun appel ATT (`FacebookProvider.swift:230-264`).
- `initialize` rejette proprement, sans `fatalError`, quand Google n'est pas compilé (`SocialLoginPlugin.swift:174-178`). Les métadonnées Google sont vides (`index.html:133-134`).

---

## 3. Nouveaux constats

### N1 · P1 (action propriétaire : déploiement du site) — la production `www.lecayenne.fr` n'a rien reçu, et le binaire en dépend maintenant

- `curl -s https://www.lecayenne.fr/` charge `compiled/components.js?v=20261029appstore2`.
- `curl …/compiled/components.js | grep -c EN_APPLICATION` → `0`, et `curl …/compiled/loyalty-v2.js | grep -c EN_APPLICATION` → `3`. **Le P0 « Mon compte » en écran blanc est donc toujours en ligne pour tout client connecté du site.**
- La politique en ligne date du « 18 mai 2026 » et ne contient pas OVH.
- Pourquoi c'est devenu un risque d'examen :
  - le correctif P2-1 envoie désormais les liens légaux de l'app vers la page **publique** (`app-native.js:134` `SITE_PUBLIC = 'https://www.lecayenne.fr'`) ;
  - l'URL de confidentialité déclarée à App Store Connect (`PUBLICATION.md`, § URL) est la même.
  - L'examinateur lira donc la politique **périmée** (« Vercel seul », Google, aucune adresse de livraison) au lieu de celle du dépôt. C'est une non-conformité 5.1.1(i) : politique inexacte au regard des étiquettes App Privacy.
- La branche n'est pas non plus sur le dépôt distant : `git ls-remote origin 'refs/heads/app/pret-apple*'` est vide et `git branch -r --contains HEAD` aussi.
- **Action** : publier ce HEAD sur le site (compilation + jetons, déjà verts), puis vérifier en ligne :
  - `curl -s https://www.lecayenne.fr/legal/privacy.html | grep -c "OVH SAS"` → ≥ 1 ;
  - `components.js` contient `EN_APPLICATION`.
- À faire **avant** l'envoi à Apple.

### N2 · P1 (dossier + vérification propriétaire) — « Se connecter avec Apple » est toujours affiché sur iPhone, alors que le dossier le dit conditionnel

- `app-native.js:673` : `if (plateforme === 'ios') liste.push('apple')`, sans aucune condition. Capture `out/A-connexion.png` : le bouton est présent.
- `PUBLICATION.md:459-461` affirme « le bouton n'apparaît que si les identifiants Apple du §5 sont posés ». Les notes FR et EN marquent le paragraphe Apple « À n'ajouter QUE si les identifiants… sont posés ».
- Le propriétaire peut donc légitimement l'omettre. L'examinateur, lui, verra le bouton et le touchera.
- S'il échoue, c'est un refus 2.1, et 4.8 si la connexion Apple ne fonctionne pas. Causes possibles :
  - capacité Sign in with Apple absente du profil de provisionnement ;
  - `APPLE_AUDIENCES` sans `fr.lecayenne.app` ;
  - `POST /api/auth/social/apple` non déployé.
- Ce n'est pas vérifiable en GET.
- **Code / dossier** : corriger `PUBLICATION.md:459-461` et rendre le paragraphe Apple **inconditionnel** pour iOS.
- **Propriétaire** : tester Apple de bout en bout sur TestFlight avant l'envoi.

### N3 · P2 — Le chemin des notes « Fidélité › Mon compte » cache l'onglet hors de l'écran

- Sonde 3, connecté, `#loyalty`, largeur 440 px : les onglets « Mes réductions » x=16, « Historique » x=157 et « Comment ça marche » x=266 sont visibles. **« Mon compte » est à x=440 (`visibleX:false`)**. À 320 px, « Comment ça marche » est hors écran lui aussi.
- Sur `out/B-mon-compte.png`, la rangée montre trois pastilles entières, sans pastille coupée : rien n'indique qu'il faut faire défiler horizontalement. C'est un effet de bord probable de `51cbde4`, qui a transformé les onglets en pastilles.
- L'icône de compte de l'en-tête mène bien au profil (sonde B), et le bouton Supprimer y est.
- **Correctif au choix** :
  - notes : « touchez l'icône de compte ronde en haut à droite, puis descendez jusqu'à Zone dangereuse » ;
  - ou faire passer à la ligne la rangée d'onglets de fidélité.

### N4 · P2 — `PrivacyInfo.xcprivacy` sans adresse postale

C'est le résidu de P1-5a, détaillé dans le tableau. Il crée une incohérence entre le rapport de confidentialité Xcode et l'étiquette App Store Connect, désormais à six données.

**Code** : ajouter un bloc `NSPrivacyCollectedDataTypePhysicalAddress` (Linked = true, Tracking = false, AppFunctionality), puis corriger « cinq » à `PUBLICATION.md:397`.

### N5 · P2 (CI) — Aucune preuve compilée que Facebook et Google sont absents

- L'exclusion repose sur le crochet exécuté pendant `cap sync`. Le `node_modules` local n'est **pas encore réécrit** : `node_modules/@capgo/capacitor-social-login/Package.swift:15,17,27-29` référence encore `facebook-ios-sdk` et `GoogleSignIn`.
- Une archive Xcode faite sans `npm run sync` les embarquerait. La procédure `PUBLICATION.md:95` et `:679` exige bien `npm run sync`, mais rien ne le vérifie.
- **Correctif** : ajouter une étape dans `ios-build-check.yml` après la compilation qui échoue si `"$RUNNER_TEMP/dd/SourcePackages/checkouts"` contient `facebook-ios-sdk` ou `GoogleSignIn-iOS`.
- **Propriétaire** : pousser la branche pour lancer la CI.

### N6 · P2 — Finitions des captures de fiche

- `3-carte-defilement.png` : le texte « 40 produits » apparaît en fantôme sous la barre d'onglets collante, entre la navigation et les tuiles (vers y≈630 sur l'image affichée).
- `1-accueil.png` montre « SERVICE FERMÉ · OUVRE DANS 1 H ». C'est exact, mais peu vendeur.
- Contradiction de légende : `tools/build-store-frames.mjs:79` « Tes points se cumulent en ligne comme au comptoir. **Rien à présenter.** », alors que l'app dit « Présente ton QR à la caisse pour cumuler » (`screens.jsx:1882`). C'est une petite inexactitude au sens de 2.3.1. À reformuler, par exemple « Ton QR suffit au comptoir ».

---

## 4. Ce qui tient (contrôlé)

- **Démarrage** : aucune `pageerror` dans l'app (déconnecté ou connecté) ni sur le site. Appels natifs au lancement : `StatusBar.setStyle`, `Keyboard.setAccessoryBarVisible`, `App.addListener:backButton`, `Network.getStatus` + écouteur, `App.getInfo`, `App.addListener:appStateChange`, `SplashScreen.hide`.
- **Notifications limitées au retrait** : `funnel.jsx:1418` (`orderOpts.orderType !== 5`). Une livraison « en route » ne déclenche que l'haptique (`funnel.jsx:3184-3187`).
- **Réglage « Statut de commande »** : il est respecté par `notifsCommandeVoulues()` (`app-native.js:390-395`), qui est appelé avant toute demande.
- **Suppression du compte** :
  - elle retire tous les rappels (`loyalty-v2.jsx:180-183`, `annulerTousLesRappels`) ;
  - le texte de confirmation est explicite (conservation légale des tickets 6 ans).
- **Description et notes** : « une alerte dès qu'elle l'est quand l'application est ouverte », ce que fait réellement le code (`signalerCommandePrete` n'est appelé que par la page de suivi).

## 5. Actions réservées au propriétaire

1. **Déployer ce HEAD sur `www.lecayenne.fr`** (N1). Cela corrige aussi l'écran blanc « Mon compte » des clients du site.
2. **Pousser `app/pret-apple-fix-2026-11-02`** et lancer `ios-build-check`. Aucune compilation iOS n'existe pour ce HEAD.
3. **App Store Connect** :
   - description et notes de `PUBLICATION.md` (livraison, notifications) ;
   - App Privacy avec l'adresse postale ;
   - les 5 nouvelles captures ;
   - URL de confidentialité **après** le déploiement N1.
4. **Serveur de production** : `foodking:review-account`, `APP_REVIEW_EMAIL`/`APP_REVIEW_OTP`/`APP_REVIEW_UNTIL`, `APPLE_AUDIENCES ∋ fr.lecayenne.app`, route `POST /api/auth/social/apple` déployée (N2). Clé `.p8` si la révocation P2-2 est faite.
5. **TestFlight sur iPhone réel** :
   - Sign in with Apple de bout en bout (N2) ;
   - demande de notification après une commande à emporter, puis rappel reçu application fermée ;
   - CGV et confidentialité ouvertes dans le navigateur intégré ;
   - suppression du compte.

## 6. Correctifs de code restants (ordre conseillé)

1. `PUBLICATION.md:459-461` et les notes FR/EN : paragraphe Apple inconditionnel sur iOS (N2) ; chemin de suppression par l'icône de compte (N3).
2. `PrivacyInfo.xcprivacy` : ajouter `PhysicalAddress`, et passer `PUBLICATION.md:397` à six données (N4).
3. `.github/workflows/ios-build-check.yml` : faire échouer la CI si `facebook-ios-sdk` ou `GoogleSignIn-iOS` apparaît dans les paquets résolus (N5).
4. P2-4 restants :
   - `legal/privacy.html:281` : « Mon profil » → « Mon compte » ;
   - `loyalty-v2.jsx:135` : mentionner le règlement à la porte en livraison.
5. Légende `tools/build-store-frames.mjs:79` et capture fantôme « 40 produits » (N6), puis régénérer les fiches.
6. P2-2 (révocation Apple, nécessite la clé `.p8`) et P2-5 (commentaire `GuestSignupController.php:168`).
