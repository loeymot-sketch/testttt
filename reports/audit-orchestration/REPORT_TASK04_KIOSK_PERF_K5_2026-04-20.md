# T04 — Rapport kioskPerf K-5 régression (2026-04-20)

## Verdict : FAIL — Sous-verdict C

## Checklist : V1..V9

- [ ] **V1.** Absence `kioskPerf.js` confirmée — **Non** : le chemin existe, mais le fichier est **vide (0 octet)** et **non versionné** (`git status` → `?? resources/js/helpers/kioskPerf.js`). Ce n’est pas une suppression proprement indexée dans l’historique ; c’est une **perte effective d’implémentation** (stub vide).
- [x] **V2.** Aucun import orphelin (preuve `rg`) — **Imports applicatifs** : `KioskAppComponent.vue` importe toujours `../../../helpers/kioskPerf` ; le fichier existe donc pas d’import vers un chemin manquant. En revanche le module est **vide** : pas d’export par défaut exploitable (risque build ou `default` indéfini). Preuve recherche (racine `testttt-kiosk-p93`, hors `node_modules`) : occurrences `kioskPerf` limitées à `KioskAppComponent.vue`, `kioskAnalytics.js` (commentaire), `kioskK5PerfInstrumentation.spec.js`, et le nom dans `kioskPerfBudgets.js` (en-tête).
- [x] **V3.** Statut `kioskPerfBudgets.js` — **Partiellement orphelin côté prod** : aucun `import` depuis `resources/js/` hors le fichier lui-même ; **consommé par les tests** `tests/js/kioskK5PerfInstrumentation.spec.js` (bloc K-5.6). L’intégration prévue avec `kioskPerf.js` (émission `perf.over_budget`, etc.) est **cassée** tant que `kioskPerf.js` n’importe pas ce module.
- [x] **V4.** Whitelist `perf.*` côté frontend (`kioskAnalytics`) — **Oui** : les 9 événements `perf.cold_start` … `perf.over_budget` sont toujours listés (commentaire : émis par `kioskPerf.js`).
- [x] **V5.** Whitelist `perf.*` côté backend (`KioskEventController`) — **Oui** : les mêmes 9 noms dans la liste autorisée.
- [x] **V6.** Tests Vitest perf — **Présents** : `tests/js/kioskK5PerfInstrumentation.spec.js` (non nommé `kioskPerf*.spec.js`, mais c’est le spec K-5 perf). **Non marqués skipped** dans le fichier. Les tests **K-5.7** importent dynamiquement `kioskPerf.js` et attendent `start` / `__peekStateForTests` / etc. : avec un fichier **vide**, cette suite est **incompatible** avec l’état actuel (échec attendu si Vitest est exécuté).
- [x] **V7.** Wiring `KioskAppComponent.mounted` — **Oui** : `mounted()` appelle toujours `try { kioskPerf.start(); }` (≈ L281) et `beforeUnmount()` `try { kioskPerf.stop(); }` (≈ L315). Le `try/catch` masque une erreur si `kioskPerf` ou `start` est indéfini (**régression silencieuse** possible au boot).
- [x] **V8.** Verdict + impact chiffré sur garanties K-5 — Voir §8 ci-dessous (**FAIL** ; pas de remplacement documenté ; **0** émission `perf.*` côté instrumentation).
- [ ] **V9.** Commit suppression identifié — **Aucun** : `git log --diff-filter=D --name-only -- resources/js/helpers/kioskPerf.js` ne retourne pas de commit ; le fichier vide est **untracked**, donc pas d’historique de suppression sur cette copie de worktree.

---

## 1. Absence + commit suppression (sha, date, msg)

**Commandes (racine `testttt-kiosk-p93`) :**

- `find resources/js/helpers -name 'kioskPerf*'`  
  → `resources/js/helpers/kioskPerf.js` et `resources/js/helpers/kioskPerfBudgets.js`.

**État réel de `kioskPerf.js` :**

- Taille **0 octet**, contenu **vide** (lecture directe).
- `git status --short -- resources/js/helpers/kioskPerf.js` → **`??`** (non suivi par git).

**Historique git :**

- `git log --diff-filter=D --name-only -- resources/js/helpers/kioskPerf.js` → **vide** (pas de commit de suppression enregistré pour ce chemin sur ce dépôt / cet état).

**Synthèse :** pas de preuve d’un commit de suppression ; présence d’un **stub vide local non versionné**, ce qui ne valide pas le livrable K-5.7 documenté dans `PLAN_K5` / `VERIFY_K5`.

---

## 2. Imports orphelins

Recherche ciblée : motifs `kioskPerf`, `perf.cold_start`, `perf.fcp`, … (hors `node_modules`).

**Code applicatif Vue/JS :** seul `KioskAppComponent.vue` importe `kioskPerf` ; pas d’autres références résiduelles dans `resources/js` hors commentaires `kioskAnalytics.js`.

**Risque :** ce n’est pas un « import vers un fichier absent », mais un **module vide** : comportement au build (export manquant) ou au runtime (`default` absent, erreur avalée par `try/catch`).

---

## 3. kioskPerfBudgets.js : statut

- **Fichier présent et complet** (constantes `PERF_BUDGETS`, `isOverBudget`, `budgetFor`).
- **Production :** pas d’import direct depuis d’autres fichiers sous `resources/js/` (le consommateur prévu était `kioskPerf.js`).
- **Tests :** import actif depuis `tests/js/kioskK5PerfInstrumentation.spec.js`.

**Conclusion :** **orphelin côté bundle kiosk** tant que `kioskPerf.js` n’implémente pas l’instrumentation ; **non orphelin** pour la couverture test des budgets (K-5.6).

---

## 4. Whitelists perf.* (front + back)

| Zone | Fichier | Statut |
|------|---------|--------|
| Frontend | `resources/js/helpers/kioskAnalytics.js` | Les 9 événements `perf.*` sont toujours dans la whitelist (L86–98). |
| Backend | `app/Http/Controllers/Frontend/KioskEventController.php` | Les 9 événements `perf.*` sont toujours autorisés (L139–150). |

Les deux côtés restent **alignés** sur le contrat nominal ; le problème est l’**absence d’émission** côté client, pas la liste autorisée.

---

## 5. Wiring KioskAppComponent

Fichier : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`.

- **Import :** `import kioskPerf from '../../../helpers/kioskPerf';` (≈ L113).
- **`mounted()` :** `try { kioskPerf.start(); } catch (_) { … }` (≈ L278–281).
- **`beforeUnmount()` :** `try { kioskPerf.stop(); } catch (_) { … }` (≈ L314–315).

Le câblage **structurel K-5.9 est toujours là** ; l’effet utile dépend entièrement du contenu de `kioskPerf.js`.

---

## 6. Tests perf

- `rg -l "kioskPerf" tests/js/` → **`tests/js/kioskK5PerfInstrumentation.spec.js`**.
- Pas de fichiers nommés `tests/js/kioskPerf*.spec.js` dans ce worktree.
- Le spec **n’est pas** annoté `skip` / `todo` dans l’en-tête ; les blocs **K-5.7** exigent une implémentation réelle de `kioskPerf.js` (observers, heap, etc.).

---

## 7. Cibles K-5 encore satisfaites ?

Références lues :

- `tasks/k-hardening/PLAN_K5_PERFORMANCE_STABILITY_2026-04-18.md` — cibles : stabilité long-running (24 h, rush 100 commandes/h), budgets FCP/LCP/INP/CLS, heap drift, cold start, etc.
- `reports/execution/VERIFY_K5_PERFORMANCE_STABILITY_2026-04-18.md` — verdict **RESOLVED_100** avec preuves **file:line** sur `kioskPerf.js` (observers, heap 30 s, etc.).

**État audit (2026-04-20) sur `testttt-kiosk-p93` :**

- **`kioskPerf.js` vide** → **aucune** mesure Web Vitals, **aucun** `perf.*` émis via `kioskAnalytics`, **aucun** lien avec `kioskPerfBudgets` en runtime.
- **Impact chiffré (garanties K-5 liées à l’instrumentation) :**
  - **Taux d’émission des 9 familles `perf.*` : 0 %** (instrumentation absente).
  - Couverture RUM / tableaux de bord K-9 prévus sur `perf.lcp`, `perf.heap_drift`, etc. : **non assurée** par le code actuel.
  - Les objectifs **non-instrumentation** du plan (leaks, rush simulator Vitest, etc.) peuvent rester valables **si** d’autres fichiers/tests sont inchangés ; cette tâche ne les a pas ré-exécutés.

---

## 8. Verdict + recommandation

**Sous-verdict : C — régression silencieuse (implémentation absente / stub vide),** faute de :

- **A** — Aucune trace d’un renommage ou d’une fusion vers un autre helper **documenté** remplaçant `kioskPerf` pour les `perf.*`.
- **B** — Aucun **feature flag** ou toggle identifié dans `resources/js` désactivant proprement l’instrumentation tout en conservant un no-op documenté.

**Recommandation (hors périmètre d’édition de cette mission read-only) :**

- Traiter comme **FAIL** audit : aligner avec **backlog K-10.1** ou **hotfix** (restaurer le contenu versionné de `kioskPerf.js` depuis une révision connue, ou réimplémenter le minimum K-5.7 / K-5.10), **supprimer** le stub vide non suivi ou le **commiter** intentionnellement avec implémentation complète.
- Revalider **Vitest** `kioskK5PerfInstrumentation.spec.js` après correction.
- **K-9** : whitelists inchangées mais **observabilité perf effective** à rétablir pour que les garanties documentées dans `VERIFY_K5` redeviennent **vérifiables** en production.

---

*Audit read-only — racine d’analyse : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93`. Rapport produit le 2026-04-20.*
