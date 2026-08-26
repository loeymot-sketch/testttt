# Contradiction `PROJECT_BRAIN.md` §9 vs §2 — dossier de décision propriétaire

- **Tâche** : T-6.2.1 du GOAL `CONSOLIDATION_V1_PRODUCTION_20260825` (vague W1)
- **Date** : 2026-08-25 · **HEAD** : `43b120c7d`
- **Règle appliquée** : CLAUDE.md §12 — contradiction dans la mémoire stable ⇒ **STOP et remonter**,
  jamais d'arbitrage silencieux. **Aucune ligne de §9 n'a été réécrite.**

---

## 1. Les deux textes, en regard

### §9 OWNER ACTION ITEMS (ligne 4478) — daté **2026-05-09**
> ⛔ **MERGE BLOQUÉ** par ultra audit POS 2026-05-09 […]
> Avant merge `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27` → `main` :
> ### NEW (pre-merge HARDSTOP — 15 P0 ultra audit, ~3-5j-agent)

### §2 CURRENT STATE (ligne 48) — daté **2026-08-22**
> **GOAL CAISSE DÉPLOYÉ ET VÉRIFIÉ SUR LE CONTENU SERVI**
> HEAD prod **`ac700e41c`** (== origin), avance rapide […] 7 commits, aucun `--force`.
> […] **APRÈS un `npx mix --production` complet, `git status` est TOUJOURS à 0 ligne.**

**La contradiction** : §9 déclare V1 **bloqué avant merge** ; §2 décrit V1 **déployé et servi en
production**, avec preuve prise sur la machine qui sert. Les deux ne peuvent pas être vrais ensemble.

---

## 2. Ce que dit le code aujourd'hui (vérifié, pas supposé)

| P0 de §9 | Affirmation de §9 (2026-05-09) | Constat 2026-08-25 | Lecture |
|---|---|---|---|
| P0-01/02 | « Décision SoftDeletes Order + OrderItem » **en attente** | `use SoftDeletes;` présent — `app/Models/Order.php:17`, `app/Models/OrderItem.php:13` | **Décision prise** (en faveur des SoftDeletes), §9 jamais mis à jour |
| P0-05 | « Décision `IDEMPOTENCY_MIDDLEWARE_ENABLED` default » **en attente** | `config/idempotency.php:28` → défaut **`false`**, MAIS garde d'amorçage production `AppServiceProvider.php:296` **refuse de démarrer** si ≠ true | **Risque neutralisé**, par un mécanisme que §9 n'anticipait pas |
| P0-11 | « SenangPay class **manquante** — restore ou drop » | Classe **présente** (`app/Http/PaymentGateways/Gateways/Senangpay.php`), + garde d'amorçage ajoutée le 2026-05-24 (`GOAL-L2-HEAL-06`, `AppServiceProvider.php:418-425`) | **Restaurée**, §9 périmé |
| P0-15 | « Gate rétroactive frozen-zone breach — KioskWizard / pos-wizard.js » | Les deux fichiers ont reçu des commits **postérieurs et documentés** : `f662a1277` (pos-wizard.js), `0c885b6ea` (KioskWizardComponent.vue, avec `LOCK_KIOSK_FRITES_SAUCE_BILLING_2026-07-29.md`) | **Traité par doc LOCK**, §9 périmé |
| Item 4 | « Triage 3 advisories composer : firebase/php-jwt, laravel/framework, **psy/psysh** » | Réel : `firebase/php-jwt`, `laravel/framework`, **`spatie/laravel-medialibrary`** — psy/psysh a disparu, medialibrary est apparu | **Liste périmée** |

**Éléments qui, eux, tiennent toujours** :
- La branche `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27` **existe encore**, en local **et** sur `origin` — elle n'a jamais été mergée ni supprimée.
- Le fichier de verdict cité, `reports/review/pos-ultra-audit-2026-05-09/99_VERDICT.md`, **existe bien**.

---

## 3. Lecture du superviseur

§9 **n'était pas faux quand il a été écrit**. Il est **périmé** : la réalité l'a dépassé. Les P0 ont
été soldés un par un par des cycles ultérieurs, mais **personne n'est revenu fermer §9**.

Le coût concret : la chaîne de démarrage à froid (CLAUDE.md §0) fait lire le BRAIN à chaque session.
Une session fraîche qui lit §9 croit que **le merge V1 est bloqué par 15 P0** — alors que V1 tourne
en production depuis août. C'est exactement le genre de documentation qui fait décider faux, avec
confiance.

⚠️ **Une réserve, à ne pas balayer** : le fait que les P0 soient techniquement résolus **ne signifie
pas** que le propriétaire a formellement levé le HARDSTOP. §9 était un gate propriétaire. Un gate ne
se périme pas tout seul parce que le code a bougé — il se lève explicitement. C'est précisément
pourquoi je ne le touche pas.

---

## 4. Décision demandée (aucune option n'est appliquée sans votre mot)

- **A)** **Clore §9** — le HARDSTOP est levé, les 15 P0 sont soldés ; §9 est archivé avec la date de
  levée et un renvoi vers ce dossier.
- **B)** **Clore partiellement** — lever les items prouvés soldés (P0-01/02, P0-11, P0-15, item 4),
  garder ouvert ce que vous jugez non tranché (typiquement P0-05 : le défaut `false` reste dans le
  fichier de config, même si la production ne peut pas démarrer sans).
- **C)** **Garder §9 ouvert tel quel** — le gate reste actif tant que vous ne l'avez pas relu vous-même ;
  j'ajoute uniquement un bandeau daté « périmé, contesté, voir ce dossier » pour protéger les
  sessions futures.
- **D)** **Rouvrir l'audit** — relancer une passe sur les 15 P0 avant toute décision.

**Recommandation** : **C maintenant, A ou B ensuite.** Le bandeau coûte une ligne et arrête
immédiatement la désinformation des sessions futures ; la levée du gate, elle, vous appartient et
mérite votre relecture, pas mon verdict.
