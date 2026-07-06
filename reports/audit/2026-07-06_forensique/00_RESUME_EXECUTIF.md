# FoodKing — Résumé exécutif de l'audit forensique

> **Date** : 2026-07-06 · **Périmètre** : monorepo complet (Laravel 9 + Vue 3, POS/kiosque/KDS/OSS/commande en ligne)
> **Méthode** : orchestration multi-agents — 7 scouts de reconnaissance, 32 audits par lentille (logique/sécurité/archi), 8 traçages d'invariants full-stack, 6 scénarios red team, puis **vérification adversariale** (3 réfutateurs + juge par finding). Audit **100 % statique** (dépendances non installées).
> **Verdict global : `block`** — score ≈ **3.5 / 10**.

---

## 1. Le verdict en une phrase

**Le socle métier ne tient pas** : sur les 4 invariants « non négociables » du projet, **7 déclinaisons full-stack sur 8 sont violées**, avec au moins **trois pertes d'argent directes** et **trois fuites de données cross-branch** exploitables — dont plusieurs par un acteur **non authentifié**. Le produit n'est **pas déployable en production** en l'état, mais il est **réparable** (les bons patterns existent, ils sont juste *opt-in* au lieu d'être imposés).

## 2. Ce qui doit alarmer aujourd'hui (Top 6)

1. 🔥 **Clé privée admin Firebase/GCP servie publiquement** (`public/file/service-account-file.json`). À considérer comme **brûlée** : rotation immédiate côté Google.
2. 💸 **Stripe encaisse un montant tronqué** : `(int) $order->total * 100` → un total de 12,99 € débite 12,00 €. **Perte d'argent déterministe sur chaque commande carte** + grand livre incohérent.
3. 💸 **Repas gratuits via l'API table QR non authentifiée** : le client dicte remise et `delivery_charge` (même négatif) sans aucun contrôle → total ramené à la seule TVA, répétable.
4. 🔓 **Le token de la borne est celui de l'admin `id=1`** : quiconque obtient ce token (mot de passe `kiosk123` embarqué) contrôle **tout le back-office**.
5. 🔓 **Isolation de branche effondrée** : `branch_id=0` (tout client) est interprété comme « admin / toutes branches » → un simple compte client lit les commandes, la PII et les paiements de **toutes** les branches ; l'Installer non authentifié permet une reprise complète de la base.
6. 💸 **Double remboursement** par annulation concurrente (ni verrou ni transaction) + **paiement PAID sur simple déclaration client** sans vérification PSP.

## 3. Les 5 causes racines (traiter celles-ci répare la majorité des findings)

| # | Cause racine | Findings dérivés |
|---|---|---|
| 1 | **Confiance au client sur les endpoints non authentifiés** (prix, remise, statut paiement, IDs acceptés bruts) | C01, C05-06, C10-11, C13-14, C20-21, C26 |
| 2 | **Sécurité *opt-in* au lieu de *deny-by-default*** (BranchScope sur 5 modèles, abilities non imposées, permissions oubliées) | C07-09, C15, C18-19, C27 |
| 3 | **`branch_id=0` surchargé** (« client sans branche » = « toutes branches / admin ») | C07, C12, C30 |
| 4 | **Aucune atomicité/verrou sur les chemins argent** (changeStatus, remboursements, ledger, outbox en check-then-act) | C23-25, + highs |
| 5 | **Sceau/contrat = une seule vérification applicative**, pas une contrainte structurelle (DB `unique`, garde de modèle, schéma mono-sourcé) | C03-04, C17, C31 |

## 4. Bilan chiffré

| | Nombre |
|---|---:|
| Systèmes audités | 13 (+ sous-systèmes paiement/notif/i18n/installer) |
| Systèmes en `block` / `heal` / `continue` | **10 / 3 / 0** |
| Invariants violés / partiels / tenus | **7 / 1 / 0** |
| Findings critiques | **31** |
| Findings élevés (high) | **76** |
| Findings moyens / faibles | ~85 / ~19 |
| Chaînes red team faisables | **5 / 6** |
| Findings vérifiés maintenus (phase adversariale) | **~87 %** |

## 5. Réponse à la question initiale : « mon repo est-il bien structuré ? »

**Non, pas comme un produit livrable.** L'ossature Laravel/Vue est propre, mais elle est **noyée sous ~1/3 de fichiers non-produit** (reports, tasks, archives, 227 Mo de binaires, 3 `.docx`, images, résidus de debug), avec des **dossiers à espaces/accents** hostiles au tooling, des **secrets committés**, et **4 implémentations kiosque concurrentes**. Le détail et le plan de rangement sûr sont dans le **rapport 02**.

## 6. Recommandation de gouvernance (doctrine CLAUDE.md)

- **Décision : `block` + `human`.** Un risque critique avéré existe (perte d'argent, fuite de données, secret exposé), plusieurs règles stables sont contredites, et la correctness business-critique est incertaine → **revue humaine requise avant tout déploiement**.
- **Ne pas empiler de nouvelles fonctionnalités** tant que les 5 causes racines ne sont pas traitées : elles se propageraient sur un socle non sûr.
- **Renforcer les portes qualité** : la CI actuelle ne lance pas Vitest et contient des tests aux assertions tolérantes — elle donne une **fausse assurance** (invariant CLAUDE.md : « des tests qui passent ne prouvent pas l'acceptabilité »).

## 7. Navigation des rapports

| # | Rapport | Contenu |
|---|---|---|
| **00** | Résumé exécutif | *(ce document)* |
| **01** | Version map & dette | Laravel 9 / PHP 8.1 EOL, Stripe SDK, build legacy |
| **02** | Structure & hygiène | Réponse « bien structuré ? » + arborescence cible + plan de rangement |
| **03** | **Invariants full-stack** | Les 8 traçages, cause de tout — *à lire en priorité* |
| **04** | Registre des findings | 31 critiques + 76 highs, ancrés `fichier:ligne` |
| **05** | Sécurité & Red Team | 6 chaînes d'attaque + inventaire des secrets |
| **06** | Scorecard & carte | Scores/verdicts par système + graphe de dépendances |
| **07** | Feuille de route | Plan P0→P3 séquencé par cause racine |

---

*Audit généré par orchestration multi-agents adversariale. Chaque finding est prouvé par le code source ; les 3 plus graves ont été relus manuellement à la source. La phase de synthèse automatique ayant été interrompue par un incident d'infrastructure, la synthèse finale a été assemblée à partir des résultats vérifiés des phases de découverte et de vérification (les phases à plus forte valeur, intégralement terminées).*
