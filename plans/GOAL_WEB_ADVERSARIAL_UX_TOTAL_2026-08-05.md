# GOAL — WEB TOTAL : ADVERSARIAL LOGIC + UX/PSYCHO + MOBILE
**Date** : 2026-08-05 · **Owner** : Kossay · **Cible** : site client `www.lecayenne.fr`
**Dépôt SSOT** : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne` (HEAD `e15bb42`)
**Backend** : `foodking-web/web/testttt` (HEAD `01499f907`, 10 commits d'avance sur VPS)
⛔ `/Users/1millnonstop/Downloads/web` = ARCHIVE MORTE — ne jamais lire ni éditer (piège documenté 2026-07-29)

---

## 0. MISSION REFORMULÉE (ce que j'ai compris, rendu discipliné)

> **Version owner (brute)** : « audit max + raisonnement illogique sur le web, parcours depuis
> création de compte et le système INVERSE : annuler la vérification, annuler l'enregistrement,
> annuler le paiement — j'ai trouvé beaucoup de failles comme ça. Parfois les suppléments ne
> sont pas calculés. Puis UX/psycho page par page, chaque pixel, wizard/options/upsells sans
> rien d'anxiogène ni de prix bizarre. Puis mobile + fidélité + points. Boucler jusqu'au vert. »

### Traduction en 6 AXES contractuels

| # | AXE | Question adversariale centrale | Verdict attendu |
|---|-----|-------------------------------|-----------------|
| **A1** | **Chemins INVERSES (abandon/annulation)** | Que se passe-t-il quand l'utilisateur **abandonne** à chaque étape ? Annuler l'OTP → suis-je connecté quand même ? Annuler le 3DS → la commande part-elle ? Fermer l'onglet à mi-wizard → état fantôme ? | Aucun chemin d'abandon ne laisse un état **autorisé, payé, ou commandé** qu'il ne devrait pas |
| **A2** | **Intégrité ARGENT (money-path)** | Chaque supplément / option / variation / frais de livraison / remise / point de fidélité est-il **affiché == facturé == scellé** ? | Écart client-visible vs `expected_total` backend = **0,00 €** sur les 55 produits |
| **A3** | **SÉCURITÉ compte & session** | Peut-on prendre un compte, réutiliser un OTP, escalader un token, commander au nom d'un autre, voir les commandes d'un autre ? | 0 P0/P1 sécurité, toute garde prouvée par test rouge→vert |
| **A4** | **UX / PSYCHOLOGIE du parcours** | Le parcours **rassure-t-il** ou **effraie-t-il** ? Prix surprises, upsells agressifs, boutons anxiogènes, tailles/couleurs/hiérarchie, charge cognitive, moment de la demande de compte, formulation des erreurs. | Chaque écran passe la grille « CALME / CLAIR / HONNÊTE / SANS FRICTION INUTILE » |
| **A5** | **MOBILE (mission 2)** | Chaque page, chaque interaction, chaque zone tactile, chaque scroll, sur vrai viewport mobile. | 0 débordement, cibles ≥44px, aucun contenu inatteignable, temps de rendu correct |
| **A6** | **FIDÉLITÉ & POINTS** | Cumul, arrondi, utilisation, expiration, cohérence affiché/DB, abus (double-redeem, points volés, clawback) | Invariants points prouvés + parité affichage↔DB |

### Ce que je NE ferai PAS (garde-fous)
- ❌ Aucun push, aucun déploiement Vercel/VPS sans GO owner explicite (commits locaux + rapport).
- ❌ Aucune édition de frozen-zone (wizard POS Vanilla JS, `PricingService`, fiscal, `BranchScope`) sans LOCK + gate.
- ❌ Aucun finding rapporté sans `file:line` + commande de reproduction exécutée (règle anti-hallucination CLAUDE.md §3ter).
- ❌ Aucun `sed -i` / regex multi-fichiers — Edit ciblés uniquement (leçon /insights 2026-08-05).
- ❌ Aucune remontée « cloud / multi-tenant / scale » comme P0/P1 (Constitution §1).

---

## 1. DÉFINITION DE « TERMINÉ » (critère de convergence, non négociable)

```
CONVERGÉ  ⟺  DEUX cycles adversariaux CONSÉCUTIFS produisent :
             ├── 0 finding P0
             ├── 0 finding P1
             ├── gates identiques et vertes (Vitest + PHPUnit ciblés + e2e web)
             └── captures visuelles desktop + mobile analysées, 0 défaut bloquant
```
Tout autre état = **HEAL** (on reboucle) ou **ESCALATE** (décision owner requise).
Le numéro de cycle et le verdict sont écrits **explicitement** dans chaque rapport d'étape.

---

## 2. PREFLIGHT (Vague 0 — avant toute ligne de code)

| Contrôle | Commande | Seuil bloquant |
|---|---|---|
| Disque libre | `df -h .` | < 5 Go → STOP (leçon : captures ont mangé le disque jusqu'à 2,3 Go) |
| Backend local up | `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8000/` | ≠ 200 → démarrer `php artisan serve` |
| Dépôt web propre & à jour | `git -C "<web>" fetch && git status --porcelain` | arbre sale non compris → STOP |
| SHA épinglé pour les agents | `git rev-parse HEAD` (backend + web) | injecté dans CHAQUE brief d'agent |
| DB de test isolée | suite PHPUnit sur sqlite/`RefreshDatabase` | jamais sur la DB de prod locale |
| Node/Playwright | `npx playwright --version` | absent → installer avant W3 |

---

## 3. PLAN DE VAGUES

```
 ┌──────────────────────────────────────────────────────────────────────────┐
 │ W0  PREFLIGHT + cartographie exhaustive des écrans/routes web            │
 ├──────────────────────────────────────────────────────────────────────────┤
 │ W1  SWARM ADVERSARIAL #1 — 6 agents en parallèle, lecture seule          │
 │     A1 inverse/abandon · A2 money · A3 sécu · A4 UX/psycho · A5 mobile   │
 │     · A6 fidélité      →  findings AVEC file:line + repro obligatoire    │
 ├──────────────────────────────────────────────────────────────────────────┤
 │ W2  PROCUREUR (anti-hallucination) — rejoue CHAQUE repro,               │
 │     classe PROUVÉ / NON-PROUVÉ / RÉFUTÉ. Seuls les PROUVÉS remontent.   │
 ├──────────────────────────────────────────────────────────────────────────┤
 │ W3  PARCOURS RÉEL navigateur — inscription→OTP→annulation→panier→        │
 │     wizard→suppléments→checkout→3DS annulé→3DS OK→suivi→fidélité         │
 │     desktop 1440 + mobile 390. Captures analysées une par une.           │
 ├──────────────────────────────────────────────────────────────────────────┤
 │ W4  HEAL P0/P1 — TDD strict : test rouge d'abord, puis correctif,        │
 │     puis blast-radius grep des jumelles (leçon « les 4 jumelles »)       │
 ├──────────────────────────────────────────────────────────────────────────┤
 │ W5  UX/PSYCHO — refonte ciblée : hiérarchie prix, ton des erreurs,      │
 │     upsells non agressifs, calme des écrans de paiement, micro-copie FR │
 ├──────────────────────────────────────────────────────────────────────────┤
 │ W6  MOBILE — page par page, viewport réel, zones tactiles, scroll,      │
 │     clavier, safe-areas, perf                                           │
 ├──────────────────────────────────────────────────────────────────────────┤
 │ W7  SWARM ADVERSARIAL #2 (cycle de convergence) → si 0 P0/P1 → CYCLE 2  │
 │     identique → CONVERGÉ. Sinon retour W4.                              │
 ├──────────────────────────────────────────────────────────────────────────┤
 │ W8  RAPPORT + BRAIN + mémoire + commits atomiques. STOP avant push.     │
 └──────────────────────────────────────────────────────────────────────────┘
```

### Détail W1 — les 6 briefs adversariaux

Chaque agent reçoit **le même préambule dur** :
> Tu es épinglé au SHA `<X>`. Vérifie-le (`git rev-parse HEAD`) avant tout. Le dépôt web est
> `<chemin>` — `/Downloads/web` est une archive morte, ne la lis JAMAIS. Tout finding doit
> porter `file:line` + code cité + **commande de reproduction exécutée** avec sortie réelle.
> Sans repro exécutée → le finding est marqué SPÉCULATIF et sera rejeté. Lecture seule.

| Agent | Terrain de chasse |
|---|---|
| **A1 — L'ABANDONNEUR** | `account-v2.jsx`, `flows.jsx`, OTP backend, retours Mollie annulés, `popstate`, TTL panier, onglet fermé, back navigateur, double-soumission |
| **A2 — LE COMPTABLE** | `funnel.jsx` + `wizard-v2.jsx` + `api.js` vs `PricingService`/`resolveLine` backend : suppléments, 2ᵉ sauce, viandes nommées, variations, frais livraison, coupon, points |
| **A3 — L'INTRUS** | OTP rejeu/brute-force, token kiosk:order, énumération de comptes, IDOR commandes/points, soft-deleted, channel-confusion email/SMS |
| **A4 — LE PSYCHOLOGUE** | chaque écran : hiérarchie visuelle, prix surprises, upsells, ton des erreurs, moment du compte obligatoire, boutons anxiogènes, dark-patterns involontaires |
| **A5 — LE POUCE** | mobile : `styles-mobile.css` + tous les écrans, cibles tactiles, débordements, clavier virtuel, safe-area, scroll piégé |
| **A6 — LE COMPTEUR DE POINTS** | cumul/redeem/arrondi/expiration/clawback, parité affiché↔DB, double-utilisation |

---

## 4. RÉSILIENCE (le plan survit à une coupure de session)

`SESSION_STATE.md` réécrit **après chaque vague** :
```json
{ "wave": "W3", "cycle": 1, "gates": {...}, "open_P0": [], "open_P1": [...],
  "next_command": "...", "evidence_dir": "reports/goal-web-total-2026-08-05/" }
```
Commit checkpoint à chaque fin de vague. Une session qui reprend lit ce fichier EN PREMIER.

---

## 5. LIVRABLES

1. `reports/goal-web-total-2026-08-05/` — findings prouvés, captures desktop+mobile analysées, verdicts de cycle.
2. Commits atomiques locaux (web + backend), **aucun push**.
3. `PROJECT_BRAIN.md §2` mis à jour + mémoire topic.
4. Liste finale des points **owner-gate** (décisions métier / frozen-zone / déploiement).
