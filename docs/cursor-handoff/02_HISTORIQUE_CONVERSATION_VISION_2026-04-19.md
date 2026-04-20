# Passation Cursor — Fichier 2/3 : historique de conversation & vision

> Ce fichier résume **ce qui a été fait dans les échanges** (y compris résumés intermédiaires
> et continuations), pas seulement le dernier message. Vision = **objectif produit** +
> **comment** on y est arrivé.

---

## 1. Vision globale (fil conducteur)

**Objectif** : rendre le **kiosk FoodKing** « production-ready » via une série **K-1 → K-10**
(hardening post-P9.3) : robustesse wizard, menu/disponibilité, offline, hardware, perf,
sécurité, UX/a11y, multi-branch, observabilité, puis **gate d’acceptation** avec preuves
automatisées.

**Principes tenus** : SSOT backend, isolation `branch_id` côté machine pour le kiosk,
EventContract + outbox `afterCommit`, tests massifs (Vitest + PHPUnit + partie Playwright),
documentation RUN/VERIFY par vague.

---

## 2. Chronologie fonctionnelle (ce qui a été livré / décidé)

### Phase K-series (worktree `testttt-kiosk-p93`, branche `feat/kiosk-phase-9-3`)

- **K-1 → K-7** : déjà closes avant la fin de la série d’échanges résumés ; détails dans
  `K_TRACKER.md` et RUN/VERIFY associés.
- **K-8** : multi-branch hybrid runtime (`/kiosk/context`, `kiosk.locale`, thème,
  capabilities, pentest 5 branches, heal item SSOT).
- **K-9** : observabilité — Sentry opt-in, correlation client, SLI ws reconnect, SLO doc +
  `SloEvaluatorJob`, heatmap POC consent, CSP report endpoint, canal `observability`,
  whitelists `observability.*`, scrub PII ; OTel et UI admin SLO **reportés**.
- **Heal K-9** : alignement whitelist `add_to_cart_guarded_against_double_click` ;
  extension scrub `device_id`, `session_id`, `username`, etc.
- **K-10.0** : acceptance — checklist 100 items (périmètre ADR K-10), PHPUnit Feature full
  vert, Vitest vert, correctifs **broadcast** (`DispatchDomainEventsJob` →
  `connection()->broadcast()`), **phpunit.xml** `BROADCAST_DRIVER=log`, mocks
  `EventContractTest` / `OutboxTest`, fixes **allergènes** (codes UE `lait`, ordre création
  Item, `MenuProjectionServiceTest`, `ItemResourceAllergensTest`), doc onboarding opérateur.

### Audit 110 % kiosk (read-only, même worktree)

- Mission **détective** : 16 axes, **60 findings** (0 P0, 5 P1, 28 P2, 27 P3), preuves
  fichier:ligne, rapports multi-fichiers sous `reports/review/AUDIT_KIOSK_110_*`.

### Demande « rapport K implémentés + logique globale »

- Création de `reports/review/REPORT_K1_K10_GLOBAL_LOGIC_2026-04-19.md` (synthèse logique
  K-1→K10 + tensions + renvois audit).

### Demande actuelle (passation nouvelle session Cursor)

- Création des **trois fichiers** dans `docs/cursor-handoff/` du clone `testttt` avec rôles
  1 = contexte max + paths, 2 = ce fichier (historique/vision), 3 = démarrage prochaines étapes.

### Post-passation (alimentations sur `testttt`, 2026-04-19)

- **Allergènes** : alignement `tests/Feature/KioskPhase1/AllergensSeederTest.php` sur les
  **codes UE du seeder** (`crustaces`, `lait`, `fruits_a_coque`, …) — à **reporter** sur
  `testttt-kiosk-p93` si divergence.
- **POS 110 %** : famille `reports/review/AUDIT_POS_110_*` + tracker
  `AUDIT_POS_110_FINDINGS_TRACKER.md`.
- **Rapport global P** : `reports/review/REPORT_GLOBAL_P_IMPLÉMENTATIONS_2026-04-19.md`.

---

## 3. Décisions / « vision » explicite (ce qu’on a choisi de NE PAS faire tout de suite)

- **OpenTelemetry** : reporté (correlation + logs comme intermédiaire).
- **CSP enforce** : reste Report-Only + `unsafe-inline`/`unsafe-eval` (dette sécurité P1).
- **Pusher multi-tenant par branche** : reporté K-10.1 / ADR_K8.
- **UI admin** timeline SLO, heatmap renderer, purge ActionLog : backlog K-10.1.
- **K-10.0** : verdict PRODUCTION READY **périmètre ADR** (gate tests + doc), pas « toutes
  les features HANDOFF ».

---

## 4. Tensions connues (mémoire de raisonnement — pas des bugs non prouvés)

- Corrélation **HTTP** vs **outbox** (`Str::uuid()` sur `OrderCreated`) — désalignement
  observabilité (P1).
- Fallback **montant** paiement UI si réponse API incomplète (P1 — UX vs SSOT écriture).
- `NormalItemResource` **is_available** global vs grille menu branche (P1 UX/compliance).
- E2E Playwright : pas de **golden path** paiement kiosk réel ; mocks Echo masquent Pusher.

---

## 5. Ce que le nouveau compte Cursor doit « savoir » sur l’humain / le projet

- Tu travailles souvent sur **deux chemins** : `testttt` et `testttt-kiosk-p93` ; la série K
  complète vit surtout sur **kiosk-p93**.
- Les **règles workspace** `.cursor/rules/*.mdc` et `AGENTS.md` restent la référence process.

---

*Fin fichier 2/3.*
