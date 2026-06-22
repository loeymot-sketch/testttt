# VERIFY-14 — Sync cross-surface (Pusher, Outbox, EventContract)

**Date :** 2026-04-20  **Origine :** `AUDIT_POS_110_SYNC_CROSS_SURFACE_2026-04-19.md`, `AUDIT_SYNC_BROADCAST_ARCHITECTURE_2026-03-31.md`  **Priorité :** P0  **Mode :** AUDIT-ONLY

## 1. Contexte
Trois surfaces (POS, Kiosk, KDS, OSS) synchronisées via events → outbox → broadcast. Vérifier : pas de dispatch avant commit DB, retries, EventContract respecté, channels privés, no-event-loss en cas de crash worker.

## 2. Sources OBLIGATOIRES
- `app/Domain/EventContract.php`
- `app/Jobs/DispatchDomainEventsJob.php`
- `app/Listeners/PersistOrder*ToOutbox.php`
- `app/Providers/EventServiceProvider.php`
- `routes/channels.php`
- Front Echo / Pusher init
- Tests : `tests/Feature/OutboxTest`, `EventContract*`, `Realtime*`
- Audits : `AUDIT_POS_110_SYNC_CROSS_SURFACE_2026-04-19.md`, `AUDIT_SYNC_BROADCAST_ARCHITECTURE_2026-03-31.md`

## 3. Hypothèses à challenger
- H1 : `event(...)` ou `broadcast(...)` appelé dans une transaction non commitée.
- H2 : Outbox sans index de retry / colonne `processed_at`.
- H3 : Worker queue qui plante perd des events (pas de durable storage).
- H4 : Channel auth callback absent (`Broadcast::channel`).
- H5 : Front Echo se connecte à un canal sans token → fuite cross-branch.
- H6 : EventContract évolutif sans versioning.

## 4. Plan multi-agent
1. **Explore A** : back events + outbox + jobs.
2. **Explore B** : front Echo + listeners Vue.
3. **GeneralPurpose** : trace cycle de vie d'un OrderCreated POS → KDS + matrice failure modes.

## 5. Vérifications obligatoires
- [ ] V1 : Tous les `event(...)`/`dispatch(...)` après commit (tests + audit code).
- [ ] V2 : Outbox a un index `(processed_at, created_at)`.
- [ ] V3 : Retry policy + DLQ documentés.
- [ ] V4 : `routes/channels.php` valide branch + role.
- [ ] V5 : EventContract versionné (champ `version`).
- [ ] V6 : Front Echo unsubscribe au unmount + reconnect logic.
- [ ] V7 : Test E2E "POS commande → KDS apparaît < 2s" présent.

## 6. Critères d'acceptation
- ALL_GREEN si V1–V7 OK.
- WARN si V5 non versionné.
- FAIL si V1 ou V4 cassables.

## 7. Livrables
- `reports/review/VERIFY_14_SYNC_CROSS_SURFACE_2026-04-20.md`

## 8. Suite
- FAIL → `P11_DISPATCH_AFTER_COMMIT_AUDIT`, `P12_CHANNELS_AUTH_TIGHTEN`.

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY.
Lis tasks/verify-2026-04-20/14_VERIFY_SYNC_CROSS_SURFACE.md, applique §4-§7.

OBLIGATIONS: 2 explore parallèles + 1 generalPurpose synthèse + trace cycle de vie + matrice failure modes. 0 code modifié.
Livrable: reports/review/VERIFY_14_SYNC_CROSS_SURFACE_2026-04-20.md
Plan 5 lignes. Conclusion "GLOBAL: ..." + cycles P.
```
