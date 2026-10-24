# G3 — Un audit n'atteste que ce qui a réellement eu lieu

Défauts : **V-07** (P1, inversion preuve/fait) · **V-15** (P3, texte d'écran périmé)
Date : 2026-09-03 · Branche : `pos/category-first-caisse-2026-06-23` · HEAD : `34519880d`
Arbre PARTAGÉ — **rien n'a été commité**.

---

## 1. Le défaut, et ce qu'il coûtait

`InterrupteurController::update()` écrivait la ligne `audit_logs` **avant** d'appliquer la
bascule :

| Avant correctif | Ligne |
|---|---|
| `$this->auditLog->write([...])` | `InterrupteurController.php:94` |
| `$this->service->regler($nom, $apres)` | `InterrupteurController.php:110` |
| l'écriture en base qui peut échouer | `InterrupteurService.php:120` (`Settings::group()->set()`) |
| `catch (\InvalidArgumentException)` seul | `InterrupteurController.php:123` |

Une `QueryException` levée par `Settings::set()` — base verrouillée, délai dépassé,
contrainte — passait à travers le seul `catch`, remontait en 500, et **la ligne d'audit
restait**. `audit_logs` est chaîné HMAC, append-only, sa suppression est refusée par un
déclencheur SQL : cette ligne fausse ne peut plus jamais être retirée. Elle affirme
pendant six ans une bascule qui n'a jamais eu lieu.

**Second cas, même classe, non listé dans le GOAL et trouvé en écrivant le banc** : un nom
hors liste blanche (`PUT /interrupteurs/app.debug`) écrivait lui aussi sa ligne d'audit
avant que `regler()` ne lève `InvalidArgumentException`. Le journal attestait la bascule
d'un interrupteur **qui n'existe pas**. Corrigé dans le même geste.

---

## 2. Ce que le banc prouve — et la preuve qu'il rougissait

**Banc :** `tests/Feature/Pilotage/InterrupteurAuditApresMutationTest.php` (créé)

| Cas | Ce qu'il exige |
|---|---|
| 1 — `test_une_bascule_qui_echoue_ne_laisse_aucune_ligne_d_audit` | `regler()` lève une vraie `QueryException` (« database is locked ») ⇒ réponse ≥ 400, **zéro** ligne `pilotage.interrupteur.bascule` ajoutée, `valeur()` inchangée, et aucune ligne `settings` pour la clé |
| 2 — `test_une_bascule_reussie_laisse_exactement_une_ligne_fidele` | bascule réussie ⇒ **exactement une** ligne ; acteur, branche, avant, après, `correlation_id` ; et `apres` **égale la valeur relue après application**, pas celle demandée |
| 3 — `test_un_interrupteur_inconnu_ne_laisse_aucune_ligne_d_audit` | nom hors catalogue ⇒ 404 et **zéro** ligne |

**Rouge AVANT correctif** — sortie brute intégrale dans
`reports/supervision/2026-09-03/G3-bancs-mordent.txt` :

```
   FAIL  Tests\Feature\Pilotage\InterrupteurAuditApresMutationTest
  ⨯ une bascule qui echoue ne laisse aucune ligne d audit
  ✓ une bascule reussie laisse exactement une ligne fidele
  ⨯ un interrupteur inconnu ne laisse aucune ligne d audit

  une bascule qui n’a pas eu lieu ne doit laisser AUCUNE ligne dans audit_logs :
  la table est chaînée et append-only, une ligne fausse y reste six ans
  Failed asserting that 1 is identical to 0.

  Tests:  2 failed, 1 passed
```

Le « 1 » de « 1 is identical to 0 », c'est exactement la ligne fausse et indélébile.
Le cas 2 était vert avant comme après : il n'a jamais été le défaut, il verrouille
l'acquis.

**Vert APRÈS correctif** : `Tests: 3 passed`.

---

## 3. Le correctif

### T3.2 — Inverser l'ordre (`InterrupteurController.php`)

`regler()` d'abord, l'audit ensuite, avec la valeur **relue** (`$applique =
$this->service->valeur($nom)`) et non celle demandée : consigner l'intention plutôt que le
fait ramènerait le défaut par une autre porte.

**Une décision à signaler, parce qu'elle va au-delà du simple ré-ordonnancement.**
Inverser seul aurait cassé un invariant déjà acquis et déjà testé
(`InterrupteurBasculeEstAuditeeTest::test_une_bascule_non_traçable_est_refusee_et_n_a_pas_lieu`) :
*pas de bascule sans trace*. Les deux invariants sont contradictoires si on les traite
séquentiellement — quel que soit l'ordre, la panne du second geste laisse le premier
orphelin. J'ai donc mis les deux appels **dans une même `DB::transaction`** : si l'audit
échoue, le réglage est annulé ; si le réglage échoue, l'audit n'est jamais écrit.

Ce qui reste vrai, et que j'ai vérifié :
- `app/Services/Fiscal/AuditLogService.php` n'est **pas** touché (diff nul, cf. §6). Sa
  mécanique de chaînage HMAC, son verrou `Cache::lock` et sa propre transaction sont
  intacts ; seul l'appelant change.
- Rien n'est réécrit ni corrigé a posteriori dans `audit_logs` : une transaction annulée
  n'insère pas, elle ne supprime pas — le déclencheur `BEFORE DELETE` n'est jamais sollicité.
- Le refus d'un nom hors liste blanche est désormais tranché **avant** la transaction, ce
  qui sépare nettement un refus (404) d'une panne (500). `InterrupteurService::regler()`
  reste l'autorité et refuserait de son côté.

### T3.3 — Élargir le `catch`

`catch (\Throwable)` remplace `catch (\InvalidArgumentException)`. Couvre ce qui arrive
vraiment : `QueryException` (base indisponible, contrainte), `RuntimeException` de verrou
de chaîne non obtenu (`AuditLogService` après 5 s d'attente), délai dépassé. Chacun donne :

- HTTP 500 avec un message explicite en français qui dit **ce qui n'a pas eu lieu** :
  « rien n'a été modifié et aucune trace n'a été enregistrée » ;
- un `Log::error` nommant l'exception, l'acteur, l'état avant et la valeur demandée ;
- **aucune** ligne dans `audit_logs`.

Un détail non évident, corrigé aussi : `regler()` pose la valeur en mémoire via
`Config::set()` pour la requête en cours. Le retour arrière de la transaction ne défait pas
cette copie mémoire — elle est remise explicitement à `$avant` dans le `catch`, sans quoi
la fin de la requête aurait continué de croire à une bascule non retenue.

### T3.4 — Dire la vérité à l'écran (`SystemHealthComponent.vue`)

Avant : « Consigne dans le **journal serveur**, pas le journal fiscal NF525. »
C'était faux dans les deux sens : ce n'est pas un fichier texte rotaté, et ce n'est pas
non plus une écriture fiscale au sens du Z.

Après :

> Prise en compte immédiate, sans mise en ligne. Chaque bascule est consignée dans le
> journal d'audit métier (audit_logs), signé en chaîne et non modifiable — ce n'est pas
> une écriture fiscale NF525 au sens du ticket Z.

**Banc :** `tests/js/systemHealthTexteAuditExact.spec.js` (créé, 4 cas) — verrouille les
deux bords : le texte doit nommer le journal d'audit et `audit_logs`, ne doit plus dire
« journal serveur » ni « journal applicatif », doit mentionner NF525 **uniquement sous
forme de négation**, et doit dire ce qui rend la trace opposable.

**Preuve qu'il mord** : le texte d'origine a été remis temporairement dans le `.vue`,
vitest relancé — **4 tests sur 4 en échec** — puis le fichier restauré à l'octet près
(comparaison binaire vérifiée : `RESTAURE_IDENTIQUE: True`). Sortie brute en seconde
moitié de `G3-bancs-mordent.txt`.

**Zone de l'autre chantier respectée** : seul le bloc de texte (lignes 118-127) a été
touché. Les modifications G4 du même fichier (`sauvegardeOk`, `sauvegardeTexte`,
`restaurationTexte`, statut `autre_fichier` — lignes ~224-265) sont intactes et vertes
(31 tests des 4 specs `systemHealth*` / `posSystemHealthPill` passent).

---

## 4. Tests

### Banc G3 + non-régressions du GOAL (PHPUnit ciblé)

```
$ php artisan test --filter='InterrupteurBasculeEstAuditeeTest|InterrupteurLectureGardeeTest\
  |InterrupteurCatalogueTest|InterrupteurTest|CashierCannotToggleInterrupteurTest\
  |InterrupteurAuditApresMutationTest'

  PASS Tests\Feature\Grok\CashierCannotToggleInterrupteurTest        (2)
  PASS Tests\Feature\Pilotage\InterrupteurAuditApresMutationTest     (3)   ← nouveau
  PASS Tests\Feature\Pilotage\InterrupteurBasculeEstAuditeeTest      (3)
  PASS Tests\Feature\Pilotage\InterrupteurCatalogueTest             (11)
  PASS Tests\Feature\Pilotage\InterrupteurLectureGardeeTest          (4)
  PASS Tests\Feature\Pilotage\InterrupteurTest                       (6)

  Tests:  29 passed
```

### Non-régressions supplémentaires (autres tests frappant la même route, trouvés par grep)

```
$ php artisan test --filter='DashboardControlAuditFixesTest|AdminRoutePermissionFloorTest'
  Tests:  12 passed
```

### Vitest (Node 22.23.2)

```
$ npx vitest run tests/js/systemHealthTexteAuditExact.spec.js \
    tests/js/systemHealthAgeSauvegardeNonArrondi.spec.js \
    tests/js/systemHealthSauvegardeRestauration.spec.js \
    tests/js/posSystemHealthPill.spec.js
  Test Files  4 passed (4)
       Tests  31 passed (31)
```

---

## 5. T3.5 — Chaîne NF525

Détail complet : `reports/supervision/2026-09-03/G3-chaine-nf525.txt`.

```
$ php artisan fiscal:verify-chain --all     (avant ET après)
  + branch=1 CHAIN OK
  + branch=2 CHAIN OK
  + branch=7 CHAIN OK
  + branch=8 CHAIN OK
  + branch=9 CHAIN OK
  + branch=10 CHAIN OK
SWEEP COMPLETE — CHAIN OK on every active branch (6 total)
```

Aller-retour **réel** sur `wheel` par le contrôleur corrigé (base MySQL `foodking_e2e`,
acteur Admin réel), deux bascules réussies :

| | avant | après | delta |
|---|---|---|---|
| `count(*)` | 8709 | 8711 | **+2** |
| `MAX(id)` | 8764 | 8766 | **+2** |
| `MAX(current_hash)` | `…` | nouvelle tête | nouvelle ligne, pas resignature |

La ligne de tête d'avant (id 8764) a ses colonnes `action`, `prev_hash` et `current_hash`
**INCHANGÉES** après l'opération : c'est un **ajout**, jamais une réécriture. Le chaînage
est continu (`prev_hash` de chaque ligne ajoutée = `current_hash` de la précédente).
`wheel` est revenu à son état initial : la vérification n'a rien laissé de modifié.

Cette attestation vaut aussi comme épreuve du correctif **sur MySQL réel**, avec les
transactions imbriquées et le `Cache::lock` de la chaîne — pas seulement sur le SQLite en
mémoire des bancs.

---

## 6. Zone gelée

```
$ git diff --stat -- <les 15 fichiers de CLAUDE.md §7>
(sortie vide — zéro ligne modifiée)

$ git diff --stat -- app/Services/Fiscal/AuditLogService.php
(sortie vide)
```

```
$ bash .cursor/hooks/safety-check.sh
[safety-check] Checking 15 frozen zones...
[safety-check] Frozen zones: OK
[safety-check] Checking PHP syntax...
[safety-check] PHP syntax: OK
[safety-check] Passed. Proceed with execution.        → PASS (exit 0)
```

---

## 7. Fichiers touchés

**Modifiés (2)**
- `app/Http/Controllers/Admin/Pilotage/InterrupteurController.php` — `+3` imports
  (`Config`, `DB`), `update()` réécrit : garde liste blanche anticipée, transaction
  `regler()` → relecture → audit, `catch (\Throwable)` avec restauration mémoire et
  message d'erreur explicite. `+120 / −44` (commentaires compris).
- `resources/js/components/admin/observability/SystemHealthComponent.vue` — **uniquement**
  le bloc de texte des Interrupteurs, lignes 118-127 : `+9 / −1`. Le reste du diff de ce
  fichier appartient au chantier G4 et n'a pas été touché.

**Créés (4)**
- `tests/Feature/Pilotage/InterrupteurAuditApresMutationTest.php`
- `tests/js/systemHealthTexteAuditExact.spec.js`
- `reports/supervision/2026-09-03/G3-bancs-mordent.txt`
- `reports/supervision/2026-09-03/G3-chaine-nf525.txt`
- (ce fichier) `reports/supervision/2026-09-03/G3-RAPPORT.md`

**Rien n'a été commité. Aucun `git add`.**

**Fichiers interdits — non touchés par moi, mais modifiés dans l'arbre.**
`git status` montre en `M` : `SyncOverviewController.php`, `OutboxOverviewComponent.vue`,
`HealthController.php`, `app/Support/Backup/RestoreDrillResult.php`,
`app/Console/Commands/Backup/BackupVerifyRestoreCommand.php`. Ces modifications ne sont pas
les miennes : mes seules écritures de cette session sont les 2 fichiers modifiés et les
5 fichiers créés listés ci-dessus. L'arbre est partagé et d'autres chantiers y travaillent
en parallèle — je le signale pour qu'aucun de ces changements ne me soit attribué au moment
de la relecture ou du commit.

---

## 8. Ce que je n'ai PAS fait, et ce que je n'ai pas prouvé

1. **Aucune capture Playwright.** Le GOAL demande trois captures analysées sur
   `http://127.0.0.1:8766/admin/observability/system`. Les serveurs MCP Playwright et
   Chrome DevTools ont échoué à se connecter au démarrage de cette session
   (`CONNECTION_CLOSED`) — pas d'absence de capacité, une panne de connexion. Et la
   consigne interdisait `npm run production` : sans recompilation, une capture aurait
   montré l'**ancien** texte et n'aurait donc rien prouvé sur T3.4. La preuve du texte
   repose sur le banc Vitest, qui monte le vrai composant. **La surface visuelle du GOAL
   reste à faire après votre compilation.**

2. **`role="alert"` absent — trouvé, non corrigé.** Le GOAL demande qu'une bascule refusée
   « annonce l'échec (`role="alert"`) ». Le comportement est déjà correct pour l'œil
   (`SystemHealthComponent.vue:322-325` : l'affichage n'est **pas** inversé sur échec, et
   `this.erreur` est posé), mais le rendu ligne **158** est
   `<p v-if="erreur" class="text-sm text-red-700" data-testid="system-health-erreur">` :
   **pas de `role="alert"`**, donc l'échec n'est pas annoncé aux lecteurs d'écran. C'est
   hors T3.1-T3.5, et votre consigne était de ne toucher QUE la zone du texte d'audit dans
   ce fichier. Je l'ai laissé et je le signale : correction d'un attribut, à faire dans un
   geste qui ne heurte pas le chantier G4.

3. **Suite PHPUnit complète non lancée** (consigne : `--filter` uniquement). Les
   non-régressions couvrent les 5 bancs du GOAL plus les 2 autres fichiers de test qui
   frappent la route `observability/interrupteurs` (trouvés par grep). Un `catch` élargi à
   `\Throwable` ne change le comportement que sur les chemins d'échec ; je n'ai pas de
   preuve globale au-delà de ce périmètre.

4. **La transaction n'a pas été éprouvée sous concurrence réelle.** Elle est prouvée sur
   SQLite (bancs) et sur MySQL en séquentiel (T3.5). Le cas « deux administrateurs
   basculent le même interrupteur à la même seconde » n'est pas couvert par un banc ; le
   verrou de chaîne d'`AuditLogService` (`Cache::lock`, 5 s) sérialise les écritures
   d'audit, mais je ne l'affirme pas comme démontré ici.

5. **Quatre bascules réelles ont été écrites dans la base de développement.**
   `foodking_e2e`, `audit_logs` ids **8763, 8764, 8765, 8766**, action
   `pilotage.interrupteur.bascule`, `correlation_id = g3-t35-attestation`. Le script
   d'attestation a été joué **deux fois** (une première fois sans capture des sorties dans
   des fichiers, puis une seconde fois pour produire le rapport `G3-chaine-nf525.txt`) :
   chaque exécution est un aller-retour de deux bascules, d'où quatre lignes et non deux.
   Ce sont de vraies lignes d'audit, légitimes et intentionnelles ; `wheel` est revenu à
   son état initial (`false`) à chaque fois. Rien n'a été fait sur la production.

6. **Condition de sortie du GOAL — deux rondes identiques : faite pour les bancs,
   pas pour le visuel.**
   Ronde 2 rejouée à l'identique : `Tests: 41 passed` (PHPUnit, 8 fichiers) et
   `Test Files 4 passed / Tests 31 passed` (Vitest) — identique à la ronde 1. Mais la
   ronde visuelle du GOAL (trois captures analysées) n'a été faite **ni** en ronde 1 **ni**
   en ronde 2, pour les raisons du point 1. La condition de sortie n'est donc **pas**
   entièrement remplie.
