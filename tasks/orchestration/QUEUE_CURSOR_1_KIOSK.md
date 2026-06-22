# QUEUE CURSOR #1 — Track A Kiosk

**Usage.** Claude Code lit ce fichier pour connaître l'ordre des demandes à envoyer à Cursor #1. Une demande active à la fois. Envoi de la suivante UNIQUEMENT après verifier 100 % + RUN + HANDOFF de la précédente.

**Règle standard commune** (ne pas répéter dans chaque prompt) : autonomie totale, décomposition items par Cursor, LOCK_A si shared, verifier sous-agent 100 % RESOLVED, RUN + VERIFY + HANDOFF, mise à jour `CROSS_TRACK_STATUS.md`, invariants (SSOT pricing, branch_id, OrderStateMachine, afterCommit, EventContract V1, Spatie Permission). Escalade humaine si shared ambigu ou invariant menacé.

---

## Position actuelle

- [x] P9.1 merged main
- [ ] P9.2 merge ready (pending human)
- [ ] **P9.3 en cours autonomie**
- [ ] P9.4 merge ready (pending human)
- [x] P9.5 merged main
- [ ] P9.6 analytics + observability + admin (démarre après P9.3)

## Queue post-P9.6

### AUDIT 110 % KIOSK (read-only, multi-fichiers)

Audit complet 110 % du domaine Kiosk couvrant 16 axes : architecture, state, sync temps réel, pricing, branch isolation, OrderStateMachine, UX/a11y/i18n, hardware, offline, sécurité, data, observability, perf, tests, regression risks, déploiement multi-branch.

Mode read-only total. Grep/Read ciblé + délégation massive sous-agents. Pour chaque finding : id, titre, axe, criticité (P0/P1/P2/P3), fichier:ligne, preuve, cause, remédiation, effort.

Livrables `reports/review/` : AUDIT_KIOSK_110_EXECUTIVE + 10 fichiers par axe + FINDINGS_TRACKER + HIDDEN_RISKS. Executive summary ≤ 300 lignes, chiffré.

Durée : plusieurs heures. Ping seulement à livraison executive ou P0 critique.

### #K-1 Wizard stress test massif
Playwright 500 parcours + perturbations (Pusher down, item 86 vol, timeout, back, lang switch, paiement interrompu). Failure rate < 0.1 %, zéro perte panier, zéro prix incohérent.

### #K-2 Menu availability end-to-end
Audit + hardening item 86, `item_branch_availability`, sync Kiosk ↔ POS ↔ KDS < 2s, paniers kiosk en cours gérés proprement, zéro race condition.

### #K-3 Offline mode kiosk
Vision : kiosk fonctionnel ≥ 15 min sans internet. Offline queue IndexedDB + SW, sync reconnect, conflict resolution, telemetry offline.

### #K-4 Hardware integration deep
Imprimante, caméra, CB, buzzer : chaque failure mode, chaque timeout, dégradation gracieuse prouvée par harness simulé + manuel scripté.

### #K-5 Performance & stabilité long-running
Kiosk stable 24h, rush hour 100 orders/h, zéro memory leak, jank < 16ms, cold start < 3s. Suite benchmark CI automatisée.

### #K-6 Security hardening kiosk
Lockdown navigateur, prévention jailbreak touch, session hijack, branch isolation deep pentest. 5 vecteurs d'attaque minimum identifiés + fixés + prouvés.

### #K-7 UX Splash-level polish
Animations < 300ms, tous états designés, microcopy i18n, WCAG AA, gloves/wheelchair/daltonisme. Captures avant/après + audit a11y auto.

### #K-8 Multi-branch deployment & config
Thèmes, menu overrides, langues, TVA, hardware profile, Pusher config par branche. Zéro fuite cross-branch prouvée sur 5 branches simulées.

### #K-9 Observability & telemetry kiosk
Sentry + traces corrélées order → event → dashboard, heatmaps opt-in, SLO kiosk, alertes seuils. Branché sur admin existant.

### #K-10 Acceptance suite kiosk finale
Checklist 100 items vérifiables avec evidence, verdict binaire PRODUCTION READY. Si non → remédiation précise.
