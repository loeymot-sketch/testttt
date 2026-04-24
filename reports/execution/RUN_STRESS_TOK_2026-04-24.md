# RUN — Stress « tokens / durée » (Happy + proxy) — 2026-04-24

## Question utilisateur (résumé)

Les sorties vont de ~**300** à **~10,5k** « tokens » visibles, durées **~35 s** en moyenne mais parfois **70–100+ s** ; crédit Happy peu consommé. Est-ce une **limite côté serveur** ou l’**appli** qui rogne l’intelligence / impose des réponses courtes ? Faut-il **multiplier les appels** à 10k tokens chacun ?

## Vérité côté code FoodKing

- **Aucune** logique de « requêtes trop courtes » : le runner envoie le `prompt` tel que construit ; la **conso** = ce que le **modèle + le proxy** génèrent.
- **Pas** de cible « 30 s » côté dépôt (uniquement **reprises** sur erreur réseau, pas d’horloge d’abandon côté client en dur sur la génération).
- Les **durations** (35 s, 100 s) viennent surtout de : **génération** (sortie + raison interne) + **réseau** + file **proxy** — le volume **demandé** dans l’`input` fixe l’**ordre de grandeur**.

## Test massif lancé

- Mission : `missions/STRESS-HAPPY-TOK-001/` — demande explicite **≥ 8000 mots**, 24 sections ≥ 320 mots chacune, sans code.
- Env (runner) : `CODEX_RAW_PROMPT=1` ; `CODEX_MAX_COMPLETION_TOKENS=200000` (plafond *demandé* côté API) ; `CODEX_LOG_USAGE=1` ; `gpt-5.5-high` ; **stream** (défaut).

### Résultat mesuré (réel)

| Métrique | Valeur |
|----------|--------|
| `time -p` (wall) | **~125 s** (`real` ≈ 124,9) |
| Taille fichier | **~32,1 ko** (≈ **4,2k mots** côté `wc`, ligne texte) |
| Ligne finale modèle (estimation) | `MOTS: 3100 \| TOKS_SORTIE_INDICATIF: 4300` |

Le modèle a terminé en expliquant explicitement une **contrainte de sortie** (pas d’invention FoodKing) :

> `**ARRET_LIMITE_MODELE**` — … n’atteint **pas** l’objectif volontaire de 8000 mots…

**Conclusion** : même en demandant 200k **max_completion** tokens au payload, le **fournisseur / le modèle** a imposé un **plafond effectif** sur **une** complétion : la réponse reste de l’ordre de **~4–5k tokens** de génération utile, pas parce que le dépôt « triche », mais parce que la **politique d’exécution** côté API s’y conforme. Le dashboard **Happy** / proxy reflète cela (coût modéré, durées 30–120+ s possibles).

### Usage API (`[codex] usage brut` sur stderr)

- En **stream** sur ce proxy, **aucune** ligne d’`usage` n’est apparue (certaines passerelles n’incluent pas `usage` dans chaque chunk SSE) ; la mesure fiable côté API reste **one-shot** (`CODEX_DISABLE_STREAM=1`) *si* le fournisseur renvoie `usage` (attention **504** sur prompts longs en non-stream derrière un CDN). Le runner tente dès lors d’enregistrer le **dernier** `usage` s’il est émis en SSE (code mis à jour 2026-04-24).

## Alignement avec Cursor (Claude / gros contexte d’entrée)

- C’est **normal** que l’**entrée** (100k+ tokens) soit beaucoup plus grosse qu’**une** sortie (2k–20k) : l’**intelligence** sert à lire, raisonner, cadrer, synthétiser — la **volumétrie** de *réponse* est souvent volontairement contenu, sauf tâches « génère un roman ».
- Ce n’est **pas** l’appli FoodKing qui « rogne le credit » : c’est le **droit d’enchaînement** modèle+proxy+facturation, visible sur ce test massif (objectif 8k mots **non** atteint, arrêt explicite).

## Multi-tâches « ≤ 10k tokens chaque » ?

- Aucun serveur FoodKing ne **découpe** un travail en tranches 10k automatiquement. Pour aller **au-delà d’une** limite **par complétion**, l’orchestrateur (plans, **plusieurs** missions, étapes) doit **enchaîner** des appels — c’est voulu en conception produit (l’**audit** terminal + Graphiti sert de mémoire entre appels), pas un bug du connecteur `codex.runner.mjs`.

---

**Fichier de preuve (sortie)** : `missions/STRESS-HAPPY-TOK-001/output_codex.json` (contient la section `ARRET_LIMITE_MODELE` + la ligne de comptage en fin de document).
