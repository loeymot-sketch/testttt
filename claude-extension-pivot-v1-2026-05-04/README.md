# Pivot V1 FoodKing — Brief Claude Extension

**Créé le** : 2026-05-04
**Auteur** : Cursor Claude (orchestrateur)
**But** : obtenir un Ultra Audit + Ultra Plan + Ultra Review d'une refonte V1 majeure du Dashboard FoodKing.

## Le pivot en 1 phrase

> Sortir une V1 simple et rapide en supprimant la personnalisation wizard per-item (mise en V2 sous bouton "Demo"), en faisant hériter automatiquement le wizard de la catégorie, et en introduisant un concept unifié "Ingrédient" (viande/sauce/supplément/crudité) avec rupture qui se propage automatiquement aux produits.

## Les 3 fichiers de ce dossier

| Fichier | Rôle |
|---|---|
| `MESSAGE-A-COLLER.md` | Le prompt complet à coller dans Claude (extension Anthropic, mode thinking max). |
| `LISTE-FICHIERS.md` | Liste précise des fichiers du repo à attacher en pièce jointe (priorisée Tier 1/2/3). |
| `README.md` | Ce fichier — explication du dossier. |

## Mode d'emploi (3 étapes)

### Étape 1 — Ouvre Claude
- Extension Claude (Anthropic) dans Cursor ou navigateur.
- Modèle : **Opus 4.7** (ou meilleur disponible) avec **thinking étendu / reasoning high**.

### Étape 2 — Colle le message + attache les fichiers
- Colle l'intégralité de `MESSAGE-A-COLLER.md`.
- Attache **a minima** les fichiers Tier 1 de `LISTE-FICHIERS.md`.
- Si la limite upload le permet, ajoute Tier 2 puis Tier 3.

### Étape 3 — Reçois le livrable
Claude produira :
1. **AUDIT** : décisions garde/masque/refait par composant.
2. **PLAN** : séquence de cycles bornés (TASK_ID, scope, dépendances, gates).
3. **ULTRA REVIEW** : auto-challenge, risques, top 3 questions humaines.

**Ne lance PAS l'exécution directement** — reviens d'abord vers Cursor Claude pour valider/ajuster.

## Suppression du dossier

Une fois le brief envoyé à Claude et le livrable reçu, ce dossier peut être supprimé (comme on l'a fait pour `devis-claude-design-catalog-studio/` dans les cycles précédents). Garde juste le livrable Claude dans `audit-claude-pivot-v1-2026-05-04/` ou similaire pour traçabilité.
