# GOAL GLOBAL — Validation totale du projet (owner 2026-07-24)
Mission jusqu'à « validé global ». Ton = dev senior + humain qui raisonne sur CHAQUE accès.

## Axes owner
1. **Phase 3** (BOM) : factures photo IA (mock↔OpenAI, testable sans clé) + boissons unités + conso réelle unifiée + charges. [ok recos, oui aux 4 Q]
2. **Audit LOGIQUE/RAISONNEMENT/INTELLIGENCE par ACCÈS** (améliorer, plus user-friendly) :
   - Accès CUISINE (KDS) · Accès CAISSIER · Accès MOBILE ADMIN · Accès GESTION.
   - Synchro entre TOUS les systèmes · tous les HISTORIQUES · commandes web acceptables/plus loin.
3. **Tickets** (spec owner) : PAS d'impression auto (reçu client caisse ET borne). À l'encaissement : j'encaisse → je peux CLIQUER pour imprimer si je veux (choix). Si oublié → réimprimer depuis « commandes en cours ». → VÉRIFIER l'existant + corriger si manque.
4. **Historiques** : accès commandes ANNULÉES + PASSÉES (vue d'ensemble complète).
5. Boucle : agents // → heal → re-test WEB réel → convergence → **validation globale**.

## Vagues
- W1 (en cours) : P3a domaine achats · vérif tickets · audit accès caisse/gestion/histo · audit accès cuisine/mobile-admin.
- W2+ : P3b pipeline IA · heals des findings · re-test e2e · validation globale.

## ✅ VAGUE 1 — DONE + déployé (commits b59bafb97 · 7811294d2 · 36d3df1be)
- **P3a achats** : 3 tables + PurchaseService (matière/boisson/charge, coût moyen pondéré). 57 tests.
- **Tickets** : reçu client À LA DEMANDE (flag OFF défaut), caisse+borne+encaissement ; bon caisse + KDS auto préservés.
- **Historique** : filtre Annulées/Refusées/Retournées (274 commandes accessibles).
- **Refuser web acceptée** : CTA « Annuler (motif) » (garde D-1).
- **Son cuisine** : débloqué (geste + bandeau + vibration).
- **/m ingrédients** : couper sauce/supplément/variation depuis le tél (SSOT).
- Preuves : vitest 2565, PHPUnit 338/1169, chaîne OK ×4, frozen 0, BUILD OK.
- Audits sains confirmés : cœur caisse/cuisine/gestion/sync robuste, 0 P0/P1 logique.
- Docs findings : `ACCES-caisse-gestion-findings.md`, `ACCES-cuisine-mobile-findings.md`, `TICKETS-findings.md`.

## ⏳ RESTE
- **W2** : P3b pipeline IA factures (mock↔OpenAI testable sans clé) + réconciliation boissons + conso réelle unifiée.
- P3 différés (améliorations) : légende ticket cuisine (F2), /m plus riche recherche/quantités/compta (F4), confirm toggle /m (F5), CTA refuser sur écran Détails déjà fait.
- Puis : re-test e2e web massif + **validation globale**.
- Owner : clé OpenAI (P3b réel), Mollie, capital mentions ; commandes test #194/#195/#230726193 à encaisser/annuler.

## ✅ PHASE 3 BOM — COMPLÈTE + déployée (P3a→P3d)
- P3a achats (`b59bafb97`) · P3b pipeline IA (`de2f1e0e`) · P3c écran scan (`bd92c3d3`) · P3d vue unifiée backend (`a30014817`)+UI.
- Chaîne complète : paramétrage matières → conso auto (ventes) → factures photo IA (mock↔OpenAI, Poulet→matière/Coca→boisson/Sac→charge) → coût moyen pondéré → **vue « Conso & Stock » unifiée** (matières+boissons+à-acheter).
- Écrans live VPS : « Scan Facture » + « Conso & Stock ». Mode démo tant que clé OpenAI absente.
- Preuves : vitest 2579, PHPUnit larges verts, chaîne NF525 ×4, frozen 0.

## RESTE vers validation globale
- Re-test e2e final (les 2 nouveaux écrans + non-régression globale).
- Améliorations différées : /m recherche/quantités (F4), légende ticket cuisine (F2), confirm toggle /m (F5).
- Owner : **clé OpenAI** (factures réelles), Mollie, capital mentions ; cmd test #194/#195/#230726193.
