# memory/JSONL_SCHEMA.md — Schéma strict des épisodes Graphiti

> Item A17 / M03 méga-checklist — référence canonique pour toute nouvelle ligne JSONL.
> Une ligne invalide = futur fail CI (item E15-bis à venir).

## Format

Chaque ligne est un objet JSON valide UNE LIGNE. Champs :

| Champ | Type | Obligatoire | Description |
|---|---|---|---|
| `name` | string | oui | titre court ≤ 80 chars, orienté facts |
| `source` | enum string | oui | `text` \| `json` \| `message` |
| `source_description` | string | oui | path source(s), séparés par ` + ` |
| `episode_body` | string | oui | contenu narratif (`text`/`message`) ou JSON échappé (`source=json`) |
| `group_id` | string | optionnel | défaut `foodking` |

## Règles

- Encoding **UTF-8** sans BOM, fin de ligne `\n`
- Si `source=json` → `episode_body` doit contenir un JSON **échappé** (guillemets `\"`)
- `name` doit être unique au sein du fichier (recommandé)
- Pas de retour ligne dans `episode_body` (ou bien échappé `\\n`)
- Pas de commentaires (JSON pur)

## Validation

Test rapide ligne valide : `python3 -c "import json; [json.loads(l) for l in open('FILE')]"`
