# G2 — Le cockpit outbox dit la vérité, et agit juste

Exécution du 2026-09-03 · branche `pos/category-first-caisse-2026-06-23` · HEAD `28cd79d5a`
Arbre PARTAGÉ (trois autres chantiers en cours) · **rien n'est commité**.

**Verdict : 7 défauts sur 7 corrigés, chacun prouvé par un banc qui rougissait avant.**
Un point de la condition de sortie n'est **pas** tenu : la vérification visuelle au
navigateur (§7). La raison est donnée, et elle n'est pas un oubli.

Preuve rouge intégrale : `reports/supervision/2026-09-03/G2-bancs-mordent.txt`
(sortie brute avant correctif, puis les mêmes bancs verts après).

---

## 1. Les sept défauts, un par un

### V-02 — La relance ne disait jamais combien · T2.1

**Banc** : `tests/js/outboxRelanceCompteVrai.spec.js` (4 cas).
Il monte le composant, fait répondre le serveur `{requeued: 37}` et exige « 37 » à l'écran.

**Rouge avant** (extrait de `G2-bancs-mordent.txt`) :

```
AssertionError: le nombre remis en file doit être à l’écran: expected 'Relance demandée.' to contain '37'
AssertionError: expected 'Relance demandée.' to contain '0'
AssertionError: expected 'Relance demandée.' to contain '4'
```

Trois cas rouges, un vert : le 4ᵉ (`{retried: 12}`) était vert **volontairement** — c'est
l'assertion de préservation du repli de dépréciation, elle documente le comportement à ne
pas perdre. Les trois autres mesurent le défaut.

**Ce que le banc prouve** : l'écran affiche le nombre réellement remis en file, y compris
« 0 » ; et une relance partiellement en échec (`audit_failed` / `dispatch_failed`) le dit
au lieu de passer pour un succès.

**Correctif** : `OutboxOverviewComponent.vue:643-666` — lecture de `data.requeued`, avec
`data.retried` conservé en repli daté (« à retirer après V1.1 »). Le retour passe en
`role="alert"` quand le serveur signale des échecs partiels.

---

### V-03 — Claims en vol et orphelins invisibles · T2.2

**Banc** : `tests/js/outboxEtatsClaimsVisibles.spec.js` (7 cas).
Charge `in_flight: 12`, `stale_claimed: 2149` + 2 lignes, `terminal_failures: 5/2`.

**Rouge avant** : les 7 cas.

```
Error: Unable to get [data-testid="outbox-in-flight-count"] within: <!-- …
Error: Unable to get [data-testid="outbox-stale-claimed-count"] within: <!-- …
Error: Unable to get [data-testid="outbox-terminal-count"] within: <!-- …
Error: Unable to get [data-testid="outbox-claims"] within: <!-- …
AssertionError: expected false to be true   (liste des orphelins)
```

Les trois états étaient **chargés puis jetés** : affectés dans `loadAll()`, absents du
template. Le cockpit pouvait connaître 2 149 orphelins sans en montrer un seul.

**Ce que le banc prouve** : les trois nombres sont rendus, la liste des orphelins est
consultable ligne par ligne, le seuil affiché (10 min) est celui que le serveur applique,
et la zone porte `aria-live="polite"`.

**Correctif** : `OutboxOverviewComponent.vue:248-339` (nouveau bloc `outbox-claims`) et
`:532-540` (valeur initiale `stale_after_minutes` 5 → 10 ; l'écran annonçait un seuil que
le serveur n'applique pas).

---

### V-04 — La purge comptait la mauvaise table · T2.3

**Banc** : `tests/js/outboxPurgeAnnonceLaBonneTable.spec.js` (5 cas).

**Rouge avant** : les 5 cas.

```
AssertionError: rien à purger ⇒ bouton désactivé: expected undefined not to be undefined
AssertionError: le nombre de jobs réellement purgeables: expected 'Purger les échecs de plus de 24 h ?5 …' to contain '3'
AssertionError: il y a bien 3 jobs à purger: expected '' to be undefined
AssertionError: ce sont des travaux en échec, pas des événements: expected '2 événement(s) supprimé(s) …'
```

La confirmation affichait littéralement « 5 » (des `domain_events`) pendant que l'action
vide `failed_jobs`.

**Ce que le banc prouve** : chaque bouton suit le compteur de **sa** table, et le message
de retour nomme les travaux en échec, pas des événements.

**Correctif — serveur** : deux compteurs d'ACTION, calculés par le **même code** que
l'action correspondante (donc impossibles à faire diverger) :
`SyncOverviewController.php:415-424` (`replayable_events`, helper `applyReplayableCriteria`
partagé avec `outboxRetryFailed`) et `:457-463` + `:687-716` (`purgeable_failed_jobs`,
helper `outboxFailedJobCandidates` partagé avec `outboxDrainFailed`).
**Correctif — écran** : `OutboxOverviewComponent.vue:81-98` (confirmation), `:147-181`
(les deux boutons), `:690-698` (message de retour).

> **Ajout au-delà de la lettre du GOAL, assumé** : `replayable_events`. Sans lui, le
> resserrement de V-10 aurait désactivé « Rejouer » pour les événements à `attempts < 6` —
> or ce sont exactement ceux qu'un exploitant veut relancer à la main **quand le worker est
> mort et que la reprise automatique ne viendra jamais**. Corriger V-10 sans ce compteur
> aurait fabriqué une régression fonctionnelle. Couvert par
> `TerminaliteExigeAttemptsEpuisesTest::test_le_bouton_rejouer_suit_ce_qui_est_reellement_rejouable`.

---

### V-05 — L'audit de la purge mentait quand elle ratait · T2.4 (le plus grave)

**Banc** : `tests/Feature/Observability/OutboxDrainAuditApresSuppressionTest.php` (5 cas).
Il injecte, via `DB::connection()->beforeExecuting()`, un comportement dans la fenêtre
exacte entre la sélection et le `DELETE` — pas un mock du contrôleur, la connexion réelle.

**Rouge avant** : 3 cas sur 5.

```
✗ une suppression partielle est auditee pour ce qu elle a reellement supprime
  Failed asserting that 5 is identical to 2.
✗ une suppression qui echoue ne laisse aucune ligne affirmant la suppression
  Failed asserting that actual size 1 matches expected size 0.
✗ une suppression qui n atteint aucune ligne ne pretend pas le contraire
  Failed asserting that 5 is identical to 0.
```

Traduction : une suppression de 2 lignes sur 5 s'auditait « deleted: 5 ». Une suppression
totalement en échec laissait **une ligne d'audit affirmant la suppression** — immuable,
signée en chaîne HMAC, donc incorrigeable et opposable.

**Ce que le banc prouve** : l'audit porte le nombre constaté (2, 0, 5 selon le cas) ;
aucune ligne n'existe quand rien n'a été supprimé ; l'écriture reste **un seul ajout**
(pas d'« intention » suivie d'une « correction ») et la ligne reste signée.

**Correctif** : `SyncOverviewController.php:601-660`. Suppression **puis** audit du nombre
réel, les deux dans la même `DB::transaction`. Le payload distingue désormais `deleted`
(constaté) de `candidates` (sélectionné) — l'écart est en soi une information forensique.

**Zone gelée** : `AuditLogService` n'est **pas** touché (diff vide, §5). Seul l'APPEL est
déplacé. `audit_logs` reste en ajout seul ; si l'audit échoue, la transaction est annulée
et rien n'est supprimé — l'invariant préexistant
`OutboxWebActionsAreAuditedTest::test_sans_audit_possible_la_purge_ne_supprime_rien` reste
vert. Vérifié aussi : `AuditLogService` ne garde **aucun** état de chaîne hors base (son
seul usage de `Cache` est le verrou, ligne 101), donc annuler la transaction ne peut pas
désynchroniser la chaîne. `php artisan fiscal:verify-chain --all` → **CHAIN OK sur les
6 branches actives** (`G2-chaine-nf525.txt`).

---

### V-06 — La sonde regardait la mauvaise file · T2.5

**Banc** : `tests/Feature/Observability/SondeWorkerBorneeFileHighTest.php` (6 cas).

**Rouge avant** : 4 cas sur 6 (les 2 verts sont des assertions de préservation : un
travail réservé sur la file outbox reste un signal positif, et `queue_high` décrit bien
la file du job).

```
✗ un worker vivant sur une autre file ne rend pas le worker outbox vivant
✗ la file default non plus
✗ l age du dernier signal ignore les autres files
✗ la methode declaree nomme la file reellement sondee
  Failed asserting that 'heuristic_jobs_reserved_or_event_delivered_within_90s' contains "high".
```

**Sur le nom de la file — vérifié avant de figer quoi que ce soit**, comme demandé :
`config/queue.php` ne déclare **aucune** file « outbox ». Il déclare
`monitored_queues = ['default', 'high', 'notifications']` (ligne 121), c'est-à-dire
l'inverse de ce qu'il faut ici : la liste de TOUTES les files surveillées. La seule source
de vérité est `DispatchDomainEventsJob::__construct` → `onQueue('high')` (ligne 46).
Je n'ai donc **pas** figé `'high'` en dur : `outboxQueueName()`
(`SyncOverviewController.php:73-95`) **lit la valeur sur le job**. Un `onQueue()` déplacé
emmène la sonde avec lui. `describeQueueLane()` reçoit désormais la même valeur, ce qui
supprime aussi le `'high'` en dur qui restait ligne 398.

**Correctif** : `SyncOverviewController.php:800-816` (`->where('queue', $outboxQueue)`) et
`:868-874` — la valeur de `method` **nomme** la file sondée, sinon la sonde n'est pas
contredictible.

---

### V-10 — « Terminal » se disait dès le premier essai · T2.6

**Banc** : `tests/Feature/Observability/TerminaliteExigeAttemptsEpuisesTest.php` (7 cas).

**Rouge avant** : 5 cas sur 7.

```
✗ un evenement au premier essai est en cours de reprise pas terminal
  Failed asserting that 1 is identical to 0.
✗ un evenement a l avant derniere tentative n est pas terminal non plus
✗ le bouton rejouer suit ce qui est reellement rejouable
  Failed asserting that 3 is identical to 2.
✗ un echec hors fenetre d age n est plus rejouable
  Undefined array key "replayable_events"
```

**Votre précision est confirmée par la mesure** : aucun banc ne verrouillait `attempts=1`
comme terminal. Le seul cas `attempts=1` compté terminal
(`OutboxDeliverySemanticsTest.php:117`) est un `contract_violation` — et il **reste vert**,
parce que le prédicat conserve cette exception : le job appelle `$this->fail()`, aucune
reprise ne viendra, quel que soit `attempts`.

**Correctif** : `SyncOverviewController.php:396-411` — terminal ⇔
`attempts >= $tries` **OU** `last_error LIKE 'contract_violation%'`. Le seuil est lu sur le
job (`outboxJobTries()`), pas recopié. `attempts_threshold` est publié dans la réponse
pour que l'écran puisse dire sur quoi il se fonde.

---

### V-11 — La purge se laissait affamer · T2.7

**Banc** : `tests/Feature/Observability/PurgeNeSeLaissePasAffamerTest.php` (4 cas).
500 `failed_jobs` étrangers plus anciens qu'un candidat outbox.

**Rouge avant** : les 4 cas.

```
✗ le candidat outbox est atteint malgre 500 travaux etrangers plus anciens
  Failed asserting that 0 is identical to 1.
✗ le compteur du bouton ne se laisse pas affamer non plus
  Undefined array key "purgeable_failed_jobs"
```

Le plafond de 500 s'appliquait **avant** le filtre PHP sur la classe : les 500 étrangers
consommaient tout le lot, le bouton répondait « 0 supprimé » indéfiniment sans dire
pourquoi.

**Correctif** : `SyncOverviewController.php:687-716` — pré-filtre SQL
`payload LIKE '%DispatchDomainEventsJob%'` **avant** `limit(500)`, puis verdict exact
maintenu en PHP par `isOutboxFailedJob()`. Le `LIKE` porte sur le nom court **volontairement** :
le nom pleinement qualifié serait doublement fragile (MySQL traite `\` comme échappement
dans `LIKE`, et le payload JSON double déjà les antislashes) — c'est le genre de filtre qui
passe au vert en ne filtrant rien. Un banc dédié vérifie qu'un job étranger dont le
**texte** évoque la classe outbox n'est ni compté ni purgé.

---

## 2. Résultats des bancs

| Banc (tous nouveaux) | Cas | Rouges avant | Après |
|---|---|---|---|
| `tests/js/outboxRelanceCompteVrai.spec.js` | 4 | 3 (+1 préservation) | ✅ 4 |
| `tests/js/outboxEtatsClaimsVisibles.spec.js` | 7 | 7 | ✅ 7 |
| `tests/js/outboxPurgeAnnonceLaBonneTable.spec.js` | 5 | 5 | ✅ 5 |
| `OutboxDrainAuditApresSuppressionTest.php` | 5 | 3 (+2 préservation) | ✅ 5 |
| `SondeWorkerBorneeFileHighTest.php` | 6 | 4 (+2 préservation) | ✅ 6 |
| `TerminaliteExigeAttemptsEpuisesTest.php` | 7 | 5 (+2 préservation) | ✅ 7 |
| `PurgeNeSeLaissePasAffamerTest.php` | 4 | 4 | ✅ 4 |

**Un cas vert du premier coup n'est pas passé sous silence** : chacun des 7 cas verts
d'emblée est une assertion de PRÉSERVATION explicite (le repli `retried`, la violation de
contrat terminale, `queue_high`, le cas nominal de la purge…). Ils ne mesurent pas le
défaut ; ils verrouillent ce qu'il ne faut pas casser en le corrigeant. Aucun cas censé
mesurer un défaut n'était vert avant correctif.

**Un banc écrit trop étroit, réécrit** : mon assertion `/travaux|jobs/i` ne reconnaissait
pas le libellé « travail(aux) ». Corrigée, puis **re-prouvée mordante** en réinjectant le
libellé fautif dans le composant (« 2 événement(s) supprimé(s) ») : 1 rouge, puis
restauration et 5 verts. Trace dans `G2-bancs-mordent.txt`.

### Non-régression

- **PHPUnit `tests/Feature/Observability` en entier : 134 passés, 0 échec.** Couvre les
  quatre non-régressions exigées (`OutboxOverviewControllerTest`,
  `OutboxDeliverySemanticsTest`, `OutboxWebActionsAreAuditedTest`,
  `SyncOverviewControllerTest`) plus 11 autres fichiers du même domaine.
- **Vitest : 43 passés, 0 échec** sur les 3 bancs nouveaux + les 2 non-régressions exigées
  (`observabilityOutboxRoute`, `outboxOverviewPanneReseau`) + 2 sentinelles voisines qui
  montent ce composant (`datesLisiblesEnFrancais`, `gestionAccessibleSentinel`).
- `php artisan fiscal:verify-chain --all` → CHAIN OK sur les 6 branches actives.

---

## 3. Un rouge qui n'est PAS le mien, signalé et non touché

`tests/Feature/Grok/DashboardControlLoopOptimizationsTest::test_system_health_failed_fetch_is_not_no_backup`
**échoue** :

```
$this->assertStringContainsString('journal serveur, pas le journal fiscal', $src);
  → resources/js/components/admin/observability/SystemHealthComponent.vue
```

Attribution vérifiée, pas supposée :
- la chaîne **existe** dans la version HEAD du fichier (`git show HEAD:… | grep -c` → 1) ;
- elle est **absente** de la version présente sur le disque (`grep -c` → 0) ;
- ce fichier est `M` dans l'arbre partagé, et il fait partie de **mes fichiers interdits**.

C'est donc un chantier voisin qui est en train de retirer cette chaîne de son propre
fichier et qui casse sa propre sentinelle. Je n'y touche pas et je ne le corrige pas.
Les 16 autres cas de ce fichier passent, dont
`test_outbox_queue_lane_uses_queue_size` qui scrute *mon* contrôleur.

---

## 4. Ce que je n'ai PAS fait, et pourquoi

### La vérification visuelle au navigateur — non faite, délibérément

Le GOAL demande trois captures à `/admin/observability/outbox`. **Je ne les ai pas
prises, et les prendre aurait été pire que de ne pas les prendre.**

Mesure, pas supposition :

```
public/js/app.js            → 2 438 374 octets, modifié à 02:10
OutboxOverviewComponent.vue → modifié à 02:28
grep -c "outbox-in-flight-count" public/js/app.js → 0
```

Le lot compilé servi au navigateur **ne contient pas** mes modifications, et vous m'avez
explicitement interdit `npm run production`. Une capture prise maintenant aurait
photographié l'**ancien** écran : elle n'aurait rien prouvé de mon correctif, tout en
ayant l'apparence d'une preuve. C'est exactement le piège « instrument avant produit » de
CLAUDE.md §3ter.

**À faire après votre compilation** (`npm run production`), sur `:8766` en compte Admin :
1. état nominal — vérifier que le bloc « Claims en cours, orphelins et échecs terminaux »
   affiche trois nombres et, s'il y en a, la table des orphelins ;
2. file `high` morte avec un autre worker vivant — insérer un `jobs` réservé sur
   `notifications` : la carte `queue:work` doit rester **rouge** ;
3. purge confirmée puis annulée — la confirmation doit annoncer le nombre de
   **travaux** purgeables, jamais celui des événements terminaux ;
   zéro erreur console ; **un refus WebSocket doit faire échouer la ronde**, pas être
   collecté en silence.

Les trois états sont déjà verrouillés au niveau composant (Vitest monte les sources), donc
la capture confirmera un rendu, elle ne découvrira pas une régression logique.

### La condition de sortie « deux rondes identiques »

Une seule ronde exécutée. La seconde suppose la campagne navigateur ci-dessus.

### Ce que je n'ai pas eu besoin de toucher

`app/Jobs/DispatchDomainEventsJob.php` : **lu, non modifié**. T2.6 se corrige entièrement
côté lecture — le job a raison d'écrire `last_error` dès le premier essai (c'est une trace),
c'est le cockpit qui avait tort de la lire comme un verdict. Aucun fichier de la liste
interdite n'a été ouvert en écriture.

---

## 5. Portes de sûreté

```
$ bash .cursor/hooks/safety-check.sh
[safety-check] Checking 15 frozen zones...
[safety-check] Frozen zones: OK
[safety-check] Checking PHP syntax...
[safety-check] No staged PHP files.
[safety-check] Passed. Proceed with execution.
→ EXIT 0 (PASS)
```

```
$ git diff HEAD --stat -- <les 15 fichiers de CLAUDE.md §7>
(vide — aucune ligne touchée)
```

`AuditLogService.php` inclus : diff vide. Seul l'appel a bougé, le service non.

---

## 6. Fichiers touchés — RIEN N'EST COMMITÉ

**Modifiés (4)**
- `app/Http/Controllers/Admin/Observability/SyncOverviewController.php`
- `resources/js/components/admin/observability/OutboxOverviewComponent.vue`
- `docs/OUTBOX_PATTERN.md`
- `docs/runbooks/OBSERVABILITY_OUTBOX_DASHBOARD.md`

**Créés (7 bancs + 2 preuves)**
- `tests/js/outboxRelanceCompteVrai.spec.js`
- `tests/js/outboxEtatsClaimsVisibles.spec.js`
- `tests/js/outboxPurgeAnnonceLaBonneTable.spec.js`
- `tests/Feature/Observability/OutboxDrainAuditApresSuppressionTest.php`
- `tests/Feature/Observability/SondeWorkerBorneeFileHighTest.php`
- `tests/Feature/Observability/TerminaliteExigeAttemptsEpuisesTest.php`
- `tests/Feature/Observability/PurgeNeSeLaissePasAffamerTest.php`
- `reports/supervision/2026-09-03/G2-bancs-mordent.txt`
- `reports/supervision/2026-09-03/G2-chaine-nf525.txt`

### ⚠ Avertissement pour la mise en commit — arbre partagé

**`SyncOverviewController.php` porte le travail de DEUX chantiers.** Sa méthode
`systemHealth()` était **déjà modifiée** quand j'ai commencé, par le chantier G4
(`RestoreDrillResult::cheminSauvegardeCourante()`, `rapprocher()`, `age_heures` décimal,
`fraiche`) — marqueurs `[G4 2026-09-03 · T4.x]`, hunks à partir de la ligne ~902 de
l'ancien fichier. Ce n'est pas mon travail et je ne l'ai pas touché.

Le `git diff --stat` de ce fichier (272 lignes) **n'est donc pas entièrement à moi**. Mes
hunks sont identifiables sans ambiguïté par le marqueur `[G2 2026-09-03` (9 occurrences,
lignes 66, 73, 396, 415, 457, 601, 662, 687, 800) et sont tous confinés à
`outboxOverview` / `outboxRetryFailed` / `outboxDrainFailed` / `probeHealth` et aux
constantes du haut de classe. **Commitez par hunk**, pas par fichier.

Idem pour `reports/supervision/2026-09-03/` : d'autres chantiers (G3, G4, G5, G7, G8) y
déposent leurs propres rapports.

---

## 7. Contrat de réponse — ce qui a changé pour un consommateur

`GET /api/admin/observability/outbox` — **trois clés ajoutées, aucune retirée** :

- `terminal_failures.attempts_threshold` (le seuil sur lequel le compteur se fonde) ;
- `replayable_events: {count, max_age_days}` ;
- `purgeable_failed_jobs: {count, older_than_hours, capped}`.

`terminal_failures.count` **rétrécit** : il ne compte plus les événements encore dans leur
courbe de reprise. C'est la correction de V-10 ; tout consommateur qui alertait sur ce
compteur alertait jusqu'ici sur du bruit.

Le composant dégrade proprement si un serveur antérieur n'envoie pas les nouvelles clés :
« Rejouer » retombe sur l'ancien critère, « Purger » retombe sur **zéro** — jamais sur
`failed_jobs.count`, parce qu'on n'invite pas à une suppression définitive sur une mesure
absente.

---

## 8. Addendum — trois fichiers interdits sont modifiés dans l'arbre, pas par moi

Contrôle final, à ne pas mal lire :

```
$ git status --porcelain app/Support/Backup app/Console/Commands/Backup app/Http/Controllers/HealthController.php
 M app/Console/Commands/Backup/BackupVerifyRestoreCommand.php
 M app/Http/Controllers/HealthController.php
 M app/Support/Backup/RestoreDrillResult.php
```

Ces trois fichiers font partie de ma liste interdite et **je ne les ai jamais ouverts en
écriture**. Ils n'étaient pas modifiés au démarrage de ma session (l'instantané `git status`
d'ouverture ne listait que `reports/grok/JOURNAL.md`,
`tests/Feature/Grok/ComposerMerchantLiesTest.php` et `tests/js/productComposerEditor.spec.js`) :
ils l'ont été **pendant** ma session, par le chantier G4 qui tourne en parallèle dans le
même arbre — le même qui a modifié `systemHealth()` dans `SyncOverviewController.php` (§6)
et `SystemHealthComponent.vue` (§3).

Mes seules écritures, exhaustivement : les 4 fichiers modifiés et les 9 fichiers créés
listés au §6. Rien d'autre.
