# Kiosk Categories — Root Cause & Fix Plan — 2026-05-01

## Verdict

`ISSUE_VERDICT: CONFIRMED`

`ROOT_CAUSE: LOCAL_DATABASE_TEST_FIXTURE_POLLUTION + DUPLICATE_KIOSK_CATEGORY_NAVIGATION`

`FIX_VERDICT: PASS_LOCAL`

`FIX_APPLIED_AT: 2026-05-01 18:25 Europe/Paris`

La capture montre un probleme reel sur la borne:

- la barre gauche liste des categories techniques `PW-C2 Category ...`;
- les memes familles apparaissent plusieurs fois;
- la borne rend deux navigations categories en meme temps: sidebar verticale a gauche + quick strip horizontale en haut du contenu.

Ce n'est pas un bug de paiement, ni un choix "emporter/sur place". Le choix emporter/sur place ne devrait jamais creer des categories. Le symptome vient de la donnee centrale consommee par la borne.

## Correction Appliquee

### UI kiosk

Fichier:

- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`

Changements:

- suppression de la barre haute des categories (`kiosk-categories-quick-strip`, `kiosk-category-strip`, `kiosk-category-pill`);
- suppression de la barre de filtres catalogue (`kiosk-filter-bar`, chips Halal/Vegetarien/etc.);
- suppression du bandeau actif de filtres (`kiosk-active-filter-banner`);
- conservation d'une seule navigation: la sidebar verticale gauche;
- sidebar gauche rendue plus compacte et scrollable proprement:
  - colonne `clamp(124px, 12vw, 156px)`;
  - scrollbar fine visible;
  - images de categories compactes;
  - noms sur 2 lignes max avec `overflow-wrap:anywhere`;
- le greyout produit reste base sur la disponibilite/rupture uniquement, pas sur des filtres client caches.

### Data repair local

Fichier:

- `app/Console/Commands/CleanupTestFixturesCommand.php`

Commande ajoutee:

```bash
php artisan foodking:cleanup-test-fixtures --prefix=PW- --dry-run --json
php artisan foodking:cleanup-test-fixtures --prefix=PW- --apply --confirm=PW-FIXTURES --json
```

Securites:

- dry-run par defaut;
- `--apply` refuse sans `--confirm=PW-FIXTURES`;
- `--apply` refuse en production;
- suppression transactionnelle par ordre de dependances;
- cache Laravel flush apres apply;
- `audit_logs` volontairement non supprime: la protection NF525 insert-only a correctement bloque une tentative initiale, donc la commande respecte maintenant cette zone append-only.

### Resultat de purge

Avant purge:

```json
{
  "orders": 27,
  "domain_events": 16,
  "stock_movements": 41,
  "stock_levels": 48,
  "item_addons": 21,
  "item_extras": 21,
  "item_variations": 21,
  "item_wizard_profiles": 21,
  "item_wizard_steps": 63,
  "items": 69,
  "item_categories": 48
}
```

Apres purge:

```json
{
  "orders": 0,
  "order_items": 0,
  "transactions": 0,
  "domain_events": 0,
  "stock_movements": 0,
  "stock_levels": 0,
  "item_addons": 0,
  "item_extras": 0,
  "item_variations": 0,
  "item_wizard_profiles": 0,
  "item_wizard_steps": 0,
  "item_branch_availability": 0,
  "items": 0,
  "item_categories": 0
}
```

Controle direct:

```json
{
  "pw_categories": 0,
  "pw_items": 0,
  "pw_orders": 0
}
```

### Tests ajoutes / mis a jour

Fichiers:

- `tests/js/KioskCategoriesRestyle.spec.js`
- `tests/Feature/Sentinels/PlaywrightFixtureCleanupCommandTest.php`

Couverture:

- la page kiosk ne rend plus de quick strip categories;
- la page kiosk ne rend plus de barre filtres;
- la page kiosk garde bien la sidebar categories;
- le cleanup dry-run ne mute rien;
- le cleanup apply supprime uniquement les fixtures prefixees;
- le cleanup apply exige une confirmation explicite.

### Validation executee

```bash
npx vitest run tests/js/KioskCategoriesRestyle.spec.js
```

Resultat: `9 passed`.

```bash
php artisan test tests/Feature/Sentinels/PlaywrightFixtureCleanupCommandTest.php --stop-on-failure
```

Resultat: `3 passed`.

```bash
npm run development
```

Resultat: bundle rebuilt, including `public/js/kiosk-shell.js`.

```bash
git diff --check -- resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue tests/js/KioskCategoriesRestyle.spec.js app/Console/Commands/CleanupTestFixturesCommand.php tests/Feature/Sentinels/PlaywrightFixtureCleanupCommandTest.php public/js/kiosk-shell.js public/js/manifest.js public/mix-manifest.json
```

Resultat: no whitespace errors.

### Validation runtime locale

Scenario Playwright headless reel:

1. ouvrir `http://127.0.0.1:8000/kiosk/idle`;
2. cliquer `Sur place`;
3. attendre la navigation vers `/kiosk/categories`;
4. inspecter le DOM rendu.

Resultat:

```json
{
  "href": "http://127.0.0.1:8000/kiosk/categories?cat=12",
  "quickStrip": 0,
  "filterBar": 0,
  "activeFilterBanner": 0,
  "sidebarCount": 14,
  "pwNames": [],
  "hasPwText": false,
  "topLabels": []
}
```

Verdict runtime: la barre haute categories est absente, les filtres sont absents, la sidebar gauche rend les categories business, et aucun texte `PW-` n'est visible.

## Preuves Locales

### Donnees polluees en base locale

Commande d'audit:

```bash
php artisan tinker --execute='echo json_encode(["pw_categories"=>DB::table("item_categories")->where(function($q){$q->where("name","like","PW-%")->orWhere("slug","like","pw-%");})->count(),"pw_items"=>DB::table("items")->where(function($q){$q->where("name","like","PW-%")->orWhere("slug","like","pw-%");})->count(),"pw_orders"=>DB::table("orders")->where(function($q){$q->where("order_serial_no","like","PW-%")->orWhere("token","like","PW-%");})->count(),"pw_order_items"=>DB::table("order_items")->where("instruction","like","PW-%")->count()]);'
```

Resultat observe:

```json
{
  "pw_categories": 48,
  "pw_items": 69,
  "pw_orders": 27,
  "pw_order_items": 0
}
```

Autre audit:

```json
{
  "total_categories": 61,
  "active_categories": 61,
  "pw_categories": 48,
  "active_pw": 48
}
```

Donc 48 categories techniques de tests sont actives et visibles dans le catalogue. La borne fait ce qu'on lui demande: elle affiche les categories actives du backend.

### Source des categories techniques

Fichier identifie:

- `tests/e2e/pos-full-process/c2-pos-process-audit.spec.js`

Il declare:

```js
const PREFIX = 'PW-C2';
```

Et cree des fixtures:

- `PW-C2 Category P1_DINE_IN_CASH`
- `PW-C2 Category P2_TAKEAWAY_CARD`
- `PW-C2 Category P3_DELIVERY_FIXTURE`
- `PW-C2 Category P4_COUNTER_CONFIRM`
- `PW-C2 Category P5_COUNTER_CANCEL`

Autre source:

- `tests/e2e/central-management-va-sys05.spec.js`

Il cree:

- `PW-VA-SYS05 Central Category`

Les noms visibles dans la capture correspondent exactement aux fixtures E2E.

### Double navigation UI

Fichier:

- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`

Le template rend deux fois `sidebarCategories`:

1. Sidebar verticale:

```vue
<aside class="kiosk-sidebar" data-testid="kiosk-categories-sidebar">
  <button v-for="cat in sidebarCategories" ...>
```

2. Barre rapide horizontale:

```vue
<div class="kiosk-category-strip" data-testid="kiosk-categories-quick-strip">
  <button v-for="cat in sidebarCategories" ...>
```

Avec 61 categories actives, le double rendu donne une UI lourde et repetee.

### Store kiosk

Fichier:

- `resources/js/store/modules/kioskMenu.js`

Le store fetch:

- priorite: `GET frontend/menu`;
- fallback: `GET frontend/item-category?paginate=0&status=5&surface=kiosk...`;
- trie via `sortCategoriesForKioskDisplay`;
- expose `sidebarCategories`.

Il ne dedoublonne pas par nom, et c'est correct: dedoublonner cote front masquerait une corruption de donnees. La source de verite doit etre corrigee en base / tests / dashboard.

## Analyse Technique

### Cause 1 — Les tests E2E ont pollue la base visible par la borne

Les tests Playwright ont ete executes contre la base locale de l'application. Ils creent des categories actives avec `channels = null`, donc visibles partout: POS, kiosk, web.

Le cleanup existe dans `tests/e2e/helpers/process-audit.js`, mais la base contient encore des restes. Causes probables:

- runs interrompus avant `afterEach`;
- anciens runs avant durcissement cleanup;
- cleanup trop specialise par prefix et pas centralise;
- absence de sentinelle globale "aucune fixture PW-* apres suite";
- E2E executes sur DB de dev au lieu d'une DB e2e ephemere.

### Cause 2 — Les categories de test ont les memes attributs qu'une vraie categorie business

Exemple observe:

```json
{
  "name": "PW-C2 Category P1_DINE_IN_CASH",
  "status": 5,
  "channels": null
}
```

`status=5` et `channels=null` signifient: categorie active visible partout.

Le backend n'a aucun moyen de savoir que c'est une fixture si elle reste en base.

### Cause 3 — Le layout kiosk affiche deux menus categories simultanes

La sidebar gauche est utile sur borne grand ecran.

La quick strip horizontale peut etre utile sur mobile ou sur un layout sans sidebar, mais sur la borne elle duplique exactement la meme navigation.

Resultat: quand la data est polluee, l'UX devient doublement mauvaise.

### Cause 4 — Cache kiosk possible apres nettoyage

`kioskMenu.js` sauvegarde un snapshot local IndexedDB via `saveSnapshot(...)`. Apres nettoyage DB, une borne peut encore afficher une ancienne liste si elle tombe sur le snapshot offline ou si le cache memoire n'est pas invalide.

Il faut donc inclure une invalidation de cache menu apres purge.

## Ce Qu'il Ne Faut Pas Faire

- Ne pas filtrer en dur `PW-` dans `KioskCategoriesComponent.vue` en production. Ce serait masquer une pollution de donnees et casser la confiance dans la projection centrale.
- Ne pas dedoublonner par nom uniquement cote frontend. Deux categories differentes peuvent exister par design; si la regle business interdit les doublons, elle doit etre imposee au dashboard/backend.
- Ne pas supprimer manuellement au hasard les lignes sans audit dry-run: il y a des foreign keys et des rows liees stock/orders/events.
- Ne pas accuser le choix emporter/sur place: ce choix influence `order_type`, pas la creation de categories.

## Plan De Correction Racine

### KIOSK-CAT-01 — Data Repair Securise Local/Staging

Objectif: supprimer les fixtures techniques `PW-*` de la base locale/staging sans toucher aux vraies donnees.

Implementation:

1. Creer ou etendre une commande artisan dediee, par exemple:

```bash
php artisan foodking:cleanup-test-fixtures --prefix=PW- --dry-run --json
php artisan foodking:cleanup-test-fixtures --prefix=PW- --apply --confirm=PW-FIXTURES
```

2. La commande doit collecter et afficher avant suppression:

- `item_categories`;
- `items`;
- `item_variations`;
- `item_extras`;
- `item_addons`;
- `stock_levels`;
- `stock_movements`;
- `orders`;
- `order_items`;
- `transactions`;
- `order_status_transitions`;
- `domain_events`;
- `audit_logs` test-only si resource/order prefix match.

3. Elle doit supprimer dans l'ordre inverse des dependances.

4. Elle doit refuser `--apply` en production sauf gate explicite.

5. Elle doit sortir un JSON avant/apres:

```json
{
  "prefix": "PW-",
  "dry_run": false,
  "before": { "categories": 48, "items": 69, "orders": 27 },
  "after": { "categories": 0, "items": 0, "orders": 0 }
}
```

Tests:

- Feature test dry-run ne mute rien.
- Feature test apply supprime uniquement prefix `PW-`.
- Feature test refuse apply en prod sans confirmation.

### KIOSK-CAT-02 — Isolation E2E Pour Eviter La Recurrence

Objectif: que les tests ne puissent plus polluer la base visible par la borne.

Options, dans l'ordre de qualite:

1. Ideal: lancer E2E contre une DB dediee `foodking_e2e`, jamais la base dev.
2. Acceptable local: chaque suite E2E a un prefix unique + cleanup robuste + sentinel no-residue.
3. Minimum: `beforeAll` et `afterAll` global cleanup pour tous prefixes `PW-C0`, `PW-C1`, `PW-C2`, `PW-VA-SYS05`, `PW-DASH-CRUD`, `PW-GLOBAL-TRACE`.

Corrections:

- Renforcer `tests/e2e/helpers/process-audit.js::cleanupProcessAudit`.
- Ajouter un helper commun `cleanupPlaywrightFixtures(prefixes)`.
- Ajouter un test/sentinel:

```bash
php artisan test tests/Feature/Sentinels/NoPlaywrightFixtureLeakTest.php
```

Ce sentinel doit echouer si une ligne active `PW-%` est visible dans `item_categories`, `items` ou `orders`.

### KIOSK-CAT-03 — Dashboard / Backend Duplicate Policy

Objectif: eviter de vraies duplications business de categories.

Decision business a prendre:

- Si le catalogue est central global: `slug` categorie doit etre unique globalement.
- Si catalogue par tenant/branch: `slug` doit etre unique par tenant/branch.

Corrections candidates:

- Validation backend `ItemCategoryRequest`: refuser slug duplique.
- Dashboard: si l'utilisateur cree une categorie avec meme nom, proposer d'editer l'existante.
- Migration unique index seulement apres audit data, pas en premier.

Ce point n'est pas la cause des `PW-C2`, mais il evite une version business du meme probleme.

### KIOSK-CAT-04 — UI Borne: Une Seule Navigation Categories Sur Grand Ecran

Objectif: supprimer la duplication visuelle sidebar + strip sur la borne.

Correction recommandee:

- Garder la sidebar verticale sur kiosk/tablet/desktop.
- Masquer `.kiosk-category-strip` sur les largeurs ou la sidebar existe.
- Garder `.kiosk-category-strip` uniquement sur mobile/narrow layout si la sidebar passe en haut ou disparait.

Implementation possible:

```css
@media (min-width: 768px) {
  .kiosk-category-strip {
    display: none;
  }
}
```

Ou plus propre:

- ajouter un computed `showQuickCategoryStrip`;
- rendre la strip seulement en layout mobile.

Tests:

- Vitest/render: quick strip absent en mode kiosk desktop.
- Playwright design: sidebar visible, quick strip absent, categories pas dupliquees.
- Mobile/narrow: quick strip visible si sidebar masque.

### KIOSK-CAT-05 — UX Robustesse Si Beaucoup De Categories Reelles

Objectif: si un restaurant a vraiment 30 categories, l'UI reste utilisable.

Ameliorations:

- limiter hauteur item sidebar;
- remplacer grosses images par icones plus compactes si > 12 categories;
- ajouter recherche ou groupement si > 20 categories;
- garder sticky selected category;
- ne pas cacher les categories business valides.

Ce point est P2 ergonomique, pas la cause racine.

### KIOSK-CAT-06 — Invalidation Cache Kiosk Apres Nettoyage

Objectif: apres purge DB, la borne ne garde pas les anciennes categories en IndexedDB.

Corrections:

- Bump `MenuSnapshot` apres cleanup fixtures.
- Cote kiosk, forcer `fetchMenu({ force: true })` apres retour idle ou refresh admin.
- Ajouter un bouton/admin action "Vider cache borne" si besoin.
- Pour test manuel: ouvrir DevTools/Application/IndexedDB et nettoyer le snapshot, ou changer snapshot_version.

## Ordre D'Execution Recommande

1. `KIOSK-CAT-01`: commande dry-run cleanup fixtures.
2. Lancer dry-run et verifier les lignes qui seront touchees.
3. Appliquer cleanup sur local/staging seulement.
4. Invalider cache kiosk / snapshot.
5. Verifier borne: seules categories business restantes.
6. `KIOSK-CAT-02`: fixer cleanup E2E et sentinel no-residue.
7. `KIOSK-CAT-04`: masquer quick strip sur borne grand ecran.
8. Ajouter tests visuels/design.
9. `KIOSK-CAT-03`: decision unique slug/name avec migration seulement apres audit.

## Commandes D'Audit Actuelles

Lister pollution:

```bash
php artisan tinker --execute='echo json_encode(DB::table("item_categories")->select("id","name","slug","status","channels")->where(function($q){$q->where("name","like","PW-%")->orWhere("slug","like","pw-%");})->orderBy("id")->get(), JSON_PRETTY_PRINT);'
```

Compter pollution:

```bash
php artisan tinker --execute='echo json_encode(["pw_categories"=>DB::table("item_categories")->where(function($q){$q->where("name","like","PW-%")->orWhere("slug","like","pw-%");})->count(),"pw_items"=>DB::table("items")->where(function($q){$q->where("name","like","PW-%")->orWhere("slug","like","pw-%");})->count(),"pw_orders"=>DB::table("orders")->where(function($q){$q->where("order_serial_no","like","PW-%")->orWhere("token","like","PW-%");})->count()]);'
```

## Acceptance Criteria

Avant de dire PASS:

- `PW-%` active categories = `0` sur base cible.
- Borne `/kiosk/categories` n'affiche aucune categorie technique.
- Sidebar gauche affiche uniquement les categories business.
- Quick strip horizontale absente sur grand ecran kiosk ou justifiee sur mobile uniquement.
- Tests E2E ne laissent aucun residue `PW-%` apres run.
- Sentinel no-residue PASS.
- Kiosk cache invalide et menu refetch depuis backend.

## Risque Business Si Non Corrige

- Client voit des categories techniques.
- UX borne inutilisable si beaucoup de fixtures.
- Le restaurateur perd confiance dans le dashboard central.
- Les tests polluent la demo et peuvent masquer de vrais bugs catalogue.
- La double navigation donne l'impression d'un systeme non fini meme si le backend est correct.

## Conclusion

Le probleme est corrigeable proprement en deux axes:

1. Donnee: supprimer et empecher les fixtures E2E actives dans la base visible.
2. UI: ne plus afficher deux navigations categories en meme temps sur la borne grand ecran.

La correction doit partir de la racine data/test isolation, pas d'un filtre frontend cache-misere.
