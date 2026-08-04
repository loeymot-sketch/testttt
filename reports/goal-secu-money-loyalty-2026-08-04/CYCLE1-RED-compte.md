# CYCLE1 — RED-TEAM convergence — Gestion de compte (signup / login / identité / token / fidélité)

**Date** : 2026-08-04 · **Mode** : audit adversarial READ-ONLY (aucun fichier applicatif modifié)
**HEAD audité** : `0649cb40d` (post-heal) · **HEAD du RED précédent** : `ba4d16a2a`
**Périmètre** : `GuestSignupController`, `LoyaltyController`, `RouteServiceProvider`, `OtpManagerService`,
`SignupController`, `ProfileController/Service`, `RefreshTokenController`, `ForgotPasswordController`,
web `account-v2.jsx`.

**Mission double** : (A) DISPUTER les 5 correctifs déployés depuis `ba4d16a2a` — (B) chasser NOUVEAU P0/P1.

**Preuve** : chaque verdict est adossé soit à un **test PHPUnit exécuté à HEAD** (sqlite `:memory:`, DB-safe),
soit à une **trace de code `file:line`**. Tests exécutés ce cycle :
- `tests/Feature/Auth/EmailOtpSignupTest.php` → **OK (18 tests, 80 assertions)**
- `tests/Feature/Loyalty/PhoneUniqueGuestTest.php` + `LoyaltyRegisterAllowsWebLoginTest.php` → **OK (2 tests, 14 assertions)**

---

## VERDICT

> **P0 restants = 0 · P1 restants = 0.** Les 5 correctifs **TIENNENT** (dont 2 verrouillés par des tests
> de régression verts qui encodent exactement l'attaque précédente). Aucun NOUVEAU P0/P1 : ni takeover,
> ni vol de points/PII d'un compte **existant**, ni escalade de privilège, ni canal non-throttlé.

**Nuance de convergence (à trancher gate)** : le RED précédent classait **P1-3 (oracle d'énumération)** et
**P1-4 (`users.phone` sans index UNIQUE)** en P1 ; ils sont **toujours ouverts** à `0649cb40d` (non healés).
Je les **re-note honnêtement P2** (signal d'existence sans PII / intégrité-données, pas de compromission
d'accès). **Ce n'est pas une stabilité de findings** au sens strict du critère de convergence §6 : tant que
l'owner n'entérine pas le down-grade (ou ne les heal pas), le couple RED n'est pas « identique 2 cycles ».

| Sévérité | Nb | Résumé |
|---|---|---|
| **CORRECTIF-TIENT** | 5 | P0-1, P0-2, P1-1, P1-2, P1-5 — tous vérifiés (tests verts + code) |
| **P2** | 5 | Squatting/DoS de numéro **inutilisé** (moitié non-close de P1-1) · alias Gmail vs seau-email · oracle d'énumération (ex-P1-3) · `phone` non-unique (ex-P1-4) · nom poussé par un tiers (ex-P2-1) |
| **P3 / owner** | 2 | sous-requête `Order` sans `withTrashed()` (défense-en-profondeur) · changement d'email sans ré-auth (latent) |
| **REFUTED** | 6 | escalade token · IDOR /check · IDOR ProfileController · canal non-throttlé · brute-force OTP via XFF · replay guest-OTP→reset |

---

# (A) DISPUTE DES 5 CORRECTIFS

## CORRECTIF-TIENT — P0-1 : takeover staff/admin soft-deleted (token wildcard)
**`GuestSignupController.php:238`** (garde) + **`:245`** (restore)

- **Ancien défaut** : `if ($user && $user->is_guest != Ask::YES && !$user->trashed())` — le `&& !trashed()`
  désarmait la garde pour un staff **supprimé**, puis `:245` le ressuscitait + mintait un token.
- **Correctif présent** : garde réduite à `if ($user && $user->is_guest != Ask::YES) throw` (**`:238`**, le
  `&& !trashed()` a disparu). Lookup en `:230` `withTrashed()` → le staff supprimé **EST** trouvé → `throw 422`
  **avant** tout restore. Le bloc restore (**`:245`**) exige `is_guest == Ask::YES` (defense-in-depth).
- **Pas de bypass latéral** :
  - `verify()` (`:196`) appelle **toujours** `register()` → même garde.
  - `SignupController::register` (`:82`) lit **sans** `withTrashed()` → ne trouve jamais le staff supprimé →
    ne le restaure jamais (crée un client neuf, aucune résurrection). `:88` n'écrase qu'un `is_guest===YES` **prouvé**.
  - `LoyaltyController::register` ne fait aucun `restore()` (crée un invité neuf).
- **Preuve exécutée** : `EmailOtpSignupTest::test_verify_refuses_and_never_restores_a_soft_deleted_staff_account`
  (`:244`) — asserte `deleted_at` intact, `is_guest` reste NO, email staff intact. **VERT**.

## CORRECTIF-TIENT — P0-2 : vol d'un invité soft-deleted à points (channel-confusion)
**`GuestSignupController.php:135`** (lookup) + **`:148-156`** (garde valeur)

- **Ancien défaut** : `emailOtp()` cherchait le compte **sans** `withTrashed()` → invité supprimé invisible →
  code livré à l'email de l'appelant (attaquant) ; `register()` le retrouvait `withTrashed()` et le restaurait.
- **Correctif présent** : lookup `:135` désormais `withTrashed()` (symétrie avec `:230`). Livraison :
  `filled(email)` → email **du compte** (`:149`) ; sinon `loyalty_points>0 || Order::exists()` → `deliverTo=null`
  (**`:150-153`**, aucun code à l'attaquant) ; sinon (compte vide) → email appelant. La garde valeur lit la
  vraie colonne `users.loyalty_points` — pas un no-op.
- **Preuve exécutée** : `test_email_otp_does_not_deliver_to_attacker_for_soft_deleted_guest_with_points` (`:271`,
  invité supprimé + 500 pts) **VERT** ; `test_channel_confusion_otp_goes_to_bound_account_email_not_attacker`
  (`:447`) **VERT**.
- **Résidu (P3, non-exploitable en pratique)** : la sous-requête `Order` (`:151`) n'a **pas** `withTrashed()`.
  Théorie : invité supprimé + 0 point + commandes **soft-deleted** → retombe sur l'email appelant. Mais
  `DeactivateController::deleteAccount` (`app/Http/Controllers/Auth/DeactivateController.php`) ne fait
  `->delete()` **que** sur `User` + addresses, **jamais** sur `Order` → les commandes restent **non-trashed** →
  `Order::exists()` les voit → `deliverTo=null`. La branche `loyalty_points>0` couvre déjà l'argent. Aligner
  `withTrashed()` sur la sous-requête = défense-en-profondeur cohérente, pas un correctif urgent.

## CORRECTIF-TIENT (cible) — P1-1 : squatting via `/loyalty/register`
**`LoyaltyController.php:177`** — `$user->email = null;` à la création (seule affectation de `user->email`
du fichier ; la branche `else` compte-existant est un no-op `:203-211`).

- **Ce qui est fermé** : l'endpoint public ne **lie plus** un email choisi par l'appelant à un compte créé sur
  un téléphone tiers → le vecteur exact de l'ex-P1-1 est mort. Preuve : `LoyaltyRegisterAllowsWebLoginTest`
  + `PhoneUniqueGuestTest` **VERTS**.
- **Résidu NON-CLOS → P2 (moitié restante du squatting)** : le correctif ne portait que sur `LoyaltyController`.
  Le chemin **email-OTP direct** de `GuestSignupController` lie **toujours** l'email de l'appelant à un
  **NOUVEAU** compte sur un numéro qu'il ne possède pas — voir **NOUVEAU-2** ci-dessous. Sévérité P2 (numéro
  **inutilisé** uniquement — aucun actif existant volé ; comptes existants à valeur = protégés P0-2/07-31).

## CORRECTIF-TIENT — P1-2 : email-bombing (seau throttle par téléphone)
**`RouteServiceProvider.php:97-105`**

- **Correctif présent** : en plus du seau par-identifiant (qui retombait toujours sur le `phone`) et du plafond
  global 20/min, un **seau dédié par email** `Limit::perMinute(5)->by('otp-email:'.Str::lower($email))` (**`:104`**)
  quand l'email est présent → figer l'email + **faire tourner le numéro** est désormais plafonné à 5/min/email.
- **Appliqué aux bonnes routes** (vérifié `routes/api.php`) : `signup/otp` (`:199`), `guest-signup/otp` (`:210`),
  `guest-signup/email-otp` (`:215`) portent tous `throttle:otp-send`.
- **Résidu → P2/P3** : le seau clé sur `Str::lower($email)` **brut** — les alias Gmail (`v.i.c+x@gmail.com`)
  ouvrent des seaux distincts pour une même boîte. Le plafond **global 20/min** reste le vrai plafond
  (≈1 seul destinataire réel → ≤20/min via alias). Vecteur borné, non éliminé.

## CORRECTIF-TIENT — P1-5 : bouton « Renvoyer le code » mort (régression front)
**`/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne/account-v2.jsx:301`**

- **Correctif présent** : l'appel resend passe désormais `(form.first||'').trim(), (form.last||'').trim()`
  (`:301`) — plus de `first_name:""` → plus de 422 `min:2`. Les deux autres appels (`:93`, `funnel.jsx`) déjà
  corrects. **Reste ouvert côté produit** : le canal SMS `POST /guest-signup/otp` (`routes/api.php:210`,
  `GuestSignupPhoneRequest` = téléphone seul) retombe encore sur le placeholder `Guest User`
  (`GuestSignupController.php:274`) — l'exigence d'identité n'est portée que par le FormRequest **email**.

---

# (B) NOUVEAUX ANGLES

## NOUVEAU-1 — REFUTED : escalade de privilège du token guest
- Mint guest = `createToken('auth_token', ['kiosk:order'], +30j)` (`GuestSignupController.php:341`) — jamais `['*']`.
- `RefreshTokenController.php:52-72` **préserve les abilities verbatim** (fallback `[]`, jamais `['*']`) et le
  **nom** du token → un guest reste `kiosk:order` après refresh (pas d'accès canal WS kiosk, réservé au nom
  `kiosk-token`). Grep exhaustif `createToken` : seuls `LoginController`/`ForgotPassword`/commandes E2E émettent `['*']`.
- `ForgotPasswordController::resetPassword` mint `['*']` (`:206-210`) **mais** sur le même `User` (rôle CUSTOMER
  inchangé → les routes admin restent gatées par Spatie/permission). L'ability large n'octroie pas de rôle.
  **Non escaladable** — d'autant que P0-1 ferme le foothold OTP initial sur un compte staff.

## NOUVEAU-2 — P2 : pré-emption / DoS d'un numéro INUTILISÉ (email-OTP lie un email à un téléphone non-possédé)
**`GuestSignupController.php:155`** (livraison à l'email appelant pour numéro sans compte) →
**`:312-321`** (bind + `email_verified_at = now` à la création).

- **Scénario** : `POST /guest-signup/email-otp {phone: V(inutilisé), email: attaquant@evil}` → `$existing=null`
  → `deliverTo = email appelant` (`:155`) → code chez l'attaquant → `verify` → `register()` **crée** le compte
  `phone=V, email=attaquant (vérifié), is_guest=YES` (`:273-321`). L'attaquant « possède » le numéro V.
- **Impact** : toute demande email-OTP **ultérieure** pour V tombe dans la branche `filled(email)` (`:148`) →
  code livré à **attaquant@evil**, **même si** la vraie victime accumule ensuite des points (la branche
  `points>0` est court-circuitée par `email` qui gagne en premier). Le vrai propriétaire de V (nouveau client)
  ne reçoit jamais **son** code → **il ne peut pas s'inscrire** (déni de service ciblé + interception du code).
- **Pourquoi P2 et pas P1** : (a) **aucun compte existant à valeur n'est touché** (P0-2/07-31 les couvrent) —
  seuls des numéros **jamais utilisés** ; (b) throttle 5/min/phone + 5/min/email + 20/min global → **pas de
  squatting de masse** ; (c) exige de **connaître le numéro exact** d'un futur client et de le squatter **avant**
  sa 1re inscription ; (d) le fermer proprement exige une **preuve de possession du téléphone (SMS)** —
  **interdite par le mandat owner « pas de SMS »**. → **décision owner** : accepter le tradeoff V1 ou exiger
  une identité de possession alternative. C'est la moitié non-close de l'ex-P1-1.

## NOUVEAU-3 — REFUTED : renommer/écraser un compte via verify sur un téléphone non-possédé
- Le renommage (`GuestSignupController.php:268`) n'est atteint qu'**après** `verify()` réussi = **possession du
  code prouvée**, et **jamais** sur un vrai nom (`in_array(trim(name), ['','Guest User'])`). Un attaquant ne
  peut pas renommer un compte à valeur d'autrui (le code part à l'email du compte, pas au sien).
- **Résidu P2 (ex-P2-1, toujours présent)** : la **source** du nom (`email_otp_name:<phone>`, posé en `:168`
  par le demandeur du code) permet de pousser un texte arbitraire (« ARNAQUE APPELEZ… ») vers l'écran caisse
  **si** la victime valide un verify **sans** re-poster son nom sur un compte encore `Guest User`. Le front web
  poste toujours le nom (priorité `:257`) → non déclenchable via l'UI ; atteignable par appel API brut. Défacement
  d'affichage, pas de takeover/argent.

## NOUVEAU-4 — REFUTED : IDOR / fuite PII sur `/loyalty/check`, `/scan`, `ProfileController`
- `/check` : `auth:sanctum` (`routes/api.php:1569`) + garde propriété/borne/staff (`LoyaltyController.php:89-98`),
  réponses **identiques** « non trouvé » (404) que le code existe ou non (anti-énumération). Le discriminant borne
  exige une **vraie** `KioskMachine` (un token guest a la même ability `kiosk:order` → `tokenCan()` seul serait
  contournable, mais la ligne KioskMachine ferme ça). `/scan` : même choke-point (`:827`).
- `ProfileController` : sujet = `auth()->user()->id` (`ProfileService.php:24`), jamais un id de requête ;
  `ProfileRequest` impose `Rule::unique(users,email/phone)->ignore(self)` → **impossible** de voler l'email/tel
  d'autrui. Pas de mass-assignment (`is_guest`/`role`/`loyalty_points`/`status` hors liste blanche 4 champs).
- **Résidu latent (owner)** : `ProfileService::update` change l'email **sans ré-auth** (ni ancien mot de passe ni
  OTP). Inoffensif seul (unicité + compte propre), mais c'était un maillon de l'ex-chaîne P0-1 — à durcir si un
  jour un token large touche ce point.

## NOUVEAU-5 — REFUTED : brute-force du code OTP via rotation X-Forwarded-For
- `/verify` porte `throttle:3,5` **par-IP** (spoofable via XFF, `TrustProxies='*'`). **Mais** le vrai rempart est
  le compteur **par-téléphone** de `OtpManagerService::verify()` (`:100-107`, `otp_verify_fail:<phone>`) : au 5e
  échec, l'OTP vivant est **brûlé** (`delete` par phone) → nouvelle demande obligatoire (throttlée `otp-send`).
  Clé = téléphone, **pas IP** → **non contournable par XFF**. Le code est **one-time** (supprimé au succès `:118`).
  `EmailOtpSignupTest::test_verify_with_wrong_code_fails` / `expired` **VERTS**.
- **Canal non-throttlé ?** Non : `otp`/`email-otp`/`signup/otp` en `throttle:otp-send`, `verify` en `throttle:3,5`
  + verrou par-identité. Aucun canal OTP nu.

## NOUVEAU-6 — REFUTED : replay d'un code guest-OTP sur le reset mot de passe
- Espaces disjoints : guest-OTP = table `otps` ; reset = table `password_resets` + `Str::random(64)`
  (`ForgotPasswordController.php:116`), verrou d'échec par-email `:88-96`. Aucun pont.

---

# (C) FINDINGS PRÉ-EXISTANTS TOUJOURS OUVERTS (non healés depuis `ba4d16a2a`)

## P2 (ex-P1-3) — Oracle d'énumération téléphone + email (public, non-auth)
**`LoyaltyController.php:228-234`** (PHONE_EXISTS) vs **`:236-243`** (data créé) vs **`:156-163`** (409 EMAIL_EXISTS).
Un anonyme distingue « ce numéro / cet email est-il client ? » ; doublé par `/loyalty/opt-in` (`routes/api.php:1637`,
`throttle:5,1` propre → 10 sondes/min/IP). **Toujours présent** à HEAD. PII déjà retirée (07-02) ; seul le
**signal d'existence** subsiste → je re-note **P2** (information-disclosure bornée, mono-resto). *Down-grade à
entériner par l'owner pour la stabilité de convergence.*

## P2 (ex-P1-4) — `users.phone` sans contrainte UNIQUE ni normalisation
Migration `2026_05_16_140100_make_user_phone_required.php` rend `phone` **NOT NULL** mais **n'ajoute AUCUN
`->unique()`** (grep confirmé : aucun index unique sur `phone`). `LoyaltyController.php:143` matche `phone` brut
(pas de normalisation, contrairement à `check` `:79`) → `+33…`/`06…`/`06 12…` = comptes distincts ; pas de
`withTrashed()` en `:143`. **Intégrité-données** (points éclatés, réconciliation), pas de compromission d'accès
directe → **P2** honnête. *Non healé — owner : migration UNIQUE risquée sur données dupliquées existantes.*

---

# RESTE À STATUER (owner / gate)
1. **État PROD** : `SELECT id, phone, is_guest, deleted_at FROM users WHERE deleted_at IS NOT NULL;` — la base de
   dev contenait des staff soft-deleted (id 71 POS Operator dans le RED précédent). P0-1/P0-2 les neutralisent,
   mais confirmer le volume PROD documente le risque **avant** heal.
2. **Convergence §6** : entériner le down-grade P1-3/P1-4 → P2 **ou** les heal ; sinon le critère « findings
   identiques 2 cycles » n'est pas rempli.
3. **NOUVEAU-2** (squatting/DoS numéro inutilisé) : accepter le tradeoff « no-SMS » V1, ou imposer une preuve de
   possession téléphone alternative.
4. **Durcissements bon-marché non-bloquants** : `withTrashed()` sur la sous-requête `Order` (`:151`) ;
   seau email normalisé (anti-alias) ; ré-auth avant changement d'email (`ProfileService`).

**Conclusion** : cœur du modèle **SAIN** — les 5 correctifs tiennent (2 verrouillés par tests verts), aucun
takeover / vol de points / fuite PII d'un compte **existant**, aucune escalade de token. **P0+P1 restants = 0.**
Le reste est P2/P3 et **décision owner**.
