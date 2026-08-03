# EXECUTE V10 #2 — P11_KIOSK_OFFLINE_QUEUE_TEST_EXTEND

TASK_ID: P11_KIOSK_OFFLINE_QUEUE_TEST_EXTEND
WAVE: V10 salve V (couverture résilience kiosk, no gate)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE: PLAN_POST_VERIFY V4 — option V recommandée

---

## Contexte

`tests/js/kioskOfflineQueue.spec.js` couvre actuellement (5 tests) :
- `saveOrder` + `getPendingCount`
- mutex `_syncInFlight` (Promise.all dédupliqué)
- analytics observability (queued, replayed, abandoned après 10 fails)

Manque (lacunes critiques pour résilience kiosk en prod) :
1. **Reconnexion partielle** : `syncQueue` avec 3 entrées ; mock postFn qui réussit la 1ère et échoue les 2 autres → vérifier que `synced=1`, `failed=2`, `getPendingCount()=2` après run
2. **`getAbandonedCount`** : entre 2 et 10 attempts → 0 abandoned. Au 10e attempt → `getAbandonedCount() === 1`
3. **Idempotency-Key replay** : vérifier que le `localKey` original est passé en header `X-Idempotency-Key` lors du replay (preuve `[FIX-54-3]` / `[AUDIT-P0]`)
4. **Pruning auto > 24h** : créer une entrée synced + savedAt vieille de 25h → après `syncQueue` → entrée prunée (queue length diminue)

Ces tests valident des comportements documentés dans le code source (commentaires `[FIX-54-3]`, `[AUDIT-P0]`, "Prune synced entries older than 24h") qui ne sont actuellement pas couverts.

---

## Goal

Étendre `tests/js/kioskOfflineQueue.spec.js` avec **4 nouveaux `it()`** (groupés dans un nouveau `describe('[V10 #2] resilience hardening')` à la fin du fichier).

---

## Scope

| Fichier | Action |
|---|---|
| `tests/js/kioskOfflineQueue.spec.js` | EDIT — ajouter `describe([V10 #2])` avec 4 it() à la fin, AVANT le `});` de fermeture du top describe |

**SUBSYSTEMS_TOUCHED**: 1 spec Vitest.
**SUBSYSTEMS_OFF_LIMITS**: TOUT le code applicatif. Pas de modif de `resources/js/helpers/kioskOfflineQueue.js`. Pas de modif des 5 tests existants.
**INVARIANTS_AT_RISK**: aucun.

---

## Spécification

### Étape 1 — Lire l'existant

Lire `tests/js/kioskOfflineQueue.spec.js` (94 lignes) et `resources/js/helpers/kioskOfflineQueue.js` pour comprendre l'API précise.

### Étape 2 — Insérer le nouveau `describe`

À l'intérieur du top `describe('kioskOfflineQueue', ...)`, APRÈS le `describe('[T14b] analytics observability')` mais AVANT le `});` final :

```javascript
    // ─── [V10 #2] Resilience hardening — partial reconnect, abandoned count, idempotency replay, pruning ───
    describe('[V10 #2] resilience hardening', () => {
        it('partial reconnect: 1 success + 2 failures leaves 2 pending', async () => {
            saveOrder({ items: [{ item_id: 1 }] }, 'partial_a');
            saveOrder({ items: [{ item_id: 2 }] }, 'partial_b');
            saveOrder({ items: [{ item_id: 3 }] }, 'partial_c');

            let callIdx = 0;
            const flakyPost = vi.fn(async () => {
                callIdx += 1;
                if (callIdx === 1) return { status: 201 };
                const err = new Error('network');
                err.code = 'ECONNREFUSED';
                throw err;
            });

            const result = await syncQueue(flakyPost);
            expect(result.synced).toBe(1);
            expect(result.failed).toBe(2);
            expect(getPendingCount()).toBe(2);
            // The first entry should be marked synced and the others retry-eligible.
            expect(flakyPost).toHaveBeenCalledTimes(3);
        });

        it('getAbandonedCount stays 0 below 10 attempts then increments at 10', async () => {
            saveOrder({ items: [{ item_id: 99 }] }, 'abandon_check');

            const failingPost = vi.fn(async () => {
                const err = new Error('network');
                err.code = 'ECONNREFUSED';
                throw err;
            });

            // 9 attempts → still 0 abandoned
            for (let i = 0; i < 9; i++) {
                await syncQueue(failingPost);
            }
            expect(getAbandonedCount()).toBe(0);

            // 10th attempt → entry is marked abandoned
            await syncQueue(failingPost);
            expect(getAbandonedCount()).toBe(1);
        });

        it('replay reuses original idempotency key as X-Idempotency-Key header', async () => {
            const ORIGINAL_KEY = 'idemp_original_xyz';
            saveOrder({ items: [{ item_id: 7 }] }, ORIGINAL_KEY);

            const capturedConfigs = [];
            const captureFn = vi.fn(async (url, payload, config) => {
                capturedConfigs.push(config);
                return { status: 201 };
            });

            await syncQueue(captureFn);

            expect(captureFn).toHaveBeenCalledTimes(1);
            expect(capturedConfigs[0]).toBeDefined();
            expect(capturedConfigs[0].headers).toBeDefined();
            expect(capturedConfigs[0].headers['X-Idempotency-Key']).toBe(ORIGINAL_KEY);
        });

        it('synced entries older than 24h are pruned on next syncQueue run', async () => {
            // Manually craft a stale entry by writing directly to localStorage
            // (mirrors what kioskOfflineQueue.js _save() does internally).
            const QUEUE_KEY = 'kiosk_offline_queue_v1';
            const TWENTY_FIVE_HOURS_AGO = Date.now() - (25 * 60 * 60 * 1000);
            const staleEntry = {
                localKey: 'stale_synced',
                payload: { items: [] },
                savedAt: TWENTY_FIVE_HOURS_AGO,
                attempts: 1,
                synced: true,
                syncedAt: TWENTY_FIVE_HOURS_AGO + 1000,
            };
            const freshEntry = {
                localKey: 'fresh_pending',
                payload: { items: [{ item_id: 42 }] },
                savedAt: Date.now(),
                attempts: 0,
                synced: false,
            };
            localStorage.setItem(QUEUE_KEY, JSON.stringify([staleEntry, freshEntry]));

            // Trigger a syncQueue run; the fresh pending entry will be POSTed once.
            const okPost = vi.fn(async () => ({ status: 201 }));
            await syncQueue(okPost);

            // After the run: stale (synced + savedAt > 24h) is pruned, fresh is now synced.
            const finalQueue = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
            expect(finalQueue.length).toBe(1);
            expect(finalQueue[0].localKey).toBe('fresh_pending');
            expect(finalQueue[0].synced).toBe(true);
        });
    });
```

### Étape 3 — Importer `getAbandonedCount`

Le top du fichier importe déjà `clearQueue, getPendingCount, saveOrder, syncQueue`. **Ajouter** `getAbandonedCount` à la liste :

Avant :
```javascript
import {
  clearQueue,
  getPendingCount,
  saveOrder,
  syncQueue,
} from '../../resources/js/helpers/kioskOfflineQueue';
```

Après :
```javascript
import {
  clearQueue,
  getAbandonedCount,
  getPendingCount,
  saveOrder,
  syncQueue,
} from '../../resources/js/helpers/kioskOfflineQueue';
```

### Étape 4 — Run

```bash
npx vitest run tests/js/kioskOfflineQueue.spec.js
```

Doit afficher **9 tests passed** (5 existants + 4 nouveaux).

Si un test échoue → analyser. Si la fonction `syncQueue` n'a pas le comportement attendu (ex: pruning ne marche pas exactement à 24h00:01) → ajuster le test pour refléter la réalité observée, NE PAS modifier le helper.

---

## VALIDATE

1. `npx vitest run tests/js/kioskOfflineQueue.spec.js` → 9/9 passed
2. Diff `tests/js/kioskOfflineQueue.spec.js` : ajout import `getAbandonedCount` + nouveau `describe([V10 #2])` avec 4 `it()`
3. Aucun autre fichier modifié
4. `resources/js/helpers/kioskOfflineQueue.js` intact (`git diff --stat` = 0)

---

## REPORT_FILE

`reports/execution/RUN_P11_KIOSK_OFFLINE_QUEUE_TEST_EXTEND_2026-04-20.md` — diff spec + sortie vitest.

---

## SCOPE_PRESSURE

- ❌ NE PAS modifier `resources/js/helpers/kioskOfflineQueue.js`
- ❌ NE PAS modifier les 5 tests existants ni le `describe([T14b])`
- ❌ NE PAS modifier d'autres specs
- ❌ Pas de `git add/commit`
- ⚠️ Si la fonction `syncQueue` itère sur le queue par référence partagée et qu'un test fait crash le runtime à cause d'un side-effect localStorage, isoler avec `clearQueue()` au début de chaque it (déjà couvert par `beforeEach`)
- ⚠️ Si un test échoue car le helper a un comportement inattendu (ex: pruning à 24h pile, pas 25h), ADAPTER le test (utiliser 26h, 30h) plutôt que modifier le helper. Documenter dans le RUN report.
