# Vague C — Application des stores (Capacitor 8, `fr.lecayenne.app`) — TOUR 1 : capture + constat

Date : 2026-10-23 (≈ 00:00–01:45 CEST). Aucun code produit modifié. Aucune écriture dans le dépôt du site
(`Site lecayenne`) : lectures + `git archive origin/main` exporté vers le scratch.

## (a) Environnement — et le fait structurant

Backend `:8766` (base `foodking_e2e`, `APP_ENV=local`, SMTP `smtp.example.com` injoignable), miroir `:8011`.
Le dépôt du site porte **deux lignes divergentes** (`git rev-list --left-right --count HEAD...origin/main` = `3  25`) :

| Ligne | Contenu | Pont natif | Servi par |
|---|---|---|---|
| **A = arbre de travail** `fix/paiement-visible-et-encoche-2026-09-28` @ `681577d` + 118 fichiers non commités d'une autre session | livraison (`afe30f7`), suppléments recommandés (`681577d`) | **mort** (`app-native.js` sha `25cdc346…`) | miroir `:8011` ; **identique octet pour octet à `app/www/` du dépôt ET au site public www.lecayenne.fr** (sha256 de `app-native.js`, `index.html`, `api.js`, `compiled/funnel.js` égaux, `curl` du 23/10) |
| **B = `origin/main`** (@ `ae8d0ea`, contient `app/natif-honnete-2026-09-30` : 25 commits « natif honnête », portes de publication, CI iOS) | pas `afe30f7`/`2dff956`/`681577d` | **réparé** (`app-native.js` sha `7a86aeb0…`) | `:8012` (que j'ai construit : `git archive origin/main` → `node tools/build-app-www.mjs` → `app/www` avec `api-base-url` réécrit vers `:8766`) |

Tout ce qui suit est donc dit **par ligne**. Les deux specs d'origine (M10) ont été jouées contre A (`:8011`) ; j'ai ajouté B (`:8012`).
Le `.git` du site répond « Repository not found » à un `fetch --dry-run` : l'état de `origin/main` est celui de la ref locale.
Node v22.23.2. Émulateur Android 36 (`lecayenne-api36`) démarré par moi puis arrêté (`adb emu kill`). Xcode absent (`xcodebuild` → « requires Xcode »).

Comptes/commandes de test (préfixe `E2E-APP-`) : client `E2E-APP-Client` (user 914, `e2e-app-1@example.invalid`), comptes jetables `E2E-APP-Suppr` (supprimés),
commandes 8613, 8647–8649, 8652, 8653, 8660, 8672, 8675 (+ celles créées par `C-CAP-SUPPR`), toutes à emporter, note « E2E-APP- ».

## (b) États capturés (PNG ; chaque état a aussi `.dom.html`, `.console.json`, `.network.json`)

Dossier : `tests/e2e/__screenshots__/e2e-complet-C/` (38 PNG).

- `captures-natif-8012/` — ligne B, coque Android, **390×844 et 360×640** : `01-accueil`, `02-carte`, `03-wizard`, `04-panier`, `05-compte-deconnecte`,
  `06-compte-inscription-invite`, `07-compte-connu-erreur-envoi`, `08-fidelite-compte`, `09a-confirmation`, `09-suivi`, `10-suppression-confirmation`, `11-hors-ligne`
  (suffixes `-390` / `-360`), + `12-suppression-resultat-390`. Mesures : `metriques.json`.
- `natif-natif-8012/` : `N3-hors-ligne-390`, `N4-panier-ouvert-avant-retour` ; `natif-main-8011/N4-…` (ligne A).
- `rerun-main-8011/` et `rerun-natif-8012/` : `A1-accueil-android-pont-reel`, `A3-2-apres-clic-apple-pont-reel`, `B1-2-apres-clic-apple-pont-corrige` + `constats.json` + `run.log`.
- `device-android/` : émulateur réel (APK construit depuis B) — `panier-ouvert`, `apres-retour-panier`, `04-hors-ligne`, `03b-tiroir`, `verify-app-device.log`.
- `contrat/constats.json` : pré-vols CORS, codes/messages API, suppression de compte.
- Non capturé en PNG (voir (e)) : iOS.

Relu à l'image : 04-panier (360 et 390), 07-erreur-envoi (390), 09-suivi (390), 10-suppression (360), 12-résultat, device `panier-ouvert`, crop d'ombre.

## (c) Résultats des specs et classement des causes

Specs : `tests/Playwright/e2e-complet-2026-10-22/C-lib.js` (outillage), `C-m10-rerun.spec.js`, `C-natif.spec.js`, `C-contrat-api.spec.js`, `C-captures.spec.js`.

### Rejeu de M10 (`C-m10-rerun`, copie littérale + chemins/jeton paramétrés)

| Ligne | Résultat | Détail |
|---|---|---|
| A `:8011` | **4 échecs / 5** | A1, A2, A3, C1 |
| B `:8012` | **2 échecs / 5** | A2, C1 |

Classement :
- **A1 (A) — produit, `connu` (M10-001), TOUJOURS vrai** : 0 appel natif, 0 écouteur, lien Itinéraire → `Browser.open` 0 (`app-native.js:38` appelle `registerPlugin`, absent de la coque). Sur B : A1 vert, 10 appels natifs, `Browser.open` 1.
- **A3 (A) — produit, `connu` (M10-001)** : le bouton Apple n'appelle ni `SocialLogin` ni le serveur. Sur B : `SocialLogin.initialize/login` puis `POST /api/auth/social/apple` → 422 propre, CORS OK.
- **A2 : deux causes distinctes.**
  - *(A)* `POST /api/frontend/order` → 422 « Sélectionnez au maximum 1 Viande 1 (actuel : 2) » : **produit de l'arbre de travail, nouveau → C-006**.
  - `LocalNotifications` « jamais appelé » (A **et** B) : **instrument**. La spec relit `__pont.appels` APRÈS un `page.goto(...)` qui remet le journal à zéro. Prouvé : dans `C-natif` (lecture avant toute navigation), la commande depuis la coque appelle `LocalNotifications.requestPermissions` puis `schedule` (ligne B, Android et iOS).
- **C1 — (A) produit `connu` (M10-003)** : `compiled/racine.js:817` cherche `funnel.jsx`. **(B)** : corrigé (`compiled/racine.js:812`, sélecteur `compiled/funnel.js`) ; l'échec vu sur `:8012` est de l'**instrument** (la spec lit `compiled/racine.js` du dépôt de travail, pas du site servi).
- **Défaut d'instrument propre à M10, reproduit** : le faux pont répondait immédiatement à `addListener` (`success:true,data:{}`), ce qui déclenchait une fois le rappel `backButton` au démarrage → **`App.exitApp` fabriqué par le banc** (vu en 9ᵉ position des appels de démarrage de B dans le rejeu, alors que le code ne quitte pas). `C-lib.js` corrige : `addListener` muet, `window.__pont.emettre(plugin, événement, données)` à la demande. Avec ce pont : B, démarrage Android et iOS sans `App.exitApp`.

### `C-natif` (pont corrigé)
- **B : 7/7.** Démarrage (Android+iOS), liens externes (4 hôtes → `Browser.open`, page jamais remplacée), bandeau hors ligne (apparaît, `pointer-events:none`, navigation utilisable, disparaît), retour avec tiroir panier ouvert (fermé, 0 `exitApp`), **commande depuis la coque Android (UA WebView) ET iOS (UA iPhone) → 201, total 7,40 € = total panier affiché = total en base**, rappel local programmé (`isExactNotification:false`, aucun « n min » dans le texte).
- **A : 0/7** (7 échecs) : pont mort + 422 viandes (C-006).
- Échec initial de C-NAT-4 (retour) = **instrument** : j'avais émis `canGoBack:false` alors que la vraie WebView a `history.length=2` à l'accueil. Vérité terrain sur émulateur : `device-android/panier-ouvert.png` → `apres-retour-panier.png` (tiroir fermé, `MainActivity` toujours au premier plan).

### `C-contrat-api` : 4/4
Pré-vol OPTIONS (5 chemins × `https://localhost`, `capacitor://localhost`, `ionic://localhost`) : 204 + `ACAO` = origine ; témoin négatif `https://evil.example` → pas d'`ACAO`. Apple/Google : 422 propres (jeton absent, court, JWT factice), jamais 5xx, CORS sur les erreurs. `email-login` : vide 422, inconnu 200 `known:false`, connu → 422 générique « Le code n'a pas pu être envoyé » (SMTP de dev injoignable = **environnement**, aucun détail de transport au client). Commande avec jeton : 201 ×2 (android/ios), total base = 2 × prix catalogue ; sans jeton : 401 avec CORS lisible.
**Suppression de compte** (API puis UI `C-CAP-SUPPR`) : 401 sans jeton ; avec jeton 200 ; en base `name='Compte supprimé'`, e-mail/téléphone/username effacés (`PENDING_DELETED_…`), `deleted_at` posé, 0 jeton, **commande conservée** (annulée par le chemin « suppression du compte »), ancien jeton → 401 ; en UI : bouton → confirmation 2 temps → jeton local effacé → message « Ton compte a été supprimé… » sur l'accueil.

### `C-captures` : 3/3 (2 viewports + suppression). Mesures dans `metriques.json` (ce sont des mesures, pas des verdicts).

### Émulateur Android réel (`tools/verify-app-device.py` d'`origin/main`, APK `assembleDebug` construit dans le scratch depuis B)
**28/28 verts** : permissions fusionnées (`SCHEDULE_EXACT_ALARM` absente, aucune permission publicitaire, `POST_NOTIFICATIONS` déclarée), pas de boîte de permission au lancement, accueil dessiné, 0 erreur JS, retour tiroir ouvert/racine, bandeau hors ligne (mode avion) en bas puis retiré en ~4 s, pas de « bientôt », lien externe hors app, 0 plantage natif. ⚠ ce script lit la PRODUCTION (API de prod, lectures seules, aucune commande) : l'APK embarque `api-base-url` de prod.

### Portes de publication (9 portes = `tools/release-gates.mjs`, présent seulement sur B)
- Ligne A, lecture seule : `node tools/build-app-www.mjs --check` → **✗ « SOURCES NON RECOMPILÉES : screens.jsx »** (`connu` M10-002, TOUJOURS vrai) ; manifeste Android **sans** `tools:node="remove"` pour `SCHEDULE_EXACT_ALARM` (`connu`, toujours vrai) ; `release-gates.mjs` absent de cet arbre.
- Ligne B, dans le scratch : `build:www` 228 fichiers puis `--check` ✓ ; `release-gates.mjs` (local) 5 portes vertes, 1 rouge « aucune clé de signature suivie par git » = **instrument** (le scratch n'est pas un dépôt git : `fatal: not a git repository`). Les portes `--prod` n'ont pas été lancées. Gradle `assembleDebug` OK (1 min).
- `capacitor.config.json` identique sur A et B : `appId fr.lecayenne.app`, `webDir www`, **aucun** `server.url`, `cleartext`, ni `allowNavigation`, `allowMixedContent:false`, `webContentsDebuggingEnabled:false`. `Info.plist` identique A/B : pas de chaîne d'usage (aucune API sensible utilisée), `ITSAppUsesNonExemptEncryption=false` ; `App.entitlements` : Sign in with Apple.

## (d) Constats candidats

Légende : `connu` = déjà au ledger du 21/10 ; `nouveau` ; second moyen indiqué.

**C-001 — `connu` (M10-001), TOUJOURS vrai sur la ligne A, corrigé sur B.** Pont natif mort : `app-native.js:38` (A) vs B `mandatairePont` (`app-native.js:44-70`). Preuve A : 0 appel/0 écouteur (`rerun-main-8011/constats.json` `A1.etat`). Second moyen : sha256 de `app-native.js` du paquet `app/www` et du site public (`curl https://www.lecayenne.fr/app-native.js`) = `25cdc346…` = arbre de travail ; sur B, émulateur réel 28/28.

**C-002 — `nouveau`, P1 de publication (process).** La seule ligne où l'app fonctionne (B) n'a ni la livraison (`afe30f7`) ni les suppléments recommandés (`681577d`) ; la ligne A les a mais `app/www` et le site public portent encore la version du 29/09 (`funnel.js?v=20260929audit1`, sha de `compiled/funnel.js` public = celui de `app/www`). Preuves : `git rev-list --left-right --count HEAD...origin/main` → `3 25` ; `shasum` des 4 fichiers public/`app/www`. Second moyen : jeton `funnel.js?v=` lu dans `index.html` des trois arbres (`20260929audit1` / `20261002lot7` / `20261020livraison3`). À arbitrer par le propriétaire (fusion), pas de ma part.

**C-003 — `connu` (M10-002) toujours vrai sur A** : `app/www` périmé (`check:www` rouge). B : vert après build.
**C-004 — `connu` (M10-007) `SCHEDULE_EXACT_ALARM`** : toujours déclarée sur A (manifeste sans `remove`) ; retirée sur B (`AndroidManifest.xml:87`, confirmé par la liste des permissions de l'APK sur émulateur).
**C-005 — `connu` (M10-007) textes « bientôt »** : sur A (`app/www/compiled/components.js`) tuiles Instagram/TikTok/Snapchat « bientôt » visibles dans l'app ; sur B filtrées dans l'app ; contrôle émulateur « aucune annonce bientôt » vert.

**C-006 — `nouveau`, P1 pour la ligne A (commande impossible sur le produit signature), hors périmètre direct (site).** Le wizard de l'arbre de travail propose « Choisis 2 viandes · 2 incluses » pour `Cayenne` (item 22) ; `GET /api/frontend/item/details/22` renvoie `viande_count:1` et l'attribut « Viande 1 » `min_select:1,max_select:1` ; la commande part avec 2 variations viande → **422 « Sélectionnez au maximum 1 Viande 1 (actuel : 2). (and 1 more error) »**, affiché à l'écran (donc pas silencieux) mais avec le suffixe brut « (and 1 more error) ». Reproduit 3 fois (rejeu A2, NAT-5 android, NAT-5 ios) ; sonde `probe-wizard` : pas « Viande » sur B, étape « Choisis 2 viandes » sur A. `wizard-v2.jsx` diffère entre A et B et l'arbre A est non commité par une autre session : **peut être un chantier en cours**, pas nécessairement un défaut livré ; à renvoyer à la vague site (M1). Second moyen non rejoué en curl avec la charge utile exacte → `non reproduit par un 2ᵉ moyen indépendant sur la cause` (le 422 lui-même est vu 3 fois et la règle `max_select:1` lue côté API).

**C-007 — `nouveau`, P2 (catégorie 3/8, ergonomie tactile), ligne B.** Tiroir panier : boutons « − » et « + » de 28×28 px (390 et 360) ; icône corbeille détachée sous la ligne article, loin du produit (`04-panier-390.png`, `04-panier-360.png`) ; champ de recherche de la carte 40 px de haut ; liens « CGV » (24×14) et « Confidentialité » (75×14) du pied de la modale de connexion. Preuves : `metriques.json` (`390.04-panier`, `360.04-panier`, `390.02-carte`, `390.05-…`) + lecture des PNG (les boutons ronds sont visiblement petits). Repère : 44 pt Apple / 48 dp Google.

**C-008 — `nouveau`, P3.** L'ombre du tiroir panier fermé (`.lc-cart-drawer`, `transform: translateX(390px)`, `box-shadow … 24px 48px`) déborde sur le bord droit de TOUS les écrans (bande grisée, plus nette sur l'en-tête). Preuve : style calculé + `probe-ombre.png` (crop). Le tiroir fermé est bien `inert` (pas de piège clavier/lecteur d'écran).

**C-009 — `nouveau`, P3.** À 360 px le numéro « 03 65 67 82 / 91 » se coupe en deux lignes (pas d'espace insécable), `10-suppression-confirmation-360.png`.

**C-010 — information (pas un défaut).** Un 422 de validation produit une ligne `console.error` « Failed to load resource » (allowlisté par le protocole). Le message `07-compte-connu-erreur-envoi` est bien visible. Après suppression, le toast « Ton compte a été supprimé… » recouvre brièvement le bouton « Commander » (`12-suppression-resultat-390.png`).

Vérifié VERT (preuves ci-dessus) : CORS des 3 origines de coque, suppression de compte réelle, commande Android/iOS avec bon total, 0 clé i18n brute / `undefined` / `NaN` sur les 22 états, 0 débordement horizontal, 0 erreur JS, rappel local non exact sans durée, safe-area iOS (`contentInset:always`, `viewport-fit=cover`), console propre (hors 422 de validation).

## (e) Non couvert, et pourquoi
- **iOS** : pas de Xcode (`xcodebuild` refuse). Seuls `Info.plist`, `App.entitlements`, `capacitor.config.json` lus ; comportement iOS simulé dans Chromium (UA iPhone + `webkit.messageHandlers`), jamais sur appareil. La CI `ios-build-check` annoncée verte n'est pas vérifiable d'ici (dépôt distant « not found »).
- **Vraie connexion Apple/Google** (jetons signés), compte social sans e-mail vérifié face à `verify.api` sur `delete-account` : impossible hors appareil/comptes.
- **E-mail d'abord de bout en bout** : SMTP de dev injoignable ; comptes créés par le canal SMS de dev (`dev_code`), seul l'envoi e-mail est resté non prouvé.
- **Safe-area réelle / edge-to-edge Android 16** : Chromium renvoie 0 pour `env(safe-area-inset-*)` ; seule la capture émulateur (`panier-ouvert.png`, barre d'état réservée) y répond, pour l'écran panier seul.
- **Paiement en ligne / lien profond** `fr.lecayenne.app://paiement/retour` : coupé dans l'app par conception (aucun écouteur `appUrlOpen`).
- **Production** : le contrat API n'a été rejoué que sur le backend local ; seul `verify-app-device.py` et quelques `curl` de fichiers publics ont touché la prod (lectures).
- Écran de suivi à l'état « prête » / notification de fin non exercés (le backend ne passe pas la commande en prête sans action caisse).
- APK du 17/10 (ligne A) non réinstallé : l'état « pont mort » sur appareil reste celui de M10-007.
- Cause de C-006 non tranchée (chantier concurrent probable).

Nettoyage : serveur `:8012` et émulateur arrêtés ; `node_modules`/builds du scratch supprimés ; gradle-home : daemon et distribution 8.14.3 supprimés. Disque libre ≈ 0,6 Go à la fin : **à surveiller**.

---
## Rectificatif de l'orchestrateur (relecture adverse C-102, 2026-10-23)
Deux affirmations de ce rapport sont inexactes et NE DOIVENT PAS orienter l'arbitrage :
1. « Ligne A identique octet pour octet à `app/www` ET au site public » : faux. `compiled/funnel.js` du site public est celui du commit `b9e1f64` (29/09) ; seul `app-native.js` égale l'arbre de travail ; `app/www` égale le public, pas l'arbre.
2. C-006 (wizard Cayenne à 2 viandes / 422) et C-002 (deux lignes de publication) ne sont **pas nouveaux** : connus (M1-004 / M5-001 + `SITE_PATCH_viandes_menu-js.diff`, et décision propriétaire « aucune publication avant réunion des chantiers » au ledger).
Source : `wave-C-adversarial.md`.

