# GOAL — Roue Le Cayenne : Expérience Client (tablette + web) + Identité de Connexion
— 2026-08-13, owner `/goal` (raisonnement maximal + agents adversaires)

## §0 Préambule

### §0.1 Working-tree decision
- **Repo backend** (`testttt`, ici) : branche `pos/category-first-caisse-2026-06-23`, working
  tree propre sur le périmètre roue (aucune modification en cours sur `WheelCounterController`
  ni les blades `admin/wheel/*`). On committe directement sur la branche courante — c'est le
  pattern déjà en usage pour les mini-lots roue (cf. commits `82e77344b`, `0ae2e5a03`).
- **Repo web** (`/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne`) : branche
  `codex/cayenne-home-product-visual-max`, **identique à `origin/codex/...`** (déjà poussée,
  0 divergence). Elle porte DÉJÀ 2 commits roue d'une session antérieure (`55e9f6f` photos
  produits en médaillon, `b3c0460` fond animé) — **non fusionnés dans `main`**. On continue SUR
  cette branche (ne pas créer de 3ᵉ branche roue qui diverge encore plus de `main`) ; le
  rapprochement vers `main` reste une décision owner séparée (hors périmètre de ce GOAL).
- Aucune migration DB, aucun frozen-zone §7 touché par ce GOAL (roue n'est pas dans la liste des
  13 fichiers gelés ; `GuestSignupController` n'est PAS touché — voir §2 contrat).

### §0.2 Portée exacte (périmètre de la demande owner, texte original conservé en tête de
conversation)
1. QR code de la roue avec le logo Cayenne intégré (actuellement QR nu).
2. Arrière-plan de l'écran roue tablette : remplacer/enrichir (piste "enfant en focus") +
   AJOUTER un bandeau déroulant (horizontal ou vertical) des VRAIS lots à gagner, avec images
   claires + effets visuels de marque.
3. Parcours complet post-gain → redirection organisée vers le site.
4. **Bug identité** : la 2ᵉ connexion redemande prénom+nom+téléphone comme la 1ʳᵉ, au lieu d'un
   flow allégé "email → code" pour un client déjà connu sur cet appareil.
5. Agents adversaires explicitement demandés pour challenger design ET fix identité.

### §0.3 Ce que ce GOAL NE fait PAS (limites assumées, à dire à l'owner)
- Ne change PAS la clé d'identité backend (`User::where('phone', …)`) — ce champ est le pivot
  d'anti-fraude/anti-takeover durci les 2026-07-30/31 (SEC MISSION-1, channel-confusion email).
  Le "login email-only" demandé par l'owner est livré **côté client** (mémorisation d'appareil),
  pas en réinventant l'authentification serveur — voir §2 contrat pour le raisonnement complet.
- Ne déploie PAS sur le VPS ni ne fusionne vers `main` (gate owner séparé, cf. mémoire
  `deploy_fidelite_roue_live_2026-08-12.md` : le dernier déploiement roue a nécessité un accès
  SSH que cette machine n'a pas — à vérifier au moment du déploiement, pas maintenant).

### §0.4 Pipeline de référence
Chaque tâche ci-dessous s'exécute avec la discipline `ultra-audit-profond` (audit lecture-seule
→ implémentation → RED-team → test → visuel). Non re-décrite ici.

---

## §1 Système 1 — Roue : Expérience Client (tablette borne + page web scannée)

### Contract
Le client scanne un QR affiché sur la tablette du comptoir, joue sur son téléphone (page
`roue.html`, servie par Vercel), gagne à 100 %, débloque son lot avec ses coordonnées, et doit
ensuite être renvoyé vers le site pour commander. La tablette, elle, tourne en boucle (`borne.blade.php`)
sans interaction humaine — c'est l'"animation sur la tablette" citée par l'owner.

### Anchors (vérifiés — CORRIGÉS après revue Architect 2026-08-13, voir §1.1 note)
- `app/Http/Controllers/Admin/Wheel/WheelCounterController.php:94` — `kiosk()` génère le QR
  (`SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(560)->margin(1)->errorCorrection('M')->generate($url)`).
  `:136` — `issue()` génère le second QR (`size(520)`, même chaîne). **Corrigé** : le GOAL v1
  avait inversé ces deux lignes et appelé une méthode `unlock()` qui n'existe pas (c'est le nom
  du *service* injecté, pas d'une méthode du contrôleur).
- `resources/views/admin/wheel/borne.blade.php:204-247,400-401` — `.qr-boite` / `.qr svg{width:...}`,
  `{!! $qr !!}` (SVG brut inline). C'est **l'écran tablette** ("l'animation sur la tablette").
- `resources/views/admin/wheel/validation.blade.php:73-74,130` — `.qr svg{width:min(62vw,260px)}`,
  `{!! $qr !!}`. **Corrigé** : c'est CE fichier qui porte le QR staff (`show()`/`issue()`), PAS
  `acces.blade.php` (grep `$qr` sur ce dernier = 0 résultat — `acces.blade.php` est l'écran PIN +
  grille de navigation, sans image QR).
- **`"/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne/assets/brand/logo-mark.png"`**
  reste le bon candidat visuel (mark carré, pas le lettrage complet).
- ⚠️ **REVUE ARCHITECT (exécution réelle)** : `php -m` sur cette machine liste `gd` mais **PAS
  `imagick`**. Le backend PNG de `bacon/bacon-qr-code` (utilisé par `simple-qrcode` pour
  `format('png')`) **requiert Imagick** — testé en exécutant réellement
  `QrCode::format('png')->generate()`, qui lève `BaconQrCode\Exception\RuntimeException: You need
  to install the imagick extension`. **`ImageMerge::merge()` lui-même utilise GD** (donc la
  fusion en soi n'est pas bloquée), mais le rendu PNG *source* du QR l'est. Rien ne garantit que
  le VPS de prod a Imagick — c'est un prérequis infra non vérifié. **Le plan `format('png')->merge()`
  est donc ABANDONNÉ** (voir §1.1 approche corrigée ci-dessous) au profit d'un overlay CSS/HTML
  posé par-dessus le SVG existant — zéro dépendance système nouvelle, zéro binaire re-généré.
- `.../Site lecayenne/roue.html:1-345` — structure existante : `<canvas id="roue">`, header avec
  `.marque-logo`, `.scene` (fond). Photos produits DÉJÀ dessinées SUR les segments du canvas
  (`roue.html:513, segments[pi].photo` — commit `55e9f6f`) ; fond animé DÉJÀ ajouté
  (commit `b3c0460`, "deux braises qui dérivent"). **Vérifié absent** : tout bandeau séparé
  listant les lots (grep `medaillon|marquee|carousel|carrousel` = 0 résultat) — c'est un NOUVEL
  élément, pas une extension de l'existant.
- `.../Site lecayenne/roue.html:1229-1293 revelerLot()` — l'écran de fin affiche `<a class="lien"
  id="gainLien" href="/#menu">Commander maintenant</a>` (ligne 457) : le lien existe déjà mais
  pointe en dur sur `/#menu`, pas de distinction produit gagné → page produit, pas de délai/CTA
  différencié points vs code vs produit offert.
- `config/wheel.php:47` `public_url` (défaut `https://www.lecayenne.fr`) — sert de base pour
  toute redirection finale.

### Sub 1.1 — QR avec logo intégré (backend testttt) — APPROCHE CORRIGÉE : overlay CSS/SVG, pas de merge binaire
**Décision post-revue Architect** : Imagick absent (vérifié par exécution), donc `format('png')->merge()`
est abandonné. On garde le QR en **SVG inchangé** côté génération PHP, et on pose le logo
**visuellement par-dessus** via un `<img>` positionné en absolu au centre du conteneur `.qr`,
dans les 2 blades. Avantages : zéro dépendance système nouvelle, zéro risque de casser le VPS de
prod, le SVG reste net à toute résolution (contrairement à un PNG base64 qui grossirait).
**Anchors** : `WheelCounterController.php:94` (`kiosk()`), `:136` (`issue()`),
`borne.blade.php:204-247,400-401`, `validation.blade.php:73-74,130`,
`"/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne/assets/brand/logo-mark.png"`
(à copier vers `public/images/wheel/logo-mark.png` côté backend — **vérifier d'abord par `find`
si un mark existe déjà dans `public/` avant de dupliquer l'asset**).
**Tasks** :
- T-1.1.1 Dans `WheelCounterController.php:94` et `:136`, remonter `errorCorrection('M')` →
  `errorCorrection('H')` (nécessaire dès qu'une zone centrale est visuellement recouverte, même
  par un overlay CSS et non par un merge binaire — le lecteur QR doit pouvoir reconstruire les
  modules recouverts) et `margin(1)` → `margin(2)` (recommandation UX/Architect : la marge à 1
  est en-dessous du confort de nombreux lecteurs, combinée à un centre visuellement chargé ça
  peut dégrader le scan plus que le logo lui-même).
  • test: `tests/Feature/Wheel/WheelQrGenerationTest.php` (TO BE CREATED) — asserte
    `errorCorrection === 'H'` et `margin === 2` sur les DEUX générateurs (mock ou assertion sur
    le SVG produit : un niveau H produit statistiquement plus de modules qu'un niveau M à taille
    de données égale — comparer le nombre de path/rect dans le SVG avant/après si l'assertion
    directe sur les options n'est pas accessible).
- T-1.1.2 Dans `borne.blade.php` et `validation.blade.php` : ajouter dans `.qr-boite`/`.qr`
  un `<img class="qr-logo" src="{{ asset('images/wheel/logo-mark.png') }}" alt="">` (décoratif,
  `alt=""` — le contenu utile est le QR lui-même, annoncé par le texte autour, pas par ce logo) en
  position `absolute; top/left:50%; transform:translate(-50%,-50%)`, taille **18-20% de la largeur
  du QR** (fourchette validée par revue UX, 15-22% sûre), fond blanc circulaire derrière le logo
  (`background:#fff; border-radius:50%; padding:` proportionnel) pour garantir un contraste net
  même si le logo a des zones transparentes.
  • visuel: `http://127.0.0.1:8000/admin/roue-borne` ET `/admin/roue` (paysage + portrait),
    réutiliser le banc Playwright existant `reports/test-e2e/roue-2026-08-10`.
- T-1.1.3 **Test de scannabilité réel, non-optionnel** (durci suite à la revue Architect qui a
  jugé la version précédente "trop faible pour un artefact qui échoue déjà à la génération") :
  décoder le SVG produit (avec overlay simulé en recouvrant la même zone centrale par une image
  blanche avant décodage) via une lib de lecture QR disponible côté test (chercher
  `khanamiryan/qrcode-detector-decoder` ou équivalent dans `composer.json` ; si absente, l'ajouter
  en dépendance **dev only** — pas de risque prod) pour prouver que le contenu reste décodable
  avec le logo à 18-20% + errorCorrection H. Tester aussi à une distance de scan réaliste
  (0,5-1,5m, pas 1-2m comme la v1 du GOAL le supposait) en gardant une taille physique de QR
  cohérente avec `.qr svg{width:min(62vw,260px)}` (validation) et les valeurs vmin de `borne.blade.php`.
  • test: `tests/Feature/Wheel/WheelQrScannabilityTest.php` (TO BE CREATED).
**Acceptance** : `WheelQrGenerationTest` + `WheelQrScannabilityTest` PASS · capture Playwright
borne+validation (paysage+portrait) montrant le logo net et centré, contraste suffisant ·
`reports/test-e2e/roue-2026-08-10` toujours vert (non-régression).

**✅ SUB 1.1 CONVERGÉE 2026-08-13.** `tests/Feature/Wheel/WheelQrLogoTest.php` (5 tests) PASS ·
suite Wheel complète (252 tests) toujours verte, 0 régression · 0 fichier de zone gelée §7 touché.
**Preuve de scannabilité RÉELLE** (pas simulée) : `/admin/roue-borne` et `/admin/roue-validation`
capturés via un vrai Chrome (screenshot, donc SVG + overlay CSS rasterisés par le moteur du
navigateur), puis décodés avec `khanamiryan/qrcode-detector-decoder` (mode GD, sans Imagick) — les
deux QR se décodent et rendent l'URL attendue (`.../roue.html?t=...`). Fichiers modifiés :
`WheelCounterController.php` (errorCorrection H, margin 2, ×2), `borne.blade.php` +
`validation.blade.php` (overlay `.qr-logo`), `public/images/wheel/logo-mark.png` (asset copié).

### Sub 1.2 — Arrière-plan + bandeau des lots à gagner (repo web, `roue.html`)
**Anchors** : `roue.html` (styles L1-324, DOM L326-408, logique segments/photos L490-700+).
**Tasks** :
- T-1.2.1 Enrichir le fond de `.scene` : garder l'animation braises existante (b3c0460, ne pas
  la retirer — c'est un acquis livré) et superposer une illustration discrète (silhouette
  enfant/famille attablée, faible opacité, `pointer-events:none`, jamais au-dessus du canvas ni
  du CTA) pour ancrer le contexte "on joue en attendant sa commande" SANS distraire de la roue —
  l'owner dit lui-même "pour laisser concentrer... sur la roue", donc l'image est un cadrage
  d'ambiance, pas un élément qui capte l'attention. Utiliser un asset existant si le pack marketing
  en a un (`find "assets"` côté web pour photo famille/enfant AVANT d'en commander une nouvelle —
  anti-fiction : ne pas inventer un asset qui n'existe pas).
  • visuel: capture roue.html mobile portrait 375×812 (le format réel de scan, cf. commentaire
    L19 "souvent sur un téléphone en 4G moyenne").
- T-1.2.2 Bandeau déroulant des lots (NOUVEAU composant, confirmé NON redondant avec les
  médaillons par la revue UX : les médaillons sur la roue sont petits, pivotés selon l'angle du
  secteur et en mouvement — ils ne permettent pas de "visualiser TOUT" comme demandé par l'owner ;
  le bandeau est le seul endroit où le catalogue est lisible à plat) : liste horizontale
  scrollable (mobile-first, `overflow-x:auto`, `scroll-snap`, **scroll MANUEL uniquement — jamais
  d'auto-avance**, à documenter en commentaire dans le code pour qu'une session future n'en ajoute
  pas un) de vignettes photo+libellé pour CHAQUE segment actif (source : même `segments` déjà
  chargés en JS pour dessiner la roue, `segments[pi].photo` — réutiliser la donnée existante, pas
  de second appel réseau).
  **Positionnement corrigé (revue UX)** : placer le bandeau **après** le bloc CTA
  (`#ecran0`/`#ecran1`/`#ecran2`/`#ecran3`), avant `.msg` — pas "juste sous la roue et avant le
  CTA" comme envisagé initialement. Sur 375×667 (iPhone SE, toujours en usage comptoir), le calcul
  mesuré du budget vertical (header+promesse+scene ≈ 496px) laisse trop peu de marge pour un
  bandeau + CTA visibles sans scroll — le placer après le CTA élimine le risque de le repousser
  hors du premier écran (même famille de piège que `borne.blade.php:33-40`, QR recouvrant une
  pastille, mesurée cette fois sur `roue.html` lui-même et pas seulement invoquée par précédent).
  **Sémantique ARIA** (revue UX) : conteneur `role="list" aria-label="Tous les lots à gagner"`,
  chaque vignette `role="listitem"` avec `<img alt="{libellé réel du lot}">` (pas décoratif : c'est
  l'info que l'owner veut rendre claire). Respecter le bloc `@media (prefers-reduced-motion:reduce)`
  déjà existant (L321-323) si `scroll-snap`/`scroll-behavior:smooth` est utilisé.
  • test: (TO BE CREATED) `tests-e2e/roue-lots-bandeau-2026-08-13.spec.js` — asserte présence
    d'une vignette par segment actif avec `alt` non-vide, `overflow-x` scrollable, position du CTA
    encore visible/atteignable SANS scroll sur **375×667 ET 375×812** (les deux tailles, pas
    seulement 812 comme prévu initialement), aucun `scroll-behavior:smooth` actif sous
    `prefers-reduced-motion:reduce`.
- T-1.2.3 Effets de marque : cohérence typographique/couleur avec `logo-cayenne.png` déjà en
  header (pas de nouvelle palette — CLAUDE.md §3bis mandate "Mobile/web standalone : palette
  NOIR/ORANGE/JAUNE/BLANC", à vérifier contre les couleurs déjà en usage dans `roue.html` avant
  d'en introduire une nouvelle).
  • visuel: même capture que T-1.2.1, contraste AA vérifié sur le nouveau bandeau (texte sur photo).
**Acceptance** : capture Playwright roue.html 375×812 ET 768×1024 (paysage tablette-scan) montrant
fond enrichi + bandeau lots + roue intacte, 0 chevauchement mesuré · spec T-1.2.2 PASS ·
`roue-2026-08-09.regression.js` toujours vert (non-régression).

### Sub 1.3 — Parcours post-gain → redirection site organisée
**Anchors** : `roue.html:1229-1293 revelerLot()`, ligne 457 `#gainLien href="/#menu"`,
`config/wheel.php public_url`.
**Tasks** :
- T-1.3.1 Différencier la destination du CTA final selon `prize_type` (déjà disponible dans `d.prize_type`,
  cf. L1255-1268) : `free_item`/`points` → CTA vers le menu général (le lot se récupère au comptoir,
  pas en ligne — cohérent avec le texte déjà affiché) ; remise (`coupon_percent`/`coupon_fixed`)
  → CTA vers le panier avec le code pré-appliqué si le site le permet (vérifier si `commander.html`
  accepte un paramètre `?code=` avant de le construire — anti-fiction, ne pas supposer).
  • test: (TO BE CREATED, même fichier que T-1.2.2 ou nouveau `roue-redirection-2026-08-13.spec.js`)
    — asserte l'URL du lien final pour chacun des 4 types de lot.
- T-1.3.2 Micro-page de transition ("Merci, à très vite !" 1-2s) avant la redirection automatique
  optionnelle SI l'owner la valide (sinon garder le clic manuel actuel — ne pas imposer un
  auto-redirect qui couperait la lecture des conditions du lot, cf. le souci d'honnêteté déjà
  documenté en commentaire L1246-1253 : le client doit VOIR la condition avant de partir).
**Acceptance** : les 4 types de lot testés manuellement (via `?preview=`) montrent un CTA cohérent
avec leur nature · aucune régression sur le message de condition déjà validé (P0 honnêteté 2026-08-09).

---

## §2 Système 2 — Roue/Site : Identité de connexion (repo web, `account-v2.jsx` + lecture backend)

### Contract
Le compte client est indexé par TÉLÉPHONE côté backend (`GuestSignupController::verify` L230
`User::where('phone', …)->first()`), l'email n'étant que le canal de livraison du code — et ce
canal est durci contre le takeover (SEC MISSION-1 2026-07-30/31 : un compte AVEC email ne reçoit
le code QUE sur l'email déjà lié, jamais sur un email fourni par l'appelant). **Ce contrat backend
n'est PAS renégocié par ce GOAL** — il porte une garde anti-fraude vérifiée par test, le
retoucher serait un changement de surface de sécurité hors du périmètre demandé par l'owner (qui
parle d'un problème d'ÉCRAN, pas de sécurité).

Le vrai défaut est **côté client** : `AccountFlow` (`account-v2.jsx`) affiche EXACTEMENT le même
formulaire (prénom+nom+téléphone+email) dans les deux onglets "J'ai un compte" et "Créer un
compte" — décision du 2026-08-07 documentée en commentaire (L344-351) car le frontend ne peut
pas savoir AVANT le code si le compte existe déjà. Sur cette base, le frontend est de bonne foi
mais **ignore ce qu'il sait déjà lui-même** : si CE navigateur a déjà authentifié un client avec
succès, il peut mémoriser (localStorage) l'identité et ne plus la redemander — c'est un problème
d'UX résolu par la mémoire d'appareil, pas par une nouvelle route serveur.

### Anchors (vérifiés)
- `account-v2.jsx:37-91` — `AccountFlow`, state `mode` ('login'|'signup'|'otp'|'success'),
  `form{first,last,email,phone,password}`, reset `avE(()=>{if(!open){setMode('login')...}},[open])`.
- `account-v2.jsx:317-410` — le rendu du formulaire : lignes 359, 380, 392 montrent que
  `(mode === 'signup' || mode === 'login')` conditionne l'affichage des CHAMPS À L'IDENTIQUE dans
  les deux modes (prénom/nom L359, email L380, téléphone L392) — **c'est le défaut exact décrit
  par l'owner**, vérifié en lisant le JSX, pas supposé.
- `account-v2.jsx:141-169 submit()` — appelle `api.guestEmailOtp(phone, email, undefined, first, last)`
  dans les deux modes indifféremment (aucune branche selon `mode`).
- `app/Http/Controllers/Auth/GuestSignupController.php:107-181 emailOtp()`,
  `:183-330+ verify()` — **phone est un paramètre requis dans les deux appels** (`GuestSignupEmailOtpRequest`,
  `VerifyPhoneRequest`), aucune route "par email seul" n'existe. Sécurité anti-énumération déjà
  câblée (L125-148) — NE PAS ajouter de route qui accepte un lookup par email nu sans repasser par
  ce contrat.
- `account-v2.jsx:436-437` bouton "Recevoir mon code par e-mail" — texte déjà unifié entre les
  deux modes (cohérent avec le fait qu'aucune donnée saisie ne diffère aujourd'hui).

### Sub 2.1 — Mémoire d'appareil : écran de retour allégé (CORRIGÉ post-revue Security+Architect)
**Anchors** : `account-v2.jsx:37-104` (état + effects) — **corrigés** après revue :
- Le point de persistance réel est **`account-v2.jsx:195`** (dans le `then()` de
  `api.isAuthed()`, juste avant/à `setMode('success')`), **PAS** le clic du CTA "Commencer à
  commander" (~L612, `onClick={()=>{ onAuthed(); onClose(); }}`) — ce bouton est optionnel, si le
  client ferme la modale autrement (croix, clic hors-modal) l'auth a quand même réussi et
  L612 ne se déclenche jamais. Écrire la mémoire à L195 garantit qu'elle survit à toute sortie.
- ⚠️ **Ne PAS créer une nouvelle clé `localStorage`** (rejeté par la revue Security, P1 "doublon
  de mécanisme" — motif "jumeau oublié" déjà vu 3× sur ce projet, mémoire
  `audit_roue_ronde2_jumeaux_oublies_2026-08-10.md`). Le repo web porte **déjà**
  `api.js` `PHONE_KEY = 'lecayenne.authPhone'` + `getPhone()`/`setPhone()`, écrit à `api.js:315`
  et **purgé automatiquement** au logout/401 (`api.js:249,343`, événement `lc:auth-changed`).
  Réutiliser `window.LC.api.getPhone()` tel quel ; n'ajouter QU'UNE nouvelle clé,
  `localStorage['lc_known_first']` (le prénom, que `PHONE_KEY` ne porte pas), synchronisée sur
  le même événement `lc:auth-changed` que `PHONE_KEY` (pas un cycle de vie indépendant).
**Tasks** :
- T-2.1.1 À `account-v2.jsx:195` (succès `verify()`), écrire `localStorage['lc_known_first']`
  avec `form.first` (déjà en state). NE PAS dupliquer le téléphone — `api.setPhone()` s'en charge
  déjà à L315 dans le même flux.
- T-2.1.2 Écouter l'événement `lc:auth-changed` existant (déjà émis par `api.js` au logout) pour
  purger `lc_known_first` en même temps que `PHONE_KEY` — sinon la mémoire "prénom" survivrait
  à une déconnexion serveur (éviction multi-device, 401) pendant que `getPhone()` reviendrait
  `null`, recréant exactement le défaut que la revue Security a signalé.
  ⚠️ **Décision owner à confirmer avant de coder cette tâche** : l'intention exprimée par l'owner
  ("la prochaine fois je me connecte, juste l'email") semble vouloir que la mémoire survive à une
  fermeture d'onglet normale — ce qui est déjà le comportement par défaut de `localStorage`. Le
  couplage à `lc:auth-changed` ne purge que sur déconnexion EXPLICITE ou éviction serveur, pas sur
  fermeture de page — cohérent avec l'intention, à vérifier en test manuel avant de clore Wave 2.
- T-2.1.3 Ajouter un TTL de 90 jours (horodatage stocké à côté de `lc_known_first`, vérifié à
  l'ouverture — au-delà, traiter comme "pas de mémoire", retour au formulaire complet). Recommandé
  par la revue Security (P2) : un appareil revendu/prêté longtemps ne doit pas rester "connu"
  indéfiniment.
- T-2.1.4 À l'ouverture de la modale (`avE(()=>{if(!open){setMode('login')...`, L91), si
  `window.LC.api.getPhone()` ET `lc_known_first` existent (et TTL valide), état `mode='login-connu'`
  au lieu de `'login'` — écran dédié : prénom mémorisé ("Content de te revoir, {first} !"), visuel
  de marque distinct (réutiliser le pattern `.lc-acc-art` déjà en place L319-327), **UN SEUL champ
  visible : email**. Signal de reconnaissance renforcé (recommandation Security) : afficher aussi
  les 2 derniers chiffres du téléphone mémorisé (`06 •• •• •• 78`), pas seulement le prénom — un
  prénom commun ne suffit pas à faire réagir un second utilisateur d'un appareil partagé. Le
  téléphone complet part en silence dans l'appel API existant (contrat backend inchangé).
  ⚠️ Vérifier que l'effet L91 réinitialise bien vers `'login-connu'` (et non `'login'`) quand la
  mémoire existe encore à la fermeture — sinon régression silencieuse à chaque réouverture.
- T-2.1.5 Échappatoire obligatoire : lien "Ce n'est pas moi" qui efface `lc_known_first` (et
  déclenche `api.setPhone(null)` pour rester cohérent avec le mécanisme existant) et retombe sur
  le formulaire complet — nécessaire pour un appareil partagé.
**Acceptance** : `tests-e2e/account-email-otp-2026-07-28.spec.js` (existant) reste vert (parcours
1ʳᵉ visite inchangé) · nouveau `tests-e2e/account-retour-connu-2026-08-13.spec.js` (TO BE CREATED)
simule `PHONE_KEY`+`lc_known_first` pré-remplis → vérifie qu'UN SEUL champ (email) est visible,
que le prénom + 2 derniers chiffres s'affichent, que "Ce n'est pas moi" restaure le formulaire
complet, et qu'un TTL expiré retombe aussi sur le formulaire complet.

### Sub 2.2 — Cohérence du parcours 1ʳᵉ visite (inchangé, non-régression)
**Anchors** : mêmes lignes 317-410, mode `signup` par défaut si pas de mémoire d'appareil.
**Tasks** :
- T-2.2.1 Vérifier qu'aucune régression n'affecte le mode `signup` classique (le formulaire
  complet doit rester identique pour un nouvel appareil/nouveau client) — ce sub-système est
  volontairement un NON-CHANGEMENT, listé pour que le RED-team ait un point de contrôle explicite.
**Acceptance** : capture avant/après du mode signup, diff visuel nul.

### Sub 2.3 — Le mode `mode==='login'` classique (onglet "J'ai un compte" sur APPAREIL INCONNU)
**Anchors** : mêmes lignes, cas où l'utilisateur clique "J'ai un compte" mais qu'aucune mémoire
locale n'existe (ex. il change de téléphone, vide son cache, ou utilise l'ordinateur du travail).
**Tasks** :
- T-2.3.1 Dans ce cas RESTE le formulaire actuel (téléphone demandé, car c'est la seule clé que
  le frontend peut donner au backend pour retrouver le compte) — mais reformuler le sous-titre
  déjà existant (L352-354, "Ton numéro suffit : s'il est déjà connu ici...") pour que ce soit
  clairement présenté comme "retrouver mon compte par numéro" et non confondu avec l'écran allégé
  de Sub 2.1. Changement de texte minimal, pas de nouveau champ.
**Acceptance** : diff de texte uniquement, capture des deux écrans ("retour connu" vs "retrouver
par numéro") côte à côte pour vérifier qu'ils ne se ressemblent pas au point de confondre l'owner
en test manuel.

---

## §A Armée d'agents (adversaires explicitement demandés par l'owner)

| Rôle | Déclenché sur | Mode |
|---|---|---|
| Architect | Sub 1.1 (QR/logo), Sub 2.1 (mémoire appareil) | lecture seule, avant implémentation |
| Security | Sub 2.1-2.3 (identité, localStorage, contrat backend non modifié) | lecture seule, avant ET après |
| UX/A11y | Sub 1.2 (bandeau), Sub 2.1 (écran retour) | lecture seule + capture |
| Implementer | toutes tâches T-* | write, jamais 2 en parallèle sur le même repo |
| RED-team | après chaque implémentation, avant de déclarer une sous-tâche DONE | lecture seule, dispute |
| QA Visual + RED Visual | Sub 1.2, Sub 1.3, Sub 2.1 (tout ce qui est visuel) | parallèle, l'un capture l'autre dispute |

Dispatch : 3 specialists (Architect+Security+UX) en UN message parallèle par système AVANT
implémentation (l'"advisor" d'Axis 8) ; puis Implementer séquentiel par sub-système (jamais deux
Implementer simultanés sur le même repo — Sub 1.* touche `testttt` ET le repo web, Sub 2.* touche
seulement le repo web : **Sub 1.1 (backend) peut tourner EN PARALLÈLE de Sub 2.* (web)**, mais
Sub 1.2/1.3 et Sub 2.1/2.2/2.3 partagent le même repo web → séquentiel entre elles.

---

## §X Vagues de convergence

- **Wave 1 — Anchor + advisor** (FAIT, ce document) : anchors vérifiés, 3 specialists en lecture
  seule sur les 2 systèmes. Checkpoint : les 3 rapports ne contredisent pas les anchors ci-dessus.
- **Wave 2 — Sub 1.1 (QR+logo, backend) ∥ Sub 2.1 (mémoire appareil, web)** : parallélisables
  (repos disjoints, aucun état partagé). Checkpoint : tests T-1.1.* + T-2.1.* verts, captures
  Read+analysées, RED-team dispute close (0 P0/P1 non traité).
- **Wave 3 — Sub 1.2 (bandeau lots) + Sub 1.3 (redirection)** : séquentiel après Wave 2 (même
  fichier `roue.html`, écrit par la même personne pour éviter un conflit). Checkpoint identique.
- **Wave 4 — Sub 2.2/2.3 (non-régression + texte)** : rapide, peut suivre directement Wave 2.
- **Wave 5 — Convergence finale** : re-capture complète roue.html (mobile+tablette) + account
  modal (3 états : 1ʳᵉ visite / retour connu / "pas moi") + re-run `roue-2026-08-09.regression.js`
  + `account-email-otp-2026-07-28.spec.js`. Deux cycles adversaires consécutifs sans nouveau
  finding = convergé.

**Interrupt-resume** : si interruption, committer `wip(roue-ux):` avec le sub-système atteint,
manifeste dans `reports/test-e2e/roue-ux-identite-2026-08-13/INTERRUPT_<wave>.md`, mettre à jour
`PROJECT_BRAIN.md §2`.

---

## §G Owner gates

| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G1 | Asset "enfant/famille" pour l'arrière-plan (Sub 1.2) — si aucun asset existant trouvé dans le pack marketing, il faut une photo/illustration validée par l'owner (droit à l'image si photo réelle d'enfant) | Owner | photo/illustration fournie ou approuvée | commit message Sub 1.2 | PENDING |
| G2 | Fusion `codex/cayenne-home-product-visual-max` → `main` + déploiement Vercel/VPS | Owner | GO explicite + accès SSH VPS si besoin | `PROJECT_BRAIN.md §2` | PENDING (hors périmètre exécution immédiate) |
| G3 | Redirection panier avec code pré-appliqué (T-1.3.1) si `commander.html` ne le supporte pas déjà | Owner | décision : construire le paramètre `?code=` ou garder redirection simple | commit Sub 1.3 | À LEVER PENDANT Wave 3 (pas bloquant, juste conditionnel) |

Gates G1/G3 ne bloquent PAS Wave 2/4 — seulement les tâches qui en dépendent (T-1.2.1, T-1.3.1
volet remise). Le reste avance.

---

## §F Règle finale
DONE = les 2 systèmes convergés (§X Wave 5), captures Read+analysées pour chaque état d'écran
listé, 0 P0/P1 restant après 2 cycles adversaires identiques, aucune régression sur les 2 specs
Playwright existantes (`roue-2026-08-09.regression.js`, `account-email-otp-2026-07-28.spec.js`),
`PROJECT_BRAIN.md §2` mis à jour, working tree des 2 repos committé (pas nécessairement poussé —
G2 reste un gate owner séparé pour le déploiement).
