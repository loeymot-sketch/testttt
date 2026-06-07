# AGENT 10 — SÉCURITÉ / RBAC / ISOLATION
> Ton : red-team paranoïaque. Tu essaies activement de casser l'isolation et les droits.

## Scope / Anchors (vérifiés)
- `app/Models/Scopes/BranchScope.php` (20 models — baseline sentinel `BranchScopeCoverageSentinelTest`)
- Spatie permissions (`permission:settings`, rôles Admin/Branch Manager/POS Operator/Chef)
- Sanctum `kiosk:order` (TTL, ability, revoke-on-relogin), `IdempotencyKeyMiddleware`
- `FormRequestAuthzDriftSentinelTest` (baseline 69, observé 66)

## Checklist abusif (AXE SEC)
- **Isolation branche** : staff branch>0 ne voit QUE sa branche ; admin branch=0 bypass ; 20 models scopés (sentinel vert). Tenter une fuite cross-branch via API directe.
- **RBAC** : chaque route admin sensible gardée (`permission:*`) ; un POS Operator ne peut PAS accéder settings/users/Z-config ; tester accès refusé (403) pour chaque rôle.
- **Sanctum kiosk** : token `kiosk:order` ability UNIQUEMENT ; `tokenCan` dans 8 controllers ; old tokens révoqués au relogin ; pas de fuite creds (KIOSK_AUTO_LOGIN gate = IP-trusted, vérifier curl externe refusé).
- **Idempotency** : double POST mutating → 2e rejoué/409, pas de double-commande/double-paiement.
- **Secrets** : aucun `.env`/clé committé ; pre-commit hook actif ; `.env.e2e` gitignored.
- **Boot guards prod** (AppServiceProvider) : POS_SIMULATION_HARDWARE, IDEMPOTENCY, APP_DEBUG, APP_URL, CACHE_DRIVER — vérifier refus de boot si violé.
- **NF525 anti-altération** : triggers BEFORE DELETE audit_logs/z_reports ; GRANT REVOKE ; tenter delete → refusé.
- **Abus** : injection champs, totaux client ignorés (PricingService SSOT), prix négatif/qté énorme rejetés.

## Méthode
Read-only audit + tentatives d'abus via API/curl sur :8766. Sentinels via safe-test (pas `php artisan test` direct — DEVDB-GUARD).

## PASS bar
Isolation + RBAC + Sanctum + idempotency + secrets + boot guards + anti-altération tous prouvés. Toute fuite = P0. Sinon ❌.

## Sortie `reports/test-e2e/goal-100pct-2026-06-07/<round>/10-security.json`
