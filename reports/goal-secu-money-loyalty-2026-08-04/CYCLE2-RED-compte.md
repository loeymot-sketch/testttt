# CYCLE 2 — RED-TEAM convergence — Gestion de compte (signup / login / identité / token / fidélité)

**Date** : 2026-08-04 · **Mode** : audit adversarial READ-ONLY (aucun fichier applicatif modifié)
**HEAD audité** : `ae4b27033` · **Cycle précédent** : `0649cb40d` (CYCLE1-RED-compte.md → P0+P1=0)
**Périmètre** : `GuestSignupController`, `SignupController`, `LoyaltyController`, `OtpManagerService`,
`ProfileController/Service`, `ProfileRequest`, `RefreshTokenController`, `ForgotPasswordController`,
`DeactivateController`, `KioskMachineLoginController`, `LoginController`, `RouteServiceProvider`,
`BlockKioskMachineToken`, `routes/api.php`.

**Discipline** : je n'ai PAS fait confiance au cycle 1. Chaque correctif est re-prouvé par une trace
`file:line` LUE À `ae4b27033` **et** par un test PHPUnit **exécuté moi-même** ce cycle (sqlite `:memory:`,
DB-safe). Résultats bruts exécutés :

| Suite | Résultat |
|---|---|
| `tests/Feature/Auth/EmailOtpSignupTest.php` | **18 passed** |
| `tests/Feature/Loyalty/PhoneUniqueGuestTest.php` | **2 passed** |
| `tests/Feature/Loyalty/LoyaltyRegisterAllowsWebLoginTest.php` | **6 passed** |
| `tests/Feature/Auth/RefreshTokenAbilityPreserveTest.php` | **6 passed** |
| `tests/Feature/Auth/GuestSignupAbilityScopeTest.php` | **1 passed** |
| `tests/Feature/Auth/UserMassAssignmentTest.php` | **2 passed** |

---

## VERDICT

> **P0 restants = 0 · P1 restants = 0.** Deuxième passe INDÉPENDANTE : les 5 correctifs **TIENNENT**
> (re-prouvés code + tests verts). Aucun NOUVEAU P0/P1 : ni takeover, ni vol de points/PII d'un compte
> **existant à valeur**, ni escalade de token/rôle, ni canal OTP non-throttlé. Le down-grade
> **P1-3 (oracle) → P2** et **P1-4 (phone non-unique) → P2** est **CONCOURU** par cette passe (2 cycles
> indépendants convergent) : je démontre pourquoi aucun des deux n'atteint la **compromission d'accès**.

| Sévérité | Nb | Résumé |
|---|---|---|
| **CORRECTIF-TIENT** | 5 | P0-1, P0-2, P1-1, P1-2, rename — chacun adossé à un test exécuté ce cycle |
| **P2** | 3 | oracle d'énumération (ex-P1-3) · `phone` non-unique + non-normalisé (ex-P1-4) · squat/DoS numéro **inutilisé/kiosk-only** (NOUVEAU-2) |
| **P3 / owner** | 3 | sous-requête `Order` sans `withTrashed()` · changement email/tel authentifié sans ré-auth (guest `auth_token` atteint `/profile`) · `->first()` non-ordonné du garde-valeur (défense-par-chance) |
| **REFUTED** | 5 | escalade token (refresh/guest) · IDOR ProfileController · IDOR/énumération `/check`+`/scan` · brute-force OTP via XFF · replay guest-OTP→reset |

---

# (A) RE-DISPUTE DES 5 CORRECTIFS — tous TIENNENT

## CORRECTIF-TIENT — P0-1 : takeover staff/admin soft-deleted
**`GuestSignupController.php:230`** (lookup `withTrashed()`) + **`:238`** (garde) + **`:245`** (restore gardé)

- **Trace** : `:230` `User::withoutGlobalScope(BranchScope)->withTrashed()->where('phone',…)->first()` → un
  staff **soft-deleted** EST trouvé. `:238` `if ($user && $user->is_guest != Ask::YES) throw 422` — la clause
  fatale `&& !$user->trashed()` a **disparu** (je l'ai cherchée, absente) → throw **AVANT** le restore. `:245`
  restore exige `is_guest == Ask::YES` (defense-in-depth).
- **Pas de contournement latéral re-vérifié** : `SignupController::register` (`:82`) lit **sans** `withTrashed()`
  (ne ressuscite jamais un staff), et n'écrase (`:88`) qu'un `is_guest===YES` **prouvé** par le marqueur
  `phone_verified` (`OtpManagerService:128` → `SignupController:72` pull). `LoyaltyController::register` ne fait
  **aucun** `restore()`.
- **Preuve exécutée** : `EmailOtpSignupTest::verify refuses and never restores a soft deleted staff account`
  **VERT** + `LoyaltyRegisterAllowsWebLoginTest::an established web account is not claimable` (l.204-214 : POST
  signup {phone victime, email attaquant} → **422**, mot de passe/email/is_guest victime **INTACTS**) **VERT**.

## CORRECTIF-TIENT — P0-2 : vol d'un invité soft-deleted À VALEUR (channel-confusion)
**`GuestSignupController.php:135`** (lookup `withTrashed()`) + **`:148-156`** (garde-valeur)

- **Trace** : lookup `:135-139` désormais `withTrashed()` (symétrique à `:230`). Livraison du code :
  `filled($existing->email)` → **email du compte** (`:149`) ; sinon `loyalty_points>0 || Order::exists()` →
  `deliverTo=null` (`:150-153`, **aucun** code à l'appelant) ; sinon (compte vide/nouveau) → email appelant
  (`:155`). Le garde lit la vraie colonne `users.loyalty_points` — pas un no-op.
- **Preuve exécutée** : `EmailOtpSignupTest::email otp does not deliver to attacker for soft deleted guest with
  points` **VERT** + `channel confusion otp goes to bound account email not attacker` **VERT**.

## CORRECTIF-TIENT — P1-1 : squatting via `/loyalty/register`
**`LoyaltyController.php:177`** — `$user->email = null;` (unique affectation `user->email` du fichier ;
branche existant `:203-211` = no-op documenté).

- **Trace** : l'endpoint PUBLIC (`routes/api.php:1570`, `throttle:5,1`, sans auth) ne **lie plus** l'email de
  l'appelant à un compte créé sur un téléphone tiers. Le conflit email (`:147-165`) renvoie 409 neutre si l'email
  appartient à autrui — **sans** fuiter code/points/phone du titulaire.
- **Preuve exécutée** : `LoyaltyRegisterAllowsWebLoginTest::public register does not bind unverified attacker
  email on a third party phone` (l.223-233 : `assertNull($created->email)`) **VERT**.

## CORRECTIF-TIENT — P1-2 : email-bombing (seau throttle par email)
**`RouteServiceProvider.php:88-107`**

- **Trace re-lue** : limiteur `otp-send` = 3 seaux — `perMinute(5)->by('otp-id:'.$id)` (`:92`, `id = phone ?: email`),
  `perMinute(20)->by('otp-send-global')` (`:93`, plafond ANTI-XFF), et `perMinute(5)->by('otp-email:'.lower($email))`
  (`:104`) quand l'email est présent → figer l'email + faire tourner le numéro est plafonné 5/min/email.
- **Appliqué aux 3 routes** (`routes/api.php`) : `signup/otp` (`:199`), `guest-signup/otp` (`:210`),
  `guest-signup/email-otp` (`:215`) — toutes `throttle:otp-send`.
- **Preuve exécutée** : `EmailOtpSignupTest::email otp throttled on sixth call` **VERT**.

## CORRECTIF-TIENT — rename « Guest User » (atteignable code-en-main seulement)
**`GuestSignupController.php:268`**

- **Trace** : renommage `if ($user && $name!=='' && in_array(trim($user->name), ['','Guest User'], true))` —
  atteint **uniquement** après `verify()` réussi (possession du code), **jamais** sur un vrai nom déjà porté.
- **Preuve exécutée** : `EmailOtpSignupTest::verify upgrades existing guest user placeholder name` **VERT** +
  `verify never overwrites a real existing name` **VERT** + `verify name from request overrides channel name`
  **VERT**.

---

# (B) NOUVEAUX ANGLES

## REFUTED — escalade de privilège du token (refresh + guest)
- Mint guest = `createToken('auth_token', ['kiosk:order'], +30j)` (`GuestSignupController.php:341`) — jamais `['*']`.
- `RefreshTokenController.php:52` `$abilities = $token->abilities ?? []` (fallback `[]`, **jamais** `['*']`) +
  `:64` préserve le **nom** → un guest reste `kiosk:order`/`auth_token`. `:40` refuse un token expiré.
- Sweep exhaustif `createToken` (5 surfaces) : `LoginController:157` (`['*']`, **mot de passe requis**),
  `ForgotPasswordController:206` (`['*']`, exige le code reset livré à l'email **lié** + `$user->tokens()->delete()`
  d'abord), `KioskMachineLoginController:104` (`kiosk-token`/`kiosk:order`, **bloqué** de `/profile`). Aucune
  ne mint un scope large sans preuve.
- **Preuve exécutée** : `RefreshTokenAbilityPreserveTest` **6 VERT** + `GuestSignupAbilityScopeTest::guest signup
  issues kiosk order ability only` **VERT**.

## REFUTED — IDOR / mass-assignment ProfileController
- `ProfileService::update` (`:24`) sujet = `auth()->user()->id`, **jamais** un id de requête. `ProfileRequest`
  impose `Rule::unique(users,email)->ignore(self)` (`:35`) et `unique(users,phone)->ignore(self)` (`:37`) →
  la règle unique interroge la table brute (**inclut les lignes soft-deleted**) → **impossible** de revendiquer
  l'email/tel d'un compte existant, même supprimé (donc pas de vol d'identité staff soft-deleted par ce chemin).
  Aucun `is_guest/role/loyalty_points/status` dans la liste blanche.
- **Preuve exécutée** : `UserMassAssignmentTest::public signup strips branch id is guest status` + `signup request
  validated strips sensitive fields` **2 VERT**.

## P3 (owner) — changement email/tel authentifié SANS ré-auth (guest atteint `/profile`)
**`routes/api.php:270`** (`middleware: auth:sanctum, block_kiosk_machine`) + **`ProfileService.php:26-29`**
- `BlockKioskMachineToken` (`:41`) refuse **uniquement** les tokens nommés `kiosk-token`. Le guest client est
  nommé `auth_token` (`GuestSignupController:341`) → il **atteint** `PUT /profile` et peut poser email/tel/nom
  avec pour seule barrière `ProfileRequest` (unique ignore-self, sans OTP ni ancien mot de passe).
- **Pourquoi PAS P1** : (a) unique(email)/unique(phone) **bloque** le vol de toute identité déjà enregistrée
  (existante OU soft-deleted) ; (b) opère sur le **propre** compte de l'appelant (`auth()->id`) → n'atteint
  aucun tiers ; (c) forgot-password sur l'email ainsi lié ne réinitialise que **son** compte (rôle CUSTOMER
  inchangé). Impact = lier un email **non-vérifié inutilisé** à son propre compte (contourne le modèle
  « preuve de possession » de l'OTP) → défense-en-profondeur latente. Durcissement : exiger OTP/ré-auth avant
  changement d'email dans `ProfileService`.

## P3 (owner) — sous-requête `Order` du garde-valeur sans `withTrashed()`
**`GuestSignupController.php:151`**
- `Order` **utilise** `SoftDeletes` (`app/Models/Order.php:17`) → `Order::exists()` (`:151`) **ne voit pas** les
  commandes soft-deleted. En théorie : invité soft-deleted + 0 point + commandes soft-deleted → retombe sur
  l'email appelant.
- **Non exploitable** re-vérifié : `DeactivateController::deleteAccount` (`:30-31`) `->delete()` **uniquement**
  addresses + user, **jamais** `Order` → les commandes restent non-trashed → visibles → `deliverTo=null`. Et la
  branche `loyalty_points>0` (`:150`) couvre déjà l'argent indépendamment des commandes. Aligner `withTrashed()`
  = cohérence, pas urgence.

## P2 — NOUVEAU-2 : pré-emption / DoS d'un numéro INUTILISÉ ou kiosk-only
**`GuestSignupController.php:155`** (livraison à l'email appelant, compte inexistant/vide) → **`:308-321`**
(bind `email` + `email_verified_at`) → **`:148`** (`filled(email)` gagne pour toutes les demandes ultérieures)
- **Scénario le plus fort (articulé plus nettement que cycle 1)** : un client « kiosk-only » a un compte
  `phone=V, email=null, is_guest=YES` (créé via `loyalty/register` → email null, `:177`). Tant qu'il a **0 point
  et 0 commande**, un attaquant connaissant V fait `POST /guest-signup/email-otp {phone:V, email:evil}` →
  `$existing` vide de valeur → `deliverTo=evil` → verify → `register()` bind `email=evil (vérifié)`. Désormais
  **toute** demande email-OTP pour V tombe dans `filled(email)` (`:148`) → code livré à **evil** ; comme aucun
  provider SMS n'est câblé (mandat owner), le vrai propriétaire de V est **verrouillé hors du web** et l'attaquant
  intercepte les codes du compte qui accumulera **ses futurs points**.
- **Pourquoi P2 et non P1** : au moment de l'exploitation **aucune valeur existante n'est compromise** — la garde
  `points>0 || orders` (`:150-153`) protège tout compte **déjà** à valeur ; seuls des comptes **vides** ou
  **inexistants** sont squattables. Le préjudice est **futur/spéculatif** (points à venir) + exige de connaître le
  numéro exact et de squatter **avant** le 1er usage web. Le fermer proprement exige une **preuve de possession
  téléphone (SMS)** — **interdite par le mandat owner**. → **décision owner** (tradeoff no-SMS V1).

## REFUTED — IDOR/énumération `/loyalty/check` et `/loyalty/scan`
- `/check` (`routes/api.php:1569`, `auth:sanctum`) : garde propriété/borne/staff (`LoyaltyController.php:89-98`),
  réponse **identique** 404 « Non trouvé » que le code existe ou non (anti-énumération). Le discriminant borne
  exige une **vraie** `KioskMachine` (`:92`) — un token guest a la même ability `kiosk:order` mais **pas** de ligne
  KioskMachine → tombe sur la garde propriétaire. `/scan` : même choke-point (`:827`).
- **Donc l'énumération via `/check` est fermée** ; le seul oracle restant est `/register` (public) — voir (C).

## REFUTED — brute-force OTP via rotation X-Forwarded-For & replay guest-OTP→reset
- `TrustProxies='*'` (`app/Http/Middleware/TrustProxies.php:24`) rend `throttle:3,5` (verify) spoofable, **mais**
  le rempart réel est le compteur **par-téléphone** `otp_verify_fail:<phone>` (`OtpManagerService:100-107`) : au 5ᵉ
  échec l'OTP vivant est **brûlé** (`:104`) → nouvelle demande obligatoire (throttlée `otp-send`). Clé = téléphone,
  pas IP → non contournable par XFF. Code one-time (`:118-121`). Tests `verify with wrong/expired code fails` **VERT**.
- Reset ≠ OTP : espaces disjoints (`otps` vs `password_resets` + `Str::random(64)` `ForgotPasswordController:116`),
  verrou d'échec par-email `:88-96`. Aucun pont.

## REFUTED (nuance intégrité) — concurrence signup/verify
- `PhoneUniqueGuestTest::two otp verifications same phone create only one user` **VERT** : en **séquentiel**, le 2ᵉ
  verify retrouve le compte du 1ᵉ (lookup `withTrashed` `:230`) → pas de doublon. Une **vraie** simultanéité (avant
  commit) pourrait créer un doublon (phone non-unique) → **intégrité** (famille P2 ci-dessous), **pas** une
  compromission d'accès.

---

# (C) FINDINGS PRÉ-EXISTANTS — CONVERGENCE P2 (concourue 2 cycles)

## P2 (ex-P1-3) — Oracle d'énumération téléphone + email (public)
**`LoyaltyController.php:228-234`** (`PHONE_EXISTS`, compte préexistant) vs **`:236-243`** (data, compte créé) vs
**`:156-163`** (`EMAIL_EXISTS` 409).
- **Fait** : `/loyalty/register` est **public** (`routes/api.php:1570`) et son `throttle:5,1` est **IP-keyed donc
  XFF-spoofable** (`TrustProxies='*'`) → énumération d'existence **effectivement illimitée**.
- **Tranche : je CONCOURS P2.** La réponse d'un compte **préexistant** ne renvoie **que** le signal d'existence
  (`PHONE_EXISTS`/`EMAIL_EXISTS`) — **aucun** name/loyalty_code/points (ceux-ci ne sortent que pour un compte
  **fraîchement créé** par la requête, `wasRecentlyCreated` `:228`). Zéro PII de contenu, **zéro accès** à un
  compte. Par le critère « accès vs existence », c'est de l'**information-disclosure d'existence** (mono-resto
  local) → **P2 solide, à healer** (réponse uniforme + seau par-identité anti-XFF), **pas P1** (ceiling =
  « ce numéro est-il client ? », pas la prise du compte).

## P2 (ex-P1-4) — `users.phone` sans UNIQUE ni normalisation
Migration `2026_05_16_140100_make_user_phone_required.php` = NOT NULL **sans** `->unique()` (grep : aucun index
unique). `LoyaltyController.php:143` matche `phone` **brut** (vs `/check:79` qui normalise) → `+33…`/`06…`/`06 12…`
= comptes distincts.
- **Tranche : je CONCOURS P2** (intégrité : points éclatés, réconciliation) **+ je signale une dimension sécu que
  cycle 1 n'avait pas articulée** : le garde-valeur email-OTP fait `->where('phone',V)->where('is_guest',YES)
  ->first()` **sans `orderBy`** (`:135-139`). Un doublon **sans valeur** (créable car `loyalty/register:143` ne
  fait pas `withTrashed`) coexistant avec un compte **à valeur** pourrait, si `->first()` le choisissait, router
  le code vers un attaquant en contournant la garde `points>0`.
- **Reste P2** : l'auto-increment ascendant + l'ordre PK par défaut de `->first()` sélectionnent le compte **le
  plus ancien** (le compte à valeur, créé en premier ; un doublon attaquant a **toujours** un id plus élevé) →
  l'attaque est **défendue-par-chance**, non fiable. → healer `phone UNIQUE` + normalisation + `orderBy('id')`
  explicite sur le garde-valeur (défense-en-profondeur).

---

# RESTE À STATUER (owner / gate) — inchangé vs cycle 1
1. **Convergence §6** : le couple RED (cycle 1 + cycle 2) atteint **P0+P1=0** de façon **indépendante**, et le
   down-grade **P1-3/P1-4 → P2** est désormais **concouru par 2 passes** → critère « findings stables 2 cycles »
   **REMPLI** (sous réserve d'entérinement owner du down-grade).
2. **NOUVEAU-2** : accepter le tradeoff « no-SMS » V1 **ou** imposer une preuve de possession téléphone alternative.
3. **Durcissements bon-marché non-bloquants** : `phone UNIQUE` + normalisation ; `orderBy('id')` +/- `withTrashed`
   cohérent sur le garde-valeur (`:135`) et la sous-requête `Order` (`:151`) ; réponse uniforme + seau par-identité
   sur `/loyalty/register` (anti-oracle XFF) ; ré-auth/OTP avant changement d'email dans `ProfileService`.
4. **État PROD** (diagnostic, non-bloquant) : `SELECT id, phone, is_guest, deleted_at FROM users WHERE deleted_at
   IS NOT NULL;` pour documenter le volume de staff/invités soft-deleted (P0-1/P0-2 les neutralisent déjà).

**Conclusion** : cœur du modèle **SAIN** et **STABLE sur 2 cycles indépendants** — 5 correctifs re-prouvés (tests
exécutés ce cycle), aucun takeover / vol de points d'un compte **à valeur** / fuite PII de contenu / escalade de
token ou de rôle. **P0+P1 restants = 0.** Le reste est **P2/P3** et **décision owner**.
