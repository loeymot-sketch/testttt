# Vague A — L'application des stores face à R1..R7 (état des lieux 2026-10-23)

Lecture seule sur le produit. Spec : `tests/Playwright/etat-des-lieux-2026-10-23/A-app-livraison.spec.js` (11 tests, tous verts en constat ; un test, A-R6, a expiré une fois sous charge machine puis passé seul en 46 s). Preuves : `reports/test-e2e/etat-des-lieux-2026-10-23/A-app-evidence/` (11 PNG lus, `constats.json`). Défauts : `A-app-findings.json` (12 entrées : 6 P1, 5 P2, 1 P3 ; aucun P0).

## Verdicts

| Exigence | app/www tel qu'embarqué | Arbre de travail du site (ce que l'app deviendrait après `build:www`) |
|---|---|---|
| R1 choix À emporter / Livraison au début | ABSENT : « Livraison » est un lien Uber Eats (`deliveryEnabled=false`) | PARTIEL : vrai choix, mais seulement après « Passer commande » |
| R2 adresse + liste de suggestions | ABSENT (aucun champ adresse) | PARTIEL : `<datalist>` alimentée par `/delivery/suggest` ; rendu WebView non prouvé |
| R3 trajet routier, < 7 km nos livreurs, ≥ 7 km Uber Direct, Uber Eats au-delà | ABSENT | FAUX : distance à vol d'oiseau, barème jusqu'à 10 km, aucun devis Uber Direct, Uber Eats au-delà de 10 km |
| R4 « Nouveau : la livraison ! » | ABSENT | ABSENT |
| R5 vouvoiement | FAUX : 46 segments tutoyés à l'écran, 188 chaînes tutoyées embarquées | FAUX (mêmes chaînes) |
| R6 paiement choisi après confirmation | ABSENT : un seul moyen, choisi avant « Confirmer » | ABSENT : idem, libellé « au comptoir » même en livraison |
| R7 QR d'avis | ABSENT : le QR de la confirmation est le ticket de retrait ; page « Avis » = texte d'attente | ABSENT |

## (a) app/www contre le site

- `app/www` est ignoré par git (`.gitignore:17`), construit le 17/10 19:44 ; les copies natives `app/android/.../assets/public` et `app/ios/App/App/public` sont identiques (seuls `cordova.js` et `cordova_plugins.js` en plus).
- sha256, 246 fichiers : **9 fichiers divergent de l'arbre de travail** : `api.js`, `carte.html`, `compiled/funnel.js`, `compiled/wizard-v2.js`, `data/menu.js`, `index.html`, `plat/cayenne.html`, `plat/supreme.html`, `sandwichs-galettes.html`. `app-native.js`, `screens.js`, `racine.js`, `components.js` sont identiques.
- Effet sur R1..R6 : `compiled/funnel.js` et `api.js` d'`app/www` précèdent la livraison du 20/10 (jeton `20260929audit1`, `feature-delivery` par défaut `0`, aucune meta dans `index.html`) : plus de statut serveur, plus de suggestions, plus de devis, plus d'adresse. `compiled/wizard-v2.js` et `data/menu.js` d'`app/www` portent le Cayenne à 2 viandes (A-003).
- `npm run check:www` (lu puis lancé en lecture seule : `--check` n'écrit rien) : échec « SOURCES NON RECOMPILÉES : screens.jsx ». Le script refuserait donc de reconstruire.

## (b) Quelle ligne de commits ?

`app/www` ne reflète **aucune des deux lignes** à jour. Preuves :
- `git rev-list --left-right --count HEAD...origin/main` = 3 / 25 ; merge-base `b9e1f64` (29/09).
- `app/www/compiled/funnel.js` et `app/www/app-native.js` sont identiques au blob de `b9e1f64` (point de bifurcation), donc **ligne HEAD (`fix/paiement-visible-et-encoche-2026-09-28`) avant ses 3 commits** (livraison `afe30f7`, `2dff956`, `681577d`), avec en plus des fichiers non commités de l'arbre de travail du 17/10 (les 88 écarts avec `b9e1f64` sont ces fichiers : images, pages SEO, `data/menu.js`, `components/racine/screens.js`).
- Nombre de fichiers différents de : HEAD 90, origin/main 99, b9e1f64 88.
- `origin/main` (ae8d0ea) porte le pont natif corrigé (`cac473a`, absent de HEAD) mais pas la livraison ; l'APK du scratchpad (`app-natif-main.apk`) en est issu : `api.js` sans `deliveryStatus` (0 occurrence), `app-native.js` identique au paquet `site-www-main`. Reconstruire depuis l'une ou l'autre ligne perd donc la moitié du travail : il faut fusionner les deux avant tout build.

## (c) Dans la WebView (390x844, UA Android WebView, pont réel `native-bridge.js` sans `registerPlugin`)

- Instrument prouvé (A-INS-1) : le paquet témoin `origin/main` fait partir 9 appels natifs au démarrage et appelle `Browser.open` sur un lien externe ; `app/www` et l'arbre de travail : **0 appel**, clic annulé (`defaultPrevented=true`) sans `Browser.open`. `app-native.js:38-40` exige `registerPlugin`, que le site ne charge pas. Conséquence pour R1/R3 : le seul chemin « livraison » d'`app/www` (« Sur Uber Eats -> ») et le lien « Commander sur Uber Eats » hors zone sont inertes dans la coque. À confirmer sur appareil (A-001).
- Hors pont, le tunnel se comporte comme sur le site : mêmes écrans, mêmes tranches (3,90 / 4,90 / 5,90 / 6,90 € mesurés à Hénin, Carvin 7,99 km, Lens 8,89 km ; Douai 10,87 km « trop loin »). Le paiement en ligne est coupé dans l'app par conception.
- Adresse : un `<input list=… autocomplete="street-address">` + `<datalist>` ; 21 requêtes `/delivery/suggest` pour 24 caractères, toutes 200 ; aucune liste ARIA ; il faut presser « Calculer ». Le popup de la datalist n'est pas capturable : **à vérifier sur Android WebView et iPhone** (A-009).
- Clavier : `capacitor.config.json` règle `Keyboard.resize = native` ; aucun `windowSoftInputMode` dans `AndroidManifest.xml`. Simulation à 390x544 : le champ reste dans la fenêtre rétrécie (top 190, bottom 234). Le recouvrement du bouton « Confirmer » par le clavier n'est pas démontrable sans appareil.
- Permissions : Android = `INTERNET` + `POST_NOTIFICATIONS` seulement (les permissions publicitaires sont retirées) ; aucune localisation ; iOS : aucune clé `NS*UsageDescription` dans `Info.plist` ; le code n'appelle pas `navigator.geolocation`. Cohérent, mais aucune « adresse actuelle » n'est possible dans l'app.

## (d) APK / émulateur

`app-natif-main.apk` existe (15,6 Mo, 23/10 01:33) mais c'est un build de `origin/main` : sans livraison. `tools/verify-app-device.py` n'est **pas** dans l'arbre de travail (branche HEAD) ; il existe seulement sur `origin/main` et `app/natif-honnete-2026-09-30`. Aucun appareil ni émulateur (`adb devices` vide) ; aucun démarré. Restent à vérifier sur appareil : A-001 (liens externes et appels natifs), A-009 (liste de suggestions), clavier sur le champ adresse, notifications.

## Effets de bord sur la base locale `foodking_e2e`

- 1 commande de retrait E2E-APP- : #8846, 4,90 €, 201 (test A-R7). Les autres confirmations ont été interceptées (`route.abort`) avant envoi.
- Le clic sur « Confirmer » en livraison a enregistré une adresse (id 140) pour le client E2E-APP-.
- L'état de la livraison n'a pas été modifié (active à l'origine, activée localement).
- Mes serveurs statiques temporaires (ports 8013, 8014) sont arrêtés.
