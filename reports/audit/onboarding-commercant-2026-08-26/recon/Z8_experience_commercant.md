# Z8 — Expérience commerçant, reconnaissance W1 (ONB-11)

**Date** : 2026-08-27 · **Méthode** : lecture de code + requêtes SQL en lecture seule (`foodking_e2e`) + `curl` GET. **Aucun accès navigateur** (Playwright/Chrome MCP indisponibles ce tour) → tout constat visuel (axe-core, clavier, tablette 768×1024/1024×768, colonnes hors cadre 1366) est **NON VÉRIFIÉ dans cette passe** et reste porté par les captures Z1/Z2/Z7 déjà citées dans le plan. Ce rapport ne mesure QUE ce qui est vérifiable par code + DB + curl : vocabulaire, structure du menu, confirmations destructrices, erreurs techniques brutes, premier écran.

Règle anti-fiction respectée : chaque ligne ci-dessous porte un `file:line` lu ou une requête SQL exécutée. Rien n'est deviné.

---

## 1. Le vocabulaire — inventaire exhaustif des trouvailles vérifiées

### 1.1 Boîte de dialogue de confirmation de suppression — LA plus grosse trouvaille
`resources/js/services/appService.js:118-130` — `destroyConfirmation()`, **40 points d'appel** vérifiés (`grep -rl "destroyConfirmation()" resources/js/ | wc -l` → 40), utilisée par la quasi-totalité des écrans CRUD admin (articles, coupons, offres, employés, clients, serveurs, cuisiniers, administrateurs, filiales, imprimantes…) :
```js
destroyConfirmation: function () {
    return new VueSimpleAlert.confirm(
        "You will not be able to recover the deleted record!",
        "Are you sure?",
        "warning",
        { confirmButtonText: "Yes, Delete it!", cancelButtonText: "No, Cancel!", ... }
    );
},
```
**100 % anglais, aucun `$t()`.** C'est exactement le moment où Nadia se demande « si je clique là, je casse quelque chose ? » (question #4 du plan) — et la réponse s'affiche dans une langue qu'elle ne lit pas. Aucun nom d'objet supprimé, aucune conséquence précisée (générique « the deleted record »).

Même fichier, mêmes symptômes :
- `logoutConfirmation()` (:105-116) — `"You will able to log in again using the kiosk machine!"` (anglais **et** grammaticalement faux **et** sémantiquement incohérent pour une déconnexion admin — texte copié d'un contexte borne).
- `acceptOrder()` (:131-141) — `"You will not be able to cancel the order!"` / `"Are you sure?"` / `"Yes, Accept it!"`.
- `cancelOrder()` (:149-159) — `"You will not be able to accept the order!"` / `"Yes, Cancel it!"`.
- Contre-exemple qui prouve qu'on sait faire mieux : `confirmCashPayment()` (:143-151, ajouté 2026-05-30) est intégralement en français (« Confirmer l'encaissement », « Oui, encaisser », « Annuler ») → incohérence pure, pas une contrainte technique.

### 1.2 Toasts franglais cassés (`resources/js/languages/fr.json`, bloc `message`)
| Clé | Valeur verbatim | Composant qui l'affiche |
|---|---|---|
| `message.coupon_add` | `"Coupon Ajouter Successfully."` | `frontend/checkout/CouponComponent.vue:164` |
| `message.coupon_delete` | `"Coupon Supprimer Successfully."` | `CouponComponent.vue:172` |
| `message.delivery_boy_add` | `"Livreur Ajoutered Successfully!"` (néologisme : verbe FR + suffixe passé EN) | non localisé côté admin dans ce passage (clé définie, appelant non trouvé par grep — à confirmer) |
| `message.image_update` | `"Image Mettre à jourd Successfully."` (mot inventé « Mettre à jourd ») | `admin/items/ItemShowComponent.vue:312` (mise à jour photo d'un **article**, action quotidienne) et `admin/offers/OfferShowComponent.vue:180` |
| `message.photo_update` | `"Photo Mettre à jourd Successfully."` | **10 composants** : `admin/customers/CustomerShowComponent.vue:459`, `admin/waiters/WaiterShowComponent.vue:357`, `admin/deliveryBoys/DeliveryBoyShowComponent.vue:349`, `admin/chefs/ChefShowComponent.vue:357`, `admin/administrators/AdministratorShowComponent.vue:363`, `admin/employees/EmployeeShowComponent.vue:353`, `layouts/backend/BackendNavbarComponent.vue:527` (photo de profil de l'utilisateur connecté !), `layouts/frontend/FrontendNavBarComponent.vue:474`, `layouts/frontend/FrontendMobileAccountComponent.vue:162` |
| `message.zone_update_successfully` | `"Zone Mettre à jour Successfully."` | `admin/settings/Branch/BranchShowComponent.vue:290` |
| `message.no_schedule_found` | `"N° schedule found."` (franglais inversé, faute probable pour « No schedule found ») | non tracé dans ce passage |

### 1.3 Messages d'erreur backend 100 % anglais, hors système i18n
- `app/Http/Requests/ItemRequest.php:165` — `$validator->errors()->add(..., 'The price must be a number.')`
- `app/Http/Requests/ItemRequest.php:170` — `'The price must be at least 0.'`
  → ces deux messages tombent sur le champ **prix d'une variante d'article** — l'action la plus banale du catalogue. Confirmé exact (ligne lue), correspond au constat Z1 du plan.
- `app/Rules/SafeRemoteHost.php:154` — `"The :attribute resolves to a forbidden IP range (loopback/link-local/private). "` et `:170` (IPv6) — se déclenche quand un commerçant saisit son serveur SMTP dans Réglages > Mail. Confirmé exact, correspond au constat Z7 du plan.

### 1.4 Attributs de validation — cause racine trouvée
`lang/fr/validation.php:200` :
```php
'attributes' => [],
```
**Le tableau est vide.** Conséquence : pour **tout** champ de **tout** formulaire de l'admin, Laravel remplace `:attribute` par le nom technique du champ « humanisé » (underscores → espaces), jamais traduit. C'est la cause racine du constat du plan (« order setup food preparation time », « site google map key ») : ce ne sont pas des libellés choisis, c'est le nom de colonne/clé brut affiché faute de traduction. Un seul fichier à remplir corrigerait un nombre indéterminé mais large d'erreurs de formulaire dans tout l'admin.

### 1.5 Jargon métier non traduit / mal choisi
- `menu.branches` = **"Filiales"** (`fr.json:439`) — vocabulaire de réseau multi-succursales appliqué à un commerçant mono-établissement (Le Cayenne, `branch_id=1`). Question #2 de Nadia dans le plan.
- `menu.pos` = **"POS"** (`fr.json`, bloc `menu`) — reste un acronyme anglais alors que son parent `menu.pos_and_orders` est traduit `"Caisse et commandes"`. Incohérence visible : « Caisse et commandes > POS ». Utilisé aussi comme libellé du premier bouton d'Accès rapide du Dashboard (`DashboardComponent.vue:155` : `this.$t('menu.pos')`).
- `resources/js/components/admin/dashboard/SlaAlertsComponent.vue:6` — **`"Alertes SLA (Cuisine > 15min)"`** — texte **en dur dans le template** (pas de `$t()`), jargon d'entreprise (Service Level Agreement) sur l'écran d'accueil.
- `SlaAlertsComponent.vue:17` — **`"Aucune alerte SLA. Flux de cuisine optimal."`** — même défaut, même écran.
- `resources/js/components/admin/dashboard/AuditTrailComponent.vue:6` — **`"Audit Trail NF525 (Journal Inviolable)"`** — texte en dur, mélange anglais/français/code réglementaire, sur le Dashboard.
- `AuditTrailComponent.vue:9` — **`"Source : audit_logs (INSERT-only, HMAC SHA-256 chain-signed). Le préfixe de hash atteste l'intégrité de la chaîne."`** — nom de table SQL brut + jargon crypto affiché directement à un commerçant, sur son écran d'accueil.
- `AuditTrailComponent.vue:33` — tooltip **`"Chain HMAC SHA-256 prefix — full hash hidden for security"`** — intégralement en anglais.
- `app/Services/DashboardService.php:537-539` et `:608-610` — les noms de canal envoyés par le backend sont codés en dur en anglais : `'Web'`, `'Kiosk/App'`, `'POS'` — jamais traduits, affichés tels quels par `ChannelStatsComponent.vue:9` (`{{ stat.name }}`) sur le Dashboard.

### 1.6 Permissions — falaise de repli sur l'anglais/le slug brut
Comparaison DB (`permissions`, 88 lignes, `SELECT name FROM permissions`) vs `fr.json` bloc `permission.*` (80 clés) : **6 permissions distinctes** (2 dupliquées en base) n'ont pas de traduction et tombent dans le repli documenté par le code lui-même :
`resources/js/components/admin/settings/Role/RoleShowComponent.vue:136-139` — commentaire du 2026-08-27 (ONB-06) : *« Les 80 permissions sont stockées en anglais brut… Repli explicite sur le titre anglais si une clé manque : mieux vaut un mot anglais qu'une case vide ou une clé technique affichée au commerçant. »* En pratique, pour 3 permissions le `title` DB est `NULL`, donc le repli affiche le **nom technique brut** :
- `catalog.compose` → affiché **"catalog.compose"**
- `catalog.publish` → affiché **"catalog.publish"**
- `ingredients_manage` → affiché **"ingredients_manage"**
- `availability_toggle` → `title` = **"Rupture produits (86)"** (français, mais avec un identifiant numérique brut en suffixe — confusion probable : « pourquoi ce 86 ? »)
(`SELECT id,name,title FROM permissions WHERE name IN (...)` exécuté, résultat vérifié.)

---

## 2. Compte des mots anglais restants

Comparaison automatique `fr.json` vs `en.json` (script Python, valeurs strictement identiques dans les deux fichiers) : **273 clés identiques FR=EN** sur l'ensemble du fichier. **Attention** : la majorité sont des faux positifs légitimes (mots courts identiques en français : « Total », « Menu », « Message », « Notification », « Description », marques : « Facebook », « Instagram », « Youtube », codes : « SSL », « TLS », unités : « minutes »). Après filtrage manuel, les anglicismes **réels** (mot qu'un patron de kebab ne comprendrait pas ou lirait mal) :

**10 exemples verbatim (parmi les plus visibles) :**
1. `"You will not be able to recover the deleted record!"` — `appService.js:119`, 40 écrans
2. `"Are you sure?"` / `"Yes, Delete it!"` / `"No, Cancel!"` — mêmes 40 écrans
3. `"You will able to log in again using the kiosk machine!"` — déconnexion admin, `appService.js:106`
4. `"The price must be a number."` — `ItemRequest.php:165`
5. `"The :attribute resolves to a forbidden IP range (loopback/link-local/private)."` — `SafeRemoteHost.php:154`
6. `"Alertes SLA (Cuisine > 15min)"` — Dashboard, `SlaAlertsComponent.vue:6`
7. `"Audit Trail NF525 (Journal Inviolable)"` — Dashboard, `AuditTrailComponent.vue:6`
8. `"Chain HMAC SHA-256 prefix — full hash hidden for security"` — Dashboard, `AuditTrailComponent.vue:33`
9. `'Kiosk/App'` — canal de vente affiché tel quel, `DashboardService.php:538`
10. `"Coupon Ajouter Successfully."` / `"Livreur Ajoutered Successfully!"` — `fr.json` bloc `message`

Le compte exact « mots anglais restants » ne peut pas être réduit à un entier unique sans arbitrer les faux positifs (travail de T-2.1.1, hors périmètre lecture-seule de ce rapport) ; ce qui est **certain et vérifié** : au moins **9 chaînes anglaises codées en dur** (hors fr.json, donc invisibles à tout script qui ne scanne que fr.json) + **6 toasts fr.json** franglais/cassés + **6 permissions** en repli anglais/slug brut = **21 points d'anglais confirmés par lecture directe**, sans compter les 273 clés identiques FR=EN qui restent à trier.

---

## 3. Le premier écran — `/admin/dashboard`

Lu intégralement : `resources/js/components/admin/dashboard/DashboardComponent.vue` (272 lignes).

Ce que voit un commerçant : un message de bienvenue + son nom (:10-11) ; un bandeau démo conditionnel (:3-6, uniquement si `ENV.DEMO`) ; un bouton PDF de clôture (:17-27, visible seulement avec la permission fiscale) ; une barre d'**Accès rapide** de 10 liens (POS, Commandes caisse, Suivi commandes, Encaissement, Historique, KDS, Suivi client, Catalogue, Ingrédients, Stock, Rapport caisses) (:133-187) ; puis une cascade de widgets : Vue d'ensemble (3 tuiles chiffrées), Suivi en direct (CA jour / commandes jour / ticket moyen), Alertes SLA, Répartition par canal, **Journal d'audit NF525**, Statistiques commandes, Ventes, Commandes, Dernier rapport Z, Articles en vedette, Articles populaires, Alertes stock faible.

**Aucune checklist, aucun guide « par où commencer », aucun indicateur de progression, aucune étape recommandée.** `grep -rli "checklist|getting.started|onboarding" resources/js/components/admin/` ne retourne aucun composant pertinent. Le premier écran est une cascade de chiffres et de tableaux (dont deux — SLA, Audit Trail NF525 — en jargon, cf. §1.5), avec zéro orientation pour un compte neuf.

---

## 4. La peur — actions destructrices et erreurs techniques brutes

**Confirmations existantes mais illisibles** : la suppression n'est PAS sans confirmation (contrairement à l'hypothèse la plus grave du plan) — mais la confirmation est en anglais, générique, sans nom de l'objet visé (§1.1). Nadia doit cliquer sur un texte qu'elle ne comprend pas pour valider une suppression irréversible.

**Erreurs techniques brutes potentielles** :
- `.env` du worktree a `APP_DEBUG=true` (`.env:` vérifié) — attendu en dev/test, **interdit en prod** par le boot guard `AppServiceProvider.php` (CLAUDE.md §8) ; non re-testé en direct (pas d'accès navigateur), donc je ne peux pas confirmer qu'une trace Laravel s'affiche réellement à l'écran dans cette session — **NON VÉRIFIÉ EN DIRECT**, seulement le réglage source.
- `AuditTrailComponent.vue:9` expose le nom de table SQL `audit_logs` directement dans l'UI — pas une trace d'erreur, mais une fuite de vocabulaire technique interne (nom de colonne DB) sur l'écran d'accueil.
- Repli permission → chaînes `"catalog.compose"`, `"ingredients_manage"` (slugs de code, pas des phrases) exposées si un gérant ouvre Réglages > Rôles (§1.6) — c'est plus intimidant qu'une simple traduction manquante : ça ressemble à un nom de fonction de programmeur.
- Audit Trail : `translateAction()` (`AuditTrailComponent.vue:77-82`) se replie sur le code brut de l'action si la clé `label.audit_event_*` manque. Vérifié : `label.audit_event_user_device_revoked` et `label.audit_event_slo_evaluation` **n'existent pas** dans `fr.json` (33 clés `audit_event_*` présentes, ces deux absentes) alors que le code source utilise `'action' => 'user.device_revoked'` (`grep` sur `app/`) et `'action' => 'slo_evaluation'` — si ces événements sont un jour renvoyés par le widget, ils s'afficheraient tels quels (`user.device_revoked`, `slo_evaluation`). **Non confirmé que ces événements alimentent CE widget précis** (pas vérifié en direct) — signalé par prudence, pas comme certitude.

---

## 5. Le menu — structure mesurée en base

`SELECT id,name,url,language,parent,type,status,priority FROM menus ORDER BY priority,id` sur `foodking_e2e` (lecture seule) : **33 lignes** — **7 groupes-titres** (`url='#'`) : Pos & Orders, Promo, Communications, Users, Accounts, Reports, **Setup** ; et **26 entrées feuilles**. `V1_HIDDEN_MENU_MODULES` (`resources/js/config/v1-hidden-modules.js`) masque 9 de ces feuilles (customers, coupons, offers, creditBalanceReport, deliveryBoys, onlineOrders, tableOrders, waiters, diningTables) côté V1.

En plus de ces lignes DB, `resources/js/components/layouts/backend/BackendMenuComponent.vue:101-169` (`V1_PRIMARY_SIDEBAR_MENUS`) **code en dur 14 entrées supplémentaires** (Produits & Stock, Conso & Stock, Ajustement stock, Catalogue+enfant Studio, Ingrédients, Scan facture, Commandes caisse, Historique, Encaissement, Vue caisse, Sessions livreur, Ticket promo+enfant réglages, Photo Uber, La roue), ajoutées en `buildMergedSidebarMenus()` **avant** le rendu des groupes DB. Le plan cite une mesure live antérieure (Z3) de **28 entrées rendues** — non recontrôlée ici faute de navigateur, mais cohérente avec DB (33) − masqué (9-ish, avec chevauchement) + statiques (14) + Dashboard/POS ajoutés en tête.

**Où est « Paramètres » ?** Le groupe `Setup` (id=31, contenant `Settings`/id=32 et `System Health`/id=33) est le **dernier groupe** de la table DB par `id` (donc par ordre de rendu, `ORDER BY priority,id`), et `buildMergedSidebarMenus()` pousse d'abord Dashboard, POS, les 14 entrées statiques, PUIS les groupes DB dans leur ordre — donc Réglages arrive **après** au moins 16 entrées avant lui dans la barre latérale. Pas de hiérarchie par fréquence d'usage : ordre = ordre d'insertion en base + ordre de déclaration dans le code, pas un tri pensé pour un nouvel utilisateur.

Sous-menu Réglages (`resources/js/components/admin/settings/MenuComponent.vue`, lu intégralement, comparé à `isSettingHidden()`) : **11 onglets visibles** — Entreprise, Site, Filiales, Bornes, Rapports Z, Imprimantes, Terminaux de paiement, Configuration commande, Configuration borne, Fidélité, Devises. (Mail, Notification, Thème, Catégories, Attributs, Rôles/Permissions, Taxes, Pages, Langues, SMS, Passerelle paiement, Licence, OTP, Alertes notif, Réseaux sociaux, Cookies, Analytique, Créneaux, Sliders = masqués V1.)

---

## Synthèse pour le rapport parent (25 lignes max, voir réponse texte)
