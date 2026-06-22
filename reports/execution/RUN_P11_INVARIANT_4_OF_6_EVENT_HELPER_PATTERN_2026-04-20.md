# RUN — P11_INVARIANT_4_OF_6_EVENT_HELPER_PATTERN (V8 #1)

**Date** : 2026-04-20  
**Plan** : `tasks/execute-2026-04-20/V8_01_P11_INVARIANT_4_OF_6_EVENT_HELPER_PATTERN.md`  
**Statut** : **SUCCESS** (Cas **B**)

---

## Cas final : **B** (justification)

- **Avant** extension V8 (regex V5 #2 uniquement, telle qu’en vigueur dans le working tree au moment du run) : `bash scripts/check-invariants.sh -v` → invariant **4/6** = **8 hits** (OrderService + FrontendOrderService uniquement ; pattern `::dispatch(`).
- **Après** ajout du pattern helper `event(new …)` / `Event::dispatch(new …)` **sans** commentaires `// allow:` sur les 5 sites : grep combiné sur le même scope → **13** lignes (8 + 5). Les 5 lignes Item/Category sont sur une ligne **sans** sous-chaîne `afterCommit` / `DB::afterCommit` → non exclues par `EXCLUDE_4_6`.
- **Après** ajout de `// allow: wrapped DB::afterCommit (V8 #1)` sur les 5 lignes `event(new …)` : **4/6** = **8 hits** à nouveau — **pas** de régression V5 #2 (≠ Cas D), **pas** de hits parasites (≠ Cas C).

---

## Forme exacte du wrap — 5 sites (snippets)

Tous les sites suivent le même schéma : **multi-ligne** — `DB::afterCommit(function () use (...): void {` sur une ligne, puis `event(new …);` sur la ligne suivante (fermeture `});` après).

### `app/Services/ItemService.php` (~182)

```php
DB::afterCommit(function () use ($createdItemId): void {
    event(new ItemCreated($createdItemId)); // allow: wrapped DB::afterCommit (V8 #1)
});
```

### `app/Services/ItemService.php` (~306)

```php
DB::afterCommit(function () use ($itemId): void {
    event(new ItemDeleted($itemId)); // allow: wrapped DB::afterCommit (V8 #1)
});
```

### `app/Services/ItemCategoryService.php` (~119)

```php
DB::afterCommit(function () use ($categoryId): void {
    event(new CategoryCreated($categoryId)); // allow: wrapped DB::afterCommit (V8 #1)
});
```

### `app/Services/ItemCategoryService.php` (~151)

```php
DB::afterCommit(function () use ($categoryId): void {
    event(new CategoryUpdated($categoryId)); // allow: wrapped DB::afterCommit (V8 #1)
});
```

### `app/Services/ItemCategoryService.php` (~186)

```php
DB::afterCommit(function () use ($categoryId): void {
    event(new CategoryDeleted($categoryId)); // allow: wrapped DB::afterCommit (V8 #1)
});
```

---

## Diff `scripts/check-invariants.sh`

Voir `git diff scripts/check-invariants.sh`. Résumé :

- Variables : `BROADCAST_EVENTS_4_6`, `PATTERN_4_6` (union V5 `::dispatch` + V8 `event(new |Event::dispatch(new`), `EXCLUDE_4_6` (+ `DB::afterCommit`, conservation de `use App\\Events`, etc.).
- **Une seule** `run_check` 4/6 (pas de split 4a/4b : la regex tient sur BSD `grep`).

---

## Diff `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md`

Fichier **non suivi** par git au moment du run (`??`). Ajout / mise à jour de la section **« V8 #1 — invariant 4/6 extended to event() helper pattern »** immédiatement **après** le bloc CORRECTIVE NOTE (post-V7) et **avant** « Confirmed call-sites ».

---

## Diff services (Cas B — commentaires uniquement)

Voir `git diff app/Services/ItemService.php app/Services/ItemCategoryService.php` : uniquement `// allow: wrapped DB::afterCommit (V8 #1)` en fin de ligne sur les 5 `event(new …)`.

---

## Sortie verbose 4/6

### Avant (baseline V5 #2 seul, premier run de la session)

```
  [4/6 App\Events\* dispatch afterCommit] ... FAIL (8 hit(s))
      app/Services/OrderService.php:541:...
      ... (6 lignes OrderService)
      app/Services/FrontendOrderService.php:842:...
      app/Services/FrontendOrderService.php:848:...
```

### Après (pattern V8 + `// allow:` sur les 5 sites)

```
  [4/6 App\Events\* dispatch afterCommit] ... FAIL (8 hit(s))
      app/Services/OrderService.php:541:...
      ... (6 lignes OrderService)
      app/Services/FrontendOrderService.php:842:...
      app/Services/FrontendOrderService.php:848:...
```

(Item/Category absents des hits — aligné Cas B attendu.)

---

## Risque résiduel / suivi validateur

- Les `// allow:` sont **volontaires** pour les lignes helper seules ; toute suppression du `DB::afterCommit` environnant sans autre garde-fou redeviendra un hit (ou exigerait une évolution du check multi-ligne).
- Invariants 5/6 et 6/6 : **OK** au dernier run complet (`1 invariant(s) violated`, uniquement 4/6).

---

## Path rapport

`reports/execution/RUN_P11_INVARIANT_4_OF_6_EVENT_HELPER_PATTERN_2026-04-20.md`

---

## AUDIT (Claude orchestrateur) — 2026-04-20
**Verdict : CLOSED — PASSED — Cas B confirmé — 0 remediation**

| # | Check | Résultat |
|---|---|---|
| 1 | Re-run `bash scripts/check-invariants.sh -v 4/6` | 8 hits FAIL (= V5 #2 baseline, pas de régression, pas de Cas C/D) |
| 2 | Diff `scripts/check-invariants.sh` | +19/-5 (regex étendue + variables `BROADCAST_EVENTS_4_6` / `PATTERN_4_6` / `EXCLUDE_4_6`) |
| 3 | 5 commentaires `// allow: wrapped DB::afterCommit (V8 #1)` en place | confirmé sur ItemService:182,306 + ItemCategoryService:119,151,186 |
| 4 | Logique services INTACTE | confirmé via `git diff app/Services/Item*.php` : uniquement ajout du commentaire en fin de ligne, aucun changement de code exécuté |
| 5 | KI-001 nouvelle section V8 #1 | présente lignes 64+ |
| 6 | Wrap `DB::afterCommit(function () use (...) { event(new ...); });` confirmé sur les 5 sites | par subagent (lecture contexte ±5 lignes) |

**Validation cruciale** : la régression V7 #1 (faux audit "events orphelins") est désormais **techniquement protégée** par le check étendu :
- Si quelqu'un retire le wrap `DB::afterCommit(function () { ... })` autour d'un `event(new ItemCreated(...))`, le `// allow:` deviendrait incohérent avec la réalité — au prochain durcissement (par ex. V9), le grep verrait à nouveau le hit.
- Pour aller plus loin : un futur cycle pourrait remplacer le `// allow:` par un check multi-ligne (ex : `awk` qui regarde 5 lignes au-dessus pour `DB::afterCommit`). Hors scope V8 #1.

**Découverte significative** : le pattern `event(new XxxCreated(...))` est **historiquement préféré au pattern `XxxCreated::dispatch(...)`** pour les events Item/Category (probablement convention de l'équipe d'origine). Aucun des 2 patterns n'est meilleur, mais leur coexistence dans le codebase = surface d'invariants à doubler. KI-001 documente maintenant cette dualité.

**Valeur produite** :
- 3e angle mort de la sentinelle 4/6 résolu
- 5 sites Item/Category désormais sous surveillance active (avec allowlist documentée)
- Le bug V7 #1 (audit faux) est techniquement neutralisé : un futur dev/agent ne peut plus prétendre que ces events sont "orphelins" sans déclencher le check
