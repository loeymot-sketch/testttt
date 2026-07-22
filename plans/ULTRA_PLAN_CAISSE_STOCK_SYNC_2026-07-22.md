# ULTRA-PLAN — CAISSE MAX (UX caissier · sécu · perf · sync) + STOCK SSOT + SYNC ENGINE + KDS/OSS
2026-07-22 · Dev senior / second cerveau · HEAD départ `42ce167e2`

## §0 Mission owner (condensé)
Caisse **d'abord et au maximum** : optimisation, sécurité, synchronisation intelligente, adaptation totale au caissier (interface + fonctionnalités principales + accessibilité). En s'appuyant sur une concentration max sur **gestion de stock** et **système de synchronisation**. Ensuite cuisine (KDS) : fonctionnalités + synchro globale avec écran client (OSS) et site web. Méthode exigée : ultra-planification, workflows dynamiques, agents adversaires, parallélisme maximum, discipline maximum, corrections réelles, **test-e2e réel navigateur**.

## §1 État des lieux (vérifié à l'instant)
- HEAD `42ce167e2` — sessions précédentes ont déjà livré : tracker web PENDING→À encaisser (caisse), CatalogHub fusion Catalogue+Stock, photo sur dashboard stock, flag composer caisse ON (VPS), commandes programmées KDS T-20, G-PRIX 1,90 €, fix nom sauce ticket cuisine web.
- **⚠️ Goal parallèle EN VOL** (autre session, `GOAL_MEGA_BORNE_TICKET_STOCK_MOBILE_2026-07-22`) : 3 agents (kiosk formule-split [LOCK frozen], mobile-stock-PIN [app/Http/Controllers/Mobile/, config/mobile_stock.php, routes/web.php dirty], responsive web). → §3 anti-collision.
- **⚠️ queue-worker DOWN** sur la box (constaté) ; soketi :6001 UP. Hypothèse à vérifier en audit : broadcasts queués → temps-réel actuellement MORT sur le poste, seuls les pollings assurent. Impact caissier/KDS direct.
- VPS : TAMPER NF525 branche 1 pré-existant (Workstream A, owner-gated) — hors périmètre code ici.
- Serveurs : :8766 (canonique) et :8000 UP.

## §2 Périmètre & priorités
| Prio | Workstream | Contenu |
|------|-----------|---------|
| P0 | **WS-A Caisse UX/A11y** | PosComponent + tuiles items + tracker + park/resume + refund/loyalty/collect modals + reçus ; vitesse caissier, hotkeys, cibles tactiles, focus, i18n FR, états erreur/offline |
| P0 | **WS-B Caisse Sécurité** | authz par action (routes POS ×212), gates remise/refund/tiroir, idempotency, branch scope, session |
| P0 | **WS-C Caisse Perf** | pos-app.js 2,13 Mo, latence par action, N+1 endpoints POS, pollings, re-renders, fuites intervals |
| P0 | **WS-G Money-path caisse** | splits tranches (refund tranche-blind order 4937 → statut ?), tiroir/sessions, rendu, remises+fidélité, TVA reçu — frozen en lecture seule |
| P1 | **WS-D Stock SSOT** | AvailabilityService, ledger stock/quota, propagation 86 complète (caisse/KDS/borne/web/OSS), CatalogHub+photo (régressions), races |
| P1 | **WS-E Sync engine** | matrice événement→producteur→canal→consommateur→fallback→trou ; outbox, worker, reconnexion, ordering, pertes ; worker DOWN à expliquer |
| P2 | **WS-F KDS + OSS** | board (aging/bump/programmées), print bridge, formatter ticket, transitions OSS, cohérence KDS↔OSS↔tracker caisse |
| — | **WS-H E2E réel** | Playwright navigateur : flux caisse complet, 86 propagation, commande web→caisse→KDS→OSS ; captures analysées |

**Hors-scope assumé** : fichiers du goal en vol (Mobile/*, formule-split, responsive web), deploy VPS/Vercel (gate owner), TAMPER VPS, coupon SSOT frozen, auto-accept web COD (décision produit déjà escaladée).

## §3 Guardrails
- **Frozen intouchables** (audit lecture seule, LOCK candidates listés, 0 edit) : pos-wizard.js, pos-wizard.css, admin-pos-v4.blade.php, PaymentComponent.vue, PosV5TrancheRow.vue, KioskWizardComponent/App/Upsell, PricingService, OrderStateMachine, FiscalSequenceService, ZReportService, AuditLogService, BranchScope, IdempotencyKeyMiddleware.
- **Anti-collision goal en vol** : interdiction d'éditer les fichiers dirty de l'autre session ; fichiers PARTAGÉS (routes/web.php, routes/api.php, .env.example, lang/*, PROJECT_BRAIN.md) = **écrivain unique : moi**, en append minimal, jamais par un implémenteur parallèle.
- NF525 : aucun nouveau chemin d'écriture fiscal ; chaîne vérifiée à chaque gate.
- Commits locaux, jamais de push sans owner.

## §4 Architecture d'attaque (vagues)
- **Vague A — AUDIT adversarial (workflow parallèle)** : 7 finders (WS-A→G) à effort max, findings exigent file:line+repro (anti-hallucination §3ter) + proposent des améliorations caissier/cuisine ; puis **réfutation adversaire par finding** (agents medium, lens « déjà fixé à HEAD ? / irréel ? / hors-scope en-vol ? »).
- **Vague B — REGISTRE** : dédup, rank P0→P3, partition par fichiers disjoints, sélection améliorations (valeur caissier / effort).
- **Vague C — IMPLÉMENTATION parallèle** : implémenteurs fichiers-disjoints, TDD (vitest en local par agent ; PHPUnit exclusivement à ma gate séquentielle), scope minimal, frozen interdit.
- **Vague D — E2E RÉEL navigateur** : caisse login→vente→paiement→ticket ; nouvelle commande web→chime/tracker ; 86→borne/web/KDS ; KDS bump→OSS. Captures lues et analysées.
- **Vague E — CONVERGENCE** : re-audit ciblé des zones modifiées, frozen diff 0, NF525 chain, suites PHPUnit/vitest filtrées, rapport final + BRAIN + mémoire + commits.

## §5 Livrable sync attendu (vague A/WS-E)
Matrice complète : `ItemAvailabilityChanged / StockLevelChanged / OrderCreated / OrderStatusChanged / ComposerProfilePublished / public-menu.{id} / private-branch / kds / oss / tracker-caisse` × (producteur, canal, consommateurs, fallback polling, fenêtre de perte, idempotence consommateur, TROU). Chaque TROU = finding.

## §6 Backlog améliorations caissier candidates (à challenger par l'audit, pas d'office)
1. **Chime + badge** nouvelle commande web/borne au tracker (le caissier ne regarde pas l'écran en continu).
2. **Indicateur état sync** discret (WS connecté / worker / dernier event) → le caissier sait si le temps-réel est vivant.
3. **Hotkeys caissier** (recherche `/`, qty +/-, park, encaisser — sans toucher PaymentComponent interne).
4. Cibles tactiles ≥48px + focus visible sur toutes les actions POS non-frozen.
5. Tuiles items : badge rupture temps réel déjà là (availability live guard) — renforcer lisibilité si audit confirme faiblesse.
6. KDS : bump clavier fiable + aging couleur si gaps confirmés ; OSS : mise en avant « prêt » + son.

## §7 Gates de convergence
frozen diff 0 · NF525 chain OK · PHPUnit ciblés verts · vitest verts · e2e réel verts avec captures analysées · registre 100 % traité (fix / réfuté / gate-owner documenté) · aucun fichier du goal en vol touché.

## §8 Risques
- Collision session parallèle → §3 (re-check `git status` avant Vague C, partition stricte).
- Worker DOWN : peut être la racine de « sync » perçue par owner → à trancher en WS-E avant d'inventer des fixes.
- Login SPA admin difficile à automatiser (mémo 21/07) → réutiliser le pattern de `pos-receives-kiosk-realtime.spec.js` ; sinon e2e via storageState token.
- PHPUnit parallèle = collision DB test → interdit aux agents, gate séquentielle unique.
