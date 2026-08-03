# POS INVARIANTS & GATES

**Version.** 2026-04-18 (rév. 2026-04-20 — V8 #2 alignement avec `scripts/check-invariants.sh` post V5 #2 + V8 #1)
**Scope.** S'applique à toutes les vagues POS-A, POS-B, POS-9.1 → POS-9.10.

---

## 1. Invariants non-négociables (hérités CLAUDE.md + spécifiques POS)

### 1.1 Hérités du projet

- **SSOT pricing.** Aucun prix lu du payload (POS, kiosk, web). `PricingService::calculateOrder()` est la source unique.
- **`branch_id` serveur.** Toujours `$user->branch_id`, jamais `$request->input('branch_id')`. Défense en profondeur (FormRequest + Service + BranchScope).
- **`OrderStateMachine::apply()`.** Toute transition de status passe par la machine. Aucun `$order->update(['status' => ...])` direct.
- **`DB::afterCommit()`.** Tout dispatch d'event après commit uniquement.
- **EventContract V1.** Enveloppe stricte (`version, type, aggregate_id, aggregate_type, branch_id, correlation_id, occurred_at, payload`). `assertEnvelopeValid()` obligatoire avant broadcast.
- **Pas de stats client dynamiques.** Jamais de "X% des clients", "bestseller". `is_chef_pick` statique admin-flag uniquement.
- **RGPD.** Tout tracking marketing = opt-in explicite. Ops (logs, healthcheck) = legitimate interest documenté.

### 1.2 Spécifiques POS

- **Permissions Spatie avant action sensible.** Cancel après PAID / refund / discount > 10 % / ré-ouverture Z / création staff → check permission dédié + log audit immuable.
- **Audit log immuable.** Table `audit_logs` ou `order_audit_events` : toute action sensible y atterrit. INSERT only, jamais UPDATE/DELETE.
- **Z fiscal conforme NF525 / loi Finance 2018 anti-fraude TVA.**
  - Numérotation séquentielle sans trou.
  - Signature cryptographique (HMAC chaîné) de chaque clôture.
  - Export archivable 6 ans.
  - Aucune suppression, aucune modification rétroactive.
- **Tiroir-caisse.** Chaque ouverture = event loggé (staff, motif, timestamp, solde théorique). Écarts comptabilisés en fin de journée.
- **Multi-tenders.** Un `Order` peut avoir N `Payment` records (cash + card + loyalty_burn). Somme `payments.amount` = `order.total` (invariant SQL check possible).
- **Idempotency POS.** Double-submit caisse (bouton cliqué 2x, TPE retry) → même commande (clé `X-Idempotency-Key` scopée `(branch_id, key)`).
- **Refund partiel.** Possible uniquement avec motif + permission + log. Impact stock : libération optionnelle (item déjà consommé ? décision manager).
- **Amend order (modifier commande post-création).** Autorisé avant DELIVERED. Chaque modification = event + ligne audit. Prix recalculés SSOT.
- **Allergens visibles caissier.** `Item.allergens` exposé dans `ItemResource` admin. Pivot `item_allergen` source unique.
- **TVA cascade par order_type.**
  - Sur place (dine-in) : 10 % resto standard, 20 % alcool.
  - À emporter (takeaway) : 5.5 % food, 20 % alcool.
  - Règle métier tranchée une fois pour toutes dans `PricingService`.
- **Arrondis.** Round half away from zero en EUR (conforme DGCCRF). Stocker en `cents` (int), afficher en € avec 2 décimales.

## 2. Gates par vague

### POS-A (audit)

- [ ] 4 sous-rapports parallèles livrés.
- [ ] Rapport consolidé >= 50 findings priorisés.
- [ ] Check-list 15 invariants répondue ligne à ligne.
- [ ] Section recouvrement avec kiosk explicite.
- [ ] `tasks/phase9-pos/FINDINGS_POS_TRACKER.md` créé.

### POS-B (plan)

- [ ] 10 vagues POS-9.1 → POS-9.10 définies.
- [ ] Dépendances explicites (DAG vérifiable).
- [ ] Gate commun identique au kiosk (Vitest + PHPUnit + build < 27 s).
- [ ] Durée totale < 10 jours ouvrés (sinon escalade humaine pour priorisation).
- [ ] Estimation ressources : quels items peuvent être parallélisés ?

### POS-9.1 (stop-the-bleed POS)

- [ ] Registre findings mis à jour : 100 % P0 en status=verified.
- [ ] Verifier indépendant 100 % RESOLVED.
- [ ] CI verte (PHPUnit + Vitest + Playwright existants).
- [ ] Build prod < 27 s.
- [ ] Zone shared touchée → lock posé + handoff envoyé à Track A.
- [ ] Rapport `RUN_POS_9_1_<DATE>.md` avec evidence.

### POS-9.2 → POS-9.9

- [ ] Même gate que POS-9.1.
- [ ] Respect du séquencement SYNC_PROTOCOL §3 (backend shared = séquentiel avec kiosk).
- [ ] Aucun invariant §1 violé (vérifié par grep dédiés avant merge).

### POS-9.10 (build + rapport + handoff)

- [ ] Rapport final consolidé : diff total lines-of-code, screenshots POS, sorties tests, couverture.
- [ ] `CROSS_TRACK_STATUS.md` 100 % items status=closed.
- [ ] Handoff `tasks/handoff/POS_PHASE_9_HANDOFF.md` pour Track C E2E.

> **2026-04-20 — Migration progressive vers `scripts/check-invariants.sh`** : les invariants 1, 2, 3, 4, 5, 6 ci-dessous (et leur évolution) sont maintenus dans le script unique. Les `grep` listés dans cette §3 restent valides comme cheat-sheet rapide, mais en cas de divergence, **le script fait foi**. Mises à jour majeures : V5 #2 (durcissement 4/6), V7 #1 (analyse Item/Category), V8 #1 (pattern event() helper).

## 3. Grep de vérification à lancer avant chaque merge

```bash
# SSOT pricing violé ?
grep -rn "->input('price')\|->input('total')\|\$request\['price'\]" app/Http/Controllers/Admin/Pos/ app/Services/OrderService.php

# branch_id lu du payload ?
grep -rn "->input('branch_id')\|\$request->branch_id" app/Http/Controllers/Admin/

# écriture directe status ?
grep -rn "->update(\[\s*'status'" app/ --include="*.php" | grep -v OrderStateMachine

# dispatch avant commit ? (SSOT: scripts/check-invariants.sh invariant 4/6)
# Couvre 3 patterns (FQN + short-name + event() helper) sur 6+ fichiers.
# Mis à jour V5 #2 (FQN + short-name), V8 #1 (event() helper).
bash scripts/check-invariants.sh -v 2>&1 | sed -n '/4\/6 App/,/^==>/p'

# EventContract bypass ?
grep -rn "broadcast(" app/Events/ | grep -v "buildEnvelope\|assertEnvelopeValid"

# audit log absent ?
grep -rn "OrderCancel\|OrderRefund\|applyDiscount" app/Services/ | grep -v "AuditLog\|audit_log"
```

Ces commandes sont attendues dans tout rapport de vague. Si une retourne un résultat suspect → investigation obligatoire avant merge.

## 4. Références documentaires

- `docs/ARCHITECTURE.md` §4 POS.
- `docs/BUSINESS_RULES.md` §7 Paiements, §9 TVA, §12 Refund.
- `docs/AUTHZ_MATRIX.md` rôles & permissions.
- `docs/ORDER_FLOW.md` transitions valides.
- `docs/GATES_DOCTRINE.md` principes gates.
- 10 briefs `tasks/audits/AUDIT_POS_*` déjà écrits.
