# Menu price sync — caisse/borne → frontends standalone (2026-05-30)

**Goal** : aligner web + mobile (standalone séparés) sur les MAJ caisse/borne, incluant la mise à jour des prix. Audit + structure + test après.

## SSOT
**DB MySQL `foodking` items table** (live, 45 items actifs status=5, last update 2026-05-30 19:40). Source canonique consommée par POS + Kiosk via `KioskMenuService`/`PricingService`. `config/menu.php` = STALE pre-reset (ne pas utiliser). Snapshot : `db-prices.tsv`.

## Méthode (advisor-validated)
1. Extraction DB live (`mysql --default-character-set=utf8mb4`).
2. Harness Node déterministe : load `window.LC.menu.items` des 2 trees, diff par NAME contre db-prices.tsv. **Test décisif = frontend-vs-DB** (pas mobile-vs-web — un sentinel mobile==web passe avec les 2 frontends faux).
3. Edits surgical. 4. Test : DB-parity + Preview MCP visual + arithmétique panier + adversarial 7-angles.

## 4 drifts corrigés (35 autres items déjà alignés)

| Produit | Caisse/DB | Frontend (avant) | Action |
|---|---|---|---|
| Sandwich Cayenne | **7,00 €** | 7,50 € | → 7,00 |
| Sandwich Classique | **6,50 €** | 7,00 € | → 6,50 |
| Tacos M (1 viande) | **6,90 €** *(owner 06-04)* | "Tacos M" 6,90 € | inchangé net (sync→revert) |
| Tacos L (2 viandes) | **8,90 €** *(owner 06-04)* | "Tacos L" 8,90 € | inchangé net (sync→revert) |
| Menu formule (addon) | **3,00 €** | 2,50 € | → 3,00 |

### ✅ DÉCISION OWNER RÉSOLUE — Tacos = 6,90/8,90 (M/L), 2026-06-04
**MISE À JOUR 2026-06-04 — flip du headline (supersession de la 1ʳᵉ passe).**
La 1ʳᵉ passe (2026-05-30) avait aligné les Tacos sur la DB caisse (8,50/11,50) avec un FLAG owner.
**L'owner a tranché 2026-06-04 : « Tacos M/L 6,90/8,90 € seul »** (= prix à la carte canonique).
→ Les Tacos ont été **revertés** dans les 2 frontends : noms **Tacos M / Tacos L**, prix **6,90 / 8,90** (base "seul").
Les 3 autres drifts (Sandwich Cayenne 7,00 · Classique 6,50 · Menu formule 3,00) **restent appliqués** (non concernés).

⚠️ **Divergence assumée vs DB** : la DB caisse/borne (items 26/27) porte **ENCORE 8,50/11,50** (noms "Tacos"/"Big Tacos").
Les frontends reflètent désormais le prix retail **owner-canonique** 6,90/8,90, **intentionnellement divergent** de la DB
stale **en attente de la décision caisse** (corriger la caisse vers 6,90/8,90, owner-gated — voir question owner ouverte).
Ne PAS re-synchroniser les Tacos vers la DB sans nouvelle instruction owner.

## Refs périmées nettoyées (au-delà des prix)
- Marquee + restaurant card + onboarding (mobile) + about/footer/5 legal HTML (web) : naming Tacos cohérent "Tacos M & L" (revert post owner 06-04)
- Wizard label formule rendu DYNAMIQUE (anti-drift futur)
- Note : la valeur demo `orders.js` "Tacos L 7,90" est restaurée telle quelle (mock historique = prix-payé-à-l'époque, non normalisé — P2 accepté)

## Preuves (test après — exigé owner) — état post-revert 2026-06-04
- **DB-parity (honnête)** : MOBILE & WEB = **42 matched · 0 price-mismatch · 2 divergences intentionnelles** (`Tacos M` 6,90 / `Tacos L` 8,90 ≠ DB `Tacos`/`Big Tacos` 8,50/11,50). ⚠️ **PAS 44/0/0** : les 2 Tacos divergent volontairement de la DB stale (décision owner) en attente du correctif caisse.
- **Visual Preview MCP** : Tacos M **6,90 €** (SIGNATURE) · Tacos L **8,90 €** (TOP) rendus sur **mobile ET web** ; Sandwich Cayenne 7,00 € conservé
- **Arithmétique panier** (`priceFor()`, identique 2 trees) : Tacos M seul 6,90 · Tacos M+Menu **9,90** · Tacos L seul 8,90 · Tacos L+Menu **11,90**
- **Sentinel mobile↔web** : **bit-identical GREEN** (41 slugs — les 2 trees bougent ensemble vers 6,90/8,90)
- **0 spec mobile-e2e** ne dépend des valeurs changées
- Note historique : l'adversarial 7-angles "GO 0 P0/P1" de la 1ʳᵉ passe validait l'alignement-DB 8,50/11,50 — **superseded** par la décision owner 06-04 (le prix canonique est 6,90/8,90, pas la DB).

## Isolation
2 commits séparés (mobile sur `heal/cms-pr1-quickwins-2026-05-18` ; web sur repo `/Users/1millnonstop/Downloads/web` main). Frontends restent STANDALONE, 0 wireup API. 0 frozen-zone touch, 0 backend touch.

## Backlog non-bloquant (adversarial P2/P3)
- orders.js demo line_totals historiques (prix d'époque — correct, immutabilité)
- web/orders.jsx "Big Cayenne XL" suffixe (pré-existant, hors scope)
- DB `item_extras` polluée de doublons périmés (NF525-deferred, DB-side, n'affecte pas frontend qui lit items 801-809 propres)
