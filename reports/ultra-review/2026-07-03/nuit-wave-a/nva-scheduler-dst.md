# NVA — Scheduler / DST Paris / bornes temporelles

HEAD cfc23966a + working-tree. Cible = durabilité temporelle (le temps casse-t-il quelque chose sur 6 mois / 6 ans ?).
Read-only. Repro PHP/tinker/grep. app.timezone = `Europe/Paris` (config/app.php:140). Cron prod = `* * * * * php artisan schedule:run` (CRONTAB_PROD.md:35).

## Verdict : IMPROVABLE — 1 finding P3 durabilité confirmé + design temporel globalement robuste

---

## FINDING P3 (durabilité) — L'archive fiscale NF525 quotidienne (02:00) est SAUTÉE la nuit du passage à l'heure d'été, silencieusement, sans rattrapage

**Fichier** : `app/Console/Kernel.php:366` — lane `foodking-fiscal-archive-daily` = `->dailyAt('02:00')` (pas de `->timezone()` explicite → hérite app tz `Europe/Paris`).

**Mécanisme** : le dernier dimanche de mars, l'horloge Paris saute de 01:59 CET directement à 03:00 CEST. Les minutes locales `02:00`–`02:59` **n'existent pas** ce jour-là. Laravel évalue chaque lane via `CronExpression::isDue(now('Europe/Paris'))` ; le slot `0 2 * * *` n'est jamais atteint (que le clock système soit UTC ou Paris). La lane ne tourne pas → l'archive ZIP+JSON signée de J-1 n'est jamais produite.

**Pourquoi ça reste silencieux** :
- `onFailure` ne se déclenche pas : un skip n'est pas un échec (la commande n'est jamais invoquée).
- Aucun balayage de rattrapage : le scheduler ne fait QUE `--from=hier --to=hier` chaque jour (Kernel.php:339-341). Aucune lane hebdomadaire/mensuelle ne rejoue une plage (`grep` = 0 sweep, 0 `subWeek`/`subDays` sur archive).
- La lane n'est pas mono-poste-recovery : Vixie cron ni Laravel ne rejouent un `dailyAt` sauté.

**Repro LIVE** (PHP, prouve l'inexistence de 02:00 Paris au spring-forward 2027-03-28) :
```
UTC 00:59 => Paris 01:59
UTC 01:00 => Paris 03:00      <-- 02:00..02:59 sautées
Requested 02:00 normalized to: 03:00 (+02:00)   // DateTime("2027-03-28 02:00", Europe/Paris)
```
Confirmé : seule lane du planning dans la zone morte 02:00–02:59 (backup 03:00, purge-parked 03:15, chain-monitor 03:30, prunes 04:xx, sanctum 04:30, verify-restore 05:00, z-membership 06:05, z-close 23:59, z-open 00:01 sont TOUTES hors zone).

**Impact durabilité** : 1 samedi/an (dernier dim. mars), l'archive fiscale J-1 manque, sans détection. Sur l'horizon NF525 6 ans = jusqu'à 6 jours d'archive absents, jamais signalés. **Non-bloquant légalement** : les sources immuables (`audit_logs`, `z_reports`, rétention 6 ans) existent toujours → l'archive est reconstructible manuellement (`php artisan foodking:fiscal:archive <branch> --from=DATE --to=DATE`, FiscalArchiveCommand.php:82-93, `@unlink` avant écriture = idempotent). Mais aucun humain ne sait qu'il faut le faire.

**Fix proposé** (non-frozen, Kernel.php seul — ZReportService/FiscalService intouchés) :
1. Déplacer la lane hors de la zone DST : `->dailyAt('01:15')` ou `->dailyAt('03:45')` (toujours après le z-close 23:59, donc J-1 vu clôturé). `01:15`/`03:45` existent au spring-forward ET n'apparaissent qu'une fois au fall-back.
2. Défense-en-profondeur : ajouter une lane hebdomadaire idempotente de rattrapage `foodking:fiscal:archive <branch> --from=<J-7> --to=<hier>` (l'`@unlink` la rend re-jouable sans doublon), OU un détecteur de complétude mensuel qui `Log::error`+`onFailure` si un `{Ymd}.zip` manque pour un jour ayant des Z clôturés.

---

## Angles attaqués — preuves de robustesse (réfutations)

**Z-close / Z-open placés HORS zone DST — design délibéré CORRECT.** `fiscal:close-all-active-branches` `->dailyAt('23:59')->timezone('Europe/Paris')` (Kernel.php:402) et `fiscal:open-all-active-branches` `->dailyAt('00:01')` (Kernel.php:447). 23:59 et 00:01 n'entrent jamais dans la fenêtre 02:00–02:59 : au spring-forward comme au fall-back, chacun s'exécute **exactement une fois**. Pas de double-close (pas de Z dupliqué), pas de Z manquant. La bascule (fall-back) qui répète 02:xx ne touche aucune lane fiscale mutante. RÉFUTÉ « double-close / Z manquant au changement d'heure ».

**Fall-back (octobre) — double-run de l'archive 02:00 = BÉNIN.** Au dernier dim. octobre, Paris 03:00→02:00, donc `0 2 * * *` matche deux fois (UTC 00:00 et 01:00). L'archive tourne 2×, mais `build()` fait `@unlink($absolute)` puis réécrit le même `{Ymd}.zip` (FiscalArchiveCommand.php:214-215) → idempotent, aucune corruption, aucun faux-échec. `withoutOverlapping` couvre le concurrent (les deux runs sont à 1h d'intervalle réel → mutex déjà relâché, pas de blocage). RÉFUTÉ « double archive = corruption ».

**Cascade d'expiration Sanctum TTL 480min — MITIGÉE (triple défense).** Token kiosk `now()->addMinutes(480)` (KioskMachineLoginController.php:107). (1) Refresh proactif toutes les 2h via `setInterval` (app.js:232-239, `refreshAuthToken` → `/api/refresh-token`, 4 refreshes/TTL, marge robuste à un tick manqué) — `setInterval` compte en ms écoulées, insensible au DST. (2) Re-login silencieux réactif sur 401 avec replay coalescé (`__retry401Kiosk`, app.js:100-133 + kioskAuthInterceptor.js). (3) Sync cross-onglet de la rotation de token (app.js:246-260). Une borne allumée en plein service ne perd pas ses commandes sur expiration de token. RÉFUTÉ « borne 401 en plein service → perte ».

**Bornes KDS Carbon::today/tomorrow — tz-correctes.** `KdsSyncService.php:92-105` et `KitchenDisplaySystemOrderService.php:122-135,227-236,539-548` utilisent `Carbon::today($appTz)` / `Carbon::tomorrow($appTz)` (Paris). `order_datetime` étant stocké en wall-clock app-tz, les bornes locales sont cohérentes des deux côtés du DST (jour de 23h au spring / 25h au fall) — aucune commande hors-fenêtre. RÉFUTÉ « KDS perd les commandes la nuit du changement d'heure ».

**Double-run / withoutOverlapping — couverture complète.** Les 24 lanes portent toutes `withoutOverlapping` (+ `onOneServer` cloud-prep). Les lanes `everyMinute` mutantes (outbox:rescue, outbox:monitor, fiscal:retry-alloc `withoutOverlapping(5)`) sont protégées. Aucune lane sans garde de ré-entrée détectée.

**Ordre de dépendance close→archive→chain — cohérent.** 23:59 close(J) → 02:00 archive(J via `now()->subDay()`, Kernel.php:339) → 03:30 chain-monitor(J). L'archive voit toujours un Z clôturé. `activeBranchIds()` (whereIn [ACTIVE,1]) protège contre le drift status=1→5 des deux lanes fiscales.

**Note secondaire (non-finding)** : `backup:verify-restore` (05:00) détecte un backup-daily manqué via seuil `>26h` (BackupVerifyRestoreCommand.php:139) mais en `warn` (pas `onFailure`, pas de pager) — la nuit de fall-back (jour 25h) peut friser ce seuil et émettre un warn bénin. Amélioration possible mais hors scope temporel strict.

## Attaques exécutées
1. DST spring-forward : inexistence de 02:00 Paris (repro PHP) → skip archive.
2. DST fall-back : répétition de 02:00 → double-run archive (idempotent via @unlink).
3. DST sur Z-close/Z-open (hors zone) → single-run prouvé.
4. DST sur bornes KDS Carbon::today/tomorrow → cohérence wall-clock.
5. Cascade TTL Sanctum 480min → triple mitigation prouvée.
6. Balayage 24 lanes : withoutOverlapping / onOneServer / onFailure / ordre de dépendance.
7. Grep sweep de rattrapage archive → absent (confirme le silence du skip).
