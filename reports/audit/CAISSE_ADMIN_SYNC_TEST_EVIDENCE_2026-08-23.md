# Preuves d'exécution — audit caisse, admin, borne, KDS

**Date :** 2026-08-23  
**Cible locale :** `http://127.0.0.1:8766`  
**But :** séparer les faits mesurés des recommandations d'interface et de remédiation.

## Addendum post-remédiation — preuve actuelle

Les résultats négatifs historiques ci-dessous décrivent l'état initial. Après remédiation, Wave E et le parcours multi-produits passent chacun intégralement. La matrice ciblée atteint 39 tests backend et 89 tests frontend verts, le build de production passe, et l'audit navigateur confirme les activations clavier borne. Les détails et limites sont consolidés dans `reports/execution/RUN_CAISSE-SUPERVISOR-CONTROL-20260823_2026-08-23.md`.

## Résultats positifs

| Commande ou parcours | Résultat | Ce que cela démontre |
|---|---|---|
| `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 npx playwright test tests/e2e/dashboard-nav-buttons-reachability.spec.js --retries=0` | 33/34 destinations de navigation valides | Les écrans d'administration ciblés chargent un contenu utile sans page d'erreur ni fuite i18n, hors Wheel. |
| `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 npx playwright test tests/e2e/kds-caisse-smoke.spec.js --retries=0` | Vert | Le smoke KDS/caisse est fonctionnel. |
| `php artisan test tests/Feature/Outbox/OutboxDeliveryTest.php` | 7 verts | Événements, disponibilité et contrôles Outbox vérifiés. |
| `php artisan test --filter='AfterCommitDispatchTest|KioskQuoteIntegrityTest|KioskPaymentStateMachineTest|KdsExpectedStatusConflictTest|OrderBranchIsolationTest|PaymentConfirmCrossBranchTest|OrderStateTransitionTest|PosDiscountForgeryTest'` | 32 verts | Prix serveur, paiement, isolation branche, transitions et dispatch après commit protégés. |
| Parcours navigateur administrateur vers `/admin/dashboard` puis `/admin/pos` | Chargement correct | Le dashboard et le POS exposent les compteurs et données opérationnelles attendues. |
| Parcours navigateur borne `/kiosk/idle` puis catégories | Chargement correct | Le CTA, réglage d'accessibilité, catégories et panier sont accessibles au chargement. |

## Résultats négatifs ou non conclusifs

| Commande ou parcours | Résultat observé | Qualification |
|---|---|---|
| Navigation Wheel | Clés `admin.wheel.home` et `admin.wheel.acces` rendues brutes ; contenu présent mais hors conteneur SPA attendu | Défaut UI confirmé ; intégration layout à clarifier. |
| `tests/Playwright/multi-device-appareils-2026-08-07.spec.js` | Deux sessions restent authentifiées et les deux appareils sont visibles, puis échec strict sur `locator('table')` car le Debugbar local ajoute 6 tables | Faux négatif de test confirmé. |
| `tests/e2e/test-e2e-pos-kds-sync-2026-05-10-wave-E.spec.js` | `422 Article 361 introuvable. Commande rejetée.` au quote | Fixture de test périmée confirmée ; pas une panne de synchronisation prouvée. |
| `tests/e2e/audit-kiosk-multiproduct-kds-journey.spec.js` | Timeout global à 360 s, sans jalon de progression exploitable | Non concluant. Le test/harness doit devenir diagnostique avant d'inférer une panne produit. |

## Nettoyage et intégrité des données d'audit

Le parcours multi-produits utilise des fixtures préfixées `AUDIT-KIOSK-MULTI`. Après exécution, elles ont été désactivées puis vérifiées : aucun article, catégorie, taxe ni ordre synthétique actif ne subsiste. Les commandes, transitions, événements, journaux d'audit et séquences fiscales historiques sont conservés ; aucune donnée métier existante n'a été supprimée ou modifiée manuellement.

## Conditions pour obtenir une preuve de synchronisation recevable

1. Base de test isolée avec branche, terminal/borne et articles expressément provisionnés.
2. Fixture dynamique ou seed versionné, jamais un identifiant de produit local pérenne.
3. Pusher/Soketi disponible, ou mesure explicite du fallback polling avec budget distinct.
4. Jalon et timeout court sur quote, création, paiement, apparition KDS, apparition POS, transition cuisine et annulation opérateur.
5. Artefacts Playwright écrits dans `test-results/` par run, avec cleanup garanti en `finally`.

## Conclusion de test

**Conclusion actualisée : le comportement métier ciblé et les deux démonstrations navigateur multi-surface réparées sont verts.** La certification matérielle, la latence temps réel sur infrastructure représentative et les gates pricing/frozen historiques restent des validations séparées ; elles ne sont pas auto-approuvées par ce cycle.
