#!/usr/bin/env bash
# Appelé avant chaque `codex exec` (PRIMARY) pour éviter qu’un
# OPENAI_BASE_URL / CODEX_API_BASE hérité (Cursor, .env, profil shell)
# n’envoie le trafic vers un relais tiers (401 INVALID_API_KEY).
#
# Le flux documenté = ChatGPT / Pro via le binaire officiel → api.openai.com.
# Uniquement pour le binaire @openai/codex (PRIMARY du dépôt)
# (n’invoque pas ce script).
#
# Usage (bash):
#   source "$REPO_ROOT/scripts/codex-sanitize-env-for-codex-cli.sh"
#   codex_sanitize_openai_routing_env
# -----------------------------------------------------------------------------
codex_sanitize_openai_routing_env() {
  # Routing HTTP — à retirer pour revenir à l’hôte OpenAI + session codex login
  unset OPENAI_BASE_URL
  unset OPENAI_API_URL
  unset OPENAI_API_BASE
  unset CODEX_API_BASE
  # Clé : une clé restreinte (sans *responses* / chat requis) provoque 401 côté /v1/responses
  # le PRIMARY doc = OAuth ChatGPT, pas de sk-* dans l’environnement pour ce binaire
  unset OPENAI_API_KEY
  unset CODEX_API_KEY
  unset AISTUDIO_API_KEY
  unset GOOGLE_GEMINI_BASE_URL
  # Azure (si jamais injecté, évite de mélanger avec le CLI openai)
  unset AZURE_OPENAI_ENDPOINT
  unset AZURE_OPENAI_KEY
  unset AZURE_OPENAI_API_KEY
}
