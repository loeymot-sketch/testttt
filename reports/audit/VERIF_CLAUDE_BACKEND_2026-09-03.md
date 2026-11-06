# Vérification en lecture seule des 10 P1 backend annoncés par l'audit externe

HEAD `28cd79d5a`, branche `pos/category-first-caisse-2026-06-23`. Lecture de code seule, `chemin:ligne`
obligatoire, aucun fichier de production modifié.

---

## A1 — `terminal_failures` sans plafond d'essais
VERDICT: CONFIRMÉ (la partie « un test verrouille ce comportement » est RÉFUTÉE)

Preuve, prédicat sans condition sur `attempts` :
`app/Http/Controllers/Admin/Observability/SyncOverviewController.php:365`
```php
$terminalQuery = DB::table('domain_events')->whereNull('dispatched_at')->whereNotNull('last_error');
```
Preuve, le job peuple `last_error` ET relâche le claim dès le 1er échec, alors que `tries = 6` :
`app/Jobs/DispatchDomainEventsJob.php:166-172`
```php
$domainEvent->forceFill([
    'dispatched_at' => null,
    'last_error' => $e instanceof PayloadMismatchException ? 'contract_violation: ' . ... : $e->getMessage(),
])->save();
```
(`$tries = 6` ligne 41, backoff cumulé ≈ 381 s, lignes 26-36.)

Le test cherché n'existe pas : le seul `attempts => 1` compté terminal est
`tests/Feature/Observability/OutboxDeliverySemanticsTest.php:117`, un `contract_violation` — réellement
terminal (`$this->fail()`, `DispatchDomainEventsJob.php:194`). `SyncOverviewControllerTest.php:112` pose
`attempts=1, last_error='boom'` mais n'assert que `recent_failures`, un nom qui ne ment pas.

Conséquence réelle : pendant les ~6,4 min de la courbe de relance (redémarrage Pusher/Soketi typique),
tout événement en retry est compté « échec terminal ». Le cockpit gonfle, et le bouton « Rejouer »
(`SyncOverviewController.php:464-466`, même prédicat) peut re-diffuser un événement dont la relance
automatique est encore programmée → double diffusion.

Correctif minimal : ajouter `->where('attempts', '>=', 6)` à `$terminalQuery` (365) et au sélecteur de
rejeu (464), en gardant l'exception `contract_violation%`, terminale dès le 1er essai.

---

## A2 — `limit(500)` posé avant le filtre métier
VERDICT: CONFIRMÉ

Preuve : `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:512-518`
```php
$candidates = DB::table('failed_jobs')
    ->where('failed_at', '<', $cutoff)
    ->orderBy('id')
    ->limit(self::DRAIN_BATCH_CAP)   // 500, const ligne 62
    ->get()
    ->filter(fn ($row) => self::isOutboxFailedJob($row))
```
`isOutboxFailedJob` (`:576-590`) est en PHP, après le `get()`.

Conséquence réelle : si 500 `failed_jobs` étrangers plus anciens occupent les plus petits `id`, la purge
renvoie `deleted: 0` indéfiniment, sans message. Pas de perte de données ; blocage silencieux.

Correctif minimal : `->where('payload', 'like', '%DispatchDomainEventsJob%')` avant `->limit(...)`, le
filtre PHP restant comme garde exacte.

---

## A3 — L'audit immuable écrit `deleted` avant le DELETE
VERDICT: CONFIRMÉ

Preuve : `SyncOverviewController.php:547` puis `:566`
```php
547:  'deleted' => count($ids),        // dans $auditLog->write([...])
...
566:  $deleted = DB::table('failed_jobs')->whereIn('id', $ids)->delete();
```
`$deleted` (réel) n'est renvoyé qu'au client (`:569`), jamais reporté dans `audit_logs`.

Conséquence réelle : si le `DELETE` échoue (verrou, connexion perdue) ou ne supprime que N-k lignes
(course avec `queue:flush` ou un second opérateur), la ligne NF525 signée en chaîne atteste une purge qui
n'a pas eu lieu — une preuve immuable mensongère.

Correctif minimal : déplacer le `write()` après le `delete()` dans une transaction et journaliser
`'deleted' => (int) $deleted`, en gardant le refus « pas d'audit → rollback ».

---

## A4 — La confirmation de purge annonce le mauvais ensemble
VERDICT: CONFIRMÉ

Preuve UI : `resources/js/components/admin/observability/OutboxOverviewComponent.vue:82`
```html
{{ terminalFailures.count }} événement(s) en échec seront supprimés définitivement.
```
`terminalFailures` vient de `terminal_failures` / `domain_events` (`:471`). La route, elle, supprime
`failed_jobs` (`SyncOverviewController.php:566`), jamais `domain_events`.

Aggravant : le bouton est désactivé quand `terminalFailures.count === 0` (`:153`) — impossible donc de
purger des `failed_jobs` outbox tant qu'aucun `domain_events` n'est en échec.

Conséquence réelle : l'opérateur lit « 12 événements seront supprimés », confirme, et obtient
`deleted: 0`. Sur une action destructive irréversible, c'est un défaut de consentement éclairé.

Correctif minimal : exposer `failed_jobs.outbox_purgeables` dans `outboxOverview()` et l'utiliser au
texte (`:82`) comme à la garde de désactivation (`:153`).

---

## A5 — Drill de restauration
VERDICT: CONFIRMÉ (a) · CONFIRMÉ sur le fait, PARTIEL sur la portée (b) · CONFIRMÉ (c)

**(a) Aucun rapprochement fichier / SHA-256.**
Preuve : le cockpit renvoie les deux valeurs côte à côte sans jamais les comparer —
`SyncOverviewController.php:758` (`$dernier = basename($fichiers[0])`) et `:871` (`'restauration' => $restauration`).
Côté écran, `SystemHealthComponent.vue:226-231` :
```js
const fichierFrais = !!s && s.age_heures !== null && s.age_heures <= s.attendu_max_h;
return fichierFrais && this.restaurationOk;
```
Aucune comparaison de `s.dernier_fichier` avec `restauration.file`/`sha256`, pourtant tous deux
persistés (`app/Support/Backup/RestoreDrillResult.php:45-46`).
Conséquence réelle : drill vert de la nuit sur la sauvegarde A + sauvegarde B de ce matin jamais
restaurée ⇒ carte verte attestant B — le scénario même que le module dit corriger.

**(b) 48 h contre 26 h.**
Preuve : `app/Support/Backup/RestoreDrillResult.php:35` `public const MAX_AGE_HOURS = 48;`
contre `app/Http/Controllers/HealthController.php:254` `return $ageHours > 26 ? ... degraded`.
Les deux sondes cohabitent dans `/health/ready` (`HealthController.php:66` et `:72`) ; le drill est
quotidien (`app/Console/Kernel.php:204-205`, `dailyAt('05:00')`).
Conséquence réelle : deux exécutions manquées d'affilée restent « ok ». Grandeur différente de la
fraîcheur du fichier (d'où PARTIEL), mais tolérance égale à deux fois la cadence.

**(c) Sorties d'échec sans verdict persisté.** Énumération exhaustive de
`app/Console/Commands/Backup/BackupVerifyRestoreCommand.php` :

| ligne | cause | persiste un rouge ? |
|---|---|---|
| 108 | pilote ≠ mysql | NON |
| 116 | nom de base live absent | NON |
| 126 | base scratch = base live (refus) | NON |
| 144 | aucun fichier de sauvegarde | **OUI** (`store()` ligne 135) |
| 149 | fichier illisible | NON |
| 181 | comptes live illisibles (base injoignable) | NON |
| 207 | création de la base scratch échouée | NON |
| 219 | restauration sortie non nulle | **OUI** (`renderVerdict(false, …)` ligne 217) |
| 291 | fin normale | **OUI** (`renderVerdict` ligne 289) |

Six sorties sur neuf ne persistent rien. Conséquence réelle : fichier illisible (149), base indisponible
(181) ou droit `CREATE DATABASE` retiré (207) → FAILURE en console, tandis que
`RestoreDrillResult::current()` rend encore le vert de la veille pendant 48 h : cockpit et
`/health/ready` affichent « restauration vérifiée » alors que rien n'a été vérifié.

Correctif minimal : un `private function echec(string $raison): int` qui `store()` un rouge puis rend
`self::FAILURE`, utilisé aux lignes 108/116/126/149/181/207 ; comparer `restauration.file`+`sha256` au
fichier le plus récent dans `systemHealth()` ; aligner `MAX_AGE_HOURS` sur 26.

---

## A6 — Interrupteur : audit écrit avant la mutation
VERDICT: CONFIRMÉ

Preuve : `app/Http/Controllers/Admin/Pilotage/InterrupteurController.php:94-110`
```php
94:  $this->auditLog->write([... 'action' => 'pilotage.interrupteur.bascule', 'avant' => $avant, 'apres' => $apres ...]);
110: $etat = $this->service->regler($nom, $apres);
```
`regler()` (`app/Services/Pilotage/InterrupteurService.php:114-126`) écrit en base ligne 120
(`Settings::group(...)->set(...)`) : base indisponible ⇒ exception levée APRÈS la pose de l'audit signé.
Le seul `catch` (`:123`) n'attrape que `InvalidArgumentException` : la panne remonte en 500, l'audit reste.

L'ordre est intentionnel et commenté (lignes 91-93) — il protège contre le défaut inverse. Il reste que
le journal affirme une bascule non survenue, sans ligne compensatoire.

Conséquence réelle : `audit_logs` dit « X a coupé le paiement fractionné à 20 h 12 » alors qu'il ne l'a
jamais été — l'enquête d'incident part sur une fausse piste.

Correctif minimal : garder l'ordre, et ajouter dans un `catch (\Throwable $e)` autour de la ligne 110 une
ligne `pilotage.interrupteur.bascule.echouee` avant de relancer.

---

## A7 — Le texte de l'écran contredit l'écriture réelle
VERDICT: CONFIRMÉ

Preuve texte : `resources/js/components/admin/observability/SystemHealthComponent.vue:119`
```
Prise en compte immédiate, sans mise en ligne. Consigne dans le journal serveur, pas le journal fiscal NF525.
```
Preuve écriture : `InterrupteurController.php:94` `$this->auditLog->write([...])` —
`App\Services\Fiscal\AuditLogService`, c'est-à-dire `audit_logs`, chaîné et non supprimable.
Le `Log::info` (`:114`) subsiste, mais n'est plus la seule trace.

Conséquence réelle : faible et dans le bon sens — l'écran sous-promet une garantie qui existe. Pas de
conséquence produit immédiate.

Correctif minimal : ligne 119, « Consigné au journal d'audit chaîné (NF525), non supprimable. »

---

## A8 — Arrondi de l'âge de sauvegarde
VERDICT: PARTIEL (côté serveur DÉJÀ CORRIGÉ ; le défaut subsiste côté écran)

Preuve serveur, la comparaison au seuil est bien décimale, correctif déjà documenté :
`SyncOverviewController.php:759-760` puis `:834`
```php
759: $ageHeuresExact = (time() - filemtime($fichiers[0])) / 3600;
760: $ageHeures = (int) round($ageHeuresExact);
834: } elseif ($ageHeuresExact > 26) {
```
Identique à `HealthController.php:253-254` : l'alerte part bien à 26 h 01. L'audit externe est périmé ici.

Preuve résiduelle : c'est la valeur ARRONDIE qui est publiée (`:868`) et l'écran recalcule le vert
dessus — `SystemHealthComponent.vue:228-229`
```js
&& s.age_heures <= s.attendu_max_h   // 26 <= 26 → vrai
```
Conséquence réelle : entre 26 h 01 et 26 h 29, la carte « Dernière sauvegarde » reste verte alors que la
bande d'alertes du même écran et `/health/ready` disent `degraded`. Deux vérités dans un même panneau.

Correctif minimal : ajouter `'age_heures_exact' => $ageHeuresExact` (`:868`) et comparer là-dessus dans
`sauvegardeOk` (`:229`).

---

## A9 — Message d'exception brut en 422
VERDICT: RÉFUTÉ

Preuve : `app/Http/Controllers/Admin/DashboardController.php:55-66`
```php
\Illuminate\Support\Facades\Log::error('[dashboard] échec non métier', [... 'message' => $exception->getMessage() ...]);
return response(['status' => false, 'message' => trans('all.message.database_error_message')], 422);
```
Le message brut ne va qu'au journal. Les refus métier (`ValidationException`, `:46`) et `HttpException`
(`:49`) sont relancés intacts. L'audit se contredisait : la branche « corrigé » est la vraie sur ce HEAD.

Conséquence réelle : aucune. Correctif : sans objet.

---

## A10 — Bords de `resolveDashboardWindow()`
VERDICT: CONFIRMÉ sur les deux bords, conséquence produit marginale

Preuve : `app/Services/DashboardService.php:66-69`
```php
$premiere = $request->input('first_date');
$aPremiere = ! empty($premiere);
```
`empty("0")` vaut `true` (vérifié en PHP). Donc `first_date=0&last_date=0` court-circuite
`jourCivilParisStrict()` (`:98-113`, qui rejetterait `"0"`) et tombe sur la fenêtre par défaut (`:79-83`).
`"0000-00-00"`, lui, n'est pas vide et est bien rejeté.

Preuve 366 jours : `app/Services/DashboardService.php:122` `if ($first->diffInDays($last) > 366)`.
Vérifié : `Carbon::parse('2026-01-01')->diffInDays('2027-01-02') === 366` — non supérieur à 366, donc
accepté, soit 367 jours inclusifs.

Conséquence réelle : réels mais sans effet exploitable. `first_date=0` ne peut venir que d'un appel forgé
(le sélecteur envoie `AAAA-MM-JJ`) et rend une période valide ; le jour surnuméraire ne menace aucune
borne. Défaut de rigueur, pas de défaut produit.

Correctif minimal : `$x !== null && trim((string) $x) !== ''` au lieu de `! empty($x)` (`:68-69`) ; `>= 366`
ligne 122 si l'intention est « 366 jours inclusifs ».

---

## Récapitulatif

| # | Sujet | Verdict | Gravité réelle |
|---|---|---|---|
| A1 | `terminal_failures` sans seuil d'essais | CONFIRMÉ (sans le test allégué) | P2 |
| A2 | `limit(500)` avant le filtre métier | CONFIRMÉ | P2 |
| A3 | `deleted` audité avant le DELETE | CONFIRMÉ | **P1** |
| A4 | Confirmation UI sur le mauvais ensemble | CONFIRMÉ | **P1** |
| A5a | Aucun rapprochement fichier / SHA-256 | CONFIRMÉ | **P1** |
| A5b | 48 h contre 26 h | PARTIEL | P2 |
| A5c | 6 sorties d'échec sans verdict persisté | CONFIRMÉ | **P1** |
| A6 | Audit interrupteur avant la mutation | CONFIRMÉ | **P1** |
| A7 | Texte « journal serveur » périmé | CONFIRMÉ | P3 |
| A8 | Arrondi de l'âge de sauvegarde | PARTIEL (serveur corrigé, écran non) | P2 |
| A9 | Exception brute en 422 | RÉFUTÉ | — |
| A10 | `empty()` et 367 jours | CONFIRMÉ, sans conséquence produit | P3 |

Total : 8 CONFIRMÉ · 2 PARTIEL · 1 RÉFUTÉ (A5 éclaté en trois verdicts).

P1_REELS: 5
