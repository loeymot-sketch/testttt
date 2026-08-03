# GOAL S3 — SYNCHRONISATION TOTALE borne↔caisse↔web↔KDS (2026-07-29)

> Tu es le LEAD SYNCHRO. Lis `plans/DISCIPLINE_MULTI_SESSIONS_2026-07-29.md` +
> `SYNC_CONTRACT.md` D'ABORD. Mission owner : « tout synchronisé, pas de doublage,
> pas de différence entre caisse/borne/site » — le système nerveux du resto.
> Convergence §6, autonomie §7.

## Ownership (tes chemins)
- `app/Events/**`, `app/Jobs/**` (Dispatch/outbox/rescue), `app/Domain/Events*`,
  `app/Services/*Sync*`, `AvailabilityService`, `KitchenReleaseRule`,
  broadcasting/Echo config, `config/broadcasting|queue`, soketi ops
- Outbox commandes (`foodking:outbox:*`), scheduler lanes sync (Kernel — section sync)
- ⚠️ FROZEN intouchables : OrderStateMachine, PricingService, Fiscal/*
- Tests : `tests/Feature/Sync*|Outbox*|Broadcast*`, `tests/js` sync ·
  rapports `reports/goal-s3-sync/`
- Côté UI (kiosk/pos/kds/web) : tu ne MODIFIES pas leurs composants — toute
  demande passe par `plans/handoffs/S3-vers-S<M>-*.md`. Tu ÉCRIS des e2e
  cross-systèmes en lecture.

## État connu (anchors — racines déjà fermées, à NE PAS casser)
- Worker supervisor `--queue=high,default` (racine 22/07 fermée) ; outbox 0 stale.
- Scheduler VPS VIVANT depuis le 27/07 (redirection cron réparée) — lanes
  rescue/monitor/retry chaque minute, `storage/logs/schedule.log`.
- `DispatchDomainEventsJob` onQueue('high') ; monitor seuil 10 ; dead-letter 0.
- Web = polling 25 s + Echo public-menu bonus ; KDS poll base configurable
  (`FK_CATALOG_KDS_*`, tools/kds-poll-tune) ; soketi UP.

## Vagues
### V1 — Cartographie du système nerveux
Inventaire EXHAUSTIF : chaque event émis (grep DomainEvent/broadcast), chaque
consommateur (borne/caisse/KDS/OSS/web), chaque fallback polling + son intervalle.
Diagramme flux dans `V1-CARTE-SYNC.md` (mermaid). Pour CHAQUE fait métier
(commande créée/acceptée/prête/servie/annulée, 86, stock, temps prep) : qui émet,
qui écoute, latence attendue, chemin de secours. Trous = findings.
Acceptance : carte complète disputée par RED (rien d'oublié).

### V2 — Preuves de latence réelles
E2e chronométrés en conditions réelles : borne→KDS (<3 s), caisse→OSS, web→caisse
(commande visible « À encaisser »), 86 caisse→borne→web (<25 s pire cas),
statut prêt→suivi web. Mesures répétées ×5, P95 consigné. Worker tué → rescue
récupère (prouver) ; soketi tué → polling prend le relais SANS perte.
Acceptance : tableau latences P95 + zéro événement perdu sur 100 émis.

### V3 — Anti-doublage & idempotence (mandat owner)
Chasse : double émission, double consommation, double décrément stock, double
broadcast, replays webhook/idempotency. Injecter les courses (double-submit,
worker×2 temporaire, redémarrage mi-vol). Vérifier UNIQUE constraints + locks.
Acceptance : chaque course jouée = 1 seul effet final, tests régression.

### V4 — Durcissement & observabilité
Alarmes utiles (monitor outbox déjà là — compléter ce qui manque SANS bruit),
métriques latence persistées, runbook `docs/SYNC_RUNBOOK.md` (symptôme→cause→
commande). Backlog scheduler : vérifier chaque lane sync tourne (schedule.log).
Acceptance : runbook testé en le suivant à l'aveugle sur une panne simulée.

### V5 — Convergence
Suite Sync complète + e2e cross-systèmes ×2 cycles propres + deploy §3 + BRAIN.

## Rappels
Le TAMPER NF525 staging (id=1) est documenté — interdiction d'y toucher.
Ne déplace JAMAIS un event vers une autre queue sans prouver le consommateur.
