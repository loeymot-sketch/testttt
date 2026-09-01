# Prochaine boucle QA — Dashboard et contrôle

Date : 2026-08-29  
Source : `reports/audit/CODEX_DEEP_AUDIT_DASHBOARD_CONTROL_2026-08-29.md`  
Recommandation : **REWORK complexe**, sans clôture du cycle courant.

## Objectif de la prochaine boucle

Rendre le dashboard et le cockpit de contrôle sûrs, strictement autorisés et isolés par branche, aligner leurs signaux avec les vraies preuves opérationnelles, puis obtenir une validation automatique et E2E fraîche sans gate rouge.

## Routage

- `EXECUTION_TIER: complex`
- Autorité PLAN/AUDIT : Claude selon la boucle FoodKing.
- PLAN_REVIEW, EXECUTE complexe et GPT_FINAL_AUDIT : Codex CLI après réparation de son exécutable plateforme.
- Validation navigateur : Playwright seulement après levée explicite du safety gate.
- Invariants touchés : `branch_id`, auth, dispatch/queue, pricing indirect, frozen zones.

## Ordre strict

### 1. Lever le blocage procédural

- Faire reconnaître par le hook la preuve de lock/gate du `KioskWizardComponent.vue` staged, ou sortir cette mutation du snapshot de validation par décision du propriétaire.
- Traiter `docs/gates/GATE_DASHBOARD_CONTROL_E2E_FROZEN_WORKTREE_2026-08-29.md` ; le hook actuel ne possède aucune logique de reconnaissance des locks et le lock staged n'est pas contresigné.
- Ne pas contourner `safety-check.sh` et ne pas modifier/revert les changements d'un autre agent.
- Réparer l'installation locale Codex puis valider `npm run codex:doctor`.

### 2. Fermer l'autorisation du cockpit

- Définir la permission/role canonique pour la lecture de `system-health` et des interrupteurs.
- Appliquer la même règle côté routes, contrôleurs et router Vue.
- Ajouter une matrice Admin/Tenant Admin/POS Operator/Chef/manager/non authentifié pour GET et PUT.
- Corriger le faux positif documentaire dans `AdminRoutePermissionFloorTest`.

### 3. Rendre l'isolation et les entrées fail-closed

- Refuser tout dashboard non-admin lorsque `branch_id` est absent ou invalide.
- Couvrir toutes les méthodes, notamment `auditTrail()`.
- Valider et borner les plages de dates ; répondre 422, jamais 500.
- Ajouter plafond/pagination côté serveur pour SLA et gros volumes.

### 4. Unifier la vérité de santé opérationnelle

- Créer une source de vérité partagée entre cockpit et readiness pour backups et queue.
- Persister/exposer le résultat réel de `backup:verify-restore`, avec fraîcheur et cause d'échec.
- Remplacer le 0 de queue en erreur totale par `unknown/degraded` et faire influer ce résultat sur le statut.
- Tester les transitions vert/dégradé/rouge et les seuils exacts.

### 5. Durcir l'interface et la preuve d'audit

- Enregistrer les bascules sensibles dans l'audit métier durable avec avant/après et acteur.
- Ajouter confirmation ou garde explicite pour les actions à effet immédiat.
- Rendre permissions, loaders, erreurs et polling fail-closed et observables.
- Corriger les lacunes clavier/focus/ARIA, états vides et erreurs inline.
- Ajouter des tests directs aux huit widgets sans référence, monter réellement `AuditTrailComponent.vue` dans un test, et couvrir `SystemHealthComponent.vue`.

### 6. Rétablir les gates globaux

- Corriger les 5 échecs backend et les 4 échecs frontend.
- Réparer la regex de la sentinelle pricing et qualifier les hits `composer invariants`.
- Réduire les fixtures E2E hardcodées ou relever le plafond uniquement avec justification gouvernée.
- Reconstruire `app.js`/`pos-app.js`, puis respecter fraîcheur et budgets.
- Résorber i18n manquante et trier les vulnérabilités Composer/npm avec versions cibles et tests de compatibilité.

### 7. Validation de convergence

Exécuter, dans cet ordre :

1. `npm run verify:boucle`
2. `.cursor/hooks/safety-check.sh`
3. suites ciblées dashboard/santé/autorisations/branch/date/backup/queue
4. `php artisan test --exclude-group=manual`
5. `npx vitest run`
6. lint status/pricing, invariants, i18n, bundle, audits dépendances
7. Playwright dashboard/contrôle dédié sur 1366×768, 768×1024 et 390×844 : Admin 200, POS/Chef/manager 403, lien cockpit absent, états ok/degraded/unknown, erreurs réseau, backup, queue et toggle réversible
8. Playwright supervisor A→E pour le périmètre historique du cycle, sans le présenter comme preuve dashboard
9. audit Claude terminal
10. audit final GPT/Codex

## Critères d'acceptation

- Aucun utilisateur non autorisé ne lit ni ne modifie le cockpit.
- Aucun non-admin sans branche ne reçoit de données globales.
- Aucune entrée date ne provoque 500 ni requête non bornée.
- Le dashboard ne peut être vert si readiness, restauration backup ou queue sont inconnus/rouges.
- Chaque bascule sensible produit une preuve d'audit métier.
- Toutes les suites prévues sont effectivement exécutées, sans skip silencieux et sans échec.
- La collecte des tests, les captures et les anciens artefacts ne sont jamais comptés comme une exécution fraîche.
- Double verdict PASS documenté avant CLOSE.
