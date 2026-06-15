# SAFE-LOT Implementation Report — POST-SHIP backlog (KDS/OSS)

> Worktree: `.claude/worktrees/post-ship-safe-2026-06-14` (branche
> `heal/post-ship-safe-2026-06-14`, off tronc poussé `f2c12bed7`).
> TDD strict par item. Frozen-zone diff branche entière = VIDE. Aucun push.

## Récapitulatif

| Item | Titre | Type | Test | RED→GREEN | SHA |
|------|-------|------|------|-----------|-----|
| B-KDS-01 | cap-50 FIFO + vrai backlog DB | fix backend | `tests/Feature/KDS/KdsFeedFifoCapTest.php` (3) | prouvé | `832ec5de5` |
| B-KDS-02 | cécité offline board V2 | fix frontend | `tests/js/kdsOfflineBanner.spec.js` (5) | prouvé | `2d958251b` |
| B-KDS-03 | toast 409 legacy trompeur | fix frontend | `tests/js/kdsLegacyConflictToast.spec.js` (2) | prouvé | `3d1b9c78b` |
| B-OSS-01 | tri lanes caisse (NO-OP réfuté) | sentinel only | `tests/js/posOrdersTrackerLanes.spec.js` (3) | PASS as-is (anti-régression) | `a6bd99124` |

Tests neufs: 13 (10 Vitest + 3 PHPUnit). Frozen modifiés: 0. NF525: read-only, chaîne intacte.

---

## B-KDS-01 — cap-50 FIFO + vrai backlog DB
- **Fichiers prod**: `app/Services/KitchenDisplaySystemOrderService.php` (+ champ `lastListBacklog`,
  reset, bloc cap remplacé, getter), `app/Http/Controllers/Admin/KitchenDisplaySystemController.php`
  (`meta.backlog`).
- **Fix**: count du backlog réel AVANT troncature (`(clone $query)->count()`), service des 50 PLUS
  ANCIENNES (`id asc limit 50`), tri d'affichage ré-appliqué en mémoire sur le sous-ensemble si
  `id desc`. `historyToday()` NON touché. Fenêtre TZ inchangée.
- **Test**: seed 55 actives, params board réels `order_by=desc`.
  - `count()===50` ; OLDEST (`min(id)`) servie ; `lastListBacklog()===5` ; `lastListOverflow()===true`.
  - cas <cap : 10 servies, backlog 0, overflow false.
- **RED**: pré-fix `order_by=desc` gardait ids 6..55 (drop id=1) → assert `contains(minId)` ÉCHOUE ;
  `lastListBacklog()` méthode inexistante → ÉCHOUE.
- **GREEN**: `OK (3 tests, 7 assertions)`.
- **Régression**: `KdsTodayWindowTzSentinelTest` (3) + `KdsBoardsReleaseFilterTest` (2) GREEN.
- **Frozen-diff**: vide.

## B-KDS-02 — cécité offline board V2
- **Fichier prod**: `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`.
- **Fix**: `v2OfflineSince` armé sur `_onWsDisconnected` (garde `useV2Layout && === null`) + dans les
  `.catch` de `_refreshWithCurrentFilter` et `list()` ; reset sur `_onWsConnected` + `.then` de succès.
  Legacy `kdsErrorBanner` non touché.
- **Test**: 5 cas — poll fail arme ; poll success reset ; `useV2Layout=false` n'arme pas ; WS
  disconnect arme (garde anti-double-armement) + reconnect reset ; WS disconnect legacy n'arme pas.
- **RED prouvé** par stash du seul fix component → 3 fail / 2 pass ; restauré.
- **GREEN**: 5/5.
- **Frozen-diff**: vide.

## B-KDS-03 — toast 409 legacy trompeur
- **Fichier prod**: même composant — suppression de la SEULE ligne
  `alertService.error("message.kds_status_conflict")` du bloc 409 de `orderStatus()`
  (refresh silencieux, aligné sur `onV2ChangeStatus`). `recall()` (L1934) et V2 banner (L1682) non touchés.
- **Test**: 409 → `alertService.error` NON appelé + `_debouncedRefresh` appelé ; 422 → error appelé.
- **RED**: pré-fix le 409 appelle error → ÉCHOUE (le 422 passe déjà).
- **GREEN**: 2/2.
- **Frozen-diff**: vide.

## B-OSS-01 — tri lanes caisse (RÉFUTÉ → sentinel only)
- **Aucun fichier de production touché.** Spec réfute « lane LIVRÉS morte / tri inversé » : actives
  oldest-first, livrées newest-first, today-DELIVERED présent.
- **Sentinel** `tests/js/posOrdersTrackerLanes.spec.js` (3) verrouille le comportement correct —
  PASSE tel quel (anti-régression, pas TDD-rouge).
- **Frozen-diff**: vide.

---

## Note infra (non bloquant)
Le worktree partage le `vendor` d'`integration-v1-2026-06-12` via symlink → l'autoloader optimisé
(`$baseDir`) pointe les classes `App\` vers integration-v1 (même HEAD `f2c12bed7`, byte-identique
hors mes edits). Pour valider le fix PHP B-KDS-01 sur la copie post-ship-safe, un bootstrap PHPUnit
jetable (`/tmp/pss_bootstrap.php`, HORS repo) surcharge le classmap des 2 fichiers édités
(`addClassMap`, read-only sur vendor). Le symlink `vendor` a été restauré à l'identique ; aucune
mutation de vendor partagé n'a été committée. RED initial (méthode `lastListBacklog` inexistante,
drop FIFO) prouvé sur la copie integration-v1 byte-identique au pré-fix.
