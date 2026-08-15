# T-1.2 — Baseline gelée (2026-08-15, HEAD `e8923b10a`)

> Capturée APRÈS T-1.1 (D9/D10/D11 réglés) — sinon la baseline aurait mesuré le vide.
> Sert de référence pour tout le reste du GOAL : un test qui rougit après cette baseline
> et qui n'est PAS dans la liste ci-dessous est une régression introduite par ce GOAL.

## Commandes rejouables
```bash
npx playwright test --list                    # collecte
php artisan test > phpunit.log 2>&1            # SQLite (défaut local)
npx vitest run > vitest.log 2>&1               # Vitest
```

## Résultats

| Suite | Résultat | Détail |
|---|---|---|
| **Playwright (collecte)** | **1590 tests / 428 fichiers** | 0 avant T-1.1 (D9) |
| **Vitest** | **2899 passed / 3 skipped (2902), 401/401 fichiers, 0 échec** | 1 échec initial (`kdsBundleFreshnessSentinel`) réparé par `npm run production` local — bundle `admin-kds.69b84e2f.js` généré, HASH IDENTIQUE à celui bâti sur le VPS ce soir (confirmation croisée) |
| **PHPUnit (SQLite)** | **4971 passed, 8 failed, 2 incomplete, 36 skipped** (995 s) | 8 échecs pré-existants, voir ci-dessous |
| **audit_logs (prod)** | count=**956**, last_hash=`e6cd6725fed31efa2bcd806f2cc86748ae3c6620c16c061d8fdc0a1c174e2963` | mesuré via SSH avant toute action de ce GOAL |
| **git HEAD** | `e8923b10a` | branche `pos/category-first-caisse-2026-06-23` |

Logs complets : `phpunit-sqlite-full.log`, `vitest-full.log` (mêmes dossier).

## Les 8 échecs PHPUnit — pré-existants, HORS PÉRIMÈTRE de ce GOAL

Aucun ne touche les 9 maillons BASE (`§0.4` du GOAL). Investigués (pas juste listés) pour ne
pas les confondre avec une future régression réelle de ce GOAL :

| Test | Cause racine identifiée | Pourquoi hors périmètre |
|---|---|---|
| `PrinterControllerTest` ×3 (`admin can create a printer`, `update changes the station`, `create accepts the corrected network type value`) | `SafeRemoteHost` rejette désormais un allowlist CIDR sans port (`192.168.0.0/16`), le fixture de test utilise l'ancien format. Message d'erreur exact : *"declares no port and is no longer accepted... use host+port format"*. | Gestion des imprimantes réseau — aucun des 9 maillons ; T-5.1 touche `InterrupteurService` (réglages), pas la validation réseau des imprimantes. |
| `PrinterHostAllowlistSentinelTest > accepts rfc1918 with allowlist` | Même cause racine (format allowlist CIDR:port). | idem |
| `IdempotencyRequiredRoutesCoverageTest` | 3 routes avec middleware `idempotency` absentes de `config('idempotency.required_routes')` : `raw-materials/{id}/adjust`, `pos-loyalty/credit-manual`, `pos-loyalty/deduct-manual` — routes ajoutées cette semaine (stock 14/08, fidélité ce soir par session concurrente), config non mise à jour en même temps. | Sentinelle fait son travail — trou réel sur des routes qui MUTENT de l'argent/stock, mais aucune n'est un des 9 maillons BASE. Digne d'un correctif dédié 1 ligne, hors budget de ce GOAL. |
| `RolePermissionSeederTest` ×3 (branch manager / pos operator / chef) | `PermissionTableSeeder.php:714` — `UNIQUE constraint failed: permissions.name` sur un bulk INSERT se terminant par `cash.reconcile.variance.override`. **Reproductible en isolation totale** (fichier seul, DB vide) : le seeder échoue sur lui-même, pas un artefact d'ordre de tests. Cause exacte du doublon non isolée dans le temps imparti (le littéral n'apparaît qu'UNE fois dans le fichier — le doublon vient d'ailleurs dans la chaîne de seed, à investiguer). | Infrastructure de seeding RBAC — aucun des 9 maillons. Le nom de la permission concernée (`cash.reconcile.variance.override`) est ironiquement celui-là même que T-2.1 (P0 clôture caisse) va manipuler ; **vérifier que T-2.1 n'aggrave pas ce trou**, sans le réparer (hors scope). |

**Verdict wave-checkpoint (Axis 3)** : documentés avec rationale ⇒ Wave 1 peut clore. Aucun de
ces 8 n'entre dans le calcul P0+P1=0 du GOAL (hors des 9 maillons BASE, §0.4).
