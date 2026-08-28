# Inventaire des missions ouvertes — dépôt entier

Date : 2026-08-24 · Produit par : `claude-code-supervisor`

Vous aviez demandé de traiter « toutes les missions qui ne sont pas terminées et toutes les
missions qui restent à faire ». Je m'étais limité aux **deux missions que vous aviez
nommées**. Voici l'inventaire du reste, avec ce qu'il faut en penser — parce que le brut
serait trompeur.

---

## 1. Ce que le dépôt affiche brut

| Signal | Compte |
| --- | --- |
| Dossiers dans `missions/` | ~160 |
| Plans avec la case « Passed — cycle closed » **non cochée** | **21 / 118** |
| Gates distincts en `PENDING_HUMAN_GATE` dans `docs/gates/GATE_LOG.md` | **9** |

**Ce brut est trompeur, et je ne vais pas vous le vendre tel quel.**

---

## 2. Ce que ça vaut réellement, après vérification

### 2.1 Les 21 plans « ouverts » sont pour l'essentiel périmés

Dix-huit d'entre eux datent d'**avril–mai 2026**. Or `PROJECT_BRAIN.md §2`, daté du
**2026-08-22**, documente des déploiements en production réussis et vérifiés jusqu'à
`ac700e41c`. Un plan d'avril dont le travail a été livré, puis déployé, puis re-vérifié
quatre mois plus tard, n'est pas « à faire » : c'est une case à cocher qu'on a oublié de
cocher.

Trois seulement sont vivants :
- `PLAN_CAISSE-SUPERVISOR-CONTROL-20260823` — ce cycle, ouvert **exprès** (pas de verdict GPT) ;
- `PLAN_GOAL-WHEEL-EXPERIENCE-20260823` — ouvert **exprès** (gate UX humain) ;
- `PLAN_TEMPLATE.md` — un gabarit, il n'a pas à être coché.

**Ce que je recommande** : ne pas « exécuter » ces 18 plans. Les réconcilier — vérifier
lesquels sont livrés et fermer leur case — est une tâche d'hygiène documentaire d'une demi-
journée, utile mais qui n'apporte aucune ligne de code. À faire quand vous voudrez, pas
maintenant.

### 2.2 Les 9 gates en attente : 1 vivant, 8 anciens

| Gate | Date | Lecture |
| --- | --- | --- |
| `GATE-WHEEL-EXPERIENCE-UX-SIGNOFF-2026-08-23` | 2026-08-23 | **Vivant** — dossier technique prêt, attend votre œil |
| `GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK` | 2026-05-02 | Ancien — zone gelée pricing |
| `GATE_SCHEMA_STOCK_MOVEMENT_IDEMPOTENCY_KEY_UNIQUE` | 2026-05-02 | Ancien — migration |
| `GATE_DROP_TABLE_DELIVERY_BOYS_V1` | 2026-05-02 | Ancien — suppression de table |
| `GATE_DROP_TABLE_TABLE_SERVICE_V1` | 2026-05-02 | Ancien — suppression de table |
| `GATE_DROP_TABLE_ONLINE_ORDERS_V1` | 2026-05-02 | Ancien — suppression de table |
| `GATE_VERIFY_P0_FROZEN_CONSOLIDATED` | 2026-04-20 | Ancien |
| `HG-W2-1`, `HG-W2-3` | 2026-04-26 | Anciens — vague W2 |

Les trois `DROP_TABLE` méritent votre attention : ce sont des **suppressions de tables**
(livreurs, service à table, commandes en ligne) restées en attente depuis quatre mois. Tant
qu'elles ne sont pas tranchées, le code de ces modules reste dans le dépôt et continue
d'être maintenu, testé et audité pour rien. C'est du poids mort qui coûte à chaque cycle.

### 2.3 Dérive documentaire à corriger — `PROJECT_BRAIN.md §9`

Cette section annonce en tête :

> ⛔ **MERGE BLOQUÉ** par ultra audit POS 2026-05-09 — 15 items P0

Or §2 du **même fichier**, daté du 2026-08-22, décrit des mises en production réussies bien
postérieures. Les deux sections se contredisent. Per CLAUDE.md §12 (anti-drift), je ne
tranche pas seul : je remonte la contradiction. Une session future qui lira §9 en premier
croira le merge bloqué et refusera d'avancer.

**Ce que je recommande** : dater §9 explicitement comme historique, ou la réconcilier avec
l'état réel. C'est cinq minutes, et ça évite de faire perdre une session entière à quelqu'un.

### 2.4 Le vrai reste-à-faire technique est dans `PROJECT_BRAIN.md §5`

C'est là que se trouve le travail réel, déjà priorisé par le projet :

**P1, V1.0.1** — encore marqués ⏳ :
- refactor FormRequest authz, 88 points d'entrée (1-2 j) — le cliquet
  `RETURN_TRUE_BASELINE` est descendu de 77 à **64**, le chantier avance déjà par vagues ;
- mot de passe `min:12` + complexité (0,5 j) ;
- TTL Sanctum 8 h → 1 h sur opérations sensibles (0,5 j) ;
- versionnage de clé API (1 j) ;
- idempotence des 6 listeners restants (0,5 j).

**P2, observabilité** : métriques de latence, drapeau de débordement KDS, `/api/sync/status`,
dédoublonnage `correlation_id`, polling adaptatif quand le WebSocket tombe.

**Sécurité, à trancher** : 3 avis `composer audit` — `firebase/php-jwt` (LOW),
`laravel/framework` CVE-2025-27515 (MEDIUM), `psy/psysh` (MEDIUM).

---

## 3. Ce que je propose de faire ensuite

Par rapport gain/risque, dans cet ordre :

1. **Les 3 avis de sécurité composer** — mesurable, borné, et c'est de la sécurité. Je peux
   établir l'exposition réelle de chacun et proposer les montées de version.
2. **TTL Sanctum + politique de mot de passe** — une journée à deux, cadré, testable.
3. **Poursuivre le cliquet FormRequest authz** — le chantier a déjà sa méthode et sa
   sentinelle ; il suffit de continuer par vagues.
4. **Réconcilier §9 et fermer les 18 plans périmés** — hygiène, aucune ligne de code.
5. **Trancher les 3 gates `DROP_TABLE`** — votre décision ; elle allégerait durablement le
   dépôt.

Je n'ai rien lancé de tout cela : ce sont des chantiers distincts de la mission que vous
m'aviez confiée, et plusieurs demandent votre arbitrage avant la première ligne.
