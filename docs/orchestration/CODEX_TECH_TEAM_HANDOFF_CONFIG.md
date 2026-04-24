# FoodKing — Handoff configuration **Codex (proxy OpenAI)** pour équipe technique (diagnostic)

**Usage** : partage ce document **tel quel** (aucun secret dedans) ; pour les vrais secrets, n’exposer que `CODEX_API_BASE` (URL) et **masquer** toute clé (remplacer par `sk-…` tronquée ou `REDACTED`).

**Constat côté client (dépôt FoodKing)** : le connecteur **n’impose** ni plafond **~2 minutes** de durée totale, ni **~10k tokens** de génération — seulement le **champ optionnel** `max_completion_tokens` (jusqu’à 2M par défaut) et le **comportement stream/undici** ci-dessous. Un **2 min / 10k** ressort typiquement du **proxy / bord (CDN) / règles modèle** ou d’une **génération** qui se termine naturellement, pas d’un *hard cap* 2 min en dur dans le runner (voir le code : `agents/codex.runner.mjs`).

---

## 1) Fichiers et ordre de chargement


| Fichier (repo)                  | Rôle                                                                                                |
| ------------------------------- | --------------------------------------------------------------------------------------------------- |
| `.env`                          | Socle Laravel + variables partagées                                                                 |
| `.env.codex`                    | Surcharge Codex (chargé **après** `.env`) : **même clé** que dans `.env` → valeur de `.env.codex` gagne, **sauf** clé **déjà** dans l’environnement du **processus** au lancement (`export …` — non écrasée) — voir `agents/codex-load-env.mjs` |
| `agents/codex.runner.mjs`       | `CODEX_WIRE=responses` (défaut) → `POST` `{BASE}/responses` ; `chat` → `POST` `{BASE}/chat/completions` (stream) |
| `agents/codex.smoke.mjs`        | 1 requête minimale de fumée (même forme, sans mission)                                              |
| `agents/codex.prompt.txt`       | Template (système + tâche) quand `CODEX_RAW_PROMPT` ≠ 1                                             |
| `missions/<TASK_ID>/input.json` | Contenu tâche ; fusion avec contexte optionnel                                                      |


---

## 2) Toutes les variables d’environnement **CODEX_*** (référence)

*Copie d’en-tête* : `agents/codex.env.example` (à jour) — reprise synthétique :


| Variable                                          | Défaut si non défini                                                                 | Rôle                                                                                                                               |
| ------------------------------------------------- | ------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------- |
| `CODEX_API_BASE`                                  | (vide) — **obligatoire** ex. `https://…/v1` (sans `/` final en pratique : normalisé) | OpenAI-compatible base URL                                                                                                         |
| `CODEX_API_KEY`                                   | (vide) — **obligatoire** (ou `OPENAI_API_KEY` même rôle)                             | `Authorization: Bearer`                                                                                                            |
| `CODEX_MODEL_COMPLEX`                             | `gpt-5.4`                                                                            | Nom de modèle côté **proxy** (nom exact affiché par le fournisseur)                                                                |
| `CODEX_WIRE`                                      | `responses`                                                                           | `responses` (défaut) → `POST {BASE}/responses` ; `chat` → `POST {BASE}/chat/completions` (stream)                                   |
| `CODEX_MAX_COMPLETION_TOKENS`                     | (vide) ; sinon surcharge `max_completion_tokens`                                     | Plafond **génération** côté API (coupé à **2M** max dans le code)                                                                  |
| `CODEX_MAX_TOKENS`                                | (vide)                                                                               | Si défini, envoie `**max_tokens`** à la place (compat ancienne API)                                                                |
| `CODEX_DEFAULT_MAX_COMPLETION_TOKENS`             | `2000000`                                                                            | Utilisé si les deux clés du dessus sont vides, pour forger le plafond par défaut (≤2M)                                             |
| `CODEX_NO_DEFAULT_OUTPUT_BUDGET`                  | (vide)                                                                               | `1` = **ne pas** envoyer de `max_completion_tokens` / défaut 2M — corps minimal côté client                                        |
| `CODEX_REASONING_EFFORT`                          | (vide)                                                                               | `low|medium|high|xhigh|…` — fusion : `reasoning: { effort }` (`xhigh` → `high` dans le JSON) **si** l’API le supporte              |
| `CODEX_RAW_PROMPT`                                | (vide)                                                                               | `1` = n’enveloppe **pas** dans `codex.prompt.txt` ; le JSON brut de la mission = prompt utilisateur (normalisations `m` possibles) |
| `CODEX_DISABLE_STREAM`                            | (vide)                                                                               | `1` = forcer le mode **one-shot** (souvent **504** sur requêtes lourderrière passerelle)                                           |
| `CODEX_LOG_USAGE`                                 | (vide)                                                                               | `1` = `usage` (prompt/completion/total) sur **stderr** quand JSON disponible (one-shot / certains fin de stream)                   |
| `CODEX_NO_ONESHOT_FALLBACK`                       | (vide)                                                                               | `1` = en cas d’échec stream, **ne pas** tenter de repli `one-shot` (évite un second gros upload)                                   |
| `CODEX_UNDICI_LONG_STREAM`                        | (vide = actif)                                                                       | Sous **Node 20+**, tente d’enregistrer le dispatcher `node:undici` (timeouts HTTP client) — `0` = désactiver le patch              |
| `CODEX_UNDICI_BODY_TIMEOUT_MS`                    | (défaut côté patch) `0`                                                              | 0 = pas de *idle* de lecture entre **deux** morceaux SSE côté undici, si patch appliqué                                            |
| `CODEX_UNDICI_CONNECT_TIMEOUT_MS` / `…_HEADERS_…` | 600_000 (si patch)                                                                   | Délai connexion / en-têtes (ms)                                                                                                    |
| `RETRY_MAX`                                       | `8` (max 12)                                                                         | Nombre de tentatives sur 502/503/504/429 + « 200 + contenu vide »                                                                  |
| `SLEEP_BASE_MS`                                   | `2000`                                                                               | Backoff exponentiel entre reprises (ms)                                                                                            |
| `CODEX_AUX_CONTEXT_FILES`                         | CSV (graphiti, plan, brief, snapshot)                                                | Fichiers fusionnés en contexte optionnel                                                                                           |
| `CODEX_APPEND_AUX_WITH_RAW`                       | `1`                                                                                  | Avec `RAW=1`, ajouter le bloc de contexte mission si `1`                                                                           |
| `CODEX_NO_NORMALIZE_M`                            | (vide)                                                                               | `1` = ne pas renommer la clé JSON `m` → `instruction` (certaines proxies)                                                          |


*Version Node* : requis pour lire `node:undici` = **Node 20+** (sinon le patch `undici` intégré ne s’applique pas).

*User-Agent client* : `FoodKing-codex-runner/2.2 (Node)` (plus `Content-Type: application/json`, `Authorization: Bearer`).

---

## 3) Corps JSON type envoyé à `POST {CODEX_API_BASE}/chat/completions` (mode par défaut)

**Stream activé (défaut)** — extraits **théoriques** (ordre de clés peut varier) :

```json
{
  "model": "<CODEX_MODEL_COMPLEX ex. gpt-5.4>",
  "messages": [ { "role": "user", "content": "<texte long : prompt+mission+contexte>" } ],
  "stream": true,
  "max_completion_tokens": 2000000
}
```

Fusions possibles (si non vides) :

- `reasoning: { "effort": "high" }` si `CODEX_REASONING_EFFORT` renseigné.  
- Si l’équipe impose uniquement `max_tokens`, utiliser `CODEX_MAX_TOKENS` côté client (remplace la logique `max_completion_tokens` **pour cette clé**).

**Si `CODEX_NO_DEFAULT_OUTPUT_BUDGET=1`**, le bloc `max_completion_tokens` **n’est pas** envoyé par défaut.

**Si `CODEX_DISABLE_STREAM=1`** : pas de `stream: true` ; une seule réponse JSON (risque de **timeout** 504 côté routeur si génération longue).

---

## 4) Ce que l’équipe tech peut vérifier **côté fournisseur / bord de réseau**

1. **Durée (≈2 min max observée)**
  - *Pas* de timer 120 s dans notre `codex.runner.mjs`. Candidats typiques : **idle / body timeout** sur *non-stream* ; **Cloudflare** ou *reverse proxy* `read_timeout` ; **WAF** sur durée d’ouverture ; **quota** côté route.  
  - *Demander* : plafond **idle** / **inactivité** entre blocs **SSE** ; plafond **stream** en secondes.
2. **Volume « ~10k tokens » en sortie**
  - Le client **autorise** (par défaut) **jusqu’à 2M** *tokens de génération possibles* ; la **conso** réelle reste celle de `usage.completion_tokens` (modèle + règles *route*). Vérifier si un **taux** (tokens/min) limite, ou plafond **défaut** sur la route.
3. **Comparer** le corps exact reçu par l’**upstream** (logs fournisseurs) avec l’**échantillon** paragraphe 3 (sans fuite de PII) — toute *réécriture* (strip, max_tokens forcé) serait côté proxy.

---

## 5) Preuves côté client (à coller en ticket)

```bash
# 1) Sans divulguer de clé : vérifier que l’environnement charge le bon .env
node -e "import fs from'fs'; ['.env','.env.codex'].forEach(f=>{if(fs.existsSync(f)) console.log('present:',f);});"
```

```bash
# 2) Smoke (1 requête) : montre le plafond demandé et le modèle répondeur
npm run codex:smoke
# Ligne type : plafond sortie (requête): 2000000
```

```bash
# 3) Avec preuve d’`usage` sur stderr (un-shot pour voir l’objet)
CODEX_LOG_USAGE=1 CODEX_DISABLE_STREAM=1 node agents/codex.runner.mjs PING
```

*Ne pas* envoyer de logs contenant de clés complètes.

---

## 6) Références internes (ne pas les confondre avec l’API proxy)

- **Cursor (IDE)** : autre compteur (session, contexte, *Composer*) — **pas** le `usage` d’un seul `POST` vers le proxy.  
- **Graphiti** : surtout **réduction du contexte** inutile dans *les prompts* côté orchestration — ne fixe **pas** un 10k sur la sortie API.  
- **Boucle E2E** : `npm run boucle:e2e` + journal `reports/execution/BOUCLE_E2E_LAST_RUN.txt`.

**Fin du handoff (configuration client FoodKing, sans secrets).**