# ADVERSARIAL VERDICT — Vague E cross-surface (Round 1, dispute-2026-06-12)

Superviseur adversarial. Verdicts de sévérité rendus APRÈS lecture multimodale réelle des PNG,
inspection DOM/console/network, re-grep file:line, et re-vérification live ciblée.
Document INCRÉMENTAL — sections ajoutées au fil de l'analyse.

## Statut: EN COURS (incremental)

---

## FINDINGS CONFIRMÉS

### E-ADV-1 — **P0 CONFIRMÉ** — Promo borne affichée au client mais JAMAIS persistée (intégrité numérique cross-écran + cross-surface)
- **Catégorie**: numeric_integrity (#11) + silent business failure.
- **Lecture visuelle faite (Read multimodal)**:
  - `E10-03-cart-apres-promo.png`: bloc vert « ✓ Code promo BORNEAUDIT5 appliqué (−1,50 €) », ligne « Code promo BORNEAUDIT5 -1,50 € », **Total 0,00 €**, CTA « Valider ma commande 0,00 € ».
  - `E10-04-payment-counter.png`: « TOTAL À RÉGLER : **0,00 €** ».
  - `E10-05-cash-instruction.png`: « Montant à régler **1,50 €** » — le client voit 0,00 € puis 1,50 € sur deux écrans consécutifs de la même borne.
  - Run 2 (4531): `E12-02` Total 5,00 € / `E12-03` 5,00 € / `E12-04` **10,00 €**.
- **DB recoupée**: orders 4518 discount=0/total=1.50 ; orders 4531 discount=0/total=10.00 ; kiosk_promos.uses_count=0 après 2 applications.
- **Root cause re-greppée** (verify-before-report, voir section GREP): `OrderQuoteService.php` ne passe jamais `kiosk_promo_code` au pricing ; seul `PricingPreviewService` (hors chemin quote/create) l'applique.
- **Verdict**: P0. Le client paie PLUS que ce que l'écran de paiement a affiché. Sur cette branche release, défaut démontré bout-en-bout. (Heal existant sur `heal/ultra-audit-w4` NON mergé ici = régression de branche, mais la branche release est celle auditée.)

---

## DISPUTES DU FINAL_REPORT 2026-06-11 (à compléter)

(en cours)

## GATES DÉJÀ ARBITRÉS REVUS (non re-comptés)

(en cours)
