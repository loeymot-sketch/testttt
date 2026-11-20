# Vague C (application des stores) — superviseur adverse, tour 1

Verdict (P0/P1 ouverts de disposition `fix` seulement) : **AMBER** — C-101 (P1, fragment anglais) et C-102 (P1, rapport inexact).
Hors verdict, restent P0/P1 : C-001 (P0), C-002, C-004, C-005, C-006 (P1), tous `owner_gate`. Aucune publication aux stores n'est possible en l'état.
Lecture seule respectée : git en lecture, `curl` sur le site public (fichiers statiques), SELECT sur `foodking_e2e`, un `artisan tinker` en lecture. Aucun PNG créé, aucun navigateur lancé.

## 1. Regardé (visuel d'abord)

PNG lus à l'image (14 de la vague + 3 appareil) : accueil 390/360, carte 390, wizard 390, panier 390/360, compte 390/360, erreur d'envoi 390, fidélité 390, suivi 390, confirmation 360, suppression 360, hors ligne 390, résultat suppression 390, et `device-android/` panier-ouvert, 03b-tiroir, 04-hors-ligne. Les autres états : métriques, DOM, console, réseau.

Constats de l'équipe confirmés à l'image : C-007 (boutons − / + ronds de 28 px, corbeille isolée sous la ligne), C-008 (dégradé gris à droite de 01/02/05/08 et de l'appareil), C-009 (numéro coupé à 360). Chiffres : panier 7,40 € = total 7,40 € au suivi = `orders.total` 7.400000 (8660, 8672), Réf. `2310268660` identique ; suppression : nom « Compte supprimé », commande 8647 annulée (statut 16).

Ce que l'équipe n'a pas vu :
- **C-103** — barre d'état de l'appareil illisible sur chaque PNG émulateur (icônes blanches sur fond quasi blanc). Cause lue : `app-native.js:250-253` suppose « le site est sombre » ; sous Android 36 (edge-to-edge) le fond demandé n'est pas appliqué.
- **C-104** — panier VIDE sur appareil : « PANIER » et « Ton panier est vide » en crème sur blanc (~1,07:1). Cause : `flows.jsx:89` et `.lc-cart-title` sans couleur héritent de `body.lcx-nuit` ; seul `.lcx-nuit .lc-modal` est épinglé (`styles-v7-braise.css:74`, même défaut déjà corrigé pour le compte, `styles.css:930`). État absent des 22 états capturés.
- **C-101** — `(and 1 more error)` affiché en français au client (DOM de N5 ; `tinker` confirme : pas de `lang/*.json`, chaîne issue de `ValidationException.php:99`).
- C-108/109/110 (P3) : descriptions tronquées sans `title` (relevées dans `metriques.json` mais non rapportées), « Se connecter » sur 2 lignes à 360 px et sur appareil 393 dp, puce orpheline, CTA de connexion à demi visible à 360×640.

## 2. Contestation de l'équipe

**(a) Les deux lignes — réponse mesurée.**
`HEAD..origin/main` = 25, `origin/main..HEAD` = 3, merge-base `b9e1f64` (29/09). `git merge-base` et les comptes de l'équipe sont exacts. Mais :
- Le site public (Vercel) ne sert **ni A ni B**. `compiled/funnel.js` public = `a263ecac…` = celui du commit `b9e1f64` (merge-base), `?v=20260929audit1`. A porte `20261020livraison3` (`77554915…`), B `20261002lot7` (`9a3a2715…`).
- Le rapport écrit « A identique octet pour octet à app/www ET au site public ». Faux pour l'arbre/HEAD : seul `app-native.js` est égal (`25cdc346`) ; `index.html` (arbre 424bac53, HEAD 4600b26d, public = `app/www` 97660fa8), `api.js`, `funnel.js` diffèrent. Le public égale `app/www`, pas A. → C-102.
- Le dépôt distant répond « not found » : `origin/main` est une ref locale possiblement périmée. Le ledger enregistre déjà la décision propriétaire « aucune publication avant réunion des chantiers » (C-002 n'est donc pas « nouveau »).
- Pire risque pour un client de store : **A**. Pont mort = bouton Apple sans effet, tuiles « bientôt », `SCHEDULE_EXACT_ALARM`, ni notification ni lien externe ni retour (rejet quasi certain, C-001/004/005). B perd la livraison et les suppléments recommandés ; or la livraison est suspendue en production et porte son propre P0 (M6-001, ASAP → KDS 500), donc son absence n'ôte rien à un client. La vraie cible est B + les 3 commits de A (ledger : 7 conflits dans 5 fichiers, 19 fichiers touchés par l'autre session) ; ce n'est pas à nous de fusionner.

**(b) Rejeux « sur B » : code publiable ?** Pas exactement. `git archive origin/main` + `build-app-www.mjs` + `api-base-url` réécrit vers `:8766` = bon proxy de ce qu'un build produit, mais : ce n'est pas le candidat fusionné ; Chromium, pas WebView ; origine `http://127.0.0.1:8012` (le `https://localhost` réel n'est couvert que par des pré-vols OPTIONS isolés) ; clé API réinjectée par `page.route`. Aucune preuve sur la livraison ni les suppléments recommandés dans l'app.

**(c) Les 3 corrections de `C-lib.js` : justes, pas de faux vert.** Relu `native-bridge.js` (sha 8ecc290b…, identique iOS/Android) : `cap.addListener` → `nativeCallback('addListener')` sans réponse de promesse ; le natif garde l'appel (`keepAlive`), donc `addListener` muet est fidèle ; `emettre` répond `save:true` (`native-bridge.js` ~985 ne supprime le rappel que sur `save===false`) ; l'`App.exitApp` du banc M10 était bien fabriqué. Le vrai `native-bridge.js` est injecté tel quel, sans `registerPlugin` (comme la coque). Écarts résiduels, non fautifs : `LocalNotifications.requestPermissions` toujours « granted » (refus jamais testé), `SocialLogin.login` renvoie un faux JWT. Vérité terrain indépendante : émulateur (retour tiroir fermé, 0 `exitApp`). La faiblesse est ailleurs : `C-captures.spec.js` n'a que 4 `expect` (C-105) : « 3/3 » = « rien n'a levé ».

**(d) « Émulateur 28/28 » : prouve / ne prouve pas.** Prouve : manifeste fusionné, permissions, démarrage sans plantage, retour, bandeau hors ligne, lien externe, GET prod depuis `https://localhost`. Ne prouve pas : commande, `social/*`, `delete-account`, pré-vol POST avec `Authorization`/`X-Idempotency-Key`/`X-LC-App-Version`, `RefuseOutdatedApp`, contre la PRODUCTION (contrat rejoué uniquement en local ; prod en staging et divergente de GitHub) → C-107, `environment`, non vérifiable d'ici.

**(e) Permissions / chaînes d'usage.** `AndroidManifest.xml` (B) : INTERNET + POST_NOTIFICATIONS ; ADS et `SCHEDULE_EXACT_ALARM` retirées par `tools:node="remove"`. `Info.plist` : aucune `NS*UsageDescription` ; recherche `geolocation|getUserMedia|capture=|type="file"|share` dans jsx/api.js/app-native.js (A et B) : rien → cohérent. `PrivacyInfo.xcprivacy` inscrit au projet (4 occurrences dans le pbxproj), UserDefaults + FileTimestamp déclarés. Suppression de compte DANS l'app : OK (zone dangereuse, deux temps, bouton « Oui, supprimer mon compte »). Manque : révocation du jeton Sign in with Apple (C-106). Comptes sociaux : `email_verified_at` posé (`SocialAuthController.php:148,171`), donc la porte `verify.api` de `delete-account` ne les bloque pas.

**(f) Ledger.** Connus : C-001 (M10-001), C-003 (M10-002), C-004/005 (M10-007), C-006 (M1-004/M5-001 + `SITE_PATCH_viandes_menu-js.diff`, étiqueté « nouveau » à tort), C-002 (décision propriétaire). Nouveaux : C-007/008/009 (confirmés), C-101..C-110.

## 3. Réfuté
- « Le public sert la ligne A » : faux (il sert `b9e1f64`).
- « C-006 nouveau » et « C-002 nouveau » : déjà au ledger.
- Soupçon de faux pont fabricant un vert : réfuté par la relecture de `native-bridge.js` et l'émulateur.
- Soupçon CORS `X-LC-App-Version` : `allowed_headers ['*']` (`config/cors.php`), motifs `https://localhost|capacitor://localhost|ionic://localhost` actifs. Réfuté en local.
- Confettis sur « ce QR » (M10-008) : non reproduits sur `09a-confirmation-360.png`.
- Clés i18n brutes / NaN / débordement X : `cles: []`, `debordeX:false` sur les 22 états, conforme.

## 4. Non vérifiable
iOS (pas de Xcode, CI distante illisible) ; connexion Apple/Google réelle ; envoi d'e-mail de bout en bout (SMTP de dev) ; production (CORS POST, routes `social/*`, `RefuseOutdatedApp`) ; défilement réel de la modale à 360×640 (C-110) ; comportement du tiroir vide hors nuit (C-104 dépend de `lcx-nuit`, à reproduire de jour) ; état « prête » + notification de fin.
