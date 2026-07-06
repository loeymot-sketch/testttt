# Audit forensique FoodKing — 2026-07-06

Audit en profondeur du monorepo, produit par **orchestration multi-agents adversariale**.

> **Verdict global : `block` — score ≈ 3.5 / 10.** 10 systèmes sur 13 en `block`. 7 invariants sur 8 violés. 31 findings critiques, 76 élevés. 5 chaînes d'attaque faisables sur 6.
> Le produit n'est **pas déployable en l'état**, mais il est **réparable** (les bons patterns existent, ils sont *opt-in* au lieu d'imposés).

## Comment lire cet audit

**Pressé ?** → `00_RESUME_EXECUTIF.md` (Top 6 + 5 causes racines + réponse « repo bien structuré ? »).
**Technique ?** → commence par `03_INVARIANTS_FULLSTACK.md` (la cause de tout), puis `04` et `05`.
**Chef de projet ?** → `07_FEUILLE_DE_ROUTE.md` (plan P0→P3 séquencé).

## Sommaire

| # | Fichier | Contenu |
|---|---|---|
| 00 | `00_RESUME_EXECUTIF.md` | Verdict, Top 6, causes racines, bilan chiffré, gouvernance |
| 01 | `01_VERSION_MAP_ET_DETTE.md` | Laravel 9 / PHP 8.1 **EOL**, Stripe SDK en retard, build legacy, double CSS |
| 02 | `02_STRUCTURE_ET_HYGIENE.md` | **« Le repo est-il bien structuré ? »** + arborescence cible + plan de rangement sûr |
| 03 | `03_INVARIANTS_FULLSTACK.md` | Les 8 traçages full-stack — **7/8 violés** — et les 5 causes racines |
| 04 | `04_REGISTRE_FINDINGS.md` | 31 critiques (par cluster) + 76 élevés (par système), ancrés `fichier:ligne` |
| 05 | `05_SECURITE_RED_TEAM.md` | 6 chaînes d'attaque détaillées + inventaire des secrets committés |
| 06 | `06_SCORECARD_ET_CARTE.md` | Scores/verdicts des 13 systèmes + graphe de dépendances + forces |
| 07 | `07_FEUILLE_DE_ROUTE.md` | Remédiation P0→P3 séquencée par cause racine + critères de sortie |

## Méthode

- **Reconnaissance** : 7 scouts (inventaire + hotspots).
- **Découverte multi-angles** : 32 audits par lentille (logique / sécurité / archi-sync) + **8 traçages d'invariants full-stack** (effort maximal) + **6 scénarios red team**.
- **Vérification adversariale** : chaque finding critical/high soumis à 3 réfutateurs indépendants + juge d'arbitrage. **~87 % maintenus** ; les 3 plus graves relus manuellement à la source.
- **Contrainte** : audit **100 % statique** (dépendances non installées — `Read`/`Grep`/`Glob`, `php -l`). Aucune exécution de test ou d'installation.

> Note de transparence : la phase de synthèse *automatique* du workflow a été interrompue par un incident d'infrastructure après la fin des phases de découverte et de vérification (les plus coûteuses et à plus forte valeur, **intégralement terminées**). La synthèse et la mise en forme des rapports ont été assemblées à partir de leurs résultats vérifiés.

## Chiffres clés

| | |
|---|---:|
| Fichiers du dépôt analysés | 3 267 |
| Systèmes audités | 13 |
| Agents d'audit exécutés | ~230 |
| Invariants violés / partiels / tenus | 7 / 1 / 0 |
| Findings critiques / élevés / moyens | 31 / 76 / ~85 |
| Chaînes red team faisables | 5 / 6 |
