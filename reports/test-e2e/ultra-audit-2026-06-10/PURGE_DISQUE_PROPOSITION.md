# Proposition de purge disque (gate G-DISK du GOAL_PRODUCTION_TOTALE)
**2026-06-12** · Disque : 877 Mo libres / 460 Gi. W-INT exige ≥15 Go. Supprimer un worktree ≠ supprimer la branche (commits préservés) ; seul risque = non-commité (compteurs « dirty » ~148 = artefact `.playwright-mcp` hérité, pas du travail).

## Tier 1 — SÛRS (0 commit hors spine, ~14,0 Go) — purge immédiate proposée
| Worktree | Taille | Branche (0 ahead spine) |
|---|---|---|
| pre-cloud-exec ⚠️ kill serveur :8766 d'abord | 2,4 G | heal/pre-cloud-exec-2026-06-05 (pushée origin) |
| wave3-deployed-heal | 2,3 G | heal/deployed-dashboard-fixes |
| borne-wa-2026-06-10 | 1,4 G | validation W-A |
| printer-saga-pos | 1,3 G | feat/pos-printer-saga (branche conservée) |
| bu-borne / cms-ux | 1,9 G | intégrées au spine |
| wc-dash / wd-kds / we-auth / magical-spence | 3,7 G | validations W-B..E |
| agent-a57c / clever-hypatia / cloud-migration / elegant-perlman | 1,2 G | 0 ahead |
**Commande type** : `git worktree remove --force .claude/worktrees/<nom>` (la branche reste).

## Tier 2 — APRÈS G-PUSH (branches d'abord poussées sur origin, ~9 Go)
massive-e2e (58 ahead, 1,5 G) · heal-dashboard-redeep (25 ahead, 2,1 G — travail divergent 06-08 non intégré : DÉCISION owner intégrer-ou-abandonner) · naughty-torvalds (1,2 G) · goal-system-a (944 M) · uiux-exec (871 M — lane mobile/web) · sad-thompson (776 M) · audit-report (311 M) · blissful-mclean (530 M, 40 ahead — à identifier) · review-bench (306 M) · mobile-update (175 M).

## GARDER
release-v1 (session spine ACTIVE) · cms-gestion (clients-next, 38 ahead) · ultra-heal-w4 (cette session) · ultra-audit-brain (381 M, rapports d'audit).

**Tier 1 seul suffit à débloquer W-INT (≈14 Go ≥ 15 Go requis avec mes 75 Mo de shots purgés en sus).**
