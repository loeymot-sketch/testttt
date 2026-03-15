# PLAN DIRECTEUR MVP — FoodKing POS + KDS + Borne

**Architecte :** Claude (Lead)  
**Date :** 10 Mars 2026  
**Statut :** SPRINT 21 — Wizard Logic: Sauces, Frites, Supplements (IMPLEMENTATION PHASE)

---

## Dernier Sprint Actif

**[📋 Sprint 21 — Wizard Logic: Sauces, Frites, Supplements](./sprint_21_plan.md)**  
**Statut:** IMPLEMENTED (en attente validation E2E)  
**Agent d'implémentation:** Kimi  

### Bugs corrigés dans Sprint 21

| ID | Sévérité | Description | Fichier |
|----|----------|-------------|---------|
| S21-1 | CRITICAL | `individualAddons` jamais écrit dans l'instruction KDS | `pos-wizard.js` buildWizardInstruction() |
| S21-2 | HIGH | `addonTotal` non multiplié par `itemQuantity` (prix faux pour qty>1) | `pos-wizard.js` calculateRunningTotal() |
| S21-3 | MEDIUM | Sandwich sans cheddar/grande frites (upsells manquants) | `pos-wizard.js` renderSupplementsMenuStep() |
| S21-6 | LOW | Carte "Boisson Seule" jamais rendue | `pos-wizard.js` renderMenuChoiceStep() |

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
| Sprint 21 | Wizard logic: sauces, frites, supplements (S21-1/2/3/6) | 🔄 IMPLEMENTED |

---

## Point d'entrée pour agents

**Pour Kimi (implémentation):**
1. Lire ce fichier (`latest.md`)
2. Lire le sprint assigné (`sprint_21_plan.md`)
3. Implémenter UNIQUEMENT les tâches du sprint
4. Exécuter les tests spécifiés
5. Écrire le résumé dans `reports/execution/latest.md`

**Pour Anti-Gravity (QA):**
1. Lire ce fichier (`latest.md`)
2. Lire le sprint en cours (`sprint_21_plan.md`)
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
