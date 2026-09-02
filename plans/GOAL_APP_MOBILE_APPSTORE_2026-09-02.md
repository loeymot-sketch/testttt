# GOAL — Application mobile Le Cayenne : parcours compte « e-mail d'abord », wizards → paiement → points, préparation App Store

> Ouvert le 2026-09-02 (`/goal` propriétaire). Plan vivant : chaque vague est cochée quand
> elle est **prouvée** (test + capture lue), pas quand elle est écrite.

## 0. Décision de cible (lue dans le code, pas devinée)

Trois candidats « application mobile » existent sur cette machine :

| Candidat | État réel (vérifié) | Sur le chemin App Store ? |
|---|---|---|
| `testttt/mobile/` (prototype React + Babel navigateur) | dernier commit 2026-07-16 ; connexion par **SMS** jamais câblé (aucun fournisseur) ; jamais empaqueté | **Non** |
| `~/Downloads/web/` | copie périmée du site (mémoire projet) | Non |
| **`~/Downloads/lecayenne-web-deploy/Site lecayenne`** + son dossier **`app/`** | site www.lecayenne.fr **empaqueté Capacitor 8** (`fr.lecayenne.app`, iOS + Android, connexion Apple/Google, suppression de compte, captures store, `PUBLICATION.md` du 19/08) ; Android déjà compilé ; iOS attend Xcode | **Oui — c'est l'app** |

Le parcours de connexion décrit par le propriétaire (« si vraiment il a un compte, juste un mail…
sans le transférer sur notre page ») décrit **exactement** le défaut actuel du site : les deux
onglets « J'ai un compte / Créer un compte » exigent prénom + nom + e-mail + téléphone, y compris
pour se connecter (`account-v2.jsx:279-289`, contrainte `GuestSignupEmailOtpRequest`).

**Cible = Site lecayenne (app) + backend testttt pour le contrat d'authentification.**
Le prototype `mobile/` n'est pas modifié (hors chemin store).

## 1. Ce que veut le client (à sa place)

1. **Je me connecte** : je tape mon e-mail, je reçois un code, je le tape. Rien d'autre.
2. **Je n'ai pas de compte** : le même écran me demande, sans changer de page, mon prénom et mon
   numéro ; je reçois le code sur mon e-mail ; mon compte est créé à la validation.
3. **Je compose mon plat** : je vois toujours ce que je compose et combien ça coûte ; je peux
   corriger une étape depuis le récapitulatif sans tout refaire.
4. **Je paie** : je sais avant de valider ce qui va se passer (sur place / en ligne), combien de
   points je gagne, et je ne peux pas envoyer deux fois la même commande.
5. **Mes points** : je vois mon solde, ce qu'il vaut en euros, comment l'utiliser en caisse.
6. **App Store** : rien de « bientôt disponible », rien de factice, suppression de compte,
   politique de confidentialité, compte de démonstration pour la revue Apple.

## 2. Vagues

### A — Backend : contrat « e-mail d'abord » (testttt) — [x] PROUVÉ (testttt `8fc877521`, EmailLoginFlowTest 11/11)
- A1 `POST /api/auth/guest-signup/email-login` (throttle `otp-send`) :
  - `{email}` seul → compte invité connu : code généré sur le téléphone du compte (ou clé
    `email:<adresse>` si le compte n'a pas de numéro), envoyé à **l'e-mail du compte** ;
    réponse `{status:true, known:true}`. Inconnu → `{status:true, known:false}` (l'app déplie
    prénom + téléphone). Choix propriétaire assumé : `known` révèle l'existence d'un e-mail ;
    mitigé par le débit `otp-send` (5/min par e-mail + 20/min global).
  - `{email, first_name, phone, last_name?}` → même moteur que `email-otp` (anti-usurpation
    canal conservé), `last_name` **facultatif** (le propriétaire ne demande plus que le prénom).
- A2 `POST /verify` accepte `email` à la place de `phone` : le serveur résout le compte, jamais
  le client. Réponse enrichie de `phone_required` (compte social sans numéro).
- A3 ~~Compte de revue App Store à code fixe~~ — **RETIRÉ le 2026-09-02, contradiction assumée.**
  Je l'avais implémenté (`APP_REVIEW_EMAIL` / `APP_REVIEW_OTP`) ; `app/PUBLICATION.md §8` du dépôt
  du site tranchait déjà l'inverse : « un code fixe pour une adresse connue est une porte qu'on
  oublie de refermer ». Le garde-fou habituel du projet (refus de démarrer en production) ne peut
  pas s'appliquer, puisque Apple examine l'application **contre la production**. Retiré, et
  verrouillé par la sentinelle `test_aucun_code_fixe_de_revue_app_store` (rouge si quelqu'un la
  remet — vérifié en restaurant le commit `8fc877521`). L'examinateur reçoit son code dans une
  boîte de démonstration dédiée, à créer par le propriétaire (PUBLICATION.md §8).
- A4 `dev_code` renvoyé en `local` uniquement (parité `otp()`), pour le banc E2E.
- A5 Tests PHPUnit `EmailLoginFlowTest` (10 verts) : connu → mail au compte + token par e-mail ;
  inconnu → `known:false` ; inscription prénom+téléphone → compte « Prénom » + e-mail attaché ;
  e-mail d'un compte non-invité → jamais de token ; compte sans numéro → clé synthétique, pas de
  doublon ; code de revue hors `local` désactivé sans env.

### B — Site/app : écran compte « e-mail d'abord » — [x] PROUVÉ (site `a7e9c50`, 24/24 + 7/7 + 17/17, captures lues)
- B1 `api.js` : `emailLogin(email)`, `emailSignup({email, first_name, phone})`, `guestVerify`
  par e-mail.
- B2 `account-v2.jsx` : **un seul écran** : e-mail → (connu) code | (inconnu) dépliage prénom +
  téléphone dans le même écran → code. Onglets supprimés. Mémoire d'appareil = e-mail
  pré-rempli. Mode `phone` (social) conservé. Renvoi du code par le même canal.
- B3 `funnel.jsx` (coordonnées dans le tunnel) : même logique e-mail d'abord.
- B4 Tests e2e (banc mock backend `helpers-mock-backend.js`) : 3 états ; anciens specs
  `account-*` remplacés (ils verrouillaient les onglets).
- B5 Captures 390×844 lues : écran e-mail, dépliage, code, succès, tunnel.

### C — Wizards → panier → paiement → points — [~] AUDITÉ, aucun P0/P1 trouvé
- C1 **FAIT** — parcours réel iPhone 13, Tacos L (produit lu dans le menu servi, jamais deviné),
  wizard complet → récap → panier → tunnel, + écrans fidélité et commandes. Captures lues.
  **Aucun P0/P1.** Ce que le parcours prouve, contre l'attente :
  - étape requise incomplète : le tap sur « Continuer » (grisé, volontairement PAS `disabled`)
    affiche « 👆 Choisis encore 2 options pour continuer ». J'ai d'abord cru à un cul-de-sac
    muet — c'était mon pilote qui cliquait « Continuer » en boucle sans jamais choisir de
    viande. Faux P0 évité de justesse ;
  - compteurs « 0 sélectionné · 2 minimum · 5 maximum », barre de progression 5 jalons,
    prix en permanence dans le CTA, récap avec un « MODIFIER » PAR ÉTAPE (la demande §1.3 du
    propriétaire est donc déjà satisfaite) ;
  - fidélité / commandes hors session : états « connecte-toi » corrects, aucun libellé brut,
    aucune erreur JS sur tout le parcours.
- C2 P2 relevés, non corrigés (cosmétique, à arbitrer) :
  - au récap, une étape facultative laissée vide affiche « — » (Suppléments) là où une autre
    affiche « Sans formule » (Faire un menu) : deux façons de dire « rien » ;
  - ~86 px de blanc entre la barre de progression et le compteur d'options, sur un écran de
    664 px : les options démarrent bas.
- C3 Captures : `scratchpad/audit-c1/` (`x00`, `x01`, `y00`..`y07`, `z-fidelite`, `z-commandes`).

### D — App Store — [~] D1 et D2 PROUVÉS ; D3 fait ; D4 partiel
- D1 **FAIT** — `app/ios/App/App/PrivacyInfo.xcprivacy` créé ET inscrit dans la cible Xcode
  (phase « Copy Bundle Resources »), preuve structurelle par lecture du `project.pbxproj`
  converti en JSON. Sans lui : rejet automatique `ITMS-91053`, avant toute revue humaine.
- D2 **FAIT** — `check:www` montrait 42+ fichiers divergents (le paquet iOS embarquait encore
  l'ANCIEN écran de connexion). `build:www` + `cap copy ios|android` (Node 22 obligatoire, le
  CLI Capacitor refuse Node 20) : les trois paquets portent désormais `?v=20260902mail1`.
  `cap sync` complet (pods/gradle) et la compilation restent à faire sur une machine avec Xcode.
- D3 `PUBLICATION.md` : compte de revue, notes de revue, parcours e-mail d'abord, ordre de
  déploiement (backend d'abord — CORS `https://localhost` — puis site, puis app).
- D4 **PARTIEL** — une seule mention trouvée : « Instagram, TikTok et Snapchat arrivent
  bientôt. » (`components.jsx:413`), qui est un fait vrai sur les réseaux du restaurant, pas une
  fonctionnalité factice. À arbitrer avec le propriétaire : Apple sanctionne les *fonctions*
  annoncées non livrées, pas une note de pied de page — mais le plus sûr reste de la retirer.

### E — Vérification et livraison — [x] SITE DÉPLOYÉ le 2026-09-02

**Déployé :** site `9f99754` poussé sur `origin/main` → Vercel. Contenu SERVI vérifié
(`api.js?v=20260902mail2`, `routeAbsente` présent, onglets absents), puis fumée sur la
production réelle : e-mail seul → 405 → dépliage prénom + nom + téléphone, **aucune erreur
affichée au client, aucune erreur JS**. La bascule de compatibilité est donc active en
production, et le nouveau parcours s'allumera seul le jour où le backend suivra.

**Non déployé, et pourquoi :**
- **Backend** — `ssh root@vps-418872ac.vps.ovh.net` → `Permission denied (publickey,password)`
  (revérifié ce jour). Le code est sur origine, branche neuve
  `app-mobile-email-dabord-2026-09-02` : la branche partagée était 207 commits en retard, et
  fusionner ça dans un arbre dont l'index porte des centaines de fichiers d'autres sessions
  n'est pas une opération à faire en douce sous couvert de « deploy ».
- **Application iOS/Android** — exige Xcode et un compte développeur (§3 de PUBLICATION.md).
  Les paquets `app/www`, `app/ios`, `app/android` portent déjà le nouveau site.

### E bis — Vérifications techniques — [x] TOUT PASSÉ, ATTRIBUTION FAITE

**Suite PHPUnit complète : 5542 tests, 23 020 assertions, 10 échecs — AUCUN n'est de mon fait.**
Attribution prouvée en rejouant les mêmes tests sur la pointe d'origine SEULE, sans mes commits :

| Échec | Verdict |
|---|---|
| `PrinterControllerTest` ×3, `CspAutoriseLesPontsDImpression`, `PrinterHostAllowlistSentinel` ×3 | **7/7 rouges sur l'origine seule** — travail d'impression d'une autre session |
| `FrozenZoneSha256BaselineSentinel` | rouge sur l'origine — voir §4 ci-dessous |
| `WithoutGlobalScopesAuditSentinel` | rouge sur l'origine — `EnsureTacosXl3ViandesCommand` |
| `Zone5PricingSsotConvergenceSentinel` | vert sur l'origine, rouge dans l'arbre principal → travail NON COMMITÉ d'une autre session (`KitchenBundledAddonCollapser`) |

Mes 4 commits ne touchent que 8 fichiers, **aucun `.js` ni `.vue`** — les 33 fichiers Vitest
rouges (9 tests) ne peuvent donc pas être de mon fait ; ils viennent du travail en cours dans
l'arbre partagé (bundles Mix non recompilés, composants POS/KDS modifiés).

**Branche déployable testée pour de vrai** : `backend-email-dabord-sur-origine` (origine + mes
4 commits reportés, 2 conflits résolus hunk par hunk) → `EmailLoginFlowTest`, `EmailOtpSignupTest`,
`GuestOtpVerifyHardeningTest`, `MultiDeviceLoginTest`, `DevOtpExposureTest` : **48/48**.

**Bancs navigateur (site)** : compte-email-dabord 24/24 · compte-serveur-en-retard 16/16 ·
compte-memoire-appareil 14/14 · coordonnees-erreurs 7/7 · one-page-checkout 17/17.

### 4. À REMONTER — un garde-fou de zone gelée est rouge sur la branche partagée

`FrozenZoneSha256BaselineSentinelTest` échoue sur l'origine à cause de
`resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (zone gelée, CLAUDE.md §7).
Le dernier commit qui l'a modifié est `baa1e41c9` (« le wizard s'ouvre sur le récap et saute à
l'étape demandée »). Deux étapes de la procédure du dépôt manquent :
- son document d'autorisation `LOCK_KIOSK_WIZARD_MODIFIER_DEPUIS_RECAP_2026-08-25.md` n'est **pas
  sur l'origine** (il n'existe qu'en zone d'index de l'arbre principal) ;
- `tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` n'a pas été mis à jour dans le même
  commit, comme l'exige le message d'erreur de la sentinelle.

**Je n'y touche pas** : mettre la référence à jour reviendrait à contresigner une modification de
zone gelée que je n'ai pas relue, ce que la porte humaine de CLAUDE.md §10 interdit précisément.
C'est à la session qui a fait ce travail, ou au propriétaire, de compléter les deux étapes.

### E ter — Vérifications d'outillage — [x]
- PHPUnit ciblé + `EmailOtpSignupTest` (18) inchangé vert.
- e2e site : `nav-smoke.local.js`, suites `*.regression.js` touchées, nouveaux specs.
- `compile-jsx.mjs --check`, `check-asset-versions.mjs --check`, `build-app-www.mjs --check`.
- Zones gelées : diff = 0. Commits par vague, **aucun push** (gate propriétaire).

## 3. Hors périmètre (dit, pas caché)
- Paiement en ligne : déjà réel (Mollie) sur le site ; pas de changement de fournisseur.
- Xcode / compte Apple Developer / certificats : humain (cf. `PUBLICATION.md §3`).
- Déploiement VPS du backend : procédure propriétaire inconnue (mémoire projet).
