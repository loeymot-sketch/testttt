# GATE_BRIEF P-MEGA-20 — K-6 branch_mismatch enforcement

**Date** : 2026-04-20  
**Sub-cycle** : W8.A  
**Audit source** : `reports/execution/AUDIT_P_MEGA_20_BRANCH_MISMATCH_BASELINE_2026-04-20.md`  
**Type** : HARD GATE (security + auth-adjacent)  
**Moteur EXECUTE recommandé** : `foodking-complex-implementer` (GPT-5.4)  
**Effort estimé** : ~93 LOC prod + ~120-180 LOC tests + 2 specs (refactor isolation + nouveau spoofing)  
**Auto-remediation** : DÉSACTIVÉE par défaut (zone critical)

---

## Problème

`KioskEventController::store()` utilise `$request->input('branch_id', $machine?->branch_id)` pour le champ `branch=` dans `ActionLog.details`. Si le client envoie un `branch_id` forgé, le log reflète le forgé au lieu de la branche serveur (lue depuis `KioskMachine`). Pas d'élévation de privilège (l'`user_id` reste celui du token), mais **corrélation SOC faussée** et **pas de signal `security` structuré** sur les écarts.

Le canal Monolog **`security`** existe déjà (`config/logging.php`) mais n'est pas utilisé.

---

## Solution proposée

1. Lire `serverBranchId = (int) KioskMachine::where('user_id', $user->id)->first()->branch_id`
2. `details` : afficher **toujours** `branch=<serverBranchId>` (autoritaire)
3. Si `claimedBranchId` ≠ `serverBranchId` → `Log::channel('security')->warning(...)` avec clés stables : `event=kiosk.branch_mismatch`, `server_branch_id`, `claimed_branch_id`, `user_id`, `machine_id`, `route`, `request_id`
4. Méta forensic dans `ActionLog.meta` (ou champ dédié) avec `branch_id_claimed`
5. Aligner `KioskEventBranchIsolationTest` (assertions actuelles cristallisent l'ancien comportement)
6. Créer `KioskEventBranchSpoofingTest` (2 cas minimum : match + mismatch)

---

## Décisions business requises

### D1 — Sémantique HTTP sur mismatch

- **A.** **200 maintenu** (observabilité only) — recommandation orchestrateur ✅
- B. **422 breaking** — bloque la requête, force la borne à se recaler

**Recommandation** : A (200 + log security). Permet de capturer les bornes mal configurées sans casser l'opérationnel pendant la transition. Si après 2 semaines logs vides → migration vers B possible dans cycle ultérieur.

### D2 — Format ActionLog.details

- **A.** Garder format `branch=<id>` (compatibilité parsing legacy ; juste valeur autoritaire) ✅
- B. Renommer `branch_server=<id>` (sémantique claire ; risque casse parsing ops)

**Recommandation** : A. Coordonner avec ops si parsing existant.

### D3 — Périmètre routes touchées

- **A.** Strictement `KioskEventController` (2 routes : `/kiosk-event` + `/kiosk/event`) ✅
- B. Étendre à TOUTES les routes kiosk acceptant `branch_id` (Menu, Pricing, Promo, Upsell, Loyalty)

**Recommandation** : A pour ce cycle. B = audit/cycle suivant car ces controllers utilisent déjà `KioskMachine` correctement (constat audit § 1.1).

---

## Risques résiduels

- Parsing legacy `branch=` peut casser → mitigation = communication ops avant déploiement
- Volume logs `security` si front bugué envoie `branch_id` forgé en masse → prévoir `sample` / rate limit (cycle ultérieur)
- Spoofing `branch_id` sur **création commande** (`FrontendOrderService`) NON couvert (gated W5) → audit séparé requis

---

## Sentinelles requises

| Test | Couverture |
|------|------------|
| `KioskEventBranchSpoofingTest::test_match_no_security_log` | Token A + `branch_id=A` → pas de log `security`, `branch=A` dans details |
| `KioskEventBranchSpoofingTest::test_mismatch_logs_security_and_uses_server_branch` | Token A + `branch_id=B` → log `security` warning + `branch=A` (autoritaire) |
| Refactor `KioskEventBranchIsolationTest` | Aligner attentes : `branch=<serverBranchId>` au lieu de `branch=<forgéId>` |
| Couverture 2 routes : `/kiosk-event` ET `/kiosk/event` | Réplication sur les deux endpoints |

---

## Décision attendue

- [ ] D1 : A (200 + log) ou B (422)
- [ ] D2 : A (`branch=`) ou B (`branch_server=`)
- [ ] D3 : A (KioskEventController only) ou B (toutes routes kiosk)
- [ ] Validation effort 93 LOC prod + ~150 LOC tests
- [ ] Validation moteur `foodking-complex-implementer`
- [ ] Auto-remediation : DÉSACTIVÉE confirmée

**Statut** : PRÊT POUR DÉCISION HUMAINE
