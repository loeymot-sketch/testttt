# Exécution : audit global profond + preuve de consommation (tokens) — 2026-04-24

## Objectif

Valider que le proxy (tokenclub) supporte des **sessions productives** avec **GPT-5.5** (préférence), **`reasoning` effort élevé (xhigh → high JSON)**, un **gros contexte** (dépôt), et des **générations longues** — en sortant d’abord de la fausse impression « tout fait 10 tokens » (typique des *smoke* “OK” en 2 s).

## Constat (pourquoi « moins de 10 tokens » dans le passé)

- Les requêtes **minimales** (`Reply with exactly: OK`) n’entraînent quasiment **aucune génération** utile côté **complétion** : le modèle s’arrête sur `OK`.
- L’**usage** affiché côté **IDE Cursor** n’est **pas** le `usage` d’un seul `POST` vers le proxy.
- Le **stream** SSE ne remonte parfois **pas** d’objet `usage` dans chaque mélange (selon le fournisseur) : il faut un **one-shot** pour une mesure fiable, ou se fier à la **longueur** de la réponse + au temps.

## Configuration retenue (fonctionne sur ce dépôt)

| Paramètre | Valeur retenue |
|-----------|----------------|
| `CODEX_API_BASE` | `https://subtp7eu3nc8.tokenclub.top/v1` |
| `CODEX_MODEL_COMPLEX` | **`gpt-5.5`** (mise à jour de `.env.codex` locale) |
| `CODEX_REASONING_EFFORT` | **`xhigh`** (mappé en `reasoning: { effort: "high" }` dans le JSON) |
| Fil génération longue | **`POST /v1/chat/completions`** avec **`stream: true`**, `max_completion_tokens` élevé (ex. 48k) + **undici** (timeouts corps entre morceaux SSE) |
| Fil `codex:complex` (défaut code) | `CODEX_WIRE=responses` → `/responses` (one-shot, pas de SSE) ; **pour audits kilométriques** utiliser l’**audit probe** (chat+stream) ou forcer `CODEX_WIRE=chat` sur le runner |

## Résultat A — Audit « zèle » (stream, contexte ~30k car.)

- **Commande** : `npm run codex:deep-audit-probe`
- **Script** : `scripts/codex-deep-audit-probe.mjs`
- **Modèle gagnant (1er essai)** : `gpt-5.5`
- **HTTP** : 200
- **Durée** : ~**175 s** (session longue, pas un micro-fumet)
- **Texte généré** : **~16 054** caractères d’audit structuré (18 sections cibles, contenu riche, extrait tronqué en rapport JSON)
- **`usage` en stream** : *non renvoyé* (null) sur ce fournisseur — **normal côté stream** fréquemment
- **Conclusion** : le proxy **a bien produit** une longue session et un volume de texte massif; la seule **preuve** numérique fournisseur sans `usage` en stream est ici la **longueur** + le **chrono**.

**Artefacts** : `reports/audit/DEEP_AUDIT_PROBE_2026-04-24T11-23-34-310Z.json`, `reports/audit/AUDIT_DEEP_TOKEN_PROOF_2026-04-24.md`

## Résultat B — Mesure `usage` explicite (one-shot, non-stream)

- **Commande** : `CODEX_MODEL_COMPLEX=gpt-5.5 node scripts/codex-oneshot-usage.mjs`
- **Réponse API** (extrait) :

```json
{
  "http": 200,
  "usage": {
    "prompt_tokens": 57,
    "completion_tokens": 1012,
    "total_tokens": 1069
  },
  "content_len": 4749
}
```

- Interprétation : pour une tâche **moyennement** longue, le fournisseur compte **~1k+ tokens de complétion**; ce n’est **pas** du « 10 tokens ». Les grosses tâches (A) sont **bien** au-dessus en volume, même si le compteur exact est absent en stream.

## Résultat C — Même jauge avec `gpt-5.4` (référence)

Avec le même one-shot, `gpt-5.4` a renvoyé (ordre de grandeur identique) :

- `completion_tokens` : **1132**, `total_tokens` : **1189** (exécution de contrôle, pas d’inscription obligatoire : **5.5** est validé en session longue A).

## Recommandations d’orchestration (implémentations lourdes futures)

1. **Préférer `gpt-5.5`** + **`CODEX_REASONING_EFFORT=xhigh`** sur ce proxy.
2. **Gros livrables** : **chat + stream** (comme le probe) ; en cas d’**échec stream**, **one-shot** accepte l’`usage` mais risque de **504** sur très gros prompts non stream.
3. **`CODEX_LOG_USAGE=1`** sur le runner pour journaliser l’`usage` quand le JSON de fin le contient.
4. **Ne jamais** conclure sur la conso d’API sur la base d’un `codex:smoke` de deux lettres : utiliser `codex:deep-audit-probe` ou `codex:audit-limits` avec `AUDIT_STRESS=1`.

## Limites

- Aucun **droit** d’invoiter le dashboard fournisseur ici : les nombres viennent des **retours `usage`** (one-shot) + **envergure** (stream long).
- La demande d’« une boucle de 10 h » a été adressée par une **probabilité de succès** : **1er modèle = succès** sur `gpt-5.5` ; pas d’heures de retry nécessaires **sur cet environnement** — le script, lui, supporte d’**autres** modèles listés (5.4, 5.5-high, 5.5-pro) si 5.5 avait échoué.

## Fichiers ajoutés / utiles

- `scripts/codex-deep-audit-probe.mjs` — boucle modèles + audit massif
- `scripts/codex-oneshot-usage.mjs` — preuve de `usage` en non-stream
- `npm run codex:deep-audit-probe` (voir `package.json`)

---

**Fin du rapport d’exécution (terminal + API, pas uniquement l’UI Cursor).**
