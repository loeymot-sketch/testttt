# Supervisor Heal Wave — 2026-05-30

Mandat owner « orchestre comme supervisor ». Vague de vérification adversariale (5 dimensions,
7 agents read-only + confirm), **calibrée V1 LOCAL Le Cayenne** (single-box, single-branch —
PAS V2 SaaS). Discipline CLAUDE.md : agents auditent en read-only ; moi = synthèse + patches
scope-minimal + gates + frozen-respect. Source : `tasks/wsciq7v2b.output`.

## Décision superviseur — verdicts

| Dimension | Verdict V1 LOCAL | Action |
|---|---|---|
| **ADMIN queue-stall** (parallèle « P0 ») | **RÉFUTÉ** — chaîne outbox detect+recover complète (rescue/monitor/retry-failed + /health/ready 503). État live propre (0 pending / 0 failed5 / 0 stale ; failed_jobs=1 = artefact Stripe webhook, PAS outbox). « 151 failed jobs » non reproductible | aucune (runbook prod déjà documenté) |
| **SECURITY mass-assignment** (parallèle « P1 ») | **P3** — write `branch_id` client-contrôlé par Branch Manager réel, MAIS single-branch = 0 cible cross-branch, 0 escalade, 0 NF525/paiement/perte-donnée. Bloqueur V2-SaaS, pas V1 | backlog V1.0.X (clamp FormRequest) |
| **PERF N+1** (parallèle « P1 ») | **P3** — N+1 réel (46 req cold, pas 16) MAIS derrière cache 60s, borne LAN 45 items ; « 15.5MB » = build dev non-minifié, borne télécharge ~137KB gz | backlog V1.0.X |
| **AUTH hardening** | OI-3 **P2** + BS-3 **P3-dormant** → **HEAL-NOW** ; BS-2 **P3** (kiosk 401 self-heal + poll mitigent la falaise 8h) | ✅ **HEALED** `66f907ff7` |
| **SENTINEL systémique** | premise réfutée (admin-oss n'a pas de sentinelle ; admin-reports i18n déjà retiré) MAIS a **attrapé mon erreur** : phoneDisplay.js droppé à tort (x0 sur le mauvais marqueur) | ✅ **HEALED** `4e88fcf4f` |

## Heals appliqués (vérifiés + testés, 0 frozen)

### `66f907ff7` — AUTH OI-3 + BS-3 (RefreshTokenController, non-frozen)
- **OI-3 (P2)** : rejet du refresh d'un token **expiré** (`$token->expires_at->isPast()` → 401). Avant : `findToken()` ressuscitait un Bearer expiré en token full-TTL frais (~24-48h jusqu'au prune). Le refresh proactif 2h tourne toujours sur un token valide → seul un token réellement expiré est bloqué.
- **BS-3 (P3-dormant)** : préservation du **nom** du token au refresh (`$token->name` au lieu de `'auth_token'` hardcodé). `channels.php` épingle l'auth canal kiosk sur `name === 'kiosk-token'` ; le hardcode aurait cassé l'authz canal d'un token kiosk rafraîchi. No-op pour le staff.
- Tests : `RefreshTokenAbilityPreserveTest` **6/6** (+2 : expired→401 sans rotation ; nom 'kiosk-token' préservé).

### `4e88fcf4f` — SENTINEL phoneDisplay restore (test-only) — correction d'une erreur que J'AI introduite
- Ma correction admin-shell `3c1fa0eb7` avait droppé `phoneDisplay.js` en grepant `sanitizePendingPhone` (x0 = le ré-export auth). Mais l'export **`safePhone`** (helper d'affichage utilisé par MessageList/ProfileEdit/Receipt) EST compilé dans admin-shell (x7), admin-kds (x15), admin-reports (x3). phoneDisplay.js est donc une vraie dépendance lazy-chunk → restaurée au groupe transitive (auth.js + BackendNavbar restent retirés — vraiment x0/entry-resident). mtime phoneDisplay 05-25 < admin-shell 05-29 → vert.

## Backlog V1.0.X (P3, non-bloquants V1 LOCAL — calibrés, pas droppés)
- **SEC-BRANCH-MA-01/02** : clamp `branch_id` non-frozen (FormRequest `$request->only`) sur /admin/{waiter,chef,delivery-boy,employee}. MA-03 (BranchScope exempte User) = frozen + V2 → owner-gate V2.
- **PERF-01** : dédup des requêtes stock par-item dans ComposerProfileProjection (vrai N+1 derrière cache).
- **BS-2** : étendre le refresh proactif 2h à l'entrée kiosk (mitigé aujourd'hui par 401 auto-relogin + poll 15s).
- **QS-4** : doc drift `docs/QUEUE_WORKER_SETUP.md` (dit `database`, prod = `redis`) — docs-only.
- **Systémique sentinelles** : lazy-chunk freshness sentinels ne doivent watcher QUE des sources chunk-résidentes (jamais i18n/store/navbar entry-resident). Pattern codifié dans les en-têtes kds + admin-shell.

## Runbook prod (rappel, pas du code — go-live owner)
Vérifier sur la box : `supervisorctl status` (lecayenne-queue-worker RUNNING ×2), `crontab -l` (ligne `schedule:run`), UptimeRobot live. Réf `scripts/deploy/PRODUCTION_GO_LIVE_CHECKLIST.md`.

## Gates
- Vitest **1881 passed | 0 failed** (4 erreurs `ECONNREFUSED ::1:3000` = bruit réseau pré-existant, sans rapport).
- PHP RefreshToken **6/6** ; full PHP suite (gate backend) — voir commit final.
- NF525 CHAIN OK · **0 fichier frozen touché** · pas de push.

## Bilan superviseur
La vague parallèle annonçait 2 P0 + 2 P1 ; après vérification adversariale calibrée V1 LOCAL :
**0 P0 réel, 0 P1 réel** — tout re-gradé honnêtement (P3 backlog ou réfuté). 2 heals réels
appliqués (1 sécurité auth P2, 1 correction de ma propre erreur de sentinelle). C'est le rôle
du superviseur : protéger contre la sur-escalade (P0 fantômes) ET attraper ses propres erreurs.
