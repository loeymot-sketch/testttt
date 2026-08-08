# Diagnostic — l'alarme « TAMPER audit_logs » est un FAUX POSITIF

**Date** : 2026-08-08 · **Méthode** : recalcul de la signature des 783 entrées, en lecture seule.

## Verdict en une ligne

**Aucune altération. Les 783 signatures sont intactes et reproductibles.** L'alarme vient
d'une incohérence dans le **choix du secret** au moment de signer, pas d'une atteinte aux
données.

## Mesures

| Constat | Valeur |
|---|---|
| Entrées `audit_logs` | 783 (id 1 → 783, **aucun trou**) |
| Ruptures de chaînage `prev_hash` | **0** — la chaîne est parfaitement liée |
| Signatures reproduites avec le secret **de leur branche** | 360 |
| Signatures reproduites avec le secret **par défaut** | **423** |
| Signatures **irréductibles** (ni l'un ni l'autre) | **0** ← aucune trace d'altération |

## Cause racine

`AuditLogService::computeHash()` signe avec `secretFor($branchId)`, où `$branchId` vient de
`resolveBranchId($data)` — le contexte de la requête. Mais la ligne est **persistée** avec un
`branch_id` résolu autrement (l'utilisateur authentifié, typiquement `1`).

Quand les deux divergent, la signature est calculée avec le secret **par défaut** alors que la
ligne porte `branch_id = 1`. `verifyChain()` recalcule avec `secretFor((int) ($row->branch_id ?? 0))`,
donc le secret de la branche 1 : il ne peut **jamais** reproduire le hachage.

Preuve directe sur l'entrée 56 (`user.login`, 2026-06-30 02:10:15) :

```
branche NULL → CORRESPOND
branche 0    → CORRESPOND        (même secret : le défaut)
branche 1    → non               ← celle qui est stockée sur la ligne
branche 2    → CORRESPOND
```

Cela explique tout ce qui rendait l'alarme illisible :
- **Pourquoi les échecs sont entremêlés** (360 OK / 423 KO) : cela dépend de la résolvabilité
  de la branche dans le contexte de CHAQUE requête, pas d'une date ni d'un type d'action.
- **Pourquoi tous les types d'action sont touchés à parts comparables** :
  `cash.movement.recorded` 100/120, `order.created.pos` 79/81, `user.login` 43/61…
- **Pourquoi le rapport ne nomme que `id=56`** : `verifyChain()` renvoie la **PREMIÈRE**
  entrée en échec et s'arrête. 56 n'a rien de spécial — c'est simplement la première.

## Pourquoi c'est important malgré l'absence d'altération

L'intérêt d'une chaîne signée est de **prouver** la non-altération à un tiers (contrôle NF525).
Pour 423 entrées sur 783, l'outil officiel du projet est aujourd'hui incapable de fournir cette
preuve — non par atteinte aux données, mais par incohérence interne. Et une alarme fiscale qui
crie au loup finit ignorée : celle-ci l'est depuis le 30 juin, notée « anomalie connue et gatée ».
C'est exactement le motif rencontré le même jour avec la sentinelle `F013` (fenêtre fixe de
5000 caractères) : **une sentinelle qui rougit à tort est pire que pas de sentinelle.**

## Remède proposé — GATE OWNER (zone gelée)

`app/Services/Fiscal/AuditLogService.php` est en zone gelée (CLAUDE.md §7) et le sujet est
fiscal. Rien n'a été modifié. Proposition, à arbitrer :

1. **Vérification tolérante aux deux secrets** (agilité de clé, exactement comme la rotation de
   clé d'API faite ce jour) : `verifyChain` accepte la signature si elle se reproduit avec le
   secret de la branche **ou** avec le défaut. Cela **restaure la vérifiabilité de l'historique**
   — l'objectif légal — sans rien affaiblir : sans posséder l'un des secrets, on ne peut
   toujours pas forger. ⚠ Ne JAMAIS re-signer les 423 entrées : la table est append-only, les
   réécrire serait précisément l'altération qu'on veut exclure.
2. **Cohérence à la signature** : utiliser pour le secret la MÊME branche que celle persistée,
   afin que la divergence cesse pour les entrées futures.
3. **Rapport utile** : `verifyChain` devrait distinguer « irréductible » (= suspect) de
   « reproductible avec un autre secret connu » (= incohérence interne), et ne pas s'arrêter à
   la première ligne. En l'état, l'outil ne sait pas dire « 0 altération » — il dit « TAMPER ».

## Reproduction (lecture seule, aucune écriture)

Recalculer chaque ligne via `AuditLogService::computeHash()` par réflexion, une fois avec
`(int) $row->branch_id` et une fois avec `0`, puis comparer à `current_hash`. Aucune écriture,
aucune donnée personnelle affichée.
