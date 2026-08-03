# Codex / OpenAI — erreur `401` `api.responses.write` sur `/v1/responses`

> **Hors code applicatif** : c’est l’**API** OpenAI qui refuse. Le dépôt FoodKing **ne gère** pas d’*API key* de production pour le flux `codex login`.

## Message typique

`Missing scopes: api.responses.write` — `POST https://api.openai.com/v1/responses`

## Causes (ordre fréquent)

1. **Clé `sk-` restreinte** injectée (Cursor, shell, `.env` / `.env.codex`, variable système) sans la portée **Responses**.  
2. **Rôles** sur [platform.openai.com](https://platform.openai.com) : *Reader* insuffisant pour certaines opérations ; vérifier **rôle org** et **rôle sur le projet** (souvent **Owner** ou **Writer** requis côté usage API / projet *Default*).  
3. **Panneau Codex dans Cursor** : s’il force une clé *Platform* incompatible, retirer l’override (Settings) et/ou se reconnecter avec le flux supporté (ChatGPT / compte lié) après nettoyage.

## Côté dépôt (aide, pas de secret)

- `npm run codex:audit-bleed` — signale hôtes / présence de clés (sans afficher de valeur).  
- `scripts/codex-sanitize-env-for-codex-cli.sh` (sourcé par le wrapper) — *unset* des clés/URLs d’héritage **pour** `codex exec` lancé par le dépôt. **Ne** modifie **pas** l’environnement global de Cursor.  
- `~/.codex/config.toml` — hôte (ex. `https://api.openai.com/v1`) et `wire_api` ; géré **hors git** sur ta machine.

## Pistes côté OpenAI

- Régénérer une clé **sans** restreintions incompatibles **ou** activer les bons *scopes* si tu utilises le mode clé.  
- Vérifier l’**organisation** / **projet** associés au compte utilisé par l’**app** qui appelle l’API.

Mise à jour procédurale : `docs/orchestration/EXPORT_CONFIG_CODEX_GPT_API_ET_AUDIT_PERSISTANCE.md` ; orchestration : `docs/orchestration/CODEX_API_DELEGATION.md` ; rappel pour le terminal : `agents/codex-extension-instructions.md`.
