# Inventaire — gates en attente & dette de plans

- **Tâches** : T-6.3.1 + T-6.3.2 du GOAL `CONSOLIDATION_V1_PRODUCTION_20260825` (vague W1)
- **Date** : 2026-08-25 · **HEAD** : `43b120c7d`
- **Règle** : rien n'est supprimé ni déplacé dans ce rapport. **Inventaire seul.** Gate **G6** requis
  avant tout mouvement.

---

## 1. Gates en attente — **9 réels** (`PENDING_HUMAN_GATE` dans `docs/gates/GATE_LOG.md`)

| Date | Gate | Nature |
|---|---|---|
| 2026-04-20 | `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` | 8 cycles P0, zones gelées |
| 2026-04-26 | `HG-W2-1` (cutover POS V4) | soft-bloqué, attend HG-W2-3 + campagne LCP réelle |
| 2026-04-26 | `HG-W2-3` (révision KPI 220 → 600 KB + LCP) | décision produit, aucun code |
| 2026-05-02 | `GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK_2026-05-02` | zone gelée `PricingService.php` |
| 2026-05-02 | `GATE_SCHEMA_STOCK_MOVEMENT_IDEMPOTENCY_KEY_UNIQUE_2026-05-02` | DDL migration |
| 2026-05-02 | `GATE_DROP_TABLE_DELIVERY_BOYS_V1_2026-05-02` | **DROP TABLE** |
| 2026-05-02 | `GATE_DROP_TABLE_TABLE_SERVICE_V1_2026-05-02` | **DROP TABLE** |
| 2026-05-02 | `GATE_DROP_TABLE_ONLINE_ORDERS_V1_2026-05-02` | **DROP TABLE** |
| 2026-08-23 | `GATE-WHEEL-EXPERIENCE-UX-SIGNOFF-2026-08-23` | acceptation UX Roue (= **G2** du GOAL) |

Les trois briefs `DROP_TABLE` portent tous une ligne `Approved by: ______` **restée vide**.

---

## 2. ⚠️ Correction d'une affirmation de mon propre GOAL

Le GOAL (§8, Sub 6.3) présente les gates `DROP_TABLE` comme un danger latent — « elles ne se purgent
pas à la légère ». **Vérification faite, le risque est bien plus petit que je ne l'ai écrit** : dans
la base actuelle (111 tables), les cibles n'existent pratiquement plus.

| Gate | Table visée | État réel 2026-08-25 |
|---|---|---|
| ONLINE_ORDERS | `online_orders` | **absente** — aucune table contenant « online » |
| DELIVERY_BOYS | `delivery_boys` | **absente** — subsistent seulement `delivery_boy_cash_sessions` et `delivery_boy_cash_movements` |
| TABLE_SERVICE | `waiters`, `chefs`, `dining_tables` | `waiters` **absente** · `chefs` **absente** · `dining_tables` **EXISTE (1 ligne)** + `dining_table_audit_logs` |

Aucune migration de `database/migrations/` ne crée **ni** ne supprime `online_orders`,
`delivery_boys` ou `frontend_dining_tables` — et les modèles `OnlineOrder.php` / `DeliveryBoy.php`
n'existent pas non plus. Ces domaines ont donc quitté le schéma **autrement que par une migration
versionnée de ce dépôt**, ou n'ont jamais existé sous ces noms en V1.

**Conséquence pratique** : ces trois gates sont **caducs pour l'essentiel**. Il n'y a pas de purge
destructrice en attente d'exécution. Ce qui reste vraiment vivant, c'est le service à table
(`dining_tables`, 1 ligne).

### 🔴 Garde-fou à ne jamais franchir
`delivery_boy_cash_sessions` et `delivery_boy_cash_movements` **subsistent** et sont des tables de
**piste de caisse**, portées par `BranchScope` (CLAUDE.md §9). Elles sont adjacentes NF525.
⛔ **Elles ne doivent jamais être emportées par un nettoyage « livreurs »**, quelle que soit la
décision prise sur `delivery_boys`.

---

## 3. Dette de plans — **261 fichiers** dans `plans/`

| Mois | Fichiers | Lecture |
|---|---|---|
| 2026-04 | 69 | > 4 mois — presque certainement clos |
| 2026-05 | 102 | > 3 mois — presque certainement clos |
| 2026-06 | 11 | |
| 2026-07 | 46 | |
| 2026-08 | 26 | cycle courant + GOAL de ce jour |

**171 fichiers (65 %) datent d'avril-mai 2026.** Aucun dossier `plans/archive/` n'existe : tout
cohabite à plat, cycles clos et plans vivants mélangés. Un agent qui cherche « le plan actif » doit
trier 261 fichiers à la main.

---

## 4. Règle proposée (T-6.3.3) — **à valider, non appliquée**

1. Un plan clos part sous `plans/archive/<AAAA-MM>/`, **par déplacement `git mv`, jamais par suppression**.
2. Il reçoit en tête un bandeau : `> CLOS le <date> — verdict : <une ligne> — voir <rapport>`.
3. `plans/` à plat ne contient que les plans **actifs ou en attente de gate**.
4. Aucun déplacement sans **G6**.

---

## 5. Décision demandée — **G6**

- **A)** Archiver les 171 plans d'avril-mai sous `plans/archive/`, gates inchangés. *(recommandé — gain de lisibilité immédiat, risque nul, réversible par `git mv` inverse)*
- **B)** Archiver **et** clore les 3 gates `DROP_TABLE` comme **caducs** (leurs cibles ont disparu), en conservant le brief `TABLE_SERVICE` ouvert pour `dining_tables`.
- **C)** Ne rien bouger — inventaire seul, décision reportée.

⚠️ Quelle que soit l'option : **aucun `DROP TABLE` ne sera exécuté par ce GOAL.** Clore un gate
caduc signifie écrire « sans objet, cible inexistante » dans le journal — pas supprimer quoi que ce soit.
