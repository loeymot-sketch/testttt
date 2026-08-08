# Diagnostic — l'alarme « TAMPER audit_logs » est un FAUX POSITIF · **RÉSOLU**

> **ÉTAT FINAL (2026-08-08)** : corrigé sous
> `plans/LOCK_FISCAL_VERIFYCHAIN_AGILITE_SECRETS_2026-08-08.md` (owner : « Corrige sous LOCK »),
> déployé, et **vérifié en production** :
> `SWEEP COMPLETE — CHAIN OK on every active branch`, `verifyChain(1)` ne signale plus aucune
> ligne sur les **783**. Aucune ligne n'a été ré-écrite.

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

`secretFor($branchId)` renvoie l'override `FISCAL_AUDIT_SECRET_BRANCH_{id}` s'il existe, sinon le
défaut `fiscal.audit_secret`. `verifyChain()` recalcule avec `secretFor((int) $row->branch_id)`,
donc — pour ces lignes, toutes en branche 1 — avec l'**override**.

Or 423 lignes ont été signées avec le **défaut**. Elles ne peuvent donc jamais être reproduites
avec l'override seul.

**Le code de signature actuel n'est PAS en cause** : les 234 entrées postérieures à l'id 549 sont
toutes valides. La divergence est **historique et environnementale** — aucun commit ne touche
`AuditLogService.php` ni `config/fiscal.php` entre le 28/07 et le 05/08, donc l'alignement observé
le 03/08 vient de l'apparition ou de la modification de `FISCAL_AUDIT_SECRET_BRANCH_1` sur le VPS.
Les lignes signées avant ce basculement portent la marque du secret précédent. C'est un artefact
de **rotation de secret**, pas un défaut de code vivant.

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

## Remède APPLIQUÉ — sous LOCK, validé par l'owner

`app/Services/Fiscal/AuditLogService.php` est en zone gelée (CLAUDE.md §7). Patch autorisé par
`LOCK_FISCAL_VERIFYCHAIN_AGILITE_SECRETS_2026-08-08.md`, portée limitée à `verifyChain()` :

1. **Vérification tolérante aux deux secrets** (agilité de clé, exactement comme la rotation de
   clé d'API faite ce jour) : `verifyChain` accepte la signature si elle se reproduit avec le
   secret de la branche **ou** avec le défaut. Cela **restaure la vérifiabilité de l'historique**
   — l'objectif légal — sans rien affaiblir : sans posséder l'un des secrets, on ne peut
   toujours pas forger. ⚠ Ne JAMAIS re-signer les 423 entrées : la table est append-only, les
   réécrire serait précisément l'altération qu'on veut exclure.
2. **Rapport honnête** : `verifyChain()` ne s'arrête plus sur une ligne qu'elle sait reproduire
   avec un autre secret connu. Une ligne irréductible reste signalée comme ALTÉRATION.

**Une partie de ma proposition initiale a été ABANDONNÉE, et c'est la mesure qui l'a réfutée** :
je voulais aussi « rendre la signature cohérente ». Inutile — le chemin de signature l'est déjà
depuis le 03/08 (234 entrées consécutives valides). Corriger ce qui fonctionne aurait été un
risque gratuit sur du code fiscal.

## Découverte annexe, rassurante

En écrivant les tests, une tentative d'`UPDATE` sur `audit_logs` a été refusée par un
**déclencheur de base de données** : « audit_logs is INSERT-only (NF525) ». L'append-only est
donc garanti par la base elle-même, pas seulement par le modèle Eloquent — protection plus forte
que ce que la documentation laissait supposer. Les tests d'altération forgent par conséquent des
lignes à l'INSERTION, seul modèle de menace praticable.

## Preuves du correctif

- `tests/Feature/Fiscal/AuditChainSecretAgilityTest.php` **8/8**, dont 4 tests qui exigent la
  DÉTECTION (charge utile incohérente, signature arbitraire, chaînage rompu, secret inconnu) et
  le cas d'une altération au MILIEU d'une chaîne mixte.
- **MUTATION** : détection neutralisée ⇒ ces 4 tests ROUGISSENT ; restaurée ⇒ 8/8. La tolérance
  aux deux secrets n'est donc pas devenue « accepter n'importe quoi ».
- Suites : Fiscal 292 (0 échec) · Security 131/131 · Sentinels 357 (0 échec).
- Production : `fiscal:verify-chain --all` → **CHAIN OK**, 783 entrées, 0 signalée.

## Reproduction (lecture seule, aucune écriture)

Recalculer chaque ligne via `AuditLogService::computeHash()` par réflexion, une fois avec
`(int) $row->branch_id` et une fois avec `0`, puis comparer à `current_hash`. Aucune écriture,
aucune donnée personnelle affichée.
