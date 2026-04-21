# GATE_BRIEF P-MEGA-14 — Receipt rendering NF525

**Cycle** : P_MEGA_W5_EATIN_TPE_RECEIPT_2026-04-20 — Phase D
**Source** : `reports/execution/AUDIT_P_MEGA_14_RECEIPT_NF525_2026-04-20.md`
**Niveau gate** : 🔴 **HUMAN_GATE NF525 + fiscal counter exposure**
**Décideur attendu** : Owner produit + responsable conformité fiscale + designer ticket

---

## Questions business à trancher (5)

1. **Le ticket papier doit-il porter le `fiscal_sequence_no` ?** Aujourd'hui : exposé en DB, jamais affiché client.
2. **Le QR code NF525 sur ticket est-il obligatoire ?** Aujourd'hui : absent.
3. **Le marqueur DUPLICATA sur réimpression est-il obligatoire ?** Aujourd'hui : absent.
4. **Le kiosk est-il dans le périmètre certification NF525 ?** Aujourd'hui : ambigu.
5. **Coordonnées légales (SIRET/TVA UE) au niveau company ou branch ?** Aujourd'hui : non exposées API.

## État actuel (résumé)

- Pas de `ReceiptRenderingService` centralisé — rendering éclaté Vue + ESC/POS.
- Chaîne HMAC + séquence fiscale OK au niveau audit_logs / orders, mais **pas exposée au ticket client**.
- `OrderDetailsResource.buildTaxLines()` correct ; affiché par `ReceiptComponent.vue` (POS payment), **pas** par `PosOrderReceiptComponent.vue` (POS show).
- Aucun marqueur DUPLICATA, aucun QR ticket, aucune section archive JET/PIAF.
- Kiosk thermique (`kioskPrinter.js`) sans TVA détaillée, sans HMAC, date locale navigateur (manipulable).

## Risques business concrets

| # | Scénario | Sévérité |
|---|---|---|
| 1 | Contrôle DGFiP demande preuve ticket → pas de QR ni numéro fiscal client → **rejet preuve** | 🔴 |
| 2 | Réimpression sans DUPLICATA → ticket peut être présenté comme original 2x → **fraude possible** | 🔴 |
| 3 | Ticket "show commande" (PosOrderReceiptComponent) sans tax_lines → **divergence avec ticket payment** | 🟡 |
| 4 | Audit certification : kiosk thermique sans HMAC → **certification kiosk impossible** | 🟡 |
| 5 | Pas d'export JET/PIAF → demande contrôle non satisfaite immédiatement | 🟡 |

## Options proposées

### Bloc α — Quick wins (zero-risk, RECOMMANDÉ EN PRÉ-FIX)
- α.1 : Unifier templates `ReceiptComponent` + `PosOrderReceiptComponent` via factor `BaseReceipt.vue` (~80-150 LOC)
- α.2 : Étendre `OrderDetailsResource` avec `fiscal_sequence_no` (champ déjà en DB, juste exposition) (~20-40 LOC + 1 test)
- α.3 : Tests sentinelles parité ticket (Vitest snapshot) (~50 LOC)

**Total α** : ~150-240 LOC, ZÉRO impact fiscal counter, ZÉRO impact HMAC. **Routine implementer OK**.

### Bloc β — Marqueur DUPLICATA (impact léger HMAC)
- β.1 : Compteur `receipt_print_count` sur orders + flag UI quand >1 (~30 LOC migration + 50 LOC service)
- β.2 : Événement `AuditLogService` action `receipt.reprint` (chaîne HMAC préservée) (~40 LOC)
- β.3 : Affichage "DUPLICATA" sur templates ticket si count > 1 (~20 LOC × 4 templates = 80 LOC)
- **Total β** : ~200 LOC, **HUMAN_GATE recommandé** car touche audit_logs

### Bloc γ — QR code ticket NF525 (lourd)
- γ.1 : Service `ReceiptQrSigner` (HMAC dérivé pour ticket signature) (~100 LOC)
- γ.2 : Endpoint public `/receipt/verify/{hash}` pour client scan (~80 LOC)
- γ.3 : Génération raster/ASCII QR pour ESC/POS (`qrcode-generator` lib) (~100 LOC + dependency)
- γ.4 : Templates ticket avec section QR (~50 LOC × N templates)
- **Total γ** : ~400-500 LOC + 1 dependency JS, **HUMAN_GATE absolu** — choix algo signature à valider

### Bloc δ — Coordonnées légales (admin + schema)
- δ.1 : Migration `branches.siret`, `branches.vat_intracom`, `branches.rcs` (ou companies si single-tenant) (~30 LOC)
- δ.2 : Admin UI saisie + validation format SIRET (~120 LOC)
- δ.3 : Affichage tickets (~40 LOC)
- **Total δ** : ~190 LOC, routine OK

### Bloc ε — Export JET/PIAF DGFiP
- ε.1 : Format spec officiel DGFiP (à valider expert)
- ε.2 : Service de génération + commande artisan + schedule (~250 LOC)
- ε.3 : Tests format (~80 LOC)
- **Total ε** : ~330 LOC, **HUMAN_GATE absolu** — algo et format à valider expert fiscal

## Recommandation orchestrator

**Phasing prioritaire** :
1. **Bloc α** (quick wins) — peut partir maintenant en routine implementer (~150-240 LOC, zero-risk)
2. **Bloc δ** (coords légales) — routine, schema additif sans risque
3. **Bloc β** (DUPLICATA) — gate humain pour valider impact HMAC, puis 1 cycle complex
4. **Bloc γ** (QR ticket) — gate humain absolu (algo signature + cinématique vérification client)
5. **Bloc ε** (JET/PIAF) — gate humain absolu (format DGFiP) + cycle complex

**Pré-fix immédiat envisageable** : α + δ = ~340 LOC, en parallèle de la review du gate.

## Tests sentinelles à créer AVANT fix

1. `test_pos_show_receipt_includes_tax_lines_matching_payment_receipt` (Feature, expected RED — F-14-04)
2. `test_order_details_resource_exposes_fiscal_sequence_no` (Feature, expected RED — F-14-03)
3. `test_thermal_receipt_includes_duplicata_marker_when_reprint` (Vitest snapshot, expected RED — F-14-01)
4. `test_thermal_receipt_includes_qr_code_section` (Vitest snapshot, expected RED — F-14-02)
5. `test_branches_have_siret_field_in_resource` (Feature, expected RED — F-14-07)

## Décision attendue (matrice)

| Question | Choix |
|---|---|
| Bloc α (quick wins) approuvé pré-fix ? | ☐ Oui ☐ Non |
| Bloc β (DUPLICATA) priorité ? | ☐ V1 ☐ V2 ☐ Backlog |
| Bloc γ (QR) priorité ? | ☐ V1 ☐ V2 ☐ Backlog |
| Bloc δ (coords légales) approuvé pré-fix ? | ☐ Oui ☐ Non |
| Bloc ε (JET/PIAF) priorité ? | ☐ V1 ☐ V2 ☐ Backlog |
| Kiosk thermique dans périmètre certif NF525 ? | ☐ Oui ☐ Non |
| Choix algo signature ticket QR ? | ☐ HMAC dérivé ☐ ECDSA ☐ Autre |
| Numéro ticket = fiscal_sequence ou séparé ? | ☐ Même ☐ Séparé |

## Impact LOC total

- α + δ (pré-fix routine) : ~340 LOC, 1 cycle
- α + β + δ : ~540 LOC, 2 cycles
- α + β + γ + δ : ~1000 LOC, 4 cycles
- Tout (α + β + γ + δ + ε) : ~1300 LOC, 5-6 cycles

## Zones touchées (selon scope)

- `app/Http/Resources/OrderDetailsResource.php`
- `app/Http/Resources/BranchResource.php`
- `app/Services/ReceiptRenderingService.php` (NEW si unification)
- `app/Services/Fiscal/AuditLogService.php` (read)
- `database/migrations/*` (NEW)
- `resources/js/components/admin/pos/ReceiptComponent.vue`
- `resources/js/components/admin/posOrders/PosOrderReceiptComponent.vue`
- `resources/js/helpers/kioskPrinter.js`
- `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue`
- `tests/Feature/Receipts/*` (NEW)
- `tests/js/receiptDuplicata.spec.js` (NEW)
- `tests/js/receiptQr.spec.js` (NEW)
