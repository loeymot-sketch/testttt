# Deep Fiscal-Close Audit — NF525 CLÔTURE (read-only adversaire)

**Date** 2026-07-11 · **DB** `foodking_e2e` · **PHP** 8.2.30 · **Branche git** `pos/category-first-caisse-2026-06-23`
**Mode** LECTURE SEULE — aucune clôture/ouverture Z, aucune mutation committée (tests trigger en transaction rollback). Frozen §7 non touchés.

**VERDICT** : intégrité de la clôture fiscale **SOLIDE**. Chaîne HMAC dual intacte, séquence Z gap-free, Z↔orders exacts au centime, triggers d'immuabilité présents ET actifs, boot guards prod présents. Le seul point actionnable = **5 orphelins « fenêtre vivante »** (dead-window post-dernier-Z) sur branch 1 — *sceller* (pas un défaut de code), plus des orphelins historiques déjà sous politique owner « detect-only ».

---

## 1. Chaîne HMAC dual — PASS (preuve verte)

```
$ php artisan fiscal:verify-chain --all
  + branch=1 CHAIN OK
  + branch=7 CHAIN OK
  + branch=8 CHAIN OK
  + branch=9 CHAIN OK
SWEEP COMPLETE — CHAIN OK on every active branch (4 total)   EXIT=0
```

Re-marche manuelle `z_reports` (25 Z, branch 1, tous `closed`) : chaque `prev_hash` == `signature` de la ligne de séquence précédente ; genèse seq=1 `prev_hash=NULL`. Dernier Z **id=27 / seq=25** sig `e2302fea`, `prev_hash=10cbe611` (= sig du Z id=26). Linkage **continu, zéro brèche**.

**Sévérité : aucune.** Invariant tenu.

## 2. Z-membership — 2449 orphelins, catégorisés (EXIT=1 attendu du détecteur)

```
$ php artisan fiscal:verify-z-membership   → EXIT=1
2449 commande(s) numerotee(s) REELLEMENT hors de tout Z signe
```

| Catégorie | Nb | Branch | Nature | Sévérité |
|---|---|---|---|---|
| `fenetre-morte pre-C33` orphelins historiques | 2442 | 1 | ventes historiques jamais scellées (ancienne sémantique `opened_at`) | KNOWN — politique owner **detect-only** + cron 06:05 (ESCALATION_NO_GO.md P0#1). Pas une régression. |
| `apres le dernier Z clos (2026-07-07 16:48:37), aucun Z ouvert` | **5** | 1 | commandes 2026-07-02→07-09 numérotées, aucun Z ouvert pour les sceller | **P2 actionnable** — se scellent en ouvrant puis clôturant un Z (partition continue C33). |
| `aucun Z (ni clos ni ouvert) sur cette branche` | 2 | 7, 9 | branches test sans aucun Z | P3 test-data |

Les 5 orphelins vivants (IDs) : `0207265427`(seq2643), `0807265560`(2639), `0907265618`(2642), `0807265574`(2640), `0807265575`(2641).
**Fix** : ouverture + clôture Z sur branch 1 (à faire par le superviseur en e2e contrôlé — PAS moi). Le détecteur qui *flag* EST le contrôle NF525 ; il fonctionne (0 faux-positif/négatif revendiqué).

## 3. Triggers d'immuabilité — PASS + enforcement PROUVÉ

```
$ php artisan fiscal:verify-immutability-triggers
IMMUTABILITY TRIGGERS OK — 8/8 present on this MySQL database.   EXIT=0
```
`information_schema.TRIGGERS` (foodking_e2e) = 8 canoniques : `audit_logs_no_update`, `audit_logs_no_delete`, `z_reports_no_delete`, `cash_movements_no_delete`, `cash_drawer_sessions_no_delete`, `order_payments_no_delete`, `stock_movements_no_delete`, `stock_movements_no_update`.

Enforcement testé (tinker, **transaction rollback — rien supprimé**) :
```
UPDATE audit_logs → SQLSTATE[45000] 1644 "audit_logs is INSERT-only (NF525 / POS-9.4.3)"  ✅ bloqué
DELETE audit_logs → SQLSTATE[45000] 1644 "audit_logs is INSERT-only"                        ✅ bloqué
DELETE z_reports  → SQLSTATE[45000] 1644 "z_reports is immutable post-close (POS-9.4.6)"    ✅ bloqué
row audit_logs id=4957 intacte (action=order.counter_payment_confirmed)
```

**INFO (by-design, pas un défaut)** : `z_reports` n'a **pas** de trigger BEFORE UPDATE (un UPDATE direct sur un Z clos n'est pas bloqué au niveau DB). C'est intentionnel — le cycle OPEN→CLOSING→CLOSED exige l'UPDATE ; le baseline canonique (FiscalInstallImmutabilityTriggersCommand::triggerDefinitions) ne définit que `z_reports_no_delete`. La falsification post-clôture d'un Z reste **détectable** par `verify-chain` (la signature couvre les totaux). Défense-en-profondeur : chain-detection, pas DB-prevention.

## 4. Séquence Z gap-free — PASS

`z_reports.sequence_no` branch 1 = **1..25 monotone, gap-free** (seule branche avec Z). Les `id` DB 6 et 7 sont absents (clé surrogate, opens rollback historiques) — sans effet : la chaîne fiscale est sur `sequence_no`, continue.

## 5. Cohérence Z↔orders — PASS (re-agrégation au centime)

Fenêtre = `(previousClosedZ.closed_at, this.closed_at]` sur `COALESCE(fiscal_dated_at, created_at)`, `branch_id`, `whereNotNull(fiscal_sequence_no)`, `payment_status != unpaid`, hors statuts terminaux.

| Z | fenêtre | stored total_ttc / order_count | re-agrégé | DELTA |
|---|---|---|---|---|
| **id=27 seq=25** | (2026-07-07 16:44:35 , 16:48:37] | 14.40 / 1 | 14.40 / 1 | **0.00** |
| id=26 seq=24 | (2026-06-19 12:34:58 , 2026-07-07 16:44:35] | 775.55 / 127 | 775.55 / 127 | **0.00** |

## 6. Boot guards prod NF525 — PASS (code lu)

`app/Providers/AppServiceProvider.php`, bloc `if (app()->environment('production'))` (l.178). Les 5 gardes demandés lèvent tous `RuntimeException` :

| Garde | Ligne | Condition de refus |
|---|---|---|
| POS_SIMULATION_HARDWARE | 185 | `config('pos.simulation_hardware')` truthy |
| APP_DEBUG | 222 | `config('app.debug')` truthy |
| IDEMPOTENCY | 243 | `!config('idempotency.enabled')` |
| APP_URL | 281 | `empty(config('app.url'))` |
| CACHE_DRIVER | 316 | `in_array(driver, ['array','null'])` |

+6 gardes bonus (PAYMENT_BYPASS 197, PRINTING_BYPASS 204, LOYALTY_QR_SECRET 262, BROADCAST 293, QUEUE≠sync 299, STRIPE_WEBHOOK 324).
**Note (déjà tracké UNI-03, CLAUDE.md §8)** : la liste CACHE_DRIVER interdit `array`/`null` seulement — `file`/`database` PASSENT. Safe en V1 LOCAL mono-poste ; à élargir au cutover cloud multi-instance. Pas un nouveau finding.

---

## Synthèse sévérité
- **P0** : 0
- **P1** : 0
- **P2** : 1 — 5 orphelins fenêtre-vivante branch 1 → sceller via open+close Z (superviseur, e2e contrôlé).
- **P3/INFO** : 2 orphelins branches-test 7/9 ; z_reports sans trigger UPDATE (by-design, chain-detected) ; CACHE_DRIVER file/database (UNI-03 backlog).

**Aucune brèche d'intégrité de clôture.** Tous les invariants durs tiennent avec preuve verte.
