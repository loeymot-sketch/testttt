# Convergence Round 1 — Audit + test réel Web, parcours complet de chaque fonctionnalité

Dual-team : 7 vérificateurs de parcours (probes API LIVE + adversaire) ‖ navigateur réel (moi) + réfutation. 11 agents, 0 erreur. Base `d69a9ccc4` → 7 commits heal (`59d40e09b`..`9ccabef65`).

## Parcours PROUVÉS solides (probes live + navigateur)
- **CAISSE** (navigateur) : wizard Tacos M (viande 1/1 requise, crudités, sauce 1ère gratuite → total 6,90 € correct), remise 10 % → 6,21 € (motif obligatoire), reçu compo complet. Backend : fiscal_seq gap-free, pas de tiroir fantôme carte, sous-paiement bloqué, annulation payée gardée, idempotency.
- **BORNE** : quote==facturé, composition_snapshot figé + immuable (UPDATE→SQLSTATE 45000), quote signé/consommé-une-fois, ability token kiosk:order, rupture item/variation filtrée, branch/source serveur-autoritaires.
- **KDS+OSS / CROSS** : 1,90 € identique au centime sur 5 surfaces (file caisse=KDS=OSS=DB=ticket), fiscal_seq 2649 unique, chain NF525 OK 4 branches, double-encaissement garde 0 doublon.
- **GESTION admin** : RBAC AIRTIGHT — POS Operator 403 sur employee/administrator/change-password(account-takeover)/sales-report/transaction/item-edit/eod-pdf ; mass-assignment role/branch_id bloqué ; kiosk-token bloqué admin (401) ; EOD PDF OK ; exports sans crash.
- **STOREFRONT** : order web 11,20 € exact (pricing SSOT), IDOR protégé (422 own-order).

## Défauts CONFIRMÉS → HEALÉS+TESTÉS (7)
| # | Sév | Onde | Fix | Commit |
|---|---|---|---|---|
| 1 | **P1** | D catalogue+borne | détails produit branch-aware (cécité mid-wizard rupture par branche) | `20c645454` |
| 2 | **P1** | C cuisine | ticket+KDS distinguent Bol Frites/Bol Riz (BOL FRI/BOL RIZ) — cuisinier prépare la bonne base | `8311bd957` |
| 3 | P2 | B borne | tri catégories déterministe (sortBy chaîné — bug Wave Y laissé sur les catégories) | `59d40e09b` |
| 4 | P2 | G cross/caisse | ticket papier imprime la ligne RENDU (écran↔papier réconciliés) | `9710942ec` |
| 5 | P2 | D gestion | suppression catégorie compte TOUS les produits (fin orphelin réactivable) | `42de18487` |
| 6 | P2 | D gestion | ItemRequest exists sur item_category_id (rejette catégorie inexistante/soft-deleted) | `9ccabef65` |
| 7 | P3 | A caisse | message montant reçu formaté (« 7,00 € » au lieu de « 7.000000€ ») | `9ccabef65` |

**0 P1 restant.** Chaque heal a un test régression (rouge→vert). Frozen source 0 sur toute la plage.

## Défauts DISCLOSÉS (P2/P3 non-bloquants — severity-gate « disclose, don't loop »)
- **A-F1 (P2)** : ventilation Z par TPE vide pour vente carte single-tender (posOrderStore n'écrit un order_payment que pour le split). **Totaux fiscaux NF525 corrects** (agrégés depuis pos_payment_method). V1 LOCAL = 1 seul TPE (id=1) → ventilation triviale ; fix différé au multi-TPE (touche la persistance paiement fiscal-adjacente).
- **C-kds F2 (P2)** : le board KDS affiche à vie les commandes « advance » PREPARED (phantoms juin — artefact DB e2e polluée, pas la prod).
- **C-kds F3 (P2)** : le mur OSS exclut TOUTES les commandes POS → n° d'appel du reçu caisse jamais affiché. **GATE OWNER** — sentinel `OssCustomerScreenFilterTest` verrouille l'exclusion (mitigation token-leak RED R-3) ; ré-ouvrir exige LOCK owner.
- **F-storefront F1 (P2)** : « Annuler ma commande » client web cassé (payload sans branch_id → idempotency 422 message brut, + sans reason). **LATENT** : STAFF_ONLY_MODE=true masque tout le storefront en V1 (routes → /login).
- **B-borne P3** : Plan B non ré-appliqué serveur sur payment_method (carte borne forgée → ordre inencaissable ~3h). UI-inatteignable (KioskPaymentComponent masque la carte) + janitor 180 min.
- **A-F2 / C-kds F4-F5 / E-admin P3** : terminal_id sans exists (mitigé par A-F1) ; recall queue_number:0 ; store↔quote soft-deleted ; POS Operator voit le CA (rôle a `dashboard`, acceptable V1 mono-poste où caissier=propriétaire).

## Verdict
Les 7 parcours exécutés en RÉEL (API live + navigateur). **2 P1 healés+testés, 0 P1 restant.** P2/P3 disclosés (gates owner + latents V1 + artefacts DB). Frozen 0, chain NF525 OK. Convergence P1 atteinte.
