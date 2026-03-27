# Audit — échec de connexion (« Invalid credentials or you are blocked »)

**Date** : 2026-03-23  
**Symptôme** : formulaire login avec `admin@lecayenne.fr` + mot de passe → message rouge en anglais *Invalid credentials or you are blocked*.

---

## 1. Que signifie vraiment ce message ?

La chaîne vient de `trans('all.message.credentials_invalid')` (`lang/en/all.php`, `lang/fr/all.php` : version FR *Identifiants invalides ou compte bloqué*).

Dans le code, **il n’y a pas de notion séparée « bloqué »** sur cet écran : pour `LoginController::login`, ce texte est renvoyé **uniquement** quand :

```php
Auth::guard('web')->attempt($request->only('email', 'password', 'status'));
```

retourne **false** (`app/Http/Controllers/Auth/LoginController.php`).

Donc en pratique : **mauvais email, mauvais mot de passe, compte inactif (`status` ≠ actif), ou utilisateur soft-deleted** — pas un flag « blocked » dédié dans ce flux.

`status` attendu à la connexion : **`App\Enums\Status::ACTIVE` (= 5)** (fusionné dans la requête avant `attempt`).

---

## 2. Cause la plus probable sur ton installation

Le **UserTableSeeder** du projet a été mis à jour pour créer **`admin@lecayenne.fr`**, mais une base déjà seedée **avant** ce changement contient encore l’ancien compte **`admin@example.com`** (ou autre email).

Tu saisis **`admin@lecayenne.fr`** → **aucune ligne** ne correspond → `attempt` échoue → message d’erreur.

Vérification rapide (Tinker) :

```bash
php artisan tinker --execute="echo App\Models\User::withoutGlobalScopes()->where('email','admin@lecayenne.fr')->exists() ? 'EXISTS' : 'MISSING';"
```

---

## 3. Correctif recommandé (sans tout réinstaller)

Commande dédiée, **idempotente** :

```bash
php artisan foodking:ensure-admin
```

Options utiles :

```bash
php artisan foodking:ensure-admin --dry-run   # voir ce qui serait changé
php artisan foodking:ensure-admin --email=admin@lecayenne.fr --password=123456
```

Elle :

1. Cherche un utilisateur **Admin** (Spatie) ou le premier utilisateur ;
2. Met l’**email** sur `admin@lecayenne.fr` (sauf conflit) ;
3. Remet le **mot de passe** (défaut `123456` en dev) ;
4. Force **`status` actif** et **réactive** si soft-delete ;
5. Assigne le rôle **Admin** si besoin.

**Attention** : en production, change le mot de passe après le premier login.

---

## 4. Autres causes déjà traitées dans le code

| Sujet | Détail |
|--------|--------|
| **Clé API / URL** | `window.foodkingConfig` dans `master.blade.php` + `resources/js/config/env.js` — alignement Laravel ↔ navigateur sans dépendre d’un vieux `npm run`. La valeur côté Laravel est **`MIX_API_KEY`** (`config('app.api_key')`). Un `.env` qui ne définit que `API_KEY` laissait la clé vide → erreur **Invalid Api Key** ; un **repli sur `API_KEY`** est pris si `MIX_API_KEY` est absent. |
| **Borne : champ identifiant** | L’API `/api/auth/kiosk-login` attend le **`username` de la table `kiosk_machines`** (ex. `kiosk-lecayenne`), pas l’e-mail d’un employé. |
| **localhost vs 127.0.0.1** | Repli d’origine dans `env.js` pour éviter un mauvais `baseURL`. |
| **`storage/installed`** | Middleware `Installed` renvoie du JSON pour l’API si l’app n’est pas installée. |
| **Invité** | `VerifyPhoneRequest` / `ValidPhone` / OTP `DEMO` — correctifs précédents. |

---

## 5. Comptes « démo » (POS, chef, etc.)

Plusieurs comptes (`posoperator@example.com`, `chef@example.com`, …) ne sont créés que si **`DEMO=true`** au moment du **UserTableSeeder**. Sinon, utiliser **`foodking:ensure-admin`** puis **`pos@lecayenne.fr`** si ce user a été ajouté par le seeder récent, ou créer un utilisateur POS en admin.

---

## 6. Fichiers de référence

- `app/Http/Controllers/Auth/LoginController.php`
- `database/seeders/UserTableSeeder.php`
- `app/Console/Commands/EnsureAdminLoginCommand.php`
- `docs/LOCAL_TEST_ACCOUNTS.md`
