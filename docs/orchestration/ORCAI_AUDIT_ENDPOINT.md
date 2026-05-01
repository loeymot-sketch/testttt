# Endpoint tiers type Orcai / Anthropic-compatible — audit sans modifier le dépôt

## Sécurité (obligatoire)

- **Ne jamais committer** de clés : utiliser **`.env.orcai`** (gitignored), copié depuis **`.env.orcai.example`**.
- Toute clé déjà collée dans un **chat** ou un ticket doit être **révoquée / régénérée** côté fournisseur.
- Ce dépôt ne **valide pas** la conformité légale ou les CGU d’un proxy tiers ; l’usage est sous ta responsabilité.

## Limite tokens : pas d’« illimité », mais quota cumulé élevé

- Les APIs type **Anthropic Messages** imposent un **`max_tokens`** (sortie) **par requête**, avec un **plafond** côté modèle **et** côté **proxy** (Orcai ou autre). Il n’existe pas de sortie réellement illimitée en une requête.
- Une **seule** réponse texte **> ~100 k tokens de sortie** est rare ; les plafonds effectifs sont souvent plus bas selon l’offre.
- Pour viser **≈100 k tokens cumulés** (entrée + sortie sur plusieurs tours), utiliser :
  - `python3 scripts/run-super-audit-orcai.py --ultra`
  - Variables : `ORCAI_SUPER_AUDIT_MIN_TOTAL_TOKENS` (défaut `100000`), `ORCAI_SUPER_AUDIT_MAX_TOKENS` (`0` ou `unlimited` = plafond élevé via `ORCAI_SUPER_AUDIT_MAX_TOKENS_DEFAULT`, borné par `ORCAI_SUPER_AUDIT_MAX_TOKENS_CAP`), `ORCAI_OUTPUT_EFFORT=max` si le proxy supporte `output_config` (sinon repli automatique).
- Pour un audit massif sans explosion de contexte : plusieurs passes **thématiques** ou **ULTRA multi-tours** (script) plutôt qu’une unique réponse géante.

### Pourquoi tu vois souvent ~**8k tokens en entrée** (`input_tokens`)

- Certains proxys **Anthropic-compat** (facturation / tableau de bord) exposent un **`input_tokens` plafonné ou sous-compté** (souvent autour de **8k**), alors que le corps de requête réel (prompt + historique) fait **beaucoup plus** — surtout en multi-tour (`--ultra`).
- Ce n’est **pas** forcément « le modèle ne lit que 8k » : le véritable contexte dépend du fournisseur ; en cas de doute, **contacter Orcai** pour la fenêtre de contexte réelle et la politique de metering.
- Le script **`run-super-audit-orcai.py`** enregistre **`prompt_chars`** et, si `input_tokens` API est incohérent avec la taille du prompt, utilise une **estimation** (caractères ÷ 4) pour les **totaux cumulés** dans le rapport.

## Fichiers utiles dans ce dépôt

| Fichier | Rôle |
|---------|------|
| `.env.orcai.example` | Modèle de variables (sans secrets). |
| `opencode.config.example.json` | Modèle OpenCode — copier vers `opencode.local.json` (gitignored) si besoin. |
| `scripts/test-orcai-minimal.sh` | Test connectivité **minimal** (`max_tokens` court par défaut). |
| `scripts/run-super-audit-orcai.py` | Super-audit Opus (`--continue`, `--ultra` = quota cumulé configurable). |
| `scripts/run-messages-api-audit.mjs` | **Même API** `POST /v1/messages` que le proxy (Orcai, etc.) **sans** le CLI `claude` — utile si le **terminal** est bloqué par limite d’abonnement mais la **clé API** fonctionne encore. Si le prompt exige les trois lignes `MASTER_AUDIT_VERDICT` / `SOFTWARE_DECISION` / `NEXT_CODEX_MISSION` et que le document ne se termine pas par elles au tour 1, un **tour 2** automatique complète le livrable (désactiver avec `AUDIT_API_CLOSEOUT_SECOND_TURN=0`). Exit code **3** si le closeout reste absent après tour 2. |
| `scripts/validate-master-audit-closeout.mjs` | Vérifie que les trois dernières lignes non vides d’un fichier `.md` sont le bloc contractuel ; exit 0 / 1 (CI / pré-commit). |
| `scripts/assemble-mega-api-context-va-sys.mjs` | Assemble **un seul** fichier « parrain » : `memory/INDEX.md` + extraits JSONL (secours Graphiti), `AGENTS.md`, rapports CODEX VA-SYS, `docs/sync/*`, puis colle l’instruction `_CENTRAL_SYNC_ORCHESTRATION_CLAUDE_AUDIT_PROMPT_2026-04-30.txt`. Sortie par défaut : `reports/audit/_MEGA_API_CONTEXT_VA_SYS_FULL.md`. |
| `scripts/run-messages-api-audit-chain.mjs` | Même API qu’au-dessus, avec **2 tours** automatiques si le prompt dépasse `CHAIN_MAX_USER_CHARS` (défaut 120000 car.) — pour proxys qui limitent la taille du corps ou pour découper volontairement. |

## Méga-contexte + « mémoire » sans MCP dans l’API

- **`POST /v1/messages` n’invoque pas Graphiti** : pas de serveur MCP dans ce flux. Pour injecter de la mémoire, soit tu **interroges Graphiti dans Cursor** puis tu colles le résultat dans le prompt, soit tu t’appuies sur l’assembleur qui embarque `memory/INDEX.md` et des **queues** des JSONL canoniques (`02`, `03`, `12`).
- Le bundle VA-SYS complet fait typiquement **~150–200 k caractères** (ordre de grandeur **~40–50 k tokens** estimés en ÷4) : **un seul message** suffit souvent. Les plafonds « 1M tokens » évoqués en marketing ne garantissent **ni** une seule fenêtre d’entrée **ni** une sortie monolithique : en cas de rejet (413, 400, timeout), utiliser `run-messages-api-audit-chain.mjs` ou plusieurs passes thématiques (`run-super-audit-orcai.py --ultra`).

### Exemple de bout en bout (VA-SYS)

```bash
node scripts/assemble-mega-api-context-va-sys.mjs
# ~151 Ko → un tour :
node scripts/run-messages-api-audit.mjs reports/audit/_MEGA_API_CONTEXT_VA_SYS_FULL.md reports/audit/CLAUDE_VA_SYS_MEGA_API_AUDIT_LATEST.md
# Si le proxy limite la taille du corps :
CHAIN_MAX_USER_CHARS=80000 node scripts/run-messages-api-audit-chain.mjs reports/audit/_MEGA_API_CONTEXT_VA_SYS_FULL.md reports/audit/CLAUDE_VA_SYS_MEGA_API_AUDIT_CHAIN.md
```

Vérifier le bloc contractuel dans un rapport :

```bash
node scripts/validate-master-audit-closeout.mjs reports/audit/CLAUDE_VA_SYS_FULL_API_RETURN_USER_REQUEST.md
```
| `reports/audit/templates/ORCAI_FULL_AUDIT_PROMPT.md` | Prompt d’audit **lecture seule** à coller dans ton client (Claude Code / OpenCode). |

## Test local

```bash
cp .env.orcai.example .env.orcai
# Éditer .env.orcai avec tes valeurs — ne pas committer

chmod +x scripts/test-orcai-minimal.sh
# Optionnel : modèle exact côté Orcai
export ORCAI_MODEL="…"
bash scripts/test-orcai-minimal.sh
```

Pour tester une **sortie longue** (sans garantie de plafond), augmente **temporairement** :

```bash
ORCAI_TEST_MAX_TOKENS=8192 bash scripts/test-orcai-minimal.sh
```

Si tu reçois **400** / message sur **max_tokens**, le **serveur** impose une limite plus basse : baisse la valeur jusqu’à succès pour connaître le plafond effectif.
