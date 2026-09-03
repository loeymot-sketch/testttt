# G4 — La sauvegarde prouve la sauvegarde du jour · rapport d'exécution

Date : 2026-09-03 · Branche : `pos/category-first-caisse-2026-06-23` · HEAD au départ : `23c2ef26c`
GOAL : `plans/goals-2026-09-03/G4_SAUVEGARDE_PREUVE_REELLE.md`
Défauts traités : **V-08**, **V-09** (P1) · **V-12** (P2) · **N-01** (P2)

**Rien n'est commité.** L'arbre de travail est laissé modifié, comme demandé.

---

## 1. Ce que chaque banc prouve, et la preuve qu'il rougissait avant

Sortie rouge intégrale : `reports/supervision/2026-09-03/G4-bancs-mordent.txt`.
Chaque banc a été écrit et lancé **avant** le correctif correspondant.

### T4.1 — V-08 · `tests/Feature/Observability/RestoreDrillAttesteFichierCourantTest.php` (NOUVEAU)

Prouve qu'un drill vert ne vaut plus rien s'il ne porte pas sur **la sauvegarde présente** —
sur le cockpit **et** sur `/health/ready`, et sur les deux façons de différer (autre nom ;
même nom, autre empreinte).

Rouge avant correctif : **4 échoués / 1 passé**.
Le seul passé est le garde-fou « le drill du fichier courant reste vert » — il devait
passer avant comme après : c'est lui qui interdit le faux correctif « tout mettre en rouge ».

```
• un drill vert sur un autre fichier ne peut pas afficher vert   -> 'green' au lieu de non-'green'
• readiness degrade quand le drill porte sur un autre fichier    -> 'ok' au lieu de 'degraded'
• le meme nom avec une autre empreinte ne passe pas pour preuve  -> 'green'
• un drill sans empreinte enregistree ne prouve pas le fichier   -> 'green'
```

### T4.2 — V-09 · `tests/Feature/Backup/RestoreDrillPersisteTousLesEchecsTest.php` (NOUVEAU)

Un cas par sortie `FAILURE` non persistée (lignes **108, 116, 126, 149, 181, 207** de
`BackupVerifyRestoreCommand` avant correctif). Chaque cas pose d'abord le **vert de la
veille**, déclenche la sortie, puis exige que `RestoreDrillResult::current()` rende `failed`.

Rouge avant correctif : **6 échoués / 1 passé** — les six rendaient `'green'` (le succès de
la veille survivait à l'échec du jour). Le passé est le garde-fou « le vert légitime survit à
l'absence d'exécution ».

Le cas 207 étant le plus indirect, j'ai instrumenté temporairement le banc pour **prouver
qu'il emprunte bien cette sortie-là** (sortie capturée dans le fichier de preuves) :
`Failed to (re)create scratch database.` — le message de la ligne 205, `return` ligne 207.
L'instrumentation a été retirée aussitôt.

### T4.3 — V-12 · `tests/Feature/Observability/SystemHealthRestoreDrillTest.php` (cas AJOUTÉS)

Bornes 25 h 59 / 26 h 00 / 26 h 01 / 27 h / 48 h sur `RestoreDrillResult::current()` — la
couche qui **décide**, pas une projection d'écran qui pourrait masquer le seuil réel.

Rouge avant correctif : **4 échoués / 2 passés**.
Rougissaient : 26 h 01, 27 h, 48 h (tous rendus `'green'` avec `MAX_AGE_HOURS = 48`) et
l'assertion du seuil (`Failed asserting that 48 is identical to 26`).
Passaient déjà, à raison : 25 h 59 et 26 h 00.

### T4.4 — N-01 · `tests/Feature/Observability/CockpitEtReadinessMemeAgeSauvegardeTest.php` (NOUVEAU)

Pour 26 h 01, 26 h 15 et 26 h 29, exige que les deux surfaces rendent le **même** verdict, et
que la valeur **publiée** ne permette aucune lecture « dans les clous ».

Rouge avant correctif : **5 échoués / 0 passé**. Cœur du défaut :
`Failed asserting that 26.0 is greater than 26.0` — le serveur publiait l'âge arrondi.

### T4.4 — N-01 · `tests/js/systemHealthAgeSauvegardeNonArrondi.spec.js` (NOUVEAU)

Rouge avant correctif : **3 échoués / 2 passés**. Le rendu exact du défaut a été capturé :

```html
<p class="mt-1 text-lg font-semibold text-emerald-700">il y a 26 h</p>
```

**Correction de mon propre banc, et re-preuve.** Ma première version assertait sur la carte
**entière** — or elle porte **deux** lignes colorées (âge du fichier, verdict de restauration) :
le banc pouvait donc conclure sur la mauvaise ligne. Je l'ai resserré sur la ligne d'âge
(`text-lg font-semibold …`), puis j'ai **remis le défaut** dans le composant pour vérifier qu'il
mord encore. Il mord :

```
❯ 26 h 20 publié comme « 26 » … → expected 'text-emerald-700' to be 'text-red-700'
❯ l'âge décimal reste lisible … → expected 'Dernière sauvegardeil y a 26.33 h…' to contain 'il y a 26 h'
❯ un verdict de fraîcheur absent … → expected 'text-emerald-700' to be 'text-red-700'
```

Correctif remis en place aussitôt. La trace est dans le fichier de preuves.

---

## 2. Fichiers de production modifiés

| Fichier | Lignes touchées (`git diff HEAD --unified=0`) | Ce qui change |
|---|---|---|
| `app/Support/Backup/RestoreDrillResult.php` | +179 / -4 · hunks `@@ -34,2 +34,20 @@`, `@@ -145,0 +164,143 @@`, `@@ -153,2 +314,6 @@`, `@@ -157,0 +323,10 @@` | `MAX_AGE_HOURS` 48 → **26** ; nouveau statut `autre_fichier` ; `cheminSauvegardeCourante()` (une seule règle pour désigner « la dernière sauvegarde ») ; `empreinteDe()` (SHA-256 mémorisé par nom+date+taille) ; `rapprocher()` (le cœur de V-08) ; `alerte()` gagne la branche `autre_fichier` et compte le retard en heures sous 48 h |
| `app/Http/Controllers/Admin/Observability/SyncOverviewController.php` | +22 / -11 · hunks à 750, 757, 767, 871, 878 | le « dernier fichier » vient de `cheminSauvegardeCourante()` ; le verdict du drill est **rapproché** de ce fichier ; `age_heures` publié **en décimal** ; nouvelle clé **`fraiche`** = le verdict de fraîcheur décidé par le serveur |
| `app/Http/Controllers/HealthController.php` | +8 / -1 · hunk `@@ -266 +266,8 @@` | `checkRestoreDrill()` rapproche le verdict de la sauvegarde présente, exactement comme le cockpit |
| `app/Console/Commands/Backup/BackupVerifyRestoreCommand.php` | +89 / -41 · 15 hunks entre 89 et 661 | `handle()` devient une enveloppe ; le corps passe dans `executer()` ; **un enregistreur unique** `persisterVerdict()` ; `echec()` (dit **et** inscrit) remplace les 6 `return self::FAILURE` muets ; `renderVerdict()` passe par le même enregistreur ; filet dans `handle()` pour toute sortie non nulle sans verdict, et pour toute exception |
| `resources/js/components/admin/observability/SystemHealthComponent.vue` | +26 / -8 · hunks à 219, 236, 245, 251 | `sauvegardeOk` **ne recalcule plus** : il lit `sauvegarde.fraiche` ; l'arrondi ne sert plus qu'à l'affichage ; branche `autre_fichier` ; le retard de drill se dit en heures sous 48 h |

Aucun autre fichier de production touché. Aucun refactor opportuniste.

### Fichiers de test

| Fichier | État |
|---|---|
| `tests/Feature/Observability/RestoreDrillAttesteFichierCourantTest.php` | NOUVEAU — 5 cas |
| `tests/Feature/Backup/RestoreDrillPersisteTousLesEchecsTest.php` | NOUVEAU — 7 cas (6 sorties + garde-fou) |
| `tests/Feature/Observability/CockpitEtReadinessMemeAgeSauvegardeTest.php` | NOUVEAU — 5 cas |
| `tests/js/systemHealthAgeSauvegardeNonArrondi.spec.js` | NOUVEAU — 5 cas |
| `tests/Feature/Observability/SystemHealthRestoreDrillTest.php` | MODIFIÉ — voir §3 |
| `tests/js/systemHealthSauvegardeRestauration.spec.js` | MODIFIÉ — voir §3 |

---

## 3. Tests existants mis à jour — le contrat a changé, je le dis

Comme annoncé dans la consigne, le passage de `MAX_AGE_HOURS` à 26 devait faire rougir des
tests écrits pour 48. Le rapprochement fichier↔drill (T4.1) en a fait rougir d'autres. **Aucun
n'a été supprimé ni assoupli** ; chacun a été mis au niveau du nouveau contrat, avec le motif
écrit dans le fichier.

1. **`SystemHealthRestoreDrillTest::test_un_drill_vert_et_frais_ne_declenche_aucune_alerte_de_restauration`**
   et **`::test_readiness_publie_le_sous_systeme_restauration`**
   Rougissaient ainsi : `'green'` attendu, **`'autre_fichier'`** obtenu ; `'ok'` attendu,
   **`'degraded'`** obtenu. Motif : ces tests posaient un drill vert sur un nom de fichier
   **inventé** (`daily-2026-09-02.sql.gz`), qui n'existe pas dans le dossier. C'est très
   exactement ce que T4.1 doit refuser. Ils déposent désormais une vraie sauvegarde et
   utilisent son empreinte réelle (helper `deposerSauvegardeAttestee()` + `tearDown` de
   nettoyage). Le test prouve toujours la même chose, mais sur un cas réel.

2. **`tests/js/systemHealthSauvegardeRestauration.spec.js`** — six gabarits mis à jour :
   `max_age_hours: 48` → `26`, et ajout de `sauvegarde.fraiche` (le verdict que le serveur
   publie désormais). Sans cela, les gabarits décriraient une réponse que le serveur n'envoie
   plus. Un seul test rougissait réellement (« fichier frais ET restauration verte → carte
   verte ») ; les autres ont été alignés pour ne pas laisser de gabarit mensonger.

3. **Aucun test existant ne dépendait de `MAX_AGE_HOURS = 48`** autrement que par ces
   gabarits : `test_un_drill_vert_mais_perime_est_signale` utilise 60 h, périmé sous les deux
   seuils. Le seul autre usage du chiffre 48 était le libellé « depuis N jours », corrigé.

---

## 4. T4.5 — la commande exercée pour de vrai

Journal complet : `reports/supervision/2026-09-03/G4-drill-reel.txt`.

- **Filtre `*.sql.gz`** : `not-sql.gz` recréé (19 o) puis retiré. **Rejeté**, comme attendu —
  le suffixe est `-sql.gz`, pas `.sql.gz`. (Le fichier n'était en fait plus dans l'arbre au
  moment où j'ai commencé ; je l'ai recréé pour faire la vérification, puis supprimé.)
- **Sauvegarde réelle** : `php artisan backup:verify-restore` → dump `daily-2026-06-24.sql.gz`
  restauré dans `foodking_e2e_restore_scratch`, chaîne NF525 **OK sur les 4 branches**, mais
  verdict **RED** : `restored schema has 88 tables but live has 113 (partial restore?)`.
  Code de sortie **1**. Verdict **persisté** avec nom, empreinte, durée et raison.
- **Fichier volontairement tronqué** (120 000 o sur 2 815 404) → `gunzip: unexpected end of
  file`, `ERROR 1064 at line 261`, verdict **RED** `restore process exited non-zero`,
  code **1**, **persisté**.
- Base jetable bien supprimée après coup (`information_schema` : 0).
- État remis à la vérité de la machine en rejouant le drill réel.

**Preuve sur le serveur réellement servi** (`127.0.0.1:8766`, sonde publique
`/api/health/ready`, aucune compilation requise) — quatre états mesurés à la suite :

| État injecté | Réponse |
|---|---|
| drill réel (RED) | `degraded` — « restauration de vérification ÉCHOUÉE : restored schema has 88 tables… » |
| **le défaut V-08** : vert sur `daily-2026-06-24.sql.gz` alors que la dernière sauvegarde est `pre-8axes-2026-08-05.sql.gz` | `degraded` — « la restauration vérifiée porte sur « daily-2026-06-24.sql.gz », pas sur la dernière sauvegarde « pre-8axes-2026-08-05.sql.gz » » |
| **garde-fou** : vert sur le fichier courant, avec sa vraie empreinte | `ok` — « restore verified 3.0h ago (pre-8axes-2026-08-05.sql.gz) » |
| même nom, empreinte faussée | `degraded` — « … a été réécrit depuis la restauration vérifiée : empreinte SHA-256 différente » |

Avant correctif, les trois derniers états répondaient `ok`.

---

## 5. Vérifications de sécurité

```
$ bash .cursor/hooks/safety-check.sh
[safety-check] Checking 15 frozen zones...
[safety-check] Frozen zones: OK
[safety-check] Checking PHP syntax...
[safety-check] No staged PHP files.
[safety-check] Passed. Proceed with execution.
=== exit=0
```

```
$ git diff HEAD --stat -- <les 15 fichiers de zone gelée de CLAUDE.md §7>
=== FIN (vide attendu) ===
```

Sortie **vide** : aucune ligne de zone gelée touchée.

Aucun `git add`, aucun `git commit`, aucun `git push`. Aucun `composer dump-autoload`.

---

## 6. Résultats — deux rondes identiques

| Ronde | PHPUnit (14 classes : bancs du GOAL + non-régression + surfaces santé adjacentes) | Vitest (3 specs) |
|---|---|---|
| 1 | 17 + 61 + 71 verts | 27 verts |
| 2 | **130 passed** | **27 passed** |

Non-régression exigée par le GOAL, toutes vertes :
`SystemHealthRestoreDrillTest` (17) · `SystemHealthTest` · `BackupVerifyRestoreLogicTest` ·
`tests/js/systemHealthSauvegardeRestauration.spec.js` (6).
Élargie de mon initiative, également vertes : `HealthControllerTest`, `SchedulerDeadManTest`,
`DashboardControlAuditFixesTest`, `HealthzDiffusionNonPusherTest`, `PosSystemHealthTest`,
`PosSystemHealthQueueRecoveryTest`, `HealthQueueWorkerContractViolationTest`,
`SyncOverviewControllerTest` — toutes touchent les surfaces que j'ai modifiées.

**Aucune suite complète lancée** : une autre session travaille dans le même arbre.

---

## 7. Ce que je n'ai PAS pu faire, et pourquoi

### 7.1 Les captures d'écran du cockpit (section « Surface visuelle » du GOAL) — NON FAIT

La moitié écran du correctif vit dans un `.vue`, qui n'atteint le navigateur qu'après une
compilation Mix. **L'arbre est partagé et une autre session y travaillait pendant ce GOAL** :
processus `npx vitest run tests/js/sentinels/` (PID 39778) actif, et `public/mix-manifest.json`
réécrit à 02:10:26 pendant mon travail. Lancer `npm run production` aurait réécrit `public/js/*`
sous cette session, en pleine exécution de ses bancs.

J'ai donc choisi de **ne pas compiler** et de le dire, plutôt que de produire une capture au
prix d'une collision. Ce qui remplace — sans le remplacer complètement :

- la moitié **serveur** est prouvée sur le serveur réellement servi (§4, quatre états mesurés
  sur `/api/health/ready`) ;
- la moitié **écran** est prouvée au niveau du rendu : `systemHealthAgeSauvegardeNonArrondi.spec.js`
  monte le **vrai** composant et vérifie la **couleur** et le **texte** réellement produits,
  avec la re-preuve « défaut remis → banc rouge ».

**Ce qui manque donc** : la vue d'ensemble de la page (mise en page cassée, débordement,
libellé i18n brut, cohérence carte ↔ bande d'alertes à l'œil). À faire quand l'arbre est
libre : compiler, puis lancer
`PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/Playwright/dashboard-controle-captures-2026-09-02.spec.js`.

Note : les MCP Playwright et Chrome de cette session étaient de toute façon en échec de
connexion (`CONNECTION_CLOSED`).

### 7.2 Un constat réel que je n'ai pas corrigé — décision à prendre

**Les deux surfaces ne filtrent pas le même motif de fichier.**

- cockpit + `/health/ready` : `glob("*.sql.gz")` → `pre-8axes-2026-08-05.sql.gz`
- `backup:verify-restore` : `glob("daily-*.sql.gz")` → `daily-2026-06-24.sql.gz`

Sur cette machine, le drill ne peut donc **jamais** attester le fichier que le cockpit appelle
« dernière sauvegarde » : le rapprochement de T4.1 y restera rouge en permanence. En production
le dossier ne contient que des `daily-*` et les deux motifs désignent le même fichier — le
rapprochement fonctionne alors normalement (§4 le montre : `ok` obtenu dès que le drill porte
sur le bon fichier).

Je ne l'ai **pas corrigé** : le GOAL ne le nomme pas, et les deux corrections possibles ont des
conséquences opposées (élargir le motif du drill le ferait restaurer des instantanés faits à la
main ; restreindre celui du cockpit changerait le sens de « dernière sauvegarde » pour
`checkBackupAge`). **À trancher par le propriétaire.** Mais un rouge permanent sur un poste est
exactement ce qui apprend à ne plus regarder l'écran, donc ça ne doit pas rester en l'état.

### 7.3 Choix de conception à connaître

- **Un drill vert sans empreinte enregistrée (`sha256 = null`) n'est plus vert.** C'est plus
  strict que « comparer si disponible », et c'est délibéré : l'absence de preuve n'est pas une
  preuve. `backup:verify-restore` renseigne toujours l'empreinte quand le fichier est lisible,
  donc le cas ne se produit qu'en anomalie.
- **`empreinteDe()` met le SHA-256 en cache** par (nom, date, taille), 30 jours. Sans cela, le
  cockpit rehacherait le dump à chaque affichage. Un fichier réécrit sous le même nom change de
  clé — c'est précisément le cas que le rapprochement doit attraper, il n'est donc pas masqué.
- **`RestoreDrillResult::current()` reste pur** : il ne rapproche rien. Le rapprochement est
  appliqué par les deux surfaces. C'est ce qui permet aux bornes de T4.3 de mesurer le seuil
  sans être perturbées par l'état du disque.
- Le filet dans `handle()` persiste un verdict même sur une **exception non rattrapée** (puis
  relance l'exception). Il ne remplace pas `echec()` : il couvre les chemins ajoutés plus tard.

---

## 8. Condition de sortie du GOAL

> « aucun chemin par lequel un vert puisse survivre à un échec. »

- Les 9 sorties de `backup:verify-restore` persistent désormais un verdict — 3 le faisaient déjà,
  6 sont corrigées, plus un filet qui couvre toute sortie non nulle et toute exception.
- Un vert qui ne porte pas sur la sauvegarde présente n'est plus un vert (nom **et** empreinte).
- Un vert de plus de 26 h n'est plus un vert, sur les deux surfaces avec le même seuil.
- Un âge de sauvegarde entre 26 h 01 et 26 h 29 n'est plus un vert : le serveur publie le
  verdict, l'écran ne recalcule plus rien.

Reste ouvert, hors périmètre nommé : le désaccord de motif de fichier décrit en **§7.2**, et
les captures d'écran de **§7.1**. Tant que §7.2 n'est pas tranché, je ne déclare pas ce GOAL
fermé — je le déclare **exécuté et prouvé, avec un point de décision propriétaire**.

---

## Complément superviseur — le point de décision est tranché (2026-09-03, 02h50)

Le chantier G4 s'est arrêté sur un constat réel qu'il n'a pas voulu trancher seul, et il a eu
raison de le signaler : **trois surfaces lisaient `storage/backups/db-daily` avec deux motifs
différents.**

| Surface | Motif avant | Fichier désigné sur ce poste |
|---|---|---|
| Cockpit (`RestoreDrillResult::cheminSauvegardeCourante`) | `*.sql.gz` | `pre-8axes-2026-08-05.sql.gz` |
| `/health/ready` (`HealthController::checkBackupAge`) | `*.sql.gz` | `pre-8axes-2026-08-05.sql.gz` |
| `backup:verify-restore` | `daily-*.sql.gz` | `daily-2026-06-24.sql.gz` |

Conséquence : dès qu'un dump manuel est plus récent qu'une quotidienne, le rapprochement
fichier ↔ drill introduit en T4.1 devient **structurellement impossible à satisfaire**. L'écran
désigne un fichier que le drill ne testera jamais. Le symptôme n'est pas un faux vert — c'est
pire à l'usage : un **rouge permanent**, et un rouge permanent, on cesse de le regarder au bout
d'une semaine.

### La décision

**La garantie de santé porte sur la chaîne de sauvegarde AUTOMATIQUE.** Un `pre-*.sql.gz` déposé
à la main avant une migration n'en fait pas partie : il ne doit ni être attesté, ni masquer une
quotidienne en retard.

Les trois surfaces cherchent donc `daily-*.sql.gz`, depuis **un seul endroit** :
`RestoreDrillResult::MOTIF_SAUVEGARDE` + `RestoreDrillResult::cheminSauvegardeCourante()`.

L'alternative — élargir le drill à `*.sql.gz` — a été écartée : elle ferait restaurer un dump
manuel à la place de la quotidienne, et prouverait donc quelque chose d'autre que ce que l'écran
promet.

### Le banc, et la preuve qu'il mord

`tests/Feature/Backup/TroisSurfacesDesignentLaMemeSauvegardeTest.php` — trois cas.
Avant correctif : **2 échecs sur 3**, avec l'écart exact
(`daily-9999-01-02.sql.gz` attendu, `pre-migration-9999-01-03.sql.gz` obtenu).
Sortie rouge intégrale consignée dans `G4-bancs-mordent.txt`.

Le troisième cas a d'abord été écrit avec un `markTestSkipped` quand la machine porte de vraies
sauvegardes. Un banc qui se saute n'est pas un banc : il a été réécrit sur un dossier temporaire,
et il prouve désormais aussi que `not-sql.gz` — le fichier d'un octet qui traîne dans
`storage/backups/db-daily/` — n'est jamais retenu (cf. G7/T7.1).

### Effet de bord traité, pas contourné

`BackupVerifyRestoreCommand::pickNewest()` devenait du code mort maintenu vert par son test
unitaire. Il délègue désormais à `RestoreDrillResult::plusRecent()` : une seule implémentation du
tri, `tests/Unit/Backup/BackupVerifyRestoreLogicTest.php` continue d'éprouver de la logique
vivante.

### Vérifié sur le serveur réellement servi

`GET http://127.0.0.1:8766/api/health/ready` :

```
backup_age    -> degraded — "newest backup 1682.9h old (>26h)"
restore_drill -> degraded — "restauration de vérification ÉCHOUÉE :
                 restored schema has 88 tables but live has 113 (partial restore?)"
```

Les 1682,9 h correspondent bien à `daily-2026-06-24.sql.gz`, pas à `pre-8axes-2026-08-05.sql.gz` :
la désignation partagée est effective sur le serveur.

Ce rouge-là est **vrai** : la chaîne quotidienne de ce poste de développement a soixante-dix
jours. Ce n'est plus un artefact de divergence, c'est un fait.

### Bancs rejoués après le correctif

`TroisSurfacesDesignentLaMemeSauvegarde` · `BackupVerifyRestoreLogic` ·
`RestoreDrillPersisteTousLesEchecs` · `RestoreDrillAttesteFichierCourant` ·
`CockpitEtReadinessMemeAgeSauvegarde` · `SystemHealthRestoreDrill` · `SystemHealth`
→ **80 verts, 0 échec** (le seul saut a été supprimé depuis).

**G4 est fermé.**
