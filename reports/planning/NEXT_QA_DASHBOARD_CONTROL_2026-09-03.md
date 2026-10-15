# Prochaine boucle QA — dashboard, contrôle et production

Date : 2026-09-03  
Source : `reports/audit/CODEX_ULTRA_AUDIT_DASHBOARD_CONTROL_E2E_2026-09-03.md`

## Priorités

### 0. Gate exploitation/conformité — avant toute déclaration production

1. Confirmer avec le propriétaire et l'exploitant que la machine mesurée est bien la machine
   d'encaissement fiscal autoritaire.
2. Ne pas changer isolément `APP_ENV` ou `POS_SIMULATION_HARDWARE` : la combinaison actuelle
   masque un blocage matériel et un changement aveugle peut arrêter la caisse.
3. Livrer d'abord une alerte cockpit/readiness explicite quand des documents fiscaux existent
   alors que les gardes production sont inertes.
4. Après câblage bancaire : simulation `false`, préflight, passage `production`, boot contrôlé,
   health 503 sur restore/backup/scheduler rouge, puis vérification NF525.

### 1. Corriger la vérité des quatre widgets dashboard

- `OverviewComponent` : états `loading/success/empty/error`, message visible, retry, une erreur
  par tuile si les requêtes restent indépendantes.
- `AuditTrailComponent` : premier échec distinct du vide, données anciennes marquées stale,
  retry et cleanup du polling.
- `OrderSummaryComponent` : ne pas conserver un ancien succès sans marqueur ; erreur inline,
  retry et barres avec sémantique accessible.
- `LastZReportWidget` : distinguer 403, panne HTTP, liste vide et dernier Z disponible.
- Pour chacun : tests de composant succès, vide, 403, 500, timeout, récupération et démontage.

### 2. Fermer les gardes transverses

- Traiter les cinq findings de `npm run pos:lint:pricing`, sans toucher aux zones frozen sans
  gate enregistré.
- Qualifier le fallback guest `request.branch_id` de `FrontendOrderService` : contexte signé ou
  résolu serveur, ou exception bornée documentée/testée.
- Rendre `check-invariants.sh` précis sur `DispatchableAfterCommit` et éviter les imports/
  commentaires comme faux positifs. Le pipeline doit sortir `0` sans whitelist large.

### 3. Ajouter les preuves navigateur manquantes

- Interception contrôlée ou environnement dédié pour worker mort, stale, purge/drain en erreur,
  backup vieux, restore rouge, timeout et récupération.
- Démarrer le serveur temps réel et prouver au moins un événement caisse→KDS, sans erreur
  WebSocket tolérée.
- Jouer deux bascules concurrentes et vérifier état final, audit et rollback.
- Extraire le PDF EOD et comparer les chiffres au Z sur remboursement et multi-tender.

### 4. Certificat final sur snapshot immuable

1. Préserver les modifications utilisateur, puis obtenir un worktree propre et un HEAD figé.
2. Rebuild production ; enregistrer SHA commit, manifest, bundles servis, base URL et DB de test.
3. Exécuter safety, pricing, invariants, PHPUnit complet, Vitest complet et les 16 E2E plus les
   nouveaux scénarios dégradés. Conserver les logs bruts.
4. Vérifier zéro fixture résiduelle, zéro diff frozen, zéro erreur console/network inattendue.
5. Audit Claude terminal PASS puis audit final Codex PASS ; gate humain seulement ensuite.

## Critères de clôture

- Aucun état dashboard ne confond panne, vide, stale et chargement.
- `APP_ENV`, hardware et readiness reflètent l'activité fiscale réelle.
- Pricing guard et invariants sortent `0`.
- PHPUnit/Vitest/E2E appartiennent au même SHA propre et aux bundles réellement servis.
- WebSocket, concurrence interrupteur et parité PDF/Z ont des preuves fraîches.
- Aucun P0/P1 ouvert ; double audit PASS et validation humaine.
