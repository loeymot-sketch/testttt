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

### A — Backend : contrat « e-mail d'abord » (testttt) — [ ]
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
- A3 Compte de revue App Store : `APP_REVIEW_EMAIL` + `APP_REVIEW_OTP` (env, vide par défaut) →
  code fixe pour **ce seul** e-mail, aucun envoi. Documenté dans `PUBLICATION.md`.
- A4 `dev_code` renvoyé en `local` uniquement (parité `otp()`), pour le banc E2E.
- A5 Tests PHPUnit `EmailLoginFlowTest` : connu → mail au compte + token par e-mail ;
  inconnu → `known:false` ; inscription prénom+téléphone → compte « Prénom » + e-mail attaché ;
  e-mail d'un compte non-invité → jamais de token ; compte sans numéro → clé synthétique, pas de
  doublon ; code de revue hors `local` désactivé sans env.

### B — Site/app : écran compte « e-mail d'abord » — [ ]
- B1 `api.js` : `emailLogin(email)`, `emailSignup({email, first_name, phone})`, `guestVerify`
  par e-mail.
- B2 `account-v2.jsx` : **un seul écran** : e-mail → (connu) code | (inconnu) dépliage prénom +
  téléphone dans le même écran → code. Onglets supprimés. Mémoire d'appareil = e-mail
  pré-rempli. Mode `phone` (social) conservé. Renvoi du code par le même canal.
- B3 `funnel.jsx` (coordonnées dans le tunnel) : même logique e-mail d'abord.
- B4 Tests e2e (banc mock backend `helpers-mock-backend.js`) : 3 états ; anciens specs
  `account-*` remplacés (ils verrouillaient les onglets).
- B5 Captures 390×844 lues : écran e-mail, dépliage, code, succès, tunnel.

### C — Wizards → panier → paiement → points — [ ]
- C1 Audit « à la place du client » des wizards (`wizard-v2.jsx`), du tunnel (`funnel.jsx`),
  de la fidélité (`screens.jsx WebLoyalty`, `loyalty-v2.jsx`) : liste défauts prouvés
  (file:line + capture), triés P0/P1/P2.
- C2 Correctifs P0/P1 (chaque correctif = un test qui rougit avant, verdit après).
- C3 Captures avant/après lues.

### D — App Store — [ ]
- D1 `PrivacyInfo.xcprivacy` (absent : requis depuis mai 2024 pour UserDefaults/fichiers).
- D2 `npm run check:www` → `build:www` → `cap sync ios` (sans Xcode : ce qui passe passe, le
  reste est listé).
- D3 `PUBLICATION.md` : compte de revue, notes de revue, parcours e-mail d'abord, ordre de
  déploiement (backend d'abord — CORS `https://localhost` — puis site, puis app).
- D4 Chasse aux « bientôt disponible » / factices visibles dans l'app.

### E — Vérification et livraison — [ ]
- PHPUnit ciblé + `EmailOtpSignupTest` (18) inchangé vert.
- e2e site : `nav-smoke.local.js`, suites `*.regression.js` touchées, nouveaux specs.
- `compile-jsx.mjs --check`, `check-asset-versions.mjs --check`, `build-app-www.mjs --check`.
- Zones gelées : diff = 0. Commits par vague, **aucun push** (gate propriétaire).

## 3. Hors périmètre (dit, pas caché)
- Paiement en ligne : déjà réel (Mollie) sur le site ; pas de changement de fournisseur.
- Xcode / compte Apple Developer / certificats : humain (cf. `PUBLICATION.md §3`).
- Déploiement VPS du backend : procédure propriétaire inconnue (mémoire projet).
