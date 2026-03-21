# PLAN DIRECTEUR MVP — FoodKing POS + KDS + Borne

**Architecte :** Claude (Lead)  
**Date :** 16 Mars 2026  
**Statut :** SPRINT 24 — Finalisation POS : Migrations + Build + Crudités Atomiques

---

## Dernier Sprint Actif

**[📋 Sprint 24 — Finalisation POS : Migrations + Build + Crudités Atomiques](./sprint_24_finalisation.md)**  
**Statut:** PARTIELLEMENT COMPLETED — Actions manuelles requises (K1/K3/K4 bloqués par sandbox)  
**Agent d'implémentation:** Kimi  
**Verdict:** Attente exécution manuelle des commandes shell

### Tâches Sprint 24

| ID | Description | Statut | Bloquant |
|----|-------------|--------|----------|
| K1 | Exécuter migrations `2026_03_16_000001` et `000002` | ⏳ PENDING | Sandbox DB |
| K2 | Fix migration 000002 (robustesse `$this->command`) | ✅ COMPLETED | — |
| K3 | Vérifier/re-seeder crudités atomiques | ⏳ PENDING | Sandbox DB |
| K4 | Build Vue `npm run dev` | ⏳ PENDING | Sandbox npm |
| K5 | Créer rapports workflow (planning/execution) | ✅ COMPLETED | — |

---

## Historique des Sprints (validés)

| Sprint | Contenu | Statut |
|--------|---------|--------|
| Sprint 14 | Authorization roles, N+1 queries, IDOR fixes, pos-wizard refactor | ✅ COMPLETED |
| Sprint 15 | Wizard pain/boisson sync, manual discount logic, category seeding | ✅ COMPLETED |
| Sprint 16 | POS UI bugs, pos-wizard data scope fixes | ✅ COMPLETED |
| Sprint 17 | ReferenceError fix, N+1 queries in pos/table order store | ✅ COMPLETED |
| Sprint 18 | Nullish coalescing operator for menu addon prices | ✅ COMPLETED |
| Sprint 19 | Wizard logic: boisson lookup, supplements_menu, hasFrites, sauce frites, parseInt NaN | ✅ COMPLETED |
| Sprint 20 | KDS instruction gaps: SANS garnitures, sauceSingle, accompagnement | ✅ COMPLETED |
| Sprint 21 | Wizard logic: sauces, frites, supplements (S21-1/2/3/6) | ✅ COMPLETED |
| Sprint 22 | Safety Lock: Sync & Pricing Integrity (S22-1/2/3/4) | ✅ COMPLETED |
| Sprint 23 | Wizard UX fixes : crudités atomiques, validation navigation, prix DB (S23-P1/P2/P3/P4) | ✅ COMPLETED |
| Sprint 24 | Finalisation : migrations + build + rapports | 🔄 PARTIELLEMENT COMPLETED |

---

## Point d'entrée pour agents

**Pour Kimi (implémentation):**
1. Lire ce fichier (`latest.md`)
2. Lire le sprint assigné (`sprint_22_plan.md`)
3. Implémenter UNIQUEMENT les tâches du sprint
4. Exécuter les tests spécifiés
5. Écrire le résumé dans `reports/execution/latest.md`

**Pour Anti-Gravity (QA):**
1. Lire ce fichier (`latest.md`)
2. Lire le sprint en cours (`sprint_22_plan.md`)
3. Exécuter le test checklist du sprint
4. Rédiger le rapport dans `reports/antigravity/latest.md`

---

## Règles de coordination

- **Claude** = architecture, raisonnement, debugging, planification
- **Kimi** = implémentation localisée, UI, CRUD, patches limités
- **Anti-Gravity** = QA, tests E2E, rapports de validation
- **Humain** = décision finale, validation staging

---

## Sécurité et gels

### Modules gelés (ne pas toucher sans instruction explicite)
- Stripe/PayPal payment gateway
- Delivery Boy module
- Analytics/Reporting
- ValidStatusTransition (règles métier critiques)

### Vérification obligatoire avant DONE
```bash
php artisan test
# Doit retourner 0 failures, 0 errors
```
