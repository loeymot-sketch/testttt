# Validation proxy Anthropic (ctok) — 2026-04-24

- **Base URL** : `https://api.ctok.ai` (appel `POST /v1/messages`, header `x-api-key` + `anthropic-version`)
- **Verdict** : **GO_CHAIN_50K_TOTAL** — cumul **input+output** (champs `usage` API) ≥ **50 000** sur l’enchaînement 1er audit + tours (voir JSON `chained_total` = **53983**, cible 50000).
- **Modèle utilisé (ping / long / chaîne)** : `claude-sonnet-4-5-20250929`  
  - **Claude Opus 4.1 / 3 Opus** : **HTTP 503** — `No available accounts` (indisponible sur ce compte / ce proxy au moment du test). **Opus 4.7** n’apparaît pas dans le script — à demander en alias côté ctok s’il existe.
- **Audit long (1er appel)** : input **1024** | output **6652** | `max_tokens` demandé **50000** (le modèle s’est arrêté avant, `stop_reason` typiquement `end_turn`).
- **Sortie seule** : **moins de 50k tokens** en un seul `messages` — l’objectif “50k+ tokens” a été atteint en **sens cumulé multi-appels** (in+out), pas en un bloc unique.
- **Timeout** : le script utilise désormais `https` + `CTOK_FETCH_TIMEOUT_MS` (défaut 15 min) pour éviter le **HeadersTimeout** du `fetch` undici sur les générations longues.

## Go / No-Go orchestration (projet)

- **GO** (technique) : le proxy `api.ctok.ai` accepte la clé, l’API Messages renvoie des `usage` cohérents, exécution longue (≈16–17 min pour ce run) **sans** coupure côté client.
- **No-Go ciblé (Opus)** : tant que **Opus** renvoie **503**, ne pas placer l’orchestrateur en **dépendance** sur Opus 4.1/3 en primaire — utiliser le modèle **ping OK** (ici Sonnet 4.5) ou monter du **crédit / capacité** côté ctok pour des comptes Opus.
- **Dashboard** : comparer consommation facturée / quotas aux champs `usage` du JSON (y compris `cache_*` si facturation au cache).

## Sécurité

- Clé : ne pas la committer ; **rotation** recommandée (exposition possible dans l’historique de chat). Utiliser `/.env.anthropic.local` (gitignored) uniquement en local.

JSON machine : `reports/audit/CTOK_ANTHROPIC_VALIDATE_2026-04-24T15-39-46-699Z.json`
