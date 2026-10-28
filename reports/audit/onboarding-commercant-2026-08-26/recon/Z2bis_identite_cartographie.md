# Z2bis — CARTOGRAPHIE DES CHAMPS D'IDENTITÉ DE L'ÉTABLISSEMENT (T-1.1.1)
> Reconnaissance W1 du GOAL ONB-01, lecture seule stricte. Cible `http://127.0.0.1:8800` (200 confirmé sur `/login`, `/admin/settings/company`,
> `/admin/settings/site`, `/admin/settings/branches/list`, `/admin/settings/theme`, `/kiosk/idle`). Toute ligne ci-dessous a été vérifiée par
> lecture de fichier (`Read`) ou `grep -n`, jamais supposée. Aucune page Entreprise/Site n'a été soumise (règle du prompt).

## 1. Méthode
Pour chaque champ : (1) où il se règle — route Vue exacte, (2) où il est stocké — table/colonne ou `.env`, avec le code qui écrit puis le
code qui lit, (3) quelles surfaces le consomment — file:line vérifié pour chacune, (4) modifiable depuis le Dashboard, oui/non/partiel.
« Route visible » = présente dans le sous-menu Réglages (11 entrées mesurées en Z2 §1) ; « caché » = accessible seulement en tapant l'URL,
via `resources/js/config/v1-hidden-modules.js`.

## 2. Tableau de cartographie

| Champ | Où il se règle | Où il est stocké | Qui le consomme | Modifiable Dashboard |
|---|---|---|---|---|
| **Nom** (`company_name`) | `/admin/settings/company` (visible) — `CompanyComponent.vue:15` `v-model="form.company_name"` | `settings` table, groupe `company` (`CompanyService.php:43` `Settings::group('company')->set()`) **+** écrit dans `.env` `APP_NAME` (`CompanyService.php:44`) | `<title>` — `master.blade.php:62` lit `Settings::group('company')->get('company_name')` | **OUI** — mais le ticket n'utilise PAS ce champ (voir Nom filiale) |
| **Nom filiale** (`branches.name`) | `/admin/settings/branches/list` → modale (visible) — `BranchCreateComponent.vue:19` `v-model="props.form.name"` ; requis + unique (`BranchRequest.php:32-37`) | `branches.name` (`Branch.php:15` fillable) | Ticket client (en-tête, gros, centré) — `OrderReceiptEscPosRenderer.php:70` : `optional($branch)->name ?: 'LE CAYENNE'` | **OUI** — c'est ce nom, pas `company_name`, qui sort sur le ticket |
| **Logo** (`theme_logo`) | `/admin/settings/theme` (**caché**, hors menu — `resources/js/config/v1-hidden-modules.js:34` `'settings.theme'`) — `ThemeRequest.php:34` `mimes:jpg,jpeg,png`, `max:2048` | Media Spatie sur `ThemeSetting`, collection `theme-logo` (`ThemeService.php:44-50`) | Sidebar admin `BackendMenuComponent.vue:6,9` ; navbar admin `BackendNavbarComponent.vue:5,8` ; navbar web `FrontendNavBarComponent.vue:7` ; **borne** : récupéré (`KioskIdleScreenComponent.vue:520` `this.restaurantLogo = data.logo_full_path \|\| data.theme_logo`) mais **jamais affiché** — `restaurantLogo` n'apparaît nulle part ailleurs dans le fichier (grep confirmé, code mort) ; **ticket** : jamais (ESC/POS texte seul, aucune commande bitmap dans `OrderReceiptEscPosRenderer.php`) | **PARTIEL** — l'API existe et accepte l'upload, mais la page est absente du menu Réglages, et même réglé le logo ne s'affiche pas sur la borne |
| **Favicon** (`theme_favicon_logo`) | même page cachée `/admin/settings/theme` — `ThemeRequest.php:35` | Media Spatie, collection `theme-favicon-logo` (`ThemeService.php:51-57`) | `<link rel="icon">` — `master.blade.php:62`, calculé par `RootController.php:17-18` (`ThemeSetting::where('key','theme_favicon_logo')->first()?->faviconLogo`) ; même schéma sur `payment.blade.php:11`, `paymentSuccess.blade.php:8` | **PARTIEL** — fonctionne, mais page cachée du menu |
| **SIRET** (`branches.siret`) | **AUCUN CHAMP** — colonne existe (`Branch.php:18,41`, migration `database/migrations/2026_04_20_210000_add_fiscal_identity_to_branches.php`, fichier confirmé présent) mais absente de `BranchRequest.php:31-47` (grep sur les 17 lignes de `rules()` = aucune clé `siret`) et absente du template `BranchCreateComponent.vue` (`grep v-model` = 10 champs, aucun `siret`) | Colonne `branches.siret`, **NULL en permanence** (rien n'écrit dedans : `BranchService::store/update` passe par `$request->validated()`, qui ne contient jamais cette clé) | Lu quand même par `ReceiptDataService.php:67` (`pos_siret`) → imprimé si non vide par `OrderReceiptEscPosRenderer.php:92-94` | **NON — exige un développeur** (ajouter la règle + le champ formulaire) |
| **TVA intracommunautaire** (`branches.vat_intra`) | idem SIRET — absent de `BranchRequest.php:31-47` et du formulaire | `branches.vat_intra`, NULL en permanence, même mécanisme | `ReceiptDataService.php:68` (`pos_vat_intra`) → `OrderReceiptEscPosRenderer.php:212-214` | **NON — exige un développeur** |
| **Mention légale du ticket** (`branches.legal_footer`) | idem SIRET — absent de `BranchRequest.php:31-47` et du formulaire | `branches.legal_footer`, NULL en permanence | `ReceiptDataService.php:69` (`pos_legal_footer`) → `OrderReceiptEscPosRenderer.php:215-217` | **NON — exige un développeur** |
| **Adresse** | DEUX endroits : `company_address` sur `/admin/settings/company` (`CompanyRequest.php:41` required, `CompanyComponent.vue:96`) **et** `address` sur la filiale (`BranchRequest.php:45` required, `BranchCreateComponent.vue:115` textarea) | `company_address` → `settings` groupe `company` ; `branches.address` → colonne dédiée | Ticket client : **seulement** `branches.address`, jamais `company_address` — `OrderReceiptEscPosRenderer.php:74-77` : `optional($branch)->address ?: config('printing.receipt.address', '')` (repli `.env` `RECEIPT_ADDRESS`, vide par défaut) | **OUI** pour ce qui compte (adresse filiale) — mais `company_address` existe, se règle, et ne s'imprime **jamais nulle part** (grep : aucune référence à `company_address` dans `ReceiptDataService.php` ni `OrderReceiptEscPosRenderer.php`) |
| **Téléphone** | `company_phone` (Entreprise, required) et `phone` (Filiale, nullable) — mêmes fichiers que l'adresse | `settings.company` / `branches.phone` | Ticket : `optional($branch)->phone ?: config('printing.receipt.phone', '')` (`OrderReceiptEscPosRenderer.php:81-84`) — repli `.env` `RECEIPT_PHONE`, **valeur par défaut `'03 65 67 82 91'`** codée en dur dans `config/printing.php:109` | **OUI** pour la filiale — **piège** : un nouvel établissement qui laisse le téléphone de sa filiale vide imprime sur son ticket le numéro de téléphone du Cayenne d'origine, sans le savoir |
| **E-mail** | `company_email` (Entreprise, required) et `email` (Filiale, nullable) | `settings.company` / `branches.email` | Ticket : `optional($branch)->email` — `OrderReceiptEscPosRenderer.php:85-87`, **aucun repli**, ligne simplement omise si vide | **OUI** (filiale) |
| **Horaires d'ouverture / jours fermés** | **N'EXISTE PAS** — recherche `find database/migrations -iname "*opening*" -o -iname "*closure*" -o -iname "*hours*"` = liste vide (vérifiée). Le seul écran proche, `/admin/settings/time-slots`, est **caché** (`v1-hidden-modules.js:49` `'settings.time-slots'`) et gouverne `time_slots.day` (`TimeSlotRequest.php:30`) pour les **créneaux de commande en ligne**, pas les horaires du restaurant | N/A | N/A — la borne n'affiche jamais « fermé » | **NON — exige un développeur** (fonctionnalité absente, pas seulement cachée) |
| **Devise** | `/admin/settings/site` (visible) : `site_default_currency` (dropdown, `SiteRequest.php:43`) + `site_currency_position` + `site_digit_after_decimal_point` ; liste des devises : `/admin/settings/currencies/list` (visible, `CurrencyController`, `routes/api.php:498-502`) | `settings.site` (+ `site_default_currency_symbol` calculé, `SiteService.php:44-45`) **et** écrit dans `.env` (`CURRENCY`, `CURRENCY_SYMBOL`, `CURRENCY_POSITION`, `CURRENCY_DECIMAL_POINT` — `SiteService.php:47-56`) | Écran (checkout, POS, aperçu reçu) : `site_default_currency_symbol` lu depuis le store — ex. `ReceiptComponent.vue:563` (repli `'€'` codé si absent) ; **ticket physique imprimé : jamais** — `OrderReceiptEscPosRenderer.php:43,57` fixe `' €'`/`' EUR'` en dur (`$moneySuffix`), sans lire le réglage | **PARTIEL** — change l'écran, ne change jamais ce qui sort de l'imprimante ; **et** la page Site exige `site_google_map_key` + `site_copyright` (`SiteRequest.php:52,55` `required`) pour accepter QUOI QUE CE SOIT, y compris un simple changement de devise |
| **Couleurs de marque** | **N'EXISTE PAS** — `grep -rln "brand_primary\|brand_color\|primary_color\|F4501E" app/Http/Requests database/migrations` = vide (vérifié) | N/A | N/A (palette Cayenne en dur dans le CSS compilé, hors du périmètre de cette recherche) | **NON — exige un développeur** |

## 3. Constats les plus lourds pour un commerçant qui voudrait mettre SON identité

1. **SIRET / TVA / mention légale légalement obligatoires sur un ticket français ne sont accessibles nulle part dans le Dashboard**, alors que
   les colonnes existent en base et sont déjà lues par le moteur de ticket. `app/Http/Requests/BranchRequest.php:31-47` (aucune des 3 clés
   dans `rules()`) + `app/Services/Hardware/OrderReceiptEscPosRenderer.php:92-94,212-217` (elles seraient imprimées si elles existaient). Un
   commerçant ne peut PAS mettre son SIRET sur son ticket sans un développeur qui édite le code.

2. **Le téléphone qui sort sur le ticket, s'il n'est pas saisi, est celui du Cayenne d'origine, pas une case vide.**
   `config/printing.php:109` : `'phone' => env('RECEIPT_PHONE', '03 65 67 82 91')`. Un nouvel établissement qui ne remplit pas le téléphone de
   sa filiale imprime silencieusement le numéro d'un autre restaurant sur son ticket — aucun message d'erreur, aucun signal.

3. **Le logo se règle sur une page que le menu Réglages ne montre pas**, et même réglé, il ne s'affiche jamais sur la borne d'accueil.
   Page cachée : `resources/js/config/v1-hidden-modules.js:34` (`'settings.theme'`). Code mort côté borne :
   `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue:520` assigne `this.restaurantLogo` puis ne l'utilise jamais dans le
   template (grep confirmé, aucune autre occurrence dans le fichier). Un commerçant qui trouve la page, upload son logo, et va vérifier sur
   la borne, ne verra aucun changement — sans indice sur la raison.

4. **Il n'existe aucune fonctionnalité d'horaires d'ouverture.** `find database/migrations -iname "*opening*" -o -iname "*closure*" -o
   -iname "*hours*"` retourne une liste vide. Ce n'est pas caché, ce n'est pas un bug — la table, le modèle et l'écran n'ont jamais été
   écrits. La borne ne peut donc jamais dire « fermé le lundi », quel que soit le réglage tenté.

5. **Deux « adresses » et deux « téléphones » existent (Entreprise et Filiale), et un seul jeu est réellement imprimé.**
   `company_address`/`company_phone` (`CompanyRequest.php:35,41`) se règlent, se valident, s'enregistrent — et ne sont lus par **aucun**
   fichier du rendu ticket (`grep company_address\|company_phone` dans `ReceiptDataService.php` et `OrderReceiptEscPosRenderer.php` = vide).
   Seuls `branches.phone`/`branches.address` comptent. Un commerçant qui règle soigneusement sa fiche « Entreprise » peut légitimement croire
   que c'est ce qui s'imprime — ce n'est pas le cas, sans qu'aucun écran ne le lui dise.

*(Constat complémentaire hors du top 5 mais vérifié : la devise choisie sur `/admin/settings/site` change l'écran mais jamais le ticket
imprimé, qui garde `' €'`/`' EUR'` en dur — `OrderReceiptEscPosRenderer.php:43,57` — et cette même page Site refuse tout enregistrement,
devise incluse, sans une clé Google Maps et un copyright, déjà signalé P1 en Z2.)*
