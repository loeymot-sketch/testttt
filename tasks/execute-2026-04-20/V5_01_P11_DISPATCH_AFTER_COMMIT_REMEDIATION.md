# EXECUTE V5 #1 — P11_DISPATCH_AFTER_COMMIT_REMEDIATION

TASK_ID: P11_DISPATCH_AFTER_COMMIT_REMEDIATION
WAVE: V5 (P0 hardening — bug réel prod)
RUNNER_MODE: single-session
PRIMARY_MODEL: **GPT-5.4** (foodking-complex-implementer)
**HUMAN GATE REQUIS** — touche `OrderService` (LOCK_A+B) et `FrontendOrderService`
SOURCE_BUG: `reports/execution/RUN_P11_DISPATCH_AFTER_COMMIT_AUDIT_2026-04-20.md` (test sentinelle rouge)

---

## ⚠️ Statut

**BLOQUÉ — attend signature humaine.** Ce cycle touche `OrderService` (frozen LOCK_A+B) et impacte le pattern de dispatch des events `App\Events\*`. Doit être ajouté au Gate Brief consolidé `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md` comme **C9 nouveau** OU faire l'objet d'un mini-Gate Brief dédié.

---

## Bug confirmé — élargi par V5 #2 + V5 #3 (post-2026-04-20)

`tests/Feature/DispatchAfterCommitTest.php` (créé V4 #8, étendu V5 #3 via data provider) — **3 tests rollback ROUGES** :
```
✘ Event is not dispatched if transaction rolls back with data set "OrderCreated"
✘ Event is not dispatched if transaction rolls back with data set "OrderStatusChanged"
✘ Event is not dispatched if transaction rolls back with data set "ItemAvailabilityChanged"
```

`scripts/check-invariants.sh` invariant 4/6 (durci V5 #2) — **8 violations statiques** :
- `OrderService.php:541, 961, 1266` (OrderCreated::dispatch)
- `OrderService.php:1423, 1478, 1575` (OrderStatusChanged::dispatch)
- `FrontendOrderService.php:842` (OrderCreated::dispatch — kiosk public flow)
- `FrontendOrderService.php:848` (OrderStatusChanged::dispatch — kiosk public flow, **confirmé par audit V6 #2**)

**Le bug n'est PAS limité à `OrderCreated`** comme initialement estimé. Au moins **3 events broadcast** sont concernés. Probablement plus (les `Item/CategoryCreated/Updated/Deleted` n'ont pas été testés mais probablement même problème — non implémentent `ShouldDispatchAfterCommit`).

**Ce que ça veut dire en prod :** si une transaction de création d'order rollback APRÈS l'appel `OrderCreated::dispatch($order)` (ex : exception dans une étape post-event au sein de la même transaction DB::transaction), les surfaces KDS / OSS / Kiosk reçoivent un broadcast pour un order **fantôme** qui n'existe pas en DB → désynchro cross-surface, audit log incohérent, potentiellement préparation de plat pour un client qui n'a pas commandé.

**Localisation :**
- `app/Events/OrderCreated.php` : classe sans `ShouldDispatchAfterCommit` ni `ShouldBroadcast`
- `app/Services/OrderService.php:541, 961, 1266` : `\App\Events\OrderCreated::dispatch($this->order);` simple
- `app/Services/FrontendOrderService.php:842` : `OrderCreated::dispatch($frontendOrder);` simple
- `app/Services/FrontendOrderService.php:585` : commentaire de l'auteur "Prevents ghost KDS orders if the transaction rolls back after these dispatches" — l'intention était bonne, l'implémentation incomplète

---

## 2 stratégies (l'humain choisit dans le Gate Brief)

### Stratégie A — Event self-defending (préférée)

Ajouter `implements ShouldDispatchAfterCommit` à `app/Events/OrderCreated.php`. Laravel 10+ déclenche alors l'event en `afterCommit` automatiquement, même si le caller utilise `::dispatch()` simple.

**Pour :**
- 1 ligne de code, surface minimale
- Tous les call-sites existants deviennent corrects sans modification
- Le contrat est porté par l'event, pas par chaque caller (plus robuste face aux nouveaux callers futurs)

**Contre :**
- Requiert Laravel 10+ (vérifier `composer.json`). Si Laravel < 10, fallback obligatoire vers Stratégie B.
- Si un caller voulait délibérément un dispatch immédiat (rare, mais possible pour debug), il devra utiliser `Event::dispatch(new OrderCreated(...))` explicitement (contournement).

**Étendue :** étendre à tous les events broadcast (`OrderStatusChanged`, `ItemAvailabilityChanged`, `ItemCreated`, `ItemDeleted`, `CategoryCreated/Updated/Deleted`) — décision Gate.

### Stratégie B — Caller refactor

Remplacer chaque `OrderCreated::dispatch($x)` par `DB::afterCommit(fn() => OrderCreated::dispatch($x))` ou `OrderCreated::dispatchAfterCommit($x)` dans :
- `OrderService.php:541, 961, 1266`
- `FrontendOrderService.php:842`

**Pour :**
- Compatible Laravel 9
- Explicite sur chaque call-site

**Contre :**
- 4 modifications dans 2 frozen files (LOCK_A+B)
- Risque oubli sur futur call-site
- Diff plus large

---

## Test sentinelle (déjà en place)

`tests/Feature/DispatchAfterCommitTest.php` — doit passer **2/2** après remédiation. Aucune modification requise sur ce fichier.

À étendre éventuellement (cycle ultérieur) à `OrderStatusChanged`, `ItemAvailabilityChanged`, etc.

---

## Audit fix indirect : `scripts/check-invariants.sh` invariant 4/6

Le grep statique actuel a des faux négatifs :
- N'attrape pas `OrderCreated::dispatch(...)` quand `use App\Events\OrderCreated` (cas FrontendOrderService.php:842)
- À investiguer pourquoi les hits OrderService.php:541/961/1266 (préfixe `\App\Events\...`) ne sont pas remontés malgré le pattern qui devrait matcher

Mini-cycle Composer parallèle possible : `P11_INVARIANT_4_OF_6_HARDENING` (élargir le grep pour couvrir l'usage `use+short-name`). Sans gate.

---

## Scope (FILES TOUCHED si Stratégie A — version étendue post V5 #2+#3)

| Fichier | Action | Justification |
|---|---|---|
| `app/Events/OrderCreated.php` | EDIT — ajouter `implements ShouldDispatchAfterCommit` | Bug confirmé runtime + 4 call-sites statiques |
| `app/Events/OrderStatusChanged.php` | EDIT — idem | Bug confirmé runtime + 4 call-sites statiques |
| `app/Events/ItemAvailabilityChanged.php` | EDIT — idem | Bug confirmé runtime |
| (optionnel) `app/Events/Item{Created,Updated,Deleted}.php` + `Category*` | EDIT — idem si analyse confirme broadcast cross-surface | Probablement même problème, à vérifier au début du cycle |

Soit **3 fichiers minimum**, jusqu'à **9 fichiers** si extension complète aux events broadcast secondaires.

Ajouter `use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;` en haut de chaque fichier touché (1 ligne par event).

**SUBSYSTEMS_TOUCHED**: dispatch pattern events critiques (cross-surface broadcast).
**SUBSYSTEMS_OFF_LIMITS** (si Strat A) : `OrderService.php`, `FrontendOrderService.php` — pas de modif nécessaire.
**INVARIANTS_AT_RISK**:
- dispatch-after-commit (corrigé par cette remédiation)
- broadcast Pusher cohérence (à vérifier : `ShouldDispatchAfterCommit` ne doit pas désactiver le broadcast immédiat ; vérifier docs Laravel)
- Pas de NF525, pas de pricing SSOT, pas de OrderStatus state machine.

---

## VALIDATE
1. `vendor/bin/phpunit --filter DispatchAfterCommitTest --testdox` → **6/6 vert** (3 events × 2 tests, après data provider V5 #3)
2. `bash scripts/check-invariants.sh` → 4/6 doit redevenir **OK** (0 hits) — sentinelle statique cohérente
3. (les autres VALIDATE déjà listés ci-dessous restent valides)
2. `vendor/bin/phpunit` complet → toujours vert (régression check)
3. `bash scripts/check-invariants.sh` → 6/6 OK
4. Test E2E manuel ou script (optionnel, si environnement Pusher disponible) : créer un order, observer broadcast Pusher arriver après commit, pas avant.
5. `git diff` minimal (2-5 lignes si Strat A pure)

---

## REPORT_FILE

`reports/execution/RUN_P11_DISPATCH_AFTER_COMMIT_REMEDIATION_2026-04-20.md`

---

## Approbation

À ajouter au Gate Brief `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md` :

```
### C9 — P11_DISPATCH_AFTER_COMMIT_REMEDIATION (NOUVEAU)
- Frozen zone touchée : app/Events/OrderCreated.php (impact OrderService + FrontendOrderService dispatch behavior)
- Invariant à risque : dispatch-after-commit (actuellement cassé, prouvé par test sentinelle)
- Plan minimal : Stratégie A (1 ligne `implements ShouldDispatchAfterCommit`)
- Pourquoi pas d'alternative : Stratégie B = 4 modifs dans frozen files = risque > Stratégie A
- Vérification Laravel version : composer.json doit indiquer >= 10.x
- [ ] Approve Stratégie A
- [ ] Approve Stratégie B
- [ ] Defer
- [ ] Cancel
```
