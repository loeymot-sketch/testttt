# T03 — Rapport Sentry front régression K-9 (2026-04-20)

## Verdict : FAIL — Sous-verdict C

Régression silencieuse (et tests cassés) : le livrable frontend `resources/js/observability/sentry.js` n’implémente plus ADR-1/ADR-9 côté SPA ; le module est un **fichier vide (0 octet)** alors que la suite Vitest et la doc d’exécution K-9 supposent une implémentation complète. Aucun remplacement front documenté dans les entrypoints (`app.js`, `bootstrap.js`). **A** et **B** ne sont pas étayés.

## Checklist : V1..V6

- [ ] **V1.** Absence de `sentry.js` confirmée — **NON** : `find … -name '*sentry*'` retourne `resources/js/observability/sentry.js` (fichier présent, **0 octet**, modifié localement 2026-04-20 selon `ls -la`).
- [ ] **V2.** Aucun `import` orphelin pointant vers le module supprimé — **NON** : `tests/js/kioskSentryBoot.spec.js` importe `installSentry`, `beforeSend`, `beforeBreadcrumb`, `scrubString`, `scrubObject` depuis `../../resources/js/observability/sentry.js`. Avec un module vide, les exports sont absents → **16 échecs** sur la spec au `npm test` (ex. `beforeSend is not a function`). Recherche applicative : aucun autre fichier `*.js`/`*.vue` sous `resources/js` n’importe `installSentry` ou `observability/sentry` (hors ce test).
- [x] **V3.** Statut de `@sentry/vue` dans `package.json` documenté — **absent** des sections `dependencies` et `devDependencies` (`testttt-kiosk-p93/package.json`). Aligné avec l’ADR-1 (dynamic import optionnel) **si** le wrapper `sentry.js` est présent ; ici le wrapper est vide.
- [ ] **V4.** ADR-9 K-9 : invariants PII scrub vérifiés (couverture ailleurs ?) — **partiel seulement côté backend** : `App\Observability\SentryBridge` + `config/sentry.php` implémentent un scrub PHP (`SCRUB_BODY_KEYS` inclut `password`, tokens, `email`, `phone`, `card_number`, `cvv`, `device_id`, `session_id`, `username`, etc.). **Côté frontend Sentry** : aucun `beforeSend` / `beforeBreadcrumb` exécutable (fichier vide) ; **pas d’init** dans `resources/js/app.js` ni dans `resources/js/bootstrap.js` (seulement `correlation.js`). Donc les exigences ADR-9 explicitement front (localStorage/sessionStorage, breadcrumbs UI, bodies sur URLs sensibles dans le SDK Vue) **ne sont pas couvertes** dans le code actuel.
- [ ] **V5.** Commit suppression identifié + message — **non applicable / vide** : `git status` → `?? resources/js/observability/sentry.js` (fichier **non versionné** dans le dépôt `testttt-kiosk-p93`). `git log --diff-filter=D -- resources/js/observability/sentry.js` ne fournit pas de commit de suppression. L’historique Git ne permet pas de dater une suppression ; l’état observé est un **trou de livraison + fichier vide non tracké**.
- [x] **V6.** Verdict A / B / C documenté avec preuves — **C** (voir sections 6 et preuves ci-dessus).

## 1. Absence du module

- Commande : `find /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93/resources/js/observability -name '*sentry*'`
- Résultat : **un fichier** `…/sentry.js` (pas une absence).
- `wc -c` : **0** octet — équivalent à un stub vide, pas à un livrable K-9.
- Copie de référence sous `testttt` : **`/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/observability/sentry.js`** — **fichier inexistant** (aucun fichier dans `testttt/resources/js/observability/` à la date de l’audit).

## 2. Imports orphelins

- Motif demandé (shell `rg` indisponible dans l’environnement d’audit : code 127) ; équivalent via recherche ciblée : seul **`tests/js/kioskSentryBoot.spec.js`** référence `observability/sentry.js`.
- **Pas** d’import dans `resources/js/app.js` (pas d’`installSentry`, pas de risque d’ImportError sur le bundle principal tel quel).
- **Régression qualité** : la spec Vitest K-9 **échoue** car le module n’exporte rien (`npm test` → échecs sur `kioskSentryBoot.spec.js`, 16 tests sur cette spec liés à des fonctions manquantes).

## 3. Statut `@sentry/vue` (`package.json`)

- Package **non déclaré** dans `testttt-kiosk-p93/package.json` (ni `dependencies` ni `devDependencies`).
- Cohérent avec une stratégie « import dynamique + no-op si absent » **uniquement si** `sentry.js` fournit cette logique ; actuellement le fichier est vide.

## 4. PII scrub : encore couvert ?

- **Backend (Laravel)** : oui — `SentryBridge::scrubEvent` et `config/sentry.php` (`before_send`) documentés et présents ; alignement large avec ADR-9 back (clés sensibles, headers, etc.). **Note** : `composer.json` ne liste **pas** `sentry/sentry-laravel` dans `require` — le pont reste no-op côté PHP tant que le SDK n’est pas installé (conforme au commentaire dans `config/sentry.php`).
- **Frontend (Vue / SDK Sentry)** : **non** — pas de code fonctionnel dans `sentry.js`, pas d’appel d’init dans les entrypoints. Si un DSN JS était exposé plus tard, **rien** n’applique les garde-fous ADR-9 front décrits dans l’ADR (ni dans l’app, ni dans les tests au vert).

## 5. Commit suppression : sha + date + message

- **Aucun commit de suppression identifiable** : le fichier `sentry.js` est **untracked** (`git status --short` → `?? resources/js/observability/sentry.js`).
- Les traces écrites au **2026-04-18** (`RUN_K9_OBSERVABILITY_2026-04-18.md`, `VERIFY_K9_OBSERVABILITY_2026-04-18.md`, copie sous `testttt-kiosk-p93/reports/execution/`) décrivent un `sentry.js` **NEW** avec init conditionnelle et tests **100 % PASS** — **divergence forte** avec l’état du workspace au **2026-04-20**.

## 6. Verdict + impact production

- **Sous-verdict C** : régression / trou de livraison par rapport au périmètre K-9 documenté.
- **Production** : pas de crash manifeste du bundle principal constaté via `app.js` (pas d’import Sentry). En revanche **aucune observabilité Sentry côté SPA**, et **non-conformité** aux engagements ADR-9 front + non-respect de la gate G1 « Sentry front » telle que décrite dans VERIFY K-9.
- **CI / qualité** : les tests Vitest `kioskSentryBoot.spec.js` **cassent** (preuve d’exécution locale : 16 tests en échec sur cette spec, fichier vide).

## 7. Recommandation (no-op ADR-9 conforme | restaurer | autre)

- **Recommandation** : **restaurer** une implémentation de `resources/js/observability/sentry.js` conforme ADR-1 + ADR-9 (init conditionnelle, `beforeSend` / `beforeBreadcrumb`, scrub clés + regex PII, no-op si DSN absent, dynamic import `@sentry/vue`), **après validation humaine** (périmètre T03b — pas d’application dans ce rapport).
- Alternative court terme si décision produit « pas de Sentry front » : retirer ou désactiver explicitement `kioskSentryBoot.spec.js` et documenter un **toggle métier** (verdict B) — **non observé** dans le code au moment de l’audit.

---

### Note chemins rapports « dernier build » (étape 8)

- Fichiers demandés sous `testttt/reports/execution/` : **introuvables**.
- Fichiers présents sous la racine auditée :  
  `testttt-kiosk-p93/reports/execution/RUN_K9_OBSERVABILITY_2026-04-18.md`  
  `testttt-kiosk-p93/reports/execution/VERIFY_K9_OBSERVABILITY_2026-04-18.md`  
  (lus pour la section 5 et la comparaison d’état).
