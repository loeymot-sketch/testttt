# Audit adversarial du plan GOAL_CMS_GESTION_2026-06-10 — 3 agents RED (2026-06-10)

## Verdict : plan CONFIRMÉ avec corrections (toutes appliquées au plan, commit suivant)

### Agent 1 — Anti-hallucination (49 tool-uses)
- ~45 anchors, 16 fichiers tests, 3 migrations, flags, listeners : **CONFIRMÉS exacts** @ 3ce18f767
- Sondage 10/10 anchors du plan wizard 2026-06-08 : tiennent encore à ce HEAD
- 2 FAUX corrigés : `GATE-G-…LOCK-REQUEST.md` + `ULTRA_AUDIT_VERDICT_2026-06-08.md` absents de cette lignée (vivent sur wizard-exec non mergée) → G-1 WHERE = « à régénérer via lock-plan »
- 3 DÉRIVÉS mineurs corrigés : `ItemCategory.php:159-193`, `choices:75-170`

### Agent 2 — Architecture/contrats (0 P0, 5 P1, 3 P2 — tous intégrés)
- P1-1 `allow_repeat` non éditable dans `ComposerStepFormPanel.vue` → T-W3.3 ajouté
- P1-2 aucun endpoint suppression de profil wizard (+ deadlock catégorie↔profil) → T-W5b + détachement dans dialog C1.2
- P1-3 `ItemCategoryService::destroy:165-183` = `SET FOREIGN_KEY_CHECKS=0` → T-C1.2 recentré sur le service + régression no-FK-toggle
- P1-4 héritage wizard parent→sous-catégorie indéfini → gate G-0c (défaut : NON + warning UI)
- P1-5 borne projette les catégories à plat, `parent_id` jamais émis → T-C1.4
- P2 : SYNC_CONTRACT §3 ordre-only (vrai contrat = EventServiceProvider:221-291) → T-C1.5 + tripwire payload ; ordre corrigé C1 avant S2 ; T-S2.1 EXTEND-only

### Agent 3 — Faisabilité (4 BLOCKERS infra + 6 corrections)
- B1-B4 : worktree sans vendor/node_modules/.env*/DEVDB-GUARD → Wave 0 bootstrap obligatoire (intégré §0.1)
- Sentinel Vitest stock = source-string (pas comportemental) → T-S2.2 réécrit ; acceptances re-précisées (tests/Feature/Availability = 1 seul fichier)
- RBAC : catégories = `permission:settings` (pas items_*) → décision Wave 0 ; i18n T-S2.4 ; throttle/bulk T-S2.3
- Bonus : fix orphelin publish-confirm DÉJÀ présent @ 3ce18f767 (`ProductComposerEditorComponent.vue:955`)

Agents : ac4f14b3126d3cedd / a4de2de89d1f82a89 / a012e706da69e078b
