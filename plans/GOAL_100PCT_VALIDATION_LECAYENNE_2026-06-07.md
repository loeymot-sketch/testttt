# 🎯 GOAL — VALIDATION 100% FONCTIONNELLE FoodKing V1 (Le Cayenne)
**Date:** 2026-06-07 · **Type:** Production-readiness — pré-installation matériel
**Mode:** SUPERVISEUR CENTRAL + ARMÉE PARALLÈLE · **Tolérance:** ZÉRO
**Statut:** PRÉPARÉ — prêt à lancer (`lance le GOAL`). NE PAS livrer tant que ≠ 100%.

> **CE FICHIER EST LE CENTRE.** Tout agent, tout sous-agent, toute vague lit ce
> fichier EN PREMIER. Le superviseur central l'utilise comme tableau de bord
> unique. Chaque sous-agent reçoit son fichier de mission dans
> `plans/goal-100pct-2026-06-07/agents/`. Rien n'est livré sans passer par ici.

---

## §0 — DOCTRINE (lire avant tout — ton supervisor sévère, zéro pitié)

### 0.1 Mission (1 phrase)
Valider **TOUT le système FoodKing V1 à 100% fonctionnel** — aucun problème
direct ou indirect, caché ou visible, technique ou interface — pour que, une
fois posé sur chaque matériel physique, **la SEULE nouveauté soit l'impression
papier du ticket.** Tout le reste doit être prouvé en logiciel AVANT.

### 0.2 Règle d'or du superviseur (zéro tolérance)
- « Ça s'affiche / HTTP 200 / pas d'erreur console » **N'EST PAS** une validation.
- PASS = **j'ai piloté la fonction + inspecté la sortie réelle + essayé de la casser.**
- Tout finding sans `file:line` + reproduction + preuve (capture / requête DB /
  test nommé) = **REJETÉ**, non remonté (anti-hallucination CLAUDE.md §3ter).
- Toute capture montrant un raw label (`kiosk.x`, `Label.foo`, `0undefined`),
  un layout cassé, une erreur console, un état vide non géré = **REJET + heal**.
- Toute remise « presque bon » / « suffisant » = **REJET. Production-perfect ou bloqué.**
- Le GOAL n'est PAS livrable tant qu'UN seul item du checklist n'est pas ✅ ou
  explicitement 🔒 owner-gate documenté.

### 0.3 Working tree
Worktree `pre-cloud-exec` (branche courante). Specs E2E + rapports = commit
local autorisé (jamais push sans owner §10). **Toute mutation/commande E2E sur
le clone jetable `foodking_e2e` :8766 — JAMAIS la DB opérante `foodking`.**
(cf. [[feedback_shared_infra_devdb_footgun]] : `php artisan test` a déjà WIPÉ la
DB op — DEVDB-GUARD actif).

### 0.4 Pipeline par tâche (NE PAS redécrire)
Chaque tâche s'exécute via `ultra-audit-profond` (14 étapes) ; audit massif
multi-pages via `test-e2e` ; override frozen via `lock-plan`. Convergence =
2 cycles consécutifs P0+P1=0 ET findings identiques (règle test-e2e).

### 0.5 Invariants durs (non négociables)
- **Frozen zones** CLAUDE.md §7 (`memory/reference_frozen_zones.md`) — strict-no-touch.
- **NF525** CLAUDE.md §8 — séquence gap-free, chaîne HMAC, snapshot prix backend SSOT.
- **V1 LOCAL Le Cayenne** — mono-poste, FR (ADR-007), 1 branche, 0 cloud blocker.

---

## §1 — CARTE DES SYSTÈMES (anchors vérifiés 2026-06-07)

| # | Système | Maturité | Anchor réel (vérifié) | Tests existants |
|---|---------|----------|------------------------|-----------------|
| S1 | **POS / Caisse** | ⚠️ partiel (CASH only validé) | `Admin/PosController.php`, `AdminPosV4Controller.php`, `PosOrderController.php`, `public/js/pos-wizard.js` (FROZEN), `pos-app.js` | `tests/Feature/Pos/*` (PosCashTrailTest, PosLoyaltyRedeemTest, PosMenuRuntimeAccessTest, FritesWizardComposerTest…) |
| S2 | **Kiosk / Borne** | ⚠️ idle+menu+drink validé, WIZARD non testé | `Frontend/MenuController.php`, `Frontend/RootController.php`, 48 composants `resources/js/components/frontend/kiosk/*.vue` | `tests/e2e/zz-kiosk-*-2026-06-07.spec.js` |
| S3 | **KDS** | ⚠️ 1 happy-path advance | `Admin/KitchenDisplaySystemController.php`, `Services/KitchenDisplaySystemOrderService.php` | KDS sentinels + `zz-kds-*` |
| S4 | **OSS** | ⚠️ affichage seul | `Admin/OrderStatusScreenController.php`, `orderStatusScreenRoutes.js` | `zz-central-surface-sweep` |
| S5 | **Admin / Dashboard** | ⚠️ render-only | `Admin/*Controller.php` (CRUD), dashboard, historique, stock, customers | `tests/Feature/*` (665) |
| TR | **FISCAL / TICKET / NF525** (transversal) | ❌ ticket print non mergé | `Services/Fiscal/*` (FROZEN), `Receipt/ReceiptDataService.php`, `Hardware/EscPos*` | fiscal sentinels |
| SY | **SYNCHRONISATION** (transversal) | ⚠️ validé 1× live | `Events/OrderStatusChanged.php`, Outbox listeners, `BroadcastableOrder.php`, soketi | SYNC_CONTRACT.md |
| SEC | **SÉCURITÉ / RBAC / isolation** (transversal) | ⚠️ sentinels verts | `BranchScope.php`, Spatie perms, Sanctum kiosk:order | BranchScopeCoverageSentinelTest, FormRequestAuthzDrift |

Standalone (hors central, NE PAS wirer) : mobile RN + web `/Downloads/web` (carte blanche owner, validés séparément).

---

## §2 — L'ARMÉE (superviseur central + sous-agents parallèles)

```
                    ┌─────────────────────────────────────┐
                    │   SUPERVISEUR CENTRAL (le cerveau)    │
                    │  agents/00-SUPERVISOR-CENTRAL.md      │
                    │  • lit CE fichier comme tableau bord  │
                    │  • dispatch + contrôle TOUS           │
                    │  • possède la synchro globale         │
                    │  • verdict §10 continue/heal/block    │
                    │  • boucle audit jusqu'au vrai vert    │
                    └───────────────┬─────────────────────┘
        ┌───────────┬───────────┬───┴───────┬───────────┬───────────┐
   ┌────▼────┐ ┌────▼────┐ ┌────▼────┐ ┌────▼────┐ ┌────▼────┐ ┌────▼────┐
   │ SYNC    │ │ DB+HIST │ │ VISUAL  │ │ FISCAL  │ │ SECURITY│ │ 5×SYSTEM│
   │ ctrl 01 │ │ ctrl 02 │ │ capt 03 │ │ tkt 09  │ │ rbac 10 │ │ 04..08  │
   └─────────┘ └─────────┘ └─────────┘ └─────────┘ └─────────┘ └─────────┘
```

### Roster (chaque agent = 1 fichier de mission dédié, contexte/mémoire massif)
| Agent | Fichier mission | Rôle | Tools |
|-------|-----------------|------|-------|
| **00 SUPERVISEUR CENTRAL** | `agents/00-SUPERVISOR-CENTRAL.md` | Orchestre, contrôle, synchronise, rend le verdict, boucle jusqu'au vert | All (Agent, Bash, Read, Edit, Skill) |
| 01 SYNC | `agents/01-SYNC-CONTROLLER.md` | Synchro temps-réel cross-surface borne↔caisse↔KDS↔OSS↔dashboard | Bash, Read, Playwright |
| 02 DB+HIST | `agents/02-DB-HISTORICAL-CONTROLLER.md` | Intégrité DB, historique, chaîne NF525, gap-free, gestion données | Bash(mysql,artisan), Read |
| 03 VISUAL | `agents/03-VISUAL-CAPTURE-ANALYST.md` | Capture CHAQUE effet/transaction, analyse vue client + vue opérateur | Playwright, Read |
| 04 POS | `agents/04-SYSTEM-POS-CAISSE.md` | POS/Caisse E2E visuel+technique+interface+fonctionnel | Bash, Read, Playwright |
| 05 KIOSK | `agents/05-SYSTEM-KIOSK-BORNE.md` | Borne complète + WIZARD composeur | Bash, Read, Playwright |
| 06 KDS | `agents/06-SYSTEM-KDS.md` | Écran cuisine cycle complet | Bash, Read, Playwright |
| 07 OSS | `agents/07-SYSTEM-OSS.md` | Écran statut client | Bash, Read, Playwright |
| 08 ADMIN | `agents/08-SYSTEM-ADMIN-DASHBOARD.md` | Dashboard, historique, stock, customers, CRUD | Bash, Read, Playwright |
| 09 FISCAL/TICKET | `agents/09-FISCAL-NF525-TICKET.md` | Ticket contenu + print-saga + Z/X-report | Bash, Read, Playwright |
| 10 SECURITY | `agents/10-SECURITY-RBAC.md` | RBAC, isolation branche, Sanctum, secrets | Bash, Read |

### Discipline de dispatch (PARALLEL_PROTOCOL.md)
- Les **audits read-only** = UN SEUL message, N appels Agent (parallèle, voies disjointes).
- **JAMAIS 2 implementers en parallèle** (write conflict).
- **QA Visual + RED Visual** = parallèle OK (read-only screenshots).
- Chaque sous-agent **persiste son rapport sur disque** (`reports/test-e2e/goal-100pct-2026-06-07/<round>/<agent>.json`) — le central synthétise depuis le disque (survit aux interruptions).
- Contrat de remontée : `[P0|P1|P2|P3] file:line — titre / reproduction / preuve / reco` (≤1500 mots/agent).

---

## §3 — LE CHECKLIST ABUSIF (maître) — 6 AXES × 2 PERSPECTIVES

> Pour CHAQUE système (S1..S5) ET chaque transversal, les 6 axes sont obligatoires.
> Chaque ligne doit finir ✅ PASS (preuve) / ⚠️ PARTIAL / ❌ FAIL / 🔒 owner.
> Détail exhaustif par système = dans le fichier de l'agent. Ici = la grille maître.

### AXE A — TECHNIQUE (backend / logique / contrats)
- A1 Chaque endpoint répond correctement (pas de 500, pas de HTML masqué sur /api/*).
- A2 Validation FormRequest + authz sur chaque mutation.
- A3 Idempotency sur chaque POST mutating (X-Idempotency-Key).
- A4 Calcul prix 100% backend (`PricingService` SSOT) — front n'envoie que id+qty+options.
- A5 `composition_snapshot` figé à la création, jamais réécrit.
- A6 0 régression : PHPUnit (665 Feature) + Vitest + sentinels VERTS réels.

### AXE B — INTERFACE (chaque écran, chaque bouton)
- B1 **CHAQUE bouton** de CHAQUE écran cliqué + effet vérifié (pas seulement le happy path).
- B2 États : normal / vide / erreur / chargement / désactivé — tous cohérents.
- B3 0 raw label, 0 i18n manquant (FR), 0 `undefined`/`NaN`/`0.00€` fantôme.
- B4 Navigation : breadcrumb, retour, deep-link, refresh page — sans casse.
- B5 Formulaires : submit, annuler, validation erreurs, double-submit guard.

### AXE C — VISUEL (capture analysée — agent 03)
- C1 Capture de CHAQUE effet/transaction/action (avant/après).
- C2 Analyse layout : responsive, pas de débordement, branding Cayenne intact.
- C3 Analyse **DOUBLE perspective** : (a) ce que voit le CLIENT (borne, OSS), (b) ce que voit l'OPÉRATEUR (caisse, KDS, admin).
- C4 Palette correcte (Cayenne #F4501E / #FFB800 / #1A1A1A ; light mode borne).
- C5 Lisibilité : tailles, contrastes (WCAG), zones tactiles.

### AXE D — FLUIDITÉ / UX (réalité d'usage)
- D1 Temps de réponse perçu (clic→effet < 1s sur actions clés ; pas de freeze).
- D2 Parcours réel complet sans blocage (commande→encaissement→cuisine→remise).
- D3 Reprise d'erreur : réseau coupé, paiement refusé, produit retiré, stock 0.
- D4 Pas de double-action accidentelle ; feedback visible à chaque action.

### AXE E — SYNCHRONISATION (agent 01)
- E1 Borne crée → apparaît caisse (encaissement) → KDS → OSS → dashboard, en temps réel.
- E2 Changement statut KDS → reflété OSS + tracker + dashboard (< quelques s, ou polling fallback).
- E3 Toggle stock admin → propagé caisse + borne + wizard en temps réel.
- E4 Dégradation : ws:6001 down → polling prend le relais (SYNC-WS-01), aucun event perdu.
- E5 Pas de double-comptage, pas d'event fantôme, ordre des events correct.

### AXE F — DONNÉES / DB / HISTORIQUE (agent 02)
- F1 **Séquence fiscale gap-free** (0 trou, 0 doublon) après TOUT le volume de test.
- F2 Chaîne HMAC `audit_logs` + `z_reports` intègre (append-only, `fiscal:verify-chain --all` OK).
- F3 **Historique** : chaque commande tracée (N° fiscal, origine, opérateur, montant, statut, date) — 0 numéro manquant.
- F4 Cash-trail NF525 complet (ouverture/mouvements/clôture tiroir), opérateur = caissier réel.
- F5 Intégrité référentielle : FK, pas d'orphelin, BranchScope sur 20 models, snapshot prix figé.
- F6 Rapports (ventes, Z, X, caisses quotidien) cohérents avec les données réelles.

### AXE G — PERSPECTIVE CLIENT vs OPÉRATEUR (transversal, agent 03 pilote)
- G1 **Client** : borne (parcours commande), OSS (suivi), ticket (lisible+légal).
- G2 **Opérateur** : caisse (encaissement+tiroir+Z), KDS (préparation+bump), admin (pilotage).
- G3 Chaque écran évalué « est-ce clair/utilisable pour CE rôle ? » — pas juste « ça marche ».

---

## §4 — CIBLES OBLIGATOIRES (gaps déjà connus — à clore impérativement)

Issus de `reports/test-e2e/validation-2026-06-07/ACCEPTANCE_CHECKLIST_100PCT.md` :

| Cible | Système | Sévérité | Agent |
|-------|---------|----------|-------|
| **Auto-print serveur ESC/POS absent de la branche** → merger+valider `feat/pos-printer-saga-autoprint` | TR | ❌ P0 (chemin choisi owner) | 09 |
| Legal SIRET/TVA non set sur devices + pas de `set-branch-legal` | TR | ❌ P0 | 09 + owner |
| 6 items `tax_id` NULL (5 Bols Gourmands + 1 Supplément) | S5/TR | ❌ P1 | 02 + 09 |
| Footer légal doit ≠ "TVA non applicable" (VAT-registered confirmé) | TR | 🔒 owner texte | 09 |
| Encaissement non-CASH : CARD/SumUp ref, Ticket-Resto, Mobile (ex-STUBS) | S1 | ⬜ | 04 + 09 |
| Remboursement (bouton Rembourser order-show) + log NF525 | S1/TR | ⬜ | 04 + 09 |
| Remise/coupon → TVA correcte (ex-P0 coupon VAT-10) | S1/TR | ⬜ | 04 + 02 |
| Z-report / clôture du jour (PDF = doc fiscal imprimé) | TR | ⬜ | 09 |
| **Kiosk WIZARD composeur** (sandwich/tacos/bols + sauces/options) | S2 | ⬜ | 05 |
| Stock toggle → sync temps réel caisse/borne/wizard | S5/SY | ⬜ | 01 + 08 |
| Loyalty gagner/échanger/consulter | S1/S5 | ⬜ | 04 + 08 |
| Admin CRUD (item/catégorie/client/user/permissions) | S5 | ⬜ | 08 |
| Écrans erreur kiosk (réseau/paiement-refusé/produit-retiré/menu-indispo) | S2 | ⬜ | 05 |
| Commande multi-items + intégrité composition_snapshot | S1/S2 | ⬜ | 04/05 + 02 |
| « 10 commandes » par sous-système (pas juste encaissement) | tous | ⬜ | 04..08 |

---

## §X — VAGUES DE CONVERGENCE (séquentiel par défaut ; parallèle read-only intra-vague)

| Vague | Scope | Parallélisme | Checkpoint (les 6, Axis 3 skill) |
|-------|-------|--------------|----------------------------------|
| **W0 Pré-vol** | backup branche + dump DB clone, baselines (PHPUnit count, audit_logs count+hash), confirmer :8766 + soketi + worker UP, légal set sur clone | séquentiel | baselines capturées, infra verte |
| **W1 Cartographie** | les 11 agents lisent leur mission + anchors, posent leur sous-checklist exhaustif | **parallèle read-only** | chaque agent a écrit son plan sur disque |
| **W2 FISCAL/TICKET** (priorité owner) | agent 09 : ticket contenu (les 2 origines, capturé visuel), merge+valider print-saga, Z/X-report, non-CASH, refund, discount→TVA, 6 items NULL VAT | séquentiel (fiscal-critique) | ticket 100% correct + auto-print prouvé + chaîne OK |
| **W3 DB+HISTORIQUE** | agent 02 : gap-free full-volume, chaîne HMAC, historique exhaustif, cash-trail, intégrité FK | parallèle avec W4 | F1-F6 ✅ |
| **W4 SYNC** | agent 01 : E1-E5 cross-surface live (multi-contexte navigateur), dégradation polling | parallèle avec W3 | E1-E5 ✅ |
| **W5 SYSTÈMES** | agents 04-08 : chaque système, 6 axes, CHAQUE bouton, 10 commandes, double perspective | parallèle (domaines disjoints) + agent 03 capture | par système : A-G ✅ |
| **W6 SÉCURITÉ** | agent 10 : RBAC, isolation, Sanctum, secrets, abus | parallèle read-only | SEC ✅ |
| **W7 Convergence finale** | superviseur : smoke complet + cross-surface E2E + frozen diff=0 + chaîne NF525 + RED-team global | séquentiel | 2 cycles P0+P1=0 findings identiques |

Checkpoint obligatoire fin de vague (skill Axis 3) : tasks PASS, frozen diff=0,
chaîne NF525 ok, gate visuel tiré, RED-team fait, BRAIN §2/§3 màj. Sinon vague NON fermée.

---

## §G — OWNER GATES (WHO / WHAT / WHERE)

| Gate | Description | WHO | WHAT | WHERE | Statut |
|------|-------------|-----|------|-------|--------|
| G1 | Régime TVA confirmé | Owner | "VAT-registered" ✅ répondu 2026-06-07 | ce GOAL §4 | ✅ LEVÉ |
| G2 | Chemin impression confirmé | Owner | "Auto-print serveur ESC/POS" ✅ répondu | ce GOAL §4 | ✅ LEVÉ |
| G3 | Texte footer légal (mention TVA correcte, pas "non applicable") | Owner | chaîne de texte officielle | branch config | PENDING |
| G4 | Valeurs légales réelles par device (SIRET/TVA E.DELICE) à appliquer en prod | Owner | exécuter set-branch-legal/seeder par machine | device DB | PENDING |
| G5 | Merge `feat/pos-printer-saga-autoprint` dans la branche (décision intégration) | Owner sign-off | merge + re-valide | commit tag | PENDING |
| G6 | IP imprimante réseau + test impression physique | Owner physique | photo/log impression | deploy report | PENDING (matériel) |
| G7 | Politique TVA emporter vs sur place (taux) + statut 6 items NULL | Owner | mapping item→taux | taxes config | PENDING |

Tant qu'un gate bloque une vague, le superviseur exécute les vagues NON bloquées en parallèle (Axis 5).

---

## §F — CRITÈRE DE FIN (DONE = 100%, pas « presque »)

Le GOAL est LIVRABLE uniquement quand TOUS sont vrais :
1. Chaque ligne du §3 (6 axes × 5 systèmes + 3 transversaux) = ✅ ou 🔒 documenté.
2. Chaque cible §4 = ✅ ou 🔒.
3. Convergence finale W7 : 2 cycles consécutifs **P0+P1 = 0** ET findings identiques.
4. Frozen-zone diff = 0 sur tout le range du GOAL.
5. Chaîne NF525 `fiscal:verify-chain --all` OK ; séquence gap-free ; historique 0 trou.
6. **Le ticket** : contenu prouvé correct (légal+TVA+opérateur+lignes+fiscal) ET chemin d'impression auto serveur prouvé en sim → sur matériel, SEULE l'impression papier reste.
7. PHPUnit + Vitest + E2E (specs `zz-*` + nouveaux) tous VERTS réels.
8. `ACCEPTANCE_CHECKLIST_100PCT.md` entièrement ✅/🔒.

**Tant que ce n'est pas atteint : NE PAS LIVRER. Boucler. Heal. Re-tester.**
Le superviseur ne rend JAMAIS un état « presque bon ».

---

## §R — RÉFÉRENCES
- Cold-start : `CONSTITUTION.md` → `PROJECT_BRAIN.md §2` → `SYSTEM_MAP.md` → `SYNC_CONTRACT.md` → `PARALLEL_PROTOCOL.md`
- Checklist source : `reports/test-e2e/validation-2026-06-07/ACCEPTANCE_CHECKLIST_100PCT.md`
- Harness : `reports/test-e2e/validation-2026-06-07/VALIDATION_REPORT_2026-06-07.md` + specs `tests/e2e/zz-*-2026-06-07.spec.js`
- Skills : `ultra-audit-profond` (pipeline tâche), `test-e2e` (audit dual-team), `lock-plan` (frozen), `verify-before-report`
- Frozen : `memory/reference_frozen_zones.md` · NF525 : CLAUDE.md §8
- Mémoire gaps : `memory/project_100pct_validation_gaps_2026-06-07.md`

---
**LANCEMENT :** dire `lance le GOAL` → le superviseur exécute W0 puis dispatch.
Fichiers agents : `plans/goal-100pct-2026-06-07/agents/00..10`.
