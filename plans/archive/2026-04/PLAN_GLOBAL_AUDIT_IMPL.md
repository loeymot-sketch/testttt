# Plan d'Implémentation — Audit Global FoodKing

**Date** : 14 avril 2026
**Auteur** : Claude Opus (Orchestrateur)
**Statut** : READY — en attente d'exécution

---

## Vue d'ensemble

Ce plan couvre l'implémentation des 18 constats identifiés lors de l'audit global d'intelligence FoodKing. Il s'appuie sur les 3 cycles précédents déjà complétés et constitue la feuille de route finale avant mise en production.

### Cycles complétés (socle)
| Task | Statut | Impact |
|------|--------|--------|
| TASK_WIZARD_AUDIT_001 | CLOSED | 10 problèmes wizard corrigés, 191 tests |
| TASK_UX_FLOW_001 | CLOSED | 6 surfaces auditées, Playwright PASS |
| TASK_SYNC_WIZARD_DEEP_001 | CLOSED | Sync + concurrence + 194 tests |

### Nouvelles tâches (ce plan)
| Task | Priorité | Constats | Gate |
|------|----------|----------|------|
| TASK_REALTIME_001 | P0 | F-01, F-05, F-17 | Non |
| TASK_PAYMENT_SAFETY_001 | P0 | F-03, F-04, F-06 | OUI (frozen zone) |
| TASK_KIOSK_RELIABILITY_001 | P1 | F-02, F-08, F-09, F-13 | Non |
| TASK_SECURITY_HARDEN_001 | P1 | F-07, F-11, F-16 | Non |
| TASK_POLISH_001 | P2 | F-10, F-12, F-14, F-15, F-18 | Non |

---

## Graphe de dépendances

```
TASK_REALTIME_001 (P0) ──────────┐
                                  ├──→ TASK_KIOSK_RELIABILITY_001 (P1)
TASK_PAYMENT_SAFETY_001 (P0) ────┤
           (parallèle)           ├──→ TASK_SECURITY_HARDEN_001 (P1)
                                  │
                                  └──→ TASK_POLISH_001 (P2) [parallèle avec P1]
```

## Ordre d'exécution Cursor

### Phase 1 — Critiques (J1-J3)
Exécuter en parallèle si deux instances Cursor disponibles :

1. **TASK_REALTIME_001** — Echo activation, reconnexion WebSocket, fallback polling
   - Fichier tâche : `tasks/TASK_REALTIME_001.md`
   - Gate : Non
   - Validation : Echo connecté, reconnexion < 10s, fallback actif

2. **TASK_PAYMENT_SAFETY_001** — Idempotency TPE, refund loyalty, affichage monnaie
   - Fichier tâche : `tasks/TASK_PAYMENT_SAFETY_001.md`
   - Gate : **OUI** — étape E2 touche OrderService.php (frozen zone)
   - Action humaine requise : approuver la gate avant E2

### Phase 2 — Fiabilité & Sécurité (J3-J5)
Après validation des P0 :

3. **TASK_KIOSK_RELIABILITY_001** — Impression fallback, offline sync, cash drawer, limites
   - Fichier tâche : `tasks/TASK_KIOSK_RELIABILITY_001.md`
   - Dépend de : TASK_REALTIME_001 (broadcast nécessaire pour sync)

4. **TASK_SECURITY_HARDEN_001** — Rate limit, URL validation, permissions
   - Fichier tâche : `tasks/TASK_SECURITY_HARDEN_001.md`
   - Indépendant, parallélisable avec KIOSK

### Phase 3 — Polish (J5-J7)
Parallélisable avec Phase 2 :

5. **TASK_POLISH_001** — Logging, backfill, syntaxe Vue, error boundaries
   - Fichier tâche : `tasks/TASK_POLISH_001.md`
   - Aucune dépendance

### Phase 4 — Validation finale (J7-J8)
- Playwright E2E complet sur les 5 flows critiques
- PHPUnit : 200+ tests attendus, 0 failures
- Audit final consolidé
- **Human gate de production** : validation Kossay sur POS + Kiosk réels

---

## Protocole Cursor pour chaque tâche

Chaque tâche suit le cycle standard :

```
1. Lire ACTIVE_CYCLE.md → confirmer PHASE
2. Lire tasks/TASK_XXX.md → plan d'exécution
3. PLAN → déclarer PRIMARY_MODEL, SUBSYSTEMS_TOUCHED
4. EXECUTE → implémenter étape par étape
5. VALIDATE → php artisan test + npm run build + checks spécifiques
6. AUDIT → vérifier invariants, scope, qualité
7. CLOSE (ou GATE si requise)
```

---

## Risques identifiés

| Risque | Impact | Mitigation |
|--------|--------|------------|
| Echo/Pusher non configuré en .env | P0 bloqué | Vérifier .env avant démarrage cycle |
| Gate OrderService retardée | P0 partiel | Exécuter E1 et E3 de PAYMENT en parallèle |
| Migration wizard_template sur données prod | Perte données | Migration additive uniquement (DEFAULT) |
| Rate limit kiosk trop restrictif | UX dégradée | Configurable via .env, pas hardcodé |

---

## Critères Go/No-Go Production

- [ ] 4 constats CRITICAL résolus et validés
- [ ] 6 constats MAJOR résolus ou mitigés
- [ ] Playwright E2E : Auth + POS Cash + POS Card + Kiosk + KDS → PASS
- [ ] PHPUnit : 0 failures
- [ ] npm run build : 0 errors
- [ ] Validation humaine Kossay sur POS et Kiosk
