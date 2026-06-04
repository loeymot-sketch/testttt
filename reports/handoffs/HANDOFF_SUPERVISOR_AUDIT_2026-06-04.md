# Handoff → SUPERVISEUR (autre session Claude) · AUDIT des plans CŒUR Bulletproof · 2026-06-04

> **Rôle du receveur** : tu es le **superviseur**. Tu n'exécutes RIEN. Tu **audites** les plans ci-dessous, tu **raisonnes fort** contre la vision V1, et tu rends un **verdict d'autorisation** (autorisé / à ajuster / bloqué) par plan + un GO/NO-GO global pour l'exécution. Anti-hallucination : toute affirmation = vérifiée `file:line` dans le vrai code, sinon « à vérifier ».

## §1. État du cycle
- **Phase** : PLAN → **AUDIT** (avant exécution). Rien de ces 7 plans n'est appliqué.
- **Cycle** : « V1 CŒUR Bulletproof + zéro crash » (pré-cloud).
- **Plans à auditer** : `plans/GOAL_V1_CORE_BULLETPROOF_2026-06-04.md` + `plans/core-bulletproof/` (README + PR-01..PR-07).
- **Dernier commit** : `5b8f441d4` (working tree avec changements pré-existants d'autres sessions — voir §5).
- **Branche** : `heal/cms-pr1-quickwins-2026-05-18`.
- **Déjà appliqué cette session (live + vérifié, PAS un plan)** : fix staff-only (`config/features.php` clé `staff_only_mode`, `master.blade.php:183` env→config, `.env STAFF_ONLY_MODE=true`) → vitrine `/home`/`/offers` désactivée, spec `06-staff-only-routing` 8/9 vert.

## §1.5. À lire AVANT de juger (ordre, ~15 min)
1. `CONSTITUTION.md` (racine) — vision V1 LOCAL Le Cayenne + règles dures + 5 systèmes + statut TPE simulé.
2. `CLAUDE.md` §§5-13 — LOOP, frozen zones (§7), NF525 (§8), decision framework (§10).
3. `plans/GOAL_V1_CORE_BULLETPROOF_2026-06-04.md` — le GOAL parent (matrice des circonstances §3 = pièce maîtresse).
4. `plans/core-bulletproof/README.md` puis `PR-01..PR-07` — les 7 plans à auditer.
5. `reports/diagrams/FOODKING_STRUCTURE_V1_2026-06-04.html` — le dessin de bout en bout (contexte structurel).
6. `SYSTEM_MAP.md` + `SYNC_CONTRACT.md` — voies disjointes + contrat synchro (pour juger PR-01/02/04).

## §2. Mission
**Auditer rigoureusement les 7 plans (PR-01..PR-07) + le GOAL**, vérifier que chacun :
1. **sert le but** : rendre fonctionnel + sans crash le CŒUR (prise de commande → validation → transfert inter-systèmes → synchronisation), tout le reste secondaire ;
2. **ne casse pas ce qui marche** (additif, hors frozen-zone, NF525 intact) ;
3. a une **analyse adversariale correcte** (les effets négatifs calculés sont réels et complets) ;
4. respecte la **vision** : V1 = outil PERSONNEL Le Cayenne mono-poste local FR, **PAS un SaaS** ; cloud = APRÈS validation locale ; TPE simulé = choix assumé.

**Puis rendre un verdict d'autorisation** (cf. §6). L'owner veut une **autorisation forte** alignée vision/but — ou des ajustements précis si un plan n'est pas sûr.

**Citation owner (verbatim)** : « la prise de commande et validation de commande et la transfert de commande entre toutes les systèmes et la synchronisation tout ça c'est obligatoirement doit être fonctionnel et avec une sécurité de ne pas y avoir des crash et des problèmes graves. Et après ça tout le reste doit être amélioré au fil du temps. »

## §3. Scope de l'audit
**Dans le scope (auditer) :** les 7 fichiers `plans/core-bulletproof/PR-*.md` + le GOAL + cohérence avec CONSTITUTION/SYSTEM_MAP/SYNC_CONTRACT.
**Hors scope (NE PAS faire) :** exécuter un plan, modifier du code, toucher un fichier, lancer un daemon, lancer `config:cache`. Tu **audites et autorises**, tu n'implémentes pas.

## §4. Frozen zones / NF525 — la grille de lecture de ton audit
Un plan qui propose de toucher l'un de ces fichiers SANS LOCK+gate = **BLOQUER**. (Réf CLAUDE.md §7, `memory/reference_frozen_zones.md`.)
| Zone | Fichiers |
|---|---|
| POS wizard (strict no-touch) | `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php` |
| POS payment (frozen) | `resources/js/components/admin/pos/PaymentComponent.vue`, `.../v5/PosV5TrancheRow.vue` |
| Kiosk wizard (frozen, auditable) | `KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue` |
| NF525 (frozen) | `app/Services/Fiscal/{FiscalSequenceService,ZReportService,AuditLogService}.php` + triggers |
| Tenant/payment (frozen) | `BranchScope.php`, `IdempotencyKeyMiddleware.php`, `PricingService.php`, `OrderStateMachine.php` |

**Point de vigilance** : PR-07 IDENTIFIE `AuditLogService.php:273` (FROZEN NF525) comme cloud-blocker `env()` mais le **classe explicitement hors-PR (LOCK+gate)** — vérifie que c'est bien respecté, c'est le test décisif de la discipline frozen.

## §5. Fichiers / artefacts à auditer (vérifiés présents)
**Plans (le cœur de ton audit) :**
- `plans/GOAL_V1_CORE_BULLETPROOF_2026-06-04.md` (17.5 Ko)
- `plans/core-bulletproof/README.md`
- `plans/core-bulletproof/PR-01_daemon_scheduler_supervision.md` (le plus risqué)
- `plans/core-bulletproof/PR-02_kds_degradation_visible.md` (le P0)
- `plans/core-bulletproof/PR-03_serve_crash_safety.md`
- `plans/core-bulletproof/PR-04_outbox_alert_visible.md`
- `plans/core-bulletproof/PR-05_menu_404.md`
- `plans/core-bulletproof/PR-06_deferred_backlog.md`
- `plans/core-bulletproof/PR-07_env_config_cache.md`

**Findings adversariaux DÉJÀ produits (à challenger, pas à reprendre aveuglément)** — 5 agents read-only ont audité ; résumés dans `PROJECT_BRAIN.md §2` + chaque PR §5. **Re-vérifie les 5 affirmations à fort impact** :
1. PR-01 : `app/Console/Kernel.php:105` + `app/Jobs/CleanupStalePendingKioskOrders.php:64` → 81 ordres kiosk PENDING auto-rejetés à `schedule:work`. **Compte réel à reconfirmer.**
2. PR-01 : `app/Jobs/DispatchDomainEventsJob.php:46` `onQueue('high')` → `queue:work` simple inerte.
3. PR-02 : masquage dégradation aussi dans `PosOrdersTrackerComponent.vue:478` + `ConnectionStatusBanner.vue:73`.
4. PR-07 : `app/Services/Fiscal/AuditLogService.php:273` `env(FISCAL_AUDIT_SECRET_BRANCH_*)` (frozen NF525).
5. PR-05 : `public/menu/le-cayenne-v2/` = doublon de `public/images/menu/` (`config/menu_images.php:30` lit `images/menu`).

**État working tree (NE PAS confondre avec mes plans)** : la branche a des changements **pré-existants** d'autres sessions (`app/Services/OrderService.php`, `PaymentService.php`, `public/js/*` bundles, `mobile/*`) — **pas** produits par ce cycle de planification. Mes seuls changements code = `config/features.php` + `master.blade.php` (staff-only, déjà live). Tout le reste de ce cycle = des plans (docs), 0 code.

## §6. Critères d'acceptation = ce que ton verdict DOIT contenir
Produire `reports/handoffs/SUPERVISOR_VERDICT_2026-06-04.md` avec :
1. **Par PR (01→07)** : `AUTORISÉ` | `AUTORISÉ-AVEC-AJUSTEMENTS (liste)` | `BLOQUÉ (raison)`. Pour chacun, attester : (a) sert le cœur/le but ; (b) additif/hors-frozen ; (c) NF525 intact ; (d) analyse adversariale réelle et complète (cite un effet négatif manquant si tu en trouves un) ; (e) rollback crédible.
2. **Vérif anti-hallucination** : reconfirme les 5 affirmations §5 (grep/Read), signale toute divergence.
3. **Ordre d'exécution** : valide ou corrige l'ordre conseillé (PR-02→PR-04→PR-01 post-triage→PR-03→PR-05/06/07).
4. **Gates owner** : confirme les gates (G0 commit, G1 daemons, triage des 81 ordres, G2 /menu, G3 ZRPT, G-CLOUD) avec WHO/WHAT/WHERE.
5. **VERDICT GLOBAL** : `GO exécution` (et dans quel ordre / sous quels gates) ou `NO-GO (raisons)`.
6. **Alignement vision** : une phrase attestant que l'ensemble respecte « V1 perso Le Cayenne, pas SaaS, cloud après, cœur d'abord ».

## §7. Commit / push
**N/A — audit only.** Le receveur écrit UN fichier verdict (`reports/handoffs/SUPERVISOR_VERDICT_2026-06-04.md`). Pas de code, pas de commit de code, **jamais de push**.

## §8. Rollback
**N/A** — aucun changement appliqué (audit). Si le receveur trouve qu'un plan appliqué romprait quelque chose, il le dit dans le verdict (c'est tout l'intérêt de l'audit AVANT exécution).

## §10. Indices de raisonnement (pour un receveur LLM)
- **Contrainte dure owner** : le CŒUR (commande→validation→transfert→sync) doit être fonctionnel + zéro crash ; le reste est secondaire/incrémental. Si un plan met le cœur en risque pour un gain secondaire → BLOQUE.
- **Vision** : V1 = outil personnel Le Cayenne, mono-poste, local, FR. **PAS un SaaS.** Ne JAMAIS remonter cloud/multi-tenant comme blocker V1. TPE simulé = choix assumé, pas un bug.
- **Pattern récurrent** : « l'horizon ment » — des audits passés ont déclaré « tout vert » à tort. Cette session a prouvé 2 défauts réels que ces verts avaient ratés (vitrine staff-only OFF, `/menu` 404). Donc : **ne fais pas confiance aux « convergé GREEN » du passé** ; re-vérifie le code.
- **Le plus gros risque d'exécution** est PR-01 (81 ordres auto-rejetés + queue `high`). Concentre ta sévérité là.
- Mémoire Graphiti `group_id=foodking` — `search` « staff-only », « outbox scheduler », « daemon » si besoin de contexte.

## §11. Anti-patterns à éviter (ton audit)
- **Ne PAS rubber-stamper.** Si un plan est correct, dis-le avec preuve ; si un effet négatif manque, AJOUTE-le.
- **Ne PAS resurface cloud/SaaS comme blocker V1** (CONSTITUTION explicite).
- **Ne PAS exécuter** un plan « parce qu'il a l'air sûr » — ton rôle est d'autoriser, pas d'appliquer.
- **Ne PAS valider une affirmation sans la grep** (anti-hallucination CLAUDE.md §3ter).
- **Ne PAS autoriser un touch frozen sans LOCK+gate** — même « mineur ».
