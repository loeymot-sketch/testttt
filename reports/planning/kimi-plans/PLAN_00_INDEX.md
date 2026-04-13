# 📋 INDEX — Plans d'Implémentation KIMI2.5
# Projet : FoodKing LeCayenne
# Architecte : Claude (Antigravity)
# Builder : KIMI 2.5
# Date : 12 Mars 2026

---

## 🎯 Mission de cet Index

Ce dossier contient **12 plans d'implémentation structurés**, issus du rapport maître d'audit
`RAPPORT_FINAL_AUDIT_CAISSE_BORNE_20260310.md`. Chaque plan est autonome, prêt à être
exécuté par KIMI2.5 sans nécessiter de contexte extérieur.

### Règles à suivre (AGENTS.md)
- **KIMI** lit `reports/review/bugbot-latest.md` AVANT d'implémenter
- Chaque plan précise `Test-Type: local-validation | Playwright / E2E verification | No-test`
- Exécution → résumé dans `reports/execution/latest.md`
- **Ne pas dévier du plan** sans validation Claude

---

## 📂 Plans par Priorité

### PHASE P0 — CRITIQUE (Sécurité — À faire IMMÉDIATEMENT)
| Fichier | ID | Tâche | Test |
|---------|-----|-------|------|
| [PLAN_01_SECURITY_PRICE_FALLBACK.md](./PLAN_01_SECURITY_PRICE_FALLBACK.md) | D-001 | Rejeter items inexistants (prix fallback) | local-validation |
| [PLAN_02_SECURITY_POS_PRICE_DB.md](./PLAN_02_SECURITY_POS_PRICE_DB.md) | D-002 | POS : prix DB pour variations/extras | local-validation |
| [PLAN_03_SECURITY_VALID_JSON_ORDER.md](./PLAN_03_SECURITY_VALID_JSON_ORDER.md) | D-004 | ValidJsonOrder : rejeter sans item_id | local-validation |

### PHASE P1 — HAUTE (Stabilité)
| Fichier | ID | Tâche | Test |
|---------|-----|-------|------|
| [PLAN_04_FIX_PAYMENT_BLADE_NULL.md](./PLAN_04_FIX_PAYMENT_BLADE_NULL.md) | MA-001+MA-002 | Null-safe payment.blade + SettingResource | local-validation |
| [PLAN_05_FIX_POS_TESTS.md](./PLAN_05_FIX_POS_TESTS.md) | D-005+A-002 | Corriger POSComprehensiveTest (6→8/8) | local-validation |
| [PLAN_06_KDS_INSTRUCTION_PARSING.md](./PLAN_06_KDS_INSTRUCTION_PARSING.md) | D-010 | KDS : parser et afficher sections instructions | Playwright / E2E verification |

### PHASE P2 — MOYENNE (UX/Fonctionnel)
| Fichier | ID | Tâche | Test |
|---------|-----|-------|------|
| [PLAN_07_UX_WIZARD_PROGRESS_BAR.md](./PLAN_07_UX_WIZARD_PROGRESS_BAR.md) | UX-03 | Barre progression wizard POS | Playwright / E2E verification |
| [PLAN_08_UX_TOKEN_ALIGNMENT.md](./PLAN_08_UX_TOKEN_ALIGNMENT.md) | D-007 | Aligner validation token POS frontend/backend | local-validation |
| [PLAN_09_UX_DINE_IN.md](./PLAN_09_UX_DINE_IN.md) | D-008 | Dine-In : message ou réactivation par config | No-test |
| [PLAN_10_KIOSK_CONFIRMATION.md](./PLAN_10_KIOSK_CONFIRMATION.md) | D-011 | Kiosk : confirmation + idle warning | Playwright / E2E verification |

### PHASE P3 — ARCHITECTURE (Évolution long terme)
| Fichier | ID | Tâche | Test |
|---------|-----|-------|------|
| [PLAN_11_ARCH_CATEGORY_CONFIG.md](./PLAN_11_ARCH_CATEGORY_CONFIG.md) | ARCH-01 | Enrichir ItemCategory : wizard_template, has_menu | local-validation |
| [PLAN_12_ARCH_WIZARD_DB_DRIVEN.md](./PLAN_12_ARCH_WIZARD_DB_DRIVEN.md) | ARCH-02 | Wizard piloté par DB (pas hardcodé) | Playwright / E2E verification |

---

## ⚡ Ordre d'exécution recommandé

```
1. PLAN_01 → PLAN_02 → PLAN_03  (sécurité, pas d'UI)
2. PLAN_04 → PLAN_05            (stabilité backend)
3. PLAN_06                      (KDS, test Playwright / E2E verification)
4. PLAN_07 → PLAN_08 → PLAN_09  (UX POS)
5. PLAN_10                      (Kiosk)
6. PLAN_11 → PLAN_12            (architecture — dernier car le plus impactant)
```

---

## 📁 Structure des fichiers de plan

Chaque plan suit le format standardisé :
```
# PLAN_XX — [Titre]
## 1. Contexte & Problème
## 2. Fichiers à modifier
## 3. Implémentation (étapes précises avec code)
## 4. Tests
## 5. Critères de succès
## 6. Ne PAS toucher
```
