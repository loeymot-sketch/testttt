# Comptes locaux & parcours de test (Le Cayenne)

## Si tu as « Invalid credentials » avec `admin@lecayenne.fr`

Ta base a peut‑être encore **`admin@example.com`** (ancien seed). **Une commande corrige email + mot de passe + statut** :

```bash
php artisan foodking:ensure-admin
# ou voir sans appliquer :
php artisan foodking:ensure-admin --dry-run
```

Détails : **`docs/AUDIT_LOGIN_ACCOUNTS.md`**.

---

## Prérequis base de données

- **`php artisan migrate --seed`** (ou au minimum seeders déjà joués avec **`MenuSeeder`** pour le menu français / `config/menu.php`).
- **`site_default_branch`** = **1** après `SiteTableSeeder` → correspond à la première succursale **« Le Cayenne (principal) »** (`BranchTableSeeder`).
- Les **`landing_url`** des rôles sont posées par **`LeCayenneRoleLandingUrlSeeder`** (appelé automatiquement depuis **`DatabaseSeeder`**).

Si ta base existait **avant** cette évolution, exécute une fois :

```bash
php artisan db:seed --class=LeCayenneRoleLandingUrlSeeder
```

…et crée manuellement un utilisateur POS rattaché à la branche **1**, ou refais un seed propre si acceptable.

---

## Identifiants (mot de passe partout : `123456`)

| Compte | Email | Rôle | Branche effective | Après login |
|--------|--------|------|-------------------|-------------|
| **Admin** | `admin@lecayenne.fr` *(seed récent)* ou **`admin@example.com`** *(bases déjà seedées)* | Admin | `branch_id` = 0 → **branche par défaut 1** (réglage site) | **Dashboard** (puis menu latéral) |
| **Caissier POS** | `pos@lecayenne.fr` | POS Operator | **1** (Le Cayenne principal) | **Écran POS** directement |
| **Chef KDS** | `chef@lecayenne.fr` | Chef | **1** | **KDS** (`kitchen-display-system`) — *créé à chaque seed* |
| **Chef (démo)** | `chef@example.com` | Chef | **1** | **KDS** — *uniquement si `DEMO=true` au moment du seed* |
| **Ancien démo POS** | `posoperator@example.com` | POS Operator | **1** | **POS** — *uniquement si `DEMO=true` au moment du seed* |

**Client passage (walking)** : `walkingcustomer@example.com` — utile pour les commandes POS ; rôle Customer, pas d’accès back-office.

---

## Parcours recommandé : prise de commande POS + bon menu

1. Ouvre l’app : **`http://127.0.0.1:8000`** (ou ton `APP_URL`).
2. Connecte-toi avec **`pos@lecayenne.fr`** / **`123456`**.  
   - Tu dois arriver sur la route **`/pos`** (redirection via **`roles.landing_url`** = `pos` pour *POS Operator*).
3. **Branche** : l’utilisateur est sur **`branch_id = 1`** → articles et catégories visibles par le POS pour cette succursale (menu issu de **`MenuSeeder`** / `config/menu.php`).
4. Choisis une **catégorie**, un **produit**, passe le **wizard** (options, sauces, suppléments selon l’article), ajoute au **panier**, puis **paiement** (Cash / saisie carte simplifiée côté web).
5. Vérifie en parallèle (autre onglet / utilisateur) :
   - **KDS** : compte chef si présent en base, ou admin avec permission cuisine ;
   - **OSS** : écran statut commande.

### Alternative admin

- **`admin@lecayenne.fr`** / **`123456`** → tu atterris sur le **dashboard** ; ouvre le menu **POS** pour la même expérience caisse. La branche active pour un admin avec `branch_id = 0` suit **`site_default_branch`** (**1** = Le Cayenne principal).

---

## Mode `DEMO` dans `.env`

Si **`DEMO=true`** au moment du **`UserTableSeeder`**, des comptes supplémentaires sont créés (`chef@example.com`, `posoperator@example.com`, clients Bangladesh, etc.).  
Si **`DEMO=false`**, tu as quand même **admin**, **walking customer**, **`pos@lecayenne.fr`** (caissier) et **`chef@lecayenne.fr`** (KDS).

### Borne kiosk (pas de saisie publique)

- L’API exige un **token machine** ; le visiteur ne voit **pas** d’écran de connexion si **`KIOSK_REQUIRE_MACHINE_LOGIN` n’est pas activé** (défaut).
- Identifiants **injectés côté serveur** dans la page (`config/kiosk.php`) : par défaut **`kiosk-lecayenne` / `kiosk123`** (aligné sur `KioskMachineTableSeeder`), surcharge possible avec **`KIOSK_MACHINE_USERNAME`** / **`KIOSK_MACHINE_PASSWORD`**.
- Pour **forcer** l’écran login machine (audit uniquement) : **`KIOSK_REQUIRE_MACHINE_LOGIN=true`** dans `.env`.
- Si la borne affiche le login après une maintenance : vider **`sessionStorage`** (clé `kiosk_maintenance_mode`) ou recharger sans ce flag.
- Message **« Identifiants invalides ou compte bloqué »** : mauvais mot de passe en base, borne **inactive** dans Admin → Bornes, ou **utilisateur lié** inactif. Réinitialiser le couple machine / mot de passe :  
  `php artisan foodking:ensure-kiosk-machine`  
  (options `--username=`, `--password=`, `--dry-run` ; en prod le script demande confirmation). Puis aligner **`KIOSK_MACHINE_*`** dans `.env` et **`php artisan config:clear`**.

---

## Vérifications rapides SQL (optionnel)

```sql
SELECT id, name, landing_url FROM roles WHERE landing_url IS NOT NULL;
SELECT id, name, branch_id FROM branches ORDER BY id;
SELECT id, email, branch_id FROM users WHERE email IN ('admin@lecayenne.fr','pos@lecayenne.fr');
```

---

## Dépannage : login ou invité « ne marche pas »

1. **`storage/installed`** doit exister (installation terminée). Sinon l’API renvoie **503 JSON** (plus de redirection HTML qui cassait axios).
2. **Clé API & URL** : le fichier **`resources/views/master.blade.php`** injecte `window.foodkingConfig` depuis **`config('app.url')`** et **`config('app.api_key')`** — le login **ne dépend plus** d’un `npm run` avec les bons `MIX_*`. Vérifie quand même que **`MIX_API_KEY`** est défini dans `.env` (c’est la source de `config('app.api_key')`).
3. **`MIX_HOST` / rebuild** : encore utile pour le dev, mais plus bloquant si tu ouvres l’app via la même origine que `APP_URL`.
4. **Invité** : indicatif type **`+33`** était rejeté par la validation `numeric` sur le champ `code` — corrigé. Numéro national : **8 à 12 chiffres** (sans indicatif), selon réglage site.
5. **`site_guest_login`** doit être activé (seed `SiteTableSeeder` : activé par défaut).

## Fichiers utiles

- Utilisateurs : `database/seeders/UserTableSeeder.php`
- Branche / site : `database/seeders/BranchTableSeeder.php`, `database/seeders/SiteTableSeeder.php`
- Landing rôles : `database/seeders/LeCayenneRoleLandingUrlSeeder.php`
- Login API : `app/Http/Controllers/Auth/LoginController.php` (`landing_url`, `site_default_branch`)
