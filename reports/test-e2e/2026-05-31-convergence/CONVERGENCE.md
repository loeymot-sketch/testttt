# Convergence E2E — Preuve reproductible (2026-05-31)

**Branche :** `claude/production-e2e-validation-kmeZk`
**Verdict :** `CONDITIONAL` (CLAUDE.md §8) — pas `GO`.

## Pourquoi CONDITIONAL et non GO

Le cycle précédent concluait `GO 100% — 0 P0/P1`, mais cette preuve vivait dans un conteneur
éphémère jamais poussé (commit `d3d290183` introuvable ici, HEAD réel = la base de cette branche).
Ce rapport ne réaffirme rien : il **remplace les captures perdues par des artefacts re-runnables
committés** dans le dépôt (CLAUDE.md §9 mémoire-en-fichiers, §11 evidence). Le verdict ne passera à
`GO` que lorsque les trois preuves ci-dessous seront vertes **et** la suite E2E live exécutée.

## Preuve A — Invariants fiscaux / intégrité (PHPUnit, committé)

Commande :

```
php artisan test --testsuite=Feature --filter=Fiscal
→ Tests: 96 passed (14.52s)
```

Tests ajoutés à la suite existante (pas de nouveau framework) :
- `tests/Feature/Fiscal/FiscalSequenceTest.php::test_high_volume_gap_free_and_branch_isolated`
  — 500 allocations, 2 branches entrelacées : chaîne `1..250` contiguë par branche, 0 doublon,
  isolation inter-branche prouvée (CLAUDE.md §3.8).
- `tests/Feature/Fiscal/AuditLogHashChainTest.php::test_high_volume_chain_remains_intact`
  — chaîne HMAC append-only de 250 entrées vérifiée intacte end-to-end.
- `tests/Feature/Fiscal/FiscalChainConcurrencyProofTest.php`
  — invariant DB-level (gap-free / no-dup / min=1 / max=count), même requête que le vérifieur
  de la sonde, avec la limite mono-process documentée explicitement.

Couverture déjà en place et re-vérifiée verte : agrégation Z **paid-only** + exclusion des commandes
sans `fiscal_sequence_no` + soft-deleted (`ZReportAggregateFilterTest`), double-comptage de fenêtres
Z (`ZReportBoundaryTest`), 5 open/close sans gap (`ZReportCloseTest`). C'est ce qui **neutralise par
test** le P2 seed (PAID sans payment-record), au lieu de l'affirmer.

## Preuve B — Concurrence RÉELLE (multi-process, committé)

Remplace l'ancienne fausse-charge `kiosk:simulate-orders` (boucle `for` séquentielle, ne touche
jamais le lock). Nouveaux artefacts :
- `app/Console/Commands/FiscalLoadProbe.php` (worker + setup + vérifieur)
- `scripts/fiscal-load-probe.sh` (K process parallèles, DB SQLite dédiée+migrée, isolée)

Commande :

```
bash scripts/fiscal-load-probe.sh 20 50
→ Launching 20 parallel workers x 50 allocations = 1000 total
→ Branch 1: total=1000 distinct=1000 min=1 max=1000
→ gap-free OK / 0 dup — contiguous chain 1..1000 (1000 allocations) verified.   (exit 0)
```

### Finding technique (important — pas un GO complaisant)

Sous 20 process réellement concurrents, deux comportements observés et **interprétés** :
1. `UNIQUE constraint failed: orders.branch_id, orders.fiscal_sequence_no` — la contrainte unique
   `orders_branch_fiscal_seq_unique` est la **porte ultime** et elle a tenu : **aucune ligne dupliquée
   n'a jamais été persistée**. C'est exactement le filet NF525 attendu.
2. `LockTimeoutException` — le lock optimiste se dégrade sur le driver de test.

**Cause :** la sonde tourne sur **SQLite + cache file**. Le docstring du service le dit lui-même :
`lockForUpdate` est un no-op sous SQLite, et le lock cache `file` n'est pas parfaitement atomique
sous forte parallélisation. La **production** utilise Redis (lock atomique) + MySQL (`lockForUpdate`
réel), où ces collisions n'apparaissent pas au niveau lock.

**Ce que la sonde prouve donc honnêtement :** même avec un lock dégradé, l'invariant qui compte —
chaîne **gap-free, zéro doublon persisté** — tient, parce que (a) la contrainte unique rejette toute
collision avant persistance, et (b) un appelant correct ré-alloue (un insert rejeté ne brûle pas de
numéro, `next()` recalcule depuis les lignes persistées → convergence vers `1..N`). Le worker modélise
ce contrat de retry. **Recommandation de suivi :** rejouer cette sonde en CI sur Redis+MySQL pour
fermer le dernier écart entre le driver de test et la prod.

## Preuve C — E2E (harness validé, exécution = gap)

```
npx playwright test --list
→ Total: 25 tests in 6 files (01-auth-refresh … 06-staff-only-routing)   (exit 0)
```

Le câblage du harness Playwright est valide (25 tests découverts/parsés sur les 6 specs). Leur
**exécution** exige une stack live (serveur `http://localhost:8000`, DB seedée avec
`chef@lecayenne.fr`, frontend buildé) qui n'a pas été montée dans ce conteneur éphémère.

➜ **Gap d'evidence assumé** (CLAUDE.md §11 : ne pas feindre la certitude). À exécuter dans un env
avec la stack live :

```
php artisan serve & npm run dev &   # + DB migrée/seedée
npx playwright test
```

## Findings connus (statut)

| ID | Sévérité | Statut |
|----|----------|--------|
| Chaîne fiscale gap-free sous charge | invariant critique | ✅ prouvé (A + B) |
| Hash-chain audit append-only | invariant critique | ✅ prouvé (A) |
| Agrégation Z paid-only / P2 seed | P2 | ✅ neutralisé par test (A) |
| Lock dégradé sur SQLite+file | observation env-test | ⚠️ filet unique tient ; rejouer sur Redis+MySQL |
| Exécution E2E live | preuve C | ⛔ non exécutée (gap assumé) |

## Discipline

- Zéro modification de logique backend fiscale (frozen-zone NF525) : `FiscalSequenceService` non
  touché. Ajouts = tests + commande sonde + script + ce rapport.
- `fiscal_sequence_no` confirmé hors `$fillable` (sûreté) — la sonde l'assigne en propriété directe
  comme le vrai `OrderService`.
- Conditions de passage à `GO` : Preuve C exécutée verte + sonde B rejouée sur Redis+MySQL.
