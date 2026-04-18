# RUN — Section 5 : Menu Borne ↔ Menu POS (SSOT + projections)

**Date** : 2026-04-16
**Scope** : implémentation backend de la section 5 de `AUDIT_MASSIF_FR_2026-04-16.md` — un catalogue unique (`items` + `item_categories`) projeté par canal (POS / Kiosk / Web).
**Branch** : `refactor/staff-only-v1`
**Statut** : livré (foundation V1 — admin UI / rewire POS+Kiosk reportés à V1.5).

---

## 1. Contexte

L'audit massif identifie une anti-pattern récurrent : deux catalogues parallèles (un POS, un Kiosk). La section 5 propose un modèle SSOT + projections par canal avec 3 valeurs d'enum (`pos`, `kiosk`, `web`).

État initial :

- `item_branch_availability` + `AvailabilityService` + `ItemAvailabilityChanged` : opérationnels depuis MENU_86 (V1 / Vague 2).
- `visible_on` JSON déjà sur `item_variations` + `item_extras` (sprint antérieur).
- **Manquait** : `channels` JSON sur `items` et `item_categories`, `MenuProjectionService`, `MenuSnapshot`, route admin unifiée, doc contrat.

---

## 2. Livraisons

### 2.1 Migration — `2026_04_16_200000_add_channel_columns_to_items_and_categories`

Ajout de colonnes nullables (valeur par défaut `NULL` = comportement legacy préservé) :

| Table              | Colonnes                                    |
| ------------------ | ------------------------------------------- |
| `items`            | `channels` JSON, `allergen_flags` JSON, `kiosk_emoji` VARCHAR(8) |
| `item_categories`  | `channels` JSON, `kiosk_sort` INT, `pos_sort` INT, `kiosk_label` VARCHAR(255) |

Idempotente (guard `Schema::hasColumn`), rollback propre.

### 2.2 Modèles — helpers de projection

- `App\Models\Item` : fillable + casts + `isVisibleOn(string $channel): bool`
- `App\Models\ItemCategory` : fillable + casts + `isVisibleOn`, `displayNameFor`, `sortFor`

Règle : `channels === NULL` ⇒ visible sur toutes les surfaces (back-compat). Aucun backfill requis.

### 2.3 Services

- `App\Services\Menu\MenuSnapshot` — version monotone par branche, stockée dans `cache` (Redis prod, array tests). Clé `menu:snapshot_version:branch:{id}`, TTL 7 j, `bump()` atomique via INCR.
- `App\Services\Menu\MenuProjectionService::forChannel(string $channel, int $branchId)` — retourne l'enveloppe `{ categories, snapshot_version, branch_id, channel }`, avec filtrage `channels`, résolution d'availability per-branch via `item_branch_availability`, tri channel-aware, override `kiosk_label` + `kiosk_emoji` réservés au canal `kiosk`.

### 2.4 Listener — snapshot freshness

`App\Listeners\BumpMenuSnapshotOnItemAvailabilityChanged` abonné à `ItemAvailabilityChanged` via `EventServiceProvider`. Branch-scoped bump pour un événement de rupture ponctuelle ; bump global (toutes branches actives) pour un événement `fromItem` (édition admin). L'échec du bump est swallowed + log warning (best-effort, n'interrompt pas l'outbox).

### 2.5 API admin

```
GET /api/admin/menu-projection?channel={pos|kiosk|web}&branch_id={int}
```

- Groupe `admin` : `installed`, `apiKey`, `auth:sanctum`, `throttle:admin-mutation`.
- Validation `channel` via `Rule::in(SUPPORTED_CHANNELS)` → 422 sur valeur invalide.
- Read-only, pas de mutation : zero impact sur les flux POS / Kiosk actuels.

### 2.6 Documentation

`docs/MENU_PROJECTIONS.md` — contrat complet : rationale, schéma, enveloppe API, access control matrix, plan de migration V1 → V1.5 (kiosk / POS bascule, admin UI, `price_overrides` temporal).

---

## 3. Tests

| Suite                                                                | Tests | Assertions |
| -------------------------------------------------------------------- | ----- | ---------- |
| `tests/Unit/Services/Menu/MenuSnapshotTest`                          | 6     | 12         |
| `tests/Feature/Services/Menu/MenuProjectionServiceTest`              | 13    | 30         |
| `tests/Feature/Http/Admin/MenuProjectionControllerTest`              | 5     | 26         |
| `tests/Feature/Menu/BumpMenuSnapshotListenerTest`                    | 2     | 4          |
| **Total section 5**                                                  | **26**| **72**     |

Cas couverts (feature projection) :

- Rejet `channel` non supporté (422 + InvalidArgumentException).
- Enveloppe stable (`categories`, `snapshot_version`, `branch_id`, `channel`).
- `channels === NULL` ⇒ visible partout.
- Item restreint `[kiosk]` masqué sur POS ; catégorie `[pos]` masquée sur Kiosk.
- `kiosk_label` actif uniquement sur `channel=kiosk`.
- Tri channel-aware : `kiosk_sort` / `pos_sort` avec fallback `sort`.
- `item_branch_availability` : row présente false → `available=false` + `unavailable_reason` ; row absente → `available=true`.
- Isolation branche : une row d'une autre branche ne leak pas sur la branche requêtée.
- `kiosk_emoji` exposé uniquement sur kiosk.
- `allergen_flags` pass-through.
- `snapshot_version` monotonique après `bump`.

Cas HTTP :

- 401 sans auth.
- 422 `channel` manquant.
- 422 `channel` invalide.
- Kiosk : `kiosk_label` + `emoji` présents.
- POS : `name` canonique, `emoji` absent.

Listener :

- Branch-scoped bump uniquement sur la branche visée.
- `fromItem` (global) bump toutes les branches actives.

---

## 4. Régression

```
PHPUnit 9.6.29 : 409 tests, 839 assertions, 0 failure.
```

Full suite y compris Vague 1/2/3/4 + section 5. Aucun linter error sur les fichiers touchés.

Side note — 2 tests JS pré-existants (`tests/js/KioskWizard.spec.js`, heuristique burger → sauce) échouent **avant et après** mes changements (vérifié par `git stash` round-trip). Hors scope section 5.

---

## 5. Garanties V1

1. **Back-compat stricte** : toutes les colonnes nullables, aucun backfill, aucun controller existant modifié.
2. **Branch isolation** : availability résolue par la branche requêtée uniquement — no cross-branch leak.
3. **Access control** : endpoint sur le groupe `admin` (sanctum + apiKey + throttle admin-mutation). POS Operator / Chef / Customer ne peuvent pas l'appeler.
4. **Idempotency** : service read-only, controller aussi — rejouer la requête ne crée pas d'effet de bord.
5. **Observability** : `CorrelationIdMiddleware` propagé de facto, bump failures loggués en `warning` sans bloquer l'outbox.
6. **Best-effort cache** : un store cache down ne casse pas le endpoint (fallback `current()` re-init à 1).

---

## 6. Risques & points de vigilance

| Risque | Mitigation |
| ------ | ---------- |
| Clients qui cachent la projection et ignorent `snapshot_version` | Doc explicite ; à re-vérifier lors du rewire kiosk/POS en V1.5 |
| `MenuSnapshot` en array store (tests) — Redis en prod | Listener log `warning` si INCR échoue ; pas de blocking |
| `channels` JSON vs MariaDB sans support JSON natif | Migration utilise `$table->json()` — Laravel fait le cast VARCHAR fallback sur MySQL < 5.7 ; à valider en prod si MariaDB ≤ 10.2 |
| Admin UI non livrée | Roadmap V1.5 (tabs POS/BORNE/WEB) ; V1 ship avec le backend seul |

---

## 7. Plan V1.5 (hors scope ce run)

1. Frontend admin : onglets de catalogue `[POS][BORNE][WEB]` + toggle channel par ligne + "Rupture rapide" floating button.
2. Rewire `resources/js/kiosk/*` → `/api/admin/menu-projection?channel=kiosk`.
3. Rewire `resources/js/pos/*` → `/api/admin/menu-projection?channel=pos`.
4. Table `price_overrides(channel, item_id, price, start_at, end_at)` pour happy hour.
5. Heartbeat kiosk : client poll `snapshot_version` toutes les 30 s, re-fetch si diverge.

---

## 8. Fichiers touchés

**Créés** :

- `database/migrations/2026_04_16_200000_add_channel_columns_to_items_and_categories.php`
- `app/Services/Menu/MenuSnapshot.php`
- `app/Services/Menu/MenuProjectionService.php`
- `app/Listeners/BumpMenuSnapshotOnItemAvailabilityChanged.php`
- `app/Http/Controllers/Admin/MenuProjectionController.php`
- `tests/Unit/Services/Menu/MenuSnapshotTest.php`
- `tests/Feature/Services/Menu/MenuProjectionServiceTest.php`
- `tests/Feature/Http/Admin/MenuProjectionControllerTest.php`
- `tests/Feature/Menu/BumpMenuSnapshotListenerTest.php`
- `docs/MENU_PROJECTIONS.md`
- `reports/execution/RUN_SECTION5_MENU_SSOT_2026-04-16.md` (ce fichier)

**Modifiés** :

- `app/Models/Item.php` (fillable + casts + helper)
- `app/Models/ItemCategory.php` (fillable + casts + helpers)
- `app/Providers/EventServiceProvider.php` (listener registration)
- `routes/api.php` (route + import)

---

**Next** : section 6 de l'audit (matrice des 12 tâches V1), ou V1.5 rewire kiosk/POS, ou commit groupé section 5.
