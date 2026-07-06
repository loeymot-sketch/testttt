# FoodKing — Deep-dive technique & sécurité (analyse + PoC + patchs proposés)

> Complément au rapport forensique du 2026-07-06.
> **Mode audit** : chaque vulnérabilité ci-dessous a été **relue à la source** (extraits de code réels avec numéros de ligne), accompagnée d'un **PoC concret** et d'un **patch proposé sous forme de diff**. ⚠️ **Aucun code n'a été modifié** — les diffs sont des propositions à valider (human-in-the-loop, doctrine `block + human`).

---

## 1. Modèle de menace — frontières de confiance

Le produit expose **7 frontières de confiance**. Six sont franchissables. Le motif commun : la garde au franchissement est *cosmétique* (clé statique, redirect non bloquant, `branch_id==0`) au lieu d'être *structurelle*.

| Frontière | Acteur extérieur (non fiable) | Ressource protégée | Garde actuelle | État |
|---|---|---|---|---|
| **Surface QR / table** | Quiconque possède `x-api-key` (embarquée dans la SPA) | Pricing, identité de commande, PII | `apiKey` statique seul | 🔴 rompue |
| **Compte client** | Client inscrit (`branch_id=0`) | Ses commandes / celles des autres | `auth:sanctum` + ownership partiel | 🔴 partielle (IDOR + scope) |
| **Machine borne** | Borne physique / identifiants | Création de commande | `apiKey` + mot de passe machine | 🔴 token = admin |
| **API admin** | Détenteur d'un token Sanctum | Back-office complet | `auth:sanctum` (perm. par contrôleur ad hoc) | 🔴 pas de garde de groupe |
| **Callback PSP** | Fournisseur de paiement / navigateur | Statut de paiement | *aucune signature* | 🔴 rompue |
| **Sceau fiscal** | Opérateur post-clôture Z | Données Z signées | `destroy()` → 409 seulement | 🔴 mutable |
| **Déploiement / Installer** | Anonyme | `.env`, base de données | Redirect non bloquant | 🔴 rompue |

```
  INTERNET / SALLE
        │
        ▼   x-api-key statique (dans la SPA)  ──────────────┐
  ┌───────────────┐                                          │  franchissement
  │  QR / TABLE   │  discount, delivery, branch_id, customer │  non contrôlé
  │ (non auth..)  │  = acceptés BRUTS                        │
  └───────┬───────┘                                          ▼
          │                                        ┌──────────────────┐
  ┌───────▼───────┐   token 'kiosk:order'          │  PRICING / ORDERS │
  │  BORNE        │   émis sur user id=1 (admin)    │   (SSOT métier)   │
  └───────┬───────┘   + Gate::before(admin→true)    └─────────┬────────┘
          │                                                    │
  ┌───────▼───────┐   auth:sanctum SEUL (pas de rôle)          ▼
  │  API ADMIN    │◄──────────────────────────────────  branch_id==0 = « toutes branches »
  └───────────────┘                                     (défaut de TOUT compte client)
```

---

## 2. Frontière « QR / table » — le client dicte prix et identité (non authentifié)

### 2.1 La route est publique et la validation ne protège rien
`routes/api.php:1004-1007` — le groupe table n'a que `apiKey` (commentaire officiel : *« table ordering is unauthenticated (QR code) »*) :
```php
Route::prefix('dining-order')->name('dining-order.')->group(function () {
    Route::get('/show/{frontendOrder}', [TableOrderController::class, 'show']);
    Route::post('/', [TableOrderController::class, 'store'])->middleware('throttle:20,1');
});
```
`app/Http/Requests/TableOrderRequest.php:19-47` — `authorize()` renvoie `true`, et les champs sensibles sont acceptés bruts :
```php
public function authorize(): bool { return true; }
public function rules(): array {
    return [
        'dining_table_id' => ['required', 'numeric'],   // ← identité forgeable
        'customer_id'     => ['required', 'numeric'],   // ← usurpation client
        'branch_id'       => ['required', 'numeric'],   // ← injection cross-branch
        'discount'        => ['nullable', 'numeric'],   // ← aucun plafond, aucun rôle
        'delivery_charge' => ['nullable'],              // ← même pas 'numeric'
        'total'           => ['required', 'numeric'],
        ...
```

### 2.2 Le pricing honore la remise et les frais du client
`app/Services/Pricing/PricingService.php:213-222` — le contexte `'table'` autorise une remise manuelle **client** ; la livraison est prise telle quelle :
```php
} elseif ($req->manualDiscountRequest > 0.0 && in_array($req->context, ['pos', 'table'], true)) {
    $calculatedDiscount = $this->discountCalculator->manualDiscount(
        $req->manualDiscountRequest, (float) $subtotalForDiscount
    );
}
$delivery = $req->deliveryCharge;                                    // ← client
$rawTotal = $realSubtotal + $totalTax + $delivery - $calculatedDiscount;
$finalTotal = ... max(0.0, $rawTotal) ...;                           // ← clampé à 0
```
`app/Services/Pricing/DiscountCalculator.php:22-29` — la remise manuelle est plafonnée **au sous-total** (donc jusqu'à 100 %) :
```php
public function manualDiscount(float $requested, float $subtotal): float {
    if ($requested <= 0) return 0.0;
    return $requested <= $subtotal ? $requested : 0.0;   // 100 % de remise autorisé
}
```
> Le sous-total, lui, est bien recalculé serveur (pas de fraude sur le prix des articles) — **mais** `discount` et `delivery_charge` sont pilotés par le client. Résultat net : `total = tax + delivery(négatif)` → **~0**.

### 2.3 PoC — repas gratuit depuis une table (aucun compte)
```bash
# 1) La clé est publique (embarquée dans la SPA QR)
API=$(curl -s https://foodking.tld/kiosk | grep -oE 'x-api-key["'\'' :]+[A-Za-z0-9]+' | head -1)

# 2) Inventaire des articles (mêmes middlewares publics)
curl -s -H "x-api-key: $API" https://foodking.tld/api/table/item-category

# 3) Commande forgée : remise = sous-total, livraison négative, identité arbitraire
curl -s -X POST https://foodking.tld/api/table/dining-order \
  -H "x-api-key: $API" -H 'Content-Type: application/json' -d '{
    "dining_table_id": 7, "customer_id": 99, "branch_id": 2,
    "subtotal": 40, "discount": 40, "delivery_charge": -5, "total": 0,
    "order_type": 3, "is_advance_order": 0, "source": 1,
    "items": "[{\"item_id\":12,\"quantity\":2}]" }'
# → commande PENDING, total ≈ 0, poussée au KDS de la branche 2. Répétable 20/min/IP.
```

### 2.4 Patch proposé
```diff
# app/Http/Requests/TableOrderRequest.php
     public function rules(): array {
         return [
-            'dining_table_id' => ['required', 'numeric'],
-            'customer_id'     => ['required', 'numeric'],
-            'branch_id'       => ['required', 'numeric'],
-            'subtotal'        => ['required', 'numeric'],
-            'discount'        => ['nullable', 'numeric'],
-            'delivery_charge' => ['nullable'],
-            'total'           => ['required', 'numeric'],
+            // Le serveur résout branch_id/customer depuis la table (jeton QR signé),
+            // et recalcule subtotal/discount/delivery/total. Ne jamais les lire du payload.
+            'dining_table_id' => ['required', 'integer', 'exists:dining_tables,id'],
             'order_type'      => ['required', 'numeric'],
             'is_advance_order'=> ['required', 'numeric'],
             ...
```
```diff
# app/Services/Pricing/PricingService.php
-        } elseif ($req->manualDiscountRequest > 0.0 && in_array($req->context, ['pos', 'table'], true)) {
+        // Remise manuelle = privilège STAFF authentifié (POS) uniquement, avec motif.
+        // La surface 'table' (QR, non authentifiée) ne doit jamais honorer une remise client.
+        } elseif ($req->manualDiscountRequest > 0.0 && $req->context === 'pos') {
             $calculatedDiscount = $this->discountCalculator->manualDiscount(...);
         }
-        $delivery = $req->deliveryCharge;
+        // La livraison est recalculée serveur en amont ; jamais négative.
+        $delivery = max(0.0, (float) $req->deliveryCharge);
```
Et côté service, résoudre `branch_id`/`customer_id` depuis `dining_table_id`, et forcer `manualDiscountRequest = 0` pour le contexte table.

### 2.5 IDOR — lecture de n'importe quelle commande
`routes/api.php:1005` : `GET /show/{frontendOrder}` binde par **id entier** sur une route non authentifiée → énumération. **PoC** : `for i in $(seq 1 500); do curl -s -H "x-api-key: $API" .../api/table/dining-order/show/$i; done` exfiltre nom, téléphone, adresse, montants de toutes les branches.
```diff
# routes/api.php  — binder par jeton non devinable (colonne 'token' déjà renvoyée à la création)
-        Route::get('/show/{frontendOrder}', [TableOrderController::class, 'show']);
+        Route::get('/show/{frontendOrder:token}', [TableOrderController::class, 'show']);
```
(+ réduire le Resource retourné au strict nécessaire pour l'affichage client, et scoper par table de session.)

---

## 3. Frontière « paiement » — perte d'argent déterministe & paiement forgé

### 3.1 Troncature Stripe (précédence de cast PHP)
`app/Http/PaymentGateways/Gateways/Stripe.php:46-51` :
```php
$response = $this->gateway->charges->create([
    'amount'   => (int) $order->total * 100,   // ← ((int)$order->total) * 100
    'currency' => $currencyCode,
    'source'   => $request->stripeToken,
    ...
]);
```
`(int)` a une précédence **supérieure** à `*`. Pour `$order->total = 12.99` → `(int)12.99 = 12` → `1200` centimes = **12,00 $**. La partie fractionnaire de **chaque** commande carte est perdue, et la `Transaction` enregistre pourtant `12.99` → **grand livre incohérent**.
```diff
# app/Http/PaymentGateways/Gateways/Stripe.php
-                'amount'      => (int) $order->total * 100,
+                // Précédence corrigée + arrondi sûr. NB: gérer les devises zéro-décimale
+                // (JPY, KRW…) qui n'utilisent pas de sous-unité ×100.
+                'amount'      => (int) round(((float) $order->total) * 100),
```

### 3.2 « Paiement confirmé » sans PSP
`app/Http/Controllers/Frontend/OrderController.php:94-118` — après un contrôle de **propriété** correct, le statut passe `PAID` sur un `transaction_id` **fourni par le client**, sans jamais interroger la passerelle :
```php
if ((int) $frontendOrder->user_id !== $authenticatedUserId) {
    return response([...], 403);                     // ownership OK
}
DB::transaction(function () ... {
    $locked = FrontendOrder::where('id', $frontendOrder->id)->lockForUpdate()->first();
    if ((int) $locked->payment_status === PaymentStatus::PAID) { $alreadyPaid = true; return; }
    $locked->payment_status = PaymentStatus::PAID;   // ← aucune vérif passerelle
    $locked->transaction_id = $request->transaction_id;   // ← valeur client
    $locked->save();
});
$promoted = $this->frontendOrderService->finalizePaidKioskOrder($frontendOrder->fresh());
```
**PoC** : un client crée sa commande, puis `POST /api/frontend/order/{id}/payment-confirm` avec `{"transaction_id":"FAKE-123","payment_method":2}` → commande **PAID**, cuisine notifiée, encaissement fictif comptabilisé au fiscal. Aucun paiement réel.
```diff
# app/Http/Controllers/Frontend/OrderController.php
-                $locked->payment_status = PaymentStatus::PAID;
-                $locked->payment_method = $request->payment_method ?? $locked->payment_method;
-                $locked->transaction_id = $request->transaction_id;
-                $locked->card_type = $request->card_type;
-                $locked->save();
+                // Ne jamais dériver PAID d'un transaction_id client : vérifier la charge
+                // auprès du PSP pour CETTE commande ET ce montant avant de sceller PAID.
+                $verified = $this->paymentVerifier->verify(
+                    $locked, (int) $request->payment_method, (string) $request->transaction_id
+                );
+                if (! $verified->succeeded
+                    || $verified->amountMinor !== (int) round(((float) $locked->total) * 100)) {
+                    throw new \App\Exceptions\PaymentVerificationException();
+                }
+                $locked->payment_status = PaymentStatus::PAID;
+                $locked->payment_method = $request->payment_method ?? $locked->payment_method;
+                $locked->transaction_id = $verified->transactionId;   // valeur PSP, pas client
+                $locked->card_type      = $request->card_type;
+                $locked->save();
```

### 3.3 Retours PSP sans signature
`routes/web.php:40-42` : `payment/{gateway}/{order}/success|fail|cancel` en `match(['get','post'])`, sous le seul middleware `installed`, **sans vérification de signature** → un attaquant peut appeler directement l'URL de succès. À coupler avec une vérification serveur→PSP (webhook signé) plutôt qu'une confiance au retour navigateur.

---

## 4. Frontière « autorisation » — la borne devient super-admin

### 4.1 Chaîne d'escalade (structurelle, 4 maillons)
1. `database/seeders/KioskMachineTableSeeder.php:33` — la borne est liée à **`user_id => 1`** (`branch_id => 1`). *(Le seeder est bloqué en prod, l.20 ; mais `EnsureKioskMachineCommand` réapplique le câblage, et l'escalade tient pour toute borne liée à un utilisateur ayant le rôle admin.)*
2. `app/Http/Controllers/Auth/KioskMachineLoginController.php:83-87` — le token est créé **sur cet utilisateur** :
   ```php
   $this->token = $user->createToken('kiosk-token', ['kiosk:order'], ...)->plainTextToken;
   ```
3. `app/Providers/AuthServiceProvider.php:30-32` — tout utilisateur au rôle `admin` court-circuite **toutes** les gates :
   ```php
   Gate::before(fn ($user, $ability) => $user->hasRole('admin') ? true : null);
   ```
4. `routes/api.php:229` — le groupe admin n'a **aucune** garde de rôle/ability/permission au niveau du groupe :
   ```php
   Route::prefix('admin')->middleware(['installed','apiKey','auth:sanctum','localization','throttle:admin-mutation'])->group(...);
   ```
   → L'ability `kiosk:order` n'est imposée nulle part (aucun alias `abilities` dans `Kernel.php`), et `Gate::before` valide l'utilisateur admin sous-jacent. **Le token le moins privilégié obtient les pleins pouvoirs.**

**PoC** :
```bash
TOKEN=$(curl -s -X POST https://foodking.tld/api/auth/kiosk-login \
  -H "x-api-key: $API" -d 'username=kiosk-lecayenne&password=kiosk123' | jq -r .token)
# Réécriture d'un prix (corruption du SSOT) — accepté :
curl -s -X PUT https://foodking.tld/api/admin/setting/item/12 \
  -H "Authorization: Bearer $TOKEN" -H "x-api-key: $API" -d 'price=0.01'
```
### 4.2 Patch proposé (défense en profondeur, 2 volets)
```diff
# app/Http/Kernel.php — enregistrer les middlewares d'ability Sanctum
 protected $middlewareAliases = [
     ...
+    'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
+    'ability'   => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
 ];
```
```diff
# routes/api.php — refuser les tokens borne sur l'API admin + exiger un rôle
-Route::prefix('admin')->middleware(['installed','apiKey','auth:sanctum','localization','throttle:admin-mutation'])->group(function () {
+Route::prefix('admin')->middleware(['installed','apiKey','auth:sanctum','role:admin|staff','localization','throttle:admin-mutation'])->group(function () {
```
```diff
# database/seeders/KioskMachineTableSeeder.php — utilisateur de service dédié, jamais l'admin
-                'user_id'    => 1,
+                'user_id'    => \App\Models\User::role('kiosk-machine')->value('id'), // rôle sans droit back-office
```
+ appliquer `->middleware('abilities:kiosk:order')` aux routes borne. La correction de fond : un rôle `kiosk-machine` sans aucune permission spatie.

---

## 5. Frontière « isolation de branche » — `branch_id == 0` = mode dieu

### 5.1 La cause exacte, prouvée
- `database/migrations/2014_10_12_000000_create_users_table.php:28` — **tout utilisateur** naît `branch_id = 0` :
  ```php
  $table->unsignedBigInteger('branch_id')->nullable()->default(0);
  ```
- `app/Traits/DefaultAccessModelTrait.php:14-30` — `branch()` renvoie **0** pour un client sans `DefaultAccess` :
  ```php
  if ($access) { return $access->default_id; }
  elseif ((int) Auth::user()->branch_id === 0) { return 0; }   // ← client = 0
  else { return Auth::user()->branch_id; }
  ```
- `app/Models/Scopes/BranchScope.php:33-39` — `branch()==0` **désactive le filtre** :
  ```php
  if ($userBranch === 0) { return; }              // ← aucun filtre → toutes branches
  $builder->where($field, '=', $userBranch);
  ```
> Conséquence : **un compte client authentifié** (donc `branch_id=0`) lit `Order`, `FrontendOrder`, `DiningTable`, `PushNotification`… de **toutes** les branches. L'invariant #2 est neutralisé par le défaut d'usine.

### 5.2 Patch proposé
```diff
# app/Models/Scopes/BranchScope.php
-            // [FIX-54-8] Only admins (branch_id = 0) can see cross-branch records.
-            if ($userBranch === 0) {
-                // Admin: no filter applied
-                return;
-            }
-            $builder->where($field, '=', $userBranch);
+            // La visibilité cross-branche est un privilège de RÔLE admin, jamais une
+            // conséquence de branch_id==0 (défaut de tout compte client).
+            if (Auth::user()->hasRole('admin')) {
+                return; // admin authentifié : pas de filtre
+            }
+            // Deny-by-default : sans branche réelle, on ne voit RIEN de cross-branche.
+            if ($userBranch === null || (int) $userBranch === 0) {
+                $builder->whereRaw('1 = 0');
+                return;
+            }
+            $builder->where($field, '=', $userBranch);
```
Correction de fond recommandée : donner aux clients une **vraie** branche (ou une colonne `is_customer` distincte) et ne plus surcharger `branch_id=0`.

### 5.3 Fuite temps réel corrélée (broadcast)
`routes/channels.php:25-39` — deux maillons cassés :
```php
Broadcast::channel('branch.{branchId}', function ($user, $branchId) {
    if ($user->currentAccessToken() && $user->tokenCan('kiosk:order')) {
        $machine = \App\Models\KioskMachine::where('user_id', $user->id)->first();  // ← ->first() arbitraire
        return $machine && (int) $machine->branch_id === (int) $branchId;
    }
    if ((int) $user->branch_id === 0) { return true; }   // ← tout client → toute branche
    return (int) $user->branch_id === (int) $branchId;
});
```
```diff
 Broadcast::channel('branch.{branchId}', function ($user, $branchId) {
+    // Admin par RÔLE (pas branch_id==0).
+    if ($user->hasRole('admin')) { return true; }
-    if ($user->currentAccessToken() && $user->tokenCan('kiosk:order')) {
-        $machine = \App\Models\KioskMachine::where('user_id', $user->id)->first();
-        return $machine && (int) $machine->branch_id === (int) $branchId;
-    }
-    if ((int) $user->branch_id === 0) { return true; }
-    return (int) $user->branch_id === (int) $branchId;
+    // Token borne : lier à la machine SPÉCIFIQUE encodée dans le token, pas ->first().
+    if ($user->currentAccessToken() && $user->tokenCan('kiosk:order')) {
+        $mid = $user->currentAccessToken()->kiosk_machine_id ?? null;
+        $machine = $mid ? \App\Models\KioskMachine::find($mid) : null;
+        return $machine && (int) $machine->branch_id === (int) $branchId;
+    }
+    // Staff : sa branche uniquement. Client (0/null) : rien.
+    return (int) $user->branch_id !== 0 && (int) $user->branch_id === (int) $branchId;
 });
```
(Nécessite d'encoder `kiosk_machine_id` sur le token borne à la création — voir §4.)

---

## 6. Frontière « déploiement / Installer »

### 6.1 Clé GCP servie en HTTP
`public/.htaccess:17-20` ne réécrit vers `index.php` **que si le fichier n'existe pas** :
```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]
```
Or `public/file/service-account-file.json` **existe** → Apache le sert tel quel. `Options -Indexes` (l.3) n'empêche que le listing, pas l'accès direct. **PoC** : `curl https://foodking.tld/file/service-account-file.json` → clé privée `foodking-inilabs` complète.
```diff
# public/.htaccess — refus défensif des fichiers sensibles servis statiquement
 <IfModule mod_headers.c>
     ...
 </IfModule>
+<FilesMatch "\.(json|env|pem|key|p12)$">
+    Require all denied
+</FilesMatch>
```
```diff
# .gitignore
+ /public/file/service-account-file.json
+ /public/file/*.json
```
**Actions hors diff** : révoquer/roter la clé côté GCP (elle est brûlée), la déplacer sous `storage/`, mettre à jour `FirebaseService`, purger l'historique Git.

### 6.2 Installer non authentifié → reprise de la base
`routes/web.php:21` — le groupe `/install` n'a **que** `web` (pas de middleware `installed`, contrairement aux autres routes l.36-46) :
```php
Route::prefix('install')->name('installer.')->middleware(['web'])->group(function () {
    Route::post('/database', [InstallerController::class, 'databaseStore']);   // ...
```
`app/Http/Controllers/Installer/InstallerController.php:28-30` — la seule garde ne **stoppe pas** l'exécution :
```php
if (file_exists(storage_path('installed'))) {
    Redirect::to(env('APP_URL'))->send();   // ← flush la réponse, mais PHP continue
}
```
`app/Services/InstallerService.php:26-48` — `databaseSetup` réécrit `.env` et **détruit la base** :
```php
$envService->addData([ 'DB_HOST' => $request->database_host, ... ]);
Artisan::call('config:cache');
Artisan::call('migrate:fresh', ['--force' => true]);   // DROP de toutes les tables
Artisan::call('db:seed',      ['--force' => true]);    // reseed admin par défaut
```
**PoC** : `POST /install/database` avec les creds du MySQL de l'attaquant → prod repointée, données détruites, admin connu recréé → prise de contrôle totale.
```diff
# app/Http/Controllers/Installer/InstallerController.php
-        if (file_exists(storage_path('installed'))) {
-            Redirect::to(env('APP_URL'))->send();
-        }
+        if (file_exists(storage_path('installed'))) {
+            abort(404); // arrêt DUR — ->send() n'interrompt pas l'exécution en PHP-FPM
+        }
```
```diff
# routes/web.php — garde de groupe explicite (défense en profondeur)
-Route::prefix('install')->name('installer.')->middleware(['web'])->group(function () {
+Route::prefix('install')->name('installer.')->middleware(['web', 'installer.notInstalled'])->group(function () {
```
+ nouveau middleware `installer.notInstalled` : `abort(404)` si `storage/installed` existe. **Idéal** : retirer l'Installer des artefacts de production.

### 6.3 Migration destructrice
`database/migrations/2026_03_11_999999_emergency_purge_english_menu.php:74` — `DB::table($table)->truncate()` en boucle dans le chemin `migrate` standard. Tout `artisan migrate` sur une base où cette migration n'est pas encore enregistrée **vide le menu de toutes les branches**. → sortir ce fichier de `migrations/` (script one-shot idempotent hors `migrate`).

---

## 7. Ordre d'application recommandé (patchs)

Tous les diffs ci-dessus sont **proposés, non appliqués**. Ordre suggéré (chaque étape est indépendante et testable) :

| Ordre | Patch | Fichiers | Risque de régression |
|---|---|---|---|
| 1 | Clé GCP (rotation + .htaccess + .gitignore) | `.htaccess`, `.gitignore`, GCP | Nul (défensif) |
| 2 | Troncature Stripe | `Stripe.php` | Faible (ajouter test 12,99→1299) |
| 3 | Installer (abort 404 + middleware) | `InstallerController.php`, `web.php` | Faible |
| 4 | `branch_id≠admin` (scope + broadcast) | `BranchScope.php`, `channels.php` | **Moyen** — tester l'accès admin légitime |
| 5 | Preuve PSP avant PAID | `Frontend/OrderController.php` | Moyen — introduire `PaymentVerifier` |
| 6 | Table QR (identité + discount + delivery) | `TableOrderRequest.php`, `PricingService.php` | Moyen — tester le flux table |
| 7 | Token borne dédié + abilities | `Kernel.php`, `routes/api.php`, seeder | **Moyen/élevé** — tester login borne |

> ⚠️ Le patch 4 (BranchScope) est le plus sensible : il change une garde transverse. À déployer avec un test qui prouve (a) qu'un admin voit toujours toutes les branches et (b) qu'un client/staff ne voit que la sienne. C'est exactement le type de test d'invariant aujourd'hui **vacant** (cf. rapport 04, `PricingIntegrityTest` / tests tolérants).

---

*Chaque extrait de code est copié de la source à la date de l'audit. Les diffs sont des propositions à revoir avant application ; certains (patch 5, 7) impliquent d'introduire des collaborateurs (`PaymentVerifier`, rôle `kiosk-machine`, `kiosk_machine_id` sur le token) décrits en commentaire. Aucune modification n'a été committée hors de ce dossier de rapport.*
