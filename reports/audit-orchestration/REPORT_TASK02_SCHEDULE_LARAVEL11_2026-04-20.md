# T02 — Rapport schedule Laravel 11 (2026-04-20)

## Verdict : FAIL

## Checklist : V1..V7

- [x] **V1.** Version Laravel confirmée (10.x ou 11.x) — **constat : Laravel 9.x** (`composer.json` `^9.19`, `composer.lock` `v9.52.21`). Ce n’est ni 10.x ni 11.x ; l’hypothèse « migration Laravel 11 » ne tient pas pour cette copie de `testttt-kiosk-p93`.
- [x] **V2.** Mécanique de schedule détectée — **Laravel 9 classique** : `bootstrap/app.php` enregistre `App\Console\Kernel` comme `Console\Kernel` contract, mais **`app/Console/Kernel.php` est absent**. `routes/console.php` ne contient **aucun** `Schedule::` (uniquement la commande closure `inspire`). **Pas** de `Application::configure()->withSchedule(...)` (idiome Laravel 11).
- [x] **V3.** SloEvaluatorJob enregistré 5 min `withoutOverlapping` — **✗** (aucune entrée de schedule ; la classe existe sous `app/Jobs/Observability/SloEvaluatorJob.php`).
- [x] **V4.** `foodking:outbox:rescue` enregistré 1 min `withoutOverlapping` — **✗** (la commande est définie dans `app/Console/Commands/OutboxRescueCommand.php`, mais **non planifiée**).
- [x] **V5.** Purge OTPs enregistrée 15 min — **✗** (aucune occurrence de `purge-expired-otps` ni équivalent dans le scheduling ; `rg` sur les motifs demandés ne remonte que le job/commande hors schedule).
- [x] **V6.** `php artisan schedule:list` — **exécuté, échec** : l’application ne démarre pas le kernel console (classe `App\Console\Kernel` introuvable). Sortie résumée ci-dessous.
- [x] **V7.** Diff explicite vs `testttt/app/Console/Kernel.php` — voir section dédiée (baseline `testttt` : 3 tâches planifiées ; `kiosk-p93` : **0** + kernel manquant).

## Version Laravel

- **`testttt-kiosk-p93/composer.json`** : `"laravel/framework": "^9.19"`.
- **`testttt-kiosk-p93/composer.lock`** : `laravel/framework` **v9.52.21**.

## Mécanique

**Attendu pour Laravel 9** : `bootstrap/app.php` + `App\Console\Kernel` (`schedule()` / `commands()`).

**Observé dans `testttt-kiosk-p93`** :

- `bootstrap/app.php` : binding vers `App\Console\Kernel::class` (fichier **manquant**).
- `routes/console.php` : **pas** de scheduling — seulement `Artisan::command('inspire', ...)`.

**Pas** de mécanique Laravel 11 (`bootstrap/app.php` avec `Application::configure()->withSchedule(...)`, ni schedules dans `routes/console.php` au sens Laravel 11).

## Tableau schedules

| Schedule | Présent | Path | Cadence | withoutOverlapping | Notes |
|---|---|---|---|---|---|
| SloEvaluatorJob | ✗ | — | — | — | Classe présente (`app/Jobs/Observability/SloEvaluatorJob.php`) ; aucun `Schedule::job` / `$schedule->job` trouvé dans le dépôt (`rg` sur motifs + recherche `Schedule::`). |
| foodking:outbox:rescue | ✗ | — | — | — | Signature dans `OutboxRescueCommand.php` ; pas d’enregistrement cron. |
| purge-expired-otps | ✗ | — | — | — | Aucune planification équivalente au baseline `testttt` (nom `purge-expired-otps` absent du scheduling). |

## Recherche globale (read-only)

Commande : `rg "SloEvaluatorJob|foodking:outbox:rescue|purge-expired-otps" --type php` sous `testttt-kiosk-p93/`.

**Fichiers touchés (hors schedule)** :

- `app/Jobs/Observability/SloEvaluatorJob.php` (définition de classe)
- `app/Services/Observability/SloMetricCollector.php` (mention dans docblock)
- `config/logging.php` (mention)
- `tests/Feature/Observability/SloEvaluatorJobTest.php`
- `app/Http/Controllers/Frontend/KioskEventController.php` (commentaire)
- `app/Console/Commands/OutboxRescueCommand.php` (`$signature = 'foodking:outbox:rescue'`)

**Aucun** fichier ne lie ces éléments à `Illuminate\Console\Scheduling\Schedule`.

## artisan schedule:list (output ou note "non exécutable")

**Non exécutable jusqu’au bout** (erreur fatale au bootstrap) :

```text
PHP Fatal error: Class "App\Console\Kernel" does not exist
...
BindingResolutionException: Target class [App\Console\Kernel] does not exist.
```

Exit code : **255** (cwd : `testttt-kiosk-p93`).

Interprétation : la suppression de `Kernel.php` **casse** `php artisan` pour cette base, en plus de supprimer toute définition de schedule.

## Diff vs testttt/Kernel.php

Référence : `testttt/app/Console/Kernel.php` (Laravel 9, `protected function schedule(Schedule $schedule)`).

| Élément | `testttt` (baseline) | `testttt-kiosk-p93` |
|---|---|---|
| Fichier `App\Console\Kernel` | Présent | **Absent** |
| Purge OTP (`purge-expired-otps`, closure DB, `everyFifteenMinutes`, `withoutOverlapping`) | ✓ | ✗ (aucun schedule) |
| `foodking:outbox:rescue` (`everyMinute`, `withoutOverlapping`) | ✓ | ✗ |
| Job toutes les 5 minutes (`withoutOverlapping`) | `CleanupStalePendingKioskOrders` via `$schedule->job(...)` | ✗ |

**Écart documentation K-9 vs baseline `testttt`** : le baseline planifie **`CleanupStalePendingKioskOrders`** toutes les 5 minutes, et **ne** référence **pas** `SloEvaluatorJob` dans `Kernel.php`. Dans `testttt`, `rg SloEvaluatorJob` sur `*.php` ne retourne **aucun** résultat. À prendre en compte pour T02b (alignement produit : job SLO vs cleanup kiosk).

## Risque chiffré (P0/P1/P2)

- **P0** — Console Laravel inutilisable (`App\Console\Kernel` manquant) : tout `artisan` échoue ; pas de scheduler opérationnel.
- **P0** — **Outbox rescue** non exécuté en cron : risque direct sur la résilience du pattern outbox documenté K-9.
- **P1** — **Purge OTP** absente : croissance table `otps`, charge DB et risque opérationnel secondaire.
- **P1** — **SLO / observabilité** : `SloEvaluatorJob` non planifié malgré la présence du job et des tests ; pas d’évaluation périodique ni d’alerting associé au cron.
- **P2** — Écart de version / narratif : dépôt en **Laravel 9**, pas 11 ; toute « migration schedule vers bootstrap L11 » est **non réalisée** sur cette copie — la situation actuelle ressemble à une **régression** (kernel supprimé sans remplacement), pas à une migration achevée.

---

**Critère global** : ≥ 1 schedule manquant → **FAIL** (ici les trois manquent, et le mécanisme scheduler est absent / artisan cassé).
