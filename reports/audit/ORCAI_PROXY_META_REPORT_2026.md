# Rapport méta — endpoint tiers & demande « >100 k tokens »

**Date** : 2026 (généré dans le cadre du dépôt FoodKing, sans appel au proxy tiers depuis CI).

## Ce qui a été fait dans le dépôt

- Modèles **sans secrets** : `.env.orcai.example`, `opencode.config.example.json`.
- **Gitignore** : `.env.orcai`, `opencode.local.json`.
- **Doc** : `docs/orchestration/ORCAI_AUDIT_ENDPOINT.md`.
- **Test connectivité** : `scripts/test-orcai-minimal.sh` (requête courte ; monte `ORCAI_TEST_MAX_TOKENS` pour sonder la limite serveur).
- **Prompt d’audit lecture seule** : `reports/audit/templates/ORCAI_FULL_AUDIT_PROMPT.md`.

## Limitation « réponse >100 k tokens »

- Une **sortie unique** de **plus de 100 000 tokens** via une API **Messages** standard est **très improbable** : **plafonds** côté **modèle**, **compte**, et **proxy** (Orcai ou autre).
- Pour mesurer la **limite par requête** : augmenter progressivement `ORCAI_TEST_MAX_TOKENS` jusqu’à erreur HTTP / corps d’erreur indiquant `max_tokens` trop élevé.

## Sécurité

- Les clés **ne doivent pas** être dans le dépôt ni dans un chat public ; **régénérer** toute clé déjà exposée.

## Audit « complet » du code par Opus

- L’agent dans Cursor **ici** ne peut pas substituer ton client Opencode avec tes credentials sans fichier **local non commité**.
- Utilise le template `ORCAI_FULL_AUDIT_PROMPT.md` dans ton environnement où **`ANTHROPIC_*`** pointe vers ton endpoint, avec **`max_tokens` au maximum permis** par le fournisseur.
