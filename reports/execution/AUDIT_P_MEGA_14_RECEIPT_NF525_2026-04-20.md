# AUDIT P-MEGA-14 — Receipt rendering NF525

**Date** : 2026-04-20
**Mode** : READONLY (Phase C du cycle W5)
**HEAD** : 781232fb4 (worktree V14_T07 sur `OrderDetailsResource.php` IGNORÉ — baseline lue depuis le fichier versionné)
**Subagent** : `explore` (readonly very thorough)

## 0. Synthèse exécutive (5 lignes max)

Il n'existe pas de `ReceiptRenderingService` : le ticket est rendu côté **Vue** (HTML + `vue3-print-nb`) et, pour la borne, en **ESC/POS** dans `kioskPrinter.js`. La chaîne **HMAC** et la **séquence fiscale** existent au niveau **audit_logs / orders**, mais le **ticket imprimé** n'expose ni numéro fiscal, ni QR NF525, ni **DUPLICATA**, ni export **JET/PIAF**. Le breakdown TVA par taux est partiellement couvert via `OrderDetailsResource::buildTaxLines()` et `ReceiptComponent.vue`, mais **pas** sur `PosOrderReceiptComponent.vue`. Baseline `AUDIT_POS_110_NF525_READINESS_2026-04-19.md` reste globalement valide pour journal/Z/archive ; les **exigences ticket client NF525** restent largement **non couvertes**.

## 1. Architecture rendering

| Couche | Rôle | Fichiers clés |
|--------|------|----------------|
| **Backend** | Pas de service dédié « receipt » ; exposition JSON commande | `app/Http/Resources/OrderDetailsResource.php` (agrégation `tax_lines` L62–L116) |
| **POS caisse** | Modal ticket HTML + impression navigateur | `resources/js/components/admin/pos/ReceiptComponent.vue` ; inclusion dans `PaymentComponent.vue` (import L104–L123) |
| **POS commandes (show)** | Ticket HTML **sans** bloc `tax_lines` | `resources/js/components/admin/posOrders/PosOrderReceiptComponent.vue` L73–L114 |
| **Kiosk** | ESC/POS + fallback `window.print()` | `resources/js/helpers/kioskPrinter.js` L74–L254 ; appel depuis `KioskConfirmationComponent.vue` L343–L371 |
| **Mail / compte** | Autres variantes de ticket | `OnlineOrderReceiptComponent.vue`, `FrontendOrderReceiptComponent.vue`, etc. |

**Formats de sortie** : HTML imprimé (POS) ; chaînes ESC/POS (kiosk/Electron) ; **pas** de PDF ticket dédié ni de pipeline Blade « ticket fiscal » identifié.

## 2. Immutabilité fiscale (snapshot)

- **Source des lignes** : les montants/TVA affichés viennent des **lignes `order_items`** via `OrderItemResource` (`app/Http/Resources/OrderItemResource.php` L31–L82) : priorité **`composition_snapshot`** puis repli JSON legacy.
- **Tests immutabilité snapshot** : `tests/Feature/OrderItemCompositionSnapshotTest.php` (docblock L19–L32 : invariant « snapshot source of truth pour reprint ») — vérifie colonne, cast, préférence API, non-mutation après renommage DB.
- **Risque résiduel** : si `composition_snapshot` est absent (anciennes commandes), le ticket peut refléter des libellés **re-lus** depuis le catalogue ; les montants ligne restent ceux persistés sur `order_items` au moment de la vente (à valider parcours SSOT).

## 3. Fiscal counter NF525

- **Service** : `app/Services/Fiscal/FiscalSequenceService.php` — `next()` L57–L104 : `Cache::lock` + transaction + `lockForUpdate` sur `MAX(fiscal_sequence_no)` ; monotone par `branch_id`.
- **Attribution** : `app/Services/OrderService.php` L884–L894 (POS) assigne `fiscal_sequence_no` avant `save()`.
- **Exposition API ticket** : **aucun** champ `fiscal_sequence_no` dans `OrderDetailsResource.php` (L16–L66) — le **ticket UI ne peut pas afficher** le numéro de séquence fiscal sans extension.
- **HMAC** : chaîne **`audit_logs`**, pas « un hash par ticket papier » — `app/Services/Fiscal/AuditLogService.php` L15–L28, L70+ (`hash_hmac` SHA-256). Clé : `config/fiscal.php` L16–L31 (`FISCAL_AUDIT_SECRET`, rotation par branche possible L24–L29).

## 4. Marqueur DUPLICATA

- **Aucune** occurrence fonctionnelle de marqueur **DUPLICATA**, compteur de réimpression, ou événement d'audit dédié « receipt.reprint » dans le périmètre ticket analysé.
- **Conséquence** : une réimpression (y compris reprint kiosk depuis cache `kioskReceiptPersistence.js` L15–L31) **ne distingue pas** l'original d'une copie sur le papier.

## 5. Breakdown TVA par taux

- **Backend** : `OrderDetailsResource::buildTaxLines()` L78–L116 — groupe par `(tax_type, tax_rate, tax_name)` ; `base_ht` = somme `(total_price - tax_amount)` ; `tax` = somme `tax_amount`.
- **UI POS paiement** : `ReceiptComponent.vue` L113–L129 — lignes par taux avec `base_ht_currency` et `tax_currency`.
- **UI POS détail commande** : `PosOrderReceiptComponent.vue` **n'inclut pas** `tax_lines` (totaux globaux seulement L73–L112) — **écart fiscal** vs `ReceiptComponent`.
- **Test** : `tests/Feature/PosReceiptTaxLinesTest.php` — groupement multi-taux et somme des taxes L112–L114.
- **Limite** : pas de ligne **TTC par taux** explicite dans `tax_lines` (seulement HT + montant TVA ; TTC reconstiturable).

## 6. Variations + extras

- **API** : `OrderItemResource` résout variations/extras depuis snapshot (`resolveVariationsForApi` / `resolveExtrasForApi` L63–L82).
- **ReceiptComponent (POS)** : affiche `item_variations` et `item_extras` L63–L77 (noms ; pas de prix unitaire détaillé par extra sur chaque ligne).
- **PosOrderReceiptComponent** : modèle **instruction** multi-lignes + taxe L51–L67 ; **pas** le même schéma que `ReceiptComponent` (deux UX divergentes).
- **Kiosk ESC/POS** : `kioskPrinter.js` L108–L125 — concatène nom + prix ligne ; `instruction` tronquée à `RECEIPT_WIDTH - 4` (L121) ; **pas** d'arborescence variation/extra détaillée.

## 7. Mention eat-in / takeaway

- Présente via **type de commande** : `ReceiptComponent.vue` L157 (`order_type` / `orderTypeEnumArray`) ; même logique dans `PosOrderReceiptComponent.vue` L119–L120.
- **Pas** de libellé légal distinct « sur place / à emporter » au-delà des traductions `label.delivery` / `takeaway` / `dining_table` — à valider conformité rédactionnelle NF525.

## 8. QR code sécurisé

- **Aucun** QR « ticket fiscal NF525 » dans `kioskPrinter.js` ni dans les composants ticket admin analysés.
- QR existants ailleurs : tables/menu (`DiningTableService`, seeders) — **hors preuve fiscale ticket**.

## 9. Coordonnées légales

- Ticket POS : `ReceiptComponent.vue` L17–L20 — `company.company_name`, `branch.address`, `branch.phone` depuis le store.
- `BranchResource.php` L16–L33 : **pas** de SIRET, RCS, ni numéro TVA intracommunautaire exposés en API standard.

## 10. Archive / export

- **Commande** : `app/Console/Commands/FiscalArchiveCommand.php` L45–L52 — zip avec `z_reports`, `orders`, `audit_logs` + manifest (L104–L115) ; **format propriétaire JSON/zip**, pas JET/PIAF DGFiP (aligné `VERIFY_08_FISCAL_NF525_Z_OPEN_2026-04-20.md`).
- **Scheduler** : `app/Console/Kernel.php` L18–L44 — **aucun** `foodking:fiscal:archive` planifié (gap opérationnel confirmé dans la littérature interne du repo).

## 11. Imprimante hardware

- **Kiosk** : `kioskPrinter.js` L9–L15, L193–L240 — ESC/POS subset ; fallback navigateur ; `reportPrinterFailure` L260–L268 poste vers `frontend/kiosk-event` (best-effort).
- **Pas** de driver Star/Epson nommé côté JS — délégué au bridge Electron (`kioskHardware.js`).
- **Buffer overflow** : pas de garde explicite sur taille des lignes ; troncature partielle sur instructions (L121).

## 12. Tests existants — couverture

| Zone | Fichiers |
|------|----------|
| TVA ticket (API) | `tests/Feature/PosReceiptTaxLinesTest.php` |
| Snapshot composition | `tests/Feature/OrderItemCompositionSnapshotTest.php` |
| Séquence fiscale | `tests/Feature/Fiscal/FiscalSequenceTest.php`, `OrderFiscalSequenceSchemaTest.php`, `PosOrderBL1WireInTest.php` |
| Chaîne HMAC audit | `tests/Feature/Fiscal/AuditLogHashChainTest.php`, `AuditLogConcurrencyTest.php` |
| Archive | `tests/Feature/Fiscal/FiscalArchiveTest.php`, `FiscalArchiveMemoryBoundedTest.php` |
| Front kiosk | `tests/js/kioskPrinter.spec.js` |

**Absence** : tests « DUPLICATA », « QR ticket », « ticket affiche fiscal_sequence », « parité ReceiptComponent vs PosOrderReceiptComponent ».

## 13. Dette technique identifiée (NIVEAU SÉVÉRITÉ)

| ID | Sévérité | Description | Fichier:ligne |
|----|----------|-------------|----------------|
| F-14-01 | **Critique** | Aucun marqueur DUPLICATA ni traçabilité réimpression ticket | *gap* |
| F-14-02 | **Critique** | Pas de QR / payload vérifiable sur le ticket — exigence visée P-MEGA-14 non satisfaite | `kioskPrinter.js` (aucune section QR) |
| F-14-03 | **Haute** | Numéro séquence fiscal non exposé au client ticket via `OrderDetailsResource` | `app/Http/Resources/OrderDetailsResource.php:16-66` |
| F-14-04 | **Haute** | Ticket « show commande POS » sans breakdown TVA par taux | `PosOrderReceiptComponent.vue:73-112` vs `ReceiptComponent.vue:113-129` |
| F-14-05 | **Haute** | Export JET/PIAF officiel absent ; zip interne seulement | `FiscalArchiveCommand.php:104-115` ; V08 repo |
| F-14-06 | **Moyenne** | Pas de schedule archive automatique | `app/Console/Kernel.php:18-44` |
| F-14-07 | **Moyenne** | Coordonnées légales complètes (SIRET/TVA UE) non modèle/API | `BranchResource.php:16-33` |
| F-14-08 | **Moyenne** | Kiosk thermal : pas TVA détaillée, date/heure locale navigateur, pas chaîne HMAC ticket | `kioskPrinter.js:74-179` |
| F-14-09 | **Basse** | Montant en lettres / « exactement » absent du template | `ReceiptComponent.vue` (totaux formatés seulement) |

## 14. Risques fiscaux concrets

- **Contrôle DGFiP** : absence de **DUPLICATA** sur rééditions, absence de **preuve ticket standardisée** (QR/signature ticket) = risque de **rejet de preuve** ou **mise en demeure** selon dossier.
- **Certification logiciel** : l'architecture actuelle sépare **preuve comptable** (audit_logs, Z) du **rendu ticket** — sans pont explicite, l'organisme peut exiger **traçabilité bout-en-bout ticket ↔ journal**.
- **Contentieux TVA** : divergence d'affichage **Payment vs Show** (`tax_lines`) = risque de **ticket non conforme** selon l'écran utilisé pour imprimer.

## 15. Recommandations correctives (impact LOC + zones)

1. **Unifier** les templates `ReceiptComponent` et `PosOrderReceiptComponent` (ou factoriser un `BaseReceipt`) — **~80–150 LOC** Vue.
2. **Étendre** `OrderDetailsResource` avec `fiscal_sequence_no` (et champs affichage légaux si colonnes existent) — **~20–40 LOC** PHP + migration si champs manquants côté `branches`/`companies`.
3. **DUPLICATA** : compteur `receipt_print_count` + flag UI + **événement `AuditLogService`** « receipt.reprint » (ne pas casser la chaîne HMAC existante) — **backend + front**.
4. **QR ticket** : endpoint ou payload signé (HMAC dédié ou dérivé) + rendu raster/ASCII imprimable — **nouveau module** (non trivial).
5. **JET/PIAF** : commande dédiée référencée roadmap interne (P13) — hors quick fix.

## 16. Tests sentinelles à créer

- PHPUnit : réponse `pos-order/show` contient `fiscal_sequence_no` + cohérence avec `orders` table.
- PHPUnit : même commande → deux rendus `ReceiptComponent` vs `PosOrderReceiptComponent` **même `tax_lines`** (snapshot DOM ou JSON).
- Vitest : `buildEscPosReceipt` inclut section DUPLICATA quand `isDuplicate` (à définir).
- Feature : `reprint` incrémente compteur + écrit `audit_logs` action fixée.

## 17. Décisions humaines requises (input GATE_BRIEF)

1. Le **ticket papier** doit-il porter le **même numéro** que `fiscal_sequence_no` ou un **numéro ticket séparé** ?
2. **QR** : URL publique de vérification DGFiP / prestataire, ou **vérif offline** uniquement ?
3. **DUPLICATA** : obliger mention **dès la 2e impression** ou dès toute impression hors « première » ?
4. Périmètre **kiosk** : doit-il être **certifié** au même titre que le POS, ou hors scope ?
5. **Données légales** (SIRET/TVA) : stockage au niveau **company** ou **branch** pour multi-tenant ?

---

**Référence baseline** : `reports/review/AUDIT_POS_110_NF525_READINESS_2026-04-19.md` — toujours pertinent pour **journal / Z / archive zip** ; **insuffisant** pour **ticket client NF525** (écart confirmé par ce audit).
