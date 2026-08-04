# RED-TEAM — Gestion de compte utilisateur (signup / login / identité / token)

**Date** : 2026-08-04 · **Mode** : audit adversarial READ-ONLY (aucun fichier applicatif modifié)
**HEAD** : `ba4d16a2a` · **Branche** : `pos/category-first-caisse-2026-06-23`
**Périmètre** : signup guest email-OTP, login, profil, identité, liaison téléphone/email, tokens Sanctum, fidélité.

**Méthode** : chaque finding est prouvé par un **repro exécuté** (PHPUnit contre l'app réelle, MySQL `foodking_e2e`)
ou **rejeté**. Les artefacts de preuve sont dans le scratchpad de session
(`RedCompteAttackTest.php`, `RedCompteWave2Test.php`, `RedCompteWave3Test.php`, `RedCompteChainTest.php`) —
**volontairement non commités** (audit read-only). Tout finding sans `file:line` + repro a été écarté.

---

## VERDICT

> **BLOCK — 2 P0 exploitables sans aucune authentification.**

La garde anti-privilege-escalation de `register()` et la garde anti-channel-confusion de `emailOtp()`
partagent **une même faille structurelle : elles ignorent les comptes soft-deleted**, alors que
`register()` les retrouve *et les restaure*. Le résultat est une chaîne complète prouvée de bout en bout :

**un simple numéro de téléphone d'ex-employé → token Sanctum `['*']` (wildcard) sur son compte staff.**

La précondition n'est pas théorique : la base de dev contient **4 comptes soft-deleted**, dont
l'id **71** (`phone=0611224433`, rôle **POS Operator**). *(État de la base de PRODUCTION non vérifié —
je n'y ai pas accès : à confirmer par l'owner, c'est ce qui décide de l'urgence réelle.)*

Bonne nouvelle : le **cœur** des gardes 07-30 / 07-31 **tient** sur les comptes vivants (ATT-3, ATT-11),
l'OTP est **one-time**, le cap anti-brute-force **ne se réinitialise pas**, le replay
guest-OTP → reset-mot-de-passe est **impossible**, et `ProfileService` **n'a ni IDOR ni mass-assignment**.
Les 2 P0 sont des **angles morts du soft-delete**, pas un effondrement du modèle.

| Sévérité | Nb | Résumé |
|---|---|---|
| **P0** | 2 | Takeover staff/admin (wildcard) · vol de compte invité + points |
| **P1** | 5 | Squatting de numéro · email-bombing · oracle d'énumération · téléphone non-unique · *(fonctionnel)* bouton « Renvoyer » mort |
| **P2** | 6 | Nom attaquant en caisse · TOCTOU · DoS OTP · DoS global · PRNG · throttle manquant |
| **REFUTED** | 9 | Hypothèses du brief testées et **écartées** (détail en fin de rapport) |

---

# P0-1 — Prise de contrôle TOTALE d'un compte STAFF/ADMIN soft-deleted (token wildcard)

**`app/Http/Controllers/Auth/GuestSignupController.php:229`** (garde) + **`:235-239`** (résurrection)

### Scénario
Un employé est licencié. `EmployeeController::destroy` → `EmployeeService.php:208` `$employee->delete()` =
**soft delete** (`User` utilise `SoftDeletes`, `app/Models/User.php:30`), **le rôle reste attaché**.
N'importe qui connaissant son numéro de téléphone reprend le compte — sans authentification.

### Preuve (code)

La garde anti-privilege-escalation est neutralisée par sa propre condition :

```php
// GuestSignupController.php:225
$user = User::withoutGlobalScope(BranchScope::class)->withTrashed()->where('phone', $array['phone'])->first();

// :229  <-- `!$user->trashed()` DÉSARME la garde pour exactement les comptes supprimés
if ($user && $user->is_guest != Ask::YES && !$user->trashed()) {
    throw new \Exception(trans('all.message.credentials_invalid'), 422);
}

// :235-239  <-- ...et le bloc suivant les RESSUSCITE
if ($user && $user->trashed()) {
    $user->restore();
    $user->status = \App\Enums\Status::ACTIVE;
    $user->save();
}
```

Pour un staff : `is_guest != Ask::YES` = **vrai** (10 ≠ 5), mais `!$user->trashed()` = **faux**
→ conjonction fausse → **le `throw` est sauté**, puis `restore()` + `status = ACTIVE`.

Le code OTP part chez l'attaquant parce que la garde channel-confusion filtre `is_guest = YES`
(un staff n'est jamais trouvé) et omet `withTrashed()` :

```php
// :131-134
$existing = User::withoutGlobalScope(BranchScope::class)
    ->where('phone', $request->post('phone'))
    ->where('is_guest', Ask::YES)      // un staff n'est JAMAIS trouvé
    ->first();                          // + aucun ->withTrashed()
// :149-151
} else { $deliverTo = $request->post('email'); }   // => l'email de l'APPELANT
// :168
Mail::to($deliverTo)->send(new SignupOtpMail((string) $token, $ttlMinutes));
```

### Repro exécuté — chaîne complète, 6 étapes

Point de départ : **le seul téléphone `0611224433`**. Aucune authentification.

```
[1] code OTP obtenu par l'attaquant : OUI          (email-otp, email = attaquant@evil.com)
[2] verify=201 | token sur compte staff : OUI | compte restaure : OUI
[3] PUT /api/profile = 200 | email du compte staff = "attaquant@evil.com"
                                        (aucune verification de l'ancien mot de passe)
[4] forgot-password = 200 | PIN livre a l'attaquant = "214233"
[5] verify-code = 200 | reset_token obtenu : OUI
[6] reset-password = 200 | token final : OUI
    >>> abilities = ["*"] | tokenable_id = 1 (compte staff = 1) | roles = ["Branch Manager"]
    >>> RESULTAT : PRISE DE CONTROLE TOTALE du compte staff avec token WILDCARD
```

Maillons de l'escalade, tous vérifiés :

| # | Maillon | `file:line` |
|---|---|---|
| 2 | token `auth_token` `['kiosk:order']` émis sur l'id staff | `GuestSignupController.php:331` |
| 3 | routes profil **autorisent** `auth_token` (seul `kiosk-token` est bloqué) | `app/Http/Middleware/BlockKioskMachineToken.php:42` |
| 3 | changement d'email **sans** contrôle de l'ancien mot de passe | `app/Services/ProfileService.php:28` + `ProfileRequest.php:31-36` |
| 6 | reset → token **wildcard** | `app/Http/Controllers/Auth/ForgotPasswordController.php:206-210` |

### Second effet : bypass d'offboarding (prouvé séparément)

```
[ATT-9] login APRES licenciement (attendu: refuse) = 400
[ATT-9] verify guest-signup = 201
[ATT-9] compte restaure ? OUI | status = 5 | roles = ["Branch Manager"]
[ATT-9] login APRES l'attaque = 201        <-- l'ancien mot de passe REFONCTIONNE
```

L'ex-employé (qui connaît son propre mot de passe) **réactive lui-même son accès** — la révocation
d'accès du licenciement est annulée. `LoginController.php:68` exige `status = ACTIVE` : c'est
précisément ce que `:237` force.

### Portée réelle — ce qui est faux dans la version « maximaliste »
- ❌ **Pas de session web** : `EnsureFrontendRequestsAreStateful` est **commentée** et `StartSession`
  absent du groupe `api` (`app/Http/Kernel.php:52-64`) → le `loginUsingId` de `:315` ne pose **aucun
  cookie**. (Mon premier repro affichait `auth('web')->id()=1` : c'est l'état *intra-requête* du process
  de test, pas une session exploitable. Écarté.)
- ❌ Le token de l'étape 2 est bien **`kiosk:order` uniquement** — la garde Z6-02 tient *au moment du mint*.
  C'est l'**étape 3→6** qui l'élargit en wildcard, pas un défaut d'ability.

### Correctif proposé (non appliqué — audit read-only)
Évaluer `trashed()` **après** le refus, jamais dedans :
```php
if ($user && $user->is_guest != Ask::YES) {         // <-- retirer `&& !$user->trashed()`
    throw new \Exception(trans('all.message.credentials_invalid'), 422);
}
```
Et ne restaurer **que** les invités (`is_guest === Ask::YES`). Un compte staff supprimé doit être
réactivé par un admin, jamais par un OTP. Considérer aussi une re-vérification (mot de passe ou
OTP sur l'ancien email) avant tout changement d'email dans `ProfileService::update`.

---

# P0-2 — Vol d'un compte INVITÉ soft-deleted + de ses points (channel-confusion contournée)

**`app/Http/Controllers/Auth/GuestSignupController.php:131-134`** — lookup sans `withTrashed()`

### Scénario
Asymétrie entre deux lectures du **même** compte :
`emailOtp()` cherche **sans** `withTrashed()` (`:131`) → ne voit pas le compte supprimé → livre le code
à l'email de l'appelant. `register()` cherche **avec** `withTrashed()` (`:225`) → le retrouve, le restaure,
et émet un token dessus. Le commentaire `[GAP-32-3]` de `:225` documente exactement ce piège… d'un seul côté.

### Repro exécuté
```
[ATT-1] code livre a l'email ATTAQUANT ? OUI (FUITE)
[ATT-1] verify status = 201
[ATT-1] compte victime restaure ? OUI | points = 500 | token emis ? OUI
```
Variante compte **téléphone-seul avec points** (la garde MISSION-1 du 07-31) — contournée pareil :
```
[ATT-12] code livre a l'attaquant malgre 800 points (soft-delete) ? OUI (GARDE CONTOURNEE)
[ATT-12] verify = 201 | restaure = OUI | points recuperes par l'attaquant = 800
```
La réponse `register()` renvoie `new UserResource($user)` (`:348`) → **nom, email, téléphone,
`loyalty_code`, `loyalty_points`** de la victime livrés à l'attaquant.

### Correctif proposé
Ajouter `->withTrashed()` à `:131` (symétrie avec `:225`) **et** appliquer la garde valeur
(points / commandes) au compte trashed. La garde `:145-148` interroge bien la vraie colonne
`users.loyalty_points` (migration `2026_03_08_145926`) — elle n'est **pas** un no-op : elle est
seulement **court-circuitée** par le soft-delete.

---

# P1-1 — Squatting de numéro via `/loyalty/register` public → prise de contrôle différée

**`app/Http/Controllers/Frontend/LoyaltyController.php:167-212`** · route `routes/api.php:1570`
(**aucun `auth:sanctum`**, seulement `apiKey` + `throttle:5,1`)

### Scénario
L'endpoint crée un compte `is_guest = YES` (`:194`) avec un **email choisi par l'appelant**, sur un
**téléphone quelconque**. L'attaquant « réserve » le numéro d'une victime avec SON email. Plus tard,
la victime commande au comptoir : la fidélité étant **indexée sur le téléphone**, les points atterrissent
sur ce compte. La garde channel-confusion (`:143`) livre alors le code à « l'email lié au compte »…
qui est celui de l'attaquant. Elle se retourne contre la victime.

### Repro exécuté
```
[ATT-13] /loyalty/register (NON authentifie) = 200
[ATT-13] compte cree : id=1 is_guest=5 email="attaquant@evil.com" loyalty_code=BF81680F
[ATT-13] code livre a l'email SQUATTEUR ? OUI
[ATT-13] verify = 201 | token emis ? OUI
[ATT-13] PII rendue a l'attaquant = {"name":"Vraie Victime","phone":"0612345678",
                                     "points":420,"code":"BF81680F"}
```

Le durcissement de 07-02 (`:197-205`, ne plus attacher d'email à un compte **existant**) est correct et
tient (**ATT-14 : no-op vérifié**) — mais il ne couvre pas la **création** sur un numéro tiers.

### Correctif proposé
N'attacher un email à la création que s'il est **vérifié** (OTP), ou créer le compte fidélité
**sans email** ; l'email ne devrait se poser que par un chemin prouvant la possession.

---

# P1-2 — Email-bombing : le throttle est indexé sur le TÉLÉPHONE, pas sur le destinataire

**`app/Providers/RouteServiceProvider.php:88-96`**

```php
$raw = $request->input('phone') ?: $request->input('email') ?: 'anon';   // <-- phone PRIORITAIRE
$id  = Str::lower(is_string($raw) ? $raw : 'anon');
return [ Limit::perMinute(5)->by('otp-id:'.$id), Limit::perMinute(20)->by('otp-send-global') ];
```

`GuestSignupEmailOtpRequest.php:30-31` rend `phone` **et** `email` obligatoires → le `?:` retient
**toujours** le téléphone. L'attaquant fige l'email de la victime et **fait tourner le téléphone** :
chaque numéro ouvre un seau neuf. Le commentaire du limiteur annonce pourtant viser
« le harcèlement d'une victime en fixant son phone/**email** » — l'intention n'est pas atteinte
sur le canal email.

### Repro exécuté (8 téléphones rotatifs, 1 seule adresse cible)
```
[ATT-6] tel rotatif #0..#7 -> 200 (x8)
[ATT-6] mails OTP envoyes a une adresse tierce = 8
```
Seul le plafond global (20/min) freine → **~1 200 emails OTP brandés/heure** vers une adresse
(harcèlement, coût Brevo, réputation d'expéditeur).

### Correctif proposé
Ajouter un seau **par email destinataire** : `Limit::perMinute(3)->by('otp-mail:'.$email)`.

---

# P1-3 — Oracle d'énumération téléphone + email (non authentifié)

**`LoyaltyController.php:222-228`** vs **`:230-237`** vs **`:156-163`**

Trois réponses distinguables sur un endpoint public :

| Cas | Réponse | `file:line` |
|---|---|---|
| Téléphone **inconnu** | `200` + `data{name, loyalty_code, points}` | `:230-237` |
| Téléphone **connu** | `200` + `code: "PHONE_EXISTS"` | `:222-228` |
| Email **déjà pris** | `409` + `code: "EMAIL_EXISTS"` | `:156-163` |

Un anonyme apprend « ce numéro / cet email est-il client ? ». Le chemin est **doublé** par
`/loyalty/opt-in` (`:461` appelle `register()` et le renvoie tel quel `:491`) qui possède son **propre**
seau `throttle:5,1` (`routes/api.php:1637`) → **10 sondes/min/IP**.
La PII a bien été retirée en 07-02 ; c'est le **signal d'existence** qui subsiste.

*Note : mon premier repro (ATT-15) fut non concluant (422 des deux côtés, payload incomplet) — le
finding repose sur la lecture du code, branches citées ci-dessus, pas sur ce repro.*

---

# P1-4 — `users.phone` sans contrainte UNIQUE ni normalisation → points éclatés

**`database/migrations/2014_10_12_000000_create_users_table.php:21-22`** — `email` et `phone`
`nullable()`, **aucun `->unique()`**. Seul `loyalty_code` est unique
(`2026_03_08_145926_add_loyalty_fields_to_users_table.php:16`).

### Repro exécuté
```
[ATT-8] 2e compte meme telephone -> CREE (aucune contrainte UNIQUE)
[ATT-8] nb comptes avec ce tel = 2
```

Trois axes de duplication, tous sur la **clé de fidélité** :
1. **Aucun index unique** (ci-dessus) — les `Rule::unique` de `ProfileRequest.php:31-36` sont
   applicatives seulement, et ignorent les soft-deleted.
2. **Aucune normalisation** dans `LoyaltyController.php:143` (`where('phone', $input)` brut) alors que
   `check` normalise (`:79` `preg_replace('/[\s\-]/', '', ...)`), et `validateRegistration` (`:39-43`)
   n'impose aucun format → `+33612345678`, `0612345678`, `06 12 34 56 78` = **3 comptes**.
3. **Pas de `withTrashed()`** en `:143` → un titulaire supprimé est invisible → doublon
   (`GuestSignupController.php:225` fait l'inverse, et documente le piège).

Conséquence métier : points d'un même client éclatés sur plusieurs lignes, réconciliation manuelle.

---

# P2 — Findings secondaires

| # | Finding | `file:line` | Détail |
|---|---|---|---|
| **P2-1** | **Nom choisi par un tiers affiché en caisse** | `GuestSignupController.php:163-167` + `:258-261` | Le cache `email_otp_name:<phone>` est posé par **celui qui demande le code**, pas par celui qui le possède. Repro : `[ATT-7] nom final du compte victime = "ARNAQUE APPELEZ 0900"`. La victime a reçu le code sur SON email (garde OK) et validé normalement ; le nom de l'attaquant s'est appliqué à son compte placeholder. Texte arbitraire poussé vers l'écran caisse / ticket cuisine. **Le renommage lui-même exige la possession du code — voir REFUTED-3.** |
| **P2-2** | TOCTOU sur `verify()` | `app/Services/OtpManagerService.php:109` → `:118` | Aucun `lockForUpdate()` / transaction entre le SELECT et le DELETE → deux `/verify` simultanés peuvent tous deux réussir (2 tokens). Le rejeu **séquentiel** est bien fermé. |
| **P2-3** | DoS de l'OTP d'une victime | `OtpManagerService.php:104` | 6 mauvais `/verify` **brûlent** l'OTP en attente (`delete` par `phone` seul) ; la victime doit redemander, plafonné 5/min. |
| **P2-4** | DoS d'inscription plateforme | `RouteServiceProvider.php:94` | `Limit::perMinute(20)->by('otp-send-global')` est un goulot **partagé** : un seul attaquant sature et **bloque toutes les inscriptions** (SMS + email). La parade anti-XFF est devenue un SPOF. |
| **P2-5** | `loyalty_code` prédictible | `LoyaltyController.php:102`, `:209` | `md5(uniqid())` **sans `more_entropy`** = timestamp µs → prédictible, incohérent avec `GuestSignupController.php:284` (`uniqid('', true)`). Non exploitable en ligne (endpoints consommateurs authentifiés + throttlés). |
| **P2-6** | `change-password` sans throttle dédié | `routes/api.php:273` | Seul le `throttle:api` (120/min) s'applique, contrairement à login/OTP qui ont tous un limiteur nommé. |

---

# P1-5 — [FONCTIONNEL, non-sécu] « Renvoyer le code » est mort — **régression** du fix 07-30

**`/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne/account-v2.jsx:298`**

```js
api.guestEmailOtp(ph, form.email.trim())      // <-- 2 arguments seulement
```
La signature est `guestEmailOtp(phone, email, code, firstName, lastName)` (`api.js:217`) et le corps
sérialise `first_name: String(firstName || '')` (`api.js:221`) → **`first_name: ""`, `last_name: ""`**.
Or `GuestSignupEmailOtpRequest.php:34-35` exige `min:2` depuis le 2026-08-01 → **422 garanti à chaque clic**.

Le `.catch()` (`account-v2.jsx:300`) le masque en `« Renvoi impossible, réessaie dans un instant. »`
— message qui suggère un throttle passager — et **ne réarme jamais le compte à rebours**.
Le client qui n'a pas reçu le premier email (spam, délai) est en **impasse définitive** : il peut
cliquer indéfiniment, aucun code ne repartira. Parcours de commande interrompu.

**C'est une régression du bouton corrigé le 2026-07-30** (« BUG A », commentaire encore présent
`account-v2.jsx:291-295`) : l'exigence nom+prénom du 08-01 n'a pas été propagée à ce point d'appel.
Les deux autres appels sont corrects (`account-v2.jsx:93`, `funnel.jsx:723`).
Correctif : passer `(form.first || '').trim(), (form.last || '').trim()` comme en `:93`.

**Parité front/back min:2 — conforme** (hypothèse du brief écartée) : `account-v2.jsx:57-58` et
`funnel.jsx:718-721` valident sur la valeur **trimmée** ; un nom d'espaces seuls est bloqué côté client.

**Chemin « Guest User » résiduel** (point 7 du brief) : le canal SMS `POST /api/auth/guest-signup/otp`
(`routes/api.php:209`, `GuestSignupPhoneRequest` = téléphone seul, **sans nom**) reste **actif côté
serveur** — plus aucun appelant dans l'UI web, mais un appel direct à l'API retombe sur le placeholder
`GuestSignupController.php:264` `$name = 'Guest User'`. L'exigence d'identité n'est portée que par le
FormRequest du canal **email** : elle est contournable en changeant de canal.

**Stockage du token** : `localStorage['lecayenne.authToken']` (`api.js:154-157`), lisible par XSS —
compromis assumé et documenté (`api.js:6`), token 30 j. `dev_code` n'est **jamais** loggué ni persisté
(état React, double garde `window.LC.isDev`) et le backend ne l'émet qu'en `local`.

---

# REFUTED — hypothèses du brief testées et écartées

Chacune était une piste explicite du brief. Elles **ne sont pas** des vulnérabilités.

1. **Channel-confusion sur les comptes VIVANTS** — la garde 07-30/07-31 **tient**.
   `[ATT-3] code livre a l'attaquant (compte tel-seul AVEC points) ? non (garde OK)` ·
   `[ATT-11] verify sur compte client PLEIN vivant = 422`. Elle ne cède **que** par le soft-delete (P0-2).
2. **OTP rejouable** — **non**, one-time : suppression de la ligne au succès
   (`OtpManagerService.php:118-121`). `[ATT-4] verify #1 = 201 | verify #2 (REJEU) = 422`.
3. **Renommage « Guest User » du compte d'autrui** — **non** : `:258` n'est atteint qu'après
   `verify()` réussi = **possession du code prouvée**, et un vrai nom n'est jamais écrasé
   (`in_array(..., ['', 'Guest User'])`). `[ATT-14/2f]` OK. *La seule brèche est la **source** du nom, pas le droit de renommer → P2-1.*
4. **Cap anti-brute-force réinitialisable en redemandant un OTP** — **non**.
   `otp()` (lignes 29-73) ne contient **aucun** `Cache::` ; le compteur `otp_verify_fail:<phone>` survit.
   `[ATT-10] TOTAL essais faux = 20 | bloques(429) = 17` → « non (cap tient) ». Double verrou :
   cap applicatif 5 (`OtpManagerService.php:101`) + `throttle:3,5` (`routes/api.php:220`).
5. **Numéro jamais-OTP qui passe le verify** — **non**, `throw` (`OtpManagerService.php:132-135`) ;
   pas de comparaison `null == null` (`token` requis + colonne string). Verrouillé par
   `tests/Feature/Auth/GuestOtpVerifyHardeningTest.php`.
6. **Replay d'un code guest-OTP sur le reset de mot de passe (staff/admin)** — **non** : espaces
   disjoints. Le reset utilise la table `password_resets` + `Str::random(64)`
   (`ForgotPasswordController.php:116`), jamais `otps`.
7. **Fuite PII / IDOR sur `/loyalty/check`** — **non** : `auth:sanctum` (`routes/api.php:1569`) +
   garde de propriété `:89-98`. Le second critère `KioskMachine::where('user_id', $caller->id)->exists()`
   est **porteur** : un token invité web a la **même** ability `kiosk:order`, donc `tokenCan()` seul
   aurait été contournable. Réponses « existe / n'existe pas » **identiques** (404 `Non trouvé`).
8. **IDOR / mass-assignment sur `ProfileController`** — **non** : sujet = `auth()->user()->id`
   (`ProfileService.php:24`), jamais un id de la requête ; affectation par liste blanche de 4 champs →
   `is_guest`, `branch_id`, `role`, `loyalty_points`, `status`, `balance` **inatteignables**.
   *(L'absence de contrôle de l'ancien mot de passe sur le changement d'email reste un maillon de P0-1.)*
9. **Routes guest-signup non throttlées** — **non** : `otp` et `email-otp` en `throttle:otp-send`,
   `verify` en `throttle:3,5` (`routes/api.php:209-220`). *Le défaut est la **clé** du seau, pas son absence (P1-2).*

Également vérifié sain : fuite `dev_code` fermée hors `local` (`GuestSignupController.php:71`) ·
token invité scopé `['kiosk:order']` au mint (`:331`) · email d'un tiers jamais attaché (`:303-311`) ·
expiry OTP fail-**closed** (`OtpManagerService.php:114-116`).

---

# Reste à statuer (owner)

1. **Vérifier la PRODUCTION** : `SELECT id, phone, is_guest FROM users WHERE deleted_at IS NOT NULL;`
   Tout compte **non-invité** listé = **P0-1 armé** sur ce numéro. (Dev : 4, dont un POS Operator.)
2. **Rotation** : si P0-1 a pu être exploité, révoquer les `personal_access_tokens` d'abilities `['*']`
   non attendus et auditer `audit_logs` sur les `restore` de comptes.
3. **Décision produit** : `/loyalty/register` doit-il rester ouvert non authentifié (P1-1, P1-3) ?

**Aucun fichier applicatif n'a été modifié. Aucun correctif n'a été appliqué.**
