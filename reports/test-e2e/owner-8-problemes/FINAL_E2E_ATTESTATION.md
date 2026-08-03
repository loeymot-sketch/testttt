# ATTESTATION E2E FINALE — GOAL owner-8-problemes (2026-07-06)

HEAD `febe73b15` · serveur canonique :8766 · 3 surfaces pilotées en vrai flux cliqué + captures analysées + bytes ESC/POS décodés.

## Verdict global : GREEN — 3/3 surfaces, 0 P0/P1

### CAISSE — GREEN
- Menu+Boisson : 15 boissons « Incluse », commande réelle A0013/#5534 total **9,90 € figé** (subtotal/TVA/total), fiscal_sequence_no 2631, **2 lignes facturées (Cayenne 7,40 + Menu 2,50), 0 boisson facturée**, « BOISSON: Hawaï 33cl » en instruction.
- Oignon cuit : composition_snapshot = Salade+Tomate+Oignons cuits, cru absent (exclusivité) ; ticket O̲ souligné.
- Images viandes : 7/7 webp réels 200 (12-29 Ko), 0 pastille, 0 404.
- Impression : window.print jamais auto, POST /raw async, UI non gelée.
- Perf : tuiles webp Ko (pas PNG Mo), refetch /api/admin/item par ajout supprimé.
- Note client : stockée instruction, rendue KDS.

### CUISINE — GREEN (bytes décodés octet par octet)
- Notes : white-space pre-line (3 lignes), écho compo strippé, no-overflow paysage+portrait.
- Boissons : noms complets Hawaï/Fuze Tea/Coca-Cola/Sprite ; 13 boissons→drink, 3 desserts→pas drink (Tarte Daim=TAR).
- O̲ : écran U+0332 natif ; ticket `1B 2D 01 4F 1B 2D 00` (underline matériel sur le O seul), ordre S T O̲, ≤32 col.
- Layout 3-cartes : slice(0,3), pastille +1 exacte, codes 3 lettres, badges MENU inline — nouvelles lignes ne cassent rien.
- Parité ticket↔écran : symboles identiques.

### BORNE — GREEN (2 commandes vrai flux UI)
- Flux complet : idle→emporter→Tacos M wizard→panier→upsell→Plan B→A0015/#5537 order 201, 0 toast jaune, 0 crash.
- Oignon cuit : défaut OFF, exclusivité 2 sens, snapshot extra 416 à 0 €.
- **P1 guéri confirmé** : ticket #5537 « ** BOISSON: Hawaï 33cl » + KDS « · BOISSON: Hawaï 33cl ».
- Ticket = design caisse : 32 col, accents réels, « A REGLER EN CAISSE », 0 ligne paiement, orderId dans l'URL.
- « Hawaï 33cl » (trema), 0 « Fanta Hawai » DOM.

## Gates attestés à HEAD febe73b15
- Vitest **2244/0** (319 fichiers) · PHPUnit **3173/0** (2 incomplete, 30 skipped)
- Frozen diff `24e8a09c3..HEAD` = SEULS pos-wizard.js + KioskWizardComponent.vue (LOCK)
- NF525 `fiscal:verify-chain --all` = CHAIN OK ×4

## Observations non bloquantes (P3, divulguées)
- composition_snapshot.addons stocke le menu_full générique, pas l'identité boisson (elle vit dans instruction, sort correctement ticket+KDS ; boisson 0€ sans impact pricing/TVA — à noter si NF525 exige l'identité boisson dans le record figé).
- 403 /api/admin/kds-order/sync (garde cross-branch fallback polling, pré-existant) — polling 60s compense.
- Auto-print borne bridge-gated (n'a pas firé en headless sans pont, wiring correct).
- Label « ✕ Sans Oignons cuits » sur opt-in (frozen, non touché — décision owner).
