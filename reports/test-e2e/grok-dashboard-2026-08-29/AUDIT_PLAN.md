# AUDIT PLAN — Grok test-e2e 2026-08-29

Voie GROK / CENTRAL. Frozen kiosk + POS wizard + fiscal = hors vague.
Pas de commandes de test NF525. Pas d'invention de produits.

BASE: `http://127.0.0.1:8766` (APP_URL) proxy → `:8000`.
Logins: `admin@lecayenne.fr` / `123456` · `pos@lecayenne.fr` / `123456`.
Produits réels: Big Burger, Bol Frites, Tacos XL, Galette Classique.

Protocole reviewer: `~/.claude/skills/test-e2e/references/REVIEWER_PROTOCOL.md` (lecture seule).

## Vagues

| Vague | Écrans | Gestes | Interdit |
|---|---|---|---|
| A | Login Admin → Dashboard | tuiles, accès rapides, sidebar | pas POS wizard |
| B | État du système | lecture cockpit, pas bascule interrupteur en prod | |
| C | Catalogue studio | liste, catégorie Sandwichs / Tacos | pas créer de produit fantôme |
| D | Rôles | liste, Chef (lecture) | pas vider un rôle live |
| E | Caissier dashboard + deep-link `/admin/observability/system` | sidebar sans cockpit | pas `/admin/pos` paiement |
| F | Composeur catégorie (si URL ouverte) | lecture wizard catégorie | pas FEATURE_WIZARD flip |

## Round 1 = vérité actuelle (Mix 28/08 stale)
Capturer le mensonge Mix. Puis corriger source + rebuild Mix. Round 2.
