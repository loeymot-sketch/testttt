# Matrice — routine vs complexe (FoodKing)

> **SSOT** : `.cursor/routing.md` (règles complètes). Ce document est un **aide-mémoire 1 page** pour router avant `EXECUTE`.

| Signal dans le plan / tâche | `foodking-routine-implementer` (Composer) | `foodking-complex-implementer` (GPT-5.4) |
|--------------------------------|---------------------------------------------|------------------------------------------|
| Fichier **frozen** (ex. `OrderService`, `FrontendOrderService`, `PaymentService`, `Pricing/`, reçu NF525) | Non — **gate** + exécuteur complexe seulement | Oui, si gate / LOCK explicite |
| **Migration / DDL** | **Non** (jamais) | Oui si au périmètre `SUBSYSTEMS_TOUCHED` + gates |
| **auth / `branch_id` / dispatch** sync sensible | Non | Oui |
| i18n, **copy** UI, config, **docs** `reports/`, scaff non-DDL | Oui | Évite si non trivial |
| **Lecture** seule (`explore`) | N/A (readonly) | N/A (readonly) — pas d’implémentation produit |
| `PRIOR_CONTEXT` (mémoire / prior findings) | Obligatoire si hors trivial | Toujours pour sync/fiscal/orders |

**Rappel** : l’orchestrateur (Claude dans Cursor) reste l’**unique** rôle **PLAN / AUDIT / GATE** ; le terminal `claude` (Claude Code) est un **allié optionnel** (voir `TERMINAL_CLAUDE_GRAPHITI_ORCHESTRATION.md`).

**Décision rapide** : si *une seule* ligne de la colonne de gauche coche « complexe » sur ton périmètre `app/` → **GPT-5.4** ; sinon → **Composer**.
