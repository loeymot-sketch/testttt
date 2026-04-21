# GATE_BRIEF P-MEGA-22 — NF525 readiness 4 piliers

**Date** : 2026-04-20  
**Sub-cycle** : W8.C  
**Audit source** : `reports/execution/AUDIT_P_MEGA_22_NF525_READINESS_BASELINE_2026-04-20.md`  
**Type** : HARD GATE décomposable en 4 sous-gates indépendants  
**Moteur EXECUTE recommandé** : `foodking-complex-implementer` (GPT-5.4) — réglementaire NF525  
**Effort estimé** : 130-800 LOC prod + 270-830 LOC tests selon scope retenu  
**Auto-remediation** : **DÉSACTIVÉE OBLIGATOIRE** (NF525 réglementaire, jamais d'auto-fix)

---

## Vue d'ensemble

4 piliers NF525 manquants identifiés. Chaque pilier est **indépendant** ; le gate peut être approuvé pilier par pilier.

| Pilier | Description | LOC prod | LOC tests | Risque | Recommandation |
|--------|-------------|----------|-----------|--------|----------------|
| **P1** | `verifyChain` Z pré-clôture | 40-90 | 80-150 | Bas | ✅ APPROUVER |
| **P2** | Schedule `foodking:fiscal:archive` | 10-30 | 40-80 | Bas | ✅ APPROUVER |
| **P3** | DUPLICATA marker admin POS | 80-180 (+30-60 si migration) | 100-200 | Moyen (conflit V14) | ⚠️ APPROUVER avec sous-gate schema |
| **P4** | Export JET XML DGFiP | 200-500+ | 150-400+ | **CRITIQUE** (spec TBD) | ❌ DEFER |

---

## Pilier 1 — `verifyChain` Z pré-clôture

### Problème
`ZReportService` chaîne `prev_hash` au close mais ne valide jamais l'intégrité de la **chaîne complète des Z** avant un nouvel `open()`/`close()`. Si un Z historique est tamponné silencieusement, le suivant chaîne sur une base corrompue sans signal.

### Solution
Ajouter `ZReportService::verifyChain(int $branchId): ?int` qui retourne le premier `sequence_no` corrompu ou null. Charge Z `status=CLOSED` ordonnés ; pour chaque : `verifySignature` + `prev_hash == signature précédent`. Réutilise `verifySignature` existant.

### Décision business requise

**D1 — Périmètre vérification**
- **A.** Toute la chaîne Z à chaque opération (O(N), simple, robuste) ✅
- B. Fenêtre glissante N derniers Z (perf, mais zone aveugle historique)
- C. Vérification asynchrone job nocturne + blocage seulement si dernier état KO (complexe ops)

**Recommandation** : A pour MVP. Réévaluer si latence devient gênante (>500ms à l'open()). Mitigation cycle ultérieur si nécessaire.

**D2 — Point d'accroche**
- A. Pre-`close()` uniquement
- B. Pre-`open()` uniquement
- **C. Pre-`open()` ET pre-`close()`** ✅ (ceinture + bretelles)

**Recommandation** : C.

**D3 — Comportement si chaîne corrompue détectée**
- A. **Bloquer hard** (Exception métier, ops doit intervenir) ✅ pour prod
- B. Mode dégradé en dev/test (log warning, ne bloque pas)
- C. A en prod, B en testttt (via env flag)

**Recommandation** : C. `phpunit.xml` ajoute `FISCAL_VERIFY_CHAIN_BLOCK=false` pour les tests qui veulent corruptions intentionnelles.

---

## Pilier 2 — Schedule `foodking:fiscal:archive`

### Problème
Commande `foodking:fiscal:archive` existe mais **non schedulée** dans `app/Console/Kernel.php`. Pas d'archivage automatique quotidien.

### Solution
```php
$schedule->command('foodking:fiscal:archive --branches=all --from=yesterday --to=yesterday')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->emailOutputOnFailure(config('fiscal.archive_alert_email'));
```

### Décision business requise

**D4 — Fréquence**
- **A.** Quotidien 02:00 (faible charge, rétention conforme NF525) ✅
- B. Horaire (over-engineering pour la plupart des cas)
- C. Manuel (non-conforme NF525 long terme)

**D5 — Périmètre branches**
- **A.** Toutes branches (boucle ou commande globale) ✅
- B. Une branche par instance schedulée

**D6 — Stockage**
- **A.** Local + sync nightly S3 (ops responsabilité) ✅ MVP
- B. S3 direct (config `archive_disk=s3`) — nécessite credentials

**D7 — Signature/scellement**
- A. ZIP + manifest JSON (état actuel) ✅ MVP
- B. ZIP signé GPG (cf. commentaire `config/fiscal.php` non implémenté) — différer cycle dédié

**Recommandation** : A/A/A/A pour MVP, sécurise la conformité légale. Hardening signature GPG = cycle dédié.

---

## Pilier 3 — DUPLICATA marker admin POS

### Problème
Aucun marqueur "DUPLICATA" sur les tickets admin POS réimprimés. NF525 exige que les copies de tickets soient identifiables visuellement.

### Conflit V14 confirmé
`ReceiptComponent.vue` est en `M` git status (worktree V14 non commité). Tout patch DUPLICATA collisionne.

### Solution
- UI : ligne `DUPLICATA` (i18n fr/en/ar) dans `ReceiptComponent.vue` quand `order.is_reprint === true` OU `order.receipt_print_count > 1`
- Backend : **NOUVELLE resource/endpoint** `POST admin/orders/{id}/receipt-print` (évite d'étendre `OrderDetailsResource` gated W5)
- Schéma : compteur `orders.receipt_print_count` (sous-gate migration)

### Décision business requise

**D8 — Source vérité réimpression**
- **A.** Colonne `orders.receipt_print_count` (simple, performant) ✅ — **active sous-gate G22-P3-SCHEMA**
- B. Table `order_receipt_prints` (audit trail riche, plus de migrations)
- C. Audit_logs uniquement (pas de migration mais query coûteux à chaque print)

**Recommandation** : A. Migration `add_receipt_print_count_to_orders` simple et robuste.

**D9 — Conflit V14**
- A. Merger V14 d'abord, ensuite EXECUTE P3
- **B.** Isoler DUPLICATA dans **sous-composant** ou **helper pur** (`buildDuplicataLine`) pour minimiser diff sur SFC ✅
- C. Bloquer P3 jusqu'à V14 mergé

**Recommandation** : B. Préserve indépendance + facilite merge V14.

**D10 — Code NF525 dans audit_logs**
- A. Logger code 155 (réimpression) / 156 (duplicata) — à valider expert métier
- **B.** Ne pas logger en audit_logs pour P3 MVP (différer cycle expert) ✅

---

## Pilier 4 — Export JET XML DGFiP

### Problème
**Spec officielle JET (logiciel de caisse certifié) NON identifiée** comme fichier téléchargeable stable. Les XSD impots.gouv concernent FEC/A47A (comptabilité), pas JET POS.

### Risque CRITIQUE
Coder un format XML sans spec figée = code non auditable, refonte garantie à chaque évolution certificateur. **R3 SPEC du plan W8 explicite ce risque.**

### Décision business requise

**D11 — Stratégie P4**
- A. Coder un format XML "best effort" basé sur lecture interne (risque refonte) ❌
- **B.** **DEFER P4** jusqu'à acquisition d'une spec contractuelle (organisme certif / LNE / revue conception) ✅
- C. Coder un format CSV/JSON intermédiaire utilisable comme base pour export manuel

**Recommandation** : **B**. Tracer P4 dans `tasks/audit-orchestration/` comme blocked-by-spec, ne pas coder.

---

## Sous-gates indépendants

| Sous-gate | Recommandation orchestrateur | Décisions clés |
|-----------|------------------------------|----------------|
| **G22-P1** | ✅ APPROUVER | D1=A, D2=C, D3=C |
| **G22-P2** | ✅ APPROUVER | D4=A, D5=A, D6=A, D7=A |
| **G22-P3** | ⚠️ APPROUVER + active **G22-P3-SCHEMA** | D8=A, D9=B, D10=B |
| **G22-P3-SCHEMA** | ⚠️ APPROUVER si D8=A | Migration `add_receipt_print_count_to_orders` |
| **G22-P4** | ❌ DEFER | D11=B (bloqué par spec TBD) |

---

## Plan EXECUTE recommandé (post-approval)

**Séquence** : P1 → P2 → P3 (séquentiel, jamais en parallèle car NF525)

| Étape | Sub-cycle | Effort cumulé | Vitest+PHPUnit attendu |
|-------|-----------|---------------|------------------------|
| 1 | P1 verifyChain Z | +90/-0 LOC + 5 tests | +5 PHPUnit |
| 2 | P2 schedule archive | +30/-0 LOC + 3 tests | +3 PHPUnit |
| 3 | P3 DUPLICATA + migration | +180/-30 LOC + 8 tests | +5 Vitest, +3 PHPUnit |
| (4) | P4 JET XML | DEFER | — |

**Total approuvé recommandé** : ~300 LOC prod + ~250 LOC tests + 1 migration (sous-gate).

---

## Risques transverses

- Latence `open()`/`close()` Z si chaîne très longue (mitigation D1=A acceptée si <500ms ; sinon migration vers D1=B cycle ultérieur)
- Disque local plein lors archive (P2) → monitoring ops séparé
- Conflit V14 ReceiptComponent.vue (P3) → mitigation D9=B (sous-composant)
- **Reglementaire** : si certificateur NF525 audite avant P4 livré, conformité partielle uniquement (P1+P2+P3 = lignes critiques couvertes ; P4 = nice-to-have)

---

## Décision attendue

Pour CHAQUE sous-gate :
- [ ] **G22-P1** : APPROUVER / REPORTER / REJETER + D1/D2/D3
- [ ] **G22-P2** : APPROUVER / REPORTER / REJETER + D4/D5/D6/D7
- [ ] **G22-P3** : APPROUVER / REPORTER / REJETER + D8/D9/D10
- [ ] **G22-P3-SCHEMA** : APPROUVER / REPORTER si D8=A
- [ ] **G22-P4** : DEFER (recommandé) ou conditions pour coder

**Statut** : PRÊT POUR DÉCISION HUMAINE (4 sous-gates indépendants)
