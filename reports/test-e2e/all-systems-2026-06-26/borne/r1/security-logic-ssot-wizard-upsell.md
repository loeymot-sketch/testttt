# BORNE r1 — Lentille SÉCURITÉ / LOGIQUE / SSOT — Wizard composition + upsell (Sub 2.b)

DB live `foodking_e2e` (READ-ONLY, 0 mutation, 0 commande réelle).
Méthode: Read fichiers ancrés + `PricingPreviewService::preview` en tinker (sans effet
de bord) + forge service-layer + PHPUnit/Vitest filters + DB queries.

## VERDICT : POSTURE SSOT/SÉCURITÉ FORTE — germes adversaires RÉFUTÉS par preuve

Les 4 germes "cœur" du plan (fantôme-upcharge, viande_count Méga→4, suppléments +0,90
invisibles, double-tap) sont tous **réfutés avec preuve live**. 2 findings réels de
faible sévérité (P3) + 1 piège enum documenté pour anti-hallucination.

---

## PIÈGE ENUM (anti-hallucination — diffuser à tous les agents borne)

`app/Enums/Status.php:7-8` → **`ACTIVE = 5`, `INACTIVE = 10`** (INVERSÉ vs intuition).
- `items.status=5` = ACTIF ; `=10` = inactif. `item_variations.status=5` = ACTIVE.
- Mon 1er passage a conclu à tort « toutes variations inactives » (status=5). FAUX :
  toutes ACTIVES, menu sain. Tout finding « X inactif/absent » DOIT être revérifié
  contre cet enum sinon faux P0 (discipline §3). Repro: `SELECT status,COUNT(*) FROM
  items WHERE deleted_at IS NULL GROUP BY status` → 48 actifs(5) / 11 inactifs(10).

---

## RÉFUTATIONS PROUVÉES (NON reportées comme bugs)

### R1 — Fantôme-upcharge « prix wizard ≠ backend » : RÉFUTÉ (reconcilie 0,00 €)
`PricingPreviewService::preview` (tinker, branch 1) vs total local attendu :
- Tacos L#97 plain → backend 7,90 == local 7,90 (DELTA 0,00)
- Tacos L + « Viande supplémentaire »(extra 393,+2,50) + Cheddar(262,+0,90) → 11,30==11,30
- Cayenne#22 + « Viande supplémentaire »(extra 398,+2,50) → 9,90==9,90
Le « +2,50 » est un **vrai item_extras DB** (ids 392-399, group=supplement, price=2.5),
PAS une constante fantôme (ça = pos-wizard.js POS, hors-scope kiosk). La borne le
sérialise, le backend le facture identiquement. SSOT respecté.

### R2 — viande_count heuristique nom (Méga→4) : RÉFUTÉ (SSOT override prime)
`KioskMenuService.php:317-323` expose `viande_count` = nb attributs « Viande N »
visibles+actifs. Live (`KioskMenuService::build` tinker): Méga#104=2, Terminator#105=2,
Tacos L#97=2, Tacos M#26=1, Bols#41/45=1, Cayenne#22=0, Suprême#103=0.
`KioskWizardComponent.detectViandeCount:970` lit `item.viande_count>=1` AVANT
`viandeCountFromName:973` → Méga=2 (SSOT), jamais 4. Vitest `kioskTacosSize` 46/46.

### R3 — Suppléments +0,90 / +2,50 non-évidents : RÉFUTÉ
`KioskStepSupplementsComponent.vue:58-59` montre `formatPrice(supplement.price)`/ligne +
total étape :7-8 + prix aria-label :31. `KioskStepViandeComponent.vue:44-45` montre
`+formatPrice(viande.price)`. Surcoût visible AVANT panier. `formatPrice`→FR « 7,40 € »
(`currencyAmountFormat(7.40)='7,40 €'` vérifié tinker).

### R4 — Double-comptage « Viande supplémentaire » (viande+supplément) : RÉFUTÉ
`kioskExtrasPartition.js:51-65` : group='supplement' ∈ SUPPLEMENT_GROUPS → false (HEAL
v3.1 2026-05-14). Donc routée UNIQUEMENT vers supplements (:115), exclue de
`kioskViandeCatalogForItem:104`. Affichée 1 fois. Vitest partition 16/16 + viande 12/12.

### R5 — Attribut requis omis (Méga sans Viande 2) accepté : RÉFUTÉ au niveau HTTP
Forge service-direct `preview` Méga 1 viande (omit attr2 min=1) → ACCEPTÉ (8,00) car
`PricingService::assertVariationConstraints:408` n'inspecte que les attrs PRÉSENTS. MAIS
HTTP `/pricing/preview` (`PricingPreviewRequest.php:72-73`) et `/order` (`OrderRequest`)
lancent `MultiVariationConstraint::validateCollectionKeyedByItemIndex` (after-hook) qui
REJETTE l'attribut requis totalement omis (`MultiVariationConstraint.php:51-105`, HEAL
2026-06-24). PHPUnit `MultiVariationValidationTest` « preview rejects when required
attribute wholly omitted » PASS. Laxité service brut INATTEIGNABLE via HTTP (le
FormRequest est la porte). NON-exploitable → NON reporté.

### R6 — Forge payload / cross-item / injection : RÉFUTÉ (4 gardes prouvées)
Forge tinker `preview` : extra 393(TacosL) sur Cayenne#22 → THROW « Extra ID 393
n'appartient pas à l'article 22 » ; variation 361 cross-item → THROW idem ; extra fantôme
999999 → THROW « introuvable » ; 2 viandes attr1(max=1) → THROW « maximum 1 sélection ».
`PricingPreviewRequest.validated():91-92` whitelist stricte = `branch_id`/`price`/`total`
STRIPPÉS. `OrderRequest:94` → `branch_id` server-résolu du token KioskMachine (jamais
payload). `authorize()` → `tokenCan('kiosk:order'):83`. subtotal/discount nullable
recalculés backend :150-151. Isolation branche + scope token + SSOT prix OK.

### R7 — Upsell auto-skip timer trop court/intrusif : RÉFUTÉ
`KioskUpsellComponent.vue:125 AUTO_SKIP_SECONDS=30`, reset sur interaction (:210-211),
barre progression + countdown visibles, skip toujours dispo, empty→auto-skip gracieux
(:167-168). Pas de cul-de-sac.

---

## FINDINGS RÉELS

### [P3] app/Http/Controllers/Frontend/UpsellController.php:103-123 — legacyFallback ne filtre PAS la visibilité canal kiosk (comment ≠ code)
repro: `legacyFallback` = `where('is_upsell',1)->where('status',ACTIVE)` sans
`isVisibleOn('kiosk')`/`visible_on`, alors que commentaire :81-82 dit « scope channel
kiosk ». Idem `UpsellRuleService::suggest` (`app/Services/Kiosk/UpsellRuleService.php:68-71`)
filtre items suggérés par `status=ACTIVE` seul.
evidence: lecture code + DB `SELECT COUNT(*) FROM items WHERE is_upsell=1 AND status=5
AND deleted_at IS NULL`=0 ; `SELECT COUNT(*) FROM upsell_rules`=0.
lentille: client (théorique) / commerçant. Un item ACTIF mais non-visible borne flaggé
`is_upsell=1` apparaîtrait sur l'upsell borne → confusion + mismatch commande.
**Aujourd'hui INERTE** (0 règle, 0 is_upsell) → P3, pas P2.
reco: filtrer la visibilité canal kiosk dans `legacyFallback` ET `UpsellRuleService`
(`whereNull('visible_on')->orWhereJsonContains('visible_on','kiosk')`). Non-frozen.

### [P3] DATA foodking_e2e — Upsell borne DORMANT : 0 règle + 0 item is_upsell → écran upsell toujours vide
repro: `SELECT COUNT(*) FROM upsell_rules`=0 ; `SELECT COUNT(*) FROM items WHERE
is_upsell=1 AND status=5 AND deleted_at IS NULL`=0. → `fetchUpsellItems`→[] →
`loadSuggestions:167`→`skip('no_suggestions')`. Client ne voit jamais d'upsell ;
commerçant capte 0 revenu incitatif.
evidence: 2 COUNT DB ci-dessus.
lentille: commerçant. Pas un défaut code (auto-skip propre), trou de config data qui
annule une fonctionnalité revenue.
reco: (owner/data, escalade) seed `upsell_rules` (dessert si panier>10€, boisson si
Tacos) OU flagger 2-3 items `is_upsell=1` visibles borne. Config, pas code.

---

## EVIDENCE TECHNIQUE
- PHPUnit (filter): MultiVariationValidationTest(12)+PosKioskPricingParityTest(4)+
  KioskUpsellCategory+PricingPreview*+KioskQuoteIntegrity* → 22 passed.
- Vitest: kioskExtrasPartition 16 + kioskTacosSize 46 + kioskViandeCatalog 12 = 74 passed.
- Frozen: 0 ligne (audit READ-ONLY, aucun fichier modifié).
- Tinker preview (sans effet de bord): 3 reconciliations DELTA 0,00 + 7 forges (4 THROW
  attendus, 3 clamp/accept attendus).
