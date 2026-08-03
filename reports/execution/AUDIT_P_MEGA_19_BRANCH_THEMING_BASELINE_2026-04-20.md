# AUDIT P-MEGA-19 — Branch theming baseline (Phase C.1 du cycle W7)

**Date** : 2026-04-20  
**Mode** : READONLY  
**HEAD** : 9c8f9e202  
**Subagent** : explore very thorough  

## 0. Synthèse exécutive (5 lignes)

- Aucun champ DB ni attribut Eloquent `theme_*`, `logo_*`, `idle_*` sur `Branch` : la personnalisation visuelle kiosk (logo + vidéo idle) repose sur les **réglages globaux** (`SettingResource` / groupe kiosk-setup), pas sur la branche.  
- Les **tokens couleur kiosk** (`--kiosk-*`) sont **globaux** dans `resources/css/kiosk/tokens.css`, sans scope par branche.  
- Le CRUD branche admin gère adresse/statut/coordonnées ; **pas d'upload** logo/couleurs/vidéo au niveau branche.  
- `BranchResource` et `BranchRequest` sont **désalignés** du modèle (locales + identité fiscale absentes de la ressource/validation).  
- Spatie Media Library est **installée** et utilisée pour le **thème site** (`ThemeSetting`), pas pour `Branch`.  

## 1. Modèle Branch baseline

Fichier : `app/Models/Branch.php`

- **`$fillable`** (l.14–18) : `name`, `email`, `phone`, `latitude`, `longitude`, `city`, `state`, `zip_code`, `address`, `zone`, `status`, `available_locales`, `siret`, `vat_intra`, `register_id`, `legal_footer`.  
- **Aucun** `theme_*`, `logo_*`, `idle_*`.  
- **`$casts`** (l.20–39) : types scalaires + `available_locales` => `array`. Pas de casts pour champs thème (inexistants).  
- **Relations** : **aucune** déclarée dans ce fichier (pas de `hasMany` / `belongsTo`).  
- **Scopes** : **aucun** ; pas de scope multi-tenant sur ce modèle.  
- **Traits** : `SoftDeletes` (l.12) — `deleted_at` ajouté par migration dédiée (voir §2).  
- **Méthode métier** : `activeLocales()` (l.45–53) pour locales kiosk avec fallback `config('kiosk.default_locale')`.  

## 2. Migrations branches historique

| Fichier | Ajout |
|---------|--------|
| `2022_11_17_110125_create_branches_table.php` | Table initiale : `name`, `email`, `phone`, `lat/long`, `city`, `state`, `zip_code`, `address`, `status`, `creator_*`, `editor_*`, timestamps. |
| `2025_02_12_000000_add_zone_to_branches_table.php` | Colonne `zone` (text, nullable). |
| `2026_04_18_120006_add_available_locales_to_branches_table.php` | `available_locales` JSON + backfill `["fr","en","ar"]`. |
| `2026_04_20_210000_add_fiscal_identity_to_branches.php` | `siret`, `vat_intra`, `register_id`, `legal_footer`. |
| `2026_04_15_230200_v1_soft_deletes_and_deletion_log.php` | `softDeletes()` sur `branches` si absent. |

- **Non trouvé** : `theme_logo_url`, `theme_primary_color`, `theme_secondary_color`, `idle_video_url` sur `branches`.  

## 3. Admin CRUD branches

- **Contrôleur** : `app/Http/Controllers/Admin/BranchController.php` — `index`, `show`, `store`, `update`, `destroy`, `updateZone`, `showByLatLong`. Middleware `permission:settings`.  
- **Validation** : `app/Http/Requests/BranchRequest.php` — uniquement champs d'adresse / contact / statut / nom unique. **Pas** de `zone`, `available_locales`, champs fiscaux.  
- **Persistance** : `app/Services/BranchService.php` — `create`/`update` avec `$request->validated()` seulement, donc champs hors validation ne passent pas par ce flux.  
- **API resource admin** : `app/Http/Resources/BranchResource.php` expose `id` … `zone` — **sans** `available_locales` ni identité fiscale.  
- **UI admin** : pas de `resources/js/components/admin/branches/**`. Composants réels :  
  `resources/js/components/admin/settings/Branch/BranchListComponent.vue`, `BranchCreateComponent.vue`, `BranchShowComponent.vue` — formulaires **texte**, **pas** d'upload thème.  
- **Uploads thème globaux** : ailleurs, `resources/js/components/admin/settings/Theme/ThemeComponent.vue` (fichiers `theme_logo`, etc.) + `ThemeSetting` + Spatie.  

## 4. Contexte branche kiosk

- **`KioskAppComponent.vue`** : `loadBranch()` appelle `frontendBranch/lists`, prend **`res?.data?.data?.[0]`**, puis `setBranch(branch.id)` (l.367–377).  
- **Pas** de lecture de `site_default_branch` dans ce flux : le « premier » élément dépend de l'ordre renvoyé par `BranchService::list` (défaut `orderBy id desc`).  
- **Réglages globaux** : `_loadSettingsIntoGlobalState()` fait `GET frontend/setting`.  
- **Store** : `resources/js/store/modules/kioskSettings.js` — **aucun** `branch_id` ; préférences a11y / idle timeouts / consentements uniquement.  
- **`kioskBranch.js`** : **absent**. Branche courante via `kioskCart`/`globalState`/`kioskMenu` (actions `setBranch` référencées).  
- **API** : `routes/api.php` groupe `frontend/branch` sans middleware auth visible sur ces routes.  

## 5. Idle video usage actuel

- **`KioskIdleScreenComponent.vue`** : `<video v-if="videoSrc" :src="videoSrc" … />` ; sinon fallback gradient.  
- **Source** : `loadSettings()` lit `frontendSetting/lists` puis `data.kiosk_idle_video`.  
- **Configurable** : oui, via réglages kiosk (`KioskSetupComponent.vue` ; `KioskSetupRequest.php` `nullable|string|max:500`). **Pas** par branche.  
- **Logo idle** : `logo_full_path || theme_logo` depuis les mêmes settings, aligné avec `SettingResource`.  

## 6. CSS variables / design tokens

- **Fichiers** : `resources/css/kiosk/tokens.css` (`:root` `--kiosk-primary`, `--kiosk-bg`, etc.), surcharges `kiosk/tokens-aaa.css`, `kiosk/tokens-pmr.css` ; `kiosk-wizard.css` consomme `--kiosk-*`.  
- **Import build** : `resources/css/app.css` importe `./kiosk-wizard.css`. **`webpack.mix.js`** ne compile que `resources/css/app.css` → PostCSS/Tailwind ; pas de chaîne séparée pour les tokens.  
- **Override par branche** : **aucun** `[data-branch="…"]` dans `resources/`. Attributs racine utilisés pour a11y : `data-kiosk-contrast`, `data-kiosk-pmr`, etc.  

## 7. Storage assets

- **`config/filesystems.php`** : disques `local` (`storage/app`), `public` (`storage/app/public`, URL `APP_URL/storage`), `s3`. Lien configuré `public/storage` → `storage/app/public`.  
- **Politique upload** : thème via Spatie sur modèle `settings` ; URLs générées via `getFirstMediaUrl` dans `ThemeSetting`. Présence **AWS** / Flysystem S3 au niveau dépendances.  
- **Symlink** : défini dans config ; existence physique de `public/storage` non contrôlée en lecture seule.  

## 8. Multi-tenant isolation

- **Assets branche** : non modélisés ; le logo/vidéo idle exposés au kiosk sont **globaux** → pas d'isolation A/B au niveau média pour le thème idle.  
- **Liste branches** : endpoint frontend liste via `BranchService::list` sans filtre tenant.  
- **`BranchPolicy`** : **aucun** fichier trouvé.  

## 9. Cache / invalidation

- Idle charge les settings à **chaque** `mounted()` via store dispatch.  
- **`KioskAppComponent.vue`** : `GET frontend/setting` au mount pour `globalState`.  
- **Pas** de mécanisme dédié type Echo pour « theme changed » ; risque **CDN/navigateur** si URLs d'assets stables sans cache-busting. **Pas** d'IDB thème identifié.  

## 10. Système theming existant (oui/non)

- **Oui au niveau application** : `resources/js/store/modules/theme.js`, `admin.settings.theme`, `ThemeComponent.vue`, `setting.theme_logo` côté frontend classique.  
- **Non au niveau branche** : pas de `kioskTheme.js` (**absent** du dossier `resources/js/helpers/`).  
- **Kiosk** : réutilise `theme_logo` + `kiosk_idle_video` dans `SettingResource`.  

## 11. Spatie media-library status

- **`composer.json`** : `"spatie/laravel-medialibrary": "^10.5"`.  
- **Usage** : `ThemeSetting`, `Item`, `User`, etc. — **pas** `Branch`.  

## 12. Worktree V14 conflicts

- Snapshot git initial : **`M app/Models/Branch.php`** (et migrations `add_fiscal_identity_to_branches`, etc.) : travaux **fiscal/locales**, pas theming.  
- **`resources/js/components/admin/branches/**`** : vide ; UI branche sous `admin/settings/Branch/`.  

## 13. Verdict GAP analysis

| Capability | Existant | Manquant |
|------------|----------|----------|
| Logo upload admin **par branche** | Non | Oui (tout à faire) |
| Logo display kiosk **par branche** | Non (logo global settings) | Oui |
| Couleurs **par branche** | Non (tokens CSS globaux) | Oui |
| Idle video **par branche** | Non (URL string globale `kiosk_idle_video`) | Oui |
| Champs DB thème branche | Non | Oui |
| API `BranchResource` thème | Non | Oui |
| Isolation assets entre branches | Non pour thème kiosk actuel | À définir + implémenter |
| Receipt fiscal logo branche | Non dans `ReceiptDataService` (seulement SIRET/TVA/footer texte) | Logo imprimé si requis métier |

## 14. Périmètre proposé pour GATE_BRIEF (Phase C.2)

- Décider si le thème kiosk doit **remplacer** ou **compléter** le thème global actuel.  
- Tracer la chaîne : **DB branche** → **Resource/API** → **kiosk boot** (`loadBranch` + `loadSettings`) → **CSS** (injection variables ou classes).  
- Cadrer **stockage** (Spatie sur `Branch` vs colonnes URL + `Storage`).  
- Aligner **POS/KDS/receipt** si « une seule vérité » branding.  

## 15. Décisions business à demander (Q1–Q8)

- **Q1** formats/tailles logo, profondeur palette, specs vidéo + fallback image  
- **Q2** S3 vs local, Spatie vs fichier simple, CDN  
- **Q3** branche seule vs franchise/concept  
- **Q4** self-service vs centralisé, modération  
- **Q5** fallbacks logo/vidéo  
- **Q6** preload vs lazy, offline  
- **Q7** audit/versioning uploads  
- **Q8** même thème POS/KDS/kiosk + **NF525** (logo ticket : aujourd'hui pas dans `ReceiptDataService`)  

## 16. Risques découverts

- **Kiosk multi-branches** : sélection implicite `data[0]` + tri `id desc` peut **ne pas** correspondre à la branche physique attendue.  
- **Désalignement modèle/API** : champs `available_locales` / fiscaux en base mais absents de `BranchResource` / `BranchRequest`.  
- **Cache** : changement d'asset sans bust peut laisser d'anciens médias côté borne.  
- **Sécurité surface** : routes `frontend/branch` publiques — à valider vs produit.  

## 17. Estimation LOC implémentation (post-gate)

- Ordre de grandeur **~800–1800 LOC** (migrations + modèle + requests/resources + admin UI + kiosk + tests) selon choix Spatie/S3 et granularité palette ; affiner après GATE sur Q1–Q3.  
