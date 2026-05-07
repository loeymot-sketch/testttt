# CV1-POS-AVAILABILITY-LIVE-001 — Investigation

- **Date** : 2026-05-08
- **Mission** : `CV1-POS-AVAILABILITY-LIVE-001` (Eng Manager + Implementer GSTACK)
- **Branche** : `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27`
- **Scope** : investigation seule. Fix appliqué dans un cycle BLUE séparé.
- **Trust but verify** : preuves file:line + DOM probe RED-R3 + curl post-mortem.

---

## 1. Récap RED-R3 — hypothèses

Référence : `docs/audit/RED_TEAM_R3_RUPTURE_STOCK_2026-05-07.md` §3.

| # | Hypothèse | Statut investigation 2026-05-08 |
|---|---|---|
| (a) | Vuex `item/lists` ne re-fetch pas après reload (cache stale) | **ÉCARTÉ** — `mounted()` PosComponent.vue:1438 dispatche systématiquement `itemList()` à chaque montage. Vue ne ressuscite pas l'instance entre reloads ; toute reload = fresh `mounted()`. |
| (b) | Tri/filter SPA ignore `is_available=false` | **ÉCARTÉ** — `items` est un computed brut (`return this.$store.getters["item/lists"]`, PosComponent.vue:1237). Aucun filter / sort applicatif. La méthode `tileClassList()` ItemComponent.vue:712 lit bien `row.is_available === false`. |
| (c) | `branch_id` du context auth pas envoyé dans la requête | **CONFIRMÉ — root cause** (cf §4). Conditionnel sur `auth.authBranchId === 0` (`admin@lecayenne.fr`). |
| (d) | Vuex localStorage rehydrate écrase la nouvelle réponse API | **ÉCARTÉ** — module `item` n'est PAS dans la liste `paths` de `createPersistedState` (resources/js/store/index.js:236-273 ; persiste seulement `auth`, `globalState`, `frontendCart`, `frontendSignup`, `GuestSignup`, `posCart`, `tableCart`, `kioskCart.*`, `kioskSettings.*`). Donc `state.item.lists` part de `[]` à chaque reload, pas de rehydrate parasite. |

---

## 2. Cartographie flow d'hydration POS au reload

### 2.1 — Ordre d'exécution (T0 = page reload)

```
T0 ──► createPersistedState plugin re-injecte localStorage → state.auth.{authBranchId, authInfo}
       (synchrone, avant tout composant)

T1 ──► Vue boot → router.push('/admin/pos') → PosComponent monté
       PosComponent.vue:1419  mounted() {
T2 ──►   this._stopBarcode = createBarcodeDetector(...)
T3 ──►   this._stopFKeys = createFKeyShortcuts(...)
T4 ──►   this.itemCategories()                           ← dispatch posCategory/lists
T5 ──►   const bootstrapBranchId = this.authBranchId()   ← lit auth (déjà hydraté @ T0)
T6 ──►   if (bootstrapBranchId) {
            this.applyPosBranchScope(bootstrapBranchId)  ← écrit props.search.branch_id
         }
T7 ──►   this.itemList()                                 ← FETCH #1 catalogue
         this.loadKioskCashOrders()
         this._subscribeEcho()                           ← Echo subscribe pour live updates
T8 ──►   this.$store.dispatch("defaultAccess/show").then(res => {
            const branchId = this.resolveDefaultAccessBranchId(res)
            if (branchId) {
T9 ──►        this.applyPosBranchScope(branchId)
              if (previousBranchId !== branchId) this.itemList()  ← FETCH #2 catalogue
            }
         })
       }
```

### 2.2 — Pointers file:line clés

| Étape | Fichier:ligne | Comportement |
|---|---|---|
| Persistance auth | `resources/js/store/index.js:237-273` | `paths` inclut `"auth"` (mais PAS `"item"`) |
| `auth.authBranchId` mutation | `resources/js/store/modules/auth.js:144` | écrit lors du login (`state.authBranchId = payload.branch_id`) |
| Lecture branch_id côté composant | `PosComponent.vue:1586-1608` `authBranchId()` | retourne `parseInt(authInfo.branch_id) || 0` |
| Bootstrap branch | `PosComponent.vue:1434-1437` | `if (bootstrapBranchId)` — **falsy guard** ; `0` skip `applyPosBranchScope` |
| 1er `itemList()` | `PosComponent.vue:1438` | dispatch `item/lists` avec `props.search.branch_id` (peut être null) |
| Initialisation `props.search` | `PosComponent.vue:1095-1106` | `branch_id: null` à la naissance du composant |
| `applyPosBranchScope()` | `PosComponent.vue:1622-1640` | retourne null si `value <= 0` (ne touche pas `props.search.branch_id`) |
| Action store `item/lists` | `resources/js/store/modules/item.js:34-67` | auto-injection branch_id : skip si payload contient déjà la clé `branch_id` (même `=null`, ligne 38-39 `hasOwnProperty`) |
| Sérialisation URL | `resources/js/services/appService.js:225-245` | **strip systématique** des valeurs `""` ou `null` (ligne 231) → `branch_id=null` n'est pas dans l'URL |
| Backend forcing branch | `app/Http/Controllers/Admin/ItemController.php:246-260` | `forcePosRuntimeBranchScope` actif **uniquement si** user `can('pos') && !can('items_show')` (ligne 249) |
| Backend overlay availability | `app/Services/ItemService.php:160-192` | `applyBranchAvailabilityOverlay` early-return si `$branchId<1` (ligne 163-165) → pas de `effective_is_available` calculé |
| Sérialisation API | `app/Http/Resources/SimpleItemResource.php:22-25` | sans overlay, `effective_is_available===null` → fallback `is_available` global (`items.is_available` colonne maître, **pas** `item_branch_availability`) |

### 2.3 — Vue 3 réactivité catalog

`items` (PosComponent.vue:1236-1238) est computed sur `$store.getters["item/lists"]`. La mutation `state.item.lists = payload` (item.js:217) déclenche bien le re-render Vue 3 (Proxy reactivity). Cette voie n'est pas la source du bug.

---

## 3. Reproduction runtime

### 3.1 — Spec qui reproduit déjà le symptôme

`tests/e2e/red-team-r3-rupture-stock-live-2026-05-07.spec.js:310-415` (R3-03) — login `admin@lecayenne.fr`, toggle item 363 OFF via HTTP, reload, DOM probe.

Evidence rejouée :
- `tests/e2e/screenshots/red-team-r3-rupture-2026-05-07/dom-snapshots.json` label `R3-03-after-reload` → `disabled:false`, `has86Badge:false`, `classes:"pos-v5-tile pos-item-tile"` (pas de `is-unavailable`).

### 3.2 — Curl post-mortem RED-R3 §6.1 (ligne 314-330)

```bash
TOKEN=$(curl -s -X POST localhost:8000/api/auth/login \
  -H 'x-api-key: ...' -d '{"email":"admin@lecayenne.fr","password":"123456"}' | jq -r .token)
curl -s -H "Authorization: Bearer $TOKEN" -H "x-api-key: ..." \
  "localhost:8000/api/admin/item?branch_id=1" | jq '.data[] | select(.id==363)'
```

→ Retourne `is_available:false`. **Note critique** : le curl passe `?branch_id=1` **explicitement**. C'est la divergence entre ce que le curl envoie et ce que la SPA envoie (cf §4) qui a masqué la cause racine.

### 3.3 — Reproduction admin@lecayenne.fr, branch_id absent

Données seeders confirmées :
- `database/seeders/UserTableSeeder.php:32-44` → `admin@lecayenne.fr` est créé avec `branch_id => 0`.
- `database/seeders/UserTableSeeder.php:80-96` → `pos@lecayenne.fr` est créé avec `branch_id => 1` ET `DefaultAccess(name='branch_id', default_id=1)`.

Donc pour `admin@lecayenne.fr` au reload `/admin/pos` :

| Step | Valeur observée |
|---|---|
| Vuex `auth.authBranchId` après rehydrate | `0` (seed `branch_id=0`) |
| `this.authBranchId()` à T5 | `0` (`parseInt(0,10)=0`) |
| `if (bootstrapBranchId)` à T6 | **falsy** → `applyPosBranchScope(0)` jamais appelé |
| `props.search.branch_id` au moment de T7 itemList #1 | reste `null` (init PosComponent.vue:1104) |
| Auto-injection store `item.js:38-50` | `hasExplicitBranchId === true` (clé présente, valeur null) → skip injection |
| URL HTTP fetched par axios | `GET /api/admin/item?paginate=0&order_column=id&order_type=asc&status=10&surface=pos` (sans `branch_id`) |
| Côté backend `forcePosRuntimeBranchScope` | `$user->can('items_show') === true` → return null (pas de force) |
| Côté backend `applyBranchAvailabilityOverlay` | `$branchId<1` → early return (pas d'overlay) |
| `SimpleItemResource` projection pour item 363 | `effective_is_available===null` → `is_available = (bool)$this->is_available = true` (col globale) |
| Réponse JSON pour item 363 | `is_available: true` |
| Vuex `state.item.lists[363].is_available` | `true` |
| `tileClassList(item)` ItemComponent.vue:712 | `is-unavailable: false` |
| DOM | tile cliquable, pas de badge OOS — **bug reproduit** |

`defaultAccess/show` (T8) ne sauve pas la situation : `admin@lecayenne.fr` n'a aucune ligne `DefaultAccess` (le seeder seede uniquement `pos@lecayenne.fr` ligne 93-96). `resolveDefaultAccessBranchId` (PosComponent.vue:1610-1620) tombe sur `this.authBranchId()=0` → second `itemList()` jamais déclenché.

### 3.4 — Discriminateur user

Le bug est **conditionnel sur le profil utilisateur** :

| User | branch_id | DefaultAccess | `forcePosRuntimeBranchScope` | itemList request | Bug ? |
|---|---|---|---|---|---|
| `admin@lecayenne.fr` | 0 | aucun | NO (a `items_show`) | sans `branch_id` | **OUI** |
| `pos@lecayenne.fr` | 1 | branch_id=1 | YES (pos sans items_show) | avec `branch_id=1` | NON |

Le RED-R3 spec lance avec `admin@lecayenne.fr` (ligne 316) → reproduit toujours.
Si on relancait le spec avec `pos@lecayenne.fr`, `bootstrapBranchId=1` → `applyPosBranchScope` écrit `props.search.branch_id=1` → request part avec `branch_id=1` → overlay backend applique → `is_available=false` retourné → tile rendue `is-unavailable`.

---

## 4. Cause racine

**Hypothèse (c) confirmée — précisée**.

> Quand l'utilisateur connecté n'a ni `users.branch_id > 0` ni ligne `default_access(name='branch_id')` (cas typique : super-admin / admin global), la SPA POS au reload :
> 1. ne peut pas alimenter `props.search.branch_id` avant le 1er fetch (`bootstrapBranchId=0` → falsy guard PosComponent.vue:1435 court-circuite `applyPosBranchScope`),
> 2. ne sera pas non plus secourue par `defaultAccess/show` (pas de row → `resolveDefaultAccessBranchId` retombe sur `authBranchId()=0`),
> 3. le store strip `branch_id=null` de l'URL (appService.js:231),
> 4. le contrôleur ne force pas la branch côté serveur car `forcePosRuntimeBranchScope` ne s'applique que pour `pos && !items_show` (ItemController.php:249),
> 5. donc `applyBranchAvailabilityOverlay` est court-circuité (`$branchId<1` early return ItemService.php:163-165),
> 6. la réponse n'inclut donc jamais l'overlay branch ; `is_available` redevient le flag global de la colonne `items.is_available` (toujours `true` tant que l'item n'est pas désactivé globalement).

Conséquence runtime : la tuile `Tacos M` (item 363) reste cliquable au POS pour les sessions admin, même après toggle branch OOS. Le rejet n'apparaît qu'au submit de la commande (HTTP 422 `Article 363 indisponible pour cette branche (manual_admin)`).

Ce mécanisme n'est PAS visible pour un caissier réel (`pos@lecayenne.fr`) parce que sa session a `branch_id=1` persistée.

---

## 5. Fix proposé (BLUE applique)

### 5.1 — Choix de stratégie

Trois options ont été pesées :

| Option | Surface | Pros | Cons |
|---|---|---|---|
| (1) SPA — bloquer fetch sans branch | PosComponent.vue 5-15 lignes | scope-minimal, INLINE-EDIT-EXCEPTION compliant | déplace le problème : empêche admin de voir le catalogue tant qu'aucune branch n'est sélectionnée |
| (2) Backend — abort 422 sur `surface=pos` sans `branch_id` | ItemController.php +5 lignes | architecturalement cohérent (POS = toujours branch-scopé) | risque blast radius : tooling admin utilisant `?surface=pos` sans branch casse |
| (3) SPA — sélecteur de branche pour admin / défaut branche 1 | PosComponent.vue 20-30 lignes | UX correcte | scope > inline-edit, demande Codex / vraie spec |

**Recommandation** : option (1) **+** option (3) en heal léger. (3) en cycle suivant si admin a réellement besoin de POS multi-branch.

### 5.2 — Diff exact (option 1)

Fichier : `resources/js/components/admin/pos/PosComponent.vue`

**Bloc à modifier** : `mounted()` lignes 1434-1438.

```diff
         this.itemCategories();
         const bootstrapBranchId = this.authBranchId();
         if (bootstrapBranchId) {
             this.applyPosBranchScope(bootstrapBranchId);
+            this.itemList();
+        } else {
+            // [CV1-POS-AVAILABILITY-LIVE-001] Aucun branch_id côté auth → ne JAMAIS fetcher
+            // un catalogue POS sans branch scope. La projection availability per-branch
+            // (item_branch_availability) ne peut s'appliquer qu'avec branch_id côté requête
+            // (cf ItemService::applyBranchAvailabilityOverlay early-return $branchId<1).
+            // On attend defaultAccess/show ; à défaut le composant restera vide jusqu'à
+            // sélection explicite (UX heal cycle suivant).
+            this.posItemsFetchPending = false;
         }
-        this.itemList();
         this.loadKioskCashOrders();
```

**Note BLUE** : ne PAS écrire `this.loading.isActive = false` dans le `else` — la ligne 1446 juste en-dessous (`this.loading.isActive = true`) l'override immédiatement (overlay defaultAccess fetch). Garder uniquement `posItemsFetchPending = false`.

Effet : pour admin sans branch, le 1er `itemList()` n'est plus émis → pas de réponse API non-overlay polluant le store. Le 2e `itemList()` (T9) ne se déclenche pas non plus puisque defaultAccess vide. Le catalogue reste vide tant que branche pas définie — c'est UX dégradée mais **correcte** : un admin global ne devrait pas pouvoir vendre depuis POS sans avoir choisi une branche.

### 5.3 — Sentinel backend complémentaire (option 2 minimum-viable)

Fichier : `app/Http/Controllers/Admin/ItemController.php`, méthode `index()`, après ligne 51.

```diff
         if ($branchId !== null) {
             $this->authorizeBranchScope($request, $branchId);
         }
+
+        // [CV1-POS-AVAILABILITY-LIVE-001] surface=pos sans branch_id ⇒ overlay
+        // availability inopérant ; refuser la requête plutôt que projeter is_available
+        // global (false-positive cliquabilité tile = perte argent + 422 au checkout).
+        // Restreint aux callers ayant la perm `pos` pour éviter de casser tooling admin
+        // (ex : éditeur catalogue filtré "POS-visible") qui appellerait surface=pos sans branch.
+        $surface = strtolower(trim((string) $request->get('surface', '')));
+        if ($surface === 'pos'
+            && ($branchId === null || $branchId < 1)
+            && $request->user() && $request->user()->can('pos')) {
+            return response(['status' => false, 'message' => 'POS catalog requires branch_id'], 422);
+        }
```

Cette garde **complète** le fix SPA : si une autre surface (kiosk standalone, future page admin) appelait `surface=pos` sans branch via un compte ayant `pos`, on refuse côté serveur plutôt que de mentir.

**Pré-requis BLUE avant activation §5.3** : `grep -rn "surface=pos" --include="*.{js,vue,php}"` pour identifier callers non-POS qui passeraient `surface=pos` sans `branch_id`. Si trouvés et légitimes (tooling admin, dashboards), narrower la guard ou changer leur appel.

---

## 6. Spec validation Playwright — `tests/e2e/cv1-pos-availability-live-validation-2026-05-08.spec.js`

### 6.1 — Squelette

```javascript
// CV1-POS-AVAILABILITY-LIVE-001 validation runtime
// Cible : prouver que le fix SPA empêche la tile cliquable quand item OOS,
// quel que soit le profil utilisateur (admin global ou caissier branche).

const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('./helpers/login');
const BASE = process.env.E2E_BASE_URL || 'http://localhost:8000';

async function apiLogin(request, email, password) {
  const res = await request.post(`${BASE}/api/auth/login`, {
    data: { email, password },
    headers: { 'x-api-key': process.env.E2E_API_KEY || '' },
  });
  return (await res.json()).token;
}

async function toggleAvailability(request, token, itemId, branchId, isAvailable, reason) {
  return request.post(`${BASE}/api/admin/menu/availability/toggle`, {
    headers: { Authorization: `Bearer ${token}`, 'x-api-key': process.env.E2E_API_KEY || '' },
    data: { item_id: itemId, branch_id: branchId, is_available: isAvailable, reason },
  });
}

test.describe('CV1-POS-AVAILABILITY-LIVE-001 — fix validation', () => {
  test('CV1-A — POS cashier (pos@lecayenne.fr branch=1) reflète is_available=false après reload', async ({ page, request }) => {
    const token = await apiLogin(request, 'admin@lecayenne.fr', '123456');

    // Précondition : item 363 dispo
    await toggleAvailability(request, token, 363, 1, true, null);

    // Login REAL cashier (branch_id=1 hardwired in seed)
    await loginAsPosOperator(page, 'pos@lecayenne.fr', '123456');

    // Capture URL du fetch /api/admin/item
    const itemListUrls = [];
    page.on('request', (req) => {
      const url = req.url();
      if (/\/admin\/item(\?|$)/.test(url) && !/\/admin\/item\//.test(url.replace(/\?.*$/, ''))) {
        itemListUrls.push(url);
      }
    });

    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);

    // ASSERTION CLÉ : la requête doit contenir branch_id=1
    expect(itemListUrls.length).toBeGreaterThan(0);
    expect(itemListUrls.some((u) => /[?&]branch_id=1(&|$)/.test(u))).toBe(true);

    // Toggle OFF + reload (cas reload après mutation)
    await toggleAvailability(request, token, 363, 1, false, 'manual_admin');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);

    // DOM doit refléter is-unavailable
    const tile = await page.evaluate(() => {
      const buttons = Array.from(document.querySelectorAll('button.pos-v5-tile, .pos-item-tile'));
      const matched = buttons.find((b) => /tacos\s*m/i.test(b.textContent || ''));
      if (!matched) return null;
      return {
        disabled: matched.hasAttribute('disabled'),
        ariaDisabled: matched.getAttribute('aria-disabled'),
        hasUnavailable: matched.classList.contains('is-unavailable'),
        has86Badge: !!matched.querySelector('.pos-item-86-badge'),
      };
    });
    expect(tile).not.toBeNull();
    expect(tile.hasUnavailable).toBe(true);
    expect(tile.disabled).toBe(true);
    expect(tile.ariaDisabled).toBe('true');
    expect(tile.has86Badge).toBe(true);

    // Cleanup
    await toggleAvailability(request, token, 363, 1, true, null);
  });

  test('CV1-B — Admin global sans branch (admin@lecayenne.fr branch=0) ne fetch PAS /admin/item au mount', async ({ page }) => {
    await loginAsPosOperator(page, 'admin@lecayenne.fr', '123456');

    const itemListUrls = [];
    page.on('request', (req) => {
      const url = req.url();
      if (/\/admin\/item\?/.test(url)) itemListUrls.push(url);
    });

    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);

    // Acceptance : soit aucun fetch, soit fetch DOIT inclure branch_id (jamais sans).
    // Le fix SPA option (1) supprime le fetch ; option (2) backend renvoie 422.
    if (itemListUrls.length > 0) {
      expect(itemListUrls.every((u) => /[?&]branch_id=\d+/.test(u))).toBe(true);
    }
  });

  test('CV1-C — Backend refuse surface=pos sans branch_id (option 2)', async ({ request }) => {
    const token = await apiLogin(request, 'admin@lecayenne.fr', '123456');
    const res = await request.get(`${BASE}/api/admin/item?surface=pos`, {
      headers: { Authorization: `Bearer ${token}`, 'x-api-key': process.env.E2E_API_KEY || '' },
    });
    expect(res.status()).toBe(422);
  });
});
```

### 6.2 — Résultats attendus pré-fix vs post-fix

| Test | Pré-fix | Post-fix |
|---|---|---|
| CV1-A | tile reste cliquable, `hasUnavailable=false` → **FAIL** | tile rendue OOS → **PASS** |
| CV1-B | URL `?paginate=0&...&surface=pos` (no branch_id) → **FAIL** | aucun fetch (option 1) ou avec branch_id → **PASS** |
| CV1-C | 200 OK avec is_available global → **FAIL** | 422 → **PASS** (sentinel option 2) |

---

## 7. Sentinel anti-régression

Fichier : `tests/Feature/Catalog/PosCatalogBranchScopeSentinelTest.php` (PHPUnit Feature).

```php
<?php

namespace Tests\Feature\Catalog;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * [CV1-POS-AVAILABILITY-LIVE-001] Sentinel anti-régression.
 *
 * Garde la triple invariante :
 *   1. POS surface SANS branch_id ⇒ refus serveur (422) — pas de leak global is_available
 *   2. Cashier `pos` sans `items_show` ⇒ branch forcée même si SPA omet le param
 *   3. Réponse contient bien `is_available` reflétant `item_branch_availability` quand branch_id fourni
 */
class PosCatalogBranchScopeSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_surface_without_branch_id_returns_422_for_admin_user(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('admin'); // can items_show + pos

        $this->actingAs($admin)
            ->getJson('/api/admin/item?surface=pos')
            ->assertStatus(422);
    }

    public function test_pos_cashier_gets_branch_forced_even_without_param(): void
    {
        $cashier = User::factory()->create(['branch_id' => 1]);
        $cashier->assignRole('pos_operator'); // can pos, !can items_show

        $this->actingAs($cashier)
            ->getJson('/api/admin/item?surface=pos') // pas de branch_id
            ->assertStatus(200); // forcePosRuntimeBranchScope inject branch_id=1
    }

    public function test_branch_overlay_projects_item_branch_availability(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('admin');

        // Crée item global available + override branch=1 unavailable
        $item = \App\Models\Item::factory()->create(['is_available' => true]);
        \App\Models\ItemBranchAvailability::create([
            'item_id'  => $item->id,
            'branch_id'=> 1,
            'is_available' => false,
            'unavailable_reason' => 'manual_admin',
        ]);

        $resp = $this->actingAs($admin)
            ->getJson('/api/admin/item?branch_id=1')
            ->assertStatus(200);

        $row = collect($resp->json('data'))->firstWhere('id', $item->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['is_available']);
        $this->assertSame('manual_admin', $row['availability_reason']);
    }
}
```

Sentinel SPA additionnelle (Vitest) : `tests/unit/store/item-list-branch-scope.spec.js` à écrire (s'assure que `dispatch('item/lists', { branch_id: null })` ne strip pas → URL doit recevoir branch_id ou erreur).

---

## 8. Risques résiduels & follow-ups

1. **UX admin** : option (1) laisse le catalogue vide pour admin global. Heal cycle suivant nécessaire : ajouter sélecteur de branche dans le header POS si role.admin. Spec Codex à rédiger.
2. **Autres surfaces** : kiosk standalone et OSS partagent la même logique de fetch. Vérifier que `kioskMenu`/`tableOrder` stores n'ont pas la même classe de bug. Hors scope CV1-POS-AVAILABILITY-LIVE-001.
3. **Cache HTTP côté axios** : aucune Cache-Control suspecte vue dans les headers résiduels du curl post-mortem. Pas d'action requise.
4. **branch switch en cours de session** : si admin change de branche via UI, `applyPosBranchScope` re-fire et `itemList()` re-fetch. Comportement existant **inchangé** par le fix.

---

## 9. Décision & handoff

- **Verdict** : `block`/`heal` (au sens CLAUDE.md §8). Le bug est P1 confirmé, fix INLINE-EDIT-EXCEPTION compliant disponible.
- **Reclassement RED-R3 P1** : le défaut affleure UNIQUEMENT quand `auth.authBranchId === 0 && pas de DefaultAccess(branch_id)` (cf §3.4). Sessions caissier réelles (`pos@lecayenne.fr`) **non affectées** post-reload — le `forcePosRuntimeBranchScope` les protège. Severity reste P1 car attaque surface admin sur POS = vente possible OOS.
- **Handoff** : BLUE orchestrator applique §5.2 (diff SPA) + §5.3 (sentinel backend, après pré-requis grep callers) + spec §6 + sentinel §7. Aucune partie du fix ne touche les frozen-zones (POS Vanilla wizard `pos-v4-item-wizard-modal` non modifié, design tile inchangé).
- **Étape 0 BLUE — runtime verify avant fix** : run CV1-A en mode `--debug` (ou `page.pause()`), capturer URL réelle de `/api/admin/item` au reload, attacher au cycle BLUE. Si URL contient `branch_id` malgré l'inférence §3.3, **re-investiguer** avant d'appliquer §5.2. Le fix dépend de l'absence de `branch_id` dans l'URL.
- **Evidence à capturer post-fix** :
  - run `npm run test:e2e -- tests/e2e/cv1-pos-availability-live-validation-2026-05-08.spec.js` → 3 tests verts.
  - run `php artisan test --filter PosCatalogBranchScopeSentinelTest` → 3 tests verts.
  - dump `dom-snapshots.json` post-fix (label `cv1-after-reload-cashier`) montrant `is-unavailable: true`.
- **Trust but verify résiduel** : §3.3 inférence URL/réseau à valider runtime via `page.on('request')` lors du run de la spec §6 (CV1-A & CV1-B la captent explicitement). Si URL captée contredit l'inférence, BLUE doit re-investiguer avant fix.

---

**Auteur** : Claude (GSTACK Eng Manager + Implementer)
**Conformité CLAUDE.md** : §3 (vision > vitesse), §7 (jugement strict — ne pas se contenter de "passing"), §11 (evidence file:line + DOM probe + curl), §10 (anti-drift — frozen-zones non touchées).
