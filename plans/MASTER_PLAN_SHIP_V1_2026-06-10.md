# MASTER PLAN — SHIP V1 (tout le système, HORS wizard/composer)

**Date:** 2026-06-10 · **Statut:** ⏸️ PLAN-ONLY superviseur (attend GO owner) · **Périmètre:** tout sauf le wizard (mission confiée à l'agent release/v1)
**Sources synthétisées (rien de réinventé):** verdict superviseur `reports/audit/supervisor-2026-06-10/SUPERVISOR_VERDICT_AND_PLAN_2026-06-10.md` (P-0→P-6) · `reports/review/STRUCTURE_REVIEW_2026-06-10.md` (topologie + collision) · `plans/GOAL_CLIENTS_NEXT_BEST_2026-06-10.md` (C-1→C-4) · mémoire cloud-deploy OVH (pièges connus).

## §0 — LA THÈSE (1 phrase)
Le projet est à **une chaîne d'intégration + un batch de décisions owner + un push** d'être réellement livré ; tout effort hors de ce chemin critique est du polish sur du non-déployé.

## §1 — DÉFINITION MESURABLE DE « V1 SHIPPED » (le DONE qui compte)
1. La box OVH exécute `release/v1` (gap 204 commits = 0) ; `fiscal:verify-chain --all` = CHAIN OK **sur la prod**.
2. Une commande borne ET une commande caisse réelles passent en prod (panier→encaissement→KDS→Z) sans erreur.
3. Le ticket s'imprime sur la SAGA physique (ou gate IMPRIMANTE explicitement reporté).
4. Le site de commande répond en HTTPS sur un domaine réel ; l'app mobile s'installe (PWA) — ou gates PUBLISH explicitement reportés.
5. Zéro placeholder légal non signé ; fidélité identique sur toutes les surfaces clientes.
6. BRAIN §2 + tag `v1.0-shipped` + ce plan coché.

## §2 — TROIS ACTEURS, TROIS COULOIRS (parallélisme sans collision)

### Couloir A — Session RELEASE (active, worktree release-v1) : P-1→P-5 du verdict
- A1 = P-1 : compléter la branche unique — **interlock #1 : merger `goal/cms-gestion-2026-06-10-spine` AVANT tout travail composer frozen** (GATE-W6 déjà livré là — sinon double patch pos-wizard.js) ; conflits attendus : i18n ×5 (union), routes/api.php (additif), bundles (NE PAS merger les bundles → rebuild) ; + `heal/mobile-update-2026-06-10` (reco superviseur : MERGER — anti-fragmentation ; le déploiement backend ne sert pas `mobile/`, risque nul) ; + fix `source_ref` (patch P-0 sauvegardé) ; ⛔ jamais `heal/dashboard-redeep` (stale).
- A2 = P-2 : LA preuve manquante — `npm ci && npx mix --production` + PHPUnit complet + Vitest complet **sur l'arbre intégré**, sentinelles vertes, frozen-diff 0, **transcripts commités**.
- A3 = P-5 (post gates) : push → `production` → CI → deploy.sh. **Pré-vol obligatoire** : (a) `deploy.sh:99` — inventorier les hot-patches opérateur sur la box AVANT le reset --hard ; (b) diff `.env` prod vs nouveaux flags requis (composer flag reste FALSE) ; (c) plan de rollback = tag pré-déploiement + dump DB prod.
- A4 post-go-live : smoke prod (les 2 commandes réelles §1.2), supervisor/workers/queues OVH (reste connu du déploiement 06-06), monitoring /health.

### Couloir B — Session CLIENTS (à lancer) : C-1→C-4 + publication
- B1 = C-1 fidélité redeem unifiée (décision owner D6 en tête, reco modèle mobile 100 pts=1 €).
- B2 = C-2 LCEN : page de saisie owner 29 champs (pré-remplie SIRET/TVA) → injection scriptée → re-validation.
- B3 = C-3 publication : web → vhost nginx OVH + TLS (playbook préparé, exécution = D8) ; mobile → PWA installable offline (manifest+SW, Lighthouse ≥90) ; **sortir les apps du Babel-in-browser/unpkg** (build prod léger — P2 connu du verdict).
- B4 = C-4 wireup-prep V2 : doc mapping miroir→API + tableau des divergences de nommage owner-locked (à trancher AVANT câblage V2, D9).
- Validation : triade pilote+adversaire ×2 cycles par wave, états AUTHENTIFIÉS inclus (leçon du jour).

### Couloir C — OWNER : UN SEUL batch de décisions (page HTML locale, pattern Wave-Polish Q1-Q14)
| # | Décision | Défaut recommandé | Conséquence du défaut |
|---|---|---|---|
| D1 | **PUSH-1** : autoriser push release→production | OUI (après A2 vert) | rien ne se livre sans ça |
| D2 | **DATA-1** : restaurer DB locale fiscale propre (45 items, purge 63 étrangers, E.DELICE) | OUI | NF525 prouvable localement |
| D3 | mobile-update dans release ? | OUI (merge) | anti-fragmentation |
| D4 | Seed Grande/Cheddar (**active CAISSE-01** — l'under-bill frites réel ticket #2155) | OUI | sinon le défaut de facturation persiste |
| D5 | Flips flags : `FK_POS_WIZARD_COMPOSER_AWARE` (G-4, dé-risqué par LOCK-W6) · `FEATURE_WIZARD_PER_ITEM_DEMO` (G-5) | au choix (différable V1.0.X) | features CMS item-level restent inertes |
| D6 | Fidélité redeem : modèle unique | A (mobile, continu) | divergence client visible |
| D7 | LCEN : fournir les 29 données légales | remplir la page B2 | site non publiable légalement |
| D8 | DNS + hébergement web (`commande.lecayenne.fr` ?) + go PWA | OUI | clients invisibles |
| D9 | Tableau nommage/prix miroirs vs DB (Tacos 6,90/8,90 vs 8,50/11,50) | trancher 1 fois | bloque le wireup V2, pas la V1 |
| D10 | Imprimante : IP SAGA + branchement (ou report explicite) | fournir IP | ticket réel impossible |

## §3 — INTERLOCKS (les règles qui évitent la re-fragmentation)
1. **Toute nouvelle session démarre par** : lire BRAIN §2 + `git rev-list --left-right --count <ma-base>...release/v1-2026-06-10` — la release est désormais LA référence, plus la spine.
2. **1 branche = 1 worktree = 1 session** ; jamais `git add -A` ; commits chemins explicites ; bundles jamais mergés, toujours rebuildés.
3. Frozen : uniquement via LOCK_*.md (mécanisme hook = message du commit précédent) ; pos-wizard.js appartient à l'agent wizard après merge de cms-gestion.
4. Mutations e2e : uniquement `foodking_e2e` (ports dédiés par session : release=8766, clients mobile=8097/web=8096, cms=8767).
5. Après D2 : interdiction de `php artisan test`/migrate hors worktree vérifié (.env.testing) — DEVDB-GUARD reste la loi.

## §4 — RISQUES MAJEURS (registre court)
R1 deploy.sh `reset --hard` écrase un hot-patch opérateur → inventaire pré-vol A3.a. · R2 schéma DB **prod** incomplet (distinct du dev cassé) → vérifier avant migrate (P-3). · R3 bundles mergés au lieu de rebuildés → écrans blancs prod. · R4 disque local 94% → purger worktrees lanes mergées (owner) avant gros builds. · R5 TCC ~/Downloads (web repo) — la session actuelle lit/écrit OK, surveiller. · R6 deux sessions sur le même worktree (collision connue) → §3.2.

## §5 — SÉQUENCE & EFFORT (estimation séance-agent)
1. **Maintenant** : Couloir A1-A2 (1 séance release) ‖ préparation page décisions C (0,5 séance).
2. **Owner** : batch D1-D10 (30 min owner).
3. **Puis** : A3 push+deploy (0,5 séance, fenêtre calme) ‖ B1-B2 (1 séance) → B3 publication (0,5 séance après D7/D8).
4. **Post-go-live** : A4 smoke+impression (avec D10) · B4 doc V2 · dettes P2/P3 divulguées (V1.0.X : UNI-03, sentinel ratchet 66, /health soketi, MADV-2).

## §F — RÈGLE FINALE
Aucun nouveau GOAL de feature tant que §1 n'est pas coché. Production-shipped ou rien. **PLAN-ONLY — attend le GO owner (et le batch D1-D10).**
