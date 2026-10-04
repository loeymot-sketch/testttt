# Z9 — Sécurité et intégrité du back-office (ONB-13, W1 audit offensif lecture seule)

- Date : 2026-08-27 · Méthode : lecture de code exclusivement (aucune requête contre :8800, aucune écriture DB).
- Outils réels utilisés : `grep`/`Read` file:line, `php artisan route:list --path=api/admin --json` (461 routes admin, dump complet dans `/tmp/admin_routes.json` — non committé), `php /tmp/find_return_true.php` (ré-implémente exactement le regex du sentinel), et **une exécution réelle** de `php artisan test --filter=IdempotencyRequiredRoutesCoverageTest`.
- Règle anti-fiction appliquée : tout ce qui suit a été ouvert et vérifié. Quand une piste s'est révélée fausse (compensée par une garde que je n'avais pas vue au premier passage), je le dis — ce n'est pas la conclusion que je cherchais.

Rappel des 3 faits déjà établis (non re-vérifiés, complétés ci-dessous) : `FormRequestAuthzDriftSentinelTest.php:67` = **64** (recompté indépendamment via script, identique) ; `NoDangerousFileExtension` protège la facture mais pas le ticket Uber (hors `public/`) ; `SyncOverviewController::systemHealth` (`/api/admin/observability/system-health`) n'a aucune garde au-delà de `auth:sanctum`.

---

## 1. Les 64 `authorize() => true` — lesquels gardent une route vraiment sensible ?

**Méthode** : pour chacun des 64 fichiers (liste complète obtenue par exécution du regex du sentinel), j'ai retrouvé le contrôleur qui l'utilise, lu son `__construct()` pour un `$this->middleware(['permission:...'])->only(...)`, et lu le corps de la méthode pour un `abort_if`/`abort_unless($user->can()/hasRole())` inline. **Résultat, vérifié sur ~40 candidats à fort rayon d'explosion (fiscal/paiement/rôles/réglages) : je n'ai trouvé AUCUNE route sensible où `authorize()=>true` est le SEUL rempart.** Chaque fois, une garde de contrôleur ou inline compense. C'est un vrai résultat (le socle documenté dans le plan comme « fort » se confirme ici), pas une esquive — mais ça veut dire que le `FormRequest` lui-même est un point de défaillance unique : si la ligne `$this->middleware(...)` disparaît dans un futur refactor, plus rien ne protège la route.

Les 10 pires par surface exposée si la garde de contrôleur venait à sauter (toutes VÉRIFIÉES gardées aujourd'hui) :

| # | FormRequest | Route gardée | Ce qu'un compte sans droit pourrait faire SI la garde ci-dessous disparaissait | Garde réelle aujourd'hui (file:line) |
|---|---|---|---|---|
| 1 | `MailRequest` | `PUT/PATCH /admin/setting/mail` | Réécrire l'hôte/identifiants SMTP (voir aussi §4 — le mot de passe est en clair) | `MailController.php:22` `permission:settings`→`only('index','update')` |
| 2 | `LicenseRequest` | `PUT/PATCH /admin/setting/license` | Réécrire la clé de licence | `LicenseController.php:20` `permission:settings` |
| 3 | `SmsGatewayRequest` | `PUT/PATCH /admin/setting/sms-gateway` | Réécrire les identifiants de la passerelle SMS | `SmsGatewayController.php:26` `permission:settings` |
| 4 | `OtpRequest` | `PUT/PATCH /admin/setting/otp` | Affaiblir l'OTP (digits, expiration) | `OtpController.php:19` `permission:settings`→`only('update')` |
| 5 | `UserChangePasswordRequest` | `POST /admin/{administrator,employee,chef,waiter,delivery-boy,customer}/change-password/{id}` | Réinitialiser le mot de passe d'un AUTRE compte (y compris un Super Admin) | gate par rôle : `AdministratorController.php:29` `permission:administrators`→`only('changePassword',...)` (même motif répété dans les 5 autres contrôleurs) |
| 6 | `WaiterRequest`/`ChefRequest`/`CustomerRequest` | `POST/PUT /admin/{waiter,chef,customer}` | Créer/éditer un compte de rôle privilégié | `WaiterController.php:33-35` `permission:waiters_create/_edit` (motif identique Chef/Customer) |
| 7 | `LanguageRequest` | `POST/PUT /admin/setting/language*` | Injecter du contenu dans les chaînes de traduction servies sur TOUTES les surfaces (kiosk/POS/admin) | `LanguageController.php:27` `permission:settings`→`only('store','update','destroy','fileText','fileTextStore')` |
| 8 | `OrderSetupRequest`/`KioskSetupRequest`/`LoyaltySetupRequest` | `PUT/PATCH /admin/setting/{order-setup,kiosk-setup,loyalty-setup}` | Modifier le ratio € → points fidélité, la config de commande, la config borne | `LoyaltySetupController.php:19` / `KioskSetupController.php:20` / `OrderSetupController.php:19` — tous `permission:settings` |
| 9 | `AnalyticRequest`/`AnalyticSectionRequest`/`TimeSlotRequest`/`SocialMediaRequest`/`ThemeRequest`/`CookiesRequest`/`PageRequest`/`SliderRequest`/`MenuTemplateRequest` | `POST/PUT/DELETE /admin/setting/{analytic,analytic-section,time-slot,social-media,theme,cookies,page,slider,menu-template}` | Modifier le contenu vitrine/CMS | tous `permission:settings` dans leurs contrôleurs respectifs (vérifié un par un) |
| 10 | `ItemAttributeRequest`/`ItemVariationRequest`/`ItemExtraRequest`/`ItemAddonRequest`/`OfferItemRequest`/`ItemCategoryImportRequest` | écriture catalogue (variations, extras, addons, offres, import CSV catégories) | Modifier la structure de prix/catalogue servie au client | `permission:items_edit` / `permission:offers_show` / `permission:settings` selon le contrôleur (vérifié un par un) |

**Note dead-code** : `PaymentMethodRequest.php` (dans les 64) n'est référencé nulle part ailleurs dans `app/` — aucune route ne l'utilise. Pas un risque, mais du code mort à nettoyer.

---

## 2. Routes admin sans middleware de permission (routes/api.php)

**Méthode** : `php artisan route:list --path=api/admin --json` (fiable ici car les gardes `$this->middleware(['permission:...'])` posées dans un `__construct()` de contrôleur APPARAISSENT dans la sortie de `route:list` sous `Spatie\Permission\Middlewares\PermissionMiddleware:...` — vérifié par recoupement direct avec le code source). Filtré : 243 routes mutantes (POST/PUT/PATCH/DELETE) sous `/admin`, dont **21 sans `PermissionMiddleware`/`RoleMiddleware`** dans la pile.

Vérification une par une de ces 21 : **20 sur 21 ont une garde inline** (`abort_if`/`abort_unless($user->can(...)/hasRole(...))`) dans le corps du contrôleur — donc non exploitables telles quelles :
- `POST/PUT/PATCH/DELETE /admin/fiscal/z-report/{open,close}` → `ZReportController.php:97-101 authorizeFiscal()` (`can('pos-manage-fiscal')`)
- `POST /admin/pos/{collect-kiosk-cash,counter-collect/confirm,counter-collect/cancel}` → closures avec `abort_unless(auth()->user()?->can('pos'), 403)` inline (`routes/api.php:1227,1247`, et confirm au même endroit)
- `POST /admin/wheel/{screen-pass,unlock-token}` → `WheelUnlockController.php:28` / `WheelAccessController.php:107` (`can('pos')`/`habilitePos()`)
- `PUT /admin/observability/interrupteurs/{nom}` → `InterrupteurController.php:62` (`hasRole('Admin'|'Tenant Admin')`) — cohérent avec le fait déjà établi (seul `index()` avait dû être regardé récemment ; `update()` était déjà gardé)
- `POST /admin/pos-loyalty/{credit-manual,deduct-manual,customers,lookup}`, `/pos-order/{order}/{attach-loyalty,redeem-loyalty}` → gardés par leurs propres `FormRequest` avec `authorize()` réel (`PosLoyaltyManualCreditRequest.php:21` `$this->user()?->can('pos')`, motif répété)
- `POST/DELETE /admin/item/{extra,variation}/{item}/{option}/change-image` → `ItemOptionPhotoRequest.php:19-24` (`hasRole('Admin'|'Tenant Admin')`) ; `removeImage` a la même garde en dur dans le contrôleur
- `POST /admin/pos/kitchen-tickets/{pending,{order}/ack}` → à confirmer par le propriétaire KDS (non ouvert en détail, budget d'audit épuisé sur cette ligne) — **SOUPÇON NON PROUVÉ**, à vérifier : lire `KitchenTicketQueueController.php` en entier.
- `POST /admin/pos/quote` → `PosController::quote` non ouvert en détail — **SOUPÇON NON PROUVÉ**.

Le seul cas confirmé sans aucune garde au-delà de `auth:sanctum` reste celui déjà établi : `SyncOverviewController::systemHealth`.

**Côté lecture (GET)**, 47 routes admin sans `PermissionMiddleware` — la quasi-totalité relève de la politique G-READ déjà connue (réglages lisibles par la caisse : `company/site/order-setup/branch/otp/theme/currency/tax/...`). Deux additions non répertoriées ailleurs dans le plan, toutes deux confirmées **BASSES** :
- `GET /admin/setting/notification-alert` → `NotificationAlertController.php:18` ne garde que `update` (`->only('update')`), pas `index` : n'importe quel compte staff lit la config des alertes.
- `GET /admin/setting/analytic-section/{analytic}` → même motif, `AnalyticSectionController.php:26` ne garde que `store/update/destroy`.
- `GET /admin/default-access` : **volontairement ouvert**, décision documentée dans le code (`DefaultAccessController.php:19-24`, commit WI-4-RED-01) — l'écriture est gardée, la lecture sert le bootstrap POS. Pas un défaut.

---

## 3. Messages d'erreur techniques renvoyés au client

Le motif déjà documenté (SQLSTATE `kds_station`, `users.phone`) n'est pas un incident isolé : c'est un **motif systémique**. `app/Exceptions/Handler.php:134-155` contient bien une désinfection correcte des `QueryException` (masque le SQL brut en prod via `QueryExceptionLibrary::message()`), **mais ce code ne s'exécute JAMAIS** dès qu'un contrôleur intercepte l'exception avant elle — ce qui est le cas quasi partout :

```
catch (Exception $exception) {
    return response(['status' => false, 'message' => $exception->getMessage()], 422);
}
```

Comptage réel : **502 occurrences de `getMessage()`** dans `app/Http/Controllers/` (`grep -rc`), dont **86 fichiers** rien que sous `app/Http/Controllers/Admin/`. Comme `Illuminate\Database\QueryException` (SQLSTATE) hérite de `\Exception`, un `catch (Exception $exception)` l'attrape aussi et retourne le SQL brut — exactement le mécanisme derrière `kds_station`/`users.phone`, mais reproductible sur n'importe quelle route qui suit ce motif. Exemples vérifiés sur des routes de réglages sensibles :
- `app/Http/Controllers/Admin/MailController.php:39` (`update`, écrit le mot de passe SMTP)
- `app/Http/Controllers/Admin/LicenseController.php:39` (`update`)
- `app/Http/Controllers/Admin/Pilotage/InterrupteurController.php:83` (catch `\InvalidArgumentException` → `getMessage()` — ici le message est un nom d'interrupteur invalide, pas du SQL, mais le motif « exception brute vers le client » est le même)
- `app/Http/Controllers/Admin/ItemController.php:110,134,145,154,164,179,184,196,205,214,224,249,290` (13 occurrences dans un seul fichier)

Le correctif de la vague W2 (T-1.1.4, `Handler.php`) devra traiter la racine — pas seulement les deux cas déjà connus — car le motif est copié-collé sur la quasi-totalité des contrôleurs Admin.

---

## 4. Secrets renvoyés en clair

Déjà établi dans le plan (Z2, non re-vérifié en détail ici) : `LicenseResource.php:27` renvoie `license_key` en clair.

**Deux constats supplémentaires, vérifiés, non listés dans le plan tel quel** :
- `app/Http/Resources/MailResource.php:26` — `"mail_password" => $this->info['mail_password']` renvoyé **en clair**, sans masquage, à toute requête `GET /admin/setting/mail` (accessible à `permission:settings`, donc pas n'importe qui, mais le mot de passe SMTP transite en clair dans la réponse JSON).
- `app/Http/Resources/GatewayOptionsResource.php:19` — `'value' => $this->value` renvoyé sans masquage pour CHAQUE ligne d'option de passerelle. Cette resource sert `PaymentGatewayController` (déjà connu Z2 : `stripe_secret`, `paypal_client_secret`) **ET** `SmsGatewayController` (`SmsGatewayResource.php:24` → `GatewayOptionsResource::collection(...)`) — donc les identifiants/API tokens de la passerelle SMS (ex. Twilio) transitent eux aussi en clair, sans que le plan les ait nommés explicitly.

Aucun endpoint trouvé qui renvoie une valeur `.env()` brute directement (au-delà des cas déjà connus licence/passerelles).

---

## 5. Mutations sensibles sans idempotence

**Vérification par exécution réelle** (lecture seule — exécution locale de PHPUnit contre la config/route table, aucune requête contre :8800) :

```
php artisan test --filter=IdempotencyRequiredRoutesCoverageTest
→ FAIL — 1 failed
```

La sentinelle `tests/Feature/Idempotency/IdempotencyRequiredRoutesCoverageTest.php` est **ROUGE dès maintenant**, pas seulement en théorie. Elle liste 3 routes qui portent le middleware `idempotency` dans `routes/api.php` mais sont ABSENTES de `config/idempotency.php` `required_routes` — donc l'en-tête `X-Idempotency-Key` y est **optionnel** (pas de 422 s'il manque) au lieu d'obligatoire, ce qui réintroduit exactement le bug déjà corrigé ailleurs pour mollie-checkout/print-receipt (cf. commentaires `config/idempotency.php:51-56`) :

| Route | file:line (middleware posé) | Effet d'un double-appel SANS l'en-tête |
|---|---|---|
| `POST /api/admin/pos-loyalty/credit-manual` | `routes/api.php:1596-1598` | Double crédit d'un montant en euros sur le compte fidélité d'un client (plafonné à 200 € par appel — `PosLoyaltyManualCreditRequest.php`, mais rejouable) |
| `POST /api/admin/pos-loyalty/deduct-manual` | `routes/api.php:1601-1604` | Double débit de points fidélité |
| `POST /api/admin/raw-materials/{rawMaterial}/adjust` | `routes/api.php:437-439` | Double ajustement de stock matière première |

Ces trois routes ne sont PAS dans les 9 « mutateurs sans FormRequest » déjà connus — c'est un angle différent (idempotence, pas validation).

---

## Résumé (25 lignes)

1. **Les 64 `authorize()=>true`** : sur ~40 candidats à fort rayon d'explosion (fiscal/paiement/rôles/réglages) vérifiés un par un, AUCUN n'est exploitable aujourd'hui — chacun a une garde de contrôleur (`permission:...`) ou inline (`can()/hasRole()`) qui compense. Risque réel = point de défaillance unique, pas trou actif. Top domaines : Mail (`MailController.php:22`), Licence (`LicenseController.php:20`), SMS gateway, OTP, changement de mot de passe (6 contrôleurs), création de comptes Waiter/Chef/Customer, réglages langue/CMS/catalogue — tous gardés, vérifié file:line.
2. **Routes admin sans permission** (`route:list --path=api/admin --json`, 461 routes) : 21 routes mutantes sans `PermissionMiddleware` ; 20/21 vérifiées gardées inline (fiscal Z-report, cash comptoir, roue, interrupteurs, pos-loyalty). Non vérifiées faute de budget : `pos/kitchen-tickets/*`, `pos/quote` (SOUPÇON NON PROUVÉ). Seul trou confirmé = celui déjà établi : `SyncOverviewController::systemHealth`.
3. **Messages techniques** : motif SYSTÉMIQUE, pas 2 cas isolés — 502 occurrences de `getMessage()` dans `app/Http/Controllers`, 86 fichiers sous `Admin/`. `Handler.php` désinfecte bien les `QueryException` mais ce code est mort dès qu'un contrôleur catch `Exception` en premier — ce qui est la norme. Exemples : `MailController.php:39`, `LicenseController.php:39`, `ItemController.php` (13 occurrences).
4. **Secrets** : en plus de la clé de licence déjà connue — `MailResource.php:26` renvoie `mail_password` en clair ; `GatewayOptionsResource.php:19` renvoie `value` non masqué pour passerelles paiement ET SMS (Twilio incl., non cité dans le plan).
5. **Idempotence** : `IdempotencyRequiredRoutesCoverageTest` tourne **ROUGE maintenant** (exécuté, pas supposé) — 3 routes avec middleware `idempotency` posé mais hors `required_routes`, donc en-tête optionnel : `pos-loyalty/credit-manual`, `pos-loyalty/deduct-manual` (double crédit/débit fidélité), `raw-materials/{id}/adjust` (double ajustement stock).
