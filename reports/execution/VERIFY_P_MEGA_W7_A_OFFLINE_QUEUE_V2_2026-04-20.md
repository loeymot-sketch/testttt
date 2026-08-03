# VERIFY P-MEGA-W7.A — Offline Queue v2 200% (Phase A.3 du cycle W7)

**Date** : 2026-04-20
**Mode** : READONLY
**HEAD audited** : `f1e0d6119`
**Subagent** : explore very thorough
**Verdict global** : **DEGRADED** (corrections urgentes requises avant W7.B)

---

## 0. Résumé exécutif

| Critère | Verdict |
|---------|---------|
| Scope contract (off-limits non touchés) | OK |
| Bugs invisibles trouvés | 9 (1 ÉLEVÉ, 4 MOYENS, 4 BAS) |
| Tests sentinelles qualité réelle | DEGRADED |
| Cohérence runtime E2E | DRIFT |
| Documentation report | INCOMPLET |
| Linter | OK |

---

## 1. Vérifications Git (readonly)

- **HEAD** : `f1e0d6119` — `[P-MEGA-W7-A] Offline queue v2 — IDB + backoff jitter + stale invalidation + conflict UX`
- **Diff `9c8f9e202..f1e0d6119`** : 11 fichiers (`package*.json`, rapport RUN, `kioskOfflineQueue*.js`, `kioskCart.js`, `KioskAppComponent.vue`, `KioskOfflineConflictModalComponent.vue`, 3 specs JS).
- **Scope contract** : aucun chemin "off-limits" listé n'apparaît dans ce diff → **pas de BREACH**.

---

## 2. Bugs invisibles (au-delà des 21 tests Vitest)

### [B4] ÉLEVÉ — `branchId` n'entre pas dans le filtre de `markStaleItems`

**Fichier** : `resources/js/helpers/kioskOfflineQueue.js:317-344`

`markStaleItems({ itemId, branchId })` ne filtre que par `itemId` ; `branchId` est seulement passé aux analytics. Risque : marquer stale des files contenant le même `item_id` sans preuve de branche. Les payloads kiosk n'incluent pas `branch_id` dans la queue → filtrage impossible côté queue sans extension de schéma.

**Mitigation** :
- Hypothèse "1 kiosk = 1 branche" → ignorer events des autres branches AU NIVEAU de `KioskAppComponent.vue`
- OU stocker `branch_id` dans entry `kioskOfflineQueue` (extension schema)

### [B2] MOYEN — Backoff dès `attempts === 1`

**Fichier** : `resources/js/helpers/kioskOfflineQueue.js:225-234`, `413-421`, `278-291`

`lastAttemptAt` est posé à l'enqueue (`saveOrder` L289). Le premier `syncQueue` peut skip ~800–1200 ms même sans échec réseau, car `_computeRetryDelay(1)` = 800-1200ms.

**Mitigation** : distinguer `savedAt` / `lastFailedAt` ; ne pas appliquer le délai exponentiel avant la **première** tentative réseau.

### [B3] MOYEN — Verrou IDB TTL 60s sans heartbeat

**Fichier** : `resources/js/helpers/kioskOfflineQueue.js:236-255`, `384-467`

Si `syncQueue` dépasse 60s (réseau lent + 50 entries) → second onglet peut acquérir le lock → 2 syncs parallèles → doublons (limités par idempotence serveur mais quand même).

**Mitigation** : refresh du lock pendant le sync (heartbeat à 30s d'intervalle) OU TTL >> p99 latence.

### [B7] MOYEN — Migration sans fusion v1→v2 si v2 existe déjà

**Fichier** : `resources/js/helpers/kioskOfflineQueue.js:144-148`, `kioskOfflineQueueMigration.spec.js:60-91`

Si IDB v2 existe déjà ET legacy v1 présent dans `localStorage` : purge v1 **sans fusion** avec la file v2 → entries v1 orphelines perdues. Le test `kioskOfflineQueueMigration.spec.js` accepte ce comportement.

**Mitigation** : documenter explicitement OU fusionner v1+v2 avant purge.

### [B6] MOYEN — `QuotaExceededError` : commande seulement en RAM

**Fichier** : `resources/js/helpers/kioskOfflineQueue.js:202-223`, `278-294`

Sur `QuotaExceededError`, la commande reste en RAM mais n'est pas persistée. Rechargement = perte de la commande si jamais flushée vers IDB.

**Mitigation** : émettre toast `error` non-dismissible jusqu'à action utilisateur explicite.

### [B10] MOYEN — Pas de debounce sur ItemAvailabilityChanged

**Fichier** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue:446-462`

Chaque event qui marque la queue déclenche un toast complet. Si rafale (10 events en 5s) → 10 toasts → spam UX.

**Mitigation** : debounce 500ms-1s sur l'émission des toasts.

### [B11] BAS — Modal conflict ne refetch pas

**Fichier** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue:479-487`

Ouverture du modal n'appelle pas `getStaleEntries()` frais → données = snapshot au dernier refresh ; onglet distant peut mettre à jour via Broadcast sans rafraîchir le modal.

**Mitigation** : `await getStaleEntries()` au mount du modal.

### [B9] BAS — `BroadcastChannel` jamais `close()`

**Fichier** : `resources/js/helpers/kioskOfflineQueue.js:46-67`

`BroadcastChannel` module-scope jamais `close()` au cycle de vie de l'app — fuite légère par onglet (pas critique en kiosk single-tab mais pollue sur dev/test).

**Mitigation** : hook `beforeunload` ou API `dispose` du module.

### [E2E] BAS — Texte toast différent du scénario demandé

**Fichier** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue:457-461`

Spec demandait : "Une commande en file d'attente contient un produit indisponible. Vérifiez avant validation." + bouton "Voir".
Code actuel : libellé différent, pas de bouton "Voir" explicite.

**Mitigation** : aligner libellé + ajouter action explicite.

---

## 3. Tests sentinelles qualité réelle : DEGRADED

| Issue | Fichier:ligne |
|-------|---------------|
| Backoff "réel" : `sleep(1200)` au lieu de `vi.useFakeTimers()` → flaky CI | `kioskOfflineQueueV2.spec.js:133-144` |
| Axe : `color-contrast` désactivé | `kioskOfflineQueueV2.spec.js:71-76` |
| Pas de test Escape explicite sur modal conflit (délégué à `KsModal`) | `KioskOfflineConflictModalComponent.vue` |
| Migration "idempotence" = rejet legacy si v2 présent (pas fusion) | `kioskOfflineQueueMigration.spec.js:60-91` |

---

## 4. Cohérence runtime E2E : DRIFT

Flux global cohérent (Echo → panier + queue stale → CTA + modal) MAIS :

- Délai avant 1er POST (B2)
- Pas de garde branche sur la queue (B4)
- Toasts non bornés (B10)
- Copie UX différente du scénario cible (E2E)

---

## 5. Documentation report : INCOMPLET

**Fichier** : `reports/execution/RUN_P_MEGA_W7_A2_OFFLINE_QUEUE_V2_EXECUTE_2026-04-20.md`

- Contient `EXECUTE_DELEGATION` ✅
- Contient `bug_signatures` ✅
- Liste risques résiduels ✅
- **Manquant** : delta LOC aligné avec `git diff --stat`
- **Ambigu** : ligne 35 "git log … 9c8f9e202" vs livraison `f1e0d6119`

---

## 6. Linter : OK

- Diagnostics IDE sur les fichiers modifiés : aucun problème
- `npm run lint` non exécuté ici (mode readonly)

---

## 7. Recommandations correctives URGENTES (avant W7.B) : 4

1. **[B4]** Filtrer stale par branche (stocker `branch_id` dans l'entrée de file OU comparer au payload une fois exposé) ou documenter explicitement l'hypothèse "un kiosk = une branche" et ignorer les events globaux non pertinents au niveau de `KioskAppComponent`.

2. **[B2]** Ne pas appliquer le délai exponentiel avant la **première** tentative réseau (`attempts === 1` et aucun échec POST) ou distinguer `savedAt` / `lastFailedAt`.

3. **[B3]** Heartbeat du lock IDB pendant `syncQueue` (refresh à 30s d'intervalle) OU TTL >> p99 latence + refresh.

4. **[B10]** Debounce / agrégation des toasts sur rafale `ItemAvailabilityChanged` (500ms-1s).

## 8. Recommandations différées (cycle ultérieur) : 5

1. **[B9]** `close()` propre du `BroadcastChannel` (ex. hook `beforeunload` / API `dispose` du module).
2. **[B11]** Refetch `getStaleEntries()` à l'ouverture du modal.
3. **[B6]** Toast non-dismissible sur QuotaExceededError jusqu'à action utilisateur.
4. **[B7]** Documenter la non-fusion v1+v2 si v2 existe déjà OU fusionner avant purge.
5. **Tests** : remplacer `sleep` par fake timers dans tests backoff ; activer au moins une règle contraste ou snapshot dédié.

---

## 9. Extraits utiles

Backoff + cap **après** jitter :
```javascript
function _computeRetryDelay(attempts, randomValue = Math.random()) {
    const baseDelay = 1000 * Math.pow(2, Math.max(0, attempts - 1));
    const jitterMultiplier = 1 + ((Math.max(0, Math.min(1, randomValue)) * 0.4) - 0.2);
    return Math.min(MAX_BACKOFF_MS, Math.round(baseDelay * jitterMultiplier));
}
```

`markStaleItems` : `branchId` uniquement dans `_track`, pas dans le filtre :
```javascript
export async function markStaleItems({ itemId, branchId = null } = {}) {
    await _ensureLoaded();
    let updatedEntries = 0;
    let markedItems = 0;
    const normalizedItemId = parseInt(itemId, 10);

    _queueCache = _queueCache.map((entry) => {
        if (entry.abandoned || !_entryContainsItem(entry, normalizedItemId)) {
            return entry;
        }
        // ...
    });
```

Cas v2 existant + legacy : purge sans merge :
```javascript
if (Array.isArray(existingV2)) {
    if (legacyEntries.length > 0) {
        _clearLegacyQueue();
    }
    return _mergeQueue(_queueCache, existingV2);
}
```

`saveOrder` n'attend pas le bootstrap :
```javascript
export function saveOrder(payload, originalKey = null) {
    _ensureLoaded();
    const savedAt = now();
    const localKey = originalKey || `offline_${savedAt}_${Math.random().toString(36).slice(2, 8)}`;
    _queueCache = _mergeQueue(_queueCache, [{
        localKey,
        payload,
        savedAt,
        attempts: 1,
        lastAttemptAt: savedAt,  // <-- BUG B2: triggers backoff immediately
```
