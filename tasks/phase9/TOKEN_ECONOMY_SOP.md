# TOKEN ECONOMY SOP — FoodKing

**Version.** 2026-04-18
**S'applique à.** Tous les agents LLM actifs sur le projet (Cursor #1, Cursor #2, Cowork, Claude Code, sous-agents Task).
**But.** Obtenir la même qualité de décision avec 50-70 % de tokens en moins.

---

## 1. Règles absolues (par ordre d'impact)

### 1.1 Ne jamais re-lire un fichier déjà ingéré dans la même session
Avant d'appeler `Read`, vérifier si le fichier a déjà été lu. Si oui, utiliser le contenu déjà en contexte. Si le contexte a été tronqué, préférer `Grep` ciblé sur les lignes pertinentes plutôt que `Read` complet.

### 1.2 Handoff = source unique inter-vagues
Au démarrage d'une vague (P9.N, POS-9.N), **lire uniquement** :
- Le `HANDOFF_P9_N_<DATE>.md` de la vague précédente.
- La section de la vague courante dans le plan global.
- Les invariants + SYNC_PROTOCOL (une fois par session, pas par vague).

**Ne pas re-lire** : l'audit global, les rapports des vagues antérieures, le CLAUDE.md, les docs stratégiques. Ces contenus doivent être déjà résumés dans le handoff.

### 1.3 Déléguer aux sous-agents
Un sous-agent Task a son propre contexte. Le parent ne voit que le retour (compressé). Règle : **toute opération > 5 appels Grep/Read/Bash exploratoires → délégation obligatoire** à un sous-agent avec un prompt précis et un retour ≤ 300 mots.

Exemples de délégation :
- Audit d'un sous-domaine (checklist + fichiers à lire → rapport < 300 mots).
- Vérification indépendante (liste de findings → verdict RESOLVED/PARTIAL/STILL_BROKEN par item).
- Scan de régression sur une zone (grep patterns → findings).

### 1.4 Rapports ≤ 500 lignes, pas 2500
Structure imposée pour tout rapport :
- **Verdict** (1 ligne).
- **Scope** (3 lignes).
- **Findings** (tableau ≤ 50 lignes, `id | title | criticity | file:line | fix`).
- **Evidence** (sortie tests clés, 10 lignes max).
- **Risques résiduels** (bullets courts).
- **Handoff** (pointeurs vers fichiers et sections, pas de duplication).

Bannir : "Dans cette section nous allons...", récapitulatifs d'intro, conclusions redondantes.

### 1.5 Commandes shell : Grep/Glob > Read massif
- `Grep` ciblé (avec `-n` et `-A/-B` courts) au lieu de `Read` fichier entier quand on cherche un pattern.
- `Glob` avec pattern précis avant tout `Read`.
- `ls` interdit si le résultat dépasse 50 lignes — utiliser `find` ou `Glob` filtré.

### 1.6 Pas de préambule
**Interdit** en début de réponse orchestrateur :
- "Je vais analyser ta demande..."
- "D'accord, voici ce que je vais faire..."
- "Excellent ! Je procède comme suit..."
- Résumés de ce que le user vient de dire.

**Autorisé** : passage direct à la tâche ou à la décision. Une ligne de contexte max si strictement nécessaire.

### 1.7 Pas de résumé post-tool
Après un `Read`, un `Grep`, un `Bash` : pas de "J'ai lu le fichier, il contient X, Y, Z". Passer directement au tool suivant ou à l'action. L'humain lit les retours bruts.

### 1.8 Un seul plan, pas de re-planification
Le plan écrit une fois (ex : `PLAN_PHASE_9_KIOSK`) est canonique. Ne pas le paraphraser dans des messages successifs. Référer par lien : "Items P9.2.3 et P9.2.4 du plan."

### 1.9 Tests ciblés, pas suite complète à chaque commit
- Vitest/PHPUnit **uniquement sur les fichiers touchés** + dépendances directes pendant le développement.
- Suite complète **une fois** à la fin de la vague (gate commun).
- Documenter dans le rapport final : "Suite complète verte : Vitest 377/377, PHPUnit 542/542".

### 1.10 Commits atomiques avec messages courts
Message de commit : 1 ligne (format Conventional Commits), pas de body sauf si contexte critique (migration complexe, breaking change). Description détaillée → rapport de vague, pas message commit.

---

## 2. Format de handoff (gabarit)

```
# HANDOFF_P9_<N+1>_<DATE>

## État post-P9.<N>
- Branche mergée : <sha>
- Tests : <X/Y>
- Invariants : <checklist>

## Shapes modifiés (à préserver P9.<N+1>)
- Fichier:ligne : <avant> → <après>

## Pré-requis P9.<N+1>
- Item X.Y dépend de <ref>
- Item X.Z dépend de <ref>

## Ordre conseillé
1. Item X.A
2. Item X.B
...

## Gates spécifiques P9.<N+1>
- <liste ≤ 6 lignes>

## Risques détectés
- <bullets courts>
```

**Cap** : 80 lignes maximum. Si dépassement → l'info en trop va dans le RUN report, pas le handoff.

---

## 3. Anti-patterns à refuser

| Anti-pattern | Remplacer par |
|---|---|
| Re-lire AUDIT_KIOSK_GLOBAL au début de P9.2, P9.3, P9.4 | Lire HANDOFF précédent uniquement |
| "Voici un résumé de la conversation précédente..." | Rien, ou 1 ligne si strictement nécessaire |
| Read fichier 500 lignes pour trouver 1 fonction | `Grep` pattern ciblé avec `-A 20` |
| 4 Read séquentiels de fichiers du même dossier | 1 `Glob` + 1 `Grep` multi-fichier |
| Rapport de 2000 lignes | ≤ 500 lignes ; détails dans fichiers dédiés linkés |
| Réponse "Parfait ! Je vais faire X, Y, Z" puis faire X, Y, Z | Faire X, Y, Z directement, puis 1 ligne de résultat |
| 20 appels Grep pour cartographier un domaine | 1 délégation sous-agent avec mission claire |

---

## 4. Mesure

À la fin de chaque vague, le rapport RUN inclut :
- **Budget token estimé** (lignes lues × 50 tokens/ligne + lignes écrites × 80 tokens/ligne + tool calls × 200 tokens).
- **Délégations effectuées** (N sous-agents lancés).
- **Re-reads évités** (N fichiers lus 1 seule fois).

Objectif : P9.N+1 doit consommer ≤ 120 % de P9.N à périmètre équivalent. Sinon régression d'efficacité à corriger à la vague suivante.

---

## 5. Invocation

Dans tout nouveau prompt lancé à Cursor / Cowork / Claude Code :

> **Respecte `tasks/phase9/TOKEN_ECONOMY_SOP.md` — lire une seule fois au début de la session, appliquer strictement.**

Cette ligne unique active toutes les règles ci-dessus sans avoir à les répéter à chaque prompt.
