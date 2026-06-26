# 00 — DISCIPLINE OPÉRATOIRE (le règlement strict de la mission)

> Lu AVANT toute vague. Le GOAL (`../GOAL_TEST_E2E_ALL_SYSTEMS_2026-06-26.md`) est l'index ;
> ce fichier est le **protocole de boucle abusive page-par-page** + l'armée d'agents.
> Chaque agent junior suit CE fichier ; le superviseur (cerveau) ne dévie jamais.

---

## 0. PRINCIPE CARDINAL

On ne teste pas « l'interface jolie ». On teste **le système entier, page par page** :
le texte, le côté technique, la logique, l'architecture, le raisonnement, la
**synchronisation entre tous les systèmes**, et surtout **la psychologie de
l'utilisateur réel** — client (borne/web/mobile), commerçant (caisse/central),
cuisinier (KDS). Une page n'est « verte » que lorsqu'un agent adversaire ne
trouve PLUS rien à disputer, deux cycles de suite.

**3 lentilles obligatoires sur CHAQUE page** (selon le système) :
- 🧑 **CLIENT** — « est-ce bien pensé pour lui ? où se perd-il, se frustre-t-il, paie-t-il trop, abandonne-t-il ? »
- 🧑‍🍳 **CUISINIER** — « lit-il SANS ambiguïté quoi préparer ? une erreur de lecture = commande client ratée. »
- 🧑‍💼 **COMMERÇANT** — « fait-il confiance aux chiffres ? un employé peut-il abuser ses droits / frauder / se tromper ? »

---

## 1. BOUCLE PAGE-PAR-PAGE (les 9 étapes, sans raccourci)

Pour CHAQUE page/surface listée dans le fichier-système courant :

1. **CAPTURE** — Playwright live (serveur local, DB `foodking_e2e`). Screenshot
   + snapshot DOM + console + network. **Read** l'image (le superviseur VOIT).
2. **FAN-OUT AUDIT (même étape, parallèle)** — dispatch en UN seul message les
   spécialistes de la matrice §4 selon le type de page : planifient ET auditent
   ET disputent SIMULTANÉMENT. Chacun écrit ses findings sur disque (§5 schéma).
3. **LENTILLE PSYCHOLOGIE** — 1 agent dédié joue l'utilisateur réel (client /
   cuisinier / commerçant) et conteste le parcours, pas juste le pixel.
4. **SYNTHÈSE + VERIFY-BEFORE-REPORT** — le superviseur grep CHAQUE `file:line`
   cité ; tout finding non reproductible = **REJETÉ** (anti-hallucination §3).
5. **HEAL (TDD, scope-minimal)** — l'implémenteur écrit le test qui pingle le
   défaut AVANT le fix, puis corrige. Frozen-zone → STOP + gate (§6).
6. **RE-TEST technique** — PHPUnit filter + Vitest filter + `git diff --stat`
   frozen = **0 ligne**. Rouge → retour étape 5 (max 3 boucles).
7. **RE-CAPTURE + DISPUTE ADVERSAIRE** — QA-Visual re-capture, RED-Visual
   ré-analyse INDÉPENDAMMENT (anti biais de confirmation).
8. **CONVERGENCE** — la page est verte quand **2 cycles consécutifs donnent
   P0+P1 = 0 ET le MÊME jeu de findings** (garde anti-flake). Sinon, reboucler.
9. **PAGE SUIVANTE** — on n'avance JAMAIS sur une page non-convergée. 3 boucles
   sans converger → protocole « bloqué » (§7).

---

## 2. RÈGLES DE REJET (un seul déclencheur = REJET + heal)

| Déclencheur | Action |
|---|---|
| Label brut visible (`kiosk.X`, `Label.foo`, `0undefined`, `null+phone`) | REJET — heal + re-capture |
| Layout cassé sur un viewport testé | REJET — heal |
| Erreur console navigateur | REJET — root-cause puis heal |
| 1 ligne de diff frozen-zone | REJET — `lock-plan` + gate OU revert |
| Message anglais user-facing (mandat FR ADR-007) | REJET — heal |
| Prix non-FR (`€7.90` au lieu de `7,90 €`) côté user | REJET (sauf frozen pos-wizard → gate) |
| Prix affiché ≠ prix backend SSOT (fantôme-upcharge) | REJET ou ESCALADE si frozen |
| P0/P1 RED-team non traité | REJET — heal jusqu'à 0 P0/P1 NEW |
| Acceptance sans chemin de test nommé | REJET le plan — réécrire |
| « presque bon » / « good enough » | REJET — production-perfect ou bloqué |
| Chaîne NF525 hash changé (delete/truncate) | REJET + **escalade humaine immédiate** |
| 2 cycles aux findings DIFFÉRENTS | NON convergé — reboucler |

**Convergence = 2 cycles consécutifs P0+P1=0 ET findings identiques.**

---

## 3. ANTI-HALLUCINATION (règle #1, non-négociable)

- Tout `file:line` / produit / route / config cité = **vérifié dans le vrai code**
  (grep/Read), sinon écrit « à vérifier » — JAMAIS inventé.
- ⛔ **JAMAIS inventer un produit** : si absent de la DB `items` / du seeder
  `OwnerMenuUpdate20260623Seeder.php`, il n'existe pas (pas de « Box Familiale »).
- Tout finding P0/P1 d'un sous-agent DOIT inclure : `file:line` + repro
  (curl/DB-query/DOM-extract) + evidence. Sinon **REJETÉ, non surfacé**.
- `withoutGlobalScopes()` retire AUSSI le SoftDeletingScope → toujours
  `whereNull('deleted_at')` pour l'état actif réel (leçon 2026-06-24).
- Lire le **`composition_snapshot` réel en DB**, jamais l'aperçu wizard (l'aperçu
  ment — défaut fantôme-upcharge prouvé).

---

## 4. ARMÉE D'AGENTS — rôles + matrice fan-out

| Rôle | Type subagent | Outils | Mission |
|---|---|---|---|
| Architect | general-purpose / Plan | read-only | patterns, cohérence layers, dépendances |
| Security | general-purpose | read-only | auth, RBAC, isolation branche, secrets, IDOR |
| UX/A11y | general-purpose | read + Playwright | WCAG 2.1, ARIA, focus, cibles ≥44px, contraste |
| DBA | general-purpose | read | schema, FK, N+1, BranchScope, snapshot |
| SRE/Sync | general-purpose | read | Outbox, soketi, poll fallback, dégradation |
| Implémenteur | general-purpose / code-editor | Edit+Write+Bash | TDD, fix scope-minimal (JAMAIS 2 en // sur même fichier) |
| RED-team | general-purpose | read-only | conteste, réfute, cherche le contournement |
| QA-Visual | general-purpose | read + Playwright | capture + analyse screenshot |
| RED-Visual | general-purpose | read | ré-analyse les captures QA, dispute |
| 🧠 Psychologie | general-purpose | read + Playwright | joue client/cuisinier/commerçant réel |

### Matrice fan-out (quels rôles tirent par type de page)

| Type de page | Arch | Sec | UX | DBA | Sync | Impl | RED | QA-V | RED-V | Psy |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Front visuel (Vue/JS UI) | x | x | x | . | . | x | x | x | x | x |
| Backend logique (service/ctrl) | x | x | . | x | . | x | x | . | . | . |
| Cascade sync (Outbox/Echo/poll) | x | x | . | x | x | x | x | . | . | . |
| Fiscal NF525-adjacent | x | x | . | x | . | x | x | . | . | . |
| Data/seeder (menu mobile/web) | . | . | x | x | . | x | x | x | . | x |
| E2E cross-surface (Borne→KDS→OSS) | x | x | x | x | x | x | x | x | x | x |

### Discipline de dispatch
- **Spécialistes read-only = UN SEUL MESSAGE, N appels Agent** (parallèle, ~3 min).
- **Implémenteur = JAMAIS en parallèle d'un autre implémenteur** (conflit d'écriture).
- **QA-Visual + RED-Visual = parallèle OK** (read-only sur captures).
- **RED-team = TOUJOURS après le commit de l'implémenteur, AVANT de déclarer DONE.**
- **Workflow dynamique** : pour une vague large, pipeline (find→verify→heal) plutôt
  que barrière ; loop-until-dry (2 rounds sans finding neuf) sur la découverte.

---

## 5. CONTRAT DE REPORTING (chaque sous-agent écrit sur disque)

Le superviseur synthétise depuis le DISQUE (survit aux interruptions), pas du contexte.

**Chemin** : `reports/test-e2e/all-systems-2026-06-26/<systeme>/<round>/<role>.md`

**Schéma par finding** :
```
[P0|P1|P2|P3] <file>:<line> — <titre 1 ligne>
  repro: <commande/clic exact>
  evidence: <screenshot | erreur console | nom de test>
  lentille: <client|cuisinier|commerçant|technique>
  reco: <fix scope-minimal proposé>
```
Plafond : ~1200 mots/agent (synthèse gérable).

---

## 6. FROZEN ZONES + NF525 (gates durs)

**FROZEN strict no-touch** : `public/js/pos-wizard.js`, `public/css/pos-wizard.css`,
`resources/views/admin-pos-v4.blade.php`.
**FROZEN auditable + gate** : `PaymentComponent.vue`, `PosV5TrancheRow.vue`,
les 3 composants kiosk (`KioskWizardComponent/KioskAppComponent/KioskUpsellComponent`).
**FROZEN backend** : `PricingService`, `FiscalSequenceService`, `ZReportService`,
`AuditLogService`, `BranchScope`, `IdempotencyKeyMiddleware`, `OrderStateMachine`.

- Toute édition frozen → **STOP**, skill `lock-plan`, contre-signature owner,
  commit séparé. Sinon → audit read-only + heal HORS-frozen (ex. le bug viande
  fut résolu non-frozen via bridge `ItemComponent.vue` + `master.blade.php`).
- **NF525** : 100 % prix backend `PricingService::calculateOrder` ; snapshot figé
  jamais réécrit ; séquence fiscale gap-free ; chaîne HMAC append-only. À chaque
  clôture de vague touchant le fiscal : `SELECT count(*), MAX(current_hash) FROM
  audit_logs` inchangé ou append-only.

---

## 7. WAVE-CHECKPOINT + INTERRUPT + BLOCAGE

**Checkpoint fin de vague (6 points, tous requis)** :
- [ ] Toutes les pages de la vague convergées (ou fail baseline documenté + raison)
- [ ] Frozen-diff de la vague = 0 (`git diff --stat <début>..HEAD -- <frozen>`)
- [ ] NF525 chain inchangée/append-only (si vague fiscale)
- [ ] Gate visuel tiré pour chaque page front (screenshots Read + analysés)
- [ ] Dispute RED terminée ; nouveaux P0/P1 healés ou différés avec raison écrite
- [ ] `PROJECT_BRAIN.md §2/§3` mis à jour + commit checkpoint

**Interrupt (limite usage / fin session)** : commit WIP (`wip(<vague>): partial`),
écrire `reports/test-e2e/all-systems-2026-06-26/INTERRUPT_<vague>_<ts>.md` (dernier
SHA vert, dernière page tentée + statut, page suivante, reports déjà sur disque),
mettre à jour BRAIN §2. **Reprise** : lire le manifeste, `git status`, re-smoke la
dernière page, continuer.

**Blocage (3 boucles heal sans converger)** : STOP, spawn agent `Plan` (« pourquoi
le heal 3× a échoué ? pivot ou escalade ? »), écrire `STUCK_<vague>_<ts>.md`,
surfacer à l'owner 4 options (A accepter-documenté / B pivot archi / C différer
V1.0.X / D gate humain). Ne PAS auto-choisir.

---

## 8. EVIDENCE (rien n'est « fait » sans preuve)

Acceptable : tests verts (PHPUnit + Vitest filtrés) · frozen-diff 0 · NF525 CHAIN OK
· flows Playwright verts · **screenshots analysés via Read** (pas juste capturés) ·
console/network propres · transition d'état confirmée · réconciliation panier ==
encaissement == fiscal. Manquant → jamais feindre la certitude ; downgrade →
heal / block / human.
